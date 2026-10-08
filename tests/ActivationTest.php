<?php
/**
 * Runtime integration test covering plugin activation, route registration, and Store API scoping.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

/**
 * Tests runtime environment, route registration, permissions, and webhook lifecycle.
 */
class ActivationTest extends GECX_TestCase {

	/**
	 * Asserts that all gecx/v1 REST routes are registered.
	 */
	public function test_all_rest_routes_registered(): void {
		$server = rest_get_server();
		$routes = $server->get_routes();

		$expected = [
			'/gecx/v1/session',
			'/gecx/v1/refresh-token',
			'/gecx/v1/auth-context',
			'/gecx/v1/webhooks/order-created',
			'/gecx/v1/link-agent',
			'/gecx/v1/public-key',
		];

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} is not registered." );
		}
	}

	/**
	 * Asserts auth-context refuses an unattributable request.
	 */
	public function test_auth_context_refuses_unattributable_request(): void {
		$request = new \WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
		$server  = rest_get_server();
		$response = $server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Asserts auth-context serves a same-origin request.
	 */
	public function test_auth_context_serves_same_origin_request(): void {
		$request = new \WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertArrayHasKey( 'customer_jwt', $data );
	}

	/**
	 * Asserts auth-context is not served over GET.
	 */
	public function test_auth_context_is_not_served_over_get(): void {
		$request = new \WP_REST_Request( 'GET', '/gecx/v1/auth-context' );
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Asserts link-agent rejects an anonymous caller.
	 */
	public function test_link_agent_rejects_anonymous_caller(): void {
		$request = new \WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
		$request->set_param( 'agent_name', 'projects/123/locations/global/agents/456' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * Asserts public-key rejects an anonymous caller.
	 */
	public function test_public_key_rejects_anonymous_caller(): void {
		$request = new \WP_REST_Request( 'GET', '/gecx/v1/public-key' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * Asserts Cart-Token authentication hooks and Store API scoping.
	 */
	public function test_cart_token_authentication_hooks_and_store_api_scoping(): void {
		$this->assertTrue( class_exists( \Google\Gemini_Enterprise_For_CX\Auth::class ) );

		$auth = ( new \ReflectionClass( \Google\Gemini_Enterprise_For_CX\Auth::class ) )->newInstanceWithoutConstructor();
		$state = new \ReflectionProperty( \Google\Gemini_Enterprise_For_CX\Auth::class, 'authenticated_via_cart_token' );
		if ( PHP_VERSION_ID < 80100 ) {
			$state->setAccessible( true );
		}
		$state->setValue( null, true );

		try {
			$blocked = $auth->block_cart_token_off_store_api( null, null, new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );
			$this->assertWPError( $blocked );
			$this->assertSame( 'rest_forbidden', $blocked->get_error_code() );
			$this->assertSame( 403, $blocked->get_error_data()['status'] ?? 0 );

			foreach ( [ '/wc/store/v1/cart', '/wc/store/v1/cart/add-item', '/wc/store/v1/batch' ] as $route ) {
				$allowed = $auth->block_cart_token_off_store_api( null, null, new \WP_REST_Request( 'GET', $route ) );
				$this->assertNotWPError( $allowed, "Route {$route} should be allowed." );
			}

			foreach ( [ '/wc/store/v1/order/1', '/wc/store/v1/checkout' ] as $route ) {
				$refused = $auth->block_cart_token_off_store_api( null, null, new \WP_REST_Request( 'GET', $route ) );
				$this->assertWPError( $refused, "Route {$route} should be refused." );
			}
		} finally {
			$state->setValue( null, false );
		}
	}

	/**
	 * Asserts webhook lifecycle through WooCommerce's real WC_Webhook.
	 */
	public function test_webhook_lifecycle_through_woocommerce(): void {
		if ( ! class_exists( 'WC_Webhook' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$webhook = new \WC_Webhook();
		$webhook->set_name( 'GECX Agent Order Created' );
		$webhook->set_topic( 'order.created' );
		$webhook->set_delivery_url( 'https://example.com/gecx/webhook' );
		$webhook->set_status( 'active' );
		$webhook_id = $webhook->save();

		$this->assertGreaterThan( 0, $webhook_id );

		update_option( 'gecx_webhook_id', $webhook_id );

		$stored = new \WC_Webhook( $webhook_id );
		$this->assertSame( 'GECX Agent Order Created', $stored->get_name() );
		$this->assertSame( 'order.created', $stored->get_topic() );
		$this->assertSame( 'active', $stored->get_status() );

		// Clean up
		$webhook->delete( true );
	}
}
