<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests for binding the shopper's browser and the agent to one cart.
 *
 * @package GECX
 */

require_once __DIR__ . '/bootstrap.php';

use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use Google\Gemini_Enterprise_For_CX\REST\Session_Attribution;

class CartBindingTest extends GECX_TestCase {

    use GECX_CartTokenMinting;

    /**
     * Stores a guest session row holding the given cart lines.
     *
     * @param string $session_key Session key.
     * @param array  $lines       Cart lines keyed by cart item key.
     */
    private function given_session_with_cart( string $session_key, array $lines ): void {
        $GLOBALS['wpdb']->wc_sessions[ $session_key ] = maybe_serialize(
            [
                'cart'            => maybe_serialize( $lines ),
                'gecx_session_id' => 'projects/1/locations/global/commerceSessions/s',
            ]
        );
    }

    private function bind( ?string $cart_token ): WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/gecx/v1/cart-session' );
        if ( null !== $cart_token ) {
            $request->set_param( 'cart_token', $cart_token );
        }
        $response = ( new REST_API() )->cart_binding->bind_cart_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        return $response;
    }

    public function test_route_requires_a_rest_nonce(): void {
        ( new REST_API() )->cart_binding->register_cart_session_rest_route();

        $route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/cart-session'] ?? null;
        $this->assertNotNull( $route );
        $this->assertSame( 'POST', $route['methods'] );
        $this->assertSame( [ Session_Attribution::class, 'check_session_permissions' ], $route['permission_callback'] );
    }

    public function test_binds_an_uncookied_guest_to_the_agent_cart(): void {
        $agent_token = $this->generate_jwt( 't_agent_cart_1' );

        $data = $this->bind( $agent_token )->get_data();

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart_1', WC()->session->get_customer_id() );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->session->session_expiration_calls );
        // No CartTokenUtils in this WooCommerce: the agent's own token is
        // still valid for the cart the browser is now bound to.
        $this->assertSame( $agent_token, $data['cart_token'] );
        $this->assertNotSame( '', $data['nonce'] );
        $this->assertSame( 0, $data['moved_items'] );
        $this->assertSame( [], WC()->cart->added );
    }

    public function test_gives_an_uncookied_guest_without_an_agent_cart_a_new_session(): void {
        $data = $this->bind( null )->get_data();

        $this->assertSame( 'new', $data['binding'] );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
    }

    public function test_moves_the_agent_cart_into_the_cart_a_cookied_guest_already_has(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_browser_cart||12345||12345||hash';
        WC()->session->set_customer_id( 't_browser_cart' );
        $this->given_session_with_cart(
            't_agent_cart_2',
            [
                'a' => [ 'product_id' => 17510, 'variation_id' => 0, 'variation' => [], 'quantity' => 1 ],
                'b' => [ 'product_id' => 72, 'variation_id' => 73, 'variation' => [ 'attribute_size' => 'L' ], 'quantity' => 2 ],
            ]
        );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart_2' ) )->get_data();

        $this->assertSame( 'existing', $data['binding'] );
        $this->assertSame( 2, $data['moved_items'] );
        $this->assertSame( 't_browser_cart', WC()->session->get_customer_id() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame(
            [
                [ 17510, 1, 0, [] ],
                [ 72, 2, 73, [ 'attribute_size' => 'L' ] ],
            ],
            WC()->cart->added
        );
        $this->assertSame( 1, WC()->cart->calculate_totals_calls );
        $this->assertSame( 1, WC()->session->save_data_calls );
        // Emptied, so a widget that copies the agent's cart into the
        // shopper's finds nothing left to copy.
        $agent_session = maybe_unserialize( $GLOBALS['wpdb']->wc_sessions['t_agent_cart_2'] );
        $this->assertSame( [], maybe_unserialize( $agent_session['cart'] ) );
        $this->assertSame( 'projects/1/locations/global/commerceSessions/s', $agent_session['gecx_session_id'] );
    }

    public function test_counts_only_the_lines_the_cart_accepts(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_browser_cart||12345||12345||hash';
        WC()->session->set_customer_id( 't_browser_cart' );
        WC()->cart->refused_product_ids = [ 5 ];
        $this->given_session_with_cart(
            't_agent_cart_3',
            [
                'a' => [ 'product_id' => 5, 'quantity' => 1 ],
                'b' => [ 'product_id' => 6, 'quantity' => 1 ],
            ]
        );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart_3' ) )->get_data();

        $this->assertSame( 1, $data['moved_items'] );
    }

    public function test_leaves_a_cart_alone_when_the_browser_already_holds_it(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_shared_cart||12345||12345||hash';
        WC()->session->set_customer_id( 't_shared_cart' );
        $this->given_session_with_cart( 't_shared_cart', [ 'a' => [ 'product_id' => 9, 'quantity' => 1 ] ] );

        $data = $this->bind( $this->generate_jwt( 't_shared_cart' ) )->get_data();

        $this->assertSame( 'existing', $data['binding'] );
        $this->assertSame( 0, $data['moved_items'] );
        $this->assertSame( [], WC()->cart->added );
    }

    public function test_moves_the_agent_cart_into_a_logged_in_shopper_cart(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'shopper@example.com', [ 'customer' ] );
        $this->given_session_with_cart( 't_agent_cart_4', [ 'a' => [ 'product_id' => 11, 'quantity' => 1 ] ] );

        $data = $this->bind( $this->generate_jwt( 't_agent_cart_4' ) )->get_data();

        $this->assertSame( 'existing', $data['binding'] );
        $this->assertSame( 1, $data['moved_items'] );
        $this->assertSame( 1, WC()->cart->persistent_cart_updates );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
    }

    public function test_never_binds_a_browser_to_a_registered_user_session(): void {
        $this->given_session_with_cart( '999', [ 'a' => [ 'product_id' => 11, 'quantity' => 1 ] ] );

        $data = $this->bind( $this->generate_jwt( '999' ) )->get_data();

        $this->assertSame( 'new', $data['binding'] );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );
        $this->assertSame( [], WC()->cart->added );
    }

    public function test_ignores_a_forged_or_expired_agent_token(): void {
        $this->given_session_with_cart( 't_victim', [ 'a' => [ 'product_id' => 11, 'quantity' => 1 ] ] );

        $forged = $this->bind( $this->generate_jwt( 't_victim', 3600, 'secret_salt', false ) )->get_data();
        $this->assertSame( 'new', $forged['binding'] );
        $this->assertSame( 't_guest_session_123', WC()->session->get_customer_id() );

        $expired = $this->bind( $this->generate_jwt( 't_victim', -10 ) )->get_data();
        $this->assertSame( 'new', $expired['binding'] );
        $this->assertSame( [], WC()->cart->added );
    }

    public function test_treats_a_forged_session_cookie_as_no_session(): void {
        $_COOKIE['wp_woocommerce_session_test'] = 't_someone_else||12345||12345||forged';

        $data = $this->bind( $this->generate_jwt( 't_agent_cart_5' ) )->get_data();

        $this->assertSame( 'agent', $data['binding'] );
        $this->assertSame( 't_agent_cart_5', WC()->session->get_customer_id() );
    }
}
