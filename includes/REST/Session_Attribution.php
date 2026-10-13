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
 * Gemini Enterprise for CX chat session to order attribution
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\REST;

use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Records which chat session a shopper is in (the /session route and the
 * gecx_session_id cookie) and copies it onto the orders they place.
 */
class Session_Attribution {

    /**
     * First-party cookie name used to persist the GECX session ID for uncookied
     * guests before WooCommerce allocates a `wp_woocommerce_session_*` cookie.
     */
    private const SESSION_COOKIE_NAME = 'gecx_session_id';

    /**
     * Default lifetime (48 hours) for the fallback `gecx_session_id` cookie,
     * matching WooCommerce's default guest session expiration.
     */
    private const SESSION_COOKIE_TTL = 172800;

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_session_rest_route' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_session_to_order_metadata' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'attach_session_to_order_metadata_store_api' ], 10, 2 );
        add_action( 'rest_api_init', [ $this, 'register_session_rest_field' ] );
        add_filter( 'woocommerce_rest_prepare_shop_order_object', [ $this, 'add_session_id_to_order_rest_response' ], 10, 2 );
    }

    /**
     * Persists the GECX session ID in a first-party HttpOnly cookie without
     * touching `wp_woocommerce_session_*` (preserving `wp_rest` and login
     * nonces for uncookied guests).
     *
     * @param string $session_id Validated GECX session ID.
     */
    private static function set_session_cookie( string $session_id ): void {
        $_COOKIE[ self::SESSION_COOKIE_NAME ] = $session_id;
        if ( headers_sent() ) {
            return;
        }
        $cookie_path   = defined( 'COOKIEPATH' ) && '' !== (string) COOKIEPATH ? (string) COOKIEPATH : '/';
        $cookie_domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';
        $is_secure     = is_ssl();
        setcookie(
            self::SESSION_COOKIE_NAME,
            $session_id,
            [
                'expires'  => time() + self::SESSION_COOKIE_TTL,
                'path'     => $cookie_path,
                'domain'   => $cookie_domain,
                'secure'   => $is_secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Register Custom Session API Route
     */
    public function register_session_rest_route(): void {
        register_rest_route( 'gecx/v1', '/session', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_session_handler' ],
            'permission_callback' => [ $this, 'check_session_permissions' ],
            'args'                => [
                'session_id' => [
                    'description'       => __( 'GECX commerce session resource name or session identifier.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => true,
                    'validate_callback' => static function( $value ): bool {
                        return is_string( $value ) && self::is_valid_session_id( trim( $value ) );
                    },
                ],
                'cart_token' => [
                    'description'       => __( 'Optional WooCommerce Store API Cart-Token to associate the session ID with.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => false,
                    'validate_callback' => static function( $value ): bool {
                        return is_string( $value );
                    },
                ],
            ],
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
            return 1 === preg_match( '#^projects/[0-9]+/locations/[a-zA-Z0-9_\-]+/(?:commerceSessions|omnichannelSessions)/[a-zA-Z0-9_\-:]+$#', $session_id );
        }
        return 1 === preg_match( '#^(projects/[0-9]+/locations/[a-zA-Z0-9_\-]+/(?:commerceSessions|omnichannelSessions)/[a-zA-Z0-9_\-:]+|[a-zA-Z0-9_\-:]+)$#', $session_id );
    }

    /**
     * Handle the POST request to save the session ID in the WooCommerce customer session.
     */
    public function save_session_handler( \WP_REST_Request $request ) {
        // Validated raw against is_valid_session_id(), which is a strict
        // allowlist. Sanitizing first can only remove the characters that
        // allowlist rejects, so it converts a 400 into a silently mangled
        // accept: "sess<script>alert(1)</script>" would be stored as "sess".
        $session_id = trim( (string) $request->get_param( 'session_id' ) );
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

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return new \WP_Error( 'session_not_initialized', __( 'WooCommerce session not active.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        WC()->session->set( 'gecx_session_id', $session_id );

        // Persist the session ID in a first-party HttpOnly cookie so an
        // uncookied guest who chats before adding anything to their cart still
        // has their GECX session bound when they later check out, without
        // creating a `wp_woocommerce_session_*` cookie that would invalidate
        // `wp_rest` or `woocommerce-login-nonce`.
        self::set_session_cookie( $session_id );

        // Resolve the WooCommerce session key. A cryptographically verified
        // Cart-Token is accepted when it authenticated the Store API request,
        // when it names a numeric user ID matching the logged-in user, or when
        // the caller is an unauthenticated guest and the token names a guest
        // session key minted by the agent via the Store API.
        $current_user_key = is_user_logged_in()
            ? (string) get_current_user_id()
            : '';
        $wc_customer_key  = method_exists( WC()->session, 'get_customer_id' )
            ? (string) WC()->session->get_customer_id()
            : '';

        $session_key    = '';
        $raw_cart_token = (string) $request->get_header( 'Cart-Token' );
        if ( '' === $raw_cart_token && method_exists( $request, 'get_headers' ) ) {
            $raw_cart_token = Cart_Session::find_cart_token( $request->get_headers() );
        }
        if ( '' === $raw_cart_token ) {
            $raw_cart_token = Cart_Session::read_cart_token_from_server();
        }
        if ( '' === $raw_cart_token ) {
            foreach ( [ 'cart_token', 'cartId', 'cart_id' ] as $param_name ) {
                $param_val = $request->get_param( $param_name );
                if ( is_string( $param_val ) && '' !== trim( $param_val ) ) {
                    $raw_cart_token = sanitize_text_field( $param_val );
                    break;
                }
            }
        }
        $cart_token_verified = false;
        if ( '' !== $raw_cart_token ) {
            $candidate_key = Auth::get_cart_token_customer_id( $raw_cart_token );
            if ( '' !== $candidate_key ) {
                $is_numeric_user_id = ctype_digit( $candidate_key );
                $matches_user       = $is_numeric_user_id && '' !== $current_user_key && $candidate_key === $current_user_key;
                $valid_guest_token  = ! $is_numeric_user_id && '' === $current_user_key;
                if ( $matches_user || $valid_guest_token ) {
                    $session_key         = $candidate_key;
                    $cart_token_verified = true;
                }
            }
        }

        if ( empty( $session_key ) && '' !== $current_user_key ) {
            $session_key = $current_user_key;
        }

        if ( empty( $session_key ) && '' !== $wc_customer_key ) {
            $session_key = $wc_customer_key;
        }

        $has_active_session = Cart_Session::has_woocommerce_session_cookie()
            || is_user_logged_in()
            || ( method_exists( WC()->session, 'has_session' ) && WC()->session->has_session() )
            || ( isset( WC()->cart ) && method_exists( WC()->cart, 'is_empty' ) && ! WC()->cart->is_empty() );

        if ( $has_active_session ) {
            if ( method_exists( WC()->session, 'save_data' ) ) {
                WC()->session->save_data();
            }
            if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
                WC()->session->set_customer_session_cookie( true );
            }
        }

        // Persist gecx_session_id directly to the database session table so
        // order attribution survives across requests, but only for a session
        // that already exists: one named by the browser's WooCommerce session
        // cookie (HMAC-verified, and naming this very row), by an HMAC-verified
        // Cart-Token, or by the logged-in user. An uncookied guest has none of
        // those, and WC_Session_Handler hands it a freshly generated t_ key on
        // every request. Writing that key would let anyone holding the shared
        // logged-out wp_rest nonce insert an unbounded number of rows. Such a
        // guest's attribution is carried by the gecx_session_id cookie set
        // above instead.
        $cookie_customer_key     = Cart_Session::get_verified_session_cookie_customer_id();
        $may_persist_session_row = ( '' !== $cookie_customer_key && $cookie_customer_key === $session_key )
            || $cart_token_verified
            || '' !== $current_user_key;

        global $wpdb;
        $uses_sql_session_handler = ! isset( WC()->session )
            || ! class_exists( 'WC_Session_Handler' )
            || WC()->session instanceof \WC_Session_Handler
            || ( class_exists( '\Automattic\WooCommerce\StoreApi\SessionHandler' ) && WC()->session instanceof \Automattic\WooCommerce\StoreApi\SessionHandler );

        if ( $may_persist_session_row && ! empty( $session_key ) && $uses_sql_session_handler && isset( $wpdb ) ) {
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

            $session_data['gecx_session_id'] = $session_id;

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
                $cache_group = defined( 'WC_SESSION_CACHE_GROUP' )
                    ? WC_SESSION_CACHE_GROUP
                    : ( defined( 'WC_Cache_Helper::WC_SESSION_CACHE_GROUP' ) ? \WC_Cache_Helper::WC_SESSION_CACHE_GROUP : 'wc_sessions' );

                $cache_prefix = ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_cache_prefix' ) )
                    ? \WC_Cache_Helper::get_cache_prefix( $cache_group )
                    : 'wc_session_';

                wp_cache_delete( $cache_prefix . $session_key, $cache_group );
            }
        }

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Check permissions for session API route.
     * Requires a valid WP REST nonce to prevent CSRF.
     */
    public static function check_session_permissions( \WP_REST_Request $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * Helper to safely retrieve session ID from WooCommerce, falling back to
     * the first-party `gecx_session_id` cookie set when an uncookied guest
     * started a chat before adding items to the cart.
     */
    public static function get_gecx_session_id_safely() {
        $session_id = '';
        if ( function_exists( 'WC' ) ) {
            if ( is_null( WC()->session ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
                include_once WC_ABSPATH . 'includes/class-wc-session-handler.php';
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
            $session_id = '';
            if ( isset( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
                $session_id = (string) ( WC()->session->get( 'gecx_session_id' ) ?? '' );
            }
            if ( ! empty( $session_id ) ) {
                return $session_id;
            }

            // Fallback to database lookup if in-memory session does not have it yet.
            global $wpdb;
            $session_key = '';
            if ( isset( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) ) {
                $session_key = (string) WC()->session->get_customer_id();
            }
            if ( empty( $session_key ) ) {
                $user_id = get_current_user_id();
                if ( $user_id > 0 ) {
                    $session_key = (string) $user_id;
                }
            }
            if ( ! empty( $session_key ) && isset( $wpdb ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback lookup when session cache missed.
                $raw = $wpdb->get_var( $wpdb->prepare(
                    "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
                    $session_key
                ) );
                if ( ! empty( $raw ) ) {
                    $data = maybe_unserialize( $raw );
                    if ( is_array( $data ) && ! empty( $data['gecx_session_id'] ) ) {
                        return (string) $data['gecx_session_id'];
                    }
                }
            }
        }

        if ( empty( $session_id ) && isset( $_COOKIE[ self::SESSION_COOKIE_NAME ] ) ) {
            $cookie_session_id = sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE_NAME ] ) );
            if ( self::is_valid_session_id( $cookie_session_id ) ) {
                $session_id = $cookie_session_id;
                if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'set' ) ) {
                    WC()->session->set( 'gecx_session_id', $session_id );
                }
            }
        }

        return $session_id;
    }

    /**
     * Bind Session ID to Created Order
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce action callback signature.
    public function attach_session_to_order_metadata( $order, $data ): void {
        $session_id = self::get_gecx_session_id_safely();
        if ( ! empty( $session_id ) ) {
            $order->update_meta_data( '_gecx_session_id', $session_id );
        }
    }

    /**
     * Bind Session ID to Created Order (WooCommerce Blocks Checkout)
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce Store API action callback signature.
    public function attach_session_to_order_metadata_store_api( \WC_Order $order, \WP_REST_Request $request ): void {
        $session_id = self::get_gecx_session_id_safely();
        if ( empty( $session_id ) ) {
            $cart_token = (string) $request->get_header( 'Cart-Token' );
            if ( '' === $cart_token && method_exists( $request, 'get_headers' ) ) {
                $cart_token = Cart_Session::find_cart_token( $request->get_headers() );
            }
            if ( '' === $cart_token ) {
                $cart_token = Cart_Session::read_cart_token_from_server();
            }
            if ( '' !== $cart_token ) {
                $customer_id = Auth::get_cart_token_customer_id( $cart_token );
                if ( '' !== $customer_id ) {
                    global $wpdb;
                    if ( isset( $wpdb ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback lookup for Store API order creation.
                        $raw = $wpdb->get_var( $wpdb->prepare(
                            "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
                            $customer_id
                        ) );
                        if ( ! empty( $raw ) ) {
                            $data = maybe_unserialize( $raw );
                            if ( is_array( $data ) && ! empty( $data['gecx_session_id'] ) ) {
                                $session_id = (string) $data['gecx_session_id'];
                            }
                        }
                    }
                }
            }
        }
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
     * Adds `_gecx_session_id` to the HPOS WooCommerce REST API order response.
     *
     * @param mixed $response WP_REST_Response object.
     * @param mixed $order    WC_Order object.
     * @return mixed
     */
    public function add_session_id_to_order_rest_response( $response, $order ) {
        if ( is_object( $response ) && isset( $response->data ) && is_array( $response->data ) && is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
            $response->data['_gecx_session_id'] = (string) $order->get_meta( '_gecx_session_id', true );
        }
        return $response;
    }
}
