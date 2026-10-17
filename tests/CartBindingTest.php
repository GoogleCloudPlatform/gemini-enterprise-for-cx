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

require_once __DIR__ . '/bootstrap.php';

use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use Google\Gemini_Enterprise_For_CX\REST\Session_Attribution;

class CartBindingTest extends GECX_TestCase {

    use GECX_CartTokenMinting;

    private const AGENT_CART_LINES = [
        'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
    ];

    /**
     * Stores a session row holding the given cart lines.
     *
     * @param string $session_key Session key.
     * @param array  $lines       Cart lines keyed by cart item key.
     */
    private function given_session_with_cart( string $session_key, array $lines ): void {
        $GLOBALS['wpdb']->wc_sessions[ $session_key ] = maybe_serialize( [ 'cart' => maybe_serialize( $lines ) ] );
    }

    /**
     * Gives the browser a session cookie that WooCommerce accepted, as it
     * does for a guest who added to their cart.
     *
     * @param string $session_key Guest session key.
     */
    private function given_browser_session( string $session_key ): void {
        $_COOKIE['wp_woocommerce_session_test'] = $session_key . '||12345||12345||hash';
        WC()->session->set_customer_id( $session_key );
    }

    private function bind( ?string $cart_token ): array {
        $request = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        if ( null !== $cart_token ) {
            $request->set_param( 'cart_token', $cart_token );
        }
        $response = ( new REST_API() )->cart_binding->bind_cart_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        return $response->get_data();
    }

    public function test_route_requires_a_rest_nonce(): void {
        ( new REST_API() )->cart_binding->register_cart_session_rest_route();

        $route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/cart-session'] ?? null;
        $this->assertNotNull( $route );
        $this->assertSame( 'POST', $route['methods'] );
        $this->assertSame( [ Session_Attribution::class, 'check_session_permissions' ], $route['permission_callback'] );
    }

    public function test_binds_an_uncookied_guest_to_an_agent_cart_with_items(): void {
        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $agent_token );

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->session->session_expiration_calls );
        // No CartTokenUtils in this WooCommerce: the agent's own token is
        // still valid for the cart the browser is now bound to.
        $this->assertSame( $agent_token, $data['cart_token'] );
    }

    public function test_defers_binding_while_the_agent_cart_is_empty(): void {
        $agent_token = $this->generate_jwt( 't_empty_agent_cart' );

        $data = $this->bind( $agent_token );

        $this->assertSame( 'deferred', $data['binding'] );
        $this->assertSame( $agent_token, $data['cart_token'] );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
    }

    public function test_defers_binding_without_an_agent_cart(): void {
        $data = $this->bind( null );

        $this->assertSame( [ 'cart_token' => '', 'binding' => 'deferred' ], $data );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_keeps_the_cart_a_cookied_guest_already_has(): void {
        $this->given_browser_session( 't_browser_cart' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $this->assertSame( 'existing', $data['binding'] );
        $this->assertSame( 't_browser_cart', WC()->session->get_customer_id() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        // The widget copies the agent's lines across; the route leaves them.
        $agent_session = maybe_unserialize( $GLOBALS['wpdb']->wc_sessions['t_agent_cart'] );
        $this->assertSame( self::AGENT_CART_LINES, maybe_unserialize( $agent_session['cart'] ) );
    }

    /**
     * A shared device after a registered user logged out: WooCommerce rejects
     * their session cookie on a logged-out request and gives the request a new
     * guest session, but the cookie, still correctly signed, stays in $_COOKIE.
     */
    public function test_never_hands_a_logged_out_browser_a_registered_user_cart(): void {
        $_COOKIE['wp_woocommerce_session_test'] = '42||12345||12345||hash';

        $without_agent_cart = $this->bind( null );
        $this->assertSame( [ 'cart_token' => '', 'binding' => 'deferred' ], $without_agent_cart );

        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );
        $with_agent_cart = $this->bind( $this->generate_jwt( 't_agent_cart' ) );
        $this->assertSame( 'agent', $with_agent_cart['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
    }

    public function test_ignores_a_guest_cookie_woocommerce_did_not_keep(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_expired_session||12345||12345||hash';

        $data = $this->bind( null );

        $this->assertSame( 'deferred', $data['binding'] );
    }

    public function test_keeps_a_logged_in_shopper_cart(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'shopper@example.com', [ 'customer' ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $this->assertSame( 'existing', $data['binding'] );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_never_binds_a_browser_to_a_registered_user_session(): void {
        $this->given_session_with_cart( '999', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( '999' ) );

        $this->assertSame( [ 'cart_token' => '', 'binding' => 'deferred' ], $data );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
    }

    public function test_ignores_a_forged_or_expired_agent_token(): void {
        $this->given_session_with_cart( 't_victim', self::AGENT_CART_LINES );

        $forged = $this->bind( $this->generate_jwt( 't_victim', 3600, 'secret_salt', false ) );
        $this->assertSame( [ 'cart_token' => '', 'binding' => 'deferred' ], $forged );

        $expired = $this->bind( $this->generate_jwt( 't_victim', -10 ) );
        $this->assertSame( [ 'cart_token' => '', 'binding' => 'deferred' ], $expired );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_treats_a_forged_session_cookie_as_no_session(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_someone_else||12345||12345||forged';
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
    }
}
