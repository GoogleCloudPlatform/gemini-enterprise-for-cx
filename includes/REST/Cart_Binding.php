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
 * Puts the shopper's browser on the agent's WooCommerce cart when the chat
 * starts (the /cart-session route).
 *
 * A guest who has not added anything to their cart has no WooCommerce session
 * cookie, so the chat widget starts the agent on a cart the browser is not
 * bound to. The plugin already binds the browser once the agent changes the
 * cart, but a page view that ends before then leaves the agent's cart behind.
 *
 * Called when the chat opens, not on page load, and only binds a guest whose
 * agent cart already holds something. Setting a WooCommerce session cookie
 * stops full-page caches from serving that visitor cached pages, and it
 * changes the woocommerce-* nonces (login, registration, order tracking)
 * already rendered on the page, so it is not done for a cart that holds
 * nothing to lose. When neither side has items yet, the existing paths join
 * the carts: the plugin binds the browser after the agent's first change, and
 * WooCommerce sets its own cookie when the shopper adds first, which the chat
 * widget then adopts.
 */
class Cart_Binding {

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_cart_session_rest_route' ] );
    }

    /**
     * Registers the /cart-session route.
     */
    public function register_cart_session_rest_route(): void {
        register_rest_route( 'gecx/v1', '/cart-session', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bind_cart_session_handler' ],
            'permission_callback' => [ Session_Attribution::class, 'check_session_permissions' ],
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
     * Binds the browser to the agent's cart when that is safe and useful, and
     * returns the token of the cart the browser holds.
     *
     * - A logged-in shopper, or a guest whose browser already has a
     *   WooCommerce session, keeps their cart. Its token is returned so the
     *   chat widget can switch the agent to it; the widget copies the agent's
     *   lines across first.
     * - A guest without a session whose agent cart holds items is bound to
     *   that cart, so nothing the agent added is stranded.
     * - Otherwise nothing is bound.
     *
     * The agent's cart token is a bearer credential for that cart: whoever
     * holds it can already read and change the cart through the Store API, so
     * binding a browser to it grants nothing more. Only guest tokens are
     * accepted, so no browser is ever bound to a registered user's session.
     *
     * Response fields:
     * - cart_token: Store API Cart-Token of the browser's cart, or of the
     *   agent's cart when nothing was bound. '' when there is neither, or when
     *   WooCommerce is too old to mint one.
     * - binding: 'existing' (the browser keeps its own cart), 'agent' (bound
     *   to the agent's cart), or 'deferred' (nothing bound yet).
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
        $cookie_key      = $user_id > 0 ? '' : self::get_browser_guest_key( $session );

        if ( $user_id > 0 ) {
            $browser_key = (string) $user_id;
            $binding     = 'existing';
        } elseif ( '' !== $cookie_key ) {
            $browser_key = $cookie_key;
            $binding     = 'existing';
        } elseif ( '' !== $agent_key && self::guest_cart_has_items( $session, $agent_key ) ) {
            if ( ! self::bind_browser_to_session( $session, $agent_key ) ) {
                return new \WP_Error( 'cart_binding_unsupported', __( 'The WooCommerce session handler does not support binding.', 'gemini-enterprise-for-cx' ), [ 'status' => 501 ] );
            }
            $browser_key = $agent_key;
            $binding     = 'agent';
        } else {
            return new \WP_REST_Response(
                [
                    'cart_token' => '' !== $agent_key ? $raw_agent_token : '',
                    'binding'    => 'deferred',
                ],
                200
            );
        }

        $cart_token = self::mint_cart_token( $browser_key );
        if ( '' === $cart_token && 'agent' === $binding ) {
            $cart_token = $raw_agent_token;
        }

        return new \WP_REST_Response(
            [
                'cart_token' => $cart_token,
                'binding'    => $binding,
            ],
            200
        );
    }

    /**
     * Returns WC()->session, initializing it from the request's cookies when
     * WooCommerce has not, as it does not for most REST requests.
     *
     * @return object|null
     */
    private static function get_session_handler() {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }
        if ( is_null( WC()->session ) && defined( 'WC_ABSPATH' ) ) {
            include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            include_once WC_ABSPATH . 'includes/class-wc-session-handler.php';
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
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
        $cart_token = trim( $cart_token );
        if ( '' === $cart_token ) {
            return '';
        }
        $key = Auth::get_cart_token_customer_id( $cart_token );
        return 0 === strpos( $key, 't_' ) ? $key : '';
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
     * @param object $session  WC()->session.
     * @param string $guest_key Guest session key (t_...).
     * @return bool Whether the session was bound.
     */
    private static function bind_browser_to_session( $session, string $guest_key ): bool {
        if ( class_exists( 'WC_Session' ) && $session instanceof \WC_Session ) {
            $point_at = \Closure::bind(
                function ( string $customer_id ): void {
                    $this->_customer_id = $customer_id;
                    $this->_data        = method_exists( $this, 'get_session' ) ? (array) $this->get_session( $customer_id, [] ) : [];
                },
                $session,
                \WC_Session::class
            );
            $point_at( $guest_key );
        } elseif ( method_exists( $session, 'set_customer_id' ) ) {
            $session->set_customer_id( $guest_key );
        } else {
            return false;
        }

        if ( method_exists( $session, 'set_session_expiration' ) ) {
            $session->set_session_expiration();
        }
        $session->set_customer_session_cookie( true );
        return true;
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
     * Whether a guest session's cart holds any lines.
     *
     * @param object $session  WC()->session.
     * @param string $guest_key Guest session key.
     * @return bool
     */
    private static function guest_cart_has_items( $session, string $guest_key ): bool {
        if ( ! method_exists( $session, 'get_session' ) ) {
            return false;
        }
        $data = $session->get_session( $guest_key, [] );
        if ( ! is_array( $data ) || empty( $data['cart'] ) ) {
            return false;
        }
        $lines = maybe_unserialize( $data['cart'] );
        return is_array( $lines ) && ! empty( $lines );
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
