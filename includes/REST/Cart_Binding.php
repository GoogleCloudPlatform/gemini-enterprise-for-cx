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
 * Puts the shopper's browser and the agent on one WooCommerce cart when the
 * chat starts (the /cart-session route).
 *
 * A guest who has not added anything to their cart has no WooCommerce session
 * cookie, so the chat widget starts the agent on a cart the browser is not
 * bound to. Without this route the two only meet after the agent changes the
 * cart, and a cart the shopper fills first never reaches the agent.
 *
 * Called when the chat opens, not on page load. Full-page caches stop serving
 * cached pages to a visitor once a WooCommerce session cookie is set, so
 * setting one for every visitor would turn caching off for the whole store.
 * Shoppers who open the chat are few, and WooCommerce sets the same cookie
 * for anyone who adds to their cart.
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
     * Binds the browser and the agent to one cart and returns that cart's
     * token, with a REST nonce for the session the browser now has.
     *
     * - A logged-in shopper, or a guest whose browser already has a
     *   WooCommerce session, keeps their cart. Anything the agent's guest cart
     *   holds is moved into it.
     * - A guest without a session is bound to the agent's cart, so nothing
     *   the agent added is stranded.
     * - A guest without a session or an agent cart gets a new session.
     *
     * The agent's cart token is a bearer credential for that cart: whoever
     * holds it can already read and change the cart through the Store API.
     * Binding a browser to it, or moving its lines out, grants nothing more.
     * Only guest tokens are accepted, so no browser is ever bound to a
     * registered user's session.
     *
     * Response fields:
     * - cart_token: Store API Cart-Token for the browser's cart, or '' when
     *   WooCommerce is too old to mint one.
     * - nonce: wp_rest nonce for the session the browser now has. Binding a
     *   guest to a session changes the nonces WordPress issues them.
     * - binding: 'existing', 'agent', or 'new'.
     * - moved_items: number of cart lines moved from the agent's cart.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function bind_cart_session_handler( \WP_REST_Request $request ) {
        $session = self::get_session_handler();
        if ( null === $session ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        $agent_key = self::get_guest_cart_key( (string) $request->get_param( 'cart_token' ) );
        $user_id   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

        $cookie_key = $user_id > 0 ? '' : Cart_Session::get_verified_session_cookie_customer_id();

        if ( $user_id > 0 ) {
            $browser_key = (string) $user_id;
            $binding     = 'existing';
        } elseif ( '' !== $cookie_key ) {
            $browser_key = $cookie_key;
            $binding     = 'existing';
        } elseif ( '' !== $agent_key ) {
            if ( ! self::bind_browser_to_session( $session, $agent_key ) ) {
                return new \WP_Error( 'cart_binding_unsupported', __( 'The WooCommerce session handler does not support binding.', 'gemini-enterprise-for-cx' ), [ 'status' => 501 ] );
            }
            $browser_key = $agent_key;
            $binding     = 'agent';
        } else {
            $session->set_customer_session_cookie( true );
            $browser_key = (string) $session->get_customer_id();
            $binding     = 'new';
        }

        $moved_items = 0;
        if ( '' !== $agent_key && $agent_key !== $browser_key ) {
            $moved_items = self::move_cart_lines( $session, $agent_key );
        }

        $cart_token = self::mint_cart_token( $browser_key );
        if ( '' === $cart_token && 'agent' === $binding ) {
            $cart_token = (string) $request->get_param( 'cart_token' );
        }

        return new \WP_REST_Response(
            [
                'cart_token'  => $cart_token,
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'binding'     => $binding,
                'moved_items' => $moved_items,
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
     * Moves every line of a guest session's cart into the browser's cart and
     * empties that session's cart.
     *
     * Moved rather than copied, so that a chat widget that predates this
     * route, which copies the agent's cart into the shopper's when it finds
     * them different, finds nothing left to copy.
     *
     * @param object $session  WC()->session, bound to the browser's session.
     * @param string $guest_key Guest session key of the agent's cart.
     * @return int Number of lines moved.
     */
    private static function move_cart_lines( $session, string $guest_key ): int {
        if ( ! method_exists( $session, 'get_session' ) ) {
            return 0;
        }
        $guest_session = $session->get_session( $guest_key, [] );
        if ( ! is_array( $guest_session ) || empty( $guest_session['cart'] ) ) {
            return 0;
        }
        $lines = maybe_unserialize( $guest_session['cart'] );
        if ( ! is_array( $lines ) || empty( $lines ) ) {
            return 0;
        }

        if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        $cart = WC()->cart;
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_to_cart' ) ) {
            return 0;
        }

        $moved = 0;
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) || empty( $line['product_id'] ) ) {
                continue;
            }
            $added = $cart->add_to_cart(
                (int) $line['product_id'],
                max( 1, (int) ( $line['quantity'] ?? 1 ) ),
                (int) ( $line['variation_id'] ?? 0 ),
                isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : []
            );
            if ( $added ) {
                ++$moved;
            }
        }
        if ( 0 === $moved ) {
            return 0;
        }

        if ( method_exists( $cart, 'calculate_totals' ) ) {
            $cart->calculate_totals();
        }
        if ( method_exists( $cart, 'persistent_cart_update' ) && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) {
            $cart->persistent_cart_update();
        }
        if ( method_exists( $session, 'save_data' ) ) {
            $session->save_data();
        }

        $guest_session['cart'] = maybe_serialize( [] );
        self::write_session_row( $guest_key, $guest_session );
        return $moved;
    }

    /**
     * Overwrites a WooCommerce session row and evicts its cached copy.
     *
     * @param string $session_key Session key.
     * @param array  $data        Session data.
     */
    private static function write_session_row( string $session_key, array $data ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
        $expiry     = time() + (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS );
        $serialized = maybe_serialize( $data );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required to update another WooCommerce session.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry)
             VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE session_value = %s, session_expiry = %d",
            $session_key, $serialized, $expiry, $serialized, $expiry
        ) );

        $cache_group  = defined( 'WC_SESSION_CACHE_GROUP' )
            ? WC_SESSION_CACHE_GROUP
            : ( defined( 'WC_Cache_Helper::WC_SESSION_CACHE_GROUP' ) ? \WC_Cache_Helper::WC_SESSION_CACHE_GROUP : 'wc_sessions' );
        $cache_prefix = ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_cache_prefix' ) )
            ? \WC_Cache_Helper::get_cache_prefix( $cache_group )
            : 'wc_session_';
        wp_cache_delete( $cache_prefix . $session_key, $cache_group );
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
