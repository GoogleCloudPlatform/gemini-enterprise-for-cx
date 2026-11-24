<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests for binding the shopper's browser to the agent's cart.
 *
 * @package GECX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\REST\Cart_Binding;
use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use stdClass;
use WC_Session;
use WC_Session_Handler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class CartBindingTest extends RestTestCase {

    private const AGENT_CART_LINES = [
        'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
    ];

    private const DEFERRED    = [ 'binding' => 'deferred' ];
    private const EXISTING    = [ 'binding' => 'existing' ];
    private const MERGED      = [ 'binding' => 'merged' ];
    private const UNSUPPORTED = [ 'binding' => 'unsupported' ];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		$blog_id = (int) get_current_blog_id();
		delete_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id );
		delete_user_meta( 42, '_gecx_merged_' . $blog_id );
		delete_user_meta( 42, '_woocommerce_load_saved_cart_after_login' );
		// Use a table-backed WC_Session_Handler subclass (one that leaves get_session
		// and save_data declared on WC_Session_Handler) so Cart_Binding::is_table_backed()
		// recognises it while still recording cookie and timestamp calls.
		WC()->session = new class() extends WC_Session_Handler {
			public int $cookie_set_calls = 0;
			public int $session_expiration_calls = 0;
			public array $session_timestamp_updates = [];

			public function __construct() {
				parent::__construct();
				$this->_customer_id = 't_guest_session_123';
			}

			public function set_customer_id( string $id ): void {
				$this->_customer_id = $id;
			}

			public function set_customer_session_cookie( $set ): void {
				if ( $set ) {
					$this->cookie_set_calls++;
				}
			}

			public function set_session_expiration(): void {
				$this->session_expiration_calls++;
				$this->_session_expiration = time() + 2 * DAY_IN_SECONDS;
			}

			public function update_session_timestamp( $customer_id, $timestamp ): void {
				$this->session_timestamp_updates[] = [ (string) $customer_id, (int) $timestamp ];
			}

			public function get_session_data_for_test(): array {
				return is_array( $this->_data ) ? $this->_data : [];
			}

			public function get_session_expiration_for_test(): int {
				return (int) ( $this->_session_expiration ?? 0 );
			}

			public function get_session_cookie() {
				foreach ( $_COOKIE as $name => $value ) {
					if ( 0 !== strpos( (string) $name, 'wp_woocommerce_session_' ) ) {
						continue;
					}
					$parts = explode( '||', (string) $value );
					if ( 4 !== count( $parts ) || '' === $parts[0] || '' === $parts[3] || 'forged' === $parts[3] ) {
						return false;
					}
					return $parts;
				}
				return false;
			}
		};
	}

    /**
     * Stores a session row holding the given cart lines.
     *
     * @param string $session_key Session key.
     * @param array  $lines       Cart lines keyed by cart item key.
     */
    private function given_session_with_cart( string $session_key, array $lines ): void {
        $this->set_wc_session( $session_key, [ 'cart' => maybe_serialize( $lines ) ] );
    }

    /**
     * Gives the browser a session cookie that WooCommerce accepted, as it
     * does for a guest who added to their cart.
     *
     * @param string $session_key Guest session key.
     */
    private function given_browser_session( string $session_key, array $cart_lines = [] ): void {
        $_COOKIE['wp_woocommerce_session_test'] = $session_key . '||12345||12345||hash';
        WC()->session->set_customer_id( $session_key );
        if ( $cart_lines ) {
            // Held by the store's session handler, which need not be the
            // database table.
            WC()->session->set( 'cart', $cart_lines );
        }
    }

    /**
     * Calls the route and checks what every answer shares: a 200 that is
     * never cached, a body holding the binding alone, and a Cart-Token
     * header for 'agent' only.
     */
    private function bind_response( ?string $cart_token ): WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        if ( null !== $cart_token ) {
            $request->set_param( 'cart_token', $cart_token );
        }
        $response = ( new REST_API() )->cart_binding->bind_cart_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $headers = $response->get_headers();
        $this->assertStringContainsString( 'no-store', $headers['Cache-Control'] ?? '' );
        $this->assertSame( [ 'binding' ], array_keys( $response->get_data() ) );
        $this->assertSame( 'agent' === $response->get_data()['binding'], isset( $headers['Cart-Token'] ) );
        return $response;
    }

    private function bind( ?string $cart_token ): array {
        return $this->bind_response( $cart_token )->get_data();
    }

    public function test_route_requires_a_rest_nonce_and_a_same_origin_request(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        new REST_API();
        do_action( 'rest_api_init' );
        $routes = rest_get_server()->get_routes();
        $route  = $routes['/gecx/v1/cart-session'][0] ?? null;
        $this->assertNotNull( $route );
        $this->assertSame( [ 'POST' ], array_keys( $route['methods'] ) );
        $check = $route['permission_callback'];

		$same_origin = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
		$same_origin->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$same_origin->set_header( 'Origin', 'https://example.org' );
		$same_origin->set_header( 'Referer', 'https://example.org/shop/' );
		$same_origin->set_header( 'Sec-Fetch-Site', 'same-origin' );
		$this->assertTrue( $check( $same_origin ) );

        $no_nonce = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        $no_nonce->set_header( 'Origin', 'https://example.org' );
        $this->assertInstanceOf( WP_Error::class, $check( $no_nonce ) );

        // The logged-out nonce is shared by every guest, so another site can
        // have it; the request's origin gives it away.
        $cross_site = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        $cross_site->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $cross_site->set_header( 'Origin', 'https://attacker.example' );
        $cross_site->set_header( 'Sec-Fetch-Site', 'cross-site' );
        $this->assertInstanceOf( WP_Error::class, $check( $cross_site ) );
    }

    public function test_binds_an_uncookied_guest_to_an_agent_cart_with_items(): void {
        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $response = $this->bind_response( $agent_token );

        $this->assertSame( [ 'binding' => 'agent' ], $response->get_data() );
        $this->assertSame( 't_agent_cart', \Google\Gemini_Enterprise_For_CX\Auth::get_cart_token_customer_id( $response->get_headers()['Cart-Token'] ) );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
        $this->assertSame( maybe_serialize( self::AGENT_CART_LINES ), WC()->session->get_session_data_for_test()['cart'] );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->session->session_expiration_calls );
    }

    /**
     * The agent's session row keeps the expiry of the agent's last change
     * until something moves it, and cleanup_sessions() deletes rows by that
     * expiry, so binding moves it to the expiry of the cookie it sets.
     */
    public function test_extends_the_bound_session_row_to_the_cookie_expiry(): void {
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $expiration = WC()->session->get_session_expiration_for_test();
        $this->assertLessThan( $expiration, time() );
        $this->assertSame( [ [ 't_agent_cart', $expiration ] ], WC()->session->session_timestamp_updates );
    }

    public function test_defers_binding_while_the_agent_cart_is_empty(): void {
        $this->assertSame( self::DEFERRED, $this->bind( $this->generate_jwt( 't_empty_agent_cart' ) ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
    }

    public function test_defers_binding_without_an_agent_cart(): void {
        $this->assertSame( self::DEFERRED, $this->bind( null ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_merges_into_the_cart_of_a_cookied_guest_and_returns_no_token_for_it(): void {
        $this->given_browser_session( 't_browser_cart', [ 'b' => [ 'product_id' => 9, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $agent_token = $this->generate_jwt( 't_agent_cart' );

        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 't_browser_cart', WC()->session->get_customer_id() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );

        $merged = WC()->session->get( 'cart' );
        $this->assertIsArray( $merged );
        $this->assertCount( 2, $merged );
        $this->assertSame( 9, $merged['b']['product_id'] );
        $this->assertSame( 17510, $merged['a']['product_id'] );

        // Repeat calls with the same token are idempotent because the agent row was drained.
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertCount( 2, WC()->session->get( 'cart' ) );
        $this->assertSame( 1, WC()->session->get( 'cart' )['a']['quantity'] );
    }

    public function test_merge_combines_matching_lines_and_preserves_variations_and_item_data(): void {
        $this->given_browser_session(
            't_browser_cart',
            [
                'existing_simple' => [
                    'key'        => 'existing_simple',
                    'product_id' => 17510,
                    'variation_id' => 0,
                    'variation'  => [],
                    'quantity'   => 2,
                    'line_total' => 20.0,
                ],
                'existing_var_sm' => [
                    'key'          => 'existing_var_sm',
                    'product_id'   => 500,
                    'variation_id' => 501,
                    'variation'    => [ 'attribute_pa_size' => 'small' ],
                    'quantity'     => 1,
                ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'agent_simple' => [
                    'key'          => 'agent_simple',
                    'product_id'   => 17510,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 3,
                ],
                'agent_var_lg' => [
                    'key'          => 'agent_var_lg',
                    'product_id'   => 500,
                    'variation_id' => 502,
                    'variation'    => [ 'attribute_pa_size' => 'large' ],
                    'quantity'     => 1,
                    'addons'       => [ [ 'name' => 'Gift wrap', 'value' => 'Yes' ] ],
                ],
            ]
        );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );

        $merged = WC()->session->get( 'cart' );
        $this->assertCount( 3, $merged );
        $this->assertSame( 5, $merged['existing_simple']['quantity'] );
        $this->assertArrayNotHasKey( 'line_total', $merged['existing_simple'] );
        $this->assertSame( 1, $merged['existing_var_sm']['quantity'] );
        $this->assertSame( 502, $merged['agent_var_lg']['variation_id'] );
        $this->assertSame( [ 'attribute_pa_size' => 'large' ], $merged['agent_var_lg']['variation'] );
        $this->assertSame( [ [ 'name' => 'Gift wrap', 'value' => 'Yes' ] ], $merged['agent_var_lg']['addons'] );
    }

    /**
     * Any guest token, an attacker's own empty one included, must not turn
     * the route into a way to read the browser's cart token.
     */
    public function test_returns_no_token_for_the_browser_session_whatever_token_is_sent(): void {
        $this->given_browser_session( 't_victim', self::AGENT_CART_LINES );

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_attacker_empty' ) ) );
        $this->assertSame( self::EXISTING, $this->bind( null ) );
    }

    /**
     * A custom session handler may write a cookie this plugin cannot verify.
     * The cart behind it still counts, so its cookie is kept and agent lines
     * merge into that session rather than replacing it.
     */
    public function test_keeps_a_cart_whose_session_cookie_cannot_be_verified(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_custom_cookie||12345||12345||forged';
        WC()->session->set_customer_id( 't_custom_cookie' );
        WC()->session->set( 'cart', [ 'b' => [ 'product_id' => 9, 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $this->assertSame( 't_custom_cookie', WC()->session->get_customer_id() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertCount( 2, WC()->session->get( 'cart' ) );
    }

    public function test_rebinds_a_cookied_guest_with_an_empty_cart_to_the_agent_cart(): void {
        $this->given_browser_session( 't_browser_empty' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
    }

    public function test_reads_and_merges_the_browser_cart_through_the_store_session_handler(): void {
        // The browser's cart is held in WC()->session memory rather than in the
        // database table yet; both carts' items are preserved.
        $this->given_browser_session( 't_cached_browser_cart', [ 'b' => [ 'product_id' => 9, 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $this->assertSame( 't_cached_browser_cart', WC()->session->get_customer_id() );
        $this->assertCount( 2, WC()->session->get( 'cart' ) );
    }

    public function test_defers_for_a_cookied_guest_while_both_carts_are_empty(): void {
        $this->given_browser_session( 't_browser_empty' );

        $this->assertSame( self::DEFERRED, $this->bind( $this->generate_jwt( 't_empty_agent_cart' ) ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_leaves_a_browser_already_on_the_agent_cart_alone(): void {
        $this->given_browser_session( 't_shared_cart' );
        $this->given_session_with_cart( 't_shared_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_shared_cart' ) ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    /**
     * A shared device after a registered user logged out: WooCommerce rejects
     * their session cookie on a logged-out request and gives the request a new
     * guest session, but the cookie, still correctly signed, stays in $_COOKIE.
     */
    public function test_never_hands_a_logged_out_browser_a_registered_user_cart(): void {
        $_COOKIE['wp_woocommerce_session_test'] = '42||12345||12345||hash';

        $this->assertSame( self::DEFERRED, $this->bind( null ) );

        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $this->assertSame( 'agent', $this->bind( $this->generate_jwt( 't_agent_cart' ) )['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
    }

    public function test_ignores_a_guest_cookie_woocommerce_did_not_keep(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_expired_session||12345||12345||hash';
        $this->given_session_with_cart( 't_expired_session', self::AGENT_CART_LINES );

        $this->assertSame( self::DEFERRED, $this->bind( null ) );
    }

    public function test_merges_agent_cart_into_logged_in_shopper_cart_without_setting_cookies(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );
        $this->given_session_with_cart(
            '42',
            [
                'u1'     => [ 'product_id' => 77, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                'shared' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $agent_token = $this->generate_jwt( 't_agent_cart' );

        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );

        $user_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertIsArray( $user_cart );
        $this->assertCount( 2, $user_cart );
        $this->assertSame( 1, $user_cart['u1']['quantity'] );
        $this->assertSame( 2, $user_cart['shared']['quantity'] );

        $saved_meta = get_user_meta( 42, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
        $this->assertSame( 2, $saved_meta['cart']['shared']['quantity'] ?? null );
        $this->assertSame( self::AGENT_CART_LINES, maybe_unserialize( $this->get_wc_session( 't_agent_cart' )['cart'] ?? '' ) );

        // Repeat calls do not re-add lines because gecx_merged recorded the synced quantity.
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $user_cart_after = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertSame( 1, $user_cart_after['u1']['quantity'] );
        $this->assertSame( 2, $user_cart_after['shared']['quantity'] );
    }

    public function test_logged_in_shopper_with_cookie_matching_agent_key_never_doubles_quantities(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );
        $this->given_session_with_cart( '42', [ 'u1' => [ 'product_id' => 77, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $signer   = new WC_Session_Handler();
        $point_at = \Closure::bind(
            function ( string $key ): void {
                $this->_customer_id = $key;
            },
            $signer,
            WC_Session_Handler::class
        );
        $point_at( 't_agent_cart' );
        $signer->set_session_expiration();
        $signer->set_customer_session_cookie( true );

        $cookie_name             = 'wp_woocommerce_session_' . COOKIEHASH;
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];
        WC()->session            = null;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertNull( WC()->session );
        $this->assertSame( self::AGENT_CART_LINES, maybe_unserialize( $this->get_wc_session( 't_agent_cart' )['cart'] ?? '' ) );

        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );

        $user_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertIsArray( $user_cart );
        $this->assertCount( 2, $user_cart );
        $this->assertSame( 1, $user_cart['u1']['quantity'] );
        $this->assertSame( 1, $user_cart['a']['quantity'] );
    }

    public function test_logged_in_shopper_does_not_initialize_wc_session_when_null(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );
        WC()->session = null;

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $this->assertNull( WC()->session );
    }

    public function test_logged_in_shopper_does_not_initialize_wc_session_when_null_even_when_merging(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        // Sign a real Store API guest cookie (`t_store_api_guest`) before
        // resetting WC()->session to null, matching WooCommerce Blocks cart.js.
        $guest_key = 't_store_api_guest';
        $this->given_session_with_cart( '42', [ 'u1' => [ 'product_id' => 77, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( $guest_key, [ 'g1' => [ 'product_id' => 88, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $signer   = new WC_Session_Handler();
        $point_at = \Closure::bind(
            function ( string $key ): void {
                $this->_customer_id = $key;
            },
            $signer,
            WC_Session_Handler::class
        );
        $point_at( $guest_key );
        $signer->set_session_expiration();
        $signer->set_customer_session_cookie( true );

        $cookie_name             = 'wp_woocommerce_session_' . COOKIEHASH;
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];
        WC()->session            = null;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertNull( WC()->session );
        $this->assertSame( [], $this->cookies );

        // Repeat calls are no-ops returning 'existing' with WC()->session still null.
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertNull( WC()->session );

        // Only the agent's lines were merged into user 42; the separate guest
        // cookie row is left untouched for WooCommerce's login migration.
        $user_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertIsArray( $user_cart );
        $this->assertCount( 2, $user_cart );
        $this->assertSame( 1, $user_cart['u1']['quantity'] );
        $this->assertSame( 1, $user_cart['a']['quantity'] );
        $this->assertArrayNotHasKey( 'g1', $user_cart );

        $guest_cart = maybe_unserialize( $this->get_wc_session( $guest_key )['cart'] ?? '' );
        $this->assertIsArray( $guest_cart );
        $this->assertCount( 1, $guest_cart );
        $this->assertSame( 1, $guest_cart['g1']['quantity'] );
        $this->assertSame( self::AGENT_CART_LINES, maybe_unserialize( $this->get_wc_session( 't_agent_cart' )['cart'] ?? '' ) );
    }

    public function test_hydrates_external_redis_like_session_handler_that_overrides_save_data(): void {
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $redis_handler = new class() extends WC_Session {
            public int $cookie_set_calls = 0;
            public int $save_calls       = 0;

            public function __construct() {
                $this->_customer_id = 't_redis_browser_session';
                $this->_data        = [];
            }

            public function set_customer_session_cookie( bool $set ): void {
                if ( $set ) {
                    $this->cookie_set_calls++;
                }
            }

            public function save_data(): void {
                $this->save_calls++;
            }
        };
        WC()->session = $redis_handler;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 't_redis_browser_session', $redis_handler->get_customer_id() );
        $this->assertSame( 1, $redis_handler->cookie_set_calls );
        $this->assertSame( 1, $redis_handler->save_calls );
        $this->assertCount( 1, $redis_handler->get( 'cart' ) );
        $this->assertSame( 17510, $redis_handler->get( 'cart' )['a']['product_id'] );

        // Repeat call is idempotent because t_agent_cart was drained.
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertSame( 1, $redis_handler->save_calls );
    }

    public function test_never_binds_a_browser_to_a_registered_user_session(): void {
        $this->given_session_with_cart( '999', self::AGENT_CART_LINES );

        $this->assertSame( self::DEFERRED, $this->bind( $this->generate_jwt( '999' ) ) );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
    }

    public function test_ignores_a_forged_or_expired_agent_token(): void {
        $this->given_session_with_cart( 't_victim', self::AGENT_CART_LINES );

        $this->assertSame( self::DEFERRED, $this->bind( $this->generate_jwt( 't_victim', 3600, 'secret_salt', false ) ) );
        $this->assertSame( self::DEFERRED, $this->bind( $this->generate_jwt( 't_victim', -10 ) ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_treats_a_forged_session_cookie_as_no_session(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_someone_else||12345||12345||forged';
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( 'agent', $this->bind( $this->generate_jwt( 't_agent_cart' ) )['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
    }

    /**
     * The agent's session is only in the database table. A handler that
     * keeps sessions elsewhere would not find it, and asking again cannot
     * change that, so the answer is final rather than an error.
     */
    public function test_answers_unsupported_for_a_session_handler_that_keeps_sessions_elsewhere(): void {
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $handlers = [
            // A WC_Session_Handler that reads sessions from somewhere else,
            // such as Redis.
            new class() extends WC_Session_Handler {
                public int $cookie_set_calls = 0;
                public function get_session( $customer_id, $default = false ) {
                    return $default;
                }
                public function set_customer_session_cookie( $set ): void {
                    $this->cookie_set_calls++;
                }
            },
            // A handler built on WC_Session alone.
            new class() extends WC_Session {
                public int $cookie_set_calls = 0;
                public function set_customer_session_cookie( bool $set ): void {
                    $this->cookie_set_calls++;
                }
            },
        ];

        foreach ( $handlers as $handler ) {
            WC()->session = $handler;
            $customer_id  = $handler->get_customer_id();

            $this->assertSame( self::UNSUPPORTED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
            $this->assertSame( $customer_id, $handler->get_customer_id() );
            $this->assertSame( 0, $handler->cookie_set_calls );
        }
    }

    public function test_answers_an_error_without_a_woocommerce_session_to_bind(): void {
        // Neither WooCommerce's session handler nor one built on WC_Session.
        WC()->session = new stdClass();

        $response = ( new REST_API() )->cart_binding->bind_cart_session_handler( new WP_REST_Request( 'POST', '/gecx/v1/cart-session' ) );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'session_not_initialized', $response->get_error_code() );
        $this->assertSame( 500, $response->get_error_data()['status'] ?? 0 );
    }

    public function test_starts_the_session_woocommerce_does_not_start_for_rest_requests(): void {
        WC()->session = null;

        $this->assertSame( self::DEFERRED, $this->bind( null ) );
        $this->assertInstanceOf( WC_Session_Handler::class, WC()->session );
    }

    public function test_merge_enforces_real_product_sold_individually_stock_caps_and_trash_status(): void {
        $solo_existing = new \WC_Product_Simple();
        $solo_existing->set_name( 'Solo Pass' );
        $solo_existing->set_regular_price( '15' );
        $solo_existing->set_sold_individually( true );
        $solo_existing_id = $solo_existing->save();

        $solo_new = new \WC_Product_Simple();
        $solo_new->set_name( 'One-Per-Order Membership' );
        $solo_new->set_regular_price( '25' );
        $solo_new->set_sold_individually( true );
        $solo_new_id = $solo_new->save();

        $capped = new \WC_Product_Simple();
        $capped->set_name( 'Limited Mug' );
        $capped->set_regular_price( '20' );
        $capped->set_manage_stock( true );
        $capped->set_stock_quantity( 3 );
        $capped->set_backorders( 'no' );
        $capped_id = $capped->save();

        $out_of_stock = new \WC_Product_Simple();
        $out_of_stock->set_name( 'Sold Out Sticker' );
        $out_of_stock->set_regular_price( '5' );
        $out_of_stock->set_manage_stock( true );
        $out_of_stock->set_stock_quantity( 0 );
        $out_of_stock->set_backorders( 'no' );
        $out_of_stock_id = $out_of_stock->save();

        $trashed = new \WC_Product_Simple();
        $trashed->set_name( 'Discontinued Hat' );
        $trashed->set_regular_price( '12' );
        $trashed->set_status( 'trash' );
        $trashed_id = $trashed->save();

        $this->given_browser_session(
            't_browser_cart',
            [
                'solo_existing' => [
                    'product_id'   => $solo_existing_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
                'capped'        => [
                    'product_id'   => $capped_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 2,
                ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'agent_solo_existing' => [
                    'product_id'   => $solo_existing_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 2,
                ],
                'agent_solo_new'      => [
                    'product_id'   => $solo_new_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 3,
                ],
                'agent_capped'        => [
                    'product_id'   => $capped_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 4,
                ],
                'agent_oos'           => [
                    'product_id'   => $out_of_stock_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
                'agent_trashed'       => [
                    'product_id'   => $trashed_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );

        $merged = WC()->session->get( 'cart' );
        $this->assertIsArray( $merged );
        $this->assertCount( 3, $merged );
        $this->assertSame( 1, $merged['solo_existing']['quantity'] );
        $this->assertSame( 1, $merged['agent_solo_new']['quantity'] );
        $this->assertSame( 3, $merged['capped']['quantity'] );
        $this->assertArrayNotHasKey( 'agent_oos', $merged );
        $this->assertArrayNotHasKey( 'agent_trashed', $merged );
    }

    public function test_merge_handles_real_any_attribute_variations_and_colliding_wc_cart_keys(): void {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name( 'Size' );
        $attribute->set_options( [ 'Small', 'Large' ] );
        $attribute->set_position( 0 );
        $attribute->set_visible( true );
        $attribute->set_variation( true );

        $variable = new \WC_Product_Variable();
        $variable->set_name( 'Any-Size Tee' );
        $variable->set_status( 'publish' );
        $variable->set_attributes( [ $attribute ] );
        $variable_id = $variable->save();

        // Wildcard ("Any Size") variation: empty attributes on the variation record.
        $any_variation = new \WC_Product_Variation();
        $any_variation->set_parent_id( $variable_id );
        $any_variation->set_regular_price( '30' );
        $any_variation->set_status( 'publish' );
        $any_variation->set_attributes( [] );
        $variation_id = $any_variation->save();
        \WC_Product_Variable::sync( $variable_id );

        $simple = new \WC_Product_Simple();
        $simple->set_name( 'Key Collision Bottle' );
        $simple->set_regular_price( '18' );
        $simple_id = $simple->save();

        $reference_cart = new \WC_Cart();
        $small_var      = [ 'attribute_size' => 'Small' ];
        $large_var      = [ 'attribute_size' => 'Large' ];
        $small_key      = $reference_cart->generate_cart_id( $variable_id, $variation_id, $small_var, [] );
        $large_key      = $reference_cart->generate_cart_id( $variable_id, $variation_id, $large_var, [] );
        $canon_key      = $reference_cart->generate_cart_id( $simple_id, 0, [], [] );

        // On REST routes WC()->cart is null; generate_wc_cart_id() still computes
        // the canonical WC_Cart::generate_cart_id() key when a source key collides
        // with an unrelated browser line.
        $fallback_simple = new \WC_Product_Simple();
        $fallback_simple->set_name( 'Null-Cart Fallback Item' );
        $fallback_simple->set_regular_price( '14' );
        $fallback_id  = $fallback_simple->save();
        $fallback_key = $reference_cart->generate_cart_id( $fallback_id, 0, [], [] );
        WC()->cart    = null;

        $this->given_browser_session(
            't_browser_cart',
            [
                $small_key => [
                    'key'          => $small_key,
                    'product_id'   => $variable_id,
                    'variation_id' => $variation_id,
                    'variation'    => $small_var,
                    'quantity'     => 1,
                ],
                // Same generate_cart_id key as the agent's line, plus an extra
                // runtime field that makes cart_line_signature() differ.
                $canon_key => [
                    'key'                => $canon_key,
                    'product_id'         => $simple_id,
                    'variation_id'       => 0,
                    'variation'          => [],
                    'quantity'           => 1,
                    'theme_runtime_meta' => 'from-browser',
                ],
                'shared_slot' => [
                    'key'          => 'shared_slot',
                    'product_id'   => 999,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $small_key => [
                    'key'          => $small_key,
                    'product_id'   => $variable_id,
                    'variation_id' => $variation_id,
                    'variation'    => $small_var,
                    'quantity'     => 2,
                ],
                $large_key => [
                    'key'          => $large_key,
                    'product_id'   => $variable_id,
                    'variation_id' => $variation_id,
                    'variation'    => $large_var,
                    'quantity'     => 1,
                ],
                $canon_key => [
                    'key'          => $canon_key,
                    'product_id'   => $simple_id,
                    'variation_id' => 0,
                    'variation'          => [],
                    'quantity'     => 2,
                ],
                // Collides with 'shared_slot' on a different product while WC()->cart is null:
                // must receive the canonical generate_cart_id key ($fallback_key).
                'shared_slot' => [
                    'key'          => 'shared_slot',
                    'product_id'   => $fallback_id,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );

        $merged = WC()->session->get( 'cart' );
        $this->assertIsArray( $merged );
        $this->assertCount( 5, $merged );
        $this->assertSame( 3, $merged[ $small_key ]['quantity'] );
        $this->assertSame( 1, $merged[ $large_key ]['quantity'] );
        $this->assertSame( 3, $merged[ $canon_key ]['quantity'] );
        $this->assertArrayHasKey( $fallback_key, $merged );
        $this->assertSame( 1, $merged[ $fallback_key ]['quantity'] );
    }

    public function test_stale_in_flight_agent_save_after_merge_only_adds_new_delta_lines(): void {
        // Reproduces overlapping "add A and C" where A merges while C's Store API
        // request (which loaded {A:1} at start) finishes afterwards and saves {A:1, C:1}.
        $this->given_browser_session(
            't_browser_cart',
            [
                'b' => [ 'product_id' => 9, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // In-flight Store API save for C writes {A:1, C:1} blindly to t_agent_cart.
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                'c' => [ 'product_id' => 321, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );

        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );

        $merged = WC()->session->get( 'cart' );
        $this->assertCount( 3, $merged );
        $this->assertSame( 1, $merged['a']['quantity'] );
        $this->assertSame( 1, $merged['b']['quantity'] );
        $this->assertSame( 1, $merged['c']['quantity'] );
    }

    public function test_agent_quantity_updates_and_removals_propagate_to_merged_lines(): void {
        $this->given_browser_session(
            't_browser_cart',
            [
                'shared' => [ 'product_id' => 10, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'agent_shared' => [ 'product_id' => 10, 'variation_id' => 0, 'variation' => [], 'quantity' => 2 ],
                'agent_only'   => [ 'product_id' => 20, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 3, WC()->session->get( 'cart' )['shared']['quantity'] );
        $this->assertSame( 1, WC()->session->get( 'cart' )['agent_only']['quantity'] );

        // Agent increases agent_only from 1 -> 4 and reduces agent_shared from 2 -> 1.
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'agent_shared' => [ 'product_id' => 10, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                'agent_only'   => [ 'product_id' => 20, 'variation_id' => 0, 'variation' => [], 'quantity' => 4 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 2, WC()->session->get( 'cart' )['shared']['quantity'] );
        $this->assertSame( 4, WC()->session->get( 'cart' )['agent_only']['quantity'] );

        // Agent removes both lines from t_agent_cart: agent_only is deleted and
        // shared drops back to the 1 unit the shopper originally put in their cart.
        $this->given_session_with_cart( 't_agent_cart', [] );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertCount( 1, WC()->session->get( 'cart' ) );
        $this->assertSame( 1, WC()->session->get( 'cart' )['shared']['quantity'] );
        $this->assertArrayNotHasKey( 'agent_only', WC()->session->get( 'cart' ) );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
    }

    public function test_failed_target_session_write_returns_error_and_preserves_agent_lines_for_retry(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );
        $this->given_session_with_cart( '42', [ 'u1' => [ 'product_id' => 77, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        WC()->session = null;

        $fail_write = static function ( string $sql ) {
            if ( false !== strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'woocommerce_sessions' ) ) {
                return '';
            }
            return $sql;
        };
        add_filter( 'query', $fail_write );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        $request->set_param( 'cart_token', $this->generate_jwt( 't_agent_cart' ) );
        $error = ( new REST_API() )->cart_binding->bind_cart_session_handler( $request );
        remove_filter( 'query', $fail_write );

        $this->assertInstanceOf( WP_Error::class, $error );
        $this->assertSame( 'cart_merge_failed', $error->get_error_code() );
        $this->assertSame( 500, $error->get_error_data()['status'] ?? 0 );
        $this->assertSame( self::AGENT_CART_LINES, maybe_unserialize( $this->get_wc_session( 't_agent_cart' )['cart'] ?? '' ) );
        $this->assertCount( 1, maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' ) );

        // Subsequent retry after DB recovery succeeds without losing or doubling lines.
        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $user_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 2, $user_cart );
        $this->assertSame( 1, $user_cart['a']['quantity'] );
    }

    public function test_merge_rereads_fresh_browser_session_row_instead_of_stale_in_memory_snapshot(): void {
        // WC()->session booted with 1 item, then a concurrent request committed a second item to SQL.
        $this->given_browser_session( 't_browser_cart', [ 'b' => [ 'product_id' => 9, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart(
            't_browser_cart',
            [
                'b' => [ 'product_id' => 9, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                'c' => [ 'product_id' => 12, 'variation_id' => 0, 'variation' => [], 'quantity' => 4 ],
            ]
        );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::MERGED, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );

        $merged = WC()->session->get( 'cart' );
        $this->assertCount( 3, $merged );
        $this->assertSame( 1, $merged['b']['quantity'] );
        $this->assertSame( 4, $merged['c']['quantity'] );
        $this->assertSame( 1, $merged['a']['quantity'] );
    }

    public function test_logged_in_ledger_survives_real_woocommerce_login_cookie_migration(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User Item' );
        $prod_g = $make_product( 'Guest Item' );
        $prod_a = $make_product( 'Agent Item A' );
        $prod_c = $make_product( 'Agent Item C' );
        $prod_d = $make_product( 'Agent Item D' );

        $ref_cart = new \WC_Cart();
        $key_u    = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_g    = $ref_cart->generate_cart_id( $prod_g, 0, [], [] );
        $key_a    = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $key_c    = $ref_cart->generate_cart_id( $prod_c, 0, [], [] );
        $key_d    = $ref_cart->generate_cart_id( $prod_d, 0, [], [] );

        $blog_id   = (int) get_current_blog_id();
        $user_init = [
            $key_u => [
                'key'          => $key_u,
                'product_id'   => $prod_u,
                'variation_id' => 0,
                'variation'    => [],
                'quantity'     => 1,
            ],
        ];
        $this->given_session_with_cart( '42', $user_init );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
        update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );

        $guest_key = 't_login_migration_guest';
        $this->given_session_with_cart(
            $guest_key,
            [
                $key_g => [
                    'key'          => $key_g,
                    'product_id'   => $prod_g,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );

        $signer   = new WC_Session_Handler();
        $point_at = \Closure::bind(
            function ( string $key ): void {
                $this->_customer_id = $key;
            },
            $signer,
            WC_Session_Handler::class
        );
        $point_at( $guest_key );
        $signer->set_session_expiration();
        $signer->set_customer_session_cookie( true );

        $cookie_name             = 'wp_woocommerce_session_' . COOKIEHASH;
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];
        WC()->session            = null;
        WC()->cart               = null;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [
                    'key'          => $key_a,
                    'product_id'   => $prod_a,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // Agent adds C while the guest cookie has still not migrated.
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [
                    'key'          => $key_a,
                    'product_id'   => $prod_a,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
                $key_c => [
                    'key'          => $key_c,
                    'product_id'   => $prod_c,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // Run WooCommerce's real login cookie migration and persistent-cart merge:
        // init_session_cookie() overwrites row 42 with the guest row and
        // get_cart_from_session() + persistent_cart_update() rewrite persistent
        // cart user meta without custom keys.
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        if ( isset( $this->cookies[ $cookie_name ]['value'] ) ) {
            $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        }
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->session->save_data();

        $migrated_row = $this->get_wc_session( '42' ) ?? [];
        $this->assertArrayNotHasKey( 'gecx_merged', $migrated_row );
        $saved_meta = get_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, true );
        $this->assertIsArray( $saved_meta );
        $this->assertArrayNotHasKey( 'gecx_merged', $saved_meta );
        $this->assertCount( 4, $saved_meta['cart'] ?? [] );

        // Next REST bind reads _gecx_merged_{blog_id} and keeps 1 of each item.
        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );

        $after_rebind = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 4, $after_rebind );
        $this->assertSame( 1, $after_rebind[ $key_a ]['quantity'] );
        $this->assertSame( 1, $after_rebind[ $key_c ]['quantity'] );
        $this->assertSame( 1, $after_rebind[ $key_g ]['quantity'] );
        $this->assertSame( 1, $after_rebind[ $key_u ]['quantity'] );

        // Subsequent new agent addition D merges cleanly on top.
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_c => [ 'key' => $key_c, 'product_id' => $prod_c, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_d => [ 'key' => $key_d, 'product_id' => $prod_d, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $after_d = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 5, $after_d );
        $this->assertSame( 1, $after_d[ $key_a ]['quantity'] );
        $this->assertSame( 1, $after_d[ $key_c ]['quantity'] );
        $this->assertSame( 1, $after_d[ $key_d ]['quantity'] );
    }

    public function test_logged_in_ledger_survives_real_logout_and_relogin(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $prod_u = new \WC_Product_Simple();
        $prod_u->set_name( 'Saved User Item' );
        $prod_u->set_regular_price( '12' );
        $prod_u->set_status( 'publish' );
        $prod_u_id = $prod_u->save();

        $prod_a = new \WC_Product_Simple();
        $prod_a->set_name( 'Agent Item' );
        $prod_a->set_regular_price( '18' );
        $prod_a->set_status( 'publish' );
        $prod_a_id = $prod_a->save();

        $ref_cart = new \WC_Cart();
        $key_u    = $ref_cart->generate_cart_id( $prod_u_id, 0, [], [] );
        $key_a    = $ref_cart->generate_cart_id( $prod_a_id, 0, [], [] );

        $blog_id   = (int) get_current_blog_id();
        $user_init = [
            $key_u => [ 'key' => $key_u, 'product_id' => $prod_u_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', $user_init );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        WC()->session = null;
        WC()->cart    = null;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // Logout: WC_Session_Handler::destroy_session() deletes row 42 while
        // keeping _woocommerce_persistent_cart_{blog_id}.
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->session->destroy_session();
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];
        $this->assertNull( $this->get_wc_session( '42' ) );

        // Re-login: WooCommerce rebuilds row 42 from persistent cart meta without gecx_merged.
        wp_set_current_user( 42 );
        update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->session->save_data();
        $this->assertArrayNotHasKey( 'gecx_merged', $this->get_wc_session( '42' ) ?? [] );

        // Next bind sees _gecx_merged_{blog_id} and does not re-add A.
        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $rebuilt_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 2, $rebuilt_cart );
        $this->assertSame( 1, $rebuilt_cart[ $key_a ]['quantity'] );
        $this->assertSame( 1, $rebuilt_cart[ $key_u ]['quantity'] );
    }

    public function test_capped_add_and_sold_individually_never_remove_shopper_units_on_agent_removal(): void {
        $capped = new \WC_Product_Simple();
        $capped->set_name( 'Stock-3 Item' );
        $capped->set_regular_price( '20' );
        $capped->set_manage_stock( true );
        $capped->set_stock_quantity( 3 );
        $capped->set_backorders( 'no' );
        $capped_id = $capped->save();

        $solo = new \WC_Product_Simple();
        $solo->set_name( 'Sold Individually Item' );
        $solo->set_regular_price( '30' );
        $solo->set_sold_individually( true );
        $solo_id = $solo->save();

        $agent_token = $this->generate_jwt( 't_agent_cart' );

        // Case 1: Stock 3, shopper has 2, agent adds 2 -> browser shows 3 (an add of 1).
        // When the agent later removes its line, only the 1 applied unit is subtracted,
        // leaving the shopper's 2 units intact.
        $this->given_browser_session(
            't_browser_cart',
            [
                'shopper_capped' => [ 'product_id' => $capped_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 2 ],
            ]
        );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                'agent_capped' => [ 'product_id' => $capped_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 2 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 3, WC()->session->get( 'cart' )['shopper_capped']['quantity'] );

        $this->given_session_with_cart( 't_agent_cart', [] );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 2, WC()->session->get( 'cart' )['shopper_capped']['quantity'] );

        // Case 2: Sold individually, shopper has 1, agent adds 1 -> no change, answer 'existing'.
        // When the agent later removes its line, the shopper's line of 1 is preserved.
        $this->given_browser_session(
            't_solo_browser',
            [
                'shopper_solo' => [ 'product_id' => $solo_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->given_session_with_cart( 't_solo_browser', WC()->session->get( 'cart' ) );
        $solo_agent_token = $this->generate_jwt( 't_solo_agent' );
        $this->given_session_with_cart(
            't_solo_agent',
            [
                'agent_solo' => [ 'product_id' => $solo_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->assertSame( self::EXISTING, $this->bind( $solo_agent_token ) );
        $this->assertSame( 1, WC()->session->get( 'cart' )['shopper_solo']['quantity'] );

        $this->given_session_with_cart( 't_solo_agent', [] );
        $this->assertSame( self::EXISTING, $this->bind( $solo_agent_token ) );
        $this->assertArrayHasKey( 'shopper_solo', WC()->session->get( 'cart' ) );
        $this->assertSame( 1, WC()->session->get( 'cart' )['shopper_solo']['quantity'] );
    }

    public function test_guest_merged_ledger_wins_over_stale_user_meta_on_relogin_migration(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User U' );
        $prod_a = $make_product( 'Agent A' );
        $prod_b = $make_product( 'Guest B' );
        $prod_c = $make_product( 'Agent C' );

        $ref_cart = new \WC_Cart();
        $key_u    = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_a    = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $key_b    = $ref_cart->generate_cart_id( $prod_b, 0, [], [] );
        $key_c    = $ref_cart->generate_cart_id( $prod_c, 0, [], [] );

        $blog_id   = (int) get_current_blog_id();
        $user_init = [
            $key_u => [ 'key' => $key_u, 'product_id' => $prod_u, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', $user_init );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        WC()->session = null;
        WC()->cart    = null;

        // 1. Logged in with {U}, agent {A}: bind gives {A:1, U:1}, user meta records A.
        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // 2. Log out via real destroy_session().
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->session->destroy_session();
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];

        // Guest adds B; agent adds C; guest bind merges into guest row -> {A, B, C} with row ledger {A, C}.
        $guest_key = 't_post_logout_guest';
        $guest_b   = [
            $key_b => [ 'key' => $key_b, 'product_id' => $prod_b, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( $guest_key, $guest_b );
        $signer   = new WC_Session_Handler();
        $point_at = \Closure::bind(
            function ( string $key, array $data ): void {
                $this->_customer_id = $key;
                $this->_data        = $data;
            },
            $signer,
            WC_Session_Handler::class
        );
        $point_at( $guest_key, [ 'cart' => maybe_serialize( $guest_b ) ] );
        $signer->set_session_expiration();
        $signer->set_customer_session_cookie( true );

        $cookie_name             = 'wp_woocommerce_session_' . COOKIEHASH;
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];
        WC()->session            = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = null;

        $this->given_session_with_cart(
            't_agent_cart',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_c => [ 'key' => $key_c, 'product_id' => $prod_c, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );

        // 3. Log back in: init_session_cookie() + get_cart_from_session() give {A, B, C, U}.
        wp_set_current_user( 42 );
        update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        if ( isset( $this->cookies[ $cookie_name ]['value'] ) ) {
            $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        }
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->session->save_data();

        // Next bind answers 'existing' with 1 of each (C is not double-added).
        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );

        $final_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 4, $final_cart );
        $this->assertSame( 1, $final_cart[ $key_a ]['quantity'] );
        $this->assertSame( 1, $final_cart[ $key_b ]['quantity'] );
        $this->assertSame( 1, $final_cart[ $key_c ]['quantity'] );
        $this->assertSame( 1, $final_cart[ $key_u ]['quantity'] );
    }

    public function test_login_migrating_agent_own_session_never_removes_agent_lines_or_later_additions(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User U' );
        $prod_a = $make_product( 'Agent A' );
        $prod_c = $make_product( 'Agent C' );
        $prod_d = $make_product( 'Agent D' );

        $ref_cart    = new \WC_Cart();
        $key_u       = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_a       = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $key_c       = $ref_cart->generate_cart_id( $prod_c, 0, [], [] );
        $key_d       = $ref_cart->generate_cart_id( $prod_d, 0, [], [] );
        $blog_id     = (int) get_current_blog_id();
        $cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;

        // Sequence 1: Logged in {U}, agent {A} -> bind {A:1, U:1} -> logout ->
        // open chat ('agent') -> log in (migrates and deletes t_agent) -> bind keeps {A:1, U:1}.
        $user_init = [
            $key_u => [ 'key' => $key_u, 'product_id' => $prod_u, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', $user_init );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
        $this->given_session_with_cart(
            't_agent_direct',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        WC()->session = null;
        WC()->cart    = null;

        $direct_token = $this->generate_jwt( 't_agent_direct' );
        $this->assertSame( self::MERGED, $this->bind( $direct_token ) );

        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->session->destroy_session();
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];
        WC()->session  = null;
        WC()->cart     = null;

        // Logged-out shopper opens chat: empty browser cart binds straight to t_agent_direct ('agent').
        $this->assertSame( 'agent', $this->bind( $direct_token )['binding'] );
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];

        // Login migrates t_agent_direct into row 42 and deletes row t_agent_direct.
        wp_set_current_user( 42 );
        update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->session->save_data();
        $this->assertNull( $this->get_wc_session( 't_agent_direct' ) );

        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $direct_token ) );

        $row_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 2, $row_cart );
        $this->assertSame( 1, $row_cart[ $key_a ]['quantity'] );
        $this->assertSame( 1, $row_cart[ $key_u ]['quantity'] );
        $meta_cart = get_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, true )['cart'] ?? [];
        $this->assertCount( 2, $meta_cart );
        $this->assertSame( 1, $meta_cart[ $key_a ]['quantity'] );

        // Variant: agent adds C before login, then after login adds D (recreating its row).
        // Neither A nor C is subtracted when D merges.
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->session->destroy_session();
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];
        WC()->session  = null;
        WC()->cart     = null;

        $variant_token = $this->generate_jwt( 't_agent_variant' );
        $this->given_session_with_cart(
            't_agent_variant',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        wp_set_current_user( 42 );
        $this->given_session_with_cart( '42', $user_init );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
        delete_user_meta( 42, '_gecx_merged_' . $blog_id );
        $this->assertSame( self::MERGED, $this->bind( $variant_token ) );

        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->session->destroy_session();
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];
        WC()->session  = null;
        WC()->cart     = null;

        $this->assertSame( 'agent', $this->bind( $variant_token )['binding'] );
        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];

        // Agent adds C before login.
        $this->given_session_with_cart(
            't_agent_variant',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_c => [ 'key' => $key_c, 'product_id' => $prod_c, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );

        wp_set_current_user( 42 );
        update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->session->save_data();
        $this->assertNull( $this->get_wc_session( 't_agent_variant' ) );

        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $variant_token ) );

        // Agent adds D, recreating t_agent_variant with {D:1} alone.
        $this->given_session_with_cart(
            't_agent_variant',
            [
                $key_d => [ 'key' => $key_d, 'product_id' => $prod_d, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $variant_token ) );

        $variant_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 4, $variant_cart );
        $this->assertSame( 1, $variant_cart[ $key_a ]['quantity'] );
        $this->assertSame( 1, $variant_cart[ $key_c ]['quantity'] );
        $this->assertSame( 1, $variant_cart[ $key_d ]['quantity'] );
        $this->assertSame( 1, $variant_cart[ $key_u ]['quantity'] );
    }

    public function test_cart_emptied_clears_ledger_so_later_agent_drop_preserves_shopper_item(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $prod_a = new \WC_Product_Simple();
        $prod_a->set_name( 'Re-Purchased Item A' );
        $prod_a->set_regular_price( '15' );
        $prod_a->set_status( 'publish' );
        $prod_a_id = $prod_a->save();

        $ref_cart = new \WC_Cart();
        $key_a    = $ref_cart->generate_cart_id( $prod_a_id, 0, [], [] );
        $blog_id  = (int) get_current_blog_id();

        $line_a = [
            $key_a => [ 'key' => $key_a, 'product_id' => $prod_a_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', [] );
        $this->given_session_with_cart( 't_agent_cart', $line_a );
        WC()->session = null;
        WC()->cart    = null;

        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertNotEmpty( get_user_meta( 42, '_gecx_merged_' . $blog_id, true ) );

        // Checkout / order placement empties the shopper's cart via WC_Cart::empty_cart(),
        // marking synced entries unapplied (quantity => 0, target_key => '') while keeping agent_qty => 1.
        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->cart->empty_cart();
        WC()->session->save_data();

        $meta_ledger = get_user_meta( 42, '_gecx_merged_' . $blog_id, true );
        $this->assertIsArray( $meta_ledger );
        $this->assertArrayHasKey( 't_agent_cart', $meta_ledger );
        $meta_entry = reset( $meta_ledger['t_agent_cart'] );
        $this->assertSame( 0, $meta_entry['quantity'] );
        $this->assertSame( 1, $meta_entry['agent_qty'] );
        $this->assertSame( '', $meta_entry['target_key'] );

        $row_ledger = maybe_unserialize( $this->get_wc_session( '42' )['gecx_merged'] ?? '' );
        $this->assertIsArray( $row_ledger );
        $row_entry = reset( $row_ledger['t_agent_cart'] );
        $this->assertSame( 0, $row_entry['quantity'] );
        $this->assertSame( 1, $row_entry['agent_qty'] );
        $this->assertSame( '', $row_entry['target_key'] );

        // Shopper later adds A themselves; agent's cart drops A; bind answers 'existing' and keeps A.
        $this->given_session_with_cart( '42', $line_a );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $line_a ] );
        $this->given_session_with_cart( 't_agent_cart', [] );
        WC()->session = null;
        WC()->cart    = null;

        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $after_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 1, $after_cart );
        $this->assertSame( 1, $after_cart[ $key_a ]['quantity'] );
    }

    public function test_post_order_cart_empty_never_readds_merged_agent_items_for_logged_in_or_guest(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '12' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User U' );
        $prod_b = $make_product( 'Guest B' );
        $prod_a = $make_product( 'Agent A' );
        $prod_d = $make_product( 'Agent D' );

        $ref_cart    = new \WC_Cart();
        $key_u       = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_b       = $ref_cart->generate_cart_id( $prod_b, 0, [], [] );
        $key_a       = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $key_d       = $ref_cart->generate_cart_id( $prod_d, 0, [], [] );
        $blog_id     = (int) get_current_blog_id();
        $cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;

        // 1. Logged-in with {U}, agent {A}: bind gives {A:1, U:1}, order empties cart,
        // next chat open answers 'existing' with {} (never re-adding A), and adding D gives {D:1}.
        wp_set_current_user( 42 );
        $user_u = [
            $key_u => [ 'key' => $key_u, 'product_id' => $prod_u, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $agent_a = [
            $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', $user_u );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_u ] );
        $this->given_session_with_cart( 't_agent_order_user', $agent_a );
        WC()->session = null;
        WC()->cart    = null;

        $user_agent_token = $this->generate_jwt( 't_agent_order_user' );
        $this->assertSame( self::MERGED, $this->bind( $user_agent_token ) );

        WC()->customer = new \WC_Customer( 42, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->cart->empty_cart();
        WC()->session->save_data();

        WC()->session = null;
        WC()->cart    = null;
        $this->assertSame( self::EXISTING, $this->bind( $user_agent_token ) );
        $this->assertEmpty( maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' ) );
        $user_meta_after_order = get_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, true );
        $this->assertEmpty( is_array( $user_meta_after_order ) ? ( $user_meta_after_order['cart'] ?? [] ) : [] );

        // Agent adds D alongside still-undrained A: bind merges {D:1} alone.
        $this->given_session_with_cart(
            't_agent_order_user',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_d => [ 'key' => $key_d, 'product_id' => $prod_d, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        $this->assertSame( self::MERGED, $this->bind( $user_agent_token ) );
        $user_cart_with_d = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertSame( [ $key_d ], array_keys( $user_cart_with_d ) );
        $this->assertSame( 1, $user_cart_with_d[ $key_d ]['quantity'] );

        // 2. Guest with {B}, agent {A}: bind gives {A:1, B:1}, order empties cart,
        // next chat open answers 'existing' with {} (never 'agent'), and adding D gives {D:1}.
        wp_set_current_user( 0 );
        $_COOKIE       = [];
        $this->cookies = [];
        $guest_key     = 't_guest_order_shopper';
        $guest_b       = [
            $key_b => [ 'key' => $key_b, 'product_id' => $prod_b, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( $guest_key, $guest_b );
        $this->given_session_with_cart( 't_agent_order_guest', $agent_a );

        $signer   = new WC_Session_Handler();
        $point_at = \Closure::bind(
            function ( string $key, array $data ): void {
                $this->_customer_id = $key;
                $this->_data        = $data;
            },
            $signer,
            WC_Session_Handler::class
        );
        $point_at( $guest_key, [ 'cart' => maybe_serialize( $guest_b ) ] );
        $signer->set_session_expiration();
        $signer->set_customer_session_cookie( true );

        $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
        $this->cookies           = [];
        WC()->session            = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = null;

        $guest_agent_token = $this->generate_jwt( 't_agent_order_guest' );
        $this->assertSame( self::MERGED, $this->bind( $guest_agent_token ) );

        WC()->customer = new \WC_Customer( 0, true );
        WC()->session  = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = new \WC_Cart();
        WC()->cart->get_cart_from_session();
        WC()->cart->empty_cart();
        WC()->session->save_data();

        WC()->session = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = null;
        $this->assertSame( self::EXISTING, $this->bind( $guest_agent_token ) );
        $this->assertEmpty( maybe_unserialize( $this->get_wc_session( $guest_key )['cart'] ?? '' ) );

        $this->given_session_with_cart(
            't_agent_order_guest',
            [
                $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                $key_d => [ 'key' => $key_d, 'product_id' => $prod_d, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ]
        );
        WC()->session = new WC_Session_Handler();
        WC()->session->init_session_cookie();
        WC()->cart = null;
        $this->assertSame( self::MERGED, $this->bind( $guest_agent_token ) );
        $guest_cart_with_d = maybe_unserialize( $this->get_wc_session( $guest_key )['cart'] ?? '' );
        $this->assertSame( [ $key_d ], array_keys( $guest_cart_with_d ) );
        $this->assertSame( 1, $guest_cart_with_d[ $key_d ]['quantity'] );
    }

    public function test_pre_wc_88_login_without_migration_hook_drops_deleted_agent_ledger_entry(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User U' );
        $prod_a = $make_product( 'Agent A' );
        $prod_c = $make_product( 'Agent C' );
        $prod_d = $make_product( 'Agent D' );

        $ref_cart    = new \WC_Cart();
        $key_u       = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_a       = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $key_c       = $ref_cart->generate_cart_id( $prod_c, 0, [], [] );
        $key_d       = $ref_cart->generate_cart_id( $prod_d, 0, [], [] );
        $blog_id     = (int) get_current_blog_id();
        $cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;

        try {
            $user_init = [
                $key_u => [ 'key' => $key_u, 'product_id' => $prod_u, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
            ];
            $this->given_session_with_cart( '42', $user_init );
            update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_init ] );
            $this->given_session_with_cart(
                't_agent_pre88',
                [
                    $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                ]
            );
            WC()->session = null;
            WC()->cart    = null;

            $token = $this->generate_jwt( 't_agent_pre88' );
            $this->assertSame( self::MERGED, $this->bind( $token ) );

            // Logout.
            WC()->customer = new \WC_Customer( 42, true );
            WC()->session  = new WC_Session_Handler();
            WC()->session->init_session_cookie();
            WC()->cart = new \WC_Cart();
            WC()->session->destroy_session();
            wp_set_current_user( 0 );
            $_COOKIE       = [];
            $this->cookies = [];
            WC()->session  = null;
            WC()->cart     = null;

            // Chat open binds guest directly to t_agent_pre88 ('agent'), then agent adds C.
            $this->assertSame( 'agent', $this->bind( $token )['binding'] );
            $_COOKIE[ $cookie_name ] = $this->cookies[ $cookie_name ]['value'];
            $this->cookies           = [];
            $this->given_session_with_cart(
                't_agent_pre88',
                [
                    $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                    $key_c => [ 'key' => $key_c, 'product_id' => $prod_c, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                ]
            );

            // Login on WC < 8.8 (remove hook after bind() since new REST_API() registers it) deletes t_agent_pre88.
            remove_action( 'woocommerce_guest_session_to_user_id', [ Cart_Binding::class, 'on_guest_session_migrated_to_user' ], 10 );
            wp_set_current_user( 42 );
            update_user_meta( 42, '_woocommerce_load_saved_cart_after_login', 1 );
            WC()->customer = new \WC_Customer( 42, true );
            WC()->session  = new WC_Session_Handler();
            WC()->session->init_session_cookie();
            WC()->cart = new \WC_Cart();
            WC()->cart->get_cart_from_session();
            WC()->session->save_data();
            $this->assertNull( $this->get_wc_session( 't_agent_pre88' ) );
            $this->assertNotEmpty( get_user_meta( 42, '_gecx_merged_' . $blog_id, true ) );

            // Post-login bind sees [] === read_session_row('t_agent_pre88'), drops stale ledger entry, and keeps {A, C, U}.
            WC()->session = null;
            WC()->cart    = null;
            $this->assertSame( self::EXISTING, $this->bind( $token ) );
            $this->assertSame( '', get_user_meta( 42, '_gecx_merged_' . $blog_id, true ) );

            // Agent later adds D (recreating t_agent_pre88 with {D:1}): A is preserved -> {A:1, C:1, D:1, U:1}.
            $this->given_session_with_cart(
                't_agent_pre88',
                [
                    $key_d => [ 'key' => $key_d, 'product_id' => $prod_d, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                ]
            );
            $this->assertSame( self::MERGED, $this->bind( $token ) );

            $final_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
            $this->assertCount( 4, $final_cart );
            $this->assertSame( 1, $final_cart[ $key_a ]['quantity'] );
            $this->assertSame( 1, $final_cart[ $key_c ]['quantity'] );
            $this->assertSame( 1, $final_cart[ $key_d ]['quantity'] );
            $this->assertSame( 1, $final_cart[ $key_u ]['quantity'] );
        } finally {
            add_action( 'woocommerce_guest_session_to_user_id', [ Cart_Binding::class, 'on_guest_session_migrated_to_user' ], 10, 2 );
        }
    }

    public function test_store_api_cart_empty_on_agent_session_while_logged_in_leaves_shopper_ledger_intact(): void {
        $this->ensure_user_with_id( 42, 'shopper42', 'customer', 'shopper@example.com' );
        wp_set_current_user( 42 );

        $make_product = static function ( string $name ): int {
            $product = new \WC_Product_Simple();
            $product->set_name( $name );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            return $product->save();
        };

        $prod_u = $make_product( 'User U' );
        $prod_a = $make_product( 'Agent A' );

        $ref_cart = new \WC_Cart();
        $key_u    = $ref_cart->generate_cart_id( $prod_u, 0, [], [] );
        $key_a    = $ref_cart->generate_cart_id( $prod_a, 0, [], [] );
        $blog_id  = (int) get_current_blog_id();

        $user_u = [
            $key_u => [ 'key' => $key_u, 'product_id' => $prod_u, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $agent_a = [
            $key_a => [ 'key' => $key_a, 'product_id' => $prod_a, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
        ];
        $this->given_session_with_cart( '42', $user_u );
        update_user_meta( 42, '_woocommerce_persistent_cart_' . $blog_id, [ 'cart' => $user_u ] );
        $this->given_session_with_cart( 't_agent_store_api', $agent_a );
        WC()->session = null;
        WC()->cart    = null;

        $token = $this->generate_jwt( 't_agent_store_api' );
        $this->assertSame( self::MERGED, $this->bind( $token ) );

        // Store API request runs while user 42 is authenticated, emptying t_agent_store_api
        // rather than user 42's own session, then re-adding A to t_agent_store_api.
        WC()->session = new class() extends WC_Session {
            public function __construct() {
                $this->_customer_id = 't_agent_store_api';
                $this->_data        = [];
            }
        };
        do_action( 'woocommerce_cart_emptied', true );

        WC()->session = null;
        WC()->cart    = null;

        // Shopper's ledger still records quantity => 1 for A, so re-binding with {A:1} gives {A:1, U:1}, not {A:2, U:1}.
        $this->assertSame( self::EXISTING, $this->bind( $token ) );
        $after_cart = maybe_unserialize( $this->get_wc_session( '42' )['cart'] ?? '' );
        $this->assertCount( 2, $after_cart );
        $this->assertSame( 1, $after_cart[ $key_a ]['quantity'] );
        $this->assertSame( 1, $after_cart[ $key_u ]['quantity'] );
    }
}
