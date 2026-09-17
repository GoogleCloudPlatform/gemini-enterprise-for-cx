<?php
/**
 * REST API Test Suite for Gemini Enterprise for Customer Experience (GECX)
 *
 * @package GECX
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-rest-api.php';

class RestApiTest extends GECX_TestCase {

    use GECX_CartTokenMinting;

    /**
     * The link endpoint is how the authoritative record in Google Cloud reaches
     * WordPress, and it is the only writer of gecx_agent_name.
     */
    public function test_link_handler_stores_agent_and_token_broker(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'agent_name', 'projects/123/locations/global/agents/agent-456' );
        $request->set_param( 'token_broker_name', 'projects/123/locations/global/tokenBrokers/tb-456' );

        $response = $rest_api->link_agent_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'projects/123/locations/global/agents/agent-456', get_option( 'gecx_agent_name' ) );
        $this->assertSame( 'projects/123/locations/global/tokenBrokers/tb-456', get_option( 'gecx_token_broker_name' ) );
        $this->assertSame( 1, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_link_handler_leaves_token_broker_alone_when_absent(): void {
        update_option( 'gecx_token_broker_name', 'projects/123/locations/global/tokenBrokers/existing' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'agent_name', 'projects/123/locations/global/agents/agent-456' );

        $rest_api->link_agent_handler( $request );

        $this->assertSame( 'projects/123/locations/global/tokenBrokers/existing', get_option( 'gecx_token_broker_name' ) );
    }

    public function test_link_handler_rejects_missing_agent_name(): void {
        $rest_api = new GECX_Rest_API();
        $response = $rest_api->link_agent_handler( new WP_REST_Request() );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 400, $response->get_error_data()['status'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_link_handler_rejects_malformed_agent_name(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'agent_name', 'projects/123/agents/<script>alert(1)</script>' );

        $response = $rest_api->link_agent_handler( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'gecx_invalid_agent_name', $response->get_error_code() );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    /**
     * A malformed token broker must not be written, and must not leave a
     * half-applied link behind either.
     */
    public function test_link_handler_rejects_malformed_token_broker_without_writing_agent(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'agent_name', 'projects/123/locations/global/agents/agent-456' );
        $request->set_param( 'token_broker_name', 'not a valid name!' );

        $response = $rest_api->link_agent_handler( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'gecx_invalid_token_broker', $response->get_error_code() );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    /**
     * Without this the backend's Basic Auth call would not be recognised as a
     * WooCommerce API request and the endpoint would reject it.
     */
    public function test_link_route_accepts_woocommerce_key_auth_pretty_permalink(): void {
        $rest_api               = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/link-agent';

        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_link_route_accepts_woocommerce_key_auth_plain_permalink(): void {
        $rest_api               = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Flink-agent';

        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * A cart token authenticates a shopper, so it must never satisfy the admin
     * permission gate even when the resolved user would otherwise qualify.
     */
    public function test_admin_permissions_rejects_cart_token_authenticated_request(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $GLOBALS['gecx_test_users']        = [ 42 => new WP_User( 42, 'shopper@example.com', [ 'customer' ] ) ];
        $_SERVER['REQUEST_URI']            = '/wp-json/wc/store/v1/cart';

        // Authenticate a non-privileged shopper via cart token to raise the flag.
        $auth = new GECX_Auth();

        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 42 );
        $this->assertSame( 42, $auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( GECX_Auth::is_cart_token_request() );

        $rest_api = new GECX_Rest_API();
        $perm     = $rest_api->check_admin_permissions( new WP_REST_Request() );

        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_logged_in_without_nonce_fails_csrf(): void {
        $GLOBALS['gecx_test_current_user']          = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_COOKIE['wordpress_logged_in_test']       = 'cookie_session_val';
        $rest_api                                   = new GECX_Rest_API();
        $request                                    = new WP_REST_Request();

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_cookie_with_valid_nonce_succeeds(): void {
        $GLOBALS['gecx_test_current_user']    = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_COOKIE['wordpress_logged_in_test'] = 'cookie_session_val';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $this->assertSame( true, $rest_api->check_admin_permissions( $request ) );
    }

    public function test_secret_route_check_admin_permissions_invalid_nonce_fails_without_cookie(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'forged' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_custom_cookie_prefix_fails_csrf_without_nonce(): void {
        $GLOBALS['gecx_test_current_user']          = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_COOKIE['wordpress_logged_in_custom_hash'] = 'cookie_session_val';
        $rest_api                                   = new GECX_Rest_API();
        $request                                    = new WP_REST_Request();
        // Attacker adds dummy consumer_key parameter; cookie presence still requires nonce
        $request->set_param( 'consumer_key', 'dummy_key' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_api_key_auth_succeeds_without_nonce(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();
        $request->set_header( 'Authorization', 'Basic Y2tfMTIzOmNzXzQ1Ng==' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertSame( true, $perm );

        // Also succeeds with consumer_key param
        $param_request = new WP_REST_Request();
        $param_request->set_param( 'consumer_key', 'ck_12345' );
        $perm_param = $rest_api->check_admin_permissions( $param_request );
        $this->assertSame( true, $perm_param );
    }

    public function test_session_route_check_session_permissions_success(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $perm = $rest_api->check_session_permissions( $request );
        $this->assertSame( true, $perm );
    }

    public function test_session_route_check_session_permissions_invalid_nonce(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'invalid_nonce' );

        $perm = $rest_api->check_session_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_success(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertSame( true, $perm );
    }

    public function test_secret_route_check_admin_permissions_invalid_nonce(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'invalid_nonce' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_secret_route_check_admin_permissions_non_admin(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'user@example.com', [ 'customer' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_save_secret_handler_persists_option(): void {
        delete_option( 'gecx_api_secret' );
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'secret', 'new_shared_secret_abc123' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( [ 'success' => true ], $response->get_data() );
        $this->assertEquals( 'new_shared_secret_abc123', get_option( 'gecx_api_secret' ) );
    }

    public function test_save_secret_handler_rejects_empty_secret(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'secret', '' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_save_secret_handler_rejects_invalid_characters(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'secret', 'invalid secret with spaces <script>' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_save_secret_handler_accepts_base64_symbols(): void {
        $rest_api            = new GECX_Rest_API();
        $request             = new WP_REST_Request();
        $valid_base64_secret = 'aB3+/=_--validBase64Token==';
        $request->set_param( 'secret', $valid_base64_secret );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( $valid_base64_secret, get_option( 'gecx_api_secret' ) );
    }

    public function test_save_secret_handler_does_not_autoload_the_secret(): void {
        delete_option( 'gecx_api_secret' );
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'secret', 'new_shared_secret_abc123' );

        $rest_api->save_secret_handler( $request );

        $this->assertEquals( 'no', $GLOBALS['gecx_test_option_autoload']['gecx_api_secret'] ?? null );
    }

    public function test_save_secret_handler_rejects_over_length_secret(): void {
        delete_option( 'gecx_api_secret' );
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'secret', str_repeat( 'a', 513 ) );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
    }

    public function test_save_secret_handler_accepts_secret_at_the_length_bound(): void {
        delete_option( 'gecx_api_secret' );
        $rest_api   = new GECX_Rest_API();
        $request    = new WP_REST_Request();
        $max_secret = str_repeat( 'a', 512 );
        $request->set_param( 'secret', $max_secret );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( $max_secret, get_option( 'gecx_api_secret' ) );
    }

    public function test_save_secret_handler_rejects_over_length_consumer_secret(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', str_repeat( 'c', 513 ) );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_order_created_webhooks_handler_rejects_over_length_consumer_secret(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', str_repeat( 'c', 513 ) );

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_save_secret_handler_registers_webhook_with_consumer_secret(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_test_consumer_secret_12345' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertFalse( get_option( 'gecx_consumer_secret' ) );

        $webhook_id = get_option( 'gecx_webhook_id' );
        $this->assertNotNull( $webhook_id );
        $this->assertTrue( (int) $webhook_id > 0 );
        $this->assertArrayHasKey( $webhook_id, $GLOBALS['gecx_test_webhooks'] );
        $webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'order.created', $webhook['topic'] );
        $this->assertEquals( 'cs_test_consumer_secret_12345', $webhook['secret'] );
        $this->assertEquals( 'active', $webhook['status'] );
    }

    public function test_save_secret_handler_prefers_consumer_secret_over_legacy_secret(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_preferred_secret' );
        $request->set_param( 'secret', 'legacy_api_secret' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertFalse( get_option( 'gecx_consumer_secret' ) );
        $this->assertEquals( 'legacy_api_secret', get_option( 'gecx_api_secret' ) );

        $webhook_id = get_option( 'gecx_webhook_id' );
        $this->assertNotNull( $webhook_id );
        $webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'cs_preferred_secret', $webhook['secret'] );
    }

    public function test_save_secret_handler_updates_existing_webhook_secret(): void {
        $existing_webhook = new WC_Webhook();
        $existing_webhook->set_name( 'GECX Agent Order Created' );
        $existing_webhook->set_topic( 'order.created' );
        $existing_webhook->set_delivery_url( 'https://gecx.cloud.google.com/woocommerce/webhook' );
        $existing_webhook->set_secret( 'old_legacy_secret' );
        $existing_webhook->set_status( 'active' );
        $webhook_id = $existing_webhook->save();
        update_option( 'gecx_webhook_id', $webhook_id );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'new_consumer_secret_updated' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( $webhook_id, get_option( 'gecx_webhook_id' ) );
        $updated_webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'new_consumer_secret_updated', $updated_webhook['secret'] );
    }

    public function test_get_webhook_secret_helper_fallback(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $this->assertEquals( '', GECX_Rest_API::get_webhook_secret() );

        update_option( 'gecx_api_secret', 'legacy_secret' );
        $this->assertEquals( 'legacy_secret', GECX_Rest_API::get_webhook_secret() );

        $webhook = new WC_Webhook();
        $webhook->set_secret( 'webhook_consumer_secret' );
        $webhook_id = $webhook->save();
        update_option( 'gecx_webhook_id', $webhook_id );
        $this->assertEquals( 'webhook_consumer_secret', GECX_Rest_API::get_webhook_secret() );
    }

    public function test_ensure_order_webhook_woocommerce_not_active_returns_error(): void {
        $GLOBALS['gecx_test_disable_wc_webhook'] = true;
        $res = GECX_Rest_API::ensure_order_webhook( 'https://example.com/webhook', 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'woocommerce_not_active', $res->get_error_code() );
        $this->assertEquals( 500, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_missing_secret_returns_error(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $res = GECX_Rest_API::ensure_order_webhook( 'https://example.com/webhook', '' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'missing_secret', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_rejects_non_https_url(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $res = GECX_Rest_API::ensure_order_webhook( 'http://insecure-site.com/webhook', 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'invalid_delivery_url', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_rejects_malformed_url(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $res = GECX_Rest_API::ensure_order_webhook( 'javascript:alert(1)', 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'invalid_delivery_url', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    public function test_save_session_handler_rejects_invalid_characters_and_length(): void {
        $rest_api = new GECX_Rest_API();

        // Test invalid characters
        $request1 = new WP_REST_Request();
        $request1->set_param( 'session_id', 'sess<script>alert(1)</script>' );
        $res1 = $rest_api->save_session_handler( $request1 );
        $this->assertTrue( $res1 instanceof WP_Error );
        $this->assertEquals( 'invalid_session_id', $res1->get_error_code() );
        $this->assertEquals( 400, $res1->get_error_data()['status'] );

        // Test excessive length (>256)
        $request2 = new WP_REST_Request();
        $request2->set_param( 'session_id', str_repeat( 'a', 300 ) );
        $res2 = $rest_api->save_session_handler( $request2 );
        $this->assertTrue( $res2 instanceof WP_Error );
        $this->assertEquals( 'invalid_session_id', $res2->get_error_code() );
        $this->assertEquals( 400, $res2->get_error_data()['status'] );

        // Test missing session ID
        $request3 = new WP_REST_Request();
        $request3->set_param( 'session_id', '' );
        $res3 = $rest_api->save_session_handler( $request3 );
        $this->assertTrue( $res3 instanceof WP_Error );
        $this->assertEquals( 'missing_session_id', $res3->get_error_code() );
        $this->assertEquals( 400, $res3->get_error_data()['status'] );

        // Test path traversal attempt
        $request4 = new WP_REST_Request();
        $request4->set_param( 'session_id', 'projects/../../locations/global/commerceSessions/abc' );
        $res4 = $rest_api->save_session_handler( $request4 );
        $this->assertTrue( $res4 instanceof WP_Error );
        $this->assertEquals( 'invalid_session_id', $res4->get_error_code() );
        $this->assertEquals( 400, $res4->get_error_data()['status'] );

        // Test unconstrained slashes / invalid structure
        $request5 = new WP_REST_Request();
        $request5->set_param( 'session_id', 'foo/bar/baz' );
        $res5 = $rest_api->save_session_handler( $request5 );
        $this->assertTrue( $res5 instanceof WP_Error );
        $this->assertEquals( 'invalid_session_id', $res5->get_error_code() );
        $this->assertEquals( 400, $res5->get_error_data()['status'] );
    }

    public function test_save_session_handler_accepts_resource_name_format_with_slashes(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $session_id = 'projects/380470877508/locations/global/commerceSessions/8HKwcSo3FGLhndGcdue72VapwOt6iVW4up9c1ma7e1Y';
        $request->set_param( 'session_id', $session_id );

        $res = $rest_api->save_session_handler( $request );
        $this->assertTrue( $res instanceof WP_REST_Response );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertEquals( [ 'success' => true ], $res->get_data() );

        if ( function_exists( 'WC' ) && WC()->session ) {
            $this->assertEquals( $session_id, WC()->session->get( 'gecx_session_id' ) );
        }

        // Also test plain alphanumeric session token
        $request_plain = new WP_REST_Request();
        $plain_id      = '8HKwcSo3FGLhndGcdue72VapwOt6iVW4up9c1ma7e1Y';
        $request_plain->set_param( 'session_id', $plain_id );
        $res_plain = $rest_api->save_session_handler( $request_plain );
        $this->assertTrue( $res_plain instanceof WP_REST_Response );
        $this->assertEquals( 200, $res_plain->get_status() );
    }

    public function test_ensure_order_webhook_success_creates_and_updates_webhook(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $created = GECX_Rest_API::ensure_order_webhook( 'https://custom.com/webhook', 'cs_created_secret' );
        $this->assertTrue( $created instanceof WC_Webhook );
        $this->assertEquals( 'order.created', $created->get_topic() );
        $this->assertEquals( 'https://custom.com/webhook', $created->get_delivery_url() );
        $this->assertEquals( 'cs_created_secret', $created->get_secret() );
        $this->assertEquals( 'active', $created->get_status() );
        $this->assertEquals( 'wp_api_v3', $created->get_api_version() );
        $created_id = $created->get_id();
        $this->assertEquals( $created_id, get_option( 'gecx_webhook_id' ) );

        // Update existing webhook
        $updated = GECX_Rest_API::ensure_order_webhook( 'https://updated.com/webhook', 'cs_updated_secret' );
        $this->assertTrue( $updated instanceof WC_Webhook );
        $this->assertEquals( $created_id, $updated->get_id() );
        $this->assertEquals( 'https://updated.com/webhook', $updated->get_delivery_url() );
        $this->assertEquals( 'cs_updated_secret', $updated->get_secret() );
    }

    public function test_ensure_order_webhook_deduplicates_existing_webhook_without_saved_option(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        // Create pre-existing webhook directly in WC_Webhook without saving gecx_webhook_id option
        $existing = new WC_Webhook();
        $existing->set_name( 'GECX Agent Order Created' );
        $existing->set_topic( 'order.created' );
        $existing->set_delivery_url( 'https://original.com/webhook' );
        $existing->set_secret( 'cs_original_secret' );
        $existing_id = $existing->save();

        $this->assertEquals( '', get_option( 'gecx_webhook_id', '' ) );

        // Call ensure_order_webhook - should locate existing webhook and update it rather than duplicating
        $result = GECX_Rest_API::ensure_order_webhook( 'https://new-url.com/webhook', 'cs_new_secret' );
        $this->assertTrue( $result instanceof WC_Webhook );
        $this->assertEquals( $existing_id, $result->get_id() );
        $this->assertEquals( $existing_id, get_option( 'gecx_webhook_id' ) );
        $this->assertEquals( 'https://new-url.com/webhook', $result->get_delivery_url() );
        $this->assertEquals( 'cs_new_secret', $result->get_secret() );
        $this->assertCount( 1, $GLOBALS['gecx_test_webhooks'] );
    }

    public function test_save_secret_handler_default_delivery_url(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_default_url_test' );

        $response = $rest_api->save_secret_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );

        $webhook_id = get_option( 'gecx_webhook_id' );
        $this->assertNotNull( $webhook_id );
        $webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/webhook', $webhook['delivery_url'] );
    }

    public function test_order_created_webhooks_handler_with_explicit_consumer_secret(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_explicit_webhooks_test_123' );

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertNotNull( $data['webhook_id'] );
        $this->assertFalse( get_option( 'gecx_consumer_secret' ) );

        $webhook = $GLOBALS['gecx_test_webhooks'][ $data['webhook_id'] ];
        $this->assertEquals( 'order.created', $webhook['topic'] );
        $this->assertEquals( 'cs_explicit_webhooks_test_123', $webhook['secret'] );
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/webhook', $webhook['delivery_url'] );
    }

    public function test_order_created_webhooks_handler_with_existing_secret(): void {
        delete_option( 'gecx_api_secret' );
        $existing_webhook = new WC_Webhook();
        $existing_webhook->set_secret( 'cs_preexisting_secret' );
        $webhook_id = $existing_webhook->save();
        update_option( 'gecx_webhook_id', $webhook_id );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );

        $webhook = $GLOBALS['gecx_test_webhooks'][ $data['webhook_id'] ];
        $this->assertEquals( 'cs_preexisting_secret', $webhook['secret'] );
    }

    public function test_order_created_webhooks_handler_missing_secret_returns_400(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'missing_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_enable_wc_auth_for_custom_endpoints_returns_true_for_custom_paths(): void {
        $rest_api = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/secret';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/secret/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/webhooks/order-created';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/webhooks/order-created/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fsecret';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/secret/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fwebhooks%2Forder-created';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/webhooks/order-created/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fpublic-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/public-key/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=///gecx/v1/secret';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=gecx/v1/secret';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_enable_wc_auth_for_custom_endpoints_returns_original_for_other_paths(): void {
        $rest_api = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/session';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( true ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // False positives: query parameter on unrelated endpoint.
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?x=gecx/v1/secret';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // False positives: hypothetical subpath.
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/secret-rotate';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fsecret-rotate';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_enable_wc_auth_for_custom_endpoints_empty_uri(): void {
        $rest_api = new GECX_Rest_API();
        unset( $_SERVER['REQUEST_URI'] );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( true ) );
    }

    /**
     * The route WordPress resolved wins over anything the request path says.
     */
    public function test_enable_wc_auth_honours_resolved_route_over_path(): void {
        $rest_api = new GECX_Rest_API();

        $GLOBALS['wp']                          = new stdClass();
        $GLOBALS['wp']->query_vars              = [ 'rest_route' => '/wp/v2/users' ];
        $_SERVER['REQUEST_URI']                 = '/wp-json/gecx/v1/secret';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $GLOBALS['wp']->query_vars['rest_route'] = '/gecx/v1/secret';
        $_SERVER['REQUEST_URI']                  = '/wp-json/wp/v2/users';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * A rest_route parameter redirects dispatch away from the path, so a path
     * naming an authenticated route must not enable key authentication for the
     * core route that actually runs.
     */
    public function test_enable_wc_auth_refuses_rest_route_spoofing_an_authenticated_path(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/gecx/v1/secret?rest_route=/wp/v2/users';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/secret';
        $_GET['rest_route']     = '/wp/v2/users';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_GET = [];
        $_POST['rest_route'] = '/wp/v2/users';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * The old path match was unanchored, so any path ending in one of these
     * route names satisfied it whether or not it was a REST request.
     */
    public function test_enable_wc_auth_refuses_authenticated_route_name_outside_the_rest_prefix(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/anything/gecx/v1/secret';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // A planted second prefix resolves to a route that is not on the list.
        $_SERVER['REQUEST_URI'] = '/wp-json/x/wp-json/gecx/v1/secret';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * Off the front controller no REST route is dispatched at all, so a
     * rest_route parameter there names nothing.
     */
    public function test_enable_wc_auth_ignores_rest_route_on_admin_ajax(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?rest_route=/gecx/v1/secret';
        $_GET['rest_route']     = '/gecx/v1/secret';

        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // A route WordPress has already resolved is honoured whatever the
        // entry point, because that is what it is going to dispatch.
        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => '/gecx/v1/secret' ];
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * rest_route[]= arrives as an array, which cannot name a route.
     */
    public function test_enable_wc_auth_refuses_array_valued_rest_route(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route[]=/gecx/v1/secret';
        $_GET['rest_route']     = [ '/gecx/v1/secret' ];

        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_enable_wc_auth_handles_subdirectory_install_and_renamed_prefix(): void {
        $rest_api = new GECX_Rest_API();

        $GLOBALS['gecx_test_home_url'] = 'https://example.com/shop';
        $_SERVER['REQUEST_URI']        = '/shop/wp-json/gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $GLOBALS['gecx_test_home_url']        = 'https://example.com';
        $GLOBALS['gecx_test_rest_url_prefix'] = 'api';
        $_SERVER['REQUEST_URI']               = '/api/gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // The old prefix is no longer a REST prefix on this site.
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_refresh_token_handler_success(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'customer@example.com', [ 'customer' ] );
        $secret                            = 'test_shared_secret_123';
        update_option( 'gecx_api_secret', $secret );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $response = $rest_api->refresh_token_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertFalse( empty( $data['customer_jwt'] ) );
        $pub_key = GECX_Auth::get_public_key();
        $this->assertTrue( $this->verify_rs256_jwt( $data['customer_jwt'], $pub_key ) );
    }

    public function test_refresh_token_handler_logged_out_returns_guest_jwt(): void {
        $GLOBALS['gecx_test_current_user'] = null;
        $secret                            = 'test_shared_secret_123';
        update_option( 'gecx_api_secret', $secret );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $response = $rest_api->refresh_token_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertFalse( empty( $data['customer_jwt'] ) );
        $pub_key = GECX_Auth::get_public_key();
        $this->assertTrue( $this->verify_rs256_jwt( $data['customer_jwt'], $pub_key ) );
        $payload = $this->decode_jwt_payload( $data['customer_jwt'] );
        $this->assertEquals( 0, $payload['user_id'] );
    }

    public function test_refresh_token_handler_no_secret_returns_500(): void {
        $GLOBALS['gecx_test_current_user'] = null;
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        $GLOBALS['gecx_test_wp_salt'] = '';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', 'valid_nonce_wp_rest' );

        $response = $rest_api->refresh_token_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'jwt_generation_failed', $response->get_error_code() );
        $this->assertEquals( 500, $response->get_error_data()['status'] );
    }

    public function test_get_public_key_handler_success(): void {
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->get_public_key_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( isset( $data['public_key'] ) );
        $this->assertStringContainsString( 'BEGIN PUBLIC KEY', $data['public_key'] );
    }

    public function test_get_public_key_handler_failure_returns_500(): void {
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        $GLOBALS['gecx_test_wp_salt'] = '';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->get_public_key_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'rest_cannot_retrieve_key', $response->get_error_code() );
        $this->assertEquals( 500, $response->get_error_data()['status'] );
    }

    public function test_public_key_route_permission_callback_admin_success(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertSame( true, $perm );
    }

    public function test_public_key_route_permission_callback_non_admin_failure(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'customer@example.com', [ 'customer' ] );
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }

    public function test_public_key_route_permission_callback_unauthenticated_failure(): void {
        $GLOBALS['gecx_test_current_user'] = null;
        $rest_api                          = new GECX_Rest_API();
        $request                           = new WP_REST_Request();

        $perm = $rest_api->check_admin_permissions( $request );
        $this->assertTrue( $perm instanceof WP_Error );
        $this->assertEquals( 'rest_forbidden', $perm->get_error_code() );
        $this->assertEquals( 403, $perm->get_error_data()['status'] );
    }
}

if ( php_sapi_name() === 'cli' ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $argv0 = isset( $_SERVER['argv'][0] ) ? sanitize_text_field( wp_unslash( $_SERVER['argv'][0] ) ) : '';
    if ( empty( $argv0 ) || basename( $argv0 ) === basename( __FILE__ ) ) {
        gecx_run_test_class( RestApiTest::class );
    }
}
