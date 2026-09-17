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
        'gecx/v1/secret',
        'gecx/v1/webhooks/order-created',
        'gecx/v1/public-key',
        'gecx/v1/link-agent',
    ];

    /**
     * Longest accepted value for the shared secret and for a WooCommerce
     * consumer secret.
     *
     * A WooCommerce consumer secret is 'cs_' followed by 40 hex characters and
     * the backend's shared secret is a base64-encoded 256-bit value, so both
     * are well under this. The bound exists so that an authenticated caller
     * cannot park a megabyte in the options table.
     */
    private const MAX_SECRET_LENGTH = 512;

    /**
     * Checks that a secret is a plausible shared or consumer secret.
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
        add_action( 'rest_api_init', [ $this, 'register_secret_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_webhooks_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_public_key_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_link_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_refresh_token_rest_route' ] );
        add_filter( 'woocommerce_rest_is_request_to_rest_api', [ $this, 'enable_wc_auth_for_custom_endpoints' ], 10, 1 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_session_to_order_metadata' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'attach_session_to_order_metadata_store_api' ], 10, 2 );
        add_action( 'rest_api_init', [ $this, 'register_session_rest_field' ] );

        // Store API post-dispatch cart token injection, SQL sync, and cache invalidation.
        add_filter( 'rest_post_dispatch', [ $this, 'inject_cart_token_into_body' ], 10, 3 );
    }

    /**
     * Injects the Cart-Token header value into the JSON response body as 'id'
     * and guarantees MySQL session sync + cache eviction.
     */
    public function inject_cart_token_into_body( $response, $server, $request ) {
        $route = $request->get_route();
        if ( strpos( $route, '/wc/store/v1/cart' ) === 0 ) {
            if ( $response instanceof \WP_REST_Response ) {
                $headers = $response->get_headers();
                $cart_token = '';
                foreach ( $headers as $key => $val ) {
                    $lower_key = strtolower( $key );
                    if ( $lower_key === 'cart-token' ) {
                        $cart_token = $val;
                    }
                }
                if ( empty( $cart_token ) ) {
                    $cart_token = $request->get_header( 'Cart-Token' );
                }

                $data = $response->get_data();
                if ( is_array( $data ) ) {
                    $data_changed = false;
                    if ( ! empty( $cart_token ) ) {
                        $data['id'] = $cart_token;
                        $data_changed = true;
                    }
                    if ( $data_changed ) {
                        $response->set_data( $data );
                    }
                }
            }
        } elseif ( strpos( $route, '/wc/store/v1/batch' ) === 0 ) {
            if ( $response instanceof \WP_REST_Response ) {
                $data = $response->get_data();
                if ( is_array( $data ) && isset( $data['responses'] ) && is_array( $data['responses'] ) ) {
                    foreach ( $data['responses'] as $key => $sub_response ) {
                        if ( isset( $sub_response['body'] ) && is_array( $sub_response['body'] ) && isset( $sub_response['headers'] ) ) {
                            $sub_headers = $sub_response['headers'];
                            $sub_cart_token = '';
                            foreach ( $sub_headers as $h_key => $h_val ) {
                                $lower_h_key = strtolower( $h_key );
                                if ( $lower_h_key === 'cart-token' ) {
                                    $sub_cart_token = $h_val;
                                }
                            }

                            $sub_data = $sub_response['body'];
                            $sub_data_changed = false;
                            if ( ! empty( $sub_cart_token ) ) {
                                $sub_data['id'] = $sub_cart_token;
                                $sub_data_changed = true;
                            }
                            if ( $sub_data_changed ) {
                                $data['responses'][$key]['body'] = $sub_data;
                            }
                        }
                    }
                    $response->set_data( $data );
                }
            }
        }

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

                if ( $sync_result !== false ) {
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
     * Handle the POST request to save the session ID in the WooCommerce customer session.
     */
    public function save_session_handler( \WP_REST_Request $request ) {
        $session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
        if ( empty( $session_id ) ) {
            return new \WP_Error( 'missing_session_id', __( 'Session ID is required.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }
        if ( strlen( $session_id ) > 256 || ! preg_match( '#^(projects/[a-zA-Z0-9_\-]+/locations/[a-zA-Z0-9_\-]+/commerceSessions/[a-zA-Z0-9_\-:]+|[a-zA-Z0-9_\-:]+)$#', $session_id ) ) {
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
     * Register API Route to securely store the gecx_api_secret.
     */
    public function register_secret_rest_route(): void {
        register_rest_route( 'gecx/v1', '/secret', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_secret_handler' ],
            'permission_callback' => [ $this, 'check_admin_permissions' ],
        ] );
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
        if ( '' !== $token_broker ) {
            update_option( 'gecx_token_broker_name', $token_broker );
        }
        delete_option( 'gecx_dismiss_activation_notice' );

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
     * Helper to retrieve preferred webhook HMAC secret (consumer_secret from webhook with fallback to gecx_api_secret).
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
        if ( empty( $secret ) ) {
            $secret = (string) get_option( 'gecx_api_secret', '' );
        }
        if ( function_exists( 'apply_filters' ) ) {
            $secret = (string) apply_filters( 'gecx_api_secret', $secret );
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

            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                if ( function_exists( 'wc_get_webhooks' ) ) {
                    $existing_webhooks = wc_get_webhooks( [
                        'status' => 'any',
                        'search' => 'GECX Agent Order Created',
                        'limit'  => 25,
                    ] );
                    if ( is_array( $existing_webhooks ) ) {
                        foreach ( $existing_webhooks as $candidate ) {
                            if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                                $webhook = $candidate;
                                update_option( 'gecx_webhook_id', $webhook->get_id() );
                                break;
                            }
                        }
                    }
                }
            }

            $current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                $webhook = new \WC_Webhook();
                $webhook->set_name( 'GECX Agent Order Created' );
                $webhook->set_user_id( $current_user_id );
                $webhook->set_topic( 'order.created' );
                $webhook->set_delivery_url( $delivery_url );
                $webhook->set_api_version( 'wp_api_v3' );
                $webhook->set_status( 'active' );
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
                if ( 'active' !== $webhook->get_status() ) {
                    $webhook->set_status( 'active' );
                    $needs_update = true;
                }
                if ( $needs_update ) {
                    $webhook->save();
                }
            }
            return $webhook;
        } catch ( \Exception $e ) {
            if ( function_exists( 'error_log' ) ) {
                error_log( '[GECX] Failed to register WooCommerce order webhook: ' . $e->getMessage() );
            }
            return new \WP_Error(
                'webhook_registration_failed',
                __( 'Failed to register WooCommerce order webhook.', 'gemini-enterprise-for-cx' ),
                [ 'status' => 500 ]
            );
        }
    }

    /**
     * Handle the POST request to save the gecx_api_secret option.
     */
    public function save_secret_handler( \WP_REST_Request $request ) {
        $raw_secret      = trim( (string) $request->get_param( 'secret' ) );
        $consumer_secret = trim( (string) $request->get_param( 'consumer_secret' ) );

        if ( empty( $raw_secret ) && empty( $consumer_secret ) ) {
            return new \WP_Error( 'invalid_secret', __( 'Secret is invalid or missing.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        if ( ! empty( $raw_secret ) && ! self::is_valid_secret( $raw_secret ) ) {
            return new \WP_Error( 'invalid_secret', __( 'Secret is invalid or missing.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        if ( ! empty( $consumer_secret ) && ! self::is_valid_secret( $consumer_secret ) ) {
            return new \WP_Error( 'invalid_secret', __( 'Consumer secret is invalid.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        if ( ! empty( $raw_secret ) ) {
            // Not autoloaded: this is read by the webhook signing path and by
            // uninstall, never by a page render, so there is no reason to hold
            // it in memory for every request on the site.
            update_option( 'gecx_api_secret', $raw_secret, 'no' );
        }

        if ( class_exists( 'WC_Webhook' ) ) {
            $webhook_secret = ! empty( $consumer_secret ) ? $consumer_secret : self::get_webhook_secret();
            if ( ! empty( $webhook_secret ) ) {
                $webhook_result = self::ensure_order_webhook( '', $webhook_secret );
                if ( is_wp_error( $webhook_result ) ) {
                    return $webhook_result;
                }
            }
        }

        // New credentials are in place, so clear any invalidation recorded by
        // a previous SyncState reconciliation.
        if ( class_exists( 'GECX_Admin' ) ) {
            delete_option( GECX_Admin::STORE_AUTH_INVALID_OPTION );
        }

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Handle the POST request to register or update WooCommerce order.created webhook.
     */
    public function order_created_webhooks_handler( \WP_REST_Request $request ) {
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
     * so GET /gecx/v1/secret?rest_route=/wp/v2/users enabled key authentication
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
}
