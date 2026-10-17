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

class CartBindingTest extends GECX_TestCase {

    use GECX_CartTokenMinting;

    private const AGENT_CART_LINES = [
        'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
    ];

    private const DEFERRED = [ 'binding' => 'deferred', 'cart_token' => '' ];
    private const EXISTING = [ 'binding' => 'existing', 'cart_token' => '' ];

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
        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
        return $response->get_data();
    }

    public function test_route_requires_a_rest_nonce_and_a_same_origin_request(): void {
        $rest_api = new REST_API();
        $rest_api->cart_binding->register_cart_session_rest_route();
        $route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/cart-session'] ?? null;
        $this->assertSame( 'POST', $route['methods'] );
        $check = $route['permission_callback'];

        $same_origin = new WP_REST_Request();
        $same_origin->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $same_origin->set_header( 'Origin', 'https://example.com' );
        $same_origin->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $this->assertTrue( $check( $same_origin ) );

        $no_nonce = new WP_REST_Request();
        $no_nonce->set_header( 'Origin', 'https://example.com' );
        $this->assertInstanceOf( WP_Error::class, $check( $no_nonce ) );

        // The logged-out nonce is shared by every guest, so another site can
        // have it; the request's origin gives it away.
        $cross_site = new WP_REST_Request();
        $cross_site->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $cross_site->set_header( 'Origin', 'https://attacker.example' );
        $cross_site->set_header( 'Sec-Fetch-Site', 'cross-site' );
        $this->assertInstanceOf( WP_Error::class, $check( $cross_site ) );
    }

    public function test_binds_an_uncookied_guest_to_an_agent_cart_with_items(): void {
        $agent_token = $this->generate_jwt( 't_agent_cart' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $agent_token );

        // No CartTokenUtils in this WooCommerce: the agent's own token is
        // still valid for the cart the browser is now bound to.
        $this->assertSame( [ 'binding' => 'agent', 'cart_token' => $agent_token ], $data );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
        $this->assertSame( maybe_serialize( self::AGENT_CART_LINES ), WC()->session->get_session_data_for_test()['cart'] );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->session->session_expiration_calls );
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

    public function test_keeps_the_cart_of_a_cookied_guest_and_returns_no_token_for_it(): void {
        $this->given_browser_session( 't_browser_cart' );
        $this->given_session_with_cart( 't_browser_cart', [ 'b' => [ 'product_id' => 9, 'quantity' => 1 ] ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $this->assertSame( 't_browser_cart', WC()->session->get_customer_id() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    /**
     * Any guest token, an attacker's own empty one included, must not turn
     * the route into a way to read the browser's cart token.
     */
    public function test_returns_no_token_for_the_browser_session_whatever_token_is_sent(): void {
        $this->given_browser_session( 't_victim' );
        $this->given_session_with_cart( 't_victim', self::AGENT_CART_LINES );

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_attacker_empty' ) ) );
        $this->assertSame( self::EXISTING, $this->bind( null ) );
    }

    public function test_rebinds_a_cookied_guest_with_an_empty_cart_to_the_agent_cart(): void {
        $this->given_browser_session( 't_browser_empty' );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart' ) );

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart', WC()->session->get_customer_id() );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
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

    public function test_keeps_a_logged_in_shopper_cart(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'shopper@example.com', [ 'customer' ] );
        $this->given_session_with_cart( 't_agent_cart', self::AGENT_CART_LINES );

        $this->assertSame( self::EXISTING, $this->bind( $this->generate_jwt( 't_agent_cart' ) ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
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
}
