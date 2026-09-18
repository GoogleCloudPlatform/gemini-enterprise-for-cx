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

    public function test_order_created_webhooks_handler_rejects_invalid_characters(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'invalid secret with spaces <script>' );

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'invalid_secret', $response->get_error_code() );
        $this->assertEquals( 400, $response->get_error_data()['status'] );
    }

    public function test_order_created_webhooks_handler_accepts_consumer_secret_at_the_length_bound(): void {
        delete_option( 'gecx_webhook_id' );
        $rest_api   = new GECX_Rest_API();
        $request    = new WP_REST_Request();
        $max_secret = str_repeat( 'a', 512 );
        $request->set_param( 'consumer_secret', $max_secret );

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $webhook = $GLOBALS['gecx_test_webhooks'][ $response->get_data()['webhook_id'] ];
        $this->assertEquals( $max_secret, $webhook['secret'] );
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

    public function test_order_created_webhooks_handler_registers_webhook_with_consumer_secret(): void {
        delete_option( 'gecx_webhook_id' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_test_consumer_secret_12345' );

        $response = $rest_api->order_created_webhooks_handler( $request );
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

    public function test_order_created_webhooks_handler_ignores_legacy_shared_secret(): void {
        delete_option( 'gecx_webhook_id' );
        // A store upgraded from a version that stored the shared secret must not
        // have that value resurrected as the webhook's signing key.
        update_option( 'gecx_api_secret', 'legacy_api_secret' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_preferred_secret' );

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );

        $webhook_id = get_option( 'gecx_webhook_id' );
        $this->assertNotNull( $webhook_id );
        $webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'cs_preferred_secret', $webhook['secret'] );

        delete_option( 'gecx_api_secret' );
    }

    public function test_order_created_webhooks_handler_updates_existing_webhook_secret(): void {
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

        $response = $rest_api->order_created_webhooks_handler( $request );
        $this->assertTrue( $response instanceof WP_REST_Response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( $webhook_id, get_option( 'gecx_webhook_id' ) );
        $updated_webhook = $GLOBALS['gecx_test_webhooks'][ $webhook_id ];
        $this->assertEquals( 'new_consumer_secret_updated', $updated_webhook['secret'] );
    }

    public function test_get_webhook_secret_helper_reads_webhook_only(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $this->assertEquals( '', GECX_Rest_API::get_webhook_secret() );

        // The retired shared secret is never consulted, even when still present.
        update_option( 'gecx_api_secret', 'legacy_secret' );
        $this->assertEquals( '', GECX_Rest_API::get_webhook_secret() );
        delete_option( 'gecx_api_secret' );

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
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/webhooks/order-created';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/webhooks/order-created/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fwebhooks%2Forder-created';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/webhooks/order-created/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fpublic-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/public-key/';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=///gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=gecx/v1/public-key';
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
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?x=gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // False positives: hypothetical subpath.
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key-rotate';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fpublic-key-rotate';
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
        $_SERVER['REQUEST_URI']                 = '/wp-json/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $GLOBALS['wp']->query_vars['rest_route'] = '/gecx/v1/public-key';
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

        $_SERVER['REQUEST_URI'] = '/gecx/v1/public-key?rest_route=/wp/v2/users';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key';
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

        $_SERVER['REQUEST_URI'] = '/anything/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // A planted second prefix resolves to a route that is not on the list.
        $_SERVER['REQUEST_URI'] = '/wp-json/x/wp-json/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * Off the front controller no REST route is dispatched at all, so a
     * rest_route parameter there names nothing.
     */
    public function test_enable_wc_auth_ignores_rest_route_on_admin_ajax(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?rest_route=/gecx/v1/public-key';
        $_GET['rest_route']     = '/gecx/v1/public-key';

        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // A route WordPress has already resolved is honoured whatever the
        // entry point, because that is what it is going to dispatch.
        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => '/gecx/v1/public-key' ];
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * rest_route[]= arrives as an array, which cannot name a route.
     */
    public function test_enable_wc_auth_refuses_array_valued_rest_route(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route[]=/gecx/v1/public-key';
        $_GET['rest_route']     = [ '/gecx/v1/public-key' ];

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

    /**
     * The batch route is not a cart route, so WooCommerce sets no Cart-Token
     * on the batch response. The token reaches the client only inside the
     * body, in each sub-response envelope. Lift it to a header so a caller
     * reading headers sees the same thing on /batch as on /cart.
     */
    public function test_batch_cart_token_is_exposed_as_a_response_header(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ] );

        $result = $rest_api->expose_batch_cart_token_header( $response, null, $request );

        $this->assertSame( 'a.cart.token', $result->get_headers()['Cart-Token'] );
    }

    public function test_batch_cart_token_exposure_leaves_the_body_alone(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $payload  = [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ];
        $response = new WP_REST_Response( $payload );

        $result = $rest_api->expose_batch_cart_token_header( $response, null, $request );

        $this->assertSame( $payload, $result->get_data() );
    }

    /**
     * Sub-requests run in order, so the token the last one reports is the one
     * that describes the session the batch leaves behind.
     */
    public function test_batch_cart_token_exposure_takes_the_last_token(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [ 'status' => 200, 'headers' => [ 'cart-token' => 'first.token' ], 'body' => [] ],
                [ 'status' => 200, 'headers' => [ 'Cart-Token' => 'second.token' ], 'body' => [] ],
            ],
        ] );

        $result = $rest_api->expose_batch_cart_token_header( $response, null, $request );

        $this->assertSame( 'second.token', $result->get_headers()['Cart-Token'] );
    }

    public function test_batch_cart_token_exposure_adds_nothing_when_no_sub_response_carries_one(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [ 'status' => 400, 'headers' => [], 'body' => [ 'code' => 'nope' ] ],
            ],
        ] );

        $result = $rest_api->expose_batch_cart_token_header( $response, null, $request );

        $this->assertArrayNotHasKey( 'Cart-Token', $result->get_headers() );
    }

    /**
     * Cart routes set their own Cart-Token. Nothing to lift, nothing to touch.
     */
    public function test_cart_route_response_is_not_touched_by_batch_token_exposure(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart' );
        $response = new WP_REST_Response( [ 'items_count' => 0 ] );
        $response->header( 'Cart-Token', 'a.cart.token' );

        $result = $rest_api->expose_batch_cart_token_header( $response, null, $request );

        $this->assertSame( [ 'items_count' => 0 ], $result->get_data() );
        $this->assertSame( 'a.cart.token', $result->get_headers()['Cart-Token'] );
    }

    /**
     * The tests above call the callback directly, which proves what it does
     * but not that anything calls it. Go through rest_post_dispatch so a
     * callback that is never registered, or registered under the wrong name,
     * fails here.
     *
     * Only the header is asserted. What else the plugin does to a batch
     * response on this filter is the business of the tests that cover it.
     */
    public function test_batch_cart_token_header_is_set_through_rest_post_dispatch(): void {
        new GECX_Rest_API();
        $request = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ] );

        $result = apply_filters( 'rest_post_dispatch', $response, null, $request );

        $this->assertSame( 'a.cart.token', $result->get_headers()['Cart-Token'] );
    }

    public function test_batch_token_exposure_returns_non_response_values_unchanged(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $error    = new WP_Error( 'woocommerce_rest_batch_error', 'Nope.' );

        $this->assertSame( $error, $rest_api->expose_batch_cart_token_header( $error, null, $request ) );
    }

    /**
     * The cart token is a bearer credential. It belongs in the Cart-Token
     * response header, which WooCommerce sets and exposes through CORS, and
     * nowhere else. A body carrying it reaches wp.data, session-replay tools,
     * HAR files and caches; a header reaches none of those.
     */
    public function test_post_dispatch_does_not_copy_the_cart_token_into_the_cart_body(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart' );
        $response = new WP_REST_Response( [ 'items' => [], 'items_count' => 0 ] );
        $response->header( 'Cart-Token', 'a.cart.token' );

        $result = $rest_api->sync_cart_session_after_dispatch( $response, null, $request );

        $this->assertSame( [ 'items' => [], 'items_count' => 0 ], $result->get_data() );
        $this->assertSame( 'a.cart.token', $result->get_headers()['Cart-Token'] );
    }

    /**
     * The token also arrives on the request, and was copied from there
     * whenever the response header was absent.
     */
    public function test_post_dispatch_does_not_copy_a_request_cart_token_into_the_cart_body(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart' );
        $request->set_header( 'Cart-Token', 'a.cart.token' );
        $response = new WP_REST_Response( [ 'items_count' => 0 ] );

        $result = $rest_api->sync_cart_session_after_dispatch( $response, null, $request );

        $this->assertArrayNotHasKey( 'id', $result->get_data() );
    }

    /**
     * Cart item sub-resources define their own 'id', the product ID. The
     * mirroring overwrote it, corrupting those responses.
     */
    public function test_post_dispatch_leaves_a_cart_item_id_alone(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart/items' );
        $response = new WP_REST_Response( [ 'id' => 99, 'quantity' => 2 ] );
        $response->header( 'Cart-Token', 'a.cart.token' );

        $result = $rest_api->sync_cart_session_after_dispatch( $response, null, $request );

        $this->assertSame( 99, $result->get_data()['id'] );
    }

    public function test_post_dispatch_does_not_copy_the_cart_token_into_batch_sub_responses(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $payload  = [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ];
        $response = new WP_REST_Response( $payload );

        $result = $rest_api->sync_cart_session_after_dispatch( $response, null, $request );

        $this->assertSame( $payload, $result->get_data() );
    }

    /**
     * The filter runs on every dispatch, so it has to hand back whatever it
     * was given, including the errors and non-arrays it cannot read.
     */
    public function test_post_dispatch_returns_non_response_values_unchanged(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart' );
        $error    = new WP_Error( 'woocommerce_rest_cart_error', 'Nope.' );

        $this->assertSame( $error, $rest_api->sync_cart_session_after_dispatch( $error, null, $request ) );
    }
    /**
     * The batch body repeats each sub-response's headers, so the token
     * WooCommerce issued for a cart sub-request is sitting in the payload as
     * data. It is on the response as a header by the time this runs; take the
     * body copy away.
     */
    public function test_batch_body_loses_the_cart_token(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ] );

        $result = $rest_api->strip_cart_token_from_batch_body( $response, null, $request );

        $this->assertSame( [], $result->get_data()['responses'][0]['headers'] );
    }

    /**
     * A sub-response carries headers a client legitimately reads. Only the
     * credential goes.
     */
    public function test_batch_body_keeps_every_other_sub_response_header(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [
                        'Nonce'      => 'a-nonce',
                        'CART-TOKEN' => 'a.cart.token',
                        'Cart-Hash'  => 'a-hash',
                    ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ] );

        $result = $rest_api->strip_cart_token_from_batch_body( $response, null, $request );

        $this->assertSame(
            [ 'Nonce' => 'a-nonce', 'Cart-Hash' => 'a-hash' ],
            $result->get_data()['responses'][0]['headers']
        );
    }

    public function test_batch_body_stripping_leaves_sub_response_bodies_alone(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token' ],
                    'body'    => [ 'items_count' => 1, 'id' => 99 ],
                ],
            ],
        ] );

        $result = $rest_api->strip_cart_token_from_batch_body( $response, null, $request );

        $this->assertSame( [ 'items_count' => 1, 'id' => 99 ], $result->get_data()['responses'][0]['body'] );
        $this->assertSame( 200, $result->get_data()['responses'][0]['status'] );
    }

    public function test_batch_body_stripping_covers_every_sub_response(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [ 'status' => 200, 'headers' => [ 'cart-token' => 'first.token' ], 'body' => [] ],
                [ 'status' => 200, 'headers' => [ 'Cart-Token' => 'second.token' ], 'body' => [] ],
            ],
        ] );

        $result = $rest_api->strip_cart_token_from_batch_body( $response, null, $request );

        $data = $result->get_data();
        $this->assertSame( [], $data['responses'][0]['headers'] );
        $this->assertSame( [], $data['responses'][1]['headers'] );
    }

    /**
     * Cart routes answer with the token in a header and nothing in the body.
     * There is nothing here to strip, and nothing to rewrite.
     */
    public function test_cart_route_body_is_not_touched_by_stripping(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart' );
        $payload  = [ 'items_count' => 0, 'responses' => [ [ 'headers' => [ 'Cart-Token' => 'a.cart.token' ] ] ] ];
        $response = new WP_REST_Response( $payload );

        $result = $rest_api->strip_cart_token_from_batch_body( $response, null, $request );

        $this->assertSame( $payload, $result->get_data() );
    }

    public function test_batch_body_stripping_returns_non_response_values_unchanged(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $error    = new WP_Error( 'woocommerce_rest_batch_error', 'Nope.' );

        $this->assertSame( $error, $rest_api->strip_cart_token_from_batch_body( $error, null, $request ) );
    }

    /**
     * The two halves have to compose: the token ends up on the response as a
     * header, and nowhere in the body. Dispatching covers the priorities that
     * order them, which calling the callbacks directly does not.
     */
    public function test_dispatching_a_batch_moves_the_cart_token_from_body_to_header(): void {
        new GECX_Rest_API();
        $request = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/batch' );
        $response = new WP_REST_Response( [
            'responses' => [
                [
                    'status'  => 200,
                    'headers' => [ 'Cart-Token' => 'a.cart.token', 'Nonce' => 'a-nonce' ],
                    'body'    => [ 'items_count' => 1 ],
                ],
            ],
        ] );

        $result = apply_filters( 'rest_post_dispatch', $response, null, $request );

        $this->assertSame( 'a.cart.token', $result->get_headers()['Cart-Token'] );
        $this->assertSame( [ 'Nonce' => 'a-nonce' ], $result->get_data()['responses'][0]['headers'] );
        $this->assertStringNotContainsString( 'a.cart.token', wp_json_encode( $result->get_data() ) );
    }

    public function test_auth_context_handler_returns_fresh_nonce_and_customer_jwt_when_logged_in(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 77, 'buyer@shop.test' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->auth_context_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertNotEmpty( $data['nonce'] );
        $this->assertTrue( (bool) wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
        $this->assertNotEmpty( $data['customer_jwt'] );

        $headers = $response->get_headers();
        $this->assertArrayHasKey( 'Cache-Control', $headers );
        $this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
        $this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
        $this->assertStringContainsString( 'private', $headers['Cache-Control'] );
        $this->assertSame( 'Cookie, Origin', $headers['Vary'] );
    }

    public function test_auth_context_handler_restores_cookie_user_when_rest_cookie_check_zeroed_current_user(): void {
        // Simulate WordPress's rest_cookie_check_errors() calling wp_set_current_user( 0 )
        // when GET /wp-json/gecx/v1/auth-context is dispatched without X-WP-Nonce.
        $GLOBALS['gecx_test_current_user']   = null;
        $GLOBALS['gecx_test_cookie_user_id'] = 77;
        $GLOBALS['gecx_test_users'][77]      = new WP_User( 77, 'buyer@shop.test' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->auth_context_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 0, get_current_user_id() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertNotEmpty( $data['nonce'] );
        $this->assertNotEmpty( $data['customer_jwt'] );

        // Verify the JWT was minted for user 77 (not guest user_id=0).
        $payload = $this->decode_jwt_payload( $data['customer_jwt'] );
        $this->assertSame( 77, $payload['user_id'] );
        $this->assertSame( 'buyer@shop.test', $payload['user_email'] );

        // Verify the nonce validates when user 77 is active on subsequent REST requests.
        $GLOBALS['gecx_test_current_user'] = $GLOBALS['gecx_test_users'][77];
        $this->assertTrue( (bool) wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
    }

    public function test_auth_context_permissions_enforces_same_origin_and_rejects_cross_site(): void {
        $rest_api = new GECX_Rest_API();

        // Same-origin request succeeds.
        $same_origin_req = new WP_REST_Request();
        $same_origin_req->set_header( 'Origin', 'https://example.com' );
        $same_origin_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $same_origin_req ) );

        // Cross-site Fetch Metadata is rejected.
        $cross_site_req = new WP_REST_Request();
        $cross_site_req->set_header( 'Sec-Fetch-Site', 'cross-site' );
        $perm = $rest_api->check_auth_context_permissions( $cross_site_req );
        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );

        // Cross-origin Origin header is rejected.
        $cross_origin_req = new WP_REST_Request();
        $cross_origin_req->set_header( 'Origin', 'https://evil.example.org' );
        $perm = $rest_api->check_auth_context_permissions( $cross_origin_req );
        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );

        // Cross-origin Referer header (without Origin) is rejected.
        $cross_referer_req = new WP_REST_Request();
        $cross_referer_req->set_header( 'Referer', 'https://evil.example.org/attack.html' );
        $perm = $rest_api->check_auth_context_permissions( $cross_referer_req );
        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_auth_context_handler_returns_guest_jwt_for_unauthenticated_user(): void {
        $GLOBALS['gecx_test_current_user'] = null;

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->auth_context_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertNotEmpty( $data['nonce'] );
        $this->assertNotEmpty( $data['customer_jwt'] );
    }

    public function test_auth_context_handler_returns_null_customer_jwt_when_no_secret(): void {
        $GLOBALS['gecx_test_current_user'] = null;
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        $GLOBALS['gecx_test_wp_salt'] = '';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $response = $rest_api->auth_context_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertNotEmpty( $data['nonce'] );
        $this->assertNull( $data['customer_jwt'] );
    }
}

if ( php_sapi_name() === 'cli' ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $argv0 = isset( $_SERVER['argv'][0] ) ? sanitize_text_field( wp_unslash( $_SERVER['argv'][0] ) ) : '';
    if ( empty( $argv0 ) || basename( $argv0 ) === basename( __FILE__ ) ) {
        gecx_run_test_class( RestApiTest::class );
    }
}
