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
 * Gemini Enterprise for CX chat cart binding
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\REST;

use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Puts the shopper's browser on the agent's WooCommerce cart (the
 * /cart-session route), when the chat opens and after each agent cart change.
 *
 * A guest who has not added anything to their cart has no WooCommerce session
 * cookie, so the chat widget starts the agent on a cart the browser is not
 * bound to. The plugin binds such a browser once the agent changes the cart,
 * but a page view that ends before then leaves the agent's cart behind, and
 * the chat widget resumes it on the next page view. A guest whose browser
 * already has a session with an empty cart, which WooCommerce starts when the
 * cart or checkout page is opened or prefetched, is never bound by that path,
 * so the agent's changes would not reach that browser at all.
 *
 * Called when the chat opens and after agent cart changes, never on page load,
 * and only binds a browser to an agent cart that holds something. Setting a
 * WooCommerce session cookie stops full-page caches from serving that visitor
 * cached pages, and it changes the woocommerce-* nonces (login, registration,
 * order tracking) already rendered on the page, so it is not done for a cart
 * that holds nothing to lose. When neither side has items yet, the existing
 * paths join the carts: the plugin binds the browser after the agent's first
 * change, and WooCommerce sets its own cookie when the shopper adds first,
 * which the chat widget then adopts.
 */
class Cart_Binding {

    /** Supplies the same-origin check the auth-context route uses. */
    private Auth_Context $auth_context;

    /**
     * Registers this component's hooks.
     *
     * @param Auth_Context $auth_context Auth-context component.
     */
    public function __construct( Auth_Context $auth_context ) {
        $this->auth_context = $auth_context;
        add_action( 'rest_api_init', [ $this, 'register_cart_session_rest_route' ] );
    }

    /**
     * Registers the /cart-session route.
     */
    public function register_cart_session_rest_route(): void {
        register_rest_route( 'gecx/v1', '/cart-session', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bind_cart_session_handler' ],
            'permission_callback' => [ $this, 'check_cart_session_permissions' ],
            'args'                => [
                'cart_token' => [
                    'description'       => __( 'Store API Cart-Token of the cart the agent is using.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => false,
                    'validate_callback' => static function( $value ): bool {
                        return is_string( $value ) && strlen( $value ) <= 4096;
                    },
                ],
            ],
        ] );
    }

    /**
     * Requires the wp_rest nonce and a same-origin request.
     *
     * The logged-out wp_rest nonce is the same for every guest, so it does not
     * stop another site from calling the route with the shopper's cookies on a
     * store whose cookies are SameSite=None. The same-origin check does.
     *
     * @param \WP_REST_Request $request Request.
     * @return true|\WP_Error
     */
    public function check_cart_session_permissions( \WP_REST_Request $request ) {
        $nonce_check = Session_Attribution::check_session_permissions( $request );
        if ( true !== $nonce_check ) {
            return $nonce_check;
        }
        return $this->auth_context->check_auth_context_permissions( $request );
    }

    /**
     * Binds the browser to the agent's cart when that loses nothing.
     *
     * - A guest without a WooCommerce session, or whose own cart is empty, is
     *   bound to the agent's cart if it holds items, so nothing the agent
     *   added is stranded.
     * - A logged-in shopper, or a guest whose own cart holds items, keeps
     *   their cart. The chat widget adopts it by itself, copying the agent's
     *   lines across first.
     * - Otherwise nothing is bound yet.
     *
     * The agent's cart token is a bearer credential for that cart: whoever
     * holds it can already read and change the cart through the Store API, so
     * binding a browser to it grants nothing more. Only guest tokens are
     * accepted, so no browser is ever bound to a registered user's session.
     * No token is ever returned for the browser's own session.
     *
     * Response fields:
     * - binding: 'agent' (bound to the agent's cart), 'existing' (the browser
     *   keeps its own cart), or 'deferred' (nothing to bind yet).
     * - cart_token: for 'agent', a Store API Cart-Token for the agent's cart;
     *   otherwise ''.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function bind_cart_session_handler( \WP_REST_Request $request ) {
        $session = self::get_session_handler();
        if ( null === $session ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        $raw_agent_token = trim( (string) $request->get_param( 'cart_token' ) );
        $agent_key       = self::get_guest_cart_key( $raw_agent_token );
        $user_id         = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

        if ( $user_id > 0 ) {
            return self::respond( 'existing' );
        }

        $browser_key = self::get_browser_guest_key( $session );
        if ( '' !== $browser_key && ( $browser_key === $agent_key || self::browser_cart_has_items( $session ) ) ) {
            return self::respond( 'existing' );
        }

        // Neither cart holds anything yet. Asked again on the next chat open,
        // by which time the agent may have filled its cart.
        if ( '' === $agent_key || ! self::guest_cart_has_items( $agent_key ) ) {
            return self::respond( 'deferred' );
        }

        if ( ! self::bind_browser_to_session( $session, $agent_key ) ) {
            return new \WP_Error( 'cart_binding_unsupported', __( 'The WooCommerce session handler does not support binding.', 'gemini-enterprise-for-cx' ), [ 'status' => 501 ] );
        }
        $cart_token = self::mint_cart_token( $agent_key );
        return self::respond( 'agent', '' !== $cart_token ? $cart_token : $raw_agent_token );
    }

    /**
     * Builds an uncacheable response.
     *
     * @param string $binding    'agent', 'existing', or 'deferred'.
     * @param string $cart_token Cart-Token for 'agent'.
     * @return \WP_REST_Response
     */
    private static function respond( string $binding, string $cart_token = '' ): \WP_REST_Response {
        $response = new \WP_REST_Response(
            [
                'binding'    => $binding,
                'cart_token' => $cart_token,
            ],
            200
        );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
        return $response;
    }

