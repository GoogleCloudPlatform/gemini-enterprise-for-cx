<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin Test Suite for Gemini Enterprise for Customer Experience (GECX)
 *
 * @package GECX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\Storefront;
use WC_Product;
use WC_Webhook;
use WP_REST_Request;

class AdminTest extends RestTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( 1 );
    }

    protected function handle_connection_callback( Admin $admin ): void {
        try {
            $admin->connection->handle_connection_callback();
        } catch ( RedirectException $e ) {
            // Expected redirect.
        }
    }

    protected function handle_connect_agent_redirect( Admin $admin ): void {
        try {
            $admin->connection->handle_connect_agent_redirect();
        } catch ( RedirectException $e ) {
            // Expected redirect.
        }
    }

    public function test_rest_save_button_config_saves_manual_placement_and_menu_target(): void {
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params(
            [
                'gecx_button_placement' => 'manual',
                'gecx_nav_menu_target'  => 'location:primary_navigation',
            ]
        );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'manual', get_option( 'gecx_button_placement' ) );
        $this->assertSame( 'location:primary_navigation', get_option( 'gecx_nav_menu_target' ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_nav_menu_target' => 'menu:abc' ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( '', get_option( 'gecx_nav_menu_target' ) );
    }

    public function test_rest_save_button_config(): void {
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params(
            [
                'gecx_button_placement'                   => 'floating',
                'gecx_floating_position'                  => 'center_right',
                'gecx_button_display_style'               => 'icon-only',
                'gecx_button_label'                       => 'Ask AI',
                'gecx_button_short_label'                 => 'Chat',
                'gecx_button_enable_shimmer'              => 1,
                'gecx_defer_widget_until_interaction'     => 1,
            ]
        );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'floating', get_option( 'gecx_button_placement' ) );
        $this->assertEquals( 'center_right', get_option( 'gecx_floating_position' ) );
        $this->assertEquals( 'icon-only', get_option( 'gecx_button_display_style' ) );
        $this->assertEquals( 'Ask AI', get_option( 'gecx_button_label' ) );
        $this->assertEquals( 'Chat', get_option( 'gecx_button_short_label' ) );
        $this->assertEquals( 1, get_option( 'gecx_button_enable_shimmer' ) );
        $this->assertEquals( 1, get_option( 'gecx_defer_widget_until_interaction' ) );

        // Test bottom_right floating position.
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_floating_position' => 'bottom_right' ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'bottom_right', get_option( 'gecx_floating_position' ) );

        // Test bottom_left floating position.
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_floating_position' => 'bottom_left' ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'bottom_left', get_option( 'gecx_floating_position' ) );

        // Test center_left floating position.
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_floating_position' => 'center_left' ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'center_left', get_option( 'gecx_floating_position' ) );

        // Test fallback to bottom_center on invalid floating_position.
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_floating_position' => 'invalid_pos' ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'bottom_center', get_option( 'gecx_floating_position' ) );

        // Test fallback to responsive on invalid display_style.
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_button_display_style' => 'invalid_style' ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 'responsive', get_option( 'gecx_button_display_style' ) );
    }

    public function test_rest_toggle_app_embed(): void {
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_agent_enabled' => 1 ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_rest_unlink_agent(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $this->http_responses[] = gecx_test_http_response( 200, '' );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $this->http_requests );
        $request = $this->http_requests[0];
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/unlink-agent', $request['url'] );
        $body = json_decode( (string) $request['args']['body'], true );
        $this->assertEquals( 'projects/123/locations/global/agents/agent-1', $body['agent_id'] );
        $this->assertArrayNotHasKey( 'admin_jwt', $body );
        $this->assertMatchesRegularExpression( '/^Bearer [\w-]+\.[\w-]+\.[\w-]+$/', (string) ( $request['args']['headers']['Authorization'] ?? '' ) );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 0, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_rest_unlink_agent_proceeds_when_backend_returns_404(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $this->http_responses[] = gecx_test_http_response( 404, '' );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 0, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_rest_unlink_agent_retains_options_when_backend_returns_error(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $this->http_responses[] = gecx_test_http_response( 500, '' );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertEquals( 'projects/123/locations/global/agents/agent-1', get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-1', get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
        $this->assertErrorResponse( 'gecx_unlink_failed', $response, 502 );
    }

    public function test_handle_connection_callback_rejects_missing_or_invalid_state(): void {
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => 'invalid_or_expired_state',
            'agent_name'  => 'projects/123/locations/global/agents/malicious-agent',
        ];
        delete_option( 'gecx_agent_name' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertEquals( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ), $this->last_redirect );
        $this->assertTrue( ! empty( get_transient( 'gecx_admin_notice_error' ) ) );
    }

    public function test_handle_connection_callback_does_not_persist_from_query_string(): void {
        $state = 'random_oauth_state_string_456';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        update_option( 'gecx_pending_oauth_states', [ $state => time() + 300 ], 'no' );
        $_GET = [
            'page'              => 'gemini-enterprise-for-cx',
            'gecx_action'       => 'linked',
            'state'             => $state,
            'agent_name'        => 'projects/123/locations/global/agents/agent-456',
            'token_broker_name' => 'projects/123/locations/global/tokenBrokers/tb-456',
        ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );

        // The agent arrives over gecx/v1/link-agent, not from this request.
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertFalse( get_option( 'gecx_agent_enabled' ) );

        // The state is still consumed, so the landing page cannot be replayed.
        $this->assertFalse( get_transient( 'gecx_oauth_state_' . $state ) );
        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $this->assertArrayNotHasKey( $state, $states );
        $this->assertSame( admin_url( 'admin.php?page=gemini-enterprise-for-cx&connected=1' ), $this->last_redirect );
    }

    /**
     * A replayed callback cannot disturb a store that is already linked,
     * because this request no longer writes anything.
     */
    public function test_handle_connection_callback_leaves_existing_link_untouched(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/legitimate' );
        $state = 'replayed_state';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
            'agent_name'  => 'projects/999/locations/global/agents/attacker-agent',
        ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );

        $this->assertSame( 'projects/123/locations/global/agents/legitimate', get_option( 'gecx_agent_name' ) );
    }

    /**
     * A malformed agent name in the query string is simply ignored now, rather
     * than producing an error, because nothing reads it.
     */
    public function test_handle_connection_callback_ignores_malformed_query_parameters(): void {
        $state = 'valid_state_malformed_params';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        $_GET = [
            'page'         => 'gemini-enterprise-for-cx',
            'gecx_action'  => 'linked',
            'state'        => $state,
            'agent_name'   => '',
            'token_broker' => 'invalid token broker with spaces <script>',
        ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertTrue( empty( get_transient( 'gecx_admin_notice_error' ) ) );
        $this->assertSame( admin_url( 'admin.php?page=gemini-enterprise-for-cx&connected=1' ), $this->last_redirect );
    }

    public function test_handle_connection_callback_clears_sync_throttle_window(): void {
        $state = 'valid_state_clear_sync';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        update_option( 'gecx_sync_last_attempt', (string) time() );
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
        ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );

        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
    }

    public function test_handle_connection_callback_rejects_expired_option_fallback(): void {
        $state = 'expired_oauth_state_option_fallback';
        delete_transient( 'gecx_oauth_state_' . $state );
        update_option( 'gecx_pending_oauth_states', [ $state => time() - 300 ], 'no' );

        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
        ];
        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );
        $this->assertSame( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ), $this->last_redirect );
        $this->assertTrue( ! empty( get_transient( 'gecx_admin_notice_error' ) ) );
    }

    public function test_handle_connection_callback_supports_oauth_state_param(): void {
        $state = 'oauth_state_param_test';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );

        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'oauth_state' => $state,
        ];
        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connection_callback( $admin );
        $this->assertFalse( get_transient( 'gecx_oauth_state_' . $state ) );
        $this->assertSame( admin_url( 'admin.php?page=gemini-enterprise-for-cx&connected=1' ), $this->last_redirect );
    }

    public function test_uninstall_cleans_up_options_and_webhooks(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        // Retired option: uninstall must still purge it on upgraded stores.
        update_option( 'gecx_api_secret', 'legacy_secret123' );
        update_option( 'gecx_pending_oauth_states', [ 'state1' => time() + 300 ] );

        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_delivery_url( 'https://example.com/webhook' );
        $webhook->set_secret( 'wh_secret_123' );
        $webhook_id = $webhook->save();
        update_option( 'gecx_webhook_id', $webhook_id );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
        $this->assertFalse( get_option( 'gecx_pending_oauth_states' ) );
        $wh = wc_get_webhook( $webhook_id );
        $this->assertNull( $wh );
    }

    public function test_uninstall_cleans_up_orphaned_webhooks_without_saved_option(): void {
        delete_option( 'gecx_webhook_id' );

        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_delivery_url( 'https://example.com/webhook' );
        $webhook->set_secret( 'wh_orphaned_secret' );
        $webhook_id = $webhook->save();

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
        $wh = wc_get_webhook( $webhook_id );
        $this->assertNull( $wh );
    }

    /**
     * @group ms-required
     */
    public function test_uninstall_also_cleans_archived_spam_and_deactivated_sites(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite only.' );
        }
        update_option( 'gecx_webhook_id', 4242 );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
    }

    /**
     * @group ms-required
     */
    public function test_uninstall_cleans_every_site_on_a_network(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite only.' );
        }
        $this->http_requests = [];

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        Auth::get_or_generate_keypair();
        update_option( 'gecx_api_secret', 'legacy_secret123' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertFalse( get_option( 'gecx_keypair' ) );
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    /**
     * @group ms-excluded
     */
    public function test_uninstall_does_not_switch_sites_on_a_single_site(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        update_option( 'gecx_plugin_version', '1.0.0' );
        update_option( 'gecx_pending_sync_notices', [ 'gecx_agent_unlinked' ] );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_plugin_version' ) );
        $this->assertFalse( get_option( 'gecx_pending_sync_notices' ) );
    }

    public function test_connect_url_return_url_is_encoded_once(): void {
        // The connect button is only rendered once the store is authorized and
        // before an agent is chosen. Rendering the settings page must not mint
        // an oauth_state transient or admin_jwt on GET; those are minted only
        // when the administrator submits the POST form to admin_post_gecx_connect_agent.
        delete_option( 'gecx_agent_name' );
        update_option( 'gecx_webhook_id', 4242 );
        update_option( 'gecx_auth_complete', 1 );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString( 'id="gecx-connect-btn"', $html );
        $this->assertStringContainsString( 'name="action" value="gecx_connect_agent"', $html );
        $this->assertFalse( get_transient( 'gecx_oauth_state_1' ) );

        $connect_url = $admin->connection->build_connect_agent_url();

        // rawurlencode() before add_query_arg() turned the ':' of 'https:'
        // into %253A, and the console received a return_url it could not use.
        $this->assertStringNotContainsString( '%253A', $connect_url );
        $expected_return_url = rawurlencode( admin_url( 'admin.php?page=gemini-enterprise-for-cx&gecx_action=linked' ) );
        $this->assertStringContainsString(
            'return_url=' . $expected_return_url,
            $connect_url
        );
        $this->assertMatchesRegularExpression( '/^[^#]*\?[^#]*return_url=[^#]+#admin_jwt=[^&#]+$/', $connect_url );
        $this->assertStringNotContainsString( '?admin_jwt=', $connect_url );
        $this->assertStringNotContainsString( '&admin_jwt=', $connect_url );
    }

    public function test_first_post_activation_settings_page_load_makes_no_outbound_requests(): void {
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        delete_option( 'gecx_auth_complete' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString( 'id="gecx-authorize-btn"', $html );
        $this->assertCount( 0, $this->http_requests );
        $this->assertFalse( get_option( 'gecx_keypair' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    /**
     * An AJAX request must leave the activation flag for the page load.
     *
     * admin_init fires on admin-ajax.php too, and a logged-in admin screen
     * starts Heartbeat within seconds of activation. When this consumed the
     * flag unconditionally, whichever request arrived first won, and if that
     * was Heartbeat the admin never saw onboarding.
     */
    public function test_activation_redirect_flag_survives_an_ajax_request(): void {
        update_option( 'gecx_do_activation_redirect', true );
        $this->enable_ajax();

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->redirect_on_activation();

        $this->assertTrue( (bool) get_option( 'gecx_do_activation_redirect' ) );
        $this->assertNull( $this->last_redirect );
    }

    /**
     * Bulk activation consumes the flag without redirecting.
     *
     * Leaving it set would fire the redirect on whatever admin page the user
     * opened next, which is worse than not redirecting at all.
     */
    public function test_activation_redirect_flag_is_cleared_by_bulk_activation(): void {
        update_option( 'gecx_do_activation_redirect', true );
        $_GET['activate-multi'] = '1';

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->redirect_on_activation();

        $this->assertFalse( get_option( 'gecx_do_activation_redirect' ) );
        $this->assertNull( $this->last_redirect );

        unset( $_GET['activate-multi'] );
    }

    /**
     * Nothing happens when the flag was never set.
     */
    public function test_activation_redirect_is_inert_without_the_flag(): void {
        delete_option( 'gecx_do_activation_redirect' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->redirect_on_activation();

        $this->assertNull( $this->last_redirect );
    }

    public function test_uninstall_via_wp_cli_without_current_user_sends_rs256_admin_jwt_and_queries_wpdb(): void {
        Auth::get_or_generate_keypair();
        wp_set_current_user( 0 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-cli' );
        $this->http_responses[] = gecx_test_http_response( 200, '' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertCount( 1, $this->http_requests );
        $headers = $this->http_requests[0]['args']['headers'];
        $this->assertArrayHasKey( 'Authorization', $headers );
        $this->assertStringContainsString( 'Bearer ', $headers['Authorization'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_handle_connect_agent_redirect_mints_state_and_redirects_on_post_only(): void {
        $_SERVER['REQUEST_METHOD']   = 'POST';
        $_POST['gecx_connect_nonce'] = wp_create_nonce( 'gecx_connect_agent_action' );
        update_option( 'gecx_webhook_id', 4242 );
        update_option( 'gecx_auth_complete', 1 );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->handle_connect_agent_redirect( $admin );

        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $this->assertNotEmpty( $states );
        $state = (string) array_key_first( $states );
        $this->assertSame( 1, get_transient( 'gecx_oauth_state_' . $state ) );
        $this->assertNotNull( $this->last_redirect );
        $this->assertStringContainsString( $state, $this->last_redirect );
        $this->assertMatchesRegularExpression( '/^[^#]*\?[^#]*return_url=[^#]+#admin_jwt=[^&#]+$/', $this->last_redirect );
        $this->assertStringNotContainsString( '?admin_jwt=', $this->last_redirect );
        $this->assertStringNotContainsString( '&admin_jwt=', $this->last_redirect );
    }

    public function test_save_product_prompts_override_field_uses_wc_product_crud_methods(): void {
        $_POST['gecx_prompts_override_nonce']      = wp_create_nonce( 'gecx_save_prompts_override' );
        $_POST['_gecx_suggested_prompts_override'] = "Prompt A\nPrompt B";

        $product = new WC_Product();
        $product->set_name( 'Test Product' );
        $product_id = $product->save();
        $admin      = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        $admin->product_prompts->save_product_prompts_override_field( $product );
        $product->save();
        $this->assertSame( "Prompt A\nPrompt B", $product->get_meta( '_gecx_suggested_prompts_override' ) );
        $this->assertSame( "Prompt A\nPrompt B", get_post_meta( $product_id, '_gecx_suggested_prompts_override', true ) );

        $_POST['_gecx_suggested_prompts_override'] = '';
        $admin->product_prompts->save_product_prompts_override_field( $product );
        $product->save();
        $this->assertSame( '', $product->get_meta( '_gecx_suggested_prompts_override' ) );
        $this->assertSame( '', get_post_meta( $product_id, '_gecx_suggested_prompts_override', true ) );
    }

    public function test_show_activation_notice_renders_when_setup_incomplete(): void {
        wp_set_current_user( 1 );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-info is-dismissible gecx-activation-notice', $output );
        $this->assertStringContainsString( 'Thanks for installing Gemini Enterprise for CX! <a href="', $output );
        $this->assertStringContainsString( 'Complete the setup</a> to start delivering better shopping experiences to your customers.', $output );
        $this->assertStringContainsString( 'admin.php?page=gemini-enterprise-for-cx', $output );
    }

    public function test_show_activation_notice_hidden_on_settings_page_via_get_page(): void {
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );
        $_GET['page'] = 'gemini-enterprise-for-cx';

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_on_settings_page_via_screen_hook(): void {
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->add_settings_page();
        set_current_screen( 'admin_page_gemini-enterprise-for-cx' );

        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_when_dismissed(): void {
        update_option( 'gecx_dismiss_activation_notice', true );
        delete_option( 'gecx_agent_name' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_when_agent_configured(): void {
        delete_option( 'gecx_dismiss_activation_notice' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_without_manage_options_capability(): void {
        $author_id = $this->factory()->user->create( [ 'role' => 'author' ] );
        wp_set_current_user( $author_id );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        $admin->settings_page->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_unlink_agent_keeps_keys_and_returns_user_to_step_2(): void {
        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_delivery_url( 'https://example.com/webhook' );
        $webhook->set_secret( 'wh_secret_123' );
        $webhook->set_status( 'active' );
        $webhook_id = $webhook->save();

        update_option( 'gecx_webhook_id', $webhook_id );
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );

        global $wpdb;
        $keys_table = $wpdb->prefix . 'woocommerce_api_keys';
        $wpdb->insert(
            $keys_table,
            [
                'user_id'         => 1,
                'description'     => 'Gemini Enterprise For CX - API (2026-09-01 10:00:00)',
                'permissions'     => 'read_write',
                'consumer_key'    => 'ck_test1',
                'consumer_secret' => 'cs_test1',
                'truncated_key'   => '1234',
            ]
        );
        $key1 = (int) $wpdb->insert_id;
        $wpdb->insert(
            $keys_table,
            [
                'user_id'         => 1,
                'description'     => 'Gemini Enterprise For CX - API (2026-09-20 12:30:00)',
                'permissions'     => 'read_write',
                'consumer_key'    => 'ck_test2',
                'consumer_secret' => 'cs_test2',
                'truncated_key'   => '5678',
            ]
        );
        $key2 = (int) $wpdb->insert_id;
        $wpdb->insert(
            $keys_table,
            [
                'user_id'         => 1,
                'description'     => 'Merchant ERP integration',
                'permissions'     => 'read_write',
                'consumer_key'    => 'ck_test3',
                'consumer_secret' => 'cs_test3',
                'truncated_key'   => '9012',
            ]
        );
        $key3 = (int) $wpdb->insert_id;

        // 1. Response for rest_unlink_agent (/woocommerce/unlink-agent)
        $this->http_responses[] = gecx_test_http_response( 200, '' );
        // 2. SyncState during render_settings_page. The backend still reports
        // the agent; the store must not adopt it after the merchant unlinked.
        $this->http_responses[] = gecx_test_http_response(
            200,
            wp_json_encode(
                [
                    'syncStatus'          => 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED',
                    'actualLinkedAgentId' => 'projects/123/locations/global/agents/agent-1',
                    'shopDomain'          => 'example.com',
                ]
            )
        );

        $admin    = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertSame( $webhook_id, get_option( 'gecx_webhook_id' ) );
        $this->assertSame( 1, get_option( 'gecx_auth_complete' ) );
        $this->assertSame( 1, get_option( Admin::MERCHANT_UNLINKED_OPTION ) );
        // Unlinking is not disconnecting: the WooCommerce API keys stay.
        $remaining = $wpdb->get_col( $wpdb->prepare( "SELECT key_id FROM {$keys_table} WHERE key_id IN (%d, %d, %d)", $key1, $key2, $key3 ) );
        $this->assertCount( 3, $remaining );

        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        // Must render Step 2 (Connect Your Store to Google Cloud), not Step 1 (Authorize Store).
        $this->assertStringContainsString( 'id="gecx-connect-btn"', $html );
        $this->assertStringContainsString( 'Store Authorization Complete', $html );
        $this->assertStringNotContainsString( 'id="gecx-authorize-btn"', $html );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertSame( 0, (int) get_option( 'gecx_agent_enabled' ) );
    }

    public function test_unlink_proceeds_locally_when_console_url_refused(): void {
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_console_base_url', 'https://attacker.example' );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 0, $this->http_requests );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertSame( 0, (int) get_option( 'gecx_agent_enabled' ) );
        $this->assertSame( 1, (int) get_option( Admin::MERCHANT_UNLINKED_OPTION ) );
    }

    public function test_failed_remote_unlink_changes_nothing(): void {
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        $this->http_responses[] = gecx_test_http_response( 500, '' );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );

        $this->assertErrorResponse( 'gecx_unlink_failed', $response, 502 );
        $this->assertSame( 'projects/123/locations/global/agents/agent-1', get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( Admin::MERCHANT_UNLINKED_OPTION ) );
    }

    public function test_revoke_woocommerce_api_keys_tolerates_missing_table(): void {
        global $wpdb;
        $table        = $wpdb->prefix . 'woocommerce_api_keys';
        $backup_table = $table . '_backup';
        $wpdb->query( "RENAME TABLE `{$table}` TO `{$backup_table}`" );
        try {
            $this->assertSame( 0, Auth::revoke_woocommerce_api_keys() );
        } finally {
            $wpdb->query( "RENAME TABLE `{$backup_table}` TO `{$table}`" );
        }
    }

    public function test_connect_redirect_refuses_disallowed_console_host(): void {
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'permalink_structure', '/%postname%/' );
        update_option( 'gecx_console_base_url', 'https://attacker.example' );
        $_POST = [ 'gecx_connect_nonce' => wp_create_nonce( 'gecx_connect_agent_action' ) ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $this->assertSame( '', $admin->connection->build_connect_agent_url() );
        $this->assertSame( [], (array) get_option( 'gecx_pending_oauth_states', [] ) );

        $this->handle_connect_agent_redirect( $admin );
        $this->assertStringNotContainsString( 'attacker.example', (string) $this->last_redirect );
    }

    public function test_settings_page_disables_authorize_for_disallowed_console_host(): void {
        update_option( 'permalink_structure', '/%postname%/' );
        update_option( 'gecx_console_base_url', 'https://attacker.example' );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringNotContainsString( 'attacker.example', $html );
        $this->assertStringNotContainsString( 'wc-auth', $html );
        $this->assertStringContainsString( 'disabled="disabled"', $html );
    }

    /**
     * @dataProvider info_tip_cases
     */
    public function test_settings_page_info_tips( string $placement, int $prompts_enabled, bool $manual_tip_shown ): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_webhook_id', 4242 );
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', $placement );
        update_option( 'gecx_pdp_prompts_enabled', $prompts_enabled );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString( '<code>[gecx_agent_button]</code>', $html );
        $this->assertStringContainsString( '<code>[gecx_suggested_prompts]</code>', $html );
        $this->assertMatchesRegularExpression( '/id="gecx-manual-placement-tip".*?style="(.*?)"/s', $html );
        preg_match( '/id="gecx-manual-placement-tip".*?style="(.*?)"/s', $html, $manual );
        $this->assertSame( $manual_tip_shown ? '' : 'display: none;', $manual[1] );
        // The prompts tip is always shown, whatever the setting.
        $this->assertMatchesRegularExpression( '/id="gecx-prompts-manual-tip"[^>]*aria-describedby="gecx-prompts-manual-tip-text">/', $html );
    }

    public static function info_tip_cases(): array {
        return [
            'manual placement, auto prompts off' => [ 'manual', 0, true ],
            'menu placement, auto prompts on'    => [ 'nav_menu', 1, false ],
            'floating placement, prompts off'    => [ 'floating', 0, false ],
        ];
    }

    public function test_toggle_app_embed_records_merchant_intent(): void {
        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_agent_enabled' => 0 ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, get_option( Admin::MERCHANT_DISABLED_OPTION ) );

        $request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $request->set_body_params( [ 'gecx_agent_enabled' => 1 ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( get_option( Admin::MERCHANT_DISABLED_OPTION ) );
    }

    public function test_rest_handlers_reject_unauthorized_subscriber(): void {
        update_option( 'gecx_button_placement', 'nav_menu' );
        update_option( 'gecx_agent_enabled', 0 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        delete_option( 'gecx_dismiss_activation_notice' );

        // 1. Administrator cannot mutate gecx_agent_name via /wp/v2/settings (show_in_rest is false),
        // while gecx_pdp_prompts_enabled and gecx_dismiss_activation_notice are updatable.
        $admin_request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $admin_request->set_body_params(
            [
                'gecx_agent_name'                => 'projects/999/locations/global/agents/hijack',
                'gecx_pdp_prompts_enabled'       => 0,
                'gecx_dismiss_activation_notice' => true,
            ]
        );
        $admin_response = rest_get_server()->dispatch( $admin_request );
        $this->assertSame( 200, $admin_response->get_status() );
        $this->assertSame( 'projects/123/locations/global/agents/agent-1', get_option( 'gecx_agent_name' ) );
        $this->assertSame( 0, get_option( 'gecx_pdp_prompts_enabled' ) );
        $this->assertTrue( (bool) get_option( 'gecx_dismiss_activation_notice', false ) );

        // Reset before testing subscriber rejection.
        update_option( 'gecx_pdp_prompts_enabled', 1 );
        delete_option( 'gecx_dismiss_activation_notice' );

        // 2. Subscriber gets 403 Forbidden and mutates nothing.
        $subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $settings_request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
        $settings_request->set_body_params(
            [
                'gecx_button_placement'          => 'floating',
                'gecx_agent_enabled'             => 1,
                'gecx_pdp_prompts_enabled'       => 0,
                'gecx_dismiss_activation_notice' => true,
            ]
        );
        $settings_response = rest_get_server()->dispatch( $settings_request );
        $this->assertErrorResponse( 'rest_forbidden', $settings_response, 403 );
        $this->assertSame( 'nav_menu', get_option( 'gecx_button_placement' ) );
        $this->assertSame( 0, get_option( 'gecx_agent_enabled' ) );
        $this->assertSame( 1, get_option( 'gecx_pdp_prompts_enabled' ) );
        $this->assertFalse( get_option( 'gecx_dismiss_activation_notice', false ) );

        $unlink_response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );
        $this->assertErrorResponse( 'rest_forbidden', $unlink_response, 403 );
        $this->assertSame( 'projects/123/locations/global/agents/agent-1', get_option( 'gecx_agent_name' ) );
    }

    public function test_is_standard_rest_api_enabled_requires_pretty_permalinks_and_wp_json(): void {
        $this->assertTrue( Admin::is_standard_rest_api_enabled() );

        add_filter( 'rest_url_prefix', function() { return 'api'; } );
        $this->assertFalse( Admin::is_standard_rest_api_enabled() );
        remove_all_filters( 'rest_url_prefix' );

        update_option( 'permalink_structure', '' );
        $this->assertFalse( Admin::is_standard_rest_api_enabled() );

        update_option( 'permalink_structure', '/index.php/%postname%/' );
        $this->assertFalse( Admin::is_standard_rest_api_enabled() );

        delete_option( 'permalink_structure' );
        $this->assertFalse( Admin::is_standard_rest_api_enabled() );

        update_option( 'permalink_structure', '/%postname%/' );
        $this->assertTrue( Admin::is_standard_rest_api_enabled() );
    }

    public function test_register_settings_enforces_enum_and_resource_name_sanitize_callbacks(): void {
        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->register_settings();

        $registered = get_registered_settings();
        $this->assertSame(
            [ Admin::class, 'sanitize_agent_name' ],
            $registered['gecx_agent_name']['sanitize_callback'] ?? null
        );
        $this->assertSame(
            [ Storefront::class, 'sanitize_button_placement' ],
            $registered['gecx_button_placement']['sanitize_callback'] ?? null
        );
        $this->assertSame(
            [ Storefront::class, 'sanitize_button_display_style' ],
            $registered['gecx_button_display_style']['sanitize_callback'] ?? null
        );

        $valid_agent = 'projects/my-proj/locations/us-central1/agents/agent-123';
        $this->assertSame( $valid_agent, Admin::sanitize_agent_name( $valid_agent ) );
        $this->assertSame( '', Admin::sanitize_agent_name( 'invalid agent?foo=bar' ) );
        $this->assertSame( '', Admin::sanitize_agent_name( [ 'array' ] ) );

        $this->assertSame( 'floating', Storefront::sanitize_button_placement( 'floating' ) );
        $this->assertSame( 'nav_menu', Storefront::sanitize_button_placement( 'arbitrary' ) );
        $this->assertSame( 'icon-only', Storefront::sanitize_button_display_style( 'icon-only' ) );
        $this->assertSame( 'responsive', Storefront::sanitize_button_display_style( 'arbitrary' ) );
    }

    public function test_render_settings_page_disables_authorize_and_connect_when_rest_api_non_standard(): void {
        add_filter( 'rest_url_prefix', function() { return 'custom-api'; } );

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = (string) ob_get_clean();
            remove_all_filters( 'rest_url_prefix' );
        }

        $this->assertStringContainsString( 'Permalinks configuration required:', $html );
        $this->assertStringContainsString( 'options-permalink.php', $html );
        $this->assertStringContainsString( 'id="gecx-authorize-btn"', $html );
        $this->assertStringContainsString( 'disabled="disabled"', $html );
        $this->assertStringNotContainsString( 'href="https://example.com/wc-auth/v1/authorize', $html );

        // Step 2 (authorized, unlinked) also disables Connect and Re-authorize buttons under plain permalinks.
        update_option( 'permalink_structure', '' );
        update_option( Admin::AUTH_COMPLETE_OPTION, 1 );
        update_option( 'gecx_sync_last_attempt', time() . ':' . GECX_VERSION );

        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $step2_html = (string) ob_get_clean();
            update_option( 'permalink_structure', '/%postname%/' );
        }

        $this->assertStringContainsString( 'Permalinks configuration required:', $step2_html );
        $this->assertStringContainsString( 'id="gecx-connect-btn"', $step2_html );
        $this->assertStringContainsString( 'id="gecx-reauthorize-btn"', $step2_html );
        $this->assertStringNotContainsString( 'name="action" value="gecx_connect_agent"', $step2_html );
    }

    public function test_handle_connect_agent_redirect_rejects_plain_permalinks_or_custom_prefix(): void {
        update_option( 'permalink_structure', '' );
        $_POST = [
            'gecx_connect_nonce' => wp_create_nonce( 'gecx_connect_agent_action' ),
        ];

        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        try {
            $this->handle_connect_agent_redirect( $admin );

            $this->assertSame(
                admin_url( 'admin.php?page=gemini-enterprise-for-cx' ),
                $this->last_redirect
            );
            $this->assertStringContainsString(
                '/wp-json',
                (string) get_transient( 'gecx_admin_notice_error' )
            );
        } finally {
            update_option( 'permalink_structure', '/%postname%/' );
        }
    }

    public function test_enqueue_admin_assets_registers_script_translations(): void {
        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
        $admin->settings_page->add_settings_page();
        $admin->settings_page->enqueue_admin_assets( 'marketing_page_gemini-enterprise-for-cx' );

        $this->assertSame( 'gemini-enterprise-for-cx', wp_scripts()->registered['gecx-admin-js']->textdomain );
        $this->assertSame( '', wp_scripts()->registered['gecx-admin-js']->translations_path );
    }
}
