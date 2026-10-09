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
 * Gemini Enterprise for CX console routes and WooCommerce API key authentication
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\REST;

use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * The routes the Gemini Enterprise for CX console calls with the store's
 * WooCommerce API key (link-agent and public-key), the permission check they
 * and the order webhook route share, and the scoped widening of WooCommerce
 * API key authentication to those routes.
 */
class Console_API {

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
     * Capabilities that survive the pre-dispatch withholding window applied by
     * restrict_widened_wc_auth_before_dispatch().
     *
     * These gate nothing on their own: `read` and `level_0` are the baseline
     * every registered role holds, and `exist` is what WordPress grants any
     * existing user. Keeping them truthful means third-party code running on
     * `init` or `wp_loaded` can still tell that somebody is logged in, while
     * every capability an action is actually gated on reads as false until
     * WooCommerce has verified the API key's read/write scope.
     */
    private const UNRESTRICTED_PRE_DISPATCH_CAPS = [
        'read',
        'level_0',
        'exist',
    ];

    /**
     * Whether this class widened `woocommerce_rest_is_request_to_rest_api` for
     * the current request.
     */
    private static bool $wc_auth_widened_by_gecx = false;

    /**
     * Whether the widened WooCommerce API key authentication has been verified
     * on `rest_pre_dispatch` (priority 20, after WooCommerce's read/write scope
     * check at priority 10) for one of `WC_AUTHENTICATED_ROUTES`.
     */
    private static bool $wc_auth_verified_for_dispatch = false;

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_public_key_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_link_rest_route' ] );
        add_filter( 'woocommerce_rest_is_request_to_rest_api', [ $this, 'enable_wc_auth_for_custom_endpoints' ], 10, 1 );
        add_filter( 'user_has_cap', [ $this, 'restrict_widened_wc_auth_before_dispatch' ], 999, 4 );
        add_filter( 'rest_pre_dispatch', [ $this, 'unlock_widened_wc_auth_on_dispatch' ], 20, 3 );
        add_filter( 'rest_post_dispatch', [ $this, 'lock_widened_wc_auth_after_dispatch' ], 999, 3 );
    }

    /**
     * Resets the WooCommerce API key auth tracking flags between requests/tests.
     */
    public static function reset_wc_auth_state(): void {
        self::$wc_auth_widened_by_gecx       = false;
        self::$wc_auth_verified_for_dispatch = false;
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
            'args'                => [
                'agent_name'        => [
                    'description'       => __( 'Google Cloud resource name of the linked GECX agent.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => true,
                    'validate_callback' => static function( $value ): bool {
                        $trimmed = is_string( $value ) ? trim( $value ) : '';
                        return '' !== $trimmed && 1 === preg_match( Auth::RESOURCE_NAME_PATTERN, $trimmed );
                    },
                ],
                'token_broker_name' => [
                    'description'       => __( 'Optional Google Cloud resource name of the token broker.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => false,
                    'validate_callback' => static function( $value ): bool {
                        if ( null === $value || '' === trim( (string) $value ) ) {
                            return true;
                        }
                        return is_string( $value ) && 1 === preg_match( Auth::RESOURCE_NAME_PATTERN, trim( $value ) );
                    },
                ],
            ],
        ] );
    }

    /**
     * Apply an agent link reported by Google Cloud.
     */
    public function link_agent_handler( \WP_REST_Request $request ) {
        // Both values are validated raw against Auth::RESOURCE_NAME_PATTERN,
        // a strict allowlist. sanitize_text_field() would run first and can only
        // delete characters the allowlist rejects, so it turns a malformed name
        // into a plausible one: "projects/123/agents/<script>alert(1)</script>"
        // comes out of it as "projects/123/agents/", which matches the pattern
        // and would be written to gecx_agent_name.
        $agent_name = trim( (string) $request->get_param( 'agent_name' ) );

        if ( '' === $agent_name || ! preg_match( Auth::RESOURCE_NAME_PATTERN, $agent_name ) ) {
            return new \WP_Error( 'gecx_invalid_agent_name', __( 'Missing or malformed agent_name.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $token_broker = trim( (string) $request->get_param( 'token_broker_name' ) );
        if ( '' !== $token_broker && ! preg_match( Auth::RESOURCE_NAME_PATTERN, $token_broker ) ) {
            return new \WP_Error( 'gecx_invalid_token_broker', __( 'Malformed token_broker_name.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        update_option( 'gecx_agent_name', $agent_name );
        update_option( 'gecx_auth_complete', 1, 'no' );
        if ( '' !== $token_broker ) {
            update_option( 'gecx_token_broker_name', $token_broker );
        }
        delete_option( 'gecx_dismiss_activation_notice' );

        // An explicit link ends the merchant's earlier unlink: from here on
        // SyncState reconciles this binding again.
        delete_option( Auth::MERCHANT_UNLINKED_OPTION );

        // A widget the merchant switched off stays off until they switch it
        // back on, and so does the order webhook.
        if ( ! get_option( Auth::MERCHANT_DISABLED_OPTION, false ) ) {
            update_option( 'gecx_agent_enabled', 1 );
            Order_Webhook::set_order_webhook_status( 'active' );
        }

        return new \WP_REST_Response( [
            'success'    => true,
            'agent_name' => $agent_name,
        ], 200 );
    }

    /**
     * Check permissions for secret API route.
     * Accepts authenticated WooCommerce API key calls, or a browser session
     * cookie with a valid WP REST nonce. Either way the user must hold
     * manage_woocommerce or manage_options.
     */
    public static function check_admin_permissions( \WP_REST_Request $request ) {
        // A Cart-Token identifies a shopper session, never a store operator.
        // Auth::block_cart_token_off_store_api() already refuses this on
        // 'rest_pre_dispatch'; this is deliberately redundant, so the endpoint
        // stays closed even if that filter is unhooked or reordered.
        if ( Auth::is_cart_token_request() ) {
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

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Unauthorized.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        return true;
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
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP_REST_Server route callback signature.
    public function get_public_key_handler( \WP_REST_Request $request ) {
        $public_key = Auth::get_public_key();
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
     * Auth::is_request_to_route() resolves the route in the order
     * WordPress does. A request that cannot name a route yet is left to
     * WooCommerce's own answer rather than being granted one.
     */
    public function enable_wc_auth_for_custom_endpoints( bool $is_rest_api, $request = null ): bool {
        if ( $is_rest_api ) {
            // WooCommerce already answers true for its own /wc/ routes, which
            // are never in WC_AUTHENTICATED_ROUTES. Nothing was widened by this
            // plugin, so the state has to be cleared here too: this filter can
            // fire several times per request, and leaving a stale true behind
            // would make unlock_widened_wc_auth_on_dispatch() reject the route
            // WooCommerce is about to dispatch.
            self::$wc_auth_widened_by_gecx       = false;
            self::$wc_auth_verified_for_dispatch = false;
            return true;
        }
        if ( Auth::is_request_to_route( self::WC_AUTHENTICATED_ROUTES, $request ) ) {
            self::$wc_auth_widened_by_gecx       = true;
            self::$wc_auth_verified_for_dispatch = false;
            return true;
        }
        self::$wc_auth_widened_by_gecx       = false;
        self::$wc_auth_verified_for_dispatch = false;
        return false;
    }

    /**
     * Withholds user capabilities granted by a widened WooCommerce API key
     * during early lifecycle hooks (`init`, `wp_loaded`) before REST dispatch.
     *
     * WooCommerce's `WC_REST_Authentication::authenticate()` runs on
     * `determine_current_user` (which third-party plugins often trigger on
     * `init` before `parse_request()`), whereas WooCommerce's read/write scope
     * check (`check_user_permissions`) only runs on `rest_pre_dispatch`
     * priority 10. Withholding capabilities until `unlock_widened_wc_auth_on_dispatch()`
     * at priority 20 prevents a read-only API key or an early pre-REST hook
     * from exercising the key owner's full WordPress capabilities.
     *
     * Scope of the window. It opens only when this plugin widened WooCommerce
     * key authentication for one of self::WC_AUTHENTICATED_ROUTES, it applies
     * only to the API key's own user (other users are untouched), and it closes
     * at `rest_pre_dispatch` priority 20. Inside it, every capability the key
     * owner holds reads as false except the baseline non-privileged ones in
     * self::UNRESTRICTED_PRE_DISPATCH_CAPS, so third-party code that merely
     * asks whether someone is logged in still gets a truthful answer while
     * nothing gated by a capability can be exercised.
     *
     * @param array $allcaps Array of key/value pairs where keys represent a capability name and boolean values represent whether the user has that capability.
     * @param array $caps    Required primitive capabilities for the requested capability.
     * @param array $args    Arguments that accompany the requested capability check.
     * @param mixed $user    WP_User object.
     * @return array Filtered capabilities map.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress user_has_cap filter signature.
    public function restrict_widened_wc_auth_before_dispatch( $allcaps, $caps = [], $args = [], $user = null ): array {
        if ( ! is_array( $allcaps ) ) {
            $allcaps = [];
        }
        if (
            self::$wc_auth_widened_by_gecx
            && ! self::$wc_auth_verified_for_dispatch
            && $user instanceof \WP_User
            && (int) get_current_user_id() === (int) $user->ID
        ) {
            $restricted = [];
            foreach ( $allcaps as $cap => $granted ) {
                $restricted[ $cap ] = in_array( $cap, self::UNRESTRICTED_PRE_DISPATCH_CAPS, true ) ? $granted : false;
            }
            return $restricted;
        }
        return $allcaps;
    }

    /**
     * Unlocks capabilities for a widened WooCommerce API key request on
     * `rest_pre_dispatch` at priority 20, after WooCommerce's own
     * `check_user_permissions` (priority 10) has verified the key's read/write
     * scope and after WordPress has resolved the exact route being dispatched.
     *
     * @param mixed            $result  Response to replace the requested version with.
     * @param \WP_REST_Server  $server  Server instance.
     * @param \WP_REST_Request $request Request used to generate the response.
     * @return mixed
     */
    public function unlock_widened_wc_auth_on_dispatch( $result, $server, $request ) {
        if ( ! self::$wc_auth_widened_by_gecx ) {
            return $result;
        }
        if ( is_wp_error( $result ) ) {
            self::$wc_auth_verified_for_dispatch = false;
            return $result;
        }
        $route = $request instanceof \WP_REST_Request
            ? ltrim( untrailingslashit( (string) $request->get_route() ), '/' )
            : '';
        if ( ! in_array( $route, self::WC_AUTHENTICATED_ROUTES, true ) ) {
            self::$wc_auth_verified_for_dispatch = false;
            return new \WP_Error(
                'rest_forbidden',
                __( 'Unauthorized.', 'gemini-enterprise-for-cx' ),
                [ 'status' => 403 ]
            );
        }
        self::$wc_auth_verified_for_dispatch = true;
        return $result;
    }

    /**
     * Resets the widened WooCommerce API key state once REST dispatch finishes.
     *
     * @param mixed            $response Result to send.
     * @param \WP_REST_Server  $server   Server instance.
     * @param \WP_REST_Request $request  Request used to generate $response.
     * @return mixed
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress rest_post_dispatch filter signature.
    public function lock_widened_wc_auth_after_dispatch( $response, $server, $request ) {
        self::reset_wc_auth_state();
        return $response;
    }
}
