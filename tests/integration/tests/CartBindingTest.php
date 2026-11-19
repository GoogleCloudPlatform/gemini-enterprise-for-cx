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
        $this->given_session_with_cart( '42', [ 'u1' => [ 'product_id' => 77, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $agent_token = $this->generate_jwt( 't_agent_cart' );

        $this->assertSame( self::MERGED, $this->bind( $agent_token ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
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

        // Repeat call is a no-op returning 'existing' with WC()->session still null.
        $this->assertSame( self::EXISTING, $this->bind( $agent_token ) );
        $this->assertNull( WC()->session );
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
}