    /**
     * Returns WC()->session, initializing it from the request's cookies when
     * WooCommerce has not, as it does not for most REST requests. Uses the
     * session handler the store configured (woocommerce_session_handler).
     *
     * @return object|null
     */
    private static function get_session_handler() {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }
        if ( is_null( WC()->session ) ) {
            if ( method_exists( WC(), 'initialize_session' ) ) {
                WC()->initialize_session();
            } elseif ( defined( 'WC_ABSPATH' ) ) {
                include_once WC_ABSPATH . 'includes/class-wc-session-handler.php';
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
        }
        $session = WC()->session;
        if ( ! is_object( $session ) || ! method_exists( $session, 'set_customer_session_cookie' ) || ! method_exists( $session, 'get_customer_id' ) ) {
            return null;
        }
        return $session;
    }

    /**
     * Returns the guest session key a verified Cart-Token names, or '' for a
     * missing, forged, or expired token, or one naming a registered user.
     *
     * @param string $cart_token Raw Cart-Token.
     * @return string
     */
    private static function get_guest_cart_key( string $cart_token ): string {
        if ( '' === $cart_token ) {
            return '';
        }
        $key = Auth::get_cart_token_customer_id( $cart_token );
        return 0 === strpos( $key, 't_' ) ? $key : '';
    }

    /**
     * Returns the guest session key the browser's WooCommerce session cookie
     * names, or '' when there is none WooCommerce accepted.
     *
     * The cookie's signature is not enough. On a logged-out request,
     * WooCommerce rejects a cookie naming a registered user's session (a
     * shared device after the user logged out) and switches the request to a
     * new guest session, but leaves the rejected value in $_COOKIE, where it
     * still verifies. Only a guest key that is the session WooCommerce kept is
     * the browser's.
     *
     * @param object $session WC()->session.
     * @return string
     */
    private static function get_browser_guest_key( $session ): string {
        $cookie_key = Cart_Session::get_verified_session_cookie_customer_id();
        if ( 0 !== strpos( $cookie_key, 't_' ) || (string) $session->get_customer_id() !== $cookie_key ) {
            return '';
        }
        return $cookie_key;
    }

    /**
     * Reads a WooCommerce session row straight from the database.
     *
     * WC_Session_Handler::get_session() caches what it reads, a missing row
     * included, and Store API writes made with a Cart-Token never update that
     * cache, so it can report an agent cart as empty after the agent filled
     * it.
     *
     * @param string $session_key Session key.
     * @return array Session data, or [] when there is no row.
     */
    private static function read_session_row( string $session_key ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses the session cache on purpose; see above.
        $raw  = $wpdb->get_var( $wpdb->prepare(
            "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
            $session_key
        ) );
        $data = empty( $raw ) ? [] : maybe_unserialize( $raw );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Whether a guest session's cart holds any lines, read from the database
     * table, where the Store API keeps the sessions it opens for a Cart-Token
     * whatever handler the store configured for browsers.
     *
     * @param string $session_key Guest session key.
     * @return bool
     */
    private static function guest_cart_has_items( string $session_key ): bool {
        $data = self::read_session_row( $session_key );
        if ( empty( $data['cart'] ) ) {
            return false;
        }
        $lines = maybe_unserialize( $data['cart'] );
        return is_array( $lines ) && ! empty( $lines );
    }

    /**
     * Whether the browser's own cart holds any lines.
     *
     * Read through the store's session handler, which may keep sessions
     * outside the database (Redis, Memcached), rather than from the table.
     *
     * @param object $session WC()->session, for the browser's session.
     * @return bool
     */
    private static function browser_cart_has_items( $session ): bool {
        $lines = method_exists( $session, 'get' ) ? $session->get( 'cart' ) : null;
        if ( is_string( $lines ) ) {
            $lines = maybe_unserialize( $lines );
        }
        return is_array( $lines ) && ! empty( $lines );
    }

    /**
     * Points the browser's WooCommerce session at an existing guest session
     * and sets the session cookie for it.
     *
     * WooCommerce writes the cookie itself, through
     * WC_Session_Handler::set_customer_session_cookie(), so it is in whatever
     * format the installed version reads. WC_Session has no public setter for
     * the customer ID, so it is set from inside the class's scope.
     *
     * @param object $session   WC()->session.
     * @param string $guest_key Guest session key (t_...).
     * @return bool Whether the session was bound.
     */
    private static function bind_browser_to_session( $session, string $guest_key ): bool {
        if ( ! class_exists( 'WC_Session' ) || ! $session instanceof \WC_Session ) {
            return false;
        }
        $data     = self::read_session_row( $guest_key );
        $point_at = \Closure::bind(
            function ( string $customer_id, array $session_data ): void {
                $this->_customer_id = $customer_id;
                $this->_data        = $session_data;
            },
            $session,
            \WC_Session::class
        );
        $point_at( $guest_key, $data );

        if ( method_exists( $session, 'set_session_expiration' ) ) {
            $session->set_session_expiration();
        }
        $session->set_customer_session_cookie( true );
        return true;
    }

    /**
     * Mints a Store API Cart-Token for a session key, or returns '' when the
     * installed WooCommerce has no CartTokenUtils.
     *
     * @param string $session_key Session key.
     * @return string
     */
    private static function mint_cart_token( string $session_key ): string {
        if ( '' === $session_key || ! class_exists( '\Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils' ) ) {
            return '';
        }
        return (string) \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $session_key );
    }
}
