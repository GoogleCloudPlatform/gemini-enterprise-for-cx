<?php
/**
 * SyncState reconciliation tests for Gemini Enterprise for CX (GECX).
 *
 * Covers GECX_Admin::sync_agent_state() and the state changes it applies.
 *
 * @package GECX
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-admin.php';

class SyncStateTest extends GECX_TestCase {

    private const SYNC_URL = 'https://gecx.cloud.google.com/woocommerce/webhook/sync-state';

    private const UNLINK_URL = 'https://gecx.cloud.google.com/woocommerce/unlink-agent';

    private const LINK_REQUIRED = 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED';
    private const SYNCED        = 'WOOCOMMERCE_SYNC_STATUS_SYNCED';

    protected function setUp(): void {
        parent::setUp();
        // generate_admin_jwt() returns null without a logged in administrator.
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
    }

    /**
     * Invoke the private sync entry point.
     *
     * @param string $current_agent Locally configured agent resource name.
     * @return string Status reported by the backend, or ''.
     */
    private function sync( string $current_agent ): string {
        $admin  = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $method = new ReflectionMethod( GECX_Admin::class, 'sync_agent_state' );
        $method->setAccessible( true );
        return (string) $method->invoke( $admin, $current_agent );
    }

    /**
     * Build a response body in the wire format the console returns.
     *
     * @param array<string, mixed> $fields           Proto3 JSON fields.
     * @param bool                 $with_xssi_prefix Prepend the XSSI guard.
     * @return string
     */
    private function body( array $fields, bool $with_xssi_prefix = true ): string {
        $json = (string) wp_json_encode( $fields );
        return $with_xssi_prefix ? ")]}'\n" . $json : $json;
    }

    /**
     * Queue one mocked HTTP response.
     *
     * @param mixed $response WP_Error or response array.
     */
    private function queue( $response ): void {
        $GLOBALS['gecx_test_http_responses'][] = $response;
    }

    /**
     * Seed a store that is locally linked to an agent.
     *
     * @param string $agent Agent resource name.
     */
    private function seed_linked_store( string $agent = 'agents/agent_a' ): void {
        update_option( 'gecx_agent_name', $agent );
        update_option( 'gecx_token_broker_name', 'broker-1' );
        update_option( 'gecx_agent_enabled', 1 );
    }

    /**
     * Assert that a settings notice with the given code was raised.
     *
     * @param string $code Notice code.
     */
    private function assert_notice_code( string $code ): void {
        $codes = array_column( $GLOBALS['gecx_test_settings_errors'], 'code' );
        $this->assertTrue(
            in_array( $code, $codes, true ),
            'Expected notice ' . $code . ', got: ' . implode( ',', $codes )
        );
    }

    /**
     * Assert that a settings notice with the given code was NOT raised.
     *
     * @param string $code Notice code.
     */
    private function assert_no_notice_code( string $code ): void {
        $codes = array_column( $GLOBALS['gecx_test_settings_errors'], 'code' );
        $this->assertFalse(
            in_array( $code, $codes, true ),
            'Did not expect notice ' . $code . ', got: ' . implode( ',', $codes )
        );
    }

    /** Assert that the local agent binding is intact. */
    private function assert_still_linked( string $agent = 'agents/agent_a' ): void {
        $this->assertEquals( $agent, get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-1', get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_link_required_without_actual_agent_unlinks_and_notifies(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => '',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 0, get_option( 'gecx_agent_enabled' ) );
        $this->assert_notice_code( 'gecx_agent_unlinked' );
    }

    public function test_link_required_with_actual_agent_adopts_and_does_not_unlink(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => 'agents/agent_b',
                        'tokenBrokerName'     => 'broker-2',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assertEquals( 'agents/agent_b', get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-2', get_option( 'gecx_token_broker_name' ) );
        $this->assertEquals( 1, get_option( 'gecx_agent_enabled' ) );
        $this->assert_notice_code( 'gecx_agent_adopted' );
        $this->assertTrue( false !== get_option( 'gecx_sync_last_attempt' ) ); // Throttle is not released
    }

    public function test_link_required_recovers_binding_when_nothing_is_stored_locally(): void {
        update_option( GECX_Admin::AUTH_COMPLETE_OPTION, 1 );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => 'agents/agent_b',
                        'tokenBrokerName'     => 'broker-2',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( '' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assertEquals( 'agents/agent_b', get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-2', get_option( 'gecx_token_broker_name' ) );
        // The store had no agent saved, so nothing was swapped out from under
        // the merchant and there is nothing to warn about.
        $this->assert_no_notice_code( 'gecx_agent_adopted' );
        // Recovering the binding has to bring the storefront widget back with
        // it, otherwise wp-admin shows connected while the storefront is dark.
        $this->assertEquals( 1, (int) get_option( 'gecx_agent_enabled' ) );
        $this->assertTrue( false !== get_option( 'gecx_sync_last_attempt' ) ); // Throttle is not released
    }

    public function test_fresh_activation_without_auth_complete_skips_sync_and_issues_no_request(): void {
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus' => self::SYNCED,
                        'shopDomain' => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( '' );

        $this->assertEquals( '', $status );
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
    }

    public function test_synced_upgrades_a_bare_agent_id_to_the_canonical_name(): void {
        // A store linked before the backend returned canonical names holds the
        // bare id. The backend still matches it and answers SYNCED, so this is
        // the only path on which the canonical name can ever be picked up.
        update_option( 'gecx_agent_name', 'agent_a' );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::SYNCED,
                        'actualLinkedAgentId' => 'projects/123/locations/global/agents/agent_a',
                        'tokenBrokerName'     => 'broker-1',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agent_a' );

        $this->assertEquals( 'projects/123/locations/global/agents/agent_a', get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'broker-1', get_option( 'gecx_token_broker_name' ) );
    }

    public function test_synced_adopts_a_token_broker_the_store_is_missing(): void {
        update_option( 'gecx_agent_name', 'agents/agent_a' );
        delete_option( 'gecx_token_broker_name' );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::SYNCED,
                        'actualLinkedAgentId' => 'agents/agent_a',
                        'tokenBrokerName'     => 'broker-1',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertEquals( 'broker-1', get_option( 'gecx_token_broker_name' ) );
    }

    public function test_adopting_an_agent_without_a_broker_clears_the_stale_one(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => 'agents/agent_b',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertEquals( 'agents/agent_b', get_option( 'gecx_agent_name' ) );
        // broker-1 belonged to agent_a. Keeping it would hand the storefront a
        // broker for an agent this store is no longer linked to.
        $this->assertFalse( get_option( 'gecx_token_broker_name' ) );
        // A genuine swap, so the merchant does get told.
        $this->assert_notice_code( 'gecx_agent_adopted' );
    }

    public function test_link_required_clears_a_previous_authorization_failure(): void {
        // Reaching LINK_REQUIRED means the backend accepted both the JWT and
        // the API keys, so the store is no longer in an auth-invalid state.
        $this->seed_linked_store();
        update_option( 'gecx_store_auth_invalid', 1 );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => 'agents/agent_a',
                        'tokenBrokerName'     => 'broker-1',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertFalse( get_option( 'gecx_store_auth_invalid' ) );
    }

    public function test_synced_is_a_no_op(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus' => self::SYNCED,
                        'shopDomain' => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::SYNCED, $status );
        $this->assert_still_linked();
        $this->assertCount( 0, $GLOBALS['gecx_test_settings_errors'] );
    }

    public function test_transport_error_does_not_unlink(): void {
        $this->seed_linked_store();
        $this->queue( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assert_still_linked();
        $this->assertCount( 0, $GLOBALS['gecx_test_settings_errors'] );
    }

    public function test_timeout_wp_error_is_noop_and_poisons_throttle(): void {
        $this->seed_linked_store();
        $this->queue( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assert_still_linked();
        $this->assertCount( 0, $GLOBALS['gecx_test_settings_errors'] );

        // Verify that the throttle was claimed and not released.
        $this->assertTrue( false !== get_option( 'gecx_sync_last_attempt' ) );
    }

    public function test_non_200_does_not_unlink(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                500,
                $this->body( [ 'syncStatus' => self::LINK_REQUIRED ] )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assert_still_linked();
    }

    public function test_malformed_body_does_not_unlink(): void {
        $this->seed_linked_store();
        $this->queue( gecx_test_http_response( 200, '<html><body>Gateway</body></html>' ) );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assert_still_linked();
    }

    public function test_unrecognized_status_does_not_unlink(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body( [ 'syncStatus' => 'SOMETHING_NEW', 'shopDomain' => 'example.com' ] )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( 'SOMETHING_NEW', $status );
        $this->assert_still_linked();
        $this->assertCount( 0, $GLOBALS['gecx_test_settings_errors'] );
    }

    public function test_status_is_read_without_the_xssi_prefix(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => '',
                        'shopDomain'          => 'example.com',
                    ],
                    false
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_status_is_read_from_snake_case_and_numeric_values(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'sync_status'            => 4,
                        'actual_linked_agent_id' => 'agents/agent_b',
                        'shop_domain'            => 'example.com',
                    ]
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assertEquals( 'agents/agent_b', get_option( 'gecx_agent_name' ) );
    }

    public function test_response_for_a_different_shop_is_ignored(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => '',
                        'shopDomain'          => 'someone-else.example.net',
                    ]
                )
            )
        );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::LINK_REQUIRED, $status );
        $this->assert_still_linked();
        $this->assertCount( 0, $GLOBALS['gecx_test_settings_errors'] );
    }

    public function test_auth_failures_reset_store_authorization_without_unlinking(): void {
        $cases = [
            'WOOCOMMERCE_SYNC_STATUS_JWT_AUTH_INVALID'            => 'gecx_sync_jwt_invalid',
            'WOOCOMMERCE_SYNC_STATUS_WOOCOMMERCE_API_KEYS_INVALID' => 'gecx_sync_api_keys_invalid',
        ];

        foreach ( $cases as $status_name => $notice_code ) {
            $this->setUp();
            $this->seed_linked_store();
            update_option( 'gecx_webhook_id', 42 );
            $this->queue(
                gecx_test_http_response(
                    200,
                    $this->body(
                        [
                            'syncStatus' => $status_name,
                            'shopDomain' => 'example.com',
                        ]
                    )
                )
            );

            $status = $this->sync( 'agents/agent_a' );

            $this->assertEquals( $status_name, $status );
            // The agent binding and the webhook survive; only the
            // authorization is flagged, which sends the settings page back to
            // the authorize step.
            $this->assert_still_linked();
            $this->assertEquals( 42, get_option( 'gecx_webhook_id' ) );
            $this->assertEquals( 1, get_option( GECX_Admin::STORE_AUTH_INVALID_OPTION ) );
            $this->assert_notice_code( $notice_code );
        }
    }

    public function test_synced_clears_a_previous_authorization_failure(): void {
        $this->seed_linked_store();
        update_option( GECX_Admin::STORE_AUTH_INVALID_OPTION, 1 );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus' => self::SYNCED,
                        'shopDomain' => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertFalse( get_option( GECX_Admin::STORE_AUTH_INVALID_OPTION ) );
    }

    public function test_second_call_inside_the_throttle_window_is_skipped(): void {
        $this->seed_linked_store();
        $synced = $this->body(
            [
                'syncStatus' => self::SYNCED,
                'shopDomain' => 'example.com',
            ]
        );
        $this->queue( gecx_test_http_response( 200, $synced ) );
        $this->queue( gecx_test_http_response( 200, $synced ) );

        $first  = $this->sync( 'agents/agent_a' );
        $second = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::SYNCED, $first );
        $this->assertEquals( '', $second );
        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
    }

    public function test_expired_throttle_window_allows_another_sync(): void {
        $this->seed_linked_store();
        $synced = $this->body(
            [
                'syncStatus' => self::SYNCED,
                'shopDomain' => 'example.com',
            ]
        );
        $this->queue( gecx_test_http_response( 200, $synced ) );
        $this->queue( gecx_test_http_response( 200, $synced ) );

        $this->sync( 'agents/agent_a' );
        update_option( 'gecx_sync_last_attempt', (string) ( time() - 601 ) );
        $second = $this->sync( 'agents/agent_a' );

        $this->assertEquals( self::SYNCED, $second );
        $this->assertCount( 2, $GLOBALS['gecx_test_http_requests'] );
    }

    public function test_unlink_releases_the_throttle_window(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => '',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
    }

    public function test_missing_admin_jwt_issues_no_request_and_takes_no_lock(): void {
        $this->seed_linked_store();
        $GLOBALS['gecx_test_current_user'] = null;

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
        $this->assert_still_linked();
    }

    public function test_non_https_console_base_url_issues_no_request(): void {
        $this->seed_linked_store();
        update_option( 'gecx_console_base_url', 'http://insecure.example.com' );

        $status = $this->sync( 'agents/agent_a' );

        $this->assertEquals( '', $status );
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
        $this->assert_still_linked();
    }

    public function test_request_shape(): void {
        $this->seed_linked_store();
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus' => self::SYNCED,
                        'shopDomain' => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
        $request = $GLOBALS['gecx_test_http_requests'][0];
        $this->assertEquals( self::SYNC_URL, $request['url'] );
        $this->assertEquals( 3, $request['args']['timeout'] );
        $this->assertEquals( 'application/json', $request['args']['headers']['Content-Type'] );
        $this->assertEquals( 'application/json', $request['args']['headers']['Accept'] );
        // Certificate verification must stay at the WordPress default.
        $this->assertArrayNotHasKey( 'sslverify', $request['args'] );

        $body = json_decode( (string) $request['args']['body'], true );
        $this->assertEquals( 'agents/agent_a', $body['expected_agent_id'] );
        $this->assertArrayHasKey( 'admin_jwt', $body );
        $this->assertTrue( '' !== (string) $body['admin_jwt'] );
    }

    public function test_automatic_unlink_preserves_appearance_but_rearms_the_notice(): void {
        $this->seed_linked_store();
        update_option( 'gecx_button_label', 'Ask AI' );
        update_option( 'gecx_button_placement', 'floating' );
        update_option( 'gecx_dismiss_activation_notice', 1 );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => '',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        $this->sync( 'agents/agent_a' );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'Ask AI', get_option( 'gecx_button_label' ) );
        $this->assertEquals( 'floating', get_option( 'gecx_button_placement' ) );
        $this->assertFalse( get_option( 'gecx_dismiss_activation_notice' ) );
    }

    public function test_explicit_unlink_keeps_appearance_and_releases_the_throttle_window(): void {
        $this->seed_linked_store();
        update_option( 'gecx_button_label', 'Ask AI' );
        update_option( 'gecx_sync_last_attempt', (string) time() );
        $_POST = [ 'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ) ];
        $this->queue( gecx_test_http_response( 200, '' ) );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertEquals( 'Ask AI', get_option( 'gecx_button_label' ) );
        $this->assertFalse( get_option( 'gecx_sync_last_attempt' ) );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
    }

    public function test_explicit_unlink_tells_google_before_clearing_the_binding(): void {
        $this->seed_linked_store();
        $_POST = [ 'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ) ];
        $this->queue( gecx_test_http_response( 200, '' ) );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        // The agent has to be named in the request, so the call must happen
        // while the binding is still readable, not after it is torn down.
        $request = $GLOBALS['gecx_test_http_requests'][0];
        $this->assertEquals( self::UNLINK_URL, $request['url'] );

        $body = json_decode( (string) $request['args']['body'], true );
        $this->assertEquals( 'agents/agent_a', $body['agent_id'] );
        $this->assertTrue( '' !== $body['admin_jwt'] );

        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_explicit_unlink_without_an_agent_issues_no_request(): void {
        $_POST = [ 'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ) ];

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->ajax_unlink_agent();

        // There is nothing to name in the request, and the backend rejects a
        // blank agent_id, so the merchant would get a 502 on a reset that has
        // nothing to release.
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
        $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
    }

    public function test_link_required_adopt_reactivates_paused_order_webhook(): void {
        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_delivery_url( 'https://gecx.cloud.google.com/woocommerce/webhook' );
        $webhook->set_status( 'paused' );
        $wh_id = $webhook->save();
        update_option( 'gecx_webhook_id', $wh_id );
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_enabled', 0 );

        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus'          => self::LINK_REQUIRED,
                        'actualLinkedAgentId' => 'agents/agent_recovered',
                        'tokenBrokerName'     => 'brokers/broker_recovered',
                        'shopDomain'          => 'example.com',
                    ]
                )
            )
        );

        require_once dirname( __DIR__ ) . '/includes/class-gecx-rest-api.php';
        $this->sync( '' );

        $this->assertEquals( 1, (int) get_option( 'gecx_agent_enabled' ) );
        $reloaded = new WC_Webhook( $wh_id );
        $this->assertSame( 'active', $reloaded->get_status() );
    }

    public function test_explicit_unlink_treats_400_and_403_as_unlinked(): void {
        foreach ( [ 400, 403 ] as $status_code ) {
            $this->seed_linked_store( 'agents/stale_agent' );
            $_POST = [ 'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ) ];
            $this->queue(
                [
                    'response' => [
                        'code'    => $status_code,
                        'message' => 'Stale binding',
                    ],
                    'body'     => '{}',
                ]
            );

            $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
            $admin->ajax_unlink_agent();

            $this->assertTrue( $GLOBALS['gecx_test_last_json_response']['success'] );
            $this->assertFalse( get_option( 'gecx_agent_name' ) );
        }
    }

    public function test_version_change_triggers_sync_and_clears_throttle(): void {
        $this->seed_linked_store();
        update_option( GECX_Admin::AUTH_COMPLETE_OPTION, 1 );
        update_option( GECX_Admin::PLUGIN_VERSION_OPTION, '0.9.0' );
        update_option( 'gecx_sync_last_attempt', (string) time() );
        $this->queue(
            gecx_test_http_response(
                200,
                $this->body(
                    [
                        'syncStatus' => self::SYNCED,
                        'shopDomain' => 'example.com',
                    ]
                )
            )
        );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->maybe_sync_on_version_change();

        $this->assertEquals( GECX_VERSION, get_option( GECX_Admin::PLUGIN_VERSION_OPTION ) );
        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
    }

    public function test_matching_version_does_not_trigger_sync_on_admin_init(): void {
        $this->seed_linked_store();
        update_option( GECX_Admin::AUTH_COMPLETE_OPTION, 1 );
        update_option( GECX_Admin::PLUGIN_VERSION_OPTION, GECX_VERSION );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->maybe_sync_on_version_change();

        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
    }

    public function test_version_change_records_version_without_sync_before_auth_complete(): void {
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        delete_option( GECX_Admin::AUTH_COMPLETE_OPTION );
        delete_option( GECX_Admin::PLUGIN_VERSION_OPTION );

        $admin = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
        $admin->maybe_sync_on_version_change();

        $this->assertEquals( GECX_VERSION, get_option( GECX_Admin::PLUGIN_VERSION_OPTION ) );
        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
    }
}

if ( php_sapi_name() === 'cli' && isset( $argv[0] ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
    gecx_run_test_class( SyncStateTest::class );
}
