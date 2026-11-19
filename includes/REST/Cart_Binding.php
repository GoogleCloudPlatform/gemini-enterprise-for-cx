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
     *   added is stranded. This needs a session handler that keeps sessions
     *   where binding can reach them (see is_table_backed()).
     * - A logged-in shopper, or a guest whose own cart holds items, keeps
     *   their cart. The chat widget adopts it by itself, copying the agent's
     *   lines across first.
     * - Otherwise nothing is bound yet.
     *
     * Binding has a cost. The agent's cart token is a bearer credential for
     * the agent's session: whoever holds it can read and change that session
     * through the Store API. Once bound, that session is also the shopper's,
     * so whatever the shopper puts in it from then on, including the
     * checkout details WooCommerce keeps under its customer key (addresses,
     * email), can be read by anyone holding the token, the agent included.
     * For this reason WooCommerce 10.0+, when it restores a session from a
     * ?session= token, clones it and leaves the customer data behind (see
     * WC_Session_Handler::clone_session_data()). Binding cannot do that, as
     * its purpose is one cart that the shopper and the agent share, so the
     * plugin accepts the exposure, and only for guests: a token naming a
     * registered user is refused, so no browser is ever bound to a
     * registered user's session. No token is ever returned for the browser's
     * own session.
     *
     * Always answers 200, with 'binding' set to:
     * - 'agent': the browser is now on the agent's cart. A Store API
     *   Cart-Token for that cart comes in the Cart-Token response header.
     *   It is never put in the body, which session-replay and RUM tools and
     *   HAR files capture whole (see
     *   Cart_Session::strip_cart_token_from_batch_body()).
     * - 'merged': the agent's guest cart lines were merged into the browser's
     *   own cart (a cookied guest with items, a logged-in shopper without
     *   migrating their session cookie, or an external session handler such as
     *   Redis/Memcached), and the agent's guest cart was drained so repeat
     *   calls and client-side fallback copies are idempotent. No Cart-Token is
     *   returned for the browser's own session.
     * - 'existing': the browser keeps its own cart unchanged (already on the
     *   agent's cart, or nothing in the agent's guest cart to merge).
     * - 'deferred': nothing to bind yet.
     * - 'unsupported': the store's session handler cannot be bound or hydrated,
     *   so asking again cannot help.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function bind_cart_session_handler( \WP_REST_Request $request ) {
        if ( ! function_exists( 'WC' ) ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }
        if ( null !== WC()->session && ( ! is_object( WC()->session ) || ! method_exists( WC()->session, 'set_customer_session_cookie' ) || ! method_exists( WC()->session, 'get_customer_id' ) ) ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        $raw_agent_token = trim( (string) $request->get_param( 'cart_token' ) );
        $agent_key       = self::get_guest_cart_key( $raw_agent_token );

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        // Handle logged-in shoppers before WC()->initialize_session(),
        // avoiding an unnecessary WC_Session_Handler boot and any cookie
        // migration while merging the agent's guest cart directly into the
        // authenticated shopper's persisted session row(s).
        if ( $user_id > 0 ) {
            return self::merge_for_logged_in_shopper( $user_id, $agent_key );
        }

        $session = self::get_session_handler();
        if ( null === $session ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        if ( '' !== $agent_key && self::get_browser_guest_key( $session ) === $agent_key ) {
            return self::respond( 'existing' );
        }

        $agent_row       = ( '' !== $agent_key ) ? self::read_session_row( $agent_key ) : [];
        $agent_lines     = self::extract_cart_lines( $agent_row['cart'] ?? null );
        $gecx_session_id = isset( $agent_row['gecx_session_id'] ) && is_string( $agent_row['gecx_session_id'] )
            ? $agent_row['gecx_session_id']
            : '';

        // The browser's own cart is checked whether or not its cookie
        // verifies: a session whose cookie this plugin cannot verify, such as
        // one a custom session handler signs in its own format, may still
        // hold a cart, and replacing the session cookie would orphan it. When
        // the agent's guest cart also holds items, merge them into the
        // browser's existing cart in place instead of swapping cookies.
        if ( self::browser_cart_has_items( $session ) ) {
            if ( empty( $agent_lines ) ) {
                return self::respond( 'existing' );
            }
            if ( ! self::is_table_backed( $session ) && ! self::can_persist_external_session( $session ) ) {
                return self::respond( 'unsupported' );
            }
            self::merge_into_browser_session( $session, $agent_lines, $gecx_session_id );
            self::clear_session_row_cart( $agent_key );
            return self::respond( 'merged' );
        }

        // Neither cart holds anything yet. Asked again on the next chat open,
        // by which time the agent may have filled its cart.
        if ( empty( $agent_lines ) ) {
            return self::respond( 'deferred' );
        }

        if ( ! self::is_table_backed( $session ) ) {
            if ( ! self::can_persist_external_session( $session ) ) {
                return self::respond( 'unsupported' );
            }
            self::hydrate_external_browser_session( $session, $agent_lines, $gecx_session_id );
            self::clear_session_row_cart( $agent_key );
            return self::respond( 'merged' );
        }
        self::bind_browser_to_session( $session, $agent_key );
        $cart_token = self::mint_cart_token( $agent_key );
        return self::respond( 'agent', '' !== $cart_token ? $cart_token : $raw_agent_token );
    }

    /**
     * Builds an uncacheable response.
     *
     * @param string $binding    'agent', 'existing', 'deferred', or 'unsupported'.
     * @param string $cart_token For 'agent', the Cart-Token, sent as a header.
     * @return \WP_REST_Response
     */
    private static function respond( string $binding, string $cart_token = '' ): \WP_REST_Response {
        $response = new \WP_REST_Response( [ 'binding' => $binding ], 200 );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
        if ( '' !== $cart_token ) {
            $response->header( 'Cart-Token', $cart_token );
        }
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
            WC()->initialize_session();
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
     * Extracts a normalized array of cart line items from raw session storage.
     *
     * @param mixed $raw_cart Serialized or array cart data.
     * @return array
     */
    private static function extract_cart_lines( $raw_cart ): array {
        if ( empty( $raw_cart ) ) {
            return [];
        }
        $lines = is_string( $raw_cart ) ? maybe_unserialize( $raw_cart ) : $raw_cart;
        return is_array( $lines ) ? $lines : [];
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
        $lines = self::extract_cart_lines( method_exists( $session, 'get' ) ? $session->get( 'cart' ) : null );
        return ! empty( $lines );
    }

    /**
     * Whether the browser's session handler keeps sessions in the
     * woocommerce_sessions table, which binding relies on.
     *
     * The agent's session exists only in that table: the Store API writes the
     * sessions it opens for a Cart-Token there, whatever handler the store
     * configured for browsers. A handler that keeps sessions elsewhere
     * (Redis, Memcached) would look the bound session up in its own store,
     * find nothing, and give the browser an empty cart. WC_Session_Handler
     * qualifies, and so does a subclass that leaves its storage alone, such
     * as one that only changes the cookie. A subclass that overrides how
     * sessions are read or saved does not, as there is no telling where it
     * keeps them.
     *
     * @param object $session WC()->session.
     * @return bool
     */
    private static function is_table_backed( $session ): bool {
        if ( ! $session instanceof \WC_Session_Handler ) {
            return false;
        }
        foreach ( [ 'get_session', 'save_data' ] as $method ) {
            if ( \WC_Session_Handler::class !== ( new \ReflectionMethod( $session, $method ) )->getDeclaringClass()->getName() ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the configured woocommerce_session_handler class is table-backed,
     * checked without instantiating or initializing WC()->session.
     *
     * @return bool
     */
    private static function is_configured_handler_table_backed(): bool {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce filter.
        $handler_class = apply_filters( 'woocommerce_session_handler', \WC_Session_Handler::class );
        if ( ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
            return false;
        }
        if ( \WC_Session_Handler::class !== $handler_class && ! is_subclass_of( $handler_class, \WC_Session_Handler::class ) ) {
            return false;
        }
        foreach ( [ 'get_session', 'save_data' ] as $method ) {
            if ( ! method_exists( $handler_class, $method ) ) {
                return false;
            }
            if ( \WC_Session_Handler::class !== ( new \ReflectionMethod( $handler_class, $method ) )->getDeclaringClass()->getName() ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a non-table-backed session handler (such as Redis or Memcached)
     * has an active customer ID and overrides save_data() to persist changes
     * to its backing store.
     *
     * Abstract WC_Session defines an empty save_data() stub, and a
     * WC_Session_Handler subclass that overrides get_session() without
     * overriding save_data() writes to SQL while reading from elsewhere, so
     * neither can round-trip hydrated cart data.
     *
     * @param object $session WC()->session.
     * @return bool
     */
    private static function can_persist_external_session( $session ): bool {
        if ( ! is_object( $session ) ) {
            return false;
        }
        foreach ( [ 'get', 'set', 'save_data', 'get_customer_id', 'set_customer_session_cookie' ] as $method ) {
            if ( ! method_exists( $session, $method ) ) {
                return false;
            }
        }
        if ( '' === (string) $session->get_customer_id() ) {
            return false;
        }
        $save_declaring = ( new \ReflectionMethod( $session, 'save_data' ) )->getDeclaringClass()->getName();
        if ( $session instanceof \WC_Session_Handler ) {
            $get_declaring = ( new \ReflectionMethod( $session, 'get_session' ) )->getDeclaringClass()->getName();
            if ( \WC_Session_Handler::class !== $get_declaring && \WC_Session_Handler::class === $save_declaring ) {
                return false;
            }
            return true;
        }
        if ( class_exists( '\WC_Session' ) && $session instanceof \WC_Session ) {
            return \WC_Session::class !== $save_declaring;
        }
        return true;
    }

    /**
     * Merges an agent guest cart into a logged-in shopper's cart without ever
     * calling WC()->initialize_session() or migrating a Store API `t_...`
     * session cookie.
     *
     * @param int    $user_id   Logged-in WordPress user ID (> 0).
     * @param string $agent_key Verified guest cart session key (`t_...`), or ''.
     * @return \WP_REST_Response
     */
    private static function merge_for_logged_in_shopper( int $user_id, string $agent_key ): \WP_REST_Response {
        if ( '' === $agent_key ) {
            return self::respond( 'existing' );
        }
        $agent_row   = self::read_session_row( $agent_key );
        $agent_lines = self::extract_cart_lines( $agent_row['cart'] ?? null );
        if ( empty( $agent_lines ) ) {
            return self::respond( 'existing' );
        }

        $gecx_session_id = isset( $agent_row['gecx_session_id'] ) && is_string( $agent_row['gecx_session_id'] )
            ? $agent_row['gecx_session_id']
            : '';

        if ( null !== WC()->session && ! self::is_table_backed( WC()->session ) ) {
            if ( (string) WC()->session->get_customer_id() === (string) $user_id && self::can_persist_external_session( WC()->session ) ) {
                $merged = self::merge_into_browser_session( WC()->session, $agent_lines, $gecx_session_id );
                self::update_persistent_user_cart( $user_id, $merged );
                self::clear_session_row_cart( $agent_key );
                return self::respond( 'merged' );
            }
            return self::respond( 'unsupported' );
        }
        if ( null === WC()->session && ! self::is_configured_handler_table_backed() ) {
            return self::respond( 'unsupported' );
        }

        $cookie_key   = Cart_Session::get_verified_session_cookie_customer_id();
        $user_key     = (string) $user_id;
        $existing_usr = self::read_user_cart_lines( $user_id );

        if ( $cookie_key === $agent_key ) {
            $merged_user = self::merge_cart_lines( $existing_usr, $agent_lines );
            self::write_session_row_cart( $user_key, $merged_user, $gecx_session_id );
            self::update_persistent_user_cart( $user_id, $merged_user );
            return self::respond( 'existing' );
        }

        if ( 0 === strpos( $cookie_key, 't_' ) ) {
            $cookie_row   = self::read_session_row( $cookie_key );
            $cookie_lines = self::extract_cart_lines( $cookie_row['cart'] ?? null );
            $merged_guest = self::merge_cart_lines( $cookie_lines, $agent_lines );
            self::write_session_row_cart( $cookie_key, $merged_guest, $gecx_session_id );
            $merged_user  = self::merge_cart_lines( $existing_usr, $merged_guest );
            self::write_session_row_cart( $user_key, $merged_user, $gecx_session_id );
            self::update_persistent_user_cart( $user_id, $merged_user );
        } else {
            $merged_user = self::merge_cart_lines( $existing_usr, $agent_lines );
            self::write_session_row_cart( $user_key, $merged_user, $gecx_session_id );
            self::update_persistent_user_cart( $user_id, $merged_user );
            if ( null !== WC()->session && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'set' ) ) {
                WC()->session->set( 'cart', $merged_user );
                if ( '' !== $gecx_session_id && method_exists( WC()->session, 'get' ) && empty( WC()->session->get( 'gecx_session_id' ) ) ) {
                    WC()->session->set( 'gecx_session_id', $gecx_session_id );
                }
                if ( method_exists( WC()->session, 'save_data' ) ) {
                    WC()->session->save_data();
                }
            }
        }

        self::clear_session_row_cart( $agent_key );
        return self::respond( 'merged' );
    }

    /**
     * Reads a logged-in user's existing cart lines without initializing
     * WC()->session.
     *
     * @param int $user_id WordPress user ID.
     * @return array
     */
    private static function read_user_cart_lines( int $user_id ): array {
        $user_key = (string) $user_id;
        if ( null !== WC()->session && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'get' ) ) {
            $in_memory = self::extract_cart_lines( WC()->session->get( 'cart' ) );
            if ( ! empty( $in_memory ) ) {
                return $in_memory;
            }
        }
        $row_lines = self::extract_cart_lines( self::read_session_row( $user_key )['cart'] ?? null );
        if ( ! empty( $row_lines ) ) {
            return $row_lines;
        }
        if ( function_exists( 'get_user_meta' ) && function_exists( 'get_current_blog_id' ) ) {
            $saved = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
            if ( is_array( $saved ) && isset( $saved['cart'] ) ) {
                return self::extract_cart_lines( $saved['cart'] );
            }
        }
        return [];
    }

    /**
     * Updates a logged-in user's persistent cart user meta.
     *
     * @param int   $user_id    WordPress user ID.
     * @param array $cart_lines Session cart lines.
     */
    private static function update_persistent_user_cart( int $user_id, array $cart_lines ): void {
        if ( function_exists( 'update_user_meta' ) && function_exists( 'get_current_blog_id' ) ) {
            update_user_meta(
                $user_id,
                '_woocommerce_persistent_cart_' . get_current_blog_id(),
                [ 'cart' => $cart_lines ]
            );
        }
    }

    /**
     * Merges agent cart lines into an active browser WC()->session without
     * altering the browser's session cookie.
     *
     * @param object $session         WC()->session for the browser.
     * @param array  $agent_lines     Cart lines from the agent's guest session.
     * @param string $gecx_session_id Optional GECX session ID to carry across.
     * @return array Merged cart lines.
     */
    private static function merge_into_browser_session( $session, array $agent_lines, string $gecx_session_id ): array {
        $browser_lines = self::extract_cart_lines( method_exists( $session, 'get' ) ? $session->get( 'cart' ) : null );
        $customer_id   = (string) $session->get_customer_id();
        if ( empty( $browser_lines ) && '' !== $customer_id && self::is_table_backed( $session ) ) {
            $browser_lines = self::extract_cart_lines( self::read_session_row( $customer_id )['cart'] ?? null );
        }
        $merged = self::merge_cart_lines( $browser_lines, $agent_lines );
        $session->set( 'cart', $merged );
        if ( '' !== $gecx_session_id && method_exists( $session, 'get' ) && empty( $session->get( 'gecx_session_id' ) ) ) {
            $session->set( 'gecx_session_id', $gecx_session_id );
        }
        if ( method_exists( $session, 'save_data' ) ) {
            $session->save_data();
        }
        if ( '' !== $customer_id && self::is_table_backed( $session ) ) {
            self::write_session_row_cart( $customer_id, $merged, $gecx_session_id );
        }
        return $merged;
    }

    /**
     * Hydrates agent cart lines into an empty external (Redis/Memcached)
     * browser session handler and ensures the browser holds a session cookie
     * for that handler's customer ID.
     *
     * @param object $session         WC()->session (external handler).
     * @param array  $agent_lines     Cart lines from the agent's guest session.
     * @param string $gecx_session_id Optional GECX session ID to carry across.
     */
    private static function hydrate_external_browser_session( $session, array $agent_lines, string $gecx_session_id ): void {
        $merged = self::merge_cart_lines( [], $agent_lines );
        $session->set( 'cart', $merged );
        if ( '' !== $gecx_session_id && method_exists( $session, 'get' ) && empty( $session->get( 'gecx_session_id' ) ) ) {
            $session->set( 'gecx_session_id', $gecx_session_id );
        }
        if ( '' === self::get_browser_guest_key( $session ) && ! Cart_Session::has_woocommerce_session_cookie() ) {
            $session->set_customer_session_cookie( true );
        }
        $session->save_data();
    }

    /**
     * Merges source cart lines into target cart lines, combining matching
     * products/variations while respecting sold_individually and stock caps
     * when WC_Product records exist.
     *
     * @param array $target_lines Existing browser/user cart lines.
     * @param array $source_lines Agent guest cart lines to merge in.
     * @return array Merged cart lines.
     */
    private static function merge_cart_lines( array $target_lines, array $source_lines ): array {
        $merged        = $target_lines;
        $signature_map = [];
        foreach ( $merged as $key => $existing_line ) {
            if ( ! is_array( $existing_line ) || self::is_composite_cart_line( $existing_line ) ) {
                continue;
            }
            $sig = self::cart_line_signature( $existing_line );
            if ( '' !== $sig && ! isset( $signature_map[ $sig ] ) ) {
                $signature_map[ $sig ] = $key;
            }
        }

        foreach ( $source_lines as $source_key => $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }
            $qty = isset( $line['quantity'] ) ? (float) $line['quantity'] : 0.0;
            if ( $qty <= 0 ) {
                continue;
            }
            $product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
            $variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
            if ( $product_id <= 0 && $variation_id <= 0 ) {
                continue;
            }

            $sold_individually = false;
            $stock_cap         = null;
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
                if ( $product instanceof \WC_Product ) {
                    if ( 'trash' === $product->get_status() ) {
                        continue;
                    }
                    $sold_individually = (bool) $product->is_sold_individually();
                    if ( $product->managing_stock() && ! $product->backorders_allowed() && null !== $product->get_stock_quantity() ) {
                        $stock_cap = (int) $product->get_stock_quantity();
                    }
                }
            }

            $is_composite = self::is_composite_cart_line( $line );
            $sig          = $is_composite ? '' : self::cart_line_signature( $line );
            if ( '' !== $sig && isset( $signature_map[ $sig ] ) ) {
                $target_key   = $signature_map[ $sig ];
                $existing_qty = isset( $merged[ $target_key ]['quantity'] ) ? (float) $merged[ $target_key ]['quantity'] : 0.0;
                $combined_qty = $existing_qty + $qty;
                if ( $sold_individually ) {
                    $combined_qty = max( $existing_qty, 1.0 );
                }
                if ( null !== $stock_cap ) {
                    $combined_qty = min( $combined_qty, max( $existing_qty, (float) $stock_cap ) );
                }
                $merged[ $target_key ]['quantity'] = ( floor( $combined_qty ) === $combined_qty ) ? (int) $combined_qty : $combined_qty;
                unset(
                    $merged[ $target_key ]['line_total'],
                    $merged[ $target_key ]['line_subtotal'],
                    $merged[ $target_key ]['line_tax'],
                    $merged[ $target_key ]['line_subtotal_tax'],
                    $merged[ $target_key ]['line_tax_data']
                );
                continue;
            }

            if ( $sold_individually ) {
                $qty = min( $qty, 1.0 );
            }
            if ( null !== $stock_cap ) {
                if ( $stock_cap <= 0 ) {
                    continue;
                }
                $qty = min( $qty, (float) $stock_cap );
            }
            $line['quantity'] = ( floor( $qty ) === $qty ) ? (int) $qty : $qty;

            $dest_key = ( is_string( $source_key ) && '' !== $source_key && ! isset( $merged[ $source_key ] ) )
                ? $source_key
                : md5( (string) wp_json_encode( [ $source_key, $product_id, $variation_id, count( $merged ) ] ) );
            if ( isset( $line['key'] ) ) {
                $line['key'] = $dest_key;
            }
            $merged[ $dest_key ] = $line;
            if ( '' !== $sig ) {
                $signature_map[ $sig ] = $dest_key;
            }
        }

        return $merged;
    }

    /**
     * Whether a cart line participates in parent/child bundle or composite key
     * references that must keep separate line entries.
     *
     * @param array $line Cart line array.
     * @return bool
     */
    private static function is_composite_cart_line( array $line ): bool {
        foreach ( [ 'bundled_by', 'bundled_items', 'composite_parent', 'composite_children' ] as $field ) {
            if ( ! empty( $line[ $field ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Computes a canonical identity signature for a cart line based on
     * product_id, variation_id, variation attributes, and custom item data.
     *
     * @param array $line Cart line array.
     * @return string Signature, or '' when invalid.
     */
    private static function cart_line_signature( array $line ): string {
        $product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
        $variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
        if ( $product_id <= 0 && $variation_id <= 0 ) {
            return '';
        }
        $variation = isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : [];
        ksort( $variation );

        $custom = $line;
        unset(
            $custom['key'],
            $custom['product_id'],
            $custom['variation_id'],
            $custom['variation'],
            $custom['quantity'],
            $custom['data_hash'],
            $custom['line_tax_data'],
            $custom['line_subtotal'],
            $custom['line_subtotal_tax'],
            $custom['line_total'],
            $custom['line_tax']
        );
        ksort( $custom );

        return md5( (string) wp_json_encode( [ $product_id, $variation_id, $variation, $custom ] ) );
    }

    /**
     * Writes updated cart lines into a woocommerce_sessions SQL row and evicts
     * its object-cache entry.
     *
     * @param string $session_key     Session key.
     * @param array  $cart_lines      Merged cart lines.
     * @param string $gecx_session_id Optional GECX session ID to preserve.
     */
    private static function write_session_row_cart( string $session_key, array $cart_lines, string $gecx_session_id = '' ): void {
        global $wpdb;
        if ( '' === $session_key || ! isset( $wpdb ) ) {
            return;
        }
        $session_data         = self::read_session_row( $session_key );
        $session_data['cart'] = maybe_serialize( $cart_lines );
        if ( empty( $session_data['gecx_session_id'] ) && '' !== $gecx_session_id ) {
            $session_data['gecx_session_id'] = $gecx_session_id;
        }
        $serialized = maybe_serialize( $session_data );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce filter.
        $expiry = time() + (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct update required to sync WooCommerce session storage.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry)
             VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE session_value = %s, session_expiry = %d",
            $session_key,
            $serialized,
            $expiry,
            $serialized,
            $expiry
        ) );
        if ( false !== $result ) {
            self::evict_session_cache( $session_key );
        }
    }

    /**
     * Empties the cart stored in an agent guest session row after its items
     * have been merged into the shopper's session so repeat calls and fallback
     * client copies are idempotent.
     *
     * @param string $agent_key Guest session key (`t_...`).
     */
    private static function clear_session_row_cart( string $agent_key ): void {
        global $wpdb;
        if ( '' === $agent_key || ! isset( $wpdb ) ) {
            return;
        }
        $session_data = self::read_session_row( $agent_key );
        if ( empty( $session_data ) ) {
            return;
        }
        $session_data['cart'] = maybe_serialize( [] );
        $serialized           = maybe_serialize( $session_data );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct update required to drain merged guest cart row.
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}woocommerce_sessions SET session_value = %s WHERE session_key = %s",
            $serialized,
            $agent_key
        ) );
        if ( false !== $result ) {
            self::evict_session_cache( $agent_key );
        }
    }

    /**
     * Evicts a WooCommerce session entry from the WordPress object cache.
     *
     * @param string $session_key Session key.
     */
    private static function evict_session_cache( string $session_key ): void {
        if ( ! function_exists( 'wp_cache_delete' ) ) {
            return;
        }
        $cache_group  = defined( 'WC_SESSION_CACHE_GROUP' )
            ? WC_SESSION_CACHE_GROUP
            : ( defined( 'WC_Cache_Helper::WC_SESSION_CACHE_GROUP' ) ? \WC_Cache_Helper::WC_SESSION_CACHE_GROUP : 'wc_sessions' );
        $cache_prefix = ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_cache_prefix' ) )
            ? \WC_Cache_Helper::get_cache_prefix( $cache_group )
            : 'wc_session_';
        wp_cache_delete( $cache_prefix . $session_key, $cache_group );
    }

    /**
     * Points the browser's WooCommerce session at an existing guest session
     * and sets the session cookie for it.
     *
     * WooCommerce writes the cookie itself, through
     * WC_Session_Handler::set_customer_session_cookie(), so it is in whatever
     * format the installed version reads. WC_Session_Handler has no public
     * setter for the customer ID, nor a getter for the session's expiration,
     * so both are handled from inside the class's scope.
     *
     * The session's row is given the cookie's new expiration too.
     * WooCommerce only moves a row's expiry when it saves changed session
     * data or finds the cookie past its expiring time, and binding does
     * neither, so the row would keep the expiry from the agent's last change
     * and cleanup_sessions() could delete the cart while the cookie still
     * names it.
     *
     * @param \WC_Session_Handler $session   WC()->session, table-backed.
     * @param string              $guest_key Guest session key (t_...).
     */
    private static function bind_browser_to_session( $session, string $guest_key ): void {
        $point_at   = \Closure::bind(
            function ( string $customer_id, array $session_data ): int {
                $this->_customer_id = $customer_id;
                $this->_data        = $session_data;
                $this->set_session_expiration();
                return (int) $this->_session_expiration;
            },
            $session,
            \WC_Session_Handler::class
        );
        $expiration = $point_at( $guest_key, self::read_session_row( $guest_key ) );
        $session->set_customer_session_cookie( true );
        $session->update_session_timestamp( $guest_key, $expiration );
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
