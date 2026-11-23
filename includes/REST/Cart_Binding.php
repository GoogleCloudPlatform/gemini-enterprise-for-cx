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
        add_action( 'woocommerce_guest_session_to_user_id', [ self::class, 'on_guest_session_migrated_to_user' ], 10, 2 );
        add_action( 'woocommerce_cart_emptied', [ self::class, 'on_cart_emptied' ], 10, 1 );
    }

    /**
     * Folds a migrated guest session's `gecx_merged` ledger into the logged-in
     * shopper's `_gecx_merged_{blog_id}` user meta on WooCommerce 8.8+ login
     * migration (`WC_Session_Handler::init_session_cookie()`), letting fresher
     * guest entries win for the same agent key and dropping `$guest_session_id`
     * itself since that cart now belongs to the shopper.
     *
     * `WC_Session_Handler::init_session_cookie()` fires this hook right after
     * `$this->save_data( $guest_session_id )`, which has already copied the
     * guest session's data into row `(string) $user_id` and deleted row
     * `$guest_session_id`.
     *
     * @param mixed $guest_session_id Previous guest session key (`t_...`).
     * @param mixed $user_id          Logged-in user ID.
     */
    public static function on_guest_session_migrated_to_user( $guest_session_id, $user_id ): void {
        $guest_key = is_scalar( $guest_session_id ) ? (string) $guest_session_id : '';
        $uid       = is_numeric( $user_id ) ? (int) $user_id : 0;
        if ( $uid <= 0 ) {
            return;
        }
        $user_key        = (string) $uid;
        $migrated_ledger = null;

        $user_row = self::read_session_row( $user_key );
        if ( array_key_exists( 'gecx_merged', $user_row ) ) {
            $migrated_ledger = self::extract_merged_ledger( $user_row['gecx_merged'] );
        } elseif ( function_exists( 'WC' ) && null !== WC()->session && is_object( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'get' ) ) {
            $in_mem = WC()->session->get( 'gecx_merged' );
            if ( null !== $in_mem ) {
                $migrated_ledger = self::extract_merged_ledger( $in_mem );
            }
        } elseif ( '' !== $guest_key ) {
            $guest_row = self::read_session_row( $guest_key );
            if ( array_key_exists( 'gecx_merged', $guest_row ) ) {
                $migrated_ledger = self::extract_merged_ledger( $guest_row['gecx_merged'] );
            }
        }

        $meta_ledger = self::read_user_merged_ledger( $uid );
        $combined    = is_array( $migrated_ledger )
            ? array_replace( $meta_ledger, $migrated_ledger )
            : $meta_ledger;
        if ( '' !== $guest_key ) {
            unset( $combined[ $guest_key ] );
        }
        if ( count( $combined ) > 16 ) {
            $combined = array_slice( $combined, -16, 16, true );
        }

        self::update_user_merged_ledger( $uid, $combined );

        if ( null !== $migrated_ledger ) {
            if ( function_exists( 'WC' ) && null !== WC()->session && is_object( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'set' ) ) {
                WC()->session->set( 'gecx_merged', empty( $combined ) ? null : $combined );
            }
            if ( ! empty( $user_row ) ) {
                self::set_session_row_ledger( $user_key, $combined );
            }
        }
    }

    /**
     * Clears `gecx_merged` ledger state when the shopper's cart is emptied
     * (such as on checkout order placement or explicit cart clear) so stale
     * synced-line entries never subtract items the shopper later adds manually.
     *
     * When WooCommerce empties only the in-memory session on logout
     * (`WC_Session_Handler::forget_session()` -> `wc_empty_cart( false )`),
     * `$clear_persistent_cart` is `false` and `_woocommerce_persistent_cart_{blog_id}`
     * is retained, so `_gecx_merged_{blog_id}` is retained alongside it.
     *
     * @param mixed $clear_persistent_cart Whether WooCommerce also cleared the persistent cart.
     */
    public static function on_cart_emptied( $clear_persistent_cart = true ): void {
        $clear_meta = ( func_num_args() >= 1 )
            ? (bool) $clear_persistent_cart
            : ( 0 === did_action( 'wp_logout' ) );

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $clear_meta && $user_id > 0 ) {
            self::update_user_merged_ledger( $user_id, [] );
            self::set_session_row_ledger( (string) $user_id, [] );
        }

        if ( function_exists( 'WC' ) && null !== WC()->session && is_object( WC()->session ) ) {
            if ( method_exists( WC()->session, 'set' ) ) {
                WC()->session->set( 'gecx_merged', null );
            }
            $customer_id = method_exists( WC()->session, 'get_customer_id' ) ? (string) WC()->session->get_customer_id() : '';
            if ( '' !== $customer_id && self::is_table_backed( WC()->session ) ) {
                if ( $clear_meta || 0 === strpos( $customer_id, 't_' ) ) {
                    self::set_session_row_ledger( $customer_id, [] );
                }
            }
        }
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
     * - 'merged': changes from the agent's guest cart were merged into the
     *   browser's own cart (a cookied guest with items, a logged-in shopper
     *   without migrating their session cookie, or an external session handler
     *   such as Redis/Memcached). The agent's guest cart row is kept intact so
     *   in-flight Store API saves cannot resurrect drained items or lose lines
     *   if a target write fails; instead, per-line synced quantities are
     *   recorded under `gecx_merged` on the target session (under the same
     *   per-session lock) so repeat calls, overlapping Store API adds,
     *   quantity updates, and item removals apply only the delta. No
     *   Cart-Token is returned for the browser's own session.
     * - 'existing': the browser keeps its own cart unchanged (already on the
     *   agent's cart, or zero net delta between the agent's guest cart and
     *   `gecx_merged`).
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

        $agent_row   = ( '' !== $agent_key ) ? self::read_session_row( $agent_key ) : [];
        $agent_lines = self::extract_cart_lines( $agent_row['cart'] ?? null );

        // The browser's own cart is checked whether or not its cookie
        // verifies: a session whose cookie this plugin cannot verify, such as
        // one a custom session handler signs in its own format, may still
        // hold a cart, and replacing the session cookie would orphan it. Once
        // a browser session holds its own items (or has previously merged from
        // this agent guest cart), apply per-line deltas under a per-session
        // lock instead of swapping cookies.
        $has_merged_history = '' !== $agent_key && self::browser_has_merged_history( $session, $agent_key );
        if ( self::browser_cart_has_items( $session ) || $has_merged_history ) {
            // A missing agent row ([] === $agent_row, such as after login migration
            // or session expiry) carries no information rather than an emptied cart.
            if ( '' === $agent_key || [] === $agent_row || ( empty( $agent_lines ) && ! self::browser_has_non_empty_merged_lines( $session, $agent_key ) ) ) {
                return self::respond( 'existing' );
            }
            if ( ! self::is_table_backed( $session ) && ! self::can_persist_external_session( $session ) ) {
                return self::respond( 'unsupported' );
            }
            $lock_key = (string) $session->get_customer_id();
            return self::with_merge_lock(
                '' !== $lock_key ? $lock_key : $agent_key,
                static function () use ( $session, $agent_key ) {
                    return self::sync_agent_into_browser_session( $session, $agent_key, false );
                }
            );
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
            $lock_key = (string) $session->get_customer_id();
            return self::with_merge_lock(
                '' !== $lock_key ? $lock_key : $agent_key,
                static function () use ( $session, $agent_key ) {
                    return self::sync_agent_into_browser_session( $session, $agent_key, true );
                }
            );
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
        $customer_id = method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
        if ( '' !== $customer_id && self::is_table_backed( $session ) ) {
            $row = self::read_session_row( $customer_id );
            if ( array_key_exists( 'cart', $row ) ) {
                return ! empty( self::extract_cart_lines( $row['cart'] ) );
            }
        }
        $lines = self::extract_cart_lines( method_exists( $session, 'get' ) ? $session->get( 'cart' ) : null );
        return ! empty( $lines );
    }

    /**
     * Whether the browser session has previously merged from `$agent_key`, so
     * it stays on its own session even if all items were subsequently removed.
     *
     * @param object $session   WC()->session.
     * @param string $agent_key Guest session key (`t_...`).
     * @return bool
     */
    private static function browser_has_merged_history( $session, string $agent_key ): bool {
        $ledger = self::read_browser_merged_ledger( $session );
        return array_key_exists( $agent_key, $ledger );
    }

    /**
     * Whether the browser session currently tracks non-zero synced lines from
     * `$agent_key` (so an emptied `$agent_key` still needs a removal pass).
     *
     * @param object $session   WC()->session.
     * @param string $agent_key Guest session key (`t_...`).
     * @return bool
     */
    private static function browser_has_non_empty_merged_lines( $session, string $agent_key ): bool {
        $ledger = self::read_browser_merged_ledger( $session );
        return ! empty( $ledger[ $agent_key ] );
    }

    /**
     * Reads the `gecx_merged` ledger for a browser session, preferring the
     * database row when table-backed.
     *
     * @param object $session WC()->session.
     * @return array
     */
    private static function read_browser_merged_ledger( $session ): array {
        $customer_id = method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
        if ( '' !== $customer_id && self::is_table_backed( $session ) ) {
            $row = self::read_session_row( $customer_id );
            if ( array_key_exists( 'gecx_merged', $row ) ) {
                return self::extract_merged_ledger( $row['gecx_merged'] );
            }
        }
        return self::extract_merged_ledger( method_exists( $session, 'get' ) ? $session->get( 'gecx_merged' ) : null );
    }

    /**
     * Normalizes raw `gecx_merged` session storage into an array map.
     *
     * @param mixed $raw Raw serialized or array ledger.
     * @return array
     */
    private static function extract_merged_ledger( $raw ): array {
        if ( empty( $raw ) ) {
            return [];
        }
        $ledger = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
        return is_array( $ledger ) ? $ledger : [];
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
     * session cookie. Any separate guest session cookie on the browser is left
     * untouched so WooCommerce's own login migration handles it once.
     *
     * @param int    $user_id   Logged-in WordPress user ID (> 0).
     * @param string $agent_key Verified guest cart session key (`t_...`), or ''.
     * @return \WP_REST_Response|\WP_Error
     */
    private static function merge_for_logged_in_shopper( int $user_id, string $agent_key ) {
        if ( '' === $agent_key ) {
            return self::respond( 'existing' );
        }
        $agent_row = self::read_session_row( $agent_key );
        if ( [] === $agent_row ) {
            return self::respond( 'existing' );
        }
        $agent_lines = self::extract_cart_lines( $agent_row['cart'] ?? null );
        if ( empty( $agent_lines ) ) {
            $initial_state = self::read_user_cart_state( $user_id );
            if ( empty( $initial_state['ledger'][ $agent_key ] ) ) {
                return self::respond( 'existing' );
            }
        }

        $user_key = (string) $user_id;
        if ( null !== WC()->session && ! self::is_table_backed( WC()->session ) ) {
            if ( (string) WC()->session->get_customer_id() === $user_key && self::can_persist_external_session( WC()->session ) ) {
                return self::with_merge_lock(
                    $user_key,
                    static function () use ( $user_id, $agent_key ) {
                        $session_ledger   = self::extract_merged_ledger( WC()->session->get( 'gecx_merged' ) );
                        $user_meta_ledger = self::read_user_merged_ledger( $user_id );
                        if ( ! empty( $user_meta_ledger ) ) {
                            $combined_ledger = array_replace( $user_meta_ledger, $session_ledger );
                            if ( count( $combined_ledger ) > 16 ) {
                                $combined_ledger = array_slice( $combined_ledger, -16, 16, true );
                            }
                            WC()->session->set( 'gecx_merged', $combined_ledger );
                        }
                        $response = self::sync_agent_into_browser_session( WC()->session, $agent_key, false );
                        if ( is_wp_error( $response ) ) {
                            return $response;
                        }
                        $merged_cart   = self::extract_cart_lines( WC()->session->get( 'cart' ) );
                        $merged_ledger = self::extract_merged_ledger( WC()->session->get( 'gecx_merged' ) );
                        self::update_persistent_user_cart( $user_id, $merged_cart );
                        self::update_user_merged_ledger( $user_id, $merged_ledger );
                        return $response;
                    }
                );
            }
            return self::respond( 'unsupported' );
        }
        if ( null === WC()->session && ! self::is_configured_handler_table_backed() ) {
            return self::respond( 'unsupported' );
        }

        return self::with_merge_lock(
            $user_key,
            static function () use ( $user_id, $user_key, $agent_key ) {
                $fresh_agent = self::read_session_row( $agent_key );
                if ( [] === $fresh_agent ) {
                    return self::respond( 'existing' );
                }
                $fresh_lines     = self::extract_cart_lines( $fresh_agent['cart'] ?? null );
                $gecx_session_id = isset( $fresh_agent['gecx_session_id'] ) && is_string( $fresh_agent['gecx_session_id'] )
                    ? $fresh_agent['gecx_session_id']
                    : '';

                $user_state = self::read_user_cart_state( $user_id );
                $ledger     = $user_state['ledger'];
                $prev_sync  = isset( $ledger[ $agent_key ] ) && is_array( $ledger[ $agent_key ] ) ? $ledger[ $agent_key ] : [];
                if ( empty( $fresh_lines ) && empty( $prev_sync ) ) {
                    return self::respond( 'existing' );
                }

                $diff   = self::apply_agent_cart_diff( $user_state['cart'], $fresh_lines, $prev_sync );
                $before = $ledger;
                $ledger = self::record_synced_agent_entry( $ledger, $agent_key, $diff['synced'] );

                if ( ! $diff['changed'] && $ledger === $before ) {
                    if ( $ledger !== $user_state['meta_ledger'] ) {
                        self::update_user_merged_ledger( $user_id, $ledger );
                    }
                    return self::respond( 'existing' );
                }

                if ( ! self::write_session_row_cart( $user_key, $diff['cart'], $gecx_session_id, $ledger ) ) {
                    return new \WP_Error( 'cart_merge_failed', __( 'Could not persist merged cart.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
                }
                self::update_persistent_user_cart( $user_id, $diff['cart'] );
                self::update_user_merged_ledger( $user_id, $ledger );
                if ( null !== WC()->session && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'set' ) ) {
                    WC()->session->set( 'cart', $diff['cart'] );
                    WC()->session->set( 'gecx_merged', $ledger );
                    if ( '' !== $gecx_session_id && method_exists( WC()->session, 'get' ) && empty( WC()->session->get( 'gecx_session_id' ) ) ) {
                        WC()->session->set( 'gecx_session_id', $gecx_session_id );
                    }
                    if ( method_exists( WC()->session, 'save_data' ) ) {
                        WC()->session->save_data();
                    }
                }
                return self::respond( $diff['changed'] ? 'merged' : 'existing' );
            }
        );
    }

    /**
     * Reads a logged-in user's existing cart lines and `gecx_merged` ledger
     * without initializing WC()->session, using `_gecx_merged_{blog_id}` user
     * meta as the durable baseline while letting any fresher `gecx_merged`
     * entries on the active/migrated session row take precedence for matching
     * agent keys.
     *
     * @param int $user_id WordPress user ID.
     * @return array `['cart' => array, 'ledger' => array, 'meta_ledger' => array]`
     */
    private static function read_user_cart_state( int $user_id ): array {
        $user_key   = (string) $user_id;
        $row        = self::read_session_row( $user_key );
        $saved_meta = ( function_exists( 'get_user_meta' ) && function_exists( 'get_current_blog_id' ) )
            ? get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true )
            : null;

        $cart = null;
        if ( array_key_exists( 'cart', $row ) ) {
            $cart = self::extract_cart_lines( $row['cart'] );
        } elseif ( null !== WC()->session && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'get' ) ) {
            $in_memory = WC()->session->get( 'cart' );
            if ( null !== $in_memory ) {
                $cart = self::extract_cart_lines( $in_memory );
            }
        }
        if ( null === $cart && is_array( $saved_meta ) && isset( $saved_meta['cart'] ) ) {
            $cart = self::extract_cart_lines( $saved_meta['cart'] );
        }

        $meta_ledger     = self::read_user_merged_ledger( $user_id );
        $fallback_ledger = null;
        if ( array_key_exists( 'gecx_merged', $row ) ) {
            $fallback_ledger = self::extract_merged_ledger( $row['gecx_merged'] );
        } elseif ( null !== WC()->session && (string) WC()->session->get_customer_id() === $user_key && method_exists( WC()->session, 'get' ) ) {
            $mem_ledger = WC()->session->get( 'gecx_merged' );
            if ( null !== $mem_ledger ) {
                $fallback_ledger = self::extract_merged_ledger( $mem_ledger );
            }
        }
        if ( null === $fallback_ledger && is_array( $saved_meta ) && isset( $saved_meta['gecx_merged'] ) ) {
            $fallback_ledger = self::extract_merged_ledger( $saved_meta['gecx_merged'] );
        }

        $ledger = is_array( $fallback_ledger )
            ? array_replace( $meta_ledger, $fallback_ledger )
            : $meta_ledger;
        if ( count( $ledger ) > 16 ) {
            $ledger = array_slice( $ledger, -16, 16, true );
        }

        return [
            'cart'        => is_array( $cart ) ? $cart : [],
            'ledger'      => $ledger,
            'meta_ledger' => $meta_ledger,
        ];
    }

    /**
     * User-meta key storing a logged-in shopper's `gecx_merged` ledger for the
     * current site, kept separate from `_woocommerce_persistent_cart_{blog_id}`
     * which WooCommerce rewrites without custom keys.
     *
     * @return string
     */
    private static function get_user_merged_ledger_meta_key(): string {
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        return '_gecx_merged_' . $blog_id;
    }

    /**
     * Reads a logged-in user's dedicated `_gecx_merged_{blog_id}` ledger.
     *
     * @param int $user_id WordPress user ID.
     * @return array
     */
    private static function read_user_merged_ledger( int $user_id ): array {
        if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
            return [];
        }
        return self::extract_merged_ledger( get_user_meta( $user_id, self::get_user_merged_ledger_meta_key(), true ) );
    }

    /**
     * Persists or deletes a logged-in user's dedicated `_gecx_merged_{blog_id}`
     * ledger (deleting the meta row when empty).
     *
     * @param int   $user_id WordPress user ID.
     * @param array $ledger  Per-agent merged line ledger.
     */
    private static function update_user_merged_ledger( int $user_id, array $ledger ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        if ( empty( $ledger ) ) {
            if ( function_exists( 'delete_user_meta' ) ) {
                delete_user_meta( $user_id, self::get_user_merged_ledger_meta_key() );
            }
            return;
        }
        if ( function_exists( 'update_user_meta' ) ) {
            update_user_meta( $user_id, self::get_user_merged_ledger_meta_key(), $ledger );
        }
    }

    /**
     * Updates a logged-in user's persistent cart user meta in WooCommerce's
     * native `['cart' => ...]` format.
     *
     * @param int   $user_id    WordPress user ID.
     * @param array $cart_lines Session cart lines.
     */
    private static function update_persistent_user_cart( int $user_id, array $cart_lines ): void {
        if ( function_exists( 'update_user_meta' ) && function_exists( 'get_current_blog_id' ) ) {
            update_user_meta(
                $user_id,
                '_woocommerce_persistent_cart_' . get_current_blog_id(),
                [
                    'cart' => $cart_lines,
                ]
            );
        }
    }

    /**
     * Synchronizes per-line deltas from `$agent_key` into an active browser
     * `WC()->session` (table-backed or external Redis/Memcached) without
     * draining `$agent_key`. Must be called inside `with_merge_lock()`.
     *
     * @param object $session               WC()->session for the browser.
     * @param string $agent_key             Guest session key (`t_...`).
     * @param bool   $set_cookie_if_missing Whether to set cookie on uncookied external session.
     * @return \WP_REST_Response|\WP_Error
     */
    private static function sync_agent_into_browser_session( $session, string $agent_key, bool $set_cookie_if_missing ) {
        $fresh_agent = self::read_session_row( $agent_key );
        if ( [] === $fresh_agent ) {
            return self::respond( $set_cookie_if_missing ? 'deferred' : 'existing' );
        }
        $agent_lines = self::extract_cart_lines( $fresh_agent['cart'] ?? null );
        $gecx_session_id = isset( $fresh_agent['gecx_session_id'] ) && is_string( $fresh_agent['gecx_session_id'] )
            ? $fresh_agent['gecx_session_id']
            : '';

        $customer_id   = (string) $session->get_customer_id();
        $table_backed  = self::is_table_backed( $session );
        $browser_lines = null;
        $ledger        = null;

        if ( '' !== $customer_id && $table_backed ) {
            $fresh_row = self::read_session_row( $customer_id );
            if ( array_key_exists( 'cart', $fresh_row ) ) {
                $browser_lines = self::extract_cart_lines( $fresh_row['cart'] );
            }
            if ( array_key_exists( 'gecx_merged', $fresh_row ) ) {
                $ledger = self::extract_merged_ledger( $fresh_row['gecx_merged'] );
            }
        }
        if ( null === $browser_lines ) {
            $browser_lines = self::extract_cart_lines( method_exists( $session, 'get' ) ? $session->get( 'cart' ) : null );
        }
        if ( null === $ledger ) {
            $ledger = self::extract_merged_ledger( method_exists( $session, 'get' ) ? $session->get( 'gecx_merged' ) : null );
        }

        $prev_sync = isset( $ledger[ $agent_key ] ) && is_array( $ledger[ $agent_key ] ) ? $ledger[ $agent_key ] : [];
        if ( empty( $agent_lines ) && empty( $prev_sync ) ) {
            return self::respond( empty( $browser_lines ) && $set_cookie_if_missing ? 'deferred' : 'existing' );
        }

        $diff   = self::apply_agent_cart_diff( $browser_lines, $agent_lines, $prev_sync );
        $before = $ledger;
        $ledger = self::record_synced_agent_entry( $ledger, $agent_key, $diff['synced'] );

        if ( ! $diff['changed'] && $ledger === $before ) {
            return self::respond( 'existing' );
        }

        if ( '' !== $customer_id && $table_backed ) {
            if ( ! self::write_session_row_cart( $customer_id, $diff['cart'], $gecx_session_id, $ledger ) ) {
                return new \WP_Error( 'cart_merge_failed', __( 'Could not persist merged cart.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
            }
        }

        $session->set( 'cart', $diff['cart'] );
        $session->set( 'gecx_merged', $ledger );
        if ( '' !== $gecx_session_id && method_exists( $session, 'get' ) && empty( $session->get( 'gecx_session_id' ) ) ) {
            $session->set( 'gecx_session_id', $gecx_session_id );
        }
        if ( $set_cookie_if_missing && ! empty( $diff['cart'] ) && '' === self::get_browser_guest_key( $session ) && ! Cart_Session::has_woocommerce_session_cookie() ) {
            $session->set_customer_session_cookie( true );
        }
        if ( ! $table_backed && method_exists( $session, 'save_data' ) ) {
            $session->save_data();
        }

        return self::respond( $diff['changed'] ? 'merged' : 'existing' );
    }

    /**
     * Records `$synced_lines` under `$agent_key` in `$ledger`, capping ledger
     * history to the 16 most recent guest tokens.
     *
     * @param array  $ledger       Existing `gecx_merged` map.
     * @param string $agent_key    Agent guest session key (`t_...`).
     * @param array  $synced_lines Updated per-line sync state for `$agent_key`.
     * @return array
     */
    private static function record_synced_agent_entry( array $ledger, string $agent_key, array $synced_lines ): array {
        unset( $ledger[ $agent_key ] );
        $ledger[ $agent_key ] = $synced_lines;
        if ( count( $ledger ) > 16 ) {
            $ledger = array_slice( $ledger, -16, 16, true );
        }
        return $ledger;
    }

    /**
     * Applies the net per-line difference between `$source_lines` (current
     * agent cart) and `$prev_synced` (lines already merged from that agent
     * cart) onto `$target_lines`, supporting additions, quantity updates, and
     * removals while respecting `sold_individually` and stock caps.
     *
     * @param array $target_lines Existing browser/user cart lines.
     * @param array $source_lines Current agent guest cart lines.
     * @param array $prev_synced  Previously synced lines for this `$agent_key`.
     * @return array `['cart' => array, 'synced' => array, 'changed' => bool]`
     */
    private static function apply_agent_cart_diff( array $target_lines, array $source_lines, array $prev_synced ): array {
        $merged        = $target_lines;
        $changed       = false;
        $next_synced   = [];
        $signature_map = self::build_signature_map( $merged );

        $current_agent = [];
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
            $is_composite = self::is_composite_cart_line( $line );
            $sig          = $is_composite ? '' : self::cart_line_signature( $line );
            $track_id     = ( ! $is_composite && '' !== $sig ) ? ( 's:' . $sig ) : ( 'k:' . (string) $source_key );

            if ( isset( $current_agent[ $track_id ] ) ) {
                $current_agent[ $track_id ]['quantity'] += $qty;
            } else {
                $current_agent[ $track_id ] = [
                    'quantity'     => $qty,
                    'source_key'   => is_string( $source_key ) ? $source_key : '',
                    'sig'          => $sig,
                    'is_composite' => $is_composite,
                    'line'         => $line,
                ];
            }
        }

        // First apply quantity reductions and full removals from previously synced lines,
        // subtracting at most the quantity actually applied to the target cart.
        foreach ( $prev_synced as $track_id => $prev_entry ) {
            if ( ! is_array( $prev_entry ) ) {
                continue;
            }
            $prev_applied = isset( $prev_entry['quantity'] ) ? max( 0.0, (float) $prev_entry['quantity'] ) : 0.0;
            $prev_agent   = isset( $prev_entry['agent_qty'] ) ? max( 0.0, (float) $prev_entry['agent_qty'] ) : $prev_applied;
            if ( $prev_agent <= 0 && $prev_applied <= 0 ) {
                continue;
            }
            $curr_qty = isset( $current_agent[ $track_id ] ) ? (float) $current_agent[ $track_id ]['quantity'] : 0.0;
            if ( $curr_qty >= $prev_agent ) {
                continue;
            }

            $desired_applied = min( $prev_applied, $curr_qty );
            $remove_qty      = $prev_applied - $desired_applied;
            $match_key       = self::find_synced_target_key( $merged, $signature_map, $prev_entry );
            $remaining_line  = 0.0;

            if ( null !== $match_key ) {
                $existing_qty = isset( $merged[ $match_key ]['quantity'] ) ? (float) $merged[ $match_key ]['quantity'] : 0.0;
                if ( $remove_qty > 0 && $existing_qty > 0 ) {
                    $actual_removed = min( $existing_qty, $remove_qty );
                    $new_target_qty = $existing_qty - $actual_removed;
                    if ( $new_target_qty <= 0 ) {
                        unset( $merged[ $match_key ] );
                        $signature_map  = self::build_signature_map( $merged );
                        $remaining_line = 0.0;
                    } else {
                        $merged[ $match_key ]['quantity'] = self::normalize_qty( $new_target_qty );
                        self::strip_cached_line_totals( $merged[ $match_key ] );
                        $remaining_line = $new_target_qty;
                    }
                    $changed = true;
                } else {
                    $remaining_line = $existing_qty;
                }
            }

            if ( $curr_qty > 0 ) {
                $new_applied              = ( null !== $match_key ) ? min( $remaining_line, $desired_applied ) : 0.0;
                $next_synced[ $track_id ] = [
                    'quantity'   => self::normalize_qty( $new_applied ),
                    'agent_qty'  => self::normalize_qty( $curr_qty ),
                    'target_key' => ( null !== $match_key && isset( $merged[ $match_key ] ) ) ? $match_key : '',
                    'source_key' => (string) ( $current_agent[ $track_id ]['source_key'] ?? ( $prev_entry['source_key'] ?? '' ) ),
                    'sig'        => (string) ( $current_agent[ $track_id ]['sig'] ?? ( $prev_entry['sig'] ?? '' ) ),
                    'line'       => self::minimal_line_identity( $current_agent[ $track_id ]['line'] ),
                ];
            }
        }

        // Next apply unchanged entries and positive deltas (new lines and quantity increases),
        // recording only the quantity actually applied alongside the seen agent quantity.
        foreach ( $current_agent as $track_id => $item ) {
            $curr_qty     = (float) $item['quantity'];
            $prev_entry   = isset( $prev_synced[ $track_id ] ) && is_array( $prev_synced[ $track_id ] ) ? $prev_synced[ $track_id ] : null;
            $prev_applied = null !== $prev_entry && isset( $prev_entry['quantity'] ) ? max( 0.0, (float) $prev_entry['quantity'] ) : 0.0;
            $prev_agent   = null !== $prev_entry && isset( $prev_entry['agent_qty'] ) ? max( 0.0, (float) $prev_entry['agent_qty'] ) : $prev_applied;

            if ( $curr_qty < $prev_agent ) {
                continue;
            }
            if ( $curr_qty === $prev_agent && null !== $prev_entry ) {
                $next_synced[ $track_id ] = $prev_entry;
                continue;
            }

            $delta_qty    = $curr_qty - $prev_agent;
            $line         = $item['line'];
            $source_key   = $item['source_key'];
            $is_composite = $item['is_composite'];
            $sig          = $item['sig'];
            $product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
            $variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;

            $sold_individually = false;
            $stock_cap         = null;
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
                if ( $product instanceof \WC_Product ) {
                    if ( 'trash' === $product->get_status() ) {
                        $next_synced[ $track_id ] = [
                            'quantity'   => self::normalize_qty( $prev_applied ),
                            'agent_qty'  => self::normalize_qty( $curr_qty ),
                            'target_key' => null !== $prev_entry ? (string) ( $prev_entry['target_key'] ?? '' ) : '',
                            'source_key' => $source_key,
                            'sig'        => $sig,
                            'line'       => self::minimal_line_identity( $line ),
                        ];
                        continue;
                    }
                    $sold_individually = (bool) $product->is_sold_individually();
                    if ( $product->managing_stock() && ! $product->backorders_allowed() && null !== $product->get_stock_quantity() ) {
                        $stock_cap = (int) $product->get_stock_quantity();
                    }
                }
            }

            $variation  = isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : [];
            $generated  = $is_composite ? '' : self::generate_wc_cart_id( $product_id, $variation_id, $variation, self::extract_custom_cart_item_data( $line ) );
            $target_key = null;

            if ( null !== $prev_entry ) {
                $target_key = self::find_synced_target_key( $merged, $signature_map, $prev_entry );
            }
            if ( null === $target_key && ! $is_composite ) {
                if ( '' !== $source_key && isset( $merged[ $source_key ] ) && self::lines_match_product_and_variation( $merged[ $source_key ], $line ) ) {
                    $target_key = $source_key;
                } elseif ( '' !== $sig && isset( $signature_map[ $sig ] ) && isset( $merged[ $signature_map[ $sig ] ] ) ) {
                    $target_key = $signature_map[ $sig ];
                } elseif ( '' !== $generated && isset( $merged[ $generated ] ) && self::lines_match_product_and_variation( $merged[ $generated ], $line ) ) {
                    $target_key = $generated;
                }
            }

            if ( null !== $target_key ) {
                $before_qty = isset( $merged[ $target_key ]['quantity'] ) ? (float) $merged[ $target_key ]['quantity'] : 0.0;
                self::combine_into_existing_cart_line( $merged, $target_key, $delta_qty, $sold_individually, $stock_cap );
                $after_qty      = isset( $merged[ $target_key ]['quantity'] ) ? (float) $merged[ $target_key ]['quantity'] : 0.0;
                $actually_added = max( 0.0, $after_qty - $before_qty );
                if ( $actually_added > 0 ) {
                    $changed = true;
                }
                $new_applied              = min( $after_qty, $prev_applied + $actually_added );
                $next_synced[ $track_id ] = [
                    'quantity'   => self::normalize_qty( $new_applied ),
                    'agent_qty'  => self::normalize_qty( $curr_qty ),
                    'target_key' => $target_key,
                    'source_key' => $source_key,
                    'sig'        => $sig,
                    'line'       => self::minimal_line_identity( $line ),
                ];
                continue;
            }

            $add_qty = $delta_qty;
            if ( $sold_individually ) {
                $add_qty = min( $add_qty, 1.0 );
            }
            if ( null !== $stock_cap ) {
                if ( $stock_cap <= 0 ) {
                    $next_synced[ $track_id ] = [
                        'quantity'   => 0,
                        'agent_qty'  => self::normalize_qty( $curr_qty ),
                        'target_key' => '',
                        'source_key' => $source_key,
                        'sig'        => $sig,
                        'line'       => self::minimal_line_identity( $line ),
                    ];
                    continue;
                }
                $add_qty = min( $add_qty, (float) $stock_cap );
            }
            $line['quantity'] = self::normalize_qty( $add_qty );
            self::strip_cached_line_totals( $line );

            if ( '' !== $source_key && ! isset( $merged[ $source_key ] ) ) {
                $dest_key = $source_key;
            } elseif ( '' !== $generated && ! isset( $merged[ $generated ] ) ) {
                $dest_key = $generated;
            } else {
                $dest_key = md5( (string) wp_json_encode( [ $source_key, $product_id, $variation_id, $variation, count( $merged ) ] ) );
            }
            if ( isset( $line['key'] ) ) {
                $line['key'] = $dest_key;
            }
            $merged[ $dest_key ] = $line;
            if ( '' !== $sig ) {
                $signature_map[ $sig ] = $dest_key;
            }
            $changed                  = true;
            $next_synced[ $track_id ] = [
                'quantity'   => self::normalize_qty( $add_qty ),
                'agent_qty'  => self::normalize_qty( $curr_qty ),
                'target_key' => $dest_key,
                'source_key' => $source_key,
                'sig'        => $sig,
                'line'       => self::minimal_line_identity( $line ),
            ];
        }

        return [
            'cart'    => $merged,
            'synced'  => $next_synced,
            'changed' => $changed,
        ];
    }

    /**
     * Builds a map from canonical line signature to cart item key for
     * non-composite lines in `$lines`.
     *
     * @param array $lines Cart lines.
     * @return array
     */
    private static function build_signature_map( array $lines ): array {
        $map = [];
        foreach ( $lines as $key => $existing_line ) {
            if ( ! is_array( $existing_line ) || self::is_composite_cart_line( $existing_line ) ) {
                continue;
            }
            $sig = self::cart_line_signature( $existing_line );
            if ( '' !== $sig && ! isset( $map[ $sig ] ) ) {
                $map[ $sig ] = (string) $key;
            }
        }
        return $map;
    }

    /**
     * Locates the target cart item key in `$merged` corresponding to a
     * previously synced ledger entry.
     *
     * @param array $merged        Current target cart lines.
     * @param array $signature_map Signature-to-key map on `$merged`.
     * @param array $prev_entry    Entry from `gecx_merged[$agent_key]`.
     * @return string|null
     */
    private static function find_synced_target_key( array $merged, array $signature_map, array $prev_entry ): ?string {
        $prev_line   = isset( $prev_entry['line'] ) && is_array( $prev_entry['line'] ) ? $prev_entry['line'] : [];
        $prev_target = isset( $prev_entry['target_key'] ) && is_string( $prev_entry['target_key'] ) ? $prev_entry['target_key'] : '';
        if ( '' !== $prev_target && isset( $merged[ $prev_target ] ) ) {
            if ( empty( $prev_line ) || self::is_composite_cart_line( $merged[ $prev_target ] ) || self::lines_match_product_and_variation( $merged[ $prev_target ], $prev_line ) ) {
                return $prev_target;
            }
        }
        $prev_source = isset( $prev_entry['source_key'] ) && is_string( $prev_entry['source_key'] ) ? $prev_entry['source_key'] : '';
        if ( '' !== $prev_source && isset( $merged[ $prev_source ] ) && ! empty( $prev_line ) && self::lines_match_product_and_variation( $merged[ $prev_source ], $prev_line ) ) {
            return $prev_source;
        }
        $prev_sig = isset( $prev_entry['sig'] ) && is_string( $prev_entry['sig'] ) ? $prev_entry['sig'] : '';
        if ( '' !== $prev_sig && isset( $signature_map[ $prev_sig ] ) && isset( $merged[ $signature_map[ $prev_sig ] ] ) ) {
            return $signature_map[ $prev_sig ];
        }
        return null;
    }

    /**
     * Extracts compact product/variation identity fields for ledger storage.
     *
     * @param array $line Cart line array.
     * @return array
     */
    private static function minimal_line_identity( array $line ): array {
        return [
            'product_id'   => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
            'variation_id' => isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0,
            'variation'    => isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : [],
        ];
    }

    /**
     * Casts whole-number float quantities to `int` while preserving fractional
     * quantities for stores using decimal stock plugins.
     *
     * @param float $qty Quantity.
     * @return int|float
     */
    private static function normalize_qty( float $qty ) {
        return ( floor( $qty ) === $qty ) ? (int) $qty : $qty;
    }

    /**
     * Strips cached line subtotal/tax fields so WooCommerce recalculates them.
     *
     * @param array $line Cart line (by reference).
     */
    private static function strip_cached_line_totals( array &$line ): void {
        unset(
            $line['line_total'],
            $line['line_subtotal'],
            $line['line_tax'],
            $line['line_subtotal_tax'],
            $line['line_tax_data']
        );
    }

    /**
     * Combines an incoming quantity into an existing cart line in `$merged`,
     * enforcing `sold_individually` and stock caps and clearing stale totals.
     *
     * @param array    $merged            Target cart lines array (by reference).
     * @param string   $target_key        Existing cart item key in `$merged`.
     * @param float    $incoming_qty      Quantity to add.
     * @param bool     $sold_individually Whether the product is sold individually.
     * @param int|null $stock_cap         Maximum stock quantity, or null if uncapped.
     */
    private static function combine_into_existing_cart_line( array &$merged, string $target_key, float $incoming_qty, bool $sold_individually, ?int $stock_cap ): void {
        $existing_qty = isset( $merged[ $target_key ]['quantity'] ) ? (float) $merged[ $target_key ]['quantity'] : 0.0;
        $combined_qty = $existing_qty + $incoming_qty;
        if ( $sold_individually ) {
            $combined_qty = max( $existing_qty, 1.0 );
        }
        if ( null !== $stock_cap ) {
            $combined_qty = min( $combined_qty, max( $existing_qty, (float) $stock_cap ) );
        }
        $merged[ $target_key ]['quantity'] = self::normalize_qty( $combined_qty );
        self::strip_cached_line_totals( $merged[ $target_key ] );
    }

    /**
     * Whether two non-composite cart lines refer to the same product,
     * variation ID, and sorted variation attribute map.
     *
     * @param mixed $existing Existing cart line entry.
     * @param array $incoming Incoming cart line entry.
     * @return bool
     */
    private static function lines_match_product_and_variation( $existing, array $incoming ): bool {
        if ( ! is_array( $existing ) || self::is_composite_cart_line( $existing ) ) {
            return false;
        }
        $existing_pid = isset( $existing['product_id'] ) ? (int) $existing['product_id'] : 0;
        $incoming_pid = isset( $incoming['product_id'] ) ? (int) $incoming['product_id'] : 0;
        $existing_vid = isset( $existing['variation_id'] ) ? (int) $existing['variation_id'] : 0;
        $incoming_vid = isset( $incoming['variation_id'] ) ? (int) $incoming['variation_id'] : 0;
        if ( $existing_pid !== $incoming_pid || $existing_vid !== $incoming_vid ) {
            return false;
        }
        $existing_var = isset( $existing['variation'] ) && is_array( $existing['variation'] ) ? $existing['variation'] : [];
        $incoming_var = isset( $incoming['variation'] ) && is_array( $incoming['variation'] ) ? $incoming['variation'] : [];
        ksort( $existing_var );
        ksort( $incoming_var );
        return $existing_var === $incoming_var;
    }

    /**
     * Extracts custom item data fields from a cart line, stripping standard
     * WooCommerce cart-line bookkeeping fields.
     *
     * @param array $line Cart line array.
     * @return array
     */
    private static function extract_custom_cart_item_data( array $line ): array {
        $custom = $line;
        unset(
            $custom['key'],
            $custom['product_id'],
            $custom['variation_id'],
            $custom['variation'],
            $custom['quantity'],
            $custom['data'],
            $custom['data_hash'],
            $custom['line_tax_data'],
            $custom['line_subtotal'],
            $custom['line_subtotal_tax'],
            $custom['line_total'],
            $custom['line_tax']
        );
        ksort( $custom );
        return $custom;
    }

    /**
     * Computes a canonical WooCommerce cart item key matching
     * `WC_Cart::generate_cart_id()`, working even on REST routes where
     * `WC()->cart` has not been instantiated.
     *
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @param array $variation      Variation attributes.
     * @param array $cart_item_data Custom cart item data.
     * @return string Generated key.
     */
    private static function generate_wc_cart_id( int $product_id, int $variation_id, array $variation, array $cart_item_data ): string {
        if ( function_exists( 'WC' ) && null !== WC()->cart && method_exists( WC()->cart, 'generate_cart_id' ) ) {
            $cart_id = WC()->cart->generate_cart_id( $product_id, $variation_id, $variation, $cart_item_data );
            return is_string( $cart_id ) ? $cart_id : '';
        }
        $id_parts = [ $product_id ];
        if ( $variation_id && 0 !== $variation_id ) {
            $id_parts[] = $variation_id;
        }
        if ( ! empty( $variation ) ) {
            $variation_key = '';
            foreach ( $variation as $key => $value ) {
                $variation_key .= trim( (string) $key ) . trim( (string) $value );
            }
            $id_parts[] = $variation_key;
        }
        if ( ! empty( $cart_item_data ) ) {
            $cart_item_data_key = '';
            foreach ( $cart_item_data as $key => $value ) {
                if ( is_array( $value ) || is_object( $value ) ) {
                    $value = http_build_query( (array) $value );
                }
                $cart_item_data_key .= trim( (string) $key ) . trim( (string) $value );
            }
            $id_parts[] = $cart_item_data_key;
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce filter matching WC_Cart::generate_cart_id().
        return (string) apply_filters( 'woocommerce_cart_id', md5( implode( '_', $id_parts ) ), $product_id, $variation_id, $variation, $cart_item_data );
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

        $custom = self::extract_custom_cart_item_data( $line );

        return md5( (string) wp_json_encode( [ $product_id, $variation_id, $variation, $custom ] ) );
    }

    /**
     * Runs `$callback` under a per-session advisory lock so concurrent merges
     * targeting the same shopper cart serialize cleanly.
     *
     * @param string   $lock_key Target session key.
     * @param callable $callback Critical section returning a WP_REST_Response or WP_Error.
     * @return \WP_REST_Response|\WP_Error
     */
    private static function with_merge_lock( string $lock_key, callable $callback ) {
        global $wpdb;
        if ( '' === $lock_key || ! isset( $wpdb ) ) {
            return $callback();
        }
        $lock_name = 'gecx_cart_' . md5( $wpdb->prefix . ':' . $lock_key );
        $prev_supp = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock serializes concurrent merges targeting the same session row.
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock_name ) );
        $wpdb->suppress_errors( $prev_supp );
        if ( '0' === (string) $acquired ) {
            return self::respond( 'deferred' );
        }
        try {
            return $callback();
        } finally {
            if ( 1 === (int) $acquired ) {
                $prev_supp = $wpdb->suppress_errors( true );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases advisory lock acquired above.
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                $wpdb->suppress_errors( $prev_supp );
            }
        }
    }

    /**
     * Writes updated cart lines and `gecx_merged` ledger into a
     * `woocommerce_sessions` SQL row and evicts its object-cache entry.
     *
     * @param string     $session_key     Session key.
     * @param array      $cart_lines      Merged cart lines.
     * @param string     $gecx_session_id Optional GECX session ID to preserve.
     * @param array|null $ledger          Optional `gecx_merged` ledger to persist.
     * @return bool True when the database write succeeded.
     */
    private static function write_session_row_cart( string $session_key, array $cart_lines, string $gecx_session_id = '', ?array $ledger = null ): bool {
        global $wpdb;
        if ( '' === $session_key || ! isset( $wpdb ) ) {
            return false;
        }
        $session_data         = self::read_session_row( $session_key );
        $session_data['cart'] = maybe_serialize( $cart_lines );
        if ( null !== $ledger ) {
            $session_data['gecx_merged'] = maybe_serialize( $ledger );
        }
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
        if ( false === $result ) {
            return false;
        }
        self::evict_session_cache( $session_key );
        return true;
    }

    /**
     * Updates or removes the `gecx_merged` ledger on an existing
     * `woocommerce_sessions` SQL row without creating a phantom row when none
     * exists.
     *
     * @param string $session_key Session key.
     * @param array  $ledger      Updated ledger, or `[]` to remove `gecx_merged`.
     */
    private static function set_session_row_ledger( string $session_key, array $ledger ): void {
        global $wpdb;
        if ( '' === $session_key || ! isset( $wpdb ) ) {
            return;
        }
        $session_data = self::read_session_row( $session_key );
        if ( [] === $session_data ) {
            return;
        }
        if ( empty( $ledger ) ) {
            if ( ! array_key_exists( 'gecx_merged', $session_data ) ) {
                return;
            }
            unset( $session_data['gecx_merged'] );
        } else {
            $session_data['gecx_merged'] = maybe_serialize( $ledger );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update keeps WooCommerce session storage consistent.
        $wpdb->update(
            "{$wpdb->prefix}woocommerce_sessions",
            [ 'session_value' => maybe_serialize( $session_data ) ],
            [ 'session_key' => $session_key ],
            [ '%s' ],
            [ '%s' ]
        );
        self::evict_session_cache( $session_key );
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
