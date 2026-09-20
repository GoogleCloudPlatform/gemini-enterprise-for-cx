<?php
/**
 * Gemini Enterprise for CX REST API and Session Handler
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class GECX_Rest_API {

    /**
     * The plugin routes that WooCommerce consumer key/secret authentication is
     * enabled for. See enable_wc_auth_for_custom_endpoints().
     */
    private const WC_AUTHENTICATED_ROUTES = [
        'gecx/v1/webhooks/order-created',
        'gecx/v1/public-key',
        'gecx/v1/link-agent',
    ];

    /**
     * Longest accepted value for a WooCommerce consumer secret.
     *
     * A WooCommerce consumer secret is 'cs_' followed by 40 hex characters, so
     * it is well under this. The bound exists so that an authenticated caller
     * cannot park a megabyte in the options table.
     */
    private const MAX_SECRET_LENGTH = 512;

    /**
     * Checks that a secret is a plausible WooCommerce consumer secret.
     *
     * @param string $secret Candidate secret.
     * @return bool True if the secret is within the length bound and uses only base64 and base64url characters.
     */
    private static function is_valid_secret( string $secret ): bool {
        return strlen( $secret ) <= self::MAX_SECRET_LENGTH
            && 1 === preg_match( '/^[a-zA-Z0-9_\-\+\/=]+$/', $secret );
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Session to order attribution hooks
        add_action( 'rest_api_init', [ $this, 'register_session_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_webhooks_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_public_key_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_link_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_refresh_token_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_auth_context_rest_route' ] );
        add_filter( 'rest_pre_serve_request', [ $this, 'suppress_cors_on_auth_context' ], 20, 4 );
        add_filter( 'woocommerce_rest_is_request_to_rest_api', [ $this, 'enable_wc_auth_for_custom_endpoints' ], 10, 1 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_session_to_order_metadata' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'attach_session_to_order_metadata_store_api' ], 10, 2 );
        add_action( 'rest_api_init', [ $this, 'register_session_rest_field' ] );
        add_filter( 'woocommerce_webhook_should_deliver', [ $this, 'gate_order_webhook_delivery' ], 10, 3 );
        add_filter( 'woocommerce_webhook_payload', [ $this, 'minimize_order_webhook_payload' ], 10, 4 );

        // Post-dispatch WooCommerce session sync and cache invalidation.
        add_filter( 'rest_post_dispatch', [ $this, 'sync_cart_session_after_dispatch' ], 10, 3 );

        // Re-emit the batch sub-response Cart-Token as a real response header.
        add_filter( 'rest_post_dispatch', [ $this, 'expose_batch_cart_token_header' ], 10, 3 );

        // Then drop it from the body, at priority 11 so it runs after the lift.
        add_filter( 'rest_post_dispatch', [ $this, 'strip_cart_token_from_batch_body' ], 11, 3 );
    }

    /**
     * Copies the Cart-Token a batch sub-response carries up to the batch
     * response itself, as a header.
     *
     * WooCommerce sets Cart-Token in AbstractCartRoute::add_response_headers(),
     * so every cart sub-request inside a batch gets one. The batch route is not
     * a cart route - it extends AbstractRoute - so the batch response that
     * WordPress actually sends carries no Cart-Token of its own. The token
     * survives only inside the JSON body, in the envelope WordPress builds for
     * each sub-response.
     *
     * A client reading response headers therefore gets a token from
     * /wc/store/v1/cart and nothing from /wc/store/v1/batch. This makes the two
     * behave the same, so a caller never has to read a token out of a body.
     *
     * The header is CORS-exposed: WooCommerce adds Cart-Token to
     * Access-Control-Expose-Headers for every Store API request, batch
     * included.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error $response Result to send.
     * @param \WP_REST_Server                               $server   Server instance.
     * @param \WP_REST_Request                              $request  Request used to generate $response.
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error The response, with a Cart-Token header when one was found.
     */
    public function expose_batch_cart_token_header( $response, $server, $request ) {
        if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
            return $response;
        }
        if ( strpos( (string) $request->get_route(), '/wc/store/v1/batch' ) !== 0 ) {
            return $response;
        }
        if ( ! empty( $this->find_cart_token( $response->get_headers() ) ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) || ! isset( $data['responses'] ) || ! is_array( $data['responses'] ) ) {
            return $response;
        }

        // Later sub-requests run after earlier ones, so the last token is the
        // one describing the session as it stands once the batch is done.
        $cart_token = '';
        foreach ( $data['responses'] as $sub_response ) {
            if ( ! is_array( $sub_response ) || ! isset( $sub_response['headers'] ) || ! is_array( $sub_response['headers'] ) ) {
                continue;
            }
            $sub_token = $this->find_cart_token( $sub_response['headers'] );
            if ( ! empty( $sub_token ) ) {
                $cart_token = $sub_token;
            }
        }

        if ( ! empty( $cart_token ) ) {
            $response->header( 'Cart-Token', $cart_token );
        }

        return $response;
    }

    /**
     * Removes the Cart-Token from the sub-response headers a batch response
     * repeats inside its JSON body.
     *
     * WordPress envelopes each sub-response into status, headers and body, and
     * puts those envelopes in the batch response body. The headers of a cart
     * sub-request include the Cart-Token WooCommerce issued, so a batch body
     * carries the session credential as data: readable by any script that gets
     * hold of the payload, captured whole by session-replay and error tools,
     * and written into HAR files attached to support tickets.
     *
     * Nothing needs it there. The token is on the response itself, as a
     * header, put there by expose_batch_cart_token_header() at priority 10.
     * This runs at 11, after it, and takes the body copy away. WooCommerce
     * Blocks never reads a cart token from anywhere, so the store's own
     * client is unaffected.
     *
     * Only the Cart-Token entry goes. Every other sub-response header stays
     * where a client expects it.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error $response Result to send.
     * @param \WP_REST_Server                               $server   Server instance.
     * @param \WP_REST_Request                              $request  Request used to generate $response.
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error The response, with no Cart-Token left in its body.
     */
    public function strip_cart_token_from_batch_body( $response, $server, $request ) {
        if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
            return $response;
        }
        if ( strpos( (string) $request->get_route(), '/wc/store/v1/batch' ) !== 0 ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) || ! isset( $data['responses'] ) || ! is_array( $data['responses'] ) ) {
            return $response;
        }

        $stripped = false;
        foreach ( $data['responses'] as $index => $sub_response ) {
            if ( ! is_array( $sub_response ) || ! isset( $sub_response['headers'] ) || ! is_array( $sub_response['headers'] ) ) {
                continue;
            }
            foreach ( $sub_response['headers'] as $key => $value ) {
                if ( strtolower( (string) $key ) === 'cart-token' ) {
                    unset( $data['responses'][ $index ]['headers'][ $key ] );
                    $stripped = true;
                }
            }
        }

        if ( $stripped ) {
            $response->set_data( $data );
        }

        return $response;
    }

    /**
     * Reads a Cart-Token out of a header map, whatever case it is keyed under.
     *
     * @param array $headers Header map.
     * @return string The token, or '' when the map carries none.
     */
    private function find_cart_token( $headers ): string {
        if ( ! is_array( $headers ) ) {
            return '';
        }
        foreach ( $headers as $key => $value ) {
            if ( strtolower( (string) $key ) !== 'cart-token' ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = reset( $value );
            }
            if ( is_string( $value ) && '' !== $value ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Syncs the cart the Store API just mutated to the browser's session row
     * and evicts the cached copy of it.
     *
     * This used to also mirror the Cart-Token response header into the JSON
     * body as 'id'. It no longer does. The cart token is a bearer credential:
     * it authenticates Store API requests for the session it names, and
     * WooCommerce returns it in the CORS-exposed Cart-Token response header,
     * which is the supported way to read it. Mirroring it into the body put it
     * everywhere a body goes and a header does not - wp.data cart state that
     * any script on the page can read via
     * wp.data.select('wc/store/cart').getCartData().id, session-replay and RUM
     * tools that capture XHR bodies, HAR files attached to support tickets,
     * and any intermediary that caches the response. The agent reads the
     * header instead.
     *
     * Removing the mirroring also stops it corrupting cart item sub-resources,
     * which define their own 'id' (the product ID) and were being overwritten.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error $response Result to send.
     * @param \WP_REST_Server                               $server   Server instance.
     * @param \WP_REST_Request                              $request  Request used to generate $response.
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error The response, unmodified.
     */
    public function sync_cart_session_after_dispatch( $response, $server, $request ) {
        if ( isset( WC()->cart ) ) {
            // Sync to user persistent cart user meta
            if ( method_exists( WC()->cart, 'persistent_cart_update' ) ) {
                WC()->cart->persistent_cart_update();
            }

            // Force set the WooCommerce session cookie in the browser if it was not sent
            // so that subsequent page refreshes align the browser with this session.
            $has_cookie = false;
            foreach ( $_COOKIE as $cookie_key => $cookie_val ) {
                if ( strpos( $cookie_key, 'wp_woocommerce_session_' ) === 0 ) {
                    $has_cookie = true;
                    break;
                }
            }
            if ( ! $has_cookie && isset( WC()->session ) && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
                WC()->session->set_customer_session_cookie( true );
            }

            // Directly sync to the browser's active session row in the database
            global $wpdb;
            $session_key = '';
            if ( isset( WC()->session ) ) {
                $session_key = WC()->session->get_customer_id();
            }

            if ( empty( $session_key ) ) {
                $user_id = get_current_user_id();
                if ( $user_id > 0 ) {
                    $session_key = (string) $user_id;
                }
            }

            if ( ! empty( $session_key ) && isset( $wpdb ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required to sync WooCommerce session data across requests.
                $existing_session = $wpdb->get_var( $wpdb->prepare(
                    "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
                    $session_key
                ) );

                $session_data = [];
                if ( ! empty( $existing_session ) ) {
                    $session_data = maybe_unserialize( $existing_session );
                }
                if ( ! is_array( $session_data ) ) {
                    $session_data = [];
                }

                $session_data['cart'] = WC()->cart->get_cart_for_session();

                $serialized_data = maybe_serialize( $session_data );
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
                $expiry = time() + (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query required to sync WooCommerce session data across requests.
                $sync_result = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry)
                     VALUES (%s, %s, %d)
                     ON DUPLICATE KEY UPDATE session_value = %s, session_expiry = %d",
                    $session_key, $serialized_data, $expiry, $serialized_data, $expiry
                ) );

                if ( false !== $sync_result ) {
                    // Invalidate WooCommerce session object cache to force browser reload from database
                    $cache_group = defined( 'WC_SESSION_CACHE_GROUP' )
                        ? WC_SESSION_CACHE_GROUP
                        : ( defined( 'WC_Cache_Helper::WC_SESSION_CACHE_GROUP' ) ? WC_Cache_Helper::WC_SESSION_CACHE_GROUP : 'wc_sessions' );

                    $cache_prefix = ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_cache_prefix' ) )
                        ? WC_Cache_Helper::get_cache_prefix( $cache_group )
                        : 'wc_session_';

                    wp_cache_delete( $cache_prefix . $session_key, $cache_group );
                }
            }
        }
        return $response;
    }

    /**
     * Register Custom Session API Route
     */
    public function register_session_rest_route(): void {
        register_rest_route( 'gecx/v1', '/session', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_session_handler' ],
            'permission_callback' => [ $this, 'check_session_permissions' ],
        ] );
    }

    /**
     * Checks whether a session ID matches a valid GECX session format.
     *
     * @param string $session_id           Candidate session ID.
     * @param bool   $strict_resource_name When true, requires the canonical 6-part GCP resource name
     *                                     with numeric project number required by the backend.
     * @return bool True if valid.
     */
    public static function is_valid_session_id( string $session_id, bool $strict_resource_name = false ): bool {
        if ( '' === $session_id || strlen( $session_id ) > 256 ) {
            return false;
        }
        if ( $strict_resource_name ) {
            return 1 === preg_match( '#^projects/[0-9]+/locations/[a-zA-Z0-9_\-]+/commerceSessions/[a-zA-Z0-9_\-:]+$#', $session_id );
        }
        return 1 === preg_match( '#^(projects/[0-9]+/locations/[a-zA-Z0-9_\-]+/commerceSessions/[a-zA-Z0-9_\-:]+|[a-zA-Z0-9_\-:]+)$#', $session_id );
    }

    /**
     * Handle the POST request to save the session ID in the WooCommerce customer session.
     */
    public function save_session_handler( \WP_REST_Request $request ) {
        $session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
        if ( empty( $session_id ) ) {
            return new \WP_Error( 'missing_session_id', __( 'Session ID is required.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }
        if ( ! self::is_valid_session_id( $session_id ) ) {
            return new \WP_Error( 'invalid_session_id', __( 'Session ID is invalid.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        // Force WooCommerce to initialize the frontend session based on client cookies
        if ( function_exists( 'WC' ) ) {
            if ( is_null( WC()->session ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
                include_once WC_ABSPATH . 'includes/class-wc-session-handler.php';
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'gecx_session_id', $session_id );
            if ( method_exists( WC()->session, 'save_data' ) ) {
                WC()->session->save_data();
            }
            if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
                WC()->session->set_customer_session_cookie( true );
            }
            return new \WP_REST_Response( [ 'success' => true ], 200 );
        }
        return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
    }

    /**
     * Check permissions for session API route.
     * Requires a valid WP REST nonce to prevent CSRF.
     */
    public function check_session_permissions( \WP_REST_Request $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * Register API Route to silently refresh customer JWT.
     */
    public function register_refresh_token_rest_route(): void {
        register_rest_route( 'gecx/v1', '/refresh-token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'refresh_token_handler' ],
            'permission_callback' => [ $this, 'check_session_permissions' ],
        ] );
    }

    /**
     * Handle the POST request to refresh customer JWT for active session.
     *
     * A missing secret is a 500 here, rather than a 200 carrying a null token
     * as on /gecx/v1/auth-context. Minting the JWT is the entire purpose of
     * this route, so there is no partial success to report; auth-context also
     * returns the nonce, which stays useful to the widget whether or not a JWT
     * could be signed.
     */
    public function refresh_token_handler( \WP_REST_Request $request ) {
        $customer_jwt = GECX_Auth::generate_customer_jwt();
        if ( empty( $customer_jwt ) ) {
            return new \WP_Error( 'jwt_generation_failed', __( 'Unable to generate customer JWT; secret is missing.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }
        return new \WP_REST_Response( [
            'success'      => true,
            'customer_jwt' => $customer_jwt,
        ], 200 );
    }

    /**
     * Register API Route to fetch fresh, dynamic auth context (nonce and customer JWT)
     * without caching.
     *
     * POST is the method the widget uses. Response headers say no-store, but a
     * CDN configured to "cache everything" can still serve a GET response from
     * the edge and hand one shopper's nonce and JWT to another; POST is not
     * cached by such rules. GET remains registered only so widget bundles that
     * predate the switch keep working, and should be dropped once those are no
     * longer deployed.
     */
    public function register_auth_context_rest_route(): void {
        register_rest_route( 'gecx/v1', '/auth-context', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'auth_context_handler' ],
            'permission_callback' => [ $this, 'check_auth_context_permissions' ],
        ] );
    }

    /**
     * Enforces strict same-origin isolation on /gecx/v1/auth-context.
     *
     * Because this endpoint resolves the logged-in user from the WordPress
     * logged_in cookie without a prior X-WP-Nonce (in order to bootstrap the
     * nonce on cached storefront pages), cross-origin and cross-site reads
     * must be rejected so another origin cannot read the user's nonce or JWT.
     *
     * A request has to positively identify itself as same-origin. Fetch
     * Metadata, Origin and Referer are each consulted, and a request carrying
     * none of the three is refused rather than trusted: a cross-origin
     * `<script src>` under `Referrer-Policy: no-referrer` arrives with all
     * three absent, and so does a non-browser client replaying a stolen
     * cookie. Browsers too old to send Fetch Metadata still send Referer,
     * unless the store suppresses it site-wide.
     *
     * @param \WP_REST_Request $request REST request instance.
     * @return true|\WP_Error
     */
    public function check_auth_context_permissions( \WP_REST_Request $request ) {
        if ( GECX_Auth::is_cart_token_request() ) {
            return new \WP_Error( 'rest_forbidden', __( 'Unauthorized.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        $fetch_site = self::read_request_header( $request, 'Sec-Fetch-Site', 'HTTP_SEC_FETCH_SITE' );
        $origin     = self::read_request_header( $request, 'Origin', 'HTTP_ORIGIN' );
        $referer    = self::read_request_header( $request, 'Referer', 'HTTP_REFERER' );

        if ( '' === $fetch_site && '' === $origin && '' === $referer ) {
            return new \WP_Error( 'rest_forbidden', __( 'Request origin could not be verified.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( '' !== $fetch_site ) {
            $lower_site = strtolower( trim( $fetch_site ) );
            if ( 'same-origin' !== $lower_site && 'none' !== $lower_site ) {
                return new \WP_Error( 'rest_forbidden', __( 'Cross-site requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
            }
        }

        if ( '' !== $origin && ! self::is_same_origin( $origin ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Cross-origin requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( '' === $origin && '' !== $referer && ! self::is_same_origin( $referer ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Cross-origin requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Reads a request header, falling back to the raw $_SERVER entry.
     *
     * @param \WP_REST_Request $request     REST request instance.
     * @param string           $header_name Header name as sent on the wire.
     * @param string           $server_key  Matching $_SERVER key.
     * @return string Sanitized header value, or '' when the header is absent.
     */
    private static function read_request_header( \WP_REST_Request $request, string $header_name, string $server_key ): string {
        $value = (string) $request->get_header( $header_name );
        if ( '' === $value && isset( $_SERVER[ $server_key ] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_text_field.
            $value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $server_key ] ) );
        }
        return $value;
    }

    /**
     * Checks whether a candidate URL (Origin or Referer) addresses this store.
     *
     * Host and port are compared against both home_url() and site_url(),
     * which differ on the common install where WordPress itself lives in a
     * subdirectory of the storefront.
     *
     * Scheme is deliberately not compared. Behind a TLS-terminating proxy or
     * a CDN doing flexible SSL, home_url() is routinely stored as http while
     * the browser reports an https Origin, and refusing that pairing takes the
     * widget offline on stores that are otherwise healthy. An http Origin on
     * the store's own host implies an active network attacker, who already
     * holds the cookie this check exists to protect. Schemes other than http
     * and https are still refused outright.
     *
     * @param string $candidate_url Candidate Origin or Referer URL.
     * @return bool
     */
    private static function is_same_origin( string $candidate_url ): bool {
        $candidate = self::parse_origin( $candidate_url );
        if ( null === $candidate ) {
            return false;
        }

        foreach ( self::get_allowed_origins() as $allowed ) {
            if ( $allowed['host'] === $candidate['host'] && $allowed['port'] === $candidate['port'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduces a URL to its host and port, collapsing the scheme's default port
     * to null so that https://example.com and https://example.com:443 compare
     * equal.
     *
     * @param string $url Candidate URL.
     * @return array|null Array with 'host' and 'port' keys, or null when the URL is unusable.
     */
    private static function parse_origin( string $url ): ?array {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return null;
        }

        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        if ( '' !== $scheme && 'http' !== $scheme && 'https' !== $scheme ) {
            return null;
        }

        $port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
        if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
            $port = null;
        }

        return [
            'host' => strtolower( (string) $parts['host'] ),
            'port' => $port,
        ];
    }

    /**
     * Builds the set of origins permitted to read /gecx/v1/auth-context.
     *
     * @return array List of arrays with 'host' and 'port' keys.
     */
    private static function get_allowed_origins(): array {
        $urls = [];
        if ( function_exists( 'home_url' ) ) {
            $urls[] = (string) home_url();
        }
        if ( function_exists( 'site_url' ) ) {
            $urls[] = (string) site_url();
        }

        /**
         * Filters the URLs whose host and port may read the auth context.
         *
         * A store that serves the storefront from a domain WordPress does not
         * know about, such as a headless front end or a mapped domain, adds it
         * here.
         *
         * @param array $urls Allowed URLs.
         */
        if ( function_exists( 'apply_filters' ) ) {
            $urls = (array) apply_filters( 'gecx_auth_context_allowed_origins', $urls );
        }

        $allowed = [];
        foreach ( $urls as $url ) {
            $parsed = self::parse_origin( (string) $url );
            if ( null !== $parsed ) {
                $allowed[] = $parsed;
            }
        }

        return $allowed;
    }

    /**
     * Strips WordPress REST API CORS reflection headers on /gecx/v1/auth-context
     * so cross-origin documents cannot read the response even if preflighted.
     *
     * @param bool             $served  Whether the request has already been served.
     * @param \WP_HTTP_Response $result  Result to send to the client.
     * @param \WP_REST_Request  $request Request used to generate the response.
     * @param \WP_REST_Server   $server  Server instance.
     * @return bool
     */
    public function suppress_cors_on_auth_context( $served, $result, $request, $server ) {
        if ( $request instanceof \WP_REST_Request && '/gecx/v1/auth-context' === $request->get_route() ) {
            if ( function_exists( 'header_remove' ) && ( ! function_exists( 'headers_sent' ) || ! headers_sent() ) ) {
                header_remove( 'Access-Control-Allow-Origin' );
                header_remove( 'Access-Control-Allow-Credentials' );
            }
        }
        return $served;
    }

    /**
     * Handle the GET request for dynamic auth context.
     *
     * WordPress's rest_cookie_check_errors() resets the active user to 0
     * when a REST request arrives without an X-WP-Nonce header. Because this
     * endpoint is what mints the fresh wp_rest nonce and customer JWT after a
     * cached page load, it resolves the logged-in user ID from the WordPress
     * logged_in cookie (after check_auth_context_permissions() has verified
     * same-origin isolation), supplies that user ID to wp_create_nonce() via
     * the core nonce_user_logged_out filter and directly to
     * GECX_Auth::generate_customer_jwt(), and never mutates global user state.
     *
     * The response always carries a nonce and a 'customer_jwt' key. That key
     * is null when no signing secret is configured, which is a 200 rather than
     * an error: the nonce alone still lets the widget reach the Store API, so
     * an unsigned store degrades to an anonymous shopper instead of a broken
     * widget. Callers must treat null as "no identity", never as a failure to
     * retry.
     *
     * @param \WP_REST_Request $request REST request instance.
     * @return \WP_REST_Response
     */
    public function auth_context_handler( \WP_REST_Request $request ): \WP_REST_Response {
        $current_user_id   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $effective_user_id = $current_user_id;
        $nonce_user_filter = null;

        if ( 0 === $effective_user_id && function_exists( 'wp_validate_auth_cookie' ) ) {
            $cookie_user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
            if ( $cookie_user_id > 0 ) {
                $effective_user_id = $cookie_user_id;
                if ( function_exists( 'add_filter' ) ) {
                    $nonce_user_filter = static function ( $uid, $action = -1 ) use ( $cookie_user_id ) {
                        return 'wp_rest' === (string) $action ? $cookie_user_id : $uid;
                    };
                    add_filter( 'nonce_user_logged_out', $nonce_user_filter, 999, 2 );
                }
            }
        }

        try {
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }

            $nonce        = function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( 'wp_rest' ) : '';
            $customer_jwt = GECX_Auth::generate_customer_jwt( $effective_user_id );
        } finally {
            if ( null !== $nonce_user_filter && function_exists( 'remove_filter' ) ) {
                remove_filter( 'nonce_user_logged_out', $nonce_user_filter, 999 );
            }
        }

        $response = new \WP_REST_Response( [
            'success'      => true,
            'nonce'        => $nonce,
            'customer_jwt' => $customer_jwt,
        ], 200 );

        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
        $response->header( 'Vary', 'Cookie, Origin' );

        return $response;
    }

    /**
     * Register API Route to register or update WooCommerce webhooks.
     */
    public function register_webhooks_rest_route(): void {
        register_rest_route( 'gecx/v1', '/webhooks/order-created', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'order_created_webhooks_handler' ],
            'permission_callback' => [ $this, 'check_admin_permissions' ],
        ] );
    }

    /**
     * Register API Route for Google Cloud to report the agent this store is
     * linked to.
     *
     * The link is established against the merchant's Cloud project and recorded
     * there; this endpoint is how that record reaches WordPress. It carries the
     * store's own API credentials, so the store is told by the system that owns
     * the answer rather than by a redirect passing through a browser.
     */
    public function register_link_rest_route(): void {
        register_rest_route( 'gecx/v1', '/link-agent', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'link_agent_handler' ],
            'permission_callback' => [ $this, 'check_admin_permissions' ],
        ] );
    }

    /**
     * Pattern for a Google Cloud resource name.
     *
     * Duplicated from GECX_Admin rather than shared, to avoid making REST
     * request handling depend on the admin class being loaded.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_\-\.\/]+$/';

    /**
     * Apply an agent link reported by Google Cloud.
     */
    public function link_agent_handler( \WP_REST_Request $request ) {
        $agent_name = (string) $request->get_param( 'agent_name' );
        $agent_name = sanitize_text_field( $agent_name );

        if ( '' === $agent_name || ! preg_match( self::RESOURCE_NAME_PATTERN, $agent_name ) ) {
            return new \WP_Error( 'gecx_invalid_agent_name', __( 'Missing or malformed agent_name.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $token_broker = sanitize_text_field( (string) $request->get_param( 'token_broker_name' ) );
        if ( '' !== $token_broker && ! preg_match( self::RESOURCE_NAME_PATTERN, $token_broker ) ) {
            return new \WP_Error( 'gecx_invalid_token_broker', __( 'Malformed token_broker_name.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        update_option( 'gecx_agent_name', $agent_name );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_auth_complete', 1, 'no' );
        if ( '' !== $token_broker ) {
            update_option( 'gecx_token_broker_name', $token_broker );
        }
        delete_option( 'gecx_dismiss_activation_notice' );
        self::set_order_webhook_status( 'active' );

        return new \WP_REST_Response( [
            'success'    => true,
            'agent_name' => $agent_name,
        ], 200 );
    }

    /**
     * Check permissions for secret API route.
     * Accepts authenticated WooCommerce API key calls (manage_woocommerce or manage_options),
     * or browser session cookie with valid WP REST nonce and manage_options capability.
     */
    public function check_admin_permissions( \WP_REST_Request $request ) {
        // A Cart-Token identifies a shopper session, never a store operator.
        // GECX_Auth::block_cart_token_off_store_api() already refuses this on
        // 'rest_pre_dispatch'; this is deliberately redundant, so the endpoint
        // stays closed even if that filter is unhooked or reordered.
        if ( GECX_Auth::is_cart_token_request() ) {
            return new \WP_Error( 'rest_forbidden', __( 'Unauthorized.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );

        $logged_in_cookie = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : 'wordpress_logged_in_';
        $has_cookie       = false;
        if ( ! empty( $_COOKIE ) ) {
            foreach ( $_COOKIE as $k => $v ) {
                if ( 0 === strpos( (string) $k, $logged_in_cookie ) || 0 === strpos( (string) $k, 'wordpress_logged_in_' ) ) {
                    $has_cookie = true;
                    break;
                }
            }
        }

        // A supplied nonce must always be valid, and a cookie-authenticated
        // caller must supply one, otherwise this endpoint is CSRF-able.
        // WooCommerce API key callers present no cookie and are authorized by
        // the capability check below instead.
        $nonce_is_valid = ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' );

        if ( ! empty( $nonce ) && ! $nonce_is_valid ) {
            return new \WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( $has_cookie && ! $nonce_is_valid ) {
            return new \WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( ! function_exists( 'current_user_can' ) ||
             ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Unauthorized.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Helper to retrieve the webhook HMAC secret (the consumer_secret stored on
     * the WooCommerce webhook).
     */
    public static function get_webhook_secret(): string {
        $secret = '';
        $webhook_id = get_option( 'gecx_webhook_id' );
        if ( ! empty( $webhook_id ) && class_exists( 'WC_Webhook' ) ) {
            try {
                $webhook = new \WC_Webhook( (int) $webhook_id );
                if ( $webhook->get_id() ) {
                    $secret = (string) $webhook->get_secret();
                }
            } catch ( \Exception $e ) {
                $secret = '';
            }
        }
        return $secret;
    }

    /**
     * Ensures the order.created WooCommerce webhook is registered and signed with the preferred HMAC secret.
     *
     * @param string $delivery_url Optional delivery URL. Defaults to console base webhook URL.
     * @param string $secret Optional explicit secret. Defaults to preferred webhook secret.
     * @return \WC_Webhook|\WP_Error Webhook instance or WP_Error on failure.
     */
    public static function ensure_order_webhook( string $delivery_url = '', string $secret = '' ) {
        if ( ! class_exists( 'WC_Webhook' ) || ( defined( 'GECX_TESTING' ) && ! empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) ) ) {
            return new \WP_Error( 'woocommerce_not_active', __( 'WooCommerce WC_Webhook class not available.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        if ( empty( $secret ) ) {
            $secret = self::get_webhook_secret();
        }

        if ( empty( $secret ) ) {
            return new \WP_Error( 'missing_secret', __( 'No webhook secret configured.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        if ( empty( $delivery_url ) ) {
            $console_url  = get_option( 'gecx_console_base_url', 'https://gecx.cloud.google.com' );
            $console_url  = (string) apply_filters( 'gecx_console_base_url', (string) $console_url );
            $delivery_url = rtrim( $console_url, '/' ) . '/woocommerce/webhook';
        }

        $delivery_url = esc_url_raw( $delivery_url );
        $scheme       = (string) wp_parse_url( $delivery_url, PHP_URL_SCHEME );
        if ( 'https' !== $scheme || ! wp_http_validate_url( $delivery_url ) ) {
            return new \WP_Error( 'invalid_delivery_url', __( 'Webhook delivery URL must be a valid HTTPS URL.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        try {
            $existing_webhook_id = get_option( 'gecx_webhook_id' );
            $webhook             = null;
            if ( ! empty( $existing_webhook_id ) ) {
                try {
                    $webhook = new \WC_Webhook( (int) $existing_webhook_id );
                    if ( ! $webhook->get_id() ) {
                        $webhook = null;
                    }
                } catch ( \Exception $e ) {
                    $webhook = null;
                }
            }

            if ( function_exists( 'wc_get_webhooks' ) ) {
                $existing_webhooks = wc_get_webhooks( [
                    'status' => 'any',
                    'search' => 'GECX Agent Order Created',
                    'limit'  => 25,
                ] );
                if ( is_array( $existing_webhooks ) ) {
                    foreach ( $existing_webhooks as $candidate ) {
                        if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                                $webhook = $candidate;
                                update_option( 'gecx_webhook_id', $webhook->get_id() );
                            } elseif ( $candidate->get_id() !== $webhook->get_id() ) {
                                $candidate->delete( true );
                            }
                        }
                    }
                }
            }

            $desired_status  = ( 1 === (int) get_option( 'gecx_agent_enabled', 1 ) ) ? 'active' : 'paused';
            $current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                $webhook = new \WC_Webhook();
                $webhook->set_name( 'GECX Agent Order Created' );
                $webhook->set_user_id( $current_user_id );
                $webhook->set_topic( 'order.created' );
                $webhook->set_delivery_url( $delivery_url );
                $webhook->set_api_version( 'wp_api_v3' );
                $webhook->set_status( $desired_status );
                $webhook->set_secret( $secret );
                $webhook->save();
                update_option( 'gecx_webhook_id', $webhook->get_id() );
            } else {
                $needs_update = false;
                if ( $current_user_id > 0 && $webhook->get_user_id() !== $current_user_id ) {
                    $webhook->set_user_id( $current_user_id );
                    $needs_update = true;
                }
                if ( $webhook->get_delivery_url() !== $delivery_url ) {
                    $webhook->set_delivery_url( $delivery_url );
                    $needs_update = true;
                }
                if ( $webhook->get_secret() !== $secret ) {
                    $webhook->set_secret( $secret );
                    $needs_update = true;
                }
                if ( 'wp_api_v3' !== $webhook->get_api_version() ) {
                    $webhook->set_api_version( 'wp_api_v3' );
                    $needs_update = true;
                }
                if ( $desired_status !== $webhook->get_status() ) {
                    $webhook->set_status( $desired_status );
                    $needs_update = true;
                }
                if ( $needs_update ) {
                    $webhook->save();
                }
            }
            return $webhook;
        } catch ( \Exception $e ) {
            GECX_Auth::log( 'Failed to register WooCommerce order webhook: ' . $e->getMessage(), 'error' );
            return new \WP_Error(
                'webhook_registration_failed',
                __( 'Failed to register WooCommerce order webhook.', 'gemini-enterprise-for-cx' ),
                [ 'status' => 500 ]
            );
        }
    }

    /**
     * Handle the POST request to register or update WooCommerce order.created webhook.
     */
    public function order_created_webhooks_handler( \WP_REST_Request $request ) {
        // Deliberately not sanitized. is_valid_secret() below is a strict
        // allowlist, and this value becomes the HMAC key WooCommerce signs
        // order deliveries with, so it has to be validated byte for byte.
        // sanitize_text_field() strips percent-octets and tags, which would
        // turn a malformed secret into a well-formed one: "cs_1234%ab5678"
        // would be rejected today but would silently become "cs_12345678"
        // and be stored as the signing key, producing signature mismatches
        // on every delivery instead of a 400 at registration time.
        $consumer_secret = trim( (string) $request->get_param( 'consumer_secret' ) );
        if ( ! empty( $consumer_secret ) && ! self::is_valid_secret( $consumer_secret ) ) {
            return new \WP_Error( 'invalid_secret', __( 'Consumer secret is invalid.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $webhook_secret = ! empty( $consumer_secret ) ? $consumer_secret : self::get_webhook_secret();
        if ( empty( $webhook_secret ) ) {
            return new \WP_Error( 'missing_secret', __( 'No webhook secret configured or provided.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $webhook = self::ensure_order_webhook( '', $webhook_secret );
        if ( is_wp_error( $webhook ) ) {
            return $webhook;
        }

        // New credentials are in place, so clear any invalidation recorded by
        // a previous SyncState reconciliation and mark store auth complete.
        update_option( 'gecx_auth_complete', 1, 'no' );
        if ( class_exists( 'GECX_Admin' ) ) {
            delete_option( GECX_Admin::STORE_AUTH_INVALID_OPTION );
        }

        return new \WP_REST_Response( [
            'success'    => true,
            'webhook_id' => $webhook->get_id(),
        ], 200 );
    }

    /**
     * Register API Route to retrieve the gecx_public_key.
     */
    public function register_public_key_rest_route(): void {
        register_rest_route( 'gecx/v1', '/public-key', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_public_key_handler' ],
            'permission_callback' => [ $this, 'check_admin_permissions' ],
        ] );
    }

    /**
     * Handle the GET request to retrieve the store's RSA public key PEM.
     */
    public function get_public_key_handler( \WP_REST_Request $request ) {
        $public_key = GECX_Auth::get_public_key();
        if ( empty( $public_key ) ) {
            return new \WP_Error( 'rest_cannot_retrieve_key', __( 'Failed to retrieve public key.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        return new \WP_REST_Response( [ 'public_key' => $public_key ], 200 );
    }

    /**
     * Enables WooCommerce API key authentication for requests to the plugin's
     * own admin REST routes.
     *
     * WooCommerce confines key-based authentication to /wc/ routes precisely so
     * that a leaked or low-privilege key cannot reach core REST endpoints. This
     * widens that confinement to the named routes above, so what counts as one
     * of them has to be the route WordPress will actually dispatch.
     *
     * It previously matched the URI path first and only consulted rest_route
     * afterwards. WordPress dispatches on rest_route whenever one is present,
     * so GET /gecx/v1/public-key?rest_route=/wp/v2/users enabled key authentication
     * and then dispatched /wp/v2/users, with the key's user's full WordPress
     * capabilities applying. Core routes have no notion of a key's read/write
     * scope, so a read-only key issued for an administrator reached every core
     * route as that administrator.
     *
     * GECX_Auth::is_request_to_route() resolves the route in the order
     * WordPress does. A request that cannot name a route yet is left to
     * WooCommerce's own answer rather than being granted one.
     */
    public function enable_wc_auth_for_custom_endpoints( bool $is_rest_api ): bool {
        return GECX_Auth::is_request_to_route( self::WC_AUTHENTICATED_ROUTES ) ? true : $is_rest_api;
    }

    /**
     * Helper to safely retrieve session ID from WooCommerce
     */
    private function get_gecx_session_id_safely() {
        if ( function_exists( 'WC' ) ) {
            if ( is_null( WC()->session ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
                include_once WC_ABSPATH . 'includes/class-wc-session-handler.php';
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
            return WC()->session->get( 'gecx_session_id' );
        }
        return '';
    }

    /**
     * Bind Session ID to Created Order
     */
    public function attach_session_to_order_metadata( $order, $data ): void {
        $session_id = $this->get_gecx_session_id_safely();
        if ( ! empty( $session_id ) ) {
            $order->update_meta_data( '_gecx_session_id', $session_id );
        }
    }

    /**
     * Bind Session ID to Created Order (WooCommerce Blocks Checkout)
     */
    public function attach_session_to_order_metadata_store_api( \WC_Order $order, \WP_REST_Request $request ): void {
        $session_id = $this->get_gecx_session_id_safely();
        if ( ! empty( $session_id ) ) {
            $order->update_meta_data( '_gecx_session_id', $session_id );
        }
    }

    /**
     * Expose Meta Key in WooCommerce REST API
     */
    public function register_session_rest_field(): void {
        register_rest_field( 'shop_order', '_gecx_session_id', [
            'get_callback' => function( $order_array ) {
                if ( function_exists( 'wc_get_order' ) ) {
                    $order = wc_get_order( $order_array['id'] );
                    return $order ? $order->get_meta( '_gecx_session_id' ) : '';
                }
                return '';
            },
            'schema' => [
                'description' => 'GECX Chat Session ID associated with this order.',
                'type'        => 'string',
                'context'     => [ 'view', 'edit' ],
            ],
        ] );
    }

    /**
     * Updates the status of the GECX order webhook (e.g. 'active' or 'paused')
     * and sweeps any duplicate/orphaned 'GECX Agent Order Created' webhooks.
     *
     * @param string $status Target WooCommerce webhook status ('active', 'paused', or 'disabled').
     */
    public static function set_order_webhook_status( string $status ): void {
        $wc_available = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_TESTING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );
        if ( ! $wc_available ) {
            return;
        }

        $stored_id = (int) get_option( 'gecx_webhook_id', 0 );
        $primary   = null;

        if ( $stored_id > 0 ) {
            try {
                $candidate = new \WC_Webhook( $stored_id );
                if ( $candidate->get_id() > 0 && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                    $primary = $candidate;
                }
            } catch ( \Exception $e ) {
                $primary = null;
            }
        }

        if ( function_exists( 'wc_get_webhooks' ) ) {
            try {
                $webhooks = wc_get_webhooks( [
                    'status' => 'any',
                    'search' => 'GECX Agent Order Created',
                    'limit'  => 25,
                ] );
                if ( is_array( $webhooks ) ) {
                    foreach ( $webhooks as $candidate ) {
                        if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                            if ( null === $primary ) {
                                $primary = $candidate;
                                update_option( 'gecx_webhook_id', $primary->get_id() );
                            } elseif ( $candidate->get_id() !== $primary->get_id() ) {
                                try {
                                    $candidate->delete( true );
                                } catch ( \Throwable $e ) {
                                    // A duplicate webhook that cannot be deleted is not worth
                                    // failing the request over, but it should be visible.
                                    GECX_Auth::log( 'Failed to delete duplicate order webhook: ' . $e->getMessage(), 'debug' );
                                }
                            }
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                GECX_Auth::log( 'Failed to query order webhooks: ' . $e->getMessage(), 'debug' );
            }
        }

        if ( null !== $primary && $primary->get_id() > 0 ) {
            if ( $primary->get_status() !== $status ) {
                $primary->set_status( $status );
                try {
                    $primary->save();
                } catch ( \Throwable $e ) {
                    // Raising here would fatal inside an activation or deactivation hook.
                    GECX_Auth::log( 'Failed to save order webhook status: ' . $e->getMessage(), 'debug' );
                }
            }
        }
    }

    /**
     * Deletes the GECX order webhook (both via WC_Webhook API and direct $wpdb fallback)
     * and removes the gecx_webhook_id option.
     */
    public static function delete_order_webhook(): void {
        $stored_id    = (int) get_option( 'gecx_webhook_id', 0 );
        $wc_available = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_TESTING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );

        if ( $wc_available ) {
            if ( $stored_id > 0 ) {
                try {
                    $webhook = new \WC_Webhook( $stored_id );
                    if ( $webhook->get_id() > 0 ) {
                        $webhook->delete( true );
                    }
                } catch ( \Throwable $e ) {
                    // Cleanup continues with the remaining webhooks either way.
                    GECX_Auth::log( 'Failed to delete order webhook by stored id: ' . $e->getMessage(), 'debug' );
                }
            }
            if ( function_exists( 'wc_get_webhooks' ) ) {
                try {
                    $webhooks = wc_get_webhooks( [
                        'status' => 'any',
                        'search' => 'GECX Agent Order Created',
                        'limit'  => 25,
                    ] );
                    if ( is_array( $webhooks ) ) {
                        foreach ( $webhooks as $candidate ) {
                            if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                                try {
                                    $candidate->delete( true );
                                } catch ( \Throwable $e ) {
                                    GECX_Auth::log( 'Failed to delete order webhook candidate: ' . $e->getMessage(), 'debug' );
                                }
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    GECX_Auth::log( 'Failed to query order webhooks during cleanup: ' . $e->getMessage(), 'debug' );
                }
            }
        } else {
            global $wpdb;
            if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
                $table_name   = $wpdb->prefix . 'wc_webhooks';
                $table_exists = true;
                if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                    $escaped_like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table_name ) : $table_name;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $escaped_like ) ) === $table_name );
                }
                if ( $table_exists ) {
                    if ( $stored_id > 0 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->delete( $table_name, [ 'webhook_id' => $stored_id ], [ '%d' ] );
                        if ( function_exists( 'wp_cache_delete' ) ) {
                            wp_cache_delete( $stored_id, 'webhooks' );
                        }
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->delete(
                        $table_name,
                        [
                            'name'  => 'GECX Agent Order Created',
                            'topic' => 'order.created',
                        ],
                        [ '%s', '%s' ]
                    );
                    if ( function_exists( 'delete_transient' ) ) {
                        delete_transient( 'woocommerce_webhook_ids' );
                        delete_transient( 'woocommerce_webhook_ids_status_active' );
                        delete_transient( 'woocommerce_webhook_ids_status_paused' );
                        delete_transient( 'woocommerce_webhook_ids_status_disabled' );
                    }
                }
            }
        }

        delete_option( 'gecx_webhook_id' );
    }

    /**
     * Reconciles the order webhook on plugin activation:
     * - If no agent is linked, sweeps and deletes any orphaned GECX webhooks.
     * - If an agent is linked, adopts/deduplicates the webhook and sets its status
     *   to match gecx_agent_enabled ('active' when enabled, 'paused' when disabled).
     */
    public static function reconcile_webhook_on_activation(): void {
        $agent_name = (string) get_option( 'gecx_agent_name', '' );
        if ( '' === $agent_name ) {
            $has_stored_webhook = ! empty( get_option( 'gecx_webhook_id' ) );
            $wc_available       = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_TESTING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );
            if ( $has_stored_webhook || $wc_available ) {
                self::delete_order_webhook();
            }
            return;
        }
        $enabled = ( 1 === (int) get_option( 'gecx_agent_enabled', 0 ) );
        self::set_order_webhook_status( $enabled ? 'active' : 'paused' );
    }

    /**
     * Checks whether the given webhook instance or ID belongs to GECX order attribution.
     *
     * @param mixed $webhook_or_id WC_Webhook instance or integer webhook ID.
     * @return bool
     */
    private function is_gecx_order_webhook( $webhook_or_id ): bool {
        $stored_id = (int) get_option( 'gecx_webhook_id', 0 );
        $webhook   = null;

        if ( $webhook_or_id instanceof \WC_Webhook ) {
            $webhook = $webhook_or_id;
            if ( $stored_id > 0 && $webhook->get_id() === $stored_id ) {
                return true;
            }
        } elseif ( is_numeric( $webhook_or_id ) ) {
            $id = (int) $webhook_or_id;
            if ( $stored_id > 0 && $id === $stored_id ) {
                return true;
            }
            if ( $id > 0 && class_exists( 'WC_Webhook' ) ) {
                try {
                    $webhook = new \WC_Webhook( $id );
                } catch ( \Exception $e ) {
                    $webhook = null;
                }
            }
        }

        if ( $webhook instanceof \WC_Webhook && $webhook->get_id() > 0 ) {
            return 'order.created' === $webhook->get_topic()
                && 'GECX Agent Order Created' === $webhook->get_name();
        }

        return false;
    }

    /**
     * Suppresses delivery of the GECX order webhook when:
     * - the agent is unlinked or storefront chat widget is disabled, or
     * - the order does not carry a valid _gecx_session_id meta value.
     *
     * @param bool  $should_deliver Whether WooCommerce intends to deliver the webhook.
     * @param mixed $webhook        WC_Webhook instance or webhook ID.
     * @param mixed $arg            Hook argument (order ID or WC_Order).
     * @return bool
     */
    public function gate_order_webhook_delivery( $should_deliver, $webhook, $arg ): bool {
        if ( ! $should_deliver || ! $this->is_gecx_order_webhook( $webhook ) ) {
            return (bool) $should_deliver;
        }

        if ( empty( get_option( 'gecx_agent_name', '' ) ) || 1 !== (int) get_option( 'gecx_agent_enabled', 0 ) ) {
            return false;
        }

        $order = null;
        if ( $arg instanceof \WC_Order ) {
            $order = $arg;
        } elseif ( is_numeric( $arg ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $arg );
        }

        if ( ! $order instanceof \WC_Order ) {
            return false;
        }

        $session_id = (string) $order->get_meta( '_gecx_session_id' );
        return self::is_valid_session_id( $session_id, true );
    }

    /**
     * Replaces the full wp_api_v3 order record with only the minimal fields
     * required by the Gemini Enterprise backend. Strips all customer PII
     * (billing/shipping addresses, email, phone, IP, payment details, notes).
     *
     * @param mixed  $payload     Original webhook payload array.
     * @param string $resource    Resource type (e.g. 'order').
     * @param mixed  $resource_id Resource ID (order ID).
     * @param mixed  $webhook_id  Webhook ID.
     * @return mixed Minimized payload array for GECX webhooks, or original payload.
     */
    public function minimize_order_webhook_payload( $payload, $resource, $resource_id, $webhook_id ) {
        if ( ! is_array( $payload ) || ! $this->is_gecx_order_webhook( $webhook_id ) ) {
            return $payload;
        }

        $order = null;
        if ( is_numeric( $resource_id ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $resource_id );
        }

        $session_id = '';
        if ( $order instanceof \WC_Order ) {
            $session_id = (string) $order->get_meta( '_gecx_session_id' );
        }
        if ( '' === $session_id && isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
            foreach ( $payload['meta_data'] as $meta ) {
                if ( is_array( $meta ) && isset( $meta['key'] ) && '_gecx_session_id' === $meta['key'] ) {
                    $session_id = isset( $meta['value'] ) ? (string) $meta['value'] : '';
                    break;
                } elseif ( is_object( $meta ) && isset( $meta->key ) && '_gecx_session_id' === $meta->key ) {
                    $session_id = isset( $meta->value ) ? (string) $meta->value : '';
                    break;
                }
            }
        }

        $line_items = [];
        if ( isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
            foreach ( $payload['line_items'] as $item ) {
                if ( is_array( $item ) ) {
                    $line_items[] = [
                        'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
                        'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
                        'name'         => isset( $item['name'] ) ? (string) $item['name'] : '',
                        'price'        => isset( $item['price'] ) ? $item['price'] : 0,
                        'quantity'     => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
                    ];
                }
            }
        } elseif ( $order instanceof \WC_Order && method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items() as $item ) {
                if ( is_object( $item ) ) {
                    $qty   = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
                    $total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
                    $line_items[] = [
                        'product_id'   => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
                        'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
                        'name'         => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
                        'price'        => method_exists( $order, 'get_item_total' ) ? (float) $order->get_item_total( $item, false, false ) : round( $total / $qty, 4 ),
                        'quantity'     => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
                    ];
                }
            }
        }

        return [
            'id'             => isset( $payload['id'] ) ? (int) $payload['id'] : ( $order instanceof \WC_Order ? (int) $order->get_id() : (int) $resource_id ),
            'currency'       => isset( $payload['currency'] ) ? (string) $payload['currency'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' ),
            'total'          => isset( $payload['total'] ) ? (string) $payload['total'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_total' ) ? (string) $order->get_total() : '0.00' ),
            'total_tax'      => isset( $payload['total_tax'] ) ? (string) $payload['total_tax'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_total_tax' ) ? (string) $order->get_total_tax() : '0.00' ),
            'shipping_total' => isset( $payload['shipping_total'] ) ? (string) $payload['shipping_total'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_shipping_total' ) ? (string) $order->get_shipping_total() : '0.00' ),
            'meta_data'      => '' !== $session_id ? [
                [
                    'key'   => '_gecx_session_id',
                    'value' => $session_id,
                ],
            ] : [],
            'line_items'     => $line_items,
        ];
    }
}
