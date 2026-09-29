<?php
/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gemini Enterprise for CX Authentication Handler
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class GECX_Auth {

    /**
     * Allowlist pattern for GCP agent and token broker resource names.
     */
    public const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_\-\.\/]+$/';

    /**
     * Production Google Cloud console origin.
     */
    public const DEFAULT_CONSOLE_BASE_URL = 'https://gecx.cloud.google.com';

    /**
     * Hosts the console base URL may point at.
     *
     * The console base URL receives the WooCommerce consumer key and secret
     * (as the wc-auth callback_url) and store-signed admin JWTs, and it can be
     * changed through the gecx_console_base_url option and filter. Pinning the
     * host means a single arbitrary option write elsewhere on the site cannot
     * redirect those credentials. Non-production hosts can be added from
     * wp-config.php via the GECX_CONSOLE_ALLOWED_HOSTS constant (array or
     * comma-separated string), which is not reachable from the database.
     */
    public const CONSOLE_ALLOWED_HOSTS = [ 'gecx.cloud.google.com' ];

    /**
     * Option set when the merchant explicitly unlinks the agent. See
     * GECX_Admin::MERCHANT_UNLINKED_OPTION.
     */
    public const MERCHANT_UNLINKED_OPTION = 'gecx_merchant_unlinked';

    /**
     * Option set when the merchant switches the storefront widget off. See
     * GECX_Admin::MERCHANT_DISABLED_OPTION.
     */
    public const MERCHANT_DISABLED_OPTION = 'gecx_merchant_disabled';

    /**
     * app_name sent to /wc-auth/v1/authorize.
     *
     * WooCommerce stores every key it issues through that flow with the
     * description "<app_name> - API (<gmdate Y-m-d H:i:s>)", which is how
     * revoke_woocommerce_api_keys() finds the keys this plugin requested.
     */
    public const WC_AUTH_APP_NAME = 'Gemini Enterprise For CX';

    /**
     * Transient name used to cache guest customer JWTs (`user_id: 0`) for a
     * fraction of their lifetime so unauthenticated auth-context / refresh-token
     * bursts do not invoke 2048-bit RSA signing on every request.
     */
    public const GUEST_JWT_CACHE_TRANSIENT = 'gecx_guest_jwt_cache';

    /**
     * Cache duration (5 minutes) for interchangeable guest customer JWTs.
     */
    private const GUEST_JWT_CACHE_TTL_SECONDS = 300;

    /**
     * Number of polls while waiting for a concurrent keypair generation.
     */
    private const KEYPAIR_WAIT_ATTEMPTS = 20;

    /**
     * Pause between polls while waiting for a concurrent keypair generation.
     */
    private const KEYPAIR_WAIT_INTERVAL_MICROSECONDS = 100000;

    /**
     * How long gecx_keypair_lock is honoured before it is treated as abandoned.
     *
     * Shared by the reclaim in acquire_keypair_lock() and the give-up check in
     * wait_for_concurrent_keypair(), so a waiter can never outlive the lock it
     * is waiting on.
     */
    private const KEYPAIR_LOCK_TTL_SECONDS = 30;

    /**
     * Option holding the store's RSA keypair.
     *
     * Both halves live in one option, and that is load-bearing rather than
     * tidiness. They used to be two, written by two update_option() calls, and
     * gecx_keypair_lock is not a strong enough mutex to guarantee those four
     * writes never interleave: add_option() checks for the option with
     * get_option() and only then issues INSERT ... ON DUPLICATE KEY UPDATE, so
     * two workers arriving together can both be told they hold the lock.
     *
     * Interleaved writes produced one worker's public key beside another's
     * private key. Nothing detected it - the private key still decrypted, and
     * openssl_sign() still signed with it - but every JWT the store minted then
     * failed verification against the public key the backend read, including
     * the re-fetch in VerifyAdminJwtWithSelfHealing, since that reads the same
     * mismatched record. One option makes that unrepresentable: a single row
     * write is atomic, so a lost race costs a wasted keypair generation rather
     * than a store that can no longer authenticate.
     */
    private const KEYPAIR_OPTION = 'gecx_keypair';

    /**
     * Capabilities that must never be reachable from a shopper credential.
     *
     * Filterable via 'gecx_cart_token_privileged_caps'. Widening the list is
     * always safe. Narrowing it is the operator's risk: every capability
     * removed becomes reachable from a stolen cart token.
     */
    private const PRIVILEGED_CAPS = [
        'manage_options',
        'manage_woocommerce',
        'edit_shop_orders',
        'edit_posts',
    ];

    /**
     * Stable refusal codes for cart token authentication.
     */
    public const REFUSAL_CODE_CAPABILITY_HELD  = 'capability_held';
    public const REFUSAL_CODE_USER_UNRESOLVED  = 'user_unresolved';
    public const REFUSAL_CODE_CAPS_UNAVAILABLE = 'caps_api_unavailable';

    /**
     * Refusal reasons that are not a held capability (for logging).
     *
     * A cart token is refused either because the named user holds something
     * privileged, or because the question could not be answered at all. The
     * two are indistinguishable to the caller if both are reported as a
     * capability name, and the second produces a log line asserting a
     * privilege the user may not hold.
     */
    private const REFUSAL_CAPS_UNAVAILABLE = 'capability API unavailable';
    private const REFUSAL_USER_UNRESOLVED  = 'user could not be resolved';

    /**
     * Request-scoped: true once the current user was resolved from a Cart-Token.
     */
    private static bool $authenticated_via_cart_token = false;

    /**
     * Re-entrancy guard. user_can() applies the 'user_has_cap' filter, which
     * third-party code may hook with calls back into wp_get_current_user().
     */
    private static bool $resolving_cart_token = false;

    /**
     * Constructor.
     */
    public function __construct() {
        // Authenticate request using Cart-Token if valid.
        add_filter( 'determine_current_user', [ $this, 'authenticate_via_cart_token' ], 20 );

        // Backstop, on the dispatch path rather than in individual permission
        // callbacks. See block_cart_token_off_store_api().
        add_filter( 'rest_pre_dispatch', [ $this, 'block_cart_token_off_store_api' ], 10, 3 );
    }

    /**
     * Resolve the Google Cloud console base URL.
     *
     * Reads the gecx_console_base_url option and filter, then accepts the
     * result only if is_allowed_console_base_url() does. Anything else yields
     * an empty string so callers send nothing: falling back to the production
     * host would turn a misconfigured staging or test install into one that
     * talks to production.
     *
     * @return string Base URL without a trailing slash, or '' when the
     *                configured value is not allowed.
     */
    public static function get_console_base_url(): string {
        $configured = get_option( 'gecx_console_base_url', self::DEFAULT_CONSOLE_BASE_URL );
        $configured = apply_filters( 'gecx_console_base_url', is_string( $configured ) ? $configured : '' );
        $configured = is_string( $configured ) ? trim( $configured ) : '';

        if ( ! self::is_allowed_console_base_url( $configured ) ) {
            static $logged = false;
            if ( ! $logged ) {
                $logged = true;
                self::log( 'Refusing console base URL that is not an allowed https origin.', 'warning' );
            }
            return '';
        }

        return rtrim( $configured, '/' );
    }

    /**
     * Whether a URL is an https origin on an allowed console host.
     *
     * Userinfo, ports, paths, queries and fragments are all rejected: the
     * plugin appends its own paths, and any of those components can be used
     * to make a URL look like it names one host while connecting to another.
     *
     * @param string $url Candidate base URL.
     */
    public static function is_allowed_console_base_url( string $url ): bool {
        if ( '' === $url ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
            return false;
        }
        foreach ( [ 'user', 'pass', 'port', 'query', 'fragment' ] as $component ) {
            if ( isset( $parts[ $component ] ) ) {
                return false;
            }
        }
        if ( '' !== trim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
            return false;
        }

        return in_array( strtolower( (string) $parts['host'] ), self::get_allowed_console_hosts(), true );
    }

    /**
     * Allowed console hosts, including any added by GECX_CONSOLE_ALLOWED_HOSTS.
     *
     * @return string[] Lower-case host names.
     */
    private static function get_allowed_console_hosts(): array {
        $hosts = self::CONSOLE_ALLOWED_HOSTS;

        if ( defined( 'GECX_CONSOLE_ALLOWED_HOSTS' ) ) {
            $extra = constant( 'GECX_CONSOLE_ALLOWED_HOSTS' );
            if ( is_string( $extra ) ) {
                $extra = explode( ',', $extra );
            }
            if ( is_array( $extra ) ) {
                foreach ( $extra as $host ) {
                    if ( is_string( $host ) && '' !== trim( $host ) ) {
                        $hosts[] = trim( $host );
                    }
                }
            }
        }

        return array_values( array_unique( array_map( 'strtolower', $hosts ) ) );
    }

    /**
     * Delete every WooCommerce REST API key issued to this plugin by
     * /wc-auth/v1/authorize.
     *
     * WooCommerce stores those keys locally in {prefix}woocommerce_api_keys and
     * authenticates each request against that table, so deleting the rows is
     * what revokes them; nothing on the Google side has to agree. Keys are
     * matched on the description WooCommerce derives from WC_AUTH_APP_NAME
     * and on the read_write permission this plugin requests, which also
     * sweeps up keys left over from earlier re-authorizations. The permission
     * check keeps a key the merchant created by hand with a similar
     * description (LIKE is case-insensitive under the default collation) out
     * of the match unless it also has read_write access.
     *
     * @return int Number of keys deleted.
     */
    public static function revoke_woocommerce_api_keys(): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';

        // WooCommerce may have been deleted along with its tables.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, nothing to cache.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $found !== $table ) {
            return 0;
        }

        $description_like = $wpdb->esc_like( self::WC_AUTH_APP_NAME . ' - API (' ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce exposes no API for deleting keys by description, and keys are not cached.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE description LIKE %s AND permissions = %s',
                $table,
                $description_like,
                'read_write'
            )
        );

        return is_int( $deleted ) ? $deleted : 0;
    }

    /**
     * Refuses any request that was authenticated from a cart token but is not
     * being dispatched to the Store API.
     *
     * authenticate_via_cart_token() already declines to authenticate outside
     * the Store API, but it runs on 'determine_current_user', which can fire
     * before WP::parse_request() has resolved the route. When it does, that
     * filter is reading a request the way WordPress is about to interpret it,
     * and this one is reading the route WordPress actually resolved. This is
     * where the two are reconciled.
     *
     * Placing it here rather than in each permission callback means a new
     * privileged endpoint is covered by default: a backstop that has to be
     * remembered at every call site is not a backstop.
     *
     * This fires for internal dispatches too. Code that calls
     * rest_do_request() for a non-Store-API route while serving a Store API
     * request authenticated by a cart token gets a 403, not a response. That
     * is deliberate: an internal dispatch runs with the same current user, so
     * exempting it would reopen the hole. Store API batch requests are
     * unaffected, since '/wc/store/v1/batch' matches the pattern itself.
     *
     * @param mixed            $result  Existing short-circuit response, if any.
     * @param mixed            $server  REST server instance.
     * @param \WP_REST_Request $request Dispatched request.
     * @return mixed
     */
    public function block_cart_token_off_store_api( $result, $server, $request ) {
        if ( ! self::$authenticated_via_cart_token ) {
            return $result;
        }

        $route = ( $request instanceof \WP_REST_Request ) ? $request->get_route() : null;
        if ( self::is_store_api_route( $route ) ) {
            return $result;
        }

        return new \WP_Error(
            'rest_forbidden',
            __( 'Sorry, you are not allowed to do that.', 'gemini-enterprise-for-cx' ),
            [ 'status' => 403 ]
        );
    }

    /**
     * Whether the current request was authenticated from a Cart-Token.
     *
     * Permission callbacks guarding privileged operations MUST reject when this
     * returns true: a cart token identifies a shopper session, not an operator.
     */
    public static function is_cart_token_request(): bool {
        return self::$authenticated_via_cart_token;
    }

    /**
     * Resets the request-scoped authentication state.
     *
     * Test seam only. This is security state: clearing it mid-request would
     * re-enable privileged endpoints for a cart-token request, so the body is
     * inert unless the test harness has defined GECX_PHPUNIT_RUNNING.
     *
     * @internal
     */
    public static function reset_cart_token_state(): void {
        if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || ! GECX_PHPUNIT_RUNNING ) {
            return;
        }

        self::$authenticated_via_cart_token = false;
        self::$resolving_cart_token         = false;
    }

    /**
     * Registers diagnostic or error messages in the WooCommerce log (WooCommerce > Status > Logs)
     * under the 'gecx' log source.
     *
     * @param string $message Log message.
     * @param string $level   Log level ('error', 'warning', 'info', 'debug').
     */
    public static function log( string $message, string $level = 'error' ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            if ( is_object( $logger ) && method_exists( $logger, $level ) ) {
                $logger->{$level}( $message, [ 'source' => 'gecx' ] );
            }
        }
    }

    /**
     * Authenticate REST API requests from the GECX agent backend using the Cart-Token header
     * so that the agent can read and write the cart on behalf of a logged-in user.
     *
     * WooCommerce already selects the correct session from this token via
     * Authentication::maybe_use_store_api_session_handler(); this filter only
     * supplies the identity needed to associate writes with the shopper's
     * account. It is deliberately narrow, because a cart token is a weak
     * credential: storefront JavaScript can read it, it is valid for 48h, it
     * cannot be revoked, and WooCommerce serves it cross-origin
     * (Authentication::send_cors_headers grants Allow-Origin plus
     * Allow-Credentials to any origin presenting a valid one).
     */
    public function authenticate_via_cart_token( $user_id ) {
        if ( ! empty( $user_id ) || self::$resolving_cart_token ) {
            return $user_id;
        }

        $cart_token = isset( $_SERVER['HTTP_CART_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) ) : '';
        if ( empty( $cart_token ) ) {
            return $user_id;
        }

        // Restrict to the WooCommerce Store API. This plugin's own /gecx/ routes
        // carry administrative capability and authenticate via WooCommerce API
        // keys instead; a shopper credential must never reach them.
        if ( ! self::is_store_api_request() ) {
            return $user_id;
        }

        $payload = self::verify_cart_token( $cart_token );
        if ( null === $payload ) {
            return $user_id;
        }

        // Guests carry a random session hash rather than a numeric ID.
        // WooCommerce resolves their cart without any identity assertion.
        // ctype_digit() rather than is_numeric(), which accepts exponent and
        // hex-adjacent notation that (int) then reinterprets: (int) '1e3' is
        // 1000, so is_numeric() would let one string name a different user.
        // The type test is separate because (string) true is '1', which would
        // otherwise resolve a boolean subject to user 1.
        $token_user_id = $payload->user_id ?? '';
        if ( ! is_int( $token_user_id ) && ! is_string( $token_user_id ) ) {
            return $user_id;
        }
        if ( ! ctype_digit( (string) $token_user_id ) || (int) $token_user_id <= 0 ) {
            return $user_id;
        }
        $token_user_id = (int) $token_user_id;

        self::$resolving_cart_token = true;
        try {
            $refusal = self::cart_token_refusal_reason( $token_user_id );
        } finally {
            self::$resolving_cart_token = false;
        }

        if ( null !== $refusal ) {
            // Note this also catches shoppers who happen to be contributors
            // or authors on the same site, a common blog-plus-store setup.
            // Their cart is unaffected: StoreApi\SessionHandler keys the
            // session off the token's own user_id claim, not off the user
            // this filter resolves. What they lose is the WordPress
            // identity for this request, so anything reading
            // get_current_user_id() sees a guest, including
            // StoreApi\Utilities\OrderController::update_order_from_cart(),
            // which means an order placed through the agent is not
            // attached to their account.
            //
            // Fired unconditionally outside the re-entrancy guard: production
            // stores run with WP_DEBUG off, which is exactly when this needs
            // diagnosing.
            do_action( 'gecx_cart_token_refused', $token_user_id, $refusal['code'], $refusal['capability'] );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                self::log(
                    sprintf(
                        'Cart-Token refused for user %d: %s.',
                        $token_user_id,
                        $refusal['reason']
                    ),
                    'debug'
                );
            }
            return $user_id;
        }

        // Deliberately sticky for the rest of the request. If something later
        // in the filter chain resolves a different user, the fact that a cart
        // token participated in authenticating this request still holds, and
        // keeping the flag raised is the safe direction: it only ever causes
        // privileged endpoints to reject.
        self::$authenticated_via_cart_token = true;
        return $token_user_id;
    }

    /**
     * Extracts and validates the customer ID (user_id) from a Store API Cart-Token.
     *
     * @param string $cart_token Raw Cart-Token value.
     * @return string Validated customer ID or empty string if invalid.
     */
    public static function get_cart_token_customer_id( string $cart_token ): string {
        $payload = self::verify_cart_token( $cart_token );
        if ( null === $payload || ! isset( $payload->user_id ) ) {
            return '';
        }
        $user_id = $payload->user_id;
        if ( ! is_string( $user_id ) && ! is_int( $user_id ) ) {
            return '';
        }
        return (string) $user_id;
    }

    /**
     * Verifies a WooCommerce Store API cart token.
     *
     * @param string $cart_token Raw Cart-Token header value.
     * @return object|null Decoded payload, or null when the token is unusable.
     */
    public static function verify_cart_token( string $cart_token ): ?object {
        $parts = explode( '.', $cart_token );
        if ( count( $parts ) !== 3 ) {
            return null;
        }

        // Verify the signature before decoding anything, so the JSON parser is
        // never handed attacker-controlled data and nothing below reasons
        // about it. The header is covered by the signature, so it is checked
        // after this rather than before.
        $secret                        = '@' . wp_salt();
        $regenerated_signature         = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );
        $encoded_regenerated_signature = self::to_base_64_url( $regenerated_signature );

        if ( ! hash_equals( $encoded_regenerated_signature, $parts[2] ) ) {
            return null;
        }

        $header  = json_decode( self::from_base_64_url( $parts[0] ) );
        $payload = json_decode( self::from_base_64_url( $parts[1] ) );

        if ( ! is_object( $header ) || ! is_object( $payload ) ) {
            return null;
        }

        if (
            ! property_exists( $header, 'typ' ) ||
            ! property_exists( $header, 'alg' ) ||
            'JWT' !== $header->typ ||
            'HS256' !== $header->alg
        ) {
            return null;
        }

        // Pin the issuer so only WooCommerce-minted Store API cart tokens are
        // accepted, never some other token that happens to share wp_salt().
        if ( ! property_exists( $payload, 'iss' ) || ! self::is_known_cart_token_issuer( $payload->iss ) ) {
            self::report_unknown_cart_token_issuer( $payload->iss ?? null );
            return null;
        }

        if ( ! property_exists( $payload, 'exp' ) ) {
            return null;
        }

        $exp = $payload->exp;
        if ( ! is_int( $exp ) && ! is_string( $exp ) ) {
            return null;
        }
        if ( ! ctype_digit( (string) $exp ) ) {
            return null;
        }
        if ( time() >= (int) $exp ) {
            return null;
        }

        return $payload;
    }

    /**
     * Whether an 'iss' claim names a WooCommerce Store API cart token issuer.
     *
     * WooCommerce has stamped the claim two different ways, and the older one
     * is not a fixed string, so this matches the shape rather than a list of
     * literals:
     *
     * - WooCommerce 10.0 and later mint in
     *   StoreApi\Utilities\CartTokenUtils::get_cart_token(), which stamps the
     *   literal 'store-api' whatever route served the response.
     * - WooCommerce 7.1 through 9.9 mint in
     *   StoreApi\Routes\V1\AbstractCartRoute::get_cart_token(), which stamps
     *   the minting route's own namespace. StoreApi\RoutesController
     *   registers every Store API route twice, under 'wc/store' and under
     *   'wc/store/v1', so a cart response served from the unversioned legacy
     *   alias mints 'iss' => 'wc/store'.
     *
     * Which namespace minted the token is independent of where it is
     * presented: the scoping check still requires this request to be on a
     * versioned Store API route, so accepting the legacy alias here does not
     * widen what a token can reach.
     *
     * 'wc/private' is deliberately excluded. RoutesController registers it as
     * a third namespace, but its only member is Routes\V1\Patterns, which
     * extends AbstractRoute rather than AbstractCartRoute and so mints no
     * cart token at all (checked on 9.9.0, 10.0.0 and 11.1.0). The same goes
     * for 'wc/agentic/v1', added in 11.x, which only exists on versions that
     * stamp the literal anyway.
     *
     * WooCommerce before 7.1 stamps no 'iss'; see the 'WC requires at least'
     * header.
     *
     * @param mixed $iss Raw claim value.
     */
    private static function is_known_cart_token_issuer( $iss ): bool {
        if ( ! is_string( $iss ) ) {
            return false;
        }

        if ( 'store-api' === $iss ) {
            return true;
        }

        return 1 === preg_match( '#^wc/store(/v\d+)?$#', $iss );
    }

    /**
     * Announces a correctly signed cart token carrying an unrecognised issuer.
     *
     * Every other refusal reaches 'gecx_cart_token_refused'. This one used to
     * return silently, which made it the least diagnosable and the most likely
     * to fire: the accepted issuers track WooCommerce's own internals, so a
     * WooCommerce version stamping something new degrades every logged-in
     * shopper on the store with no signal anywhere.
     *
     * Only reachable after hash_equals() has passed, so the value came from
     * something holding wp_salt() rather than from a caller. Malformed and
     * badly signed tokens stay silent and cannot be used to fill the log.
     *
     * Action is fired on every request; logging is throttled via transient to
     * once per hour per issuer value.
     *
     * @param mixed $iss Rejected claim, or null when the token carried none.
     */
    private static function report_unknown_cart_token_issuer( $iss ): void {
        $reported = is_string( $iss ) ? $iss : '(absent)';

        /**
         * Fires when a signed cart token names an issuer the plugin does not
         * recognise. Handlers receive the raw claim.
         *
         * @param string $reported Rejected issuer, or '(absent)'.
         */
        do_action( 'gecx_cart_token_unknown_issuer', $reported );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $transient_key = 'gecx_unknown_iss_' . md5( $reported );
            $throttled     = function_exists( 'get_transient' ) && false !== get_transient( $transient_key );
            if ( ! $throttled ) {
                if ( function_exists( 'set_transient' ) ) {
                    set_transient( $transient_key, 1, defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
                }
                self::log(
                    sprintf(
                        'Cart-Token refused: unrecognised issuer %s. Check the WooCommerce version against the accepted issuers.',
                        wp_json_encode( $reported )
                    ),
                    'warning'
                );
            }
        }
    }

    /**
     * Returns the refusal details when the user holds a capability that must
     * not be reachable from a shopper credential or cannot be checked,
     * or null when they hold nothing privileged.
     *
     * Fails closed when capability APIs are unavailable, and when the user
     * cannot be resolved at all: a token naming a deleted user should not
     * authenticate anything.
     *
     * Two sources are consulted and the answer is their union:
     *
     * - WP_User::$allcaps is the raw role/user grant. It misses capabilities a
     *   plugin only grants dynamically through the 'user_has_cap' filter.
     * - user_can() applies that filter, but we call it behind a re-entrancy
     *   guard, and while the guard is up a hook that calls back into
     *   wp_get_current_user() sees user 0. A plugin that grants capabilities
     *   conditionally on the current user will therefore under-report.
     *
     * The two capability loops look redundant because user_can() consults
     * allcaps internally, but they are not: checking allcaps first ensures that
     * static role capabilities are caught even if a user_has_cap hook calls
     * wp_get_current_user() and suffers from guard-induced under-reporting.
     *
     * Privileged endpoints reject cart-token requests outright via
     * GECX_Auth::is_cart_token_request() regardless of what this returns.
     *
     * @param int $user_id User ID to inspect.
     * @return array{code: string, capability: ?string, reason: string}|null
     *                     Refusal details or null when the user holds nothing privileged.
     */
    private static function cart_token_refusal_reason( int $user_id ): ?array {
        if ( ! function_exists( 'user_can' ) || ! function_exists( 'get_userdata' ) ) {
            return [
                'code'       => self::REFUSAL_CODE_CAPS_UNAVAILABLE,
                'capability' => null,
                'reason'     => self::REFUSAL_CAPS_UNAVAILABLE,
            ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [
                'code'       => self::REFUSAL_CODE_USER_UNRESOLVED,
                'capability' => null,
                'reason'     => self::REFUSAL_USER_UNRESOLVED,
            ];
        }

        $privileged_caps = self::privileged_caps();

        // Check raw allcaps first: user_can() consults allcaps internally, but
        // user_can() also applies the 'user_has_cap' filter under our re-entrancy
        // guard. A filter callback calling wp_get_current_user() sees user 0 and
        // may under-report or clear capabilities. Checking allcaps directly first
        // avoids this under-reporting for static grants.
        if ( ! empty( $user->allcaps ) && is_array( $user->allcaps ) ) {
            foreach ( $privileged_caps as $capability ) {
                if ( ! empty( $user->allcaps[ $capability ] ) ) {
                    return [
                        'code'       => self::REFUSAL_CODE_CAPABILITY_HELD,
                        'capability' => $capability,
                        'reason'     => self::held_capability_reason( $capability ),
                    ];
                }
            }
        }

        // user_can() accepts a WP_User, so reuse the object already resolved
        // above rather than making it look the user up again per capability.
        foreach ( $privileged_caps as $capability ) {
            if ( user_can( $user, $capability ) ) {
                return [
                    'code'       => self::REFUSAL_CODE_CAPABILITY_HELD,
                    'capability' => $capability,
                    'reason'     => self::held_capability_reason( $capability ),
                ];
            }
        }

        return null;
    }

    /**
     * Capabilities a cart token must never reach, after filtering.
     *
     * @return string[]
     */
    private static function privileged_caps(): array {
        if ( ! function_exists( 'apply_filters' ) ) {
            return self::PRIVILEGED_CAPS;
        }

        /**
         * Filters the capabilities that disqualify a user from cart-token
         * authentication.
         *
         * 'edit_posts' is held by contributors and above, so on a
         * blog-plus-store site a shopper who also writes posts is refused a
         * WordPress identity for the request. Their cart still resolves from
         * the token, but an order placed through the agent is not attached to
         * their account. Sites that want those shoppers fully served by the
         * agent can remove it here, accepting that the capability then becomes
         * reachable from a stolen cart token.
         *
         * Returning a non-array, or an array with no strings in it, restores
         * the default list: disabling this check wholesale is not something a
         * filter should be able to do by accident.
         *
         * @param string[] $caps Capability names.
         */
        $caps = apply_filters( 'gecx_cart_token_privileged_caps', self::PRIVILEGED_CAPS );

        if ( ! is_array( $caps ) ) {
            return self::PRIVILEGED_CAPS;
        }

        $caps = array_filter( $caps, function( $cap ): bool {
            return is_string( $cap ) && '' !== trim( $cap );
        } );

        return empty( $caps ) ? self::PRIVILEGED_CAPS : array_values( $caps );
    }

    /**
     * @param string $capability Capability the user holds.
     */
    private static function held_capability_reason( string $capability ): string {
        return sprintf( 'holds privileged capability "%s"', $capability );
    }

    /**
     * Whether this request entered through WordPress's front controller.
     *
     * The fallback tiers describe how WP::parse_request() would route a
     * request, which only means anything if parse_request() is going to run.
     * It runs from wp(), which runs from wp-blog-header.php, which is included
     * by index.php. Other entry points load WordPress without it:
     * admin-ajax.php, wp-comments-post.php, wp-cron.php and any plugin file
     * loaded directly. On those, a 'rest_route' parameter names nothing, no
     * REST route is ever dispatched, and 'rest_pre_dispatch' never fires, so
     * the backstop in block_cart_token_off_store_api() does not apply either.
     *
     * Without this gate, POST /wp-admin/admin-ajax.php?rest_route=/wc/store/v1/cart
     * with a stolen Cart-Token runs the wp_ajax_{$action} handler as that
     * shopper.
     *
     * Tier 1 is deliberately checked before this: a resolved query var can
     * only exist because parse_request() already ran.
     */
    private static function is_front_controller_request(): bool {
        if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
             ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ||
             ( function_exists( 'is_admin' ) && is_admin() ) ) {
            return false;
        }

        if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && defined( 'ABSPATH' ) && is_string( ABSPATH ) ) {
            $normalize       = function_exists( 'wp_normalize_path' ) ? 'wp_normalize_path' : static function( $p ) {
                return str_replace( '\\', '/', (string) $p );
            };
            $script_filename = sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) );
            if ( $normalize( $script_filename ) !== $normalize( rtrim( ABSPATH, '/\\' ) . '/index.php' ) ) {
                return false;
            }
        }

        $script = isset( $_SERVER['SCRIPT_NAME'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) )
            : '';

        $home_path = '';
        if ( function_exists( 'home_url' ) ) {
            $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        }
        $home_path = trim( $home_path, '/' );
        if ( '' !== $home_path ) {
            $home_path = '/' . $home_path;
            if ( strpos( $script, $home_path . '/' ) === 0 ) {
                $script = substr( $script, strlen( $home_path ) );
            }
        }

        if ( '/index.php' === $script || 'index.php' === $script ) {
            return true;
        }

        // A host that reports something else here disables cart-token
        // authentication site-wide. That is the safe direction, but it is
        // otherwise silent: this returns before any token is read, so the
        // 'gecx_cart_token_refused' action never fires for it.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            self::log(
                sprintf(
                    'Cart-Token scope check skipped: entry point is "%s", not index.php.',
                    $script
                ),
                'debug'
            );
        }

        return false;
    }

    /**
     * Whether the current request targets the WooCommerce Store API.
     */
    private static function is_store_api_request(): bool {
        return self::is_store_api_route( self::resolve_rest_route() );
    }

    /**
     * The REST route WordPress is going to dispatch for this request.
     *
     * That route is $GLOBALS['wp']->query_vars['rest_route'], which
     * WP_REST_Server reads in rest_api_loaded(). It is used whenever it is
     * available, including when it is empty: an empty value means WordPress
     * resolved no REST route, and rest_api_loaded() bails on it, so nothing is
     * dispatched.
     *
     * It is not always available. Its one caller, is_store_api_request(), runs
     * from 'determine_current_user', which fires before WP::parse_request()
     * has: WP::init() resolves the current user immediately before
     * WP::parse_request() on every front controller request, and another plugin
     * calling wp_get_current_user() during 'plugins_loaded' moves it earlier
     * still. The fallback therefore mirrors the precedence WP::parse_request()
     * applies to a public query var, which is WP::$extra_query_vars, then
     * $_POST, then $_GET, then the rewritten path. Checking the path first, or
     * skipping a tier, would let a request name one route in the place this
     * reads and have WordPress dispatch another.
     *
     * The tiers exist because cart-token authentication has to answer while the
     * request is still being determined and has no second chance: unlike the
     * WooCommerce key path, nothing re-offers it after parse_request(). That is
     * why is_request_to_route() does not share them.
     *
     * The fallback tiers additionally require the request to have entered
     * through the front controller. See is_front_controller_request().
     *
     * @return mixed Route as WordPress would resolve it, '' when it resolves
     *               none, or null when this request cannot name one at all. A
     *               rest_route[]= parameter arrives as an array and is returned
     *               as one; callers must not assume a string.
     */
    private static function resolve_rest_route() {
        if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
            return $GLOBALS['wp']->query_vars['rest_route'];
        }

        if ( ! self::is_front_controller_request() ) {
            return null;
        }

        $sources = [];
        // WP::$extra_query_vars is assigned inside parse_request(), so in the
        // pre-parse_request path it is always the empty default. Correctly
        // ordered and harmless; defence in depth rather than a live tier.
        if ( isset( $GLOBALS['wp']->extra_query_vars ) && is_array( $GLOBALS['wp']->extra_query_vars ) ) {
            $sources[] = $GLOBALS['wp']->extra_query_vars;
        }
        // Route resolution only, mirroring how WordPress itself reads these
        // superglobals in WP::parse_request() before any handler runs. Nothing
        // here acts on the request or writes state, and REST requests carry
        // their own authentication rather than a nonce. $_POST raises the
        // .Missing variant of this sniff and $_GET the .Recommended one, so
        // the exception has to name the sniff rather than either code.
        // phpcs:disable WordPress.Security.NonceVerification
        $sources[] = $_POST;
        $sources[] = $_GET;

        foreach ( $sources as $source ) {
            if ( isset( $source['rest_route'] ) ) {
                // Returned raw. The consumer, is_store_api_route(), matches it
                // against an anchored allowlist and nothing echoes or stores
                // it. sanitize_text_field() only removes characters, so running
                // it here would leave the plugin matching against a different
                // string from the one WordPress dispatches. That divergence is
                // the bug already removed from the REQUEST_URI tier below.
                //
                // Non-string values (rest_route[]=x yields an array) are passed
                // through untouched; the consumer rejects anything that is not
                // a string.
                return wp_unslash( $source['rest_route'] );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification

        // Deliberately not sanitize_text_field(). That strips every
        // %[a-f0-9]{2} sequence, so the plain-permalink form
        // "/index.php?rest_route=%2Fgecx%2Fv1%2Fpublic-key" would arrive here
        // as "/index.php?rest_route=gecxv1public-key" and no route would ever
        // match. The value is parsed for a route below and is never echoed or
        // stored, and neither is the route that comes out of the parse: every
        // tier of this function returns the route WordPress itself would
        // dispatch, unaltered.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed as a URL, never output. See above.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

        // Duplicates the $_GET tier above and is unreachable under any SAPI
        // that populates $_GET, which is all of them. Kept because the tests
        // drive this code through REQUEST_URI alone, and because a caller that
        // has emptied $_GET should still get the right answer.
        $query_str    = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
        $query_params = [];
        parse_str( $query_str, $query_params );
        if ( isset( $query_params['rest_route'] ) && is_string( $query_params['rest_route'] ) ) {
            // Returned raw, for the same reason the $_GET tier above is.
            // parse_str() has already percent-decoded this, so the octet
            // stripping that broke the REQUEST_URI path has nothing left to
            // strip in the ordinary case; a double-encoded value is the one
            // that diverges, and it diverges in the direction of matching a
            // route WordPress will not dispatch.
            return $query_params['rest_route'];
        }

        return self::route_from_path( $request_uri );
    }

    /**
     * Whether the route WordPress has resolved for this request is one of
     * $routes.
     *
     * For callers that need to scope themselves to a fixed set of the plugin's
     * own routes, rather than to a namespace as is_store_api_route() does.
     *
     * Only a route WordPress has already resolved counts, so this deliberately
     * does not use resolve_rest_route(): that infers a route from $_POST, $_GET
     * or the request path when WP::parse_request() has not run, and for this
     * caller it never has. WP::init() resolves the current user immediately
     * before WP::parse_request(), so on a front controller request
     * 'determine_current_user' always fires first, with no route resolved and
     * nothing to compare against but the request's own unparsed claims.
     *
     * The sole caller widens WooCommerce API key authentication to the routes
     * in GECX_Rest_Console_API::WC_AUTHENTICATED_ROUTES. Answering from an inferred
     * route is what lets the route matched here differ from the route
     * WordPress goes on to dispatch, and a key admitted for the wrong route
     * carries the key owner's full WordPress capabilities into it.
     *
     * Declining to answer early costs nothing. WooCommerce's
     * WC_REST_Authentication::authentication_fallback() runs on
     * 'rest_authentication_errors', inside WP_REST_Server::serve_request(), and
     * opens by calling get_current_user_id(). With no user resolved yet that
     * re-runs 'determine_current_user', this time after parse_request(), so the
     * key is offered again against the route WordPress actually resolved and
     * authenticates there. Confirmed end to end on WP 6.2/WC 7.1.0,
     * WP 7.1/WC 9.9.0 and WP 7.1/WC 11.1.0, under both permalink styles.
     *
     * @param string[] $routes Routes to match, without a leading slash.
     */
    public static function is_request_to_route( array $routes ): bool {
        if ( ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
            return false;
        }

        $route = $GLOBALS['wp']->query_vars['rest_route'];
        if ( ! is_string( $route ) ) {
            return false;
        }

        return in_array( ltrim( untrailingslashit( $route ), '/' ), $routes, true );
    }

    /**
     * Extracts the REST route from a request path, as WordPress does.
     *
     * The rewrite rule WordPress installs strips the home path and REST url
     * prefix and hands the remainder to the server as the route, so the home path
     * is stripped and the prefix is required at offset 0.
     * A path with no prefix at the site root is not a pretty-permalink REST request
     * at all.
     *
     * @param string $request_uri Raw request URI.
     * @return string Route with a leading slash, or '' when none was found.
     */
    private static function route_from_path( string $request_uri ): string {
        // Strip query string first without using parse_url to preserve the leading
        // path structure and avoid treating //host as a protocol-relative authority.
        $path = explode( '?', $request_uri, 2 )[0];

        $home_path = '';
        if ( function_exists( 'home_url' ) ) {
            $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        }
        $home_path = trim( $home_path, '/' );
        if ( '' !== $home_path ) {
            $home_path = '/' . $home_path;
            if ( strpos( $path, $home_path . '/' ) === 0 ) {
                $path = substr( $path, strlen( $home_path ) );
            } elseif ( $path === $home_path ) {
                $path = '';
            } else {
                return '';
            }
        }

        $prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
        $prefix = trim( (string) $prefix, '/' );
        if ( '' === $prefix ) {
            return '';
        }

        $expected_prefix = '/' . $prefix . '/';
        if ( strpos( $path, $expected_prefix ) !== 0 ) {
            return '';
        }

        // Keep the separator's trailing slash so the route is returned in the
        // '/wc/store/v1/cart' form WordPress resolves it to.
        return substr( $path, strlen( $expected_prefix ) - 1 );
    }

    /**
     * Whether a resolved REST route names a versioned Store API cart or batch endpoint.
     *
     * Cart-Token authentication is strictly restricted to cart management routes
     * ('/wc/store/v1/cart', '/wc/store/v1/cart/...', and '/wc/store/v1/batch').
     * Non-cart Store API endpoints such as '/wc/store/v1/order/...' and
     * '/wc/store/v1/checkout/...' are deliberately excluded so a Cart-Token
     * cannot be used to enumerate orders or bypass guest order verification.
     *
     * @param mixed $route Route as WordPress would resolve it. A rest_route[]=
     *                     query parameter arrives as an array, which cannot
     *                     name a route.
     */
    private static function is_store_api_route( $route ): bool {
        if ( ! is_string( $route ) ) {
            return false;
        }

        return 1 === preg_match( '#^/wc/store/v\d+/(cart(/.*)?|batch)$#', $route );
    }

    /**
     * Generate a signed customer JWT for the active logged-in user or guest.
     *
     * The token always asserts is_admin: false, whatever the shopper can do in
     * WordPress. It is handed to the chat widget over
     * POST /gecx/v1/auth-context and held in the DOM as a property of the
     * widget element, where any script on the page can read it, so it must not
     * carry a claim that grants anything. An operator who needs one calls
     * generate_admin_jwt(), which gates on capability and lives for 300
     * seconds rather than an hour.
     *
     * @param int|null $user_id Optional user ID. If null, resolved from current logged-in user or defaults to 0 (guest).
     * @param int $expiration Expiration duration in seconds (default 3600).
     * @param string|null $email Optional email. If null, resolved from user ID or current user.
     * @return string|null Signed JWT string, or null if the store's RSA private key is unavailable.
     */
    public static function generate_customer_jwt( ?int $user_id = null, int $expiration = 3600, ?string $email = null ): ?string {
        return self::generate_jwt_internal( $user_id, false, $expiration, $email );
    }

    /**
     * Generate a signed short-lived admin JWT with is_admin: true for management actions.
     *
     * @param int|null $user_id Optional user ID. If null, the current logged-in user ID is used.
     * @param int $expiration Expiration duration in seconds (default 300).
     * @param string|null $email Optional email. If null, resolved from user ID or current user.
     * @return string|null Signed JWT string, or null if the user is unauthorized or the store's RSA private key is unavailable.
     */
    public static function generate_admin_jwt( ?int $user_id = null, int $expiration = 300, ?string $email = null ): ?string {
        return self::build_admin_jwt( $user_id, $expiration, $email, true );
    }

    /**
     * Mint an admin RS256 JWT using only an already-stored, decryptable keypair.
     *
     * Used during uninstall and background tasks so plugin deletion never
     * generates a new keypair and never writes options. When invoked from
     * `uninstall.php` or WP-CLI (`wp plugin uninstall ...`) with no logged-in
     * user, falls back to the store's lowest-ID administrator so the backend
     * accepts the signed unlink request.
     *
     * @param int|null    $user_id    Optional user ID.
     * @param int         $expiration Expiration duration in seconds (default 300).
     * @param string|null $email      Optional email.
     * @return string|null Signed JWT string, or null if unavailable.
     */
    public static function generate_existing_rs256_admin_jwt( ?int $user_id = null, int $expiration = 300, ?string $email = null ): ?string {
        if ( null === $user_id ) {
            $logged_in_id = ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'get_current_user_id' ) )
                ? (int) get_current_user_id()
                : 0;
            $allow_unauthenticated_fallback = ( defined( 'WP_CLI' ) && WP_CLI )
                || ( defined( 'WP_UNINSTALL_PLUGIN' ) && WP_UNINSTALL_PLUGIN );
            if ( $logged_in_id > 0 ) {
                $user_id = $logged_in_id;
            } elseif ( $allow_unauthenticated_fallback && function_exists( 'get_users' ) ) {
                $admin_ids = get_users(
                    [
                        'role'    => 'administrator',
                        'number'  => 1,
                        'fields'  => 'ID',
                        'orderby' => 'ID',
                        'order'   => 'ASC',
                    ]
                );
                if ( is_array( $admin_ids ) && ! empty( $admin_ids ) ) {
                    $first_admin = reset( $admin_ids );
                    $user_id     = is_object( $first_admin ) && isset( $first_admin->ID )
                        ? (int) $first_admin->ID
                        : (int) $first_admin;
                }
            }
        }
        return self::build_admin_jwt( $user_id, $expiration, $email, false );
    }

    /**
     * Resolve and authorize the admin user, then delegate to the internal builder.
     */
    private static function build_admin_jwt(
        ?int $user_id,
        int $expiration,
        ?string $email,
        bool $allow_key_generation
    ): ?string {
        if ( null === $user_id ) {
            if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
                return null;
            }
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        }

        if ( $user_id <= 0 ) {
            return null;
        }

        // Verify user actually possesses administrator or shop manager capabilities.
        $has_admin_cap = false;
        if ( function_exists( 'user_can' ) ) {
            $has_admin_cap = (bool) ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ) );
        } elseif ( function_exists( 'current_user_can' ) && function_exists( 'get_current_user_id' ) && (int) get_current_user_id() === (int) $user_id ) {
            $has_admin_cap = (bool) ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) );
        }
        if ( ! $has_admin_cap ) {
            return null;
        }

        return self::generate_jwt_internal( $user_id, true, $expiration, $email, $allow_key_generation );
    }

    /**
     * Shared internal JWT builder.
     *
     * The payload is not filterable; see the comment where it is built.
     *
     * @param int|null $user_id Optional user ID. Resolved from the current user,
     *                          or defaulted to 0 for a guest, only when
     *                          $is_admin is false. Admin callers resolve and
     *                          authorize it themselves before calling.
     * @param bool $is_admin Value of the is_admin claim. Callers decide this;
     *                       it is never derived from the user's capabilities
     *                       here, so a token type cannot acquire the claim by
     *                       being minted for a privileged user. It also selects
     *                       the user resolution above.
     * @param int $expiration Expiration duration in seconds.
     * @param string|null $email Optional email. Used as given when non-empty,
     *                           which skips the user lookup entirely.
     * @param bool $allow_key_generation Whether missing/corrupt RSA keys may be generated/replaced.
     * @return string|null RS256-signed JWT string, or null when the user is
     *                     missing or the store's RSA private key is unusable.
     */
    private static function generate_jwt_internal(
        ?int $user_id,
        bool $is_admin,
        int $expiration,
        ?string $email,
        bool $allow_key_generation = true
    ): ?string {
        if ( ! $is_admin ) {
            // Customer JWT: authenticated user or guest ($user_id = 0).
            if ( null === $user_id ) {
                if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
                    $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
                } else {
                    $user_id = 0;
                }
            } elseif ( $user_id < 0 ) {
                $user_id = 0;
            }
        }

        $user_email = ! empty( $email ) ? (string) $email : '';
        if ( $user_id > 0 ) {
            if ( empty( $user_email ) && function_exists( 'get_userdata' ) ) {
                $user = get_userdata( $user_id );
                if ( $user instanceof \WP_User ) {
                    $user_email = (string) $user->user_email;
                }
            }
            if ( empty( $user_email ) && function_exists( 'wp_get_current_user' ) ) {
                $current_user = wp_get_current_user();
                if ( $current_user instanceof \WP_User && (int) $current_user->ID === (int) $user_id ) {
                    $user_email = (string) $current_user->user_email;
                }
            }
        }

        $keypair     = $allow_key_generation ? self::get_or_generate_keypair() : self::read_stored_keypair();
        $private_key = $keypair['private_key'] ?? null;
        $public_key  = isset( $keypair['public_key'] ) && is_string( $keypair['public_key'] ) ? $keypair['public_key'] : '';
        if ( empty( $private_key ) || ! function_exists( 'openssl_sign' ) ) {
            self::log( 'Unable to sign JWT: the store has no usable RSA private key.', 'error' );
            return null;
        }

        $issued_at          = time();
        $expires_at         = $issued_at + $expiration;
        $store_domain       = self::get_sanitized_store_domain();
        $is_cacheable_guest = ! $is_admin && 0 === (int) $user_id && '' === $user_email && '' !== $public_key && $expiration >= ( 2 * self::GUEST_JWT_CACHE_TTL_SECONDS );
        $cache_fingerprint  = '';

        // Guest JWTs carry zero shopper-specific data (user_id: 0, is_admin: false)
        // and are cached in a transient to avoid repeated RS256 signing operations
        // on high-traffic storefront visits. The signed token carries no administrative
        // or user privileges, so storing it at rest in wp_options has minimal blast radius.
        // The fingerprint is derived from the public key rather than hashing private key bytes.
        if ( $is_cacheable_guest && function_exists( 'get_transient' ) ) {
            $cache_fingerprint = hash( 'sha256', (string) $store_domain . '|' . (string) $expiration . '|' . $public_key );
            $cached_guest_jwt  = get_transient( self::GUEST_JWT_CACHE_TRANSIENT );
            if (
                is_array( $cached_guest_jwt ) &&
                isset( $cached_guest_jwt['fp'], $cached_guest_jwt['exp'], $cached_guest_jwt['jwt'] ) &&
                is_string( $cached_guest_jwt['fp'] ) &&
                hash_equals( $cache_fingerprint, $cached_guest_jwt['fp'] ) &&
                is_int( $cached_guest_jwt['exp'] ) &&
                ( $cached_guest_jwt['exp'] - $issued_at ) >= ( $expiration - self::GUEST_JWT_CACHE_TTL_SECONDS ) &&
                is_string( $cached_guest_jwt['jwt'] ) &&
                '' !== $cached_guest_jwt['jwt']
            ) {
                return $cached_guest_jwt['jwt'];
            }
        }

        // Deliberately not filterable. Every plugin on the store can register a
        // WordPress filter, and this array is about to be signed with the
        // store's own key, so a hook here would let any of them mint claims the
        // backend authorizes on. Nothing is lost by withholding it: the backend
        // reads a closed set of claims and discards the rest, so a filter could
        // not have attached anything it would honour.
        $payload = [
            'iss'        => (string) $store_domain,
            'aud'        => 'gecx.cloud.google.com',
            'user_id'    => (int) $user_id,
            'user_email' => (string) $user_email,
            'is_admin'   => (bool) $is_admin,
            'iat'        => $issued_at,
            'exp'        => $expires_at,
        ];

        $payload_encoded = self::to_base_64_url( (string) wp_json_encode( $payload ) );

        $signing_input = self::build_signing_input( 'RS256', $payload_encoded );
        $raw_signature = '';

        // Suppress OpenSSL error output on corrupt/invalid private key strings;
        // the queued errors are drained into the log lines below instead.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- OpenSSL warnings on a corrupt key are drained into the log lines below.
        if ( @openssl_sign( $signing_input, $raw_signature, $private_key, OPENSSL_ALGO_SHA256 ) && ! empty( $raw_signature ) ) {
            $signed_jwt = $signing_input . '.' . self::to_base_64_url( $raw_signature );
            if ( $is_cacheable_guest && '' !== $cache_fingerprint && function_exists( 'set_transient' ) ) {
                set_transient(
                    self::GUEST_JWT_CACHE_TRANSIENT,
                    [
                        'fp'  => $cache_fingerprint,
                        'exp' => $expires_at,
                        'jwt' => $signed_jwt,
                    ],
                    self::GUEST_JWT_CACHE_TTL_SECONDS
                );
            }
            return $signed_jwt;
        }

        if ( ! $allow_key_generation ) {
            self::log( 'RS256 signing failed and key generation is not permitted here: ' . self::get_last_openssl_error(), 'error' );
            return null;
        }

        // The key decrypted but will not sign, so it is corrupt rather than
        // merely unreadable. Replace it under gecx_keypair_lock and retry once.
        // There is no symmetric fallback left, so if OpenSSL cannot produce a
        // usable keypair the store mints no token at all.
        self::log(
            'RS256 signing failed: ' . self::get_last_openssl_error() . ' Replacing the keypair under lock and retrying once.',
            'warning'
        );
        $private_key = self::regenerate_keypair_locked( $private_key );
        if ( ! empty( $private_key ) ) {
            $raw_signature = '';
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- OpenSSL warnings on a corrupt key are drained into the log lines below.
            if ( @openssl_sign( $signing_input, $raw_signature, $private_key, OPENSSL_ALGO_SHA256 ) && ! empty( $raw_signature ) ) {
                return $signing_input . '.' . self::to_base_64_url( $raw_signature );
            }
        }

        self::log( 'RS256 signing failed on a freshly generated keypair: ' . self::get_last_openssl_error(), 'error' );
        return null;
    }

    /**
     * Decodes a string encoded using URL-safe base64.
     *
     * @param string $input URL-safe base64 string.
     * @return string Decoded binary/text string.
     */
    public static function from_base_64_url( string $input ): string {
        $remainder = strlen( $input ) % 4;
        if ( 0 !== $remainder ) {
            $input .= str_repeat( '=', 4 - $remainder );
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url decoding of a JWT segment, not obfuscation.
        return (string) base64_decode(
            str_replace(
                [ '-', '_' ],
                [ '+', '/' ],
                $input
            )
        );
    }

    /**
     * Encodes a string to URL-safe base64.
     *
     * @param string $input Raw string to encode.
     * @return string URL-safe base64 string.
     */
    public static function to_base_64_url( string $input ): string {
        return str_replace(
            [ '+', '/', '=' ],
            [ '-', '_', '' ],
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of a JWT segment, not obfuscation.
            base64_encode( $input )
        );
    }

    /**
     * Builds the serialized signing input for a JWT given an algorithm and base64url-encoded payload.
     */
    private static function build_signing_input( string $alg, string $payload_encoded ): string {
        $header_encoded = self::to_base_64_url( (string) wp_json_encode( [
            'typ' => 'JWT',
            'alg' => $alg,
        ] ) );
        return $header_encoded . '.' . $payload_encoded;
    }

    /**
     * Retrieves and drains all queued OpenSSL error messages into a single formatted string.
     */
    private static function get_last_openssl_error(): string {
        if ( ! function_exists( 'openssl_error_string' ) ) {
            return 'OpenSSL extension not available';
        }
        $errors = [];
        $err    = openssl_error_string();
        while ( false !== $err ) {
            $errors[] = $err;
            $err      = openssl_error_string();
        }
        return ! empty( $errors ) ? implode( '; ', $errors ) : 'Unknown OpenSSL error';
    }

    /**
     * Sanitizes the store URL into a normalized domain without scheme or trailing slash.
     */
    public static function get_sanitized_store_domain(): string {
        $raw_url = function_exists( 'home_url' ) ? (string) home_url() : '';
        if ( empty( $raw_url ) ) {
            return '';
        }
        $parsed = wp_parse_url( $raw_url );
        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
            return rtrim( (string) preg_replace( '#^https?://#i', '', $raw_url ), '/' );
        }
        $domain = (string) $parsed['host'];
        if ( ! empty( $parsed['port'] ) && 80 !== (int) $parsed['port'] && 443 !== (int) $parsed['port'] ) {
            $domain .= ':' . $parsed['port'];
        }
        if ( ! empty( $parsed['path'] ) && '/' !== $parsed['path'] ) {
            $domain .= rtrim( (string) $parsed['path'], '/' );
        }
        return $domain;
    }

    /**
     * Derives a 256-bit encryption key from WordPress salts using HKDF-SHA256.
     *
     * @return string|null 32-byte binary encryption key, or null if WordPress salts are unavailable.
     */
    public static function get_encryption_key(): ?string {
        if ( ! function_exists( 'wp_salt' ) ) {
            return null;
        }
        $ikm  = (string) wp_salt( 'secure_auth' );
        $salt = (string) wp_salt( 'auth' );
        if ( empty( $ikm ) || empty( $salt ) ) {
            return null;
        }
        // hash_hkdf() has been in PHP since 7.1.2 and the plugin requires 7.4,
        // so there is no fallback path to maintain here.
        return hash_hkdf( 'sha256', $ikm, 32, 'gecx_private_key_encryption', $salt );
    }

    /**
     * Encrypts the private key PEM using AES-256-GCM.
     *
     * @param string $private_key_pem Plaintext private key PEM.
     * @return array|null Associative array with version, iv, ciphertext, and tag (base64 encoded), or null on failure.
     */
    public static function encrypt_private_key( string $private_key_pem ): ?array {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return null;
        }
        $enc_key = self::get_encryption_key();
        if ( empty( $enc_key ) ) {
            return null;
        }
        $iv         = random_bytes( 12 );
        $tag        = '';
        $ciphertext = openssl_encrypt(
            $private_key_pem,
            'aes-256-gcm',
            $enc_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ( false === $ciphertext || empty( $tag ) ) {
            return null;
        }
        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary AES-GCM output has to be stored as text in an option.
        return [
            'version'    => 1,
            'iv'         => base64_encode( $iv ),
            'ciphertext' => base64_encode( $ciphertext ),
            'tag'        => base64_encode( $tag ),
        ];
        // phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypts an encrypted private key structure using AES-256-GCM.
     *
     * @param array $encrypted_data Associative array containing iv, ciphertext, and tag.
     * @return string|null Decrypted private key PEM, or null on failure.
     */
    public static function decrypt_private_key( array $encrypted_data ): ?string {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return null;
        }
        if (
            empty( $encrypted_data['iv'] ) || ! is_string( $encrypted_data['iv'] ) ||
            empty( $encrypted_data['ciphertext'] ) || ! is_string( $encrypted_data['ciphertext'] ) ||
            empty( $encrypted_data['tag'] ) || ! is_string( $encrypted_data['tag'] )
        ) {
            return null;
        }
        $enc_key = self::get_encryption_key();
        if ( empty( $enc_key ) ) {
            return null;
        }
        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reverses the encoding applied by encrypt_private_key().
        $iv         = base64_decode( $encrypted_data['iv'], true );
        $ciphertext = base64_decode( $encrypted_data['ciphertext'], true );
        $tag        = base64_decode( $encrypted_data['tag'], true );
        // phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        if ( false === $iv || false === $ciphertext || false === $tag ) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $enc_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return false !== $plaintext ? $plaintext : null;
    }

    /**
     * Returns the existing public key or generates a new RSA keypair if missing.
     *
     * @return string|null Public key PEM string, or null on failure.
     */
    public static function get_public_key(): ?string {
        $keypair = self::get_or_generate_keypair();
        return $keypair['public_key'] ?? null;
    }

    /**
     * Returns the decrypted RSA private key or generates a new RSA keypair if missing.
     *
     * @return string|null Decrypted private key PEM string, or null on failure.
     */
    public static function get_private_key(): ?string {
        $keypair = self::get_or_generate_keypair();
        return $keypair['private_key'] ?? null;
    }

    /**
     * Returns the existing decrypted RSA private key without generating a new keypair.
     *
     * @return string|null Decrypted private key PEM string, or null if missing/undecryptable.
     */
    private static function get_existing_private_key(): ?string {
        $keypair = self::read_stored_keypair();
        return $keypair['private_key'] ?? null;
    }

    /**
     * Read and decrypt the currently stored RSA keypair without modifying options.
     *
     * @return array{public_key: string, private_key: string}|null
     */
    private static function read_stored_keypair(): ?array {
        $stored = function_exists( 'get_option' ) ? get_option( self::KEYPAIR_OPTION, null ) : null;

        if ( is_array( $stored ) ) {
            $public_key = $stored['public_key'] ?? '';
            $encrypted  = $stored['private_key'] ?? null;

            if ( ! empty( $public_key ) && is_string( $public_key ) && is_array( $encrypted ) ) {
                $decrypted = self::decrypt_private_key( $encrypted );
                if ( ! empty( $decrypted ) ) {
                    return [
                        'public_key'  => $public_key,
                        'private_key' => $decrypted,
                    ];
                }
            }

            // Present but unusable. Do not fall back to the legacy options: they
            // are whatever this record replaced, so trusting them would resurrect
            // a superseded key.
            return null;
        }

        return self::migrate_legacy_keypair();
    }

    /**
     * Fold a pre-0.3.15 two-option keypair into the single option, if one exists.
     *
     * Stores written before the consolidation live in gecx_public_key plus
     * gecx_private_key. They are read once, rewritten as one record, and the old
     * pair is deleted, so every install converges on a single source of truth
     * rather than carrying two layouts indefinitely.
     *
     * Safe to run unlocked and concurrently: it derives the new record from a
     * pair that is already written, so racing callers compute identical bytes,
     * and whichever write lands last is the same as the one before it.
     *
     * @return array{public_key: string, private_key: string}|null
     */
    private static function migrate_legacy_keypair(): ?array {
        if ( ! function_exists( 'get_option' ) ) {
            return null;
        }

        $public_key = get_option( 'gecx_public_key', '' );
        $encrypted  = get_option( 'gecx_private_key', null );

        if ( empty( $public_key ) || ! is_string( $public_key ) || ! is_array( $encrypted ) ) {
            return null;
        }

        $decrypted = self::decrypt_private_key( $encrypted );
        if ( empty( $decrypted ) ) {
            return null;
        }

        if ( function_exists( 'update_option' ) ) {
            $migrated = update_option(
                self::KEYPAIR_OPTION,
                [
                    'version'     => 1,
                    'public_key'  => $public_key,
                    'private_key' => $encrypted,
                ],
                'no'
            );

            // Only drop the old pair once the new record is actually on disk.
            // Deleting first would leave a store with no keypair at all if the
            // write failed.
            if ( $migrated && function_exists( 'delete_option' ) ) {
                delete_option( 'gecx_public_key' );
                delete_option( 'gecx_private_key' );
            }
        }

        return [
            'public_key'  => $public_key,
            'private_key' => $decrypted,
        ];
    }

    /**
     * Drop a stored keypair that can no longer be used.
     *
     * Callers MUST hold gecx_keypair_lock before invoking this helper so a
     * concurrent worker in the middle of writing or rotating a keypair does not
     * have its newly written record deleted out from under it.
     */
    private static function discard_keypair(): void {
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::KEYPAIR_OPTION );
            // Pre-0.3.15 layout. Cleared too, so a discard cannot leave a
            // superseded pair behind for migrate_legacy_keypair() to adopt.
            delete_option( 'gecx_public_key' );
            delete_option( 'gecx_private_key' );
        }
    }

    /**
     * Acquire the gecx_keypair_lock mutex, reclaiming locks older than the TTL.
     */
    private static function acquire_keypair_lock(): bool {
        $lock_acquired = function_exists( 'add_option' ) ? add_option( 'gecx_keypair_lock', time(), '', 'no' ) : true;
        if ( ! $lock_acquired && function_exists( 'get_option' ) ) {
            $lock_time = (int) get_option( 'gecx_keypair_lock', 0 );
            if ( $lock_time > 0 && ( time() - $lock_time ) > self::KEYPAIR_LOCK_TTL_SECONDS ) {
                delete_option( 'gecx_keypair_lock' );
                $lock_acquired = add_option( 'gecx_keypair_lock', time(), '', 'no' );
            }
        }
        return (bool) $lock_acquired;
    }

    /**
     * Whether someone is still plausibly working under gecx_keypair_lock.
     *
     * False once the lock has been released, or once it is old enough that
     * acquire_keypair_lock() would reclaim it, which means the holder died
     * without releasing.
     */
    private static function keypair_lock_holder_is_live(): bool {
        if ( ! function_exists( 'get_option' ) ) {
            // No way to inspect the lock, so fall back to waiting it out.
            return true;
        }

        $lock_time = (int) get_option( 'gecx_keypair_lock', 0 );
        if ( $lock_time <= 0 ) {
            return false;
        }

        return ( time() - $lock_time ) <= self::KEYPAIR_LOCK_TTL_SECONDS;
    }

    /**
     * Wait briefly for another process holding gecx_keypair_lock to finish publishing a keypair.
     *
     * @return array{public_key: string, private_key: string}|null
     */
    private static function wait_for_concurrent_keypair(): ?array {
        // Generating a 2048-bit RSA keypair can take well over half a second on
        // constrained shared hosting. Giving up early mints no token at all,
        // because 0.3.9 removed the shared-secret fallback, so the shopper's
        // request fails outright. Two seconds of waiting on this cold path is
        // cheaper than that.
        //
        // Two seconds is the ceiling, not the cost. Both callers are reachable
        // from shopper traffic and usleep() pins the worker for the duration,
        // so the loop stops the moment waiting cannot pay off: either the
        // holder released the lock without publishing a keypair, or the lock
        // aged past its TTL because the holder died. In both cases nobody is
        // coming and the remaining sleeps are pure latency.
        for ( $i = 0; $i < self::KEYPAIR_WAIT_ATTEMPTS; $i++ ) {
            usleep( self::KEYPAIR_WAIT_INTERVAL_MICROSECONDS );

            $stored = self::read_stored_keypair();
            if ( null !== $stored ) {
                return $stored;
            }

            if ( self::keypair_lock_holder_is_live() ) {
                continue;
            }

            // The holder publishes the keypair and only then releases the lock,
            // so it can have done both in the gap between the read above and
            // this check. Read once more before concluding there is nothing to
            // wait for.
            $stored = self::read_stored_keypair();
            if ( null !== $stored ) {
                return $stored;
            }

            self::log(
                sprintf(
                    'Gave up after %d ms waiting for a concurrent process to publish a keypair: the lock was released or expired without one appearing.',
                    (int) ( ( $i + 1 ) * self::KEYPAIR_WAIT_INTERVAL_MICROSECONDS / 1000 )
                ),
                'warning'
            );

            return null;
        }

        self::log(
            sprintf(
                'Timed out after %d ms waiting for a concurrent process to publish a keypair.',
                (int) ( self::KEYPAIR_WAIT_ATTEMPTS * self::KEYPAIR_WAIT_INTERVAL_MICROSECONDS / 1000 )
            ),
            'warning'
        );

        return null;
    }

    /**
     * Generate and persist a new 2048-bit RSA keypair. Caller MUST hold gecx_keypair_lock.
     *
     * @return array{public_key: string, private_key: string}|null
     */
    private static function generate_and_store_keypair(): ?array {
        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            return null;
        }

        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new( $config );
        if ( false === $res ) {
            self::log( 'openssl_pkey_new failed: ' . self::get_last_openssl_error(), 'error' );
            return null;
        }

        $private_key_pem = '';
        $exported        = openssl_pkey_export( $res, $private_key_pem );
        if ( ! $exported || empty( $private_key_pem ) ) {
            self::log( 'openssl_pkey_export failed: ' . self::get_last_openssl_error(), 'error' );
            return null;
        }

        $details = openssl_pkey_get_details( $res );
        if ( empty( $details['key'] ) ) {
            return null;
        }
        $public_key_pem = $details['key'];

        $encrypted_payload = self::encrypt_private_key( $private_key_pem );
        if ( empty( $encrypted_payload ) ) {
            return null;
        }

        if ( function_exists( 'update_option' ) ) {
            // One write. See KEYPAIR_OPTION: this is what makes a lost lock race
            // survivable, because the row either holds this worker's pair or
            // another worker's, never one half of each.
            update_option(
                self::KEYPAIR_OPTION,
                [
                    'version'     => 1,
                    'public_key'  => $public_key_pem,
                    'private_key' => $encrypted_payload,
                ],
                'no'
            );

            // Clear the pre-0.3.15 pair if this store still had one, so a later
            // read cannot migrate a keypair this one just replaced.
            if ( function_exists( 'delete_option' ) ) {
                delete_option( 'gecx_public_key' );
                delete_option( 'gecx_private_key' );
            }
        }

        return [
            'public_key'  => $public_key_pem,
            'private_key' => $private_key_pem,
        ];
    }

    /**
     * Replace a decrypted keypair that OpenSSL rejected at sign time, serialized under gecx_keypair_lock.
     *
     * @param string $bad_private_key The private key PEM that failed openssl_sign().
     * @return string|null Replacement private key PEM, or null on failure.
     */
    private static function regenerate_keypair_locked( string $bad_private_key ): ?string {
        if ( ! self::acquire_keypair_lock() ) {
            $concurrent = self::wait_for_concurrent_keypair();
            if ( null !== $concurrent && $concurrent['private_key'] !== $bad_private_key ) {
                return $concurrent['private_key'];
            }
            return null;
        }

        try {
            // Re-read inside the lock: if another worker already rotated the corrupt
            // keypair while we waited for gecx_keypair_lock, adopt its replacement
            // rather than discarding and regenerating a second time.
            $stored = self::read_stored_keypair();
            if ( null !== $stored && $stored['private_key'] !== $bad_private_key ) {
                return $stored['private_key'];
            }

            self::discard_keypair();
            $fresh = self::generate_and_store_keypair();
            return $fresh['private_key'] ?? null;
        } finally {
            if ( function_exists( 'delete_option' ) ) {
                delete_option( 'gecx_keypair_lock' );
            }
        }
    }

    /**
     * Retrieves the active RSA keypair or generates and stores a new 2048-bit RSA keypair.
     *
     * @return array|null Array with 'public_key' and 'private_key' strings, or null on failure.
     */
    public static function get_or_generate_keypair(): ?array {
        $stored = self::read_stored_keypair();
        if ( null !== $stored ) {
            return $stored;
        }

        // Acquire gecx_keypair_lock BEFORE inspecting or discarding damaged
        // state, so that after a salt rotation concurrent callers do not each
        // discard and overwrite the fresh keypair produced by the first holder.
        //
        // The lock is advisory rather than reliable - see KEYPAIR_OPTION - so
        // the storage layout, not this lock, is what guarantees a reader never
        // sees half a keypair.
        if ( ! self::acquire_keypair_lock() ) {
            return self::wait_for_concurrent_keypair();
        }

        try {
            // Re-check inside the lock in case the previous lock holder just
            // finished generating or rotating the keypair.
            $stored = self::read_stored_keypair();
            if ( null !== $stored ) {
                return $stored;
            }

            $record = function_exists( 'get_option' ) ? get_option( self::KEYPAIR_OPTION, null ) : null;

            if ( is_array( $record ) ) {
                // read_stored_keypair() already refused this record, and the two
                // halves were written together, so the pair is intact and the
                // key that decrypts it is gone.
                self::log(
                    'Failed to decrypt RSA private key; salts were most likely rotated. Discarding the unusable keypair and generating a new one.',
                    'error'
                );
                self::discard_keypair();
            } else {
                $public_key = function_exists( 'get_option' ) ? get_option( 'gecx_public_key', '' ) : '';
                $encrypted  = function_exists( 'get_option' ) ? get_option( 'gecx_private_key', null ) : null;
                $has_pub    = ! empty( $public_key ) && is_string( $public_key );
                $has_priv   = ! empty( $encrypted );

                if ( $has_pub && $has_priv ) {
                    // A pre-0.3.15 pair that migrate_legacy_keypair() could not
                    // read, which means the same salt rotation as above.
                    self::log(
                        'Failed to decrypt the pre-0.3.15 RSA private key; salts were most likely rotated. Discarding the unusable keypair and generating a new one.',
                        'error'
                    );
                    self::discard_keypair();
                } elseif ( $has_pub || $has_priv ) {
                    // Only reachable for a store that never migrated, since the
                    // current layout cannot express half a keypair.
                    self::log(
                        'Pre-0.3.15 keypair state desynchronized in database; public or private key is missing. Discarding the remaining half and generating a new keypair.',
                        'error'
                    );
                    self::discard_keypair();
                }
            }

            return self::generate_and_store_keypair();
        } finally {
            if ( function_exists( 'delete_option' ) ) {
                delete_option( 'gecx_keypair_lock' );
            }
        }
    }
}
