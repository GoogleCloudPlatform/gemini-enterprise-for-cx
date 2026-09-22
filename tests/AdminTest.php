<?php
/**
 * Admin Test Suite for Gemini Enterprise for Customer Experience (GECX)
 *
 * @package GECX
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-admin.php';

class AdminTest extends GECX_TestCase {

    protected function setUp(): void {
        parent::setUp();
    }

    public function test_admin_ajax_save_button_config(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce'             => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'placement'         => 'floating',
            'floating_position' => 'center_right',
            'display_style'     => 'icon-only',
            'label'             => 'Ask AI',
            'short_label'       => 'Chat',
            'enable_shimmer'    => '1',
        ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_save_button_config();

        $this->assertEquals( 'floating', get_option( 'gecx_button_placement' ) );
        $this->assertEquals( 'center_right', get_option( 'gecx_floating_position' ) );
        $this->assertEquals( 'icon-only', get_option( 'gecx_button_display_style' ) );
        $this->assertEquals( 'Ask AI', get_option( 'gecx_button_label' ) );
        $this->assertEquals( 'Chat', get_option( 'gecx_button_short_label' ) );
        $this->assertEquals( 1, get_option( 'gecx_button_enable_shimmer' ) );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );

        // Test fallback to responsive on invalid display_style.
        $_POST['display_style'] = 'invalid_style';
        $admin->ajax_save_button_config();
        $this->assertEquals( 'responsive', get_option( 'gecx_button_display_style' ) );
    }

    public function test_admin_ajax_toggle_app_embed(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce'   => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'enabled' => '1',
        ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_toggle_app_embed();

        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
    }

    public function test_admin_ajax_unlink_agent(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 200, '' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
        $request = $GLOBALS['gecx_test_http_requests'][0];
        $this->assertEquals( 'https://gecx.cloud.google.com/woocommerce/unlink-agent', $request['url'] );
        $body = json_decode( (string) $request['args']['body'], true );
        $this->assertEquals( 'projects/123/locations/global/agents/agent-1', $body['agent_id'] );
        $this->assertTrue( ! empty( $body['admin_jwt'] ) );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 0, get_option( 'gecx_agent_enabled' ) );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
    }

    public function test_admin_ajax_unlink_agent_proceeds_when_backend_returns_404(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 404, '' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 0, get_option( 'gecx_agent_enabled' ) );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
    }

    public function test_admin_ajax_unlink_agent_retains_options_when_backend_returns_error(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 500, '' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        $this->assertEquals( 'projects/123/locations/global/agents/agent-1', get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-1', get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
        $this->assertFalse( $GLOBALS['gecx_test_last_json_response']['success'] );
        $this->assertEquals( 502, $GLOBALS['gecx_test_last_json_response']['status'] );
    }

    public function test_handle_connection_callback_rejects_missing_or_invalid_state(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => 'invalid_or_expired_state',
            'agent_name'  => 'projects/123/locations/global/agents/malicious-agent',
        ];
        delete_option( 'gecx_agent_name' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'https://example.com/wp-admin/admin.php?page=gemini-enterprise-for-cx', $GLOBALS['gecx_test_last_redirect'] );
        $this->assertTrue( ! empty( get_transient( 'gecx_admin_notice_error' ) ) );
    }

    public function test_handle_connection_callback_does_not_persist_from_query_string(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
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

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();

        // The agent arrives over gecx/v1/link-agent, not from this request.
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertFalse( get_option( 'gecx_agent_enabled' ) );

        // The state is still consumed, so the landing page cannot be replayed.
        $this->assertFalse( get_transient( 'gecx_oauth_state_' . $state ) );
        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $this->assertArrayNotHasKey( $state, $states );
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=gemini-enterprise-for-cx&connected=1', $GLOBALS['gecx_test_last_redirect'] );
    }

    /**
     * A replayed callback cannot disturb a store that is already linked,
     * because this request no longer writes anything.
     */
    public function test_handle_connection_callback_leaves_existing_link_untouched(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/legitimate' );
        $state = 'replayed_state';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
            'agent_name'  => 'projects/999/locations/global/agents/attacker-agent',
        ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();

        $this->assertSame( 'projects/123/locations/global/agents/legitimate', get_option( 'gecx_agent_name' ) );
    }

    /**
     * A malformed agent name in the query string is simply ignored now, rather
     * than producing an error, because nothing reads it.
     */
    public function test_handle_connection_callback_ignores_malformed_query_parameters(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $state = 'valid_state_malformed_params';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        $_GET = [
            'page'         => 'gemini-enterprise-for-cx',
            'gecx_action'  => 'linked',
            'state'        => $state,
            'agent_name'   => '',
            'token_broker' => 'invalid token broker with spaces <script>',
        ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertTrue( empty( get_transient( 'gecx_admin_notice_error' ) ) );
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=gemini-enterprise-for-cx&connected=1', $GLOBALS['gecx_test_last_redirect'] );
    }

    public function test_handle_connection_callback_clears_sync_throttle_window(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $state = 'valid_state_clear_sync';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );
        update_option( 'gecx_sync_last_attempt', (string) time() );
        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
        ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();

        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
    }

    public function test_handle_connection_callback_rejects_expired_option_fallback(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $state = 'expired_oauth_state_option_fallback';
        delete_transient( 'gecx_oauth_state_' . $state );
        update_option( 'gecx_pending_oauth_states', [ $state => time() - 300 ], 'no' );

        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'state'       => $state,
        ];
        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=gemini-enterprise-for-cx', $GLOBALS['gecx_test_last_redirect'] );
        $this->assertTrue( ! empty( get_transient( 'gecx_admin_notice_error' ) ) );
    }

    public function test_handle_connection_callback_supports_oauth_state_param(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $state = 'oauth_state_param_test';
        set_transient( 'gecx_oauth_state_' . $state, 1, 300 );

        $_GET = [
            'page'        => 'gemini-enterprise-for-cx',
            'gecx_action' => 'linked',
            'oauth_state' => $state,
        ];
        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connection_callback();
        $this->assertFalse( get_transient( 'gecx_oauth_state_' . $state ) );
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=gemini-enterprise-for-cx&connected=1', $GLOBALS['gecx_test_last_redirect'] );
    }

    public function test_uninstall_cleans_up_options_and_webhooks(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        // Retired option: uninstall must still purge it on upgraded stores.
        update_option( 'gecx_api_secret', 'legacy_secret123' );
        update_option( 'gecx_pending_oauth_states', [ 'state1' => time() + 300 ] );

        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_secret( 'wh_secret_123' );
        $webhook_id = $webhook->save();
        update_option( 'gecx_webhook_id', $webhook_id );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
        $this->assertFalse( get_option( 'gecx_pending_oauth_states' ) );
        $this->assertArrayNotHasKey( $webhook_id, $GLOBALS['gecx_test_webhooks'] );
    }

    public function test_uninstall_cleans_up_orphaned_webhooks_without_saved_option(): void {
        delete_option( 'gecx_webhook_id' );

        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_secret( 'wh_orphaned_secret' );
        $webhook_id = $webhook->save();

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
        $this->assertArrayNotHasKey( $webhook_id, $GLOBALS['gecx_test_webhooks'] );
    }

    public function test_uninstall_cleans_every_site_on_a_network(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $GLOBALS['gecx_test_is_multisite'] = true;
        $GLOBALS['gecx_test_sites']        = [ 1, 7, 9 ];
        $GLOBALS['gecx_test_http_requests'] = [];

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        // The uninstall notification is authenticated with the store's own
        // keypair now, so the connected site needs one.
        GECX_Auth::get_or_generate_keypair();
        // A store upgraded from a version that stored the shared secret keeps
        // the row until uninstall clears it. It must not sign anything.
        update_option( 'gecx_api_secret', 'legacy_secret123' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        // Every site is visited, and every switch is unwound. Because options
        // are cleared on site 1, sites 7 and 9 are unconnected and must NOT
        // generate ephemeral RSA keypairs or fire outbound uninstall webhooks.
        $this->assertEquals( [ 1, 7, 9 ], $GLOBALS['gecx_test_switched_blogs'] );
        $this->assertEquals( [], $GLOBALS['gecx_test_blog_stack'] );
        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );

        $args    = $GLOBALS['gecx_test_http_requests'][0]['args'];
        $headers = $args['headers'];
        $this->assertArrayHasKey( 'Authorization', $headers );
        $this->assertArrayNotHasKey( 'X-WC-Webhook-Signature', $headers );
        $this->assertSame( 0, $args['redirection'] );
        $this->assertSame( 10240, $args['limit_response_size'] );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertFalse( get_option( 'gecx_keypair' ) );
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    public function test_uninstall_does_not_switch_sites_on_a_single_site(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-123' );
        update_option( 'gecx_plugin_version', '1.0.0' );
        update_option( 'gecx_pending_sync_notices', [ 'gecx_agent_unlinked' ] );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertEquals( [], $GLOBALS['gecx_test_switched_blogs'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_plugin_version' ) );
        $this->assertFalse( get_option( 'gecx_pending_sync_notices' ) );
    }

    public function test_connect_url_return_url_is_encoded_once(): void {
        // The connect button is only rendered once the store is authorized and
        // before an agent is chosen. Rendering the settings page must not mint
        // an oauth_state transient or admin_jwt on GET; those are minted only
        // when the administrator submits the POST form to admin_post_gecx_connect_agent.
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_agent_name' );
        update_option( 'gecx_webhook_id', 4242 );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        try {
            $admin->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString( 'id="gecx-connect-btn"', $html );
        $this->assertStringContainsString( 'name="action" value="gecx_connect_agent"', $html );
        $this->assertFalse( get_transient( 'gecx_oauth_state_1' ) );

        $connect_url = $admin->build_connect_agent_url();

        // rawurlencode() before add_query_arg() turned the ':' of 'https:'
        // into %253A, and the console received a return_url it could not use.
        $this->assertStringNotContainsString( '%253A', $connect_url );
        $this->assertStringContainsString(
            'return_url=https%3A%2F%2Fexample.com%2Fwp-admin%2Fadmin.php%3Fpage%3Dgemini-enterprise-for-cx%26gecx_action%3Dlinked',
            $connect_url
        );
    }

    public function test_first_post_activation_settings_page_load_makes_no_outbound_requests(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        delete_option( 'gecx_auth_complete' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        try {
            $admin->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString( 'id="gecx-authorize-btn"', $html );
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
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
        $GLOBALS['gecx_test_doing_ajax']   = true;
        $GLOBALS['gecx_test_last_redirect'] = null;

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->redirect_on_activation();

        $this->assertTrue( (bool) get_option( 'gecx_do_activation_redirect' ) );
        $this->assertNull( $GLOBALS['gecx_test_last_redirect'] );

        $GLOBALS['gecx_test_doing_ajax'] = false;
    }

    /**
     * Bulk activation consumes the flag without redirecting.
     *
     * Leaving it set would fire the redirect on whatever admin page the user
     * opened next, which is worse than not redirecting at all.
     */
    public function test_activation_redirect_flag_is_cleared_by_bulk_activation(): void {
        update_option( 'gecx_do_activation_redirect', true );
        $_GET['activate-multi']             = '1';
        $GLOBALS['gecx_test_last_redirect'] = null;

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->redirect_on_activation();

        $this->assertFalse( get_option( 'gecx_do_activation_redirect' ) );
        $this->assertNull( $GLOBALS['gecx_test_last_redirect'] );

        unset( $_GET['activate-multi'] );
    }

    /**
     * Nothing happens when the flag was never set.
     */
    public function test_activation_redirect_is_inert_without_the_flag(): void {
        delete_option( 'gecx_do_activation_redirect' );
        $GLOBALS['gecx_test_last_redirect'] = null;

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->redirect_on_activation();

        $this->assertNull( $GLOBALS['gecx_test_last_redirect'] );
    }

    public function test_uninstall_via_wp_cli_without_current_user_sends_rs256_admin_jwt_and_queries_wpdb(): void {
        GECX_Auth::get_or_generate_keypair();
        $GLOBALS['gecx_test_current_user'] = null;
        $GLOBALS['gecx_test_users'][3]     = new WP_User( 3, 'cli-admin@example.com', [ 'administrator' ] );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-cli' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
        $headers = $GLOBALS['gecx_test_http_requests'][0]['args']['headers'];
        $this->assertArrayHasKey( 'Authorization', $headers );
        $this->assertStringContainsString( 'Bearer ', $headers['Authorization'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_handle_connect_agent_redirect_mints_state_and_redirects_on_post_only(): void {
        $GLOBALS['gecx_test_current_user']  = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $GLOBALS['gecx_test_last_redirect'] = null;
        $_SERVER['REQUEST_METHOD']          = 'POST';
        $_POST['gecx_connect_nonce']        = 'valid_nonce_gecx_connect_agent_action';
        update_option( 'gecx_webhook_id', 4242 );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->handle_connect_agent_redirect();

        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $this->assertNotEmpty( $states );
        $state = (string) array_key_first( $states );
        $this->assertSame( 1, get_transient( 'gecx_oauth_state_' . $state ) );
        $this->assertNotNull( $GLOBALS['gecx_test_last_redirect'] );
        $this->assertStringContainsString( $state, $GLOBALS['gecx_test_last_redirect'] );
        $this->assertStringContainsString( 'admin_jwt=', $GLOBALS['gecx_test_last_redirect'] );
    }

    public function test_save_product_prompts_override_field_uses_wc_product_crud_methods(): void {
        $GLOBALS['gecx_test_current_user']      = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST['gecx_prompts_override_nonce']   = 'valid_nonce_gecx_save_prompts_override';
        $_POST['_gecx_suggested_prompts_override'] = "Prompt A\nPrompt B";

        $product = new WC_Product( 505 );
        $admin   = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );

        $admin->save_product_prompts_override_field( $product );
        $this->assertSame( "Prompt A\nPrompt B", $product->get_meta( '_gecx_suggested_prompts_override' ) );
        $this->assertSame( "Prompt A\nPrompt B", get_post_meta( 505, '_gecx_suggested_prompts_override', true ) );

        $_POST['_gecx_suggested_prompts_override'] = '';
        $admin->save_product_prompts_override_field( $product );
        $this->assertSame( '', $product->get_meta( '_gecx_suggested_prompts_override' ) );
        $this->assertSame( '', get_post_meta( 505, '_gecx_suggested_prompts_override', true ) );
    }

    public function test_show_activation_notice_renders_when_setup_incomplete(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-info is-dismissible gecx-activation-notice', $output );
        $this->assertStringContainsString( 'Thanks for installing Gemini Enterprise for CX! <a href="', $output );
        $this->assertStringContainsString( 'Complete the setup</a> to start delivering better shopping experiences to your customers.', $output );
        $this->assertStringContainsString( 'admin.php?page=gemini-enterprise-for-cx', $output );
    }

    public function test_show_activation_notice_hidden_on_settings_page_via_get_page(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );
        $_GET['page'] = 'gemini-enterprise-for-cx';

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_on_settings_page_via_screen_hook(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->add_settings_page();
        set_current_screen( 'marketing_page_gemini-enterprise-for-cx' );

        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_when_dismissed(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        update_option( 'gecx_dismiss_activation_notice', true );
        delete_option( 'gecx_agent_name' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_when_agent_configured(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_dismiss_activation_notice' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_show_activation_notice_hidden_without_manage_options_capability(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 2, 'author@example.com', [ 'author' ] );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_agent_name' );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        ob_start();
        $admin->show_activation_notice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_unlink_agent_returns_user_to_step_2_connect_store(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];

        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_secret( 'wh_secret_123' );
        $webhook->set_status( 'active' );
        $webhook_id = $webhook->save();

        update_option( 'gecx_webhook_id', $webhook_id );
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );

        // 1. Response for ajax_unlink_agent (/woocommerce/unlink-agent)
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 200, '' );
        // 2. Response for maybe_sync_with_backend during render_settings_page (/woocommerce/sync)
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response(
            200,
            wp_json_encode( [ 'status' => 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED' ] )
        );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertSame( $webhook_id, get_option( 'gecx_webhook_id' ) );
        $this->assertSame( 1, get_option( 'gecx_auth_complete' ) );

        ob_start();
        try {
            $admin->render_settings_page();
        } finally {
            $html = ob_get_clean();
        }

        // Must render Step 2 (Connect Your Store to Google Cloud), not Step 1 (Authorize Store).
        $this->assertStringContainsString( 'id="gecx-connect-btn"', $html );
        $this->assertStringContainsString( 'Store Authorization Complete', $html );
        $this->assertStringNotContainsString( 'id="gecx-authorize-btn"', $html );
    }
}

if ( php_sapi_name() === 'cli' ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $argv0 = isset( $_SERVER['argv'][0] ) ? sanitize_text_field( wp_unslash( $_SERVER['argv'][0] ) ) : '';
    if ( empty( $argv0 ) || basename( $argv0 ) === basename( __FILE__ ) ) {
        gecx_run_test_class( AdminTest::class );
    }
}
