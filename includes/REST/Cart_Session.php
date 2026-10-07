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
 * Gemini Enterprise for CX cart session sync
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\REST;

use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Keeps the shopper's browser in step with carts the agent changes through the
 * Store API: Cart-Token headers on batch responses, the WooCommerce session
 * cookie for a Cart-Token cart, and cart cookies and caches after a change.
 */
class Cart_Session {

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
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
        if ( ! empty( self::find_cart_token( $response->get_headers() ) ) ) {
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
            $sub_token = self::find_cart_token( $sub_response['headers'] );
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
    public static function find_cart_token( $headers ): string {
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
     * Reads the raw `Cart-Token` request header from `$_SERVER`.
     *
     * WP_REST_Request only carries the headers WordPress parsed for the route
     * it dispatched, so the Store API checkout hooks and the post-dispatch cart
     * sync still have to fall back to `$_SERVER`. The value is a compact JWS:
     * three base64url segments separated by dots. `sanitize_text_field()` is
     * lossless over that alphabet, and the charset filter drops anything a JWT
     * cannot contain, so a malformed header can never reach
     * `GECX_Auth::get_cart_token_customer_id()` intact. Verification of the
     * token itself is still done there by HMAC.
     *
     * @return string The token, or '' when the request carries none.
     */
    public static function read_cart_token_from_server(): string {
        if ( empty( $_SERVER['HTTP_CART_TOKEN'] ) ) {
            return '';
        }
        $cart_token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) );
        return (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $cart_token );
    }

    /**
     * Checks whether the current request already carries a WooCommerce session cookie.
     *
     * @return bool True if a non-empty wp_woocommerce_session_* cookie is present.
     */
    public static function has_woocommerce_session_cookie(): bool {
        foreach ( $_COOKIE as $cookie_key => $cookie_val ) {
            if ( strpos( (string) $cookie_key, 'wp_woocommerce_session_' ) === 0 && '' !== (string) $cookie_val ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Customer ID named by the request's WooCommerce session cookie, once
     * WooCommerce has verified the cookie's HMAC.
     *
     * has_woocommerce_session_cookie() only checks that a cookie is present,
     * which any client can fake. WC_Session_Handler::get_session_cookie()
     * checks the hash WooCommerce signed the cookie with and returns false for
     * anything else.
     *
     * @return string Customer ID, or '' when there is no valid session cookie.
     */
    public static function get_verified_session_cookie_customer_id(): string {
        if ( ! self::has_woocommerce_session_cookie() || ! function_exists( 'WC' ) ) {
            return '';
        }
        $session = WC()->session;
        if ( ! is_object( $session ) || ! method_exists( $session, 'get_session_cookie' ) ) {
            return '';
        }
        $cookie = $session->get_session_cookie();
        if ( ! is_array( $cookie ) || empty( $cookie[0] ) ) {
            return '';
        }
        return (string) $cookie[0];
    }

    /**
     * Sets the WooCommerce session cookie for a guest customer ID.
     *
     * In WooCommerce Store API requests, WC()->session is an instance of
     * StoreApi\SessionHandler which does not implement set_customer_session_cookie().
     * This helper constructs and sets the standard WooCommerce session cookie so
     * that subsequent browser requests (such as /cart and /checkout) have access
     * to the session created via Cart-Token.
     *
     * @param string $customer_id Guest customer ID (e.g. t_...).
     */
    private static function set_guest_session_cookie( string $customer_id ): void {
        if ( empty( $customer_id ) ) {
            return;
        }

        if (
            isset( WC()->session ) &&
            method_exists( WC()->session, 'set_customer_session_cookie' ) &&
            method_exists( WC()->session, 'get_customer_id' ) &&
            (string) WC()->session->get_customer_id() === $customer_id
        ) {
            WC()->session->set_customer_session_cookie( true );
        }

        $cookie_hash_val = defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_site_option( 'siteurl' ) );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
        $cookie_name = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . $cookie_hash_val );

        $default_expiring_seconds   = DAY_IN_SECONDS;
        $default_expiration_seconds = 2 * DAY_IN_SECONDS;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
        $expiring_seconds   = (int) apply_filters( 'wc_session_expiring', $default_expiring_seconds );
        $expiring_seconds   = $expiring_seconds > 0 ? $expiring_seconds : $default_expiring_seconds;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
        $expiration_seconds = (int) apply_filters( 'wc_session_expiration', $default_expiration_seconds );
        $expiration_seconds = $expiration_seconds > 0 ? $expiration_seconds : $default_expiration_seconds;

        $session_expiring   = time() + $expiring_seconds;
        $session_expiration = time() + $expiration_seconds;

        $to_hash     = $customer_id . '|' . $session_expiration;
        $cookie_hash = function_exists( 'wp_hash' )
            ? hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) )
            : md5( $to_hash );
        $cookie_value = $customer_id . '||' . $session_expiration . '||' . $session_expiring . '||' . $cookie_hash;

