<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
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
     * Puts WordPress in the state it reaches once WP::parse_request() has
     * resolved a REST route.
     *
     * Both permalink styles arrive here: the rewrite rule and a ?rest_route=
     * parameter both end up in query_vars, because rest_route is a registered
     * public query var. Nothing else names a route the plugin will act on.
     *
     * @param mixed $route Route as WordPress resolved it.
     */
    private function given_wordpress_resolved_route( $route ): void {
        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => $route ];
    }

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
     * Regression guard for the same class of bug as
     * test_order_created_webhooks_handler_rejects_percent_encoded_consumer_secret:
     * sanitize_text_field() ahead of RESOURCE_NAME_PATTERN would delete the
     * "%2f" and leave "projects/123/agentsagent-456", a name that matches the
     * pattern and would be written to gecx_agent_name.
     */
    public function test_link_handler_rejects_percent_encoded_agent_name(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'agent_name', 'projects/123/agents%2fagent-456' );

        $response = $rest_api->link_agent_handler( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'gecx_invalid_agent_name', $response->get_error_code() );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    /**
     * Without this the backend's Basic Auth call would not be recognised as a
     * WooCommerce API request and the endpoint would reject it.
     *
     * Both permalink styles are accepted because the widening reads the route
     * WordPress resolved, and both styles produce the same one.
     */
    public function test_link_route_accepts_woocommerce_key_auth_pretty_permalink(): void {
        $rest_api               = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/link-agent';
        $this->given_wordpress_resolved_route( '/gecx/v1/link-agent' );

        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_link_route_accepts_woocommerce_key_auth_plain_permalink(): void {
        $rest_api               = new GECX_Rest_API();
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Flink-agent';
        $this->given_wordpress_resolved_route( '/gecx/v1/link-agent' );

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
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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

    /**
     * Regression guard. The consumer_secret becomes the HMAC key WooCommerce
     * signs every order delivery with, so it has to be validated exactly as
     * sent. Running sanitize_text_field() first strips the "%ab" and leaves
     * "cs_12345678", which passes is_valid_secret() and gets stored, turning a
     * 400 at registration into a signature mismatch on every later delivery.
     *
     * This only fails if the sanitize call comes back, and only because the
     * bootstrap stub reproduces core's percent-octet loop. See
     * SanitizeStubTest.
     */
    public function test_order_created_webhooks_handler_rejects_percent_encoded_consumer_secret(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'consumer_secret', 'cs_1234%ab5678' );

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
        $res = GECX_Rest_API::ensure_order_webhook( 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'woocommerce_not_active', $res->get_error_code() );
        $this->assertEquals( 500, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_missing_secret_returns_error(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        $res = GECX_Rest_API::ensure_order_webhook( '' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'missing_secret', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_refuses_non_https_console_url(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        update_option( 'gecx_console_base_url', 'http://gecx.cloud.google.com' );
        $res = GECX_Rest_API::ensure_order_webhook( 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'console_url_refused', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    public function test_ensure_order_webhook_refuses_malformed_console_url(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        update_option( 'gecx_console_base_url', 'javascript:alert(1)' );
        $res = GECX_Rest_API::ensure_order_webhook( 'cs_test_secret' );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'console_url_refused', $res->get_error_code() );
        $this->assertEquals( 400, $res->get_error_data()['status'] );
    }

    /**
     * Regression guard. sanitize_text_field() ahead of is_valid_session_id()
     * would strip the "%2f" and leave "sessabcdef", which passes the
     * allowlist and would be written into the WooCommerce customer session as
     * if the caller had sent it.
     */
    public function test_save_session_handler_rejects_percent_encoded_session_id(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_param( 'session_id', 'sess%2fabcdef' );

        $res = $rest_api->save_session_handler( $request );
        $this->assertTrue( $res instanceof WP_Error );
        $this->assertEquals( 'invalid_session_id', $res->get_error_code() );
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
        $session_id = 'projects/123456789012/locations/global/commerceSessions/TestSessionToken0123456789abcdefghijklmnopq';
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
        $plain_id      = 'TestSessionToken0123456789abcdefghijklmnopq';
        $request_plain->set_param( 'session_id', $plain_id );
        $res_plain = $rest_api->save_session_handler( $request_plain );
        $this->assertTrue( $res_plain instanceof WP_REST_Response );
        $this->assertEquals( 200, $res_plain->get_status() );
    }

    public function test_ensure_order_webhook_success_creates_and_updates_webhook(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );

        $created = GECX_Rest_API::ensure_order_webhook( 'cs_created_secret' );
        $this->assertTrue( $created instanceof WC_Webhook );
        $this->assertEquals( 'order.created', $created->get_topic() );
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/webhook', $created->get_delivery_url() );
        $this->assertEquals( 'cs_created_secret', $created->get_secret() );
        $this->assertEquals( 'active', $created->get_status() );
        $this->assertEquals( 'wp_api_v3', $created->get_api_version() );
        $created_id = $created->get_id();
        $this->assertEquals( $created_id, get_option( 'gecx_webhook_id' ) );

        // Update existing webhook
        $updated = GECX_Rest_API::ensure_order_webhook( 'cs_updated_secret' );
        $this->assertTrue( $updated instanceof WC_Webhook );
        $this->assertEquals( $created_id, $updated->get_id() );
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/webhook', $updated->get_delivery_url() );
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
        $result = GECX_Rest_API::ensure_order_webhook( 'cs_new_secret' );
        $this->assertTrue( $result instanceof WC_Webhook );
        $this->assertEquals( $existing_id, $result->get_id() );
        $this->assertEquals( $existing_id, get_option( 'gecx_webhook_id' ) );
        // A stale delivery URL is rewritten to the console webhook URL.
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/webhook', $result->get_delivery_url() );
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

    public function test_enable_wc_auth_for_custom_endpoints_returns_true_for_resolved_custom_routes(): void {
        $rest_api = new GECX_Rest_API();

        $routes = [
            '/gecx/v1/webhooks/order-created',
            '/gecx/v1/webhooks/order-created/',
            '/gecx/v1/public-key',
            '/gecx/v1/public-key/',
            // WordPress hands the route over with whatever leading slashes the
            // request carried, so the match has to normalise them.
            '///gecx/v1/public-key',
            'gecx/v1/public-key',
        ];

        foreach ( $routes as $route ) {
            $this->given_wordpress_resolved_route( $route );
            $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ), $route );
        }
    }

    /**
     * The widening runs on 'determine_current_user'. WP::init() resolves the
     * current user immediately before WP::parse_request(), so on a front
     * controller request it always runs before WordPress has resolved a route,
     * and the only thing available to match is what the request says about
     * itself.
     *
     * Matching that is what allowed a key admitted for a plugin route to be
     * spent on a core one, so it must decline instead. WooCommerce re-offers
     * the key after parse_request() via
     * WC_REST_Authentication::authentication_fallback(), which is where the
     * routes above are matched.
     */
    public function test_enable_wc_auth_declines_before_wordpress_resolves_a_route(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fgecx%2Fv1%2Fpublic-key';
        $_GET['rest_route']     = '/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $_GET                = [];
        $_POST['rest_route'] = '/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // WooCommerce's own answer still passes through untouched.
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( true ) );
    }

    /**
     * An array cannot name a route, whether it arrives resolved or not.
     */
    public function test_enable_wc_auth_refuses_a_resolved_route_that_is_not_a_string(): void {
        $rest_api = new GECX_Rest_API();

        $this->given_wordpress_resolved_route( [ '/gecx/v1/public-key' ] );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_enable_wc_auth_for_custom_endpoints_returns_original_for_other_paths(): void {
        $rest_api = new GECX_Rest_API();
        $this->given_wordpress_resolved_route( '/gecx/v1/session' );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( true ) );

        $this->given_wordpress_resolved_route( '/wp/v2/posts' );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // False positives: hypothetical subpath.
        $this->given_wordpress_resolved_route( '/gecx/v1/public-key-rotate' );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // WordPress resolved no route at all, so nothing is dispatched.
        $this->given_wordpress_resolved_route( '' );
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    /**
     * A double-encoded separator must not collapse into a real route.
     *
     * parse_str() decodes once, leaving the literal "%2F".
     * sanitize_text_field() used to strip that octet, turning
     * "%2Fgecx/v1/public-key" into "gecx/v1/public-key" and enabling
     * WooCommerce key authentication for a route WordPress would never
     * dispatch. The route is matched raw now, so the two agree again.
     */
    public function test_enable_wc_auth_ignores_a_double_encoded_route_separator(): void {
        $rest_api = new GECX_Rest_API();

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%252Fgecx/v1/public-key';
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

    /**
     * Install layout used to decide this, because the widening parsed the
     * request path and had to find the home path and the REST prefix in it. It
     * reads the route WordPress resolved now, which is already relative to
     * both, so neither a subdirectory install nor a renamed prefix changes the
     * answer, and a path alone never produces one.
     */
    public function test_enable_wc_auth_is_independent_of_install_layout(): void {
        $rest_api = new GECX_Rest_API();

        $GLOBALS['gecx_test_home_url'] = 'https://example.com/shop';
        $_SERVER['REQUEST_URI']        = '/shop/wp-json/gecx/v1/public-key';
        $this->assertFalse( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $this->given_wordpress_resolved_route( '/gecx/v1/public-key' );
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        $GLOBALS['gecx_test_home_url']        = 'https://example.com';
        $GLOBALS['gecx_test_rest_url_prefix'] = 'api';
        $_SERVER['REQUEST_URI']               = '/api/gecx/v1/public-key';
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
    }

    public function test_refresh_token_handler_success(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'customer@example.com', [ 'customer' ] );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
        delete_option( 'gecx_keypair' );
        $GLOBALS['gecx_test_wp_salt'] = '';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = $rest_api->refresh_token_handler( $request );
        $this->assertTrue( $response instanceof WP_Error );
        $this->assertEquals( 'jwt_generation_failed', $response->get_error_code() );
        $this->assertEquals( 500, $response->get_error_data()['status'] );
    }

    public function test_get_public_key_handler_success(): void {
        delete_option( 'gecx_keypair' );

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
        delete_option( 'gecx_keypair' );
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

        // Verify the nonce validates when user 77 is active on subsequent REST requests,
        // and that the temporary nonce_user_logged_out filter was removed.
        $this->assertSame( [], $GLOBALS['gecx_test_filter_callbacks']['nonce_user_logged_out'] ?? [] );
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

    public function test_auth_context_permissions_rejects_a_request_carrying_no_origin_signal(): void {
        // No Sec-Fetch-Site, no Origin, no Referer: a cross-origin <script src>
        // under Referrer-Policy: no-referrer looks exactly like this, as does a
        // non-browser client replaying a stolen cookie.
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();

        $perm = $rest_api->check_auth_context_permissions( $request );

        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_auth_context_permissions_rejects_sec_fetch_site_none(): void {
        // "none" is what a browser sends for a user-initiated load with no
        // initiator document, which a POST-only route cannot receive from a
        // browser at all. What is left is a client setting the header itself,
        // so it is refused even when the Origin looks right.
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Sec-Fetch-Site', 'none' );
        $request->set_header( 'Origin', 'https://example.com' );

        $perm = $rest_api->check_auth_context_permissions( $request );

        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_auth_context_permissions_accepts_a_same_origin_referer_without_origin(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Referer', 'https://example.com/shop/product-1' );

        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $request ) );
    }

    public function test_auth_context_permissions_accepts_https_origin_when_home_url_is_http(): void {
        // Behind a TLS-terminating proxy or flexible SSL, home_url() stays http
        // while the browser reports an https Origin. Refusing that pairing
        // would take the widget offline on a healthy store.
        $GLOBALS['gecx_test_home_url'] = 'http://example.com';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://example.com' );
        $request->set_header( 'Sec-Fetch-Site', 'same-origin' );

        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $request ) );
    }

    public function test_auth_context_permissions_accepts_the_site_url_host(): void {
        // WordPress in a subdirectory of the storefront: home_url() and
        // site_url() disagree, and requests legitimately arrive from either.
        $GLOBALS['gecx_test_home_url'] = 'https://shop.example.com';
        $GLOBALS['gecx_test_site_url'] = 'https://wp.example.com';

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://wp.example.com' );

        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $request ) );
    }

    public function test_auth_context_permissions_treats_default_ports_as_equal(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://example.com:443' );

        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $request ) );
    }

    public function test_auth_context_permissions_rejects_a_mismatched_explicit_port(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://example.com:8443' );

        $perm = $rest_api->check_auth_context_permissions( $request );

        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_auth_context_permissions_rejects_a_non_http_origin_scheme(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'chrome-extension://example.com' );

        $perm = $rest_api->check_auth_context_permissions( $request );

        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );
    }

    public function test_auth_context_allowed_origins_are_filterable(): void {
        // Escape hatch for a headless front end or a mapped domain that
        // WordPress itself has no record of.
        add_filter(
            'gecx_auth_context_allowed_origins',
            static function ( $urls ) {
                $urls[] = 'https://headless.example.net';
                return $urls;
            }
        );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://headless.example.net' );

        $this->assertTrue( true === $rest_api->check_auth_context_permissions( $request ) );
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
        delete_option( 'gecx_keypair' );
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

    public function test_auth_context_route_accepts_post_only(): void {
        // The widget posts, so that a CDN told to "cache everything" cannot
        // serve one shopper's nonce and JWT to the next. GET was registered
        // alongside POST only for bundles deployed before that switch, and
        // must not come back.
        $rest_api = new GECX_Rest_API();

        $rest_api->register_auth_context_rest_route();

        $route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/auth-context'] ?? null;
        $this->assertNotEmpty( $route );
        $this->assertSame( 'POST', $route['methods'] );
    }

    public function test_sync_cart_session_ignores_unrelated_rest_routes(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'item_1' => [ 'product_id' => 10, 'quantity' => 1 ] ];

        $rest_api = new GECX_Rest_API();
        foreach ( [ '/wp/v2/posts', '/gecx/v1/auth-context', '/wc/v3/orders' ] as $route ) {
            $request = new WP_REST_Request( 'POST', $route );
            $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'ok' => true ], 200 ), null, $request );
        }

        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 0, WC()->cart->persistent_cart_updates );
        $this->assertSame( [], $wpdb->wc_sessions );
    }

    public function test_sync_cart_session_ignores_read_only_store_api_cart_and_batch_requests(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'item_1' => [ 'product_id' => 10, 'quantity' => 1 ] ];

        $rest_api = new GECX_Rest_API();

        // 1. GET /wc/store/v1/cart (e.g. Mini-Cart block on /my-account/)
        $get_cart = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items' => [] ], 200 ), null, $get_cart );

        // 2. POST /wc/store/v1/batch containing only GET sub-requests
        $read_only_batch = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
        $read_only_batch->set_param(
            'requests',
            [
                [
                    'path'   => '/wc/store/v1/cart',
                    'method' => 'GET',
                ],
            ]
        );
        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'responses' => [] ], 200 ), null, $read_only_batch );

        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 0, WC()->cart->persistent_cart_updates );
        $this->assertSame( [], $wpdb->wc_sessions );
    }

    public function test_sync_cart_session_does_not_force_cookie_for_empty_cart_on_uncookied_guest(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [];

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/wc/store/v1/cart/update-customer' );
        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items' => [] ], 200 ), null, $request );

        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 0, WC()->cart->persistent_cart_updates );
        $this->assertSame( [], $wpdb->wc_sessions );
    }

    public function test_sync_cart_session_sets_cookie_and_persists_session_when_browser_adds_item_to_cart(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'abc123' => [ 'product_id' => 42, 'quantity' => 2 ] ];

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items_count' => 2 ], 200 ), null, $request );

        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertTrue( WC()->session->cookie_set );
        $this->assertSame( 1, WC()->cart->persistent_cart_updates );
        $this->assertArrayHasKey( 't_guest_session_123', $wpdb->wc_sessions );

        $stored = maybe_unserialize( $wpdb->wc_sessions['t_guest_session_123'] );
        $this->assertSame( WC()->cart->cart_for_session, $stored['cart'] );
        $this->assertNotEmpty( $GLOBALS['gecx_test_deleted_cache_keys'] );
    }

    public function test_sync_cart_session_syncs_cart_token_mutation_without_forcing_browser_cookie(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'abc123' => [ 'product_id' => 99, 'quantity' => 1 ] ];

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
        $request->set_header( 'Cart-Token', 'agent.cart.token' );
        $request->set_param(
            'requests',
            [
                [
                    'path'   => '/wc/store/v1/cart/add-item',
                    'method' => 'POST',
                ],
            ]
        );

        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'responses' => [] ], 200 ), null, $request );

        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->cart->persistent_cart_updates );
        $this->assertArrayHasKey( 't_guest_session_123', $wpdb->wc_sessions );
    }

    public function test_save_session_handler_does_not_force_cookie_for_uncookied_guest_without_cart(): void {
        WC()->cart = new WC_Cart_Mock();

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-1' );

        $res = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-1', WC()->session->get( 'gecx_session_id' ) );
        $this->assertSame( 0, WC()->session->cookie_set_calls );
        $this->assertSame( 0, WC()->session->save_data_calls );

        // Once the browser already has a WooCommerce session cookie, save_session_handler persists and refreshes it.
        $_COOKIE['wp_woocommerce_session_test'] = 't_guest_session_123||12345||12345||hash';
        $res_with_cookie                        = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res_with_cookie );
        $this->assertSame( 1, WC()->session->cookie_set_calls );
        $this->assertSame( 1, WC()->session->save_data_calls );
    }

    public function test_save_session_handler_writes_no_row_for_uncookied_guest(): void {
        global $wpdb;
        WC()->cart = new WC_Cart_Mock();

        $request = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-flood' );
        $res = ( new GECX_Rest_API() )->save_session_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status() );
        $this->assertArrayNotHasKey( 't_guest_session_123', $wpdb->wc_sessions );
    }

    public function test_save_session_handler_writes_no_row_for_forged_session_cookie(): void {
        global $wpdb;
        WC()->cart                              = new WC_Cart_Mock();
        $_COOKIE['wp_woocommerce_session_test'] = 't_guest_session_123||12345||12345||forged';

        $request = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-forged' );
        ( new GECX_Rest_API() )->save_session_handler( $request );

        $this->assertArrayNotHasKey( 't_guest_session_123', $wpdb->wc_sessions );
    }

    public function test_save_session_handler_writes_row_for_verified_session_cookie(): void {
        global $wpdb;
        WC()->cart                              = new WC_Cart_Mock();
        $_COOKIE['wp_woocommerce_session_test'] = 't_guest_session_123||12345||12345||hash';

        $request = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-cookie' );
        ( new GECX_Rest_API() )->save_session_handler( $request );

        $this->assertArrayHasKey( 't_guest_session_123', $wpdb->wc_sessions );
        $stored = maybe_unserialize( $wpdb->wc_sessions['t_guest_session_123'] );
        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-cookie', $stored['gecx_session_id'] );
    }

    public function test_save_session_handler_persists_cart_token_session_id_to_database(): void {
        global $wpdb;
        WC()->cart = new WC_Cart_Mock();
        // Even when WC()->session holds a default uncookied guest ID
        // ('t_guest_session_123'), an HMAC-verified guest Cart-Token minted by
        // the agent ('t_agent_shopper_888') must be bound in the DB.
        $token = $this->generate_jwt( 't_agent_shopper_888' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_header( 'Cart-Token', $token );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-token-1' );

        $res = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, WC()->session->cookie_set_calls );

        $this->assertArrayHasKey( 't_agent_shopper_888', $wpdb->wc_sessions );
        $stored = maybe_unserialize( $wpdb->wc_sessions['t_agent_shopper_888'] );
        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-token-1', $stored['gecx_session_id'] );
    }

    public function test_save_session_handler_rejects_cross_user_cart_token(): void {
        global $wpdb;
        WC()->cart = new WC_Cart_Mock();
        WC()->session->set_customer_id( 't_attacker_111' );

        // 1. Guest caller presenting a Cart-Token for registered user ID '999' is rejected.
        $victim_user_token = $this->generate_jwt( '999' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_header( 'Cart-Token', $victim_user_token );
        $request->set_param( 'cart_token', $victim_user_token );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-attacker' );

        $res = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status() );
        $this->assertArrayNotHasKey( '999', $wpdb->wc_sessions );

        // 2. Logged-in user 42 presenting a Cart-Token for user 999 or guest 't_victim_999' is rejected.
        $GLOBALS['gecx_test_current_user'] = new WP_User( 42, 'attacker@example.com', [ 'customer' ] );
        $foreign_guest_token               = $this->generate_jwt( 't_victim_999' );
        $request->set_header( 'Cart-Token', $foreign_guest_token );
        $request->set_param( 'cart_token', $foreign_guest_token );

        $res2 = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res2 );
        $this->assertArrayNotHasKey( 't_victim_999', $wpdb->wc_sessions );
    }

    public function test_session_endpoint_blocked_under_cart_token_auth(): void {
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';
        $GLOBALS['gecx_test_users'] = [
            456 => new WP_User( 456, 'shopper456@example.com', [ 'customer' ] ),
        ];

        $auth = new GECX_Auth();
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertSame( 456, $auth->authenticate_via_cart_token( 0 ) );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $result  = $auth->block_cart_token_off_store_api( null, null, $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rest_forbidden', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_save_session_handler_soft_ignores_invalid_or_expired_cart_token(): void {
        global $wpdb;
        $GLOBALS['gecx_test_current_user'] = new WP_User( 12, 'shopper@example.com', [ 'customer' ] );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/gecx/v1/session' );
        $request->set_param( 'session_id', 'projects/123/locations/global/commerceSessions/sess-expired' );
        $request->set_param( 'cart_token', 'invalid-or-expired-token' );

        $res = $rest_api->save_session_handler( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $res );
        $this->assertSame( 200, $res->get_status() );

        $this->assertArrayHasKey( '12', $wpdb->wc_sessions );
        $stored = maybe_unserialize( $wpdb->wc_sessions['12'] );
        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-expired', $stored['gecx_session_id'] );
    }

    public function test_sync_cart_session_bridges_cart_token_to_cookie_on_get_cart_with_items(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'item1' => [ 'product_id' => 42, 'quantity' => 2 ] ];

        $token = $this->generate_jwt( 't_guest_shopper_999' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
        $request->set_header( 'Cart-Token', $token );

        unset( $_COOKIE['wp_woocommerce_session_testcookiehash'] );
        $GLOBALS['gecx_test_cookies'] = [];

        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items' => [] ], 200 ), null, $request );

        $this->assertArrayHasKey( 'wp_woocommerce_session_testcookiehash', $_COOKIE );
        $cookie_val = $_COOKIE['wp_woocommerce_session_testcookiehash'];
        $this->assertStringStartsWith( 't_guest_shopper_999||', $cookie_val );
        $this->assertArrayHasKey( 't_guest_shopper_999', $wpdb->wc_sessions );
    }

    public function test_sync_cart_session_does_not_set_cookie_on_empty_cart_read(): void {
        global $wpdb;
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [];

        $token = $this->generate_jwt( 't_guest_empty' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
        $request->set_header( 'Cart-Token', $token );

        unset( $_COOKIE['wp_woocommerce_session_testcookiehash'] );
        $GLOBALS['gecx_test_cookies'] = [];

        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items' => [] ], 200 ), null, $request );

        $this->assertArrayNotHasKey( 'wp_woocommerce_session_testcookiehash', $_COOKIE );
    }

    public function test_sync_cart_session_does_not_bridge_a_numeric_cart_token_user_id_to_a_cookie(): void {
        // A Cart-Token minted while the shopper was logged in carries a numeric
        // user_id and stays valid after logout. Writing it as a session cookie for a
        // logged-out browser makes WC_Session_Handler::is_session_cookie_valid() fail,
        // which destroys that user's session row and empties their saved cart.
        WC()->cart                   = new WC_Cart_Mock();
        WC()->cart->cart_for_session = [ 'item1' => [ 'product_id' => 42, 'quantity' => 2 ] ];

        $token = $this->generate_jwt( '5' );

        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
        $request->set_header( 'Cart-Token', $token );

        unset( $_COOKIE['wp_woocommerce_session_testcookiehash'] );
        $GLOBALS['gecx_test_cookies'] = [];

        $rest_api->sync_cart_session_after_dispatch( new WP_REST_Response( [ 'items' => [] ], 200 ), null, $request );

        $this->assertArrayNotHasKey( 'wp_woocommerce_session_testcookiehash', $_COOKIE );
    }

    public function test_is_mutating_store_api_cart_request_returns_false_when_batch_requests_missing(): void {
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );

        $ref = new ReflectionMethod( $rest_api, 'is_mutating_store_api_cart_request' );
        $ref->setAccessible( true );

        $this->assertFalse( $ref->invoke( $rest_api, $request ) );

        $request->set_param( 'requests', 'invalid' );
        $this->assertFalse( $ref->invoke( $rest_api, $request ) );

        $request->set_param( 'requests', [ [ 'path' => '/wc/store/v1/products', 'method' => 'GET' ] ] );
        $this->assertFalse( $ref->invoke( $rest_api, $request ) );

        $request->set_param( 'requests', [ [ 'path' => '/wc/store/v1/cart/add-item', 'method' => 'POST' ] ] );
        $this->assertTrue( $ref->invoke( $rest_api, $request ) );
    }

    public function test_attach_session_to_order_metadata_falls_back_to_database(): void {
        global $wpdb;
        $order = new WC_Order();

        WC()->session              = new WC_Session_Handler();
        WC()->session->customer_id = 't_order_shopper_111';

        $wpdb->wc_sessions['t_order_shopper_111'] = serialize( [
            'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-order-1',
        ] );

        $rest_api = new GECX_Rest_API();
        $rest_api->attach_session_to_order_metadata( $order, [] );

        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-order-1', $order->get_meta( '_gecx_session_id' ) );
    }

    public function test_attach_session_to_order_metadata_store_api_falls_back_to_cart_token(): void {
        global $wpdb;
        $order = new WC_Order();

        WC()->session              = new WC_Session_Handler();
        WC()->session->customer_id = '';

        $token = $this->generate_jwt( 't_blocks_checkout_222' );
        $wpdb->wc_sessions['t_blocks_checkout_222'] = serialize( [
            'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-blocks-2',
        ] );

        $request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
        $request->set_header( 'Cart-Token', $token );

        $rest_api = new GECX_Rest_API();
        $rest_api->attach_session_to_order_metadata_store_api( $order, $request );

        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-blocks-2', $order->get_meta( '_gecx_session_id' ) );
    }

    public function test_attach_session_to_order_metadata_store_api_reads_cart_token_from_server_headers(): void {
        global $wpdb;
        $order = new WC_Order();

        WC()->session              = new WC_Session_Handler();
        WC()->session->customer_id = '';

        // WooCommerce Blocks dispatches checkout internally, so the Cart-Token
        // only survives on $_SERVER. Sanitizing it must leave the base64url
        // segments and the dots intact or the HMAC check fails.
        $token                                     = $this->generate_jwt( 't_blocks_checkout_333' );
        $_SERVER['HTTP_CART_TOKEN']                = $token;
        $wpdb->wc_sessions['t_blocks_checkout_333'] = serialize( [
            'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-blocks-3',
        ] );

        $rest_api = new GECX_Rest_API();
        $rest_api->attach_session_to_order_metadata_store_api( $order, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-blocks-3', $order->get_meta( '_gecx_session_id' ) );

        // A header carrying characters a JWT cannot contain resolves nothing.
        $junk_order                 = new WC_Order();
        $_SERVER['HTTP_CART_TOKEN'] = '<script>alert(1)</script>';
        $rest_api->attach_session_to_order_metadata_store_api( $junk_order, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
        $this->assertSame( '', (string) $junk_order->get_meta( '_gecx_session_id' ) );

        unset( $_SERVER['HTTP_CART_TOKEN'] );
    }

    public function test_sync_cart_session_persists_to_wc_sessions_table_and_wc_session_handler(): void {
        $token    = $this->generate_jwt( 't_guest_session_abc' );
        $rest_api = new GECX_Rest_API();
        $request  = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
        $request->set_header( 'Cart-Token', $token );

        WC()->session->set_customer_id( 't_guest_session_abc' );
        WC()->cart->session_cart = [
            'item_key_1' => [
                'key'        => 'item_key_1',
                'product_id' => 42,
                'quantity'   => 2,
            ],
        ];

        $response = new WP_REST_Response( [ 'items' => [] ], 200 );

        $rest_api->sync_cart_session_after_dispatch( $response, null, $request );

        $this->assertArrayHasKey( 't_guest_session_abc', $GLOBALS['gecx_test_wc_sessions_table'] );
        $session_row  = $GLOBALS['gecx_test_wc_sessions_table']['t_guest_session_abc'];
        $unserialized = maybe_unserialize( $session_row['session_value'] );
        $this->assertTrue( is_array( $unserialized ) );
        $this->assertArrayHasKey( 'cart', $unserialized );

        $cart_data = maybe_unserialize( $unserialized['cart'] );
        $this->assertArrayHasKey( 'item_key_1', $cart_data );
        $this->assertSame( 42, $cart_data['item_key_1']['product_id'] );
        $this->assertSame( 2, $cart_data['item_key_1']['quantity'] );
        $this->assertSame( 1, WC()->session->save_data_calls );
    }
    public function test_sync_cart_session_skips_get_requests_and_non_2xx_responses(): void {
        $token    = $this->generate_jwt( 't_guest_session_skip' );
        $rest_api = new GECX_Rest_API();
        WC()->session->set_customer_id( 't_guest_session_skip' );

        // A plain GET cart read (no Cart-Token) is never synced. Cart-Token cart
        // reads are intentionally bridged, see
        // test_sync_cart_session_bridges_cart_token_to_cookie_on_get_cart_with_items().
        $get_request = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
        $ok_response = new WP_REST_Response( [ 'items' => [] ], 200 );

        $rest_api->sync_cart_session_after_dispatch( $ok_response, null, $get_request );
        $this->assertArrayNotHasKey( 't_guest_session_skip', $GLOBALS['gecx_test_wc_sessions_table'] );
        $this->assertSame( 0, WC()->session->save_data_calls );

        $post_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
        $post_request->set_header( 'Cart-Token', $token );
        $err_response = new WP_REST_Response( [ 'code' => 'invalid_stock' ], 400 );

        $rest_api->sync_cart_session_after_dispatch( $err_response, null, $post_request );
        $this->assertArrayNotHasKey( 't_guest_session_skip', $GLOBALS['gecx_test_wc_sessions_table'] );
        $this->assertSame( 0, WC()->session->save_data_calls );
    }
    public function test_widened_wc_auth_strips_capabilities_before_rest_pre_dispatch_and_restores_on_dispatch(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $other_admin                       = new WP_User( 2, 'other-admin@example.com', [ 'administrator' ] );
        $_SERVER['REQUEST_URI']            = '/wp-json/gecx/v1/webhooks/order-created';
        $this->given_wordpress_resolved_route( '/gecx/v1/webhooks/order-created' );

        $rest_api = new GECX_Rest_API();
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // Before rest_pre_dispatch runs (e.g. during parse_request hook window),
        // widened WC API key auth cannot grant admin/woocommerce capabilities
        // to the API-key user, while unrelated users keep their capabilities.
        $this->assertFalse( current_user_can( 'manage_woocommerce' ) );
        $this->assertFalse( current_user_can( 'manage_options' ) );
        $this->assertTrue( user_can( $other_admin, 'manage_woocommerce' ) );

        // At rest_pre_dispatch priority 20 on an allowed GECX route, capabilities are unlocked.
        $request = new WP_REST_Request( 'POST', '/gecx/v1/webhooks/order-created' );
        $rest_api->unlock_widened_wc_auth_on_dispatch( null, null, $request );
        $this->assertTrue( current_user_can( 'manage_woocommerce' ) );
        $this->assertTrue( current_user_can( 'manage_options' ) );

        // After rest_post_dispatch, both widened-auth flags are reset so shutdown
        // hooks see the user's normal capabilities again.
        $rest_api->lock_widened_wc_auth_after_dispatch( new WP_REST_Response(), null, $request );
        $this->assertTrue( current_user_can( 'manage_woocommerce' ) );
    }
    public function test_widened_wc_auth_rejects_a_route_mismatch_with_403_and_keeps_capabilities_withheld(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ], [ 'read' => true ] );
        $_SERVER['REQUEST_URI']            = '/wp-json/gecx/v1/public-key';
        $this->given_wordpress_resolved_route( '/gecx/v1/public-key' );

        $rest_api = new GECX_Rest_API();
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );

        // Baseline, non-gating capabilities stay truthful inside the window so
        // third-party code can still tell that somebody is logged in.
        $this->assertTrue( current_user_can( 'read' ) );
        $this->assertFalse( current_user_can( 'manage_woocommerce' ) );

        // WooCommerce key auth was widened for /gecx/v1/public-key, but the
        // route WordPress actually dispatches is a core route. It must be
        // rejected rather than served with the key owner's capabilities.
        $mismatch = new WP_REST_Request( 'GET', '/wp/v2/users' );
        $result   = $rest_api->unlock_widened_wc_auth_on_dispatch( null, null, $mismatch );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );

        // The rejection must not unlock capabilities for anything running later
        // in the same request.
        $this->assertFalse( current_user_can( 'manage_woocommerce' ) );
        $this->assertFalse( current_user_can( 'manage_options' ) );
        $this->assertTrue( current_user_can( 'read' ) );

        // An upstream WP_Error (e.g. WooCommerce's own scope check failing at
        // priority 10) is passed through with capabilities still withheld.
        $allowed  = new WP_REST_Request( 'GET', '/gecx/v1/public-key' );
        $wp_error = new WP_Error( 'woocommerce_rest_authentication_error', 'denied', [ 'status' => 401 ] );
        $this->assertSame( $wp_error, $rest_api->unlock_widened_wc_auth_on_dispatch( $wp_error, null, $allowed ) );
        $this->assertFalse( current_user_can( 'manage_woocommerce' ) );
    }

    public function test_widened_wc_auth_state_is_cleared_when_woocommerce_claims_the_request_itself(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ], [ 'read' => true ] );
        $_SERVER['REQUEST_URI']            = '/wp-json/gecx/v1/link-agent';
        $this->given_wordpress_resolved_route( '/gecx/v1/link-agent' );

        $rest_api = new GECX_Rest_API();
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( false ) );
        $this->assertFalse( current_user_can( 'manage_woocommerce' ) );

        // woocommerce_rest_is_request_to_rest_api can fire more than once per
        // request. When WooCommerce answers true for one of its own /wc/
        // routes, nothing is widened by this plugin, so the flags must be
        // cleared instead of leaving a stale true that would 403 the route
        // WooCommerce is about to dispatch.
        $this->assertTrue( $rest_api->enable_wc_auth_for_custom_endpoints( true ) );
        $this->assertTrue( current_user_can( 'manage_woocommerce' ) );

        $wc_request = new WP_REST_Request( 'GET', '/wc/v3/orders' );
        $this->assertNull( $rest_api->unlock_widened_wc_auth_on_dispatch( null, null, $wc_request ) );
    }

    public function test_auth_context_multisite_subdirectory_rejects_sibling_site_referer_and_non_member_user(): void {
        $GLOBALS['gecx_test_is_multisite']  = true;
        $GLOBALS['gecx_test_sites_by_path'] = [
            '/site-a/' => 1,
            '/site-b/' => 2,
        ];
        $GLOBALS['gecx_test_home_url'] = 'https://example.com/site-a';
        $GLOBALS['gecx_test_site_url'] = 'https://example.com/site-a';

        $rest_api = new GECX_Rest_API();

        // Sibling subsite Referer must be rejected with 403 on subsite (/site-a).
        $cross_site_req = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $cross_site_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $cross_site_req->set_header( 'Origin', 'https://example.com' );
        $cross_site_req->set_header( 'Referer', 'https://example.com/site-b/shop/' );
        $perm = $rest_api->check_auth_context_permissions( $cross_site_req );
        $this->assertInstanceOf( WP_Error::class, $perm );
        $this->assertSame( 403, $perm->get_error_data()['status'] );

        // Root site (allowed_path === '') must also reject a sibling subsite Referer (/site-b/shop/) via get_site_by_path().
        $GLOBALS['gecx_test_home_url'] = 'https://example.com';
        $GLOBALS['gecx_test_site_url'] = 'https://example.com';
        $root_cross_site_perm          = $rest_api->check_auth_context_permissions( $cross_site_req );
        $this->assertInstanceOf( WP_Error::class, $root_cross_site_perm );
        $this->assertSame( 403, $root_cross_site_perm->get_error_data()['status'] );

        // Root blog homepage sends a bare origin Referer under the default
        // Referrer-Policy, so it must be served rather than 403'd. The blog it
        // came from is unknowable, so auth_context_handler() downgrades it to
        // guest (asserted in the dedicated test below).
        $origin_only_req = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $origin_only_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $origin_only_req->set_header( 'Referer', 'https://example.com/' );
        $this->assertTrue( $rest_api->check_auth_context_permissions( $origin_only_req ) );

        // Unresolved get_site_by_path() (false) must also be rejected rather than falling through.
        $GLOBALS['gecx_test_sites_by_path']['/unmapped/'] = false;
        $unmapped_req                                     = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $unmapped_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $unmapped_req->set_header( 'Referer', 'https://example.com/unmapped/page/' );
        $unmapped_perm = $rest_api->check_auth_context_permissions( $unmapped_req );
        $this->assertInstanceOf( WP_Error::class, $unmapped_perm );
        $this->assertSame( 403, $unmapped_perm->get_error_data()['status'] );

        // Root site accepts a root-site Referer (/shop/product-1/).
        $root_same_site_req = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $root_same_site_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $root_same_site_req->set_header( 'Origin', 'https://example.com' );
        $root_same_site_req->set_header( 'Referer', 'https://example.com/shop/product-1/' );
        $this->assertTrue( $rest_api->check_auth_context_permissions( $root_same_site_req ) );

        // Same subsite Referer is accepted, but a user who is not a member of blog 1
        // must be downgraded to guest (user_id === 0 and guest nonce) for site-a.
        $GLOBALS['gecx_test_home_url'] = 'https://example.com/site-a';
        $GLOBALS['gecx_test_site_url'] = 'https://example.com/site-a';
        $same_site_req                 = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $same_site_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $same_site_req->set_header( 'Origin', 'https://example.com' );
        $same_site_req->set_header( 'Referer', 'https://example.com/site-a/shop/' );
        $this->assertTrue( $rest_api->check_auth_context_permissions( $same_site_req ) );

        GECX_Auth::get_or_generate_keypair();
        $GLOBALS['gecx_test_current_user']            = new WP_User( 77, 'otherblog@example.com', [ 'customer' ] );
        $GLOBALS['gecx_test_blog_memberships'][1][77] = false;

        $response = $rest_api->auth_context_handler( $same_site_req );
        $data     = $response->get_data();
        $this->assertSame( 'test_nonce_wp_rest', $data['nonce'] );
        $parts   = explode( '.', (string) $data['customer_jwt'] );
        $payload = json_decode( $this->base64_url_decode( $parts[1] ), true );
        $this->assertSame( 0, $payload['user_id'] );
        $this->assertSame( '', $payload['user_email'] );
    }
    public function test_auth_context_multisite_root_homepage_origin_only_referer_degrades_to_guest(): void {
        $GLOBALS['gecx_test_is_multisite']  = true;
        $GLOBALS['gecx_test_sites_by_path'] = [
            '/site-a/' => 2,
        ];
        $GLOBALS['gecx_test_home_url'] = 'https://example.com';
        $GLOBALS['gecx_test_site_url'] = 'https://example.com';

        GECX_Auth::get_or_generate_keypair();
        $rest_api = new GECX_Rest_API();

        // Member of the root blog, so nothing but the Referer can disqualify them.
        $root_member                                  = new WP_User( 88, 'rootmember@example.com', [ 'customer' ] );
        $GLOBALS['gecx_test_users'][88]               = $root_member;
        $GLOBALS['gecx_test_current_user']            = $root_member;
        $GLOBALS['gecx_test_blog_memberships'][1][88] = true;

        // Root blog homepage: Referer carries no path, so identity is unknowable
        // and the shopper is served a guest nonce rather than a 403.
        $origin_only_req = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $origin_only_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $origin_only_req->set_header( 'Referer', 'https://example.com/' );
        $this->assertTrue( $rest_api->check_auth_context_permissions( $origin_only_req ) );

        $guest_response = $rest_api->auth_context_handler( $origin_only_req );
        $this->assertSame( 200, $guest_response->get_status() );
        $guest_data = $guest_response->get_data();
        $this->assertSame( 'test_nonce_wp_rest', $guest_data['nonce'] );
        $guest_parts   = explode( '.', (string) $guest_data['customer_jwt'] );
        $guest_payload = json_decode( $this->base64_url_decode( $guest_parts[1] ), true );
        $this->assertSame( 0, $guest_payload['user_id'] );
        $this->assertSame( '', $guest_payload['user_email'] );

        // A Referer that names a root-blog path still yields the member identity.
        $path_req = new WP_REST_Request( 'POST', '/gecx/v1/auth-context' );
        $path_req->set_header( 'Sec-Fetch-Site', 'same-origin' );
        $path_req->set_header( 'Referer', 'https://example.com/shop/product-1/' );
        $this->assertTrue( $rest_api->check_auth_context_permissions( $path_req ) );

        $member_response = $rest_api->auth_context_handler( $path_req );
        $member_data     = $member_response->get_data();
        $member_parts    = explode( '.', (string) $member_data['customer_jwt'] );
        $member_payload  = json_decode( $this->base64_url_decode( $member_parts[1] ), true );
        $this->assertSame( 88, $member_payload['user_id'] );
        $this->assertSame( 'rootmember@example.com', $member_payload['user_email'] );
    }
    public function test_rest_routes_declare_args_schemas_for_session_webhooks_and_link_agent(): void {
        $rest_api = new GECX_Rest_API();
        $rest_api->register_session_rest_route();
        $rest_api->register_webhooks_rest_route();
        $rest_api->register_link_rest_route();

        $session_route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/session'] ?? [];
        $this->assertArrayHasKey( 'args', $session_route );
        $this->assertArrayHasKey( 'session_id', $session_route['args'] );
        $this->assertTrue( ( $session_route['args']['session_id']['validate_callback'] )( 'sess_valid_123' ) );
        $this->assertFalse( ( $session_route['args']['session_id']['validate_callback'] )( 'bad session!' ) );

        $webhook_route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/webhooks/order-created'] ?? [];
        $this->assertArrayHasKey( 'args', $webhook_route );
        $this->assertArrayHasKey( 'consumer_secret', $webhook_route['args'] );

        $link_route = $GLOBALS['gecx_test_rest_routes']['gecx/v1/link-agent'] ?? [];
        $this->assertArrayHasKey( 'args', $link_route );
        $this->assertArrayHasKey( 'agent_name', $link_route['args'] );
        $this->assertArrayHasKey( 'token_broker_name', $link_route['args'] );
    }

    public function test_omnichannel_session_id_accepted_for_save_and_webhook_gate(): void {
        $omnichannel_session = 'projects/123456789/locations/global/omnichannelSessions/sess_omnichannel_abc123';
        $this->assertTrue( GECX_Rest_API::is_valid_session_id( $omnichannel_session, false ) );
        $this->assertTrue( GECX_Rest_API::is_valid_session_id( $omnichannel_session, true ) );

        update_option( 'gecx_webhook_id', 901 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/a1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order_id = 4041;
        $GLOBALS['gecx_test_orders'][ $order_id ] = new WC_Order( $order_id );
        $GLOBALS['gecx_test_orders'][ $order_id ]->update_meta_data( '_gecx_session_id', $omnichannel_session );

        $rest_api = new GECX_Rest_API();
        $this->assertTrue( $rest_api->gate_order_webhook_delivery( true, 901, $order_id ) );

        $response = (object) [ 'data' => [] ];
        $prepared = $rest_api->add_session_id_to_order_rest_response( $response, $GLOBALS['gecx_test_orders'][ $order_id ] );
        $this->assertSame( $omnichannel_session, $prepared->data['_gecx_session_id'] );

        $GLOBALS['gecx_test_orders'][ $order_id ]->update_meta_data( '_gecx_session_id', 'invalid_unqualified_session' );
        $this->assertFalse( $rest_api->gate_order_webhook_delivery( true, 901, $order_id ) );

        $GLOBALS['gecx_test_orders'][ $order_id ]->update_meta_data( '_gecx_session_id', '' );
        $this->assertFalse( $rest_api->gate_order_webhook_delivery( true, 901, $order_id ) );
    }
}

if ( php_sapi_name() === 'cli' ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $argv0 = isset( $_SERVER['argv'][0] ) ? sanitize_text_field( wp_unslash( $_SERVER['argv'][0] ) ) : '';
    if ( empty( $argv0 ) || basename( $argv0 ) === basename( __FILE__ ) ) {
        gecx_run_test_class( RestApiTest::class );
    }
}