        $use_secure = function_exists( 'wc_site_is_https' ) && function_exists( 'is_ssl' ) && wc_site_is_https() && is_ssl();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
        $use_secure = (bool) apply_filters( 'wc_session_use_secure_cookie', $use_secure );

        if ( function_exists( 'wc_setcookie' ) ) {
            wc_setcookie( $cookie_name, $cookie_value, $session_expiration, $use_secure, true );
        } elseif ( ! headers_sent() ) {
            $cookie_path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
            $cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
            setcookie( $cookie_name, $cookie_value, $session_expiration, $cookie_path, $cookie_domain, $use_secure, true );
        }

        $_COOKIE[ $cookie_name ] = $cookie_value;
    }

    /**
     * Brings the browser's woocommerce_items_in_cart and woocommerce_cart_hash
     * cookies in line with the cart WooCommerce just loaded.
     *
     * The agent changes the cart from Google's servers, so WooCommerce sets
     * those cookies on the agent's response, not the shopper's. The browser
     * keeps the old ones, which has two effects. Full-page caches keep serving
     * cached pages to a shopper they think has an empty cart, since
     * woocommerce_items_in_cart is what makes them bypass the cache. And
     * wc-cart-fragments reuses its sessionStorage copy of the header cart,
     * because the cart hash cookie it compares against has not changed.
     *
     * storefront.js reads the cart from the Store API after every agent update,
     * and this sets the cookies on that read. WooCommerce's own
     * WC_Cart_Session::maybe_set_cart_cookies() runs on 'wp' and 'shutdown',
     * neither of which can set a cookie on a REST response, and the method
     * that does the writing is private. This mirrors it, including the
     * woocommerce_set_cart_cookies action that caching plugins listen for.
     * wc_setcookie() does nothing once headers are sent. The callers ensure
     * the loaded cart is the browser's own.
     */
    private static function maybe_set_browser_cart_cookies(): void {
        if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! function_exists( 'wc_setcookie' ) ) {
            return;
        }
        $cart     = WC()->cart;
        $is_empty = method_exists( $cart, 'is_empty' ) ? (bool) $cart->is_empty() : true;

        if ( ! $is_empty ) {
            $cookies = [
                'woocommerce_items_in_cart' => '1',
                'woocommerce_cart_hash'     => method_exists( $cart, 'get_cart_hash' ) ? (string) $cart->get_cart_hash() : '',
            ];
            foreach ( $cookies as $name => $value ) {
                if ( ! isset( $_COOKIE[ $name ] ) || $_COOKIE[ $name ] !== $value ) {
                    wc_setcookie( $name, $value );
                    $_COOKIE[ $name ] = $value;
                }
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce action.
            do_action( 'woocommerce_set_cart_cookies', true );
            return;
        }

        if ( isset( $_COOKIE['woocommerce_items_in_cart'] ) ) {
            foreach ( [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash' ] as $name ) {
                if ( isset( $_COOKIE[ $name ] ) ) {
                    wc_setcookie( $name, '0', time() - HOUR_IN_SECONDS );
                    unset( $_COOKIE[ $name ] );
                }
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce action.
            do_action( 'woocommerce_set_cart_cookies', false );
        }
    }

    /**
     * Checks whether a REST request represents a cart mutation on the
     * WooCommerce Store API.
     *
     * @param \WP_REST_Request $request Dispatched REST request.
     * @return bool True when the request targets a mutating Store API cart route or batch cart sub-request.
     */
    private function is_mutating_store_api_cart_request( \WP_REST_Request $request ): bool {
        $route = (string) $request->get_route();
        if ( 1 !== preg_match( '#^/wc/store/v\d+/(cart(/.*)?|batch)$#', $route ) ) {
            return false;
        }

        $method = method_exists( $request, 'get_method' )
            ? strtoupper( (string) $request->get_method() )
            : ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET' );

        if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
            return false;
        }

        if ( 1 === preg_match( '#^/wc/store/v\d+/batch$#', $route ) ) {
            $sub_requests = $request->get_param( 'requests' );
            if ( null === $sub_requests && method_exists( $request, 'get_json_params' ) ) {
                $json_params  = $request->get_json_params();
                $sub_requests = is_array( $json_params ) && isset( $json_params['requests'] ) ? $json_params['requests'] : null;
            }
            if ( ! is_array( $sub_requests ) ) {
                return false;
            }

            $has_mutating_cart_sub_request = false;
            foreach ( $sub_requests as $sub_request ) {
                if ( ! is_array( $sub_request ) ) {
                    continue;
                }
                $sub_path   = isset( $sub_request['path'] ) ? (string) wp_parse_url( (string) $sub_request['path'], PHP_URL_PATH ) : '';
                $sub_method = isset( $sub_request['method'] ) ? strtoupper( (string) $sub_request['method'] ) : 'POST';
                if (
                    in_array( $sub_method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) &&
                    1 === preg_match( '#(^|/+)wc/store/v\d+/cart(/.*)?$#', $sub_path )
                ) {
                    $has_mutating_cart_sub_request = true;
                    break;
                }
            }
            return $has_mutating_cart_sub_request;
        }

        return true;
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
        if ( ! $request instanceof \WP_REST_Request || is_wp_error( $response ) ) {
            return $response;
        }

        if ( $response instanceof \WP_REST_Response || $response instanceof \WP_HTTP_Response ) {
            $status = (int) $response->get_status();
            if ( $status < 200 || $status >= 300 ) {
                return $response;
            }
        }

        $is_mutation = $this->is_mutating_store_api_cart_request( $request );
        $route       = (string) $request->get_route();
        $method      = method_exists( $request, 'get_method' )
            ? strtoupper( (string) $request->get_method() )
            : ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET' );

        $has_cart_token = ( Auth::is_cart_token_request() )
            || ! empty( $_SERVER['HTTP_CART_TOKEN'] )
            || '' !== (string) $request->get_header( 'Cart-Token' );

        $is_cart_read            = ( 'GET' === $method && 1 === preg_match( '#^/wc/store/v\d+/cart(/.*)?$#', $route ) );
        $is_cart_token_cart_read = ( $is_cart_read && $has_cart_token );

        if ( ! $is_mutation && ! $is_cart_token_cart_read ) {
            // A browser reading its own cart: nothing to sync, but the agent
            // may have changed this cart from its own request, which never
            // reaches the browser's cookies. See maybe_set_browser_cart_cookies().
            if ( $is_cart_read && self::has_woocommerce_session_cookie() ) {
                self::maybe_set_browser_cart_cookies();
            }
            return $response;
        }

        if ( isset( WC()->cart ) ) {
            $cart_for_session = method_exists( WC()->cart, 'get_cart_for_session' )
                ? (array) WC()->cart->get_cart_for_session()
                : [];
            $has_cart_items = ! empty( $cart_for_session );
            if ( ! $has_cart_items && method_exists( WC()->cart, 'is_empty' ) ) {
                $has_cart_items = ! WC()->cart->is_empty();
            }

            $has_cookie = self::has_woocommerce_session_cookie();

            // Never create or persist an empty guest session when the shopper has no
            // existing WooCommerce session cookie. Forcing a new wp_woocommerce_session_*
            // cookie on a guest with an empty cart changes WC_Session_Handler::has_session()
            // from false to true and invalidates any woocommerce-login-nonce or
            // woocommerce-register-nonce already rendered on the My Account page.
            if ( ! $has_cart_items && ! $has_cookie && 0 === get_current_user_id() ) {
                return $response;
            }

            // Sync to user persistent cart user meta
            if ( method_exists( WC()->cart, 'persistent_cart_update' ) ) {
                WC()->cart->persistent_cart_update();
            }

            // Persist through WC()->session API so custom session handlers (Redis/Memcached) stay in sync.
            $bound_session_id = Session_Attribution::get_gecx_session_id_safely();
            if ( isset( WC()->session ) ) {
                if ( method_exists( WC()->session, 'set' ) ) {
                    WC()->session->set( 'cart', $cart_for_session );
                    if ( ! empty( $bound_session_id ) ) {
                        WC()->session->set( 'gecx_session_id', $bound_session_id );
                    }
                }
                if ( method_exists( WC()->session, 'save_data' ) ) {
                    WC()->session->save_data();
                }
            }

            // Directly sync to the browser's active session row in the database when using WooCommerce's SQL session handler.
            global $wpdb;
            $session_key = '';
            if ( $has_cart_token ) {
                $raw_cart_token = (string) $request->get_header( 'Cart-Token' );
                if ( '' === $raw_cart_token && method_exists( $request, 'get_headers' ) ) {
                    $raw_cart_token = self::find_cart_token( $request->get_headers() );
                }
                if ( '' === $raw_cart_token ) {
                    $raw_cart_token = self::read_cart_token_from_server();
                }
                if ( '' !== $raw_cart_token ) {
                    $session_key = Auth::get_cart_token_customer_id( $raw_cart_token );
                }
            }

            if ( empty( $session_key ) && isset( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) ) {
                $session_key = (string) WC()->session->get_customer_id();
            }

            if ( empty( $session_key ) ) {
                $user_id = get_current_user_id();
                if ( $user_id > 0 ) {
                    $session_key = (string) $user_id;
                }
            }

            // Set the WooCommerce session cookie for un-cookied guests with cart items:
            // 1) when bridging a Cart-Token cart to the browser on GET /wc/store/v1/cart, or
            // 2) when a non-empty cart was mutated directly by the browser without a Cart-Token.
            //
            // Only guest session keys are eligible. A Cart-Token minted while the shopper was
            // logged in carries a numeric user_id, and that token stays valid after the shopper
            // logs out. Writing a numeric key as a session cookie for a logged-out browser makes
            // WC_Session_Handler::is_session_cookie_valid() fail, which calls destroy_session()
            // and deletes that user's row from the sessions table along with their saved cart.
            $is_guest_session_key = ( 0 === strpos( $session_key, 't_' ) );
            $bridged_to_browser   = false;
            if ( ! $has_cookie && $has_cart_items && 0 === get_current_user_id() && $is_guest_session_key ) {
                if ( $is_cart_token_cart_read || ( $is_mutation && ! $has_cart_token ) ) {
                    self::set_guest_session_cookie( $session_key );
                    $bridged_to_browser = true;
                }
            }

            // Only when the cart just loaded is the browser's own: the session
            // this request bridged to it, or the one its verified session
            // cookie names. A cookied browser presenting a Cart-Token for some
            // other session must not have that session's hash written into
            // its cookies.
            if ( $is_cart_token_cart_read && ( $bridged_to_browser || ( '' !== $session_key && self::get_verified_session_cookie_customer_id() === $session_key ) ) ) {
                self::maybe_set_browser_cart_cookies();
            }

            $uses_sql_session_handler = ! isset( WC()->session )
                || ! class_exists( 'WC_Session_Handler' )
                || WC()->session instanceof \WC_Session_Handler
                || ( class_exists( '\Automattic\WooCommerce\StoreApi\SessionHandler' ) && WC()->session instanceof \Automattic\WooCommerce\StoreApi\SessionHandler );

            if ( $uses_sql_session_handler && ! empty( $session_key ) && isset( $wpdb ) ) {
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

                $session_data['cart'] = $cart_for_session;
                if ( empty( $session_data['gecx_session_id'] ) && ! empty( $bound_session_id ) ) {
                    $session_data['gecx_session_id'] = (string) $bound_session_id;
                }

                $serialized_data = maybe_serialize( $session_data );
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core WooCommerce filter.
                $expiry = time() + (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query required to sync WooCommerce session data across requests.
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
}
