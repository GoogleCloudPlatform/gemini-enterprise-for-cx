<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Unit tests for pausing, resuming and deleting the webhooks the Gemini
 * Enterprise for CX backend registers on the store.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\Backend_Webhooks;
use Google\Gemini_Enterprise_For_CX\REST\REST_API;

class BackendWebhooksTest extends GECX_TestCase {

    private const PROD_URL    = 'https://gecx.cloud.google.com/woocommerce/webhook';
    private const STAGING_URL = 'https://gecx-agentis-express-console-staging.cloud.google.com/woocommerce/webhook';

    private function create_webhook( string $name, string $topic, string $delivery_url, string $status = 'active' ): int {
        // Written directly: the mock WC_Webhook numbers new rows by count,
        // which collides once a row has been deleted.
        $id = empty( $GLOBALS['gecx_test_webhooks'] ) ? 18 : max( array_keys( $GLOBALS['gecx_test_webhooks'] ) ) + 1;
        $GLOBALS['gecx_test_webhooks'][ $id ] = [
            'id'           => $id,
            'name'         => $name,
            'user_id'      => 1,
            'topic'        => $topic,
            'delivery_url' => $delivery_url,
            'secret'       => 'cs_secret',
            'status'       => $status,
            'api_version'  => 'wp_api_v3',
        ];
        return $id;
    }

    /**
     * The webhooks vitalpawraw.net shows (ids 18-22), plus two of the
     * merchant's own that must never be touched.
     *
     * @return array<string, int>
     */
    private function seed_store(): array {
        return [
            'created'      => $this->create_webhook( 'Product created', 'product.created', self::PROD_URL ),
            'updated'      => $this->create_webhook( 'Product updated', 'product.updated', self::PROD_URL ),
            'deleted'      => $this->create_webhook( 'Product deleted', 'product.deleted', self::PROD_URL ),
            'restored'     => $this->create_webhook( 'Product restored', 'product.restored', self::PROD_URL ),
            'order'        => $this->create_webhook( 'Order created', 'order.created', self::PROD_URL ),
            'merchant_erp' => $this->create_webhook( 'ERP product sync', 'product.updated', 'https://erp.example.com/woocommerce/webhook' ),
            'merchant_zap' => $this->create_webhook( 'Zapier orders', 'order.created', 'https://hooks.zapier.com/hooks/catch/1/abc' ),
        ];
    }

    private function status( int $id ): ?string {
        return $GLOBALS['gecx_test_webhooks'][ $id ]['status'] ?? null;
    }

    private function backend_ids( array $ids ): array {
        return array_intersect_key( $ids, array_flip( [ 'created', 'updated', 'deleted', 'restored', 'order' ] ) );
    }

    private function link_agent(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_auth_complete', 1 );
    }

    private function run_uninstall(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
        include dirname( __DIR__ ) . '/uninstall.php';
    }

    /**
     * @dataProvider delivery_urls
     */
    public function test_is_backend_delivery_url( string $url, bool $expected ): void {
        $this->assertSame( $expected, Backend_Webhooks::is_backend_delivery_url( $url ) );
    }

    public function delivery_urls(): array {
        return [
            'production'               => [ self::PROD_URL, true ],
            'staging'                  => [ self::STAGING_URL, true ],
            'trailing slash'           => [ self::PROD_URL . '/', true ],
            'upper-case host'          => [ 'https://GECX.cloud.google.com/woocommerce/webhook', true ],
            'look-alike host'          => [ 'https://gecx.cloud.google.com.example.net/woocommerce/webhook', false ],
            'subdomain of ours'        => [ 'https://evil.gecx.cloud.google.com/woocommerce/webhook', false ],
            'merchant host, same path' => [ 'https://erp.example.com/woocommerce/webhook', false ],
            'woo-auth sibling path'    => [ 'https://gecx.cloud.google.com/woocommerce/webhook/woo-auth', false ],
            'other path'               => [ 'https://gecx.cloud.google.com/other', false ],
            'not a URL'                => [ 'woocommerce/webhook', false ],
            'empty'                    => [ '', false ],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_hosts_added_through_the_allowlist_constant_count_as_ours(): void {
        if ( ! defined( 'GECX_CONSOLE_ALLOWED_HOSTS' ) ) {
            define( 'GECX_CONSOLE_ALLOWED_HOSTS', 'gecx-dev.example.google.com' );
        }
        $this->assertContains( 'gecx-dev.example.google.com', Auth::get_allowed_console_hosts() );
        $this->assertTrue( Backend_Webhooks::is_backend_delivery_url( 'https://gecx-dev.example.google.com/woocommerce/webhook' ) );
    }

    public function test_deactivation_pauses_backend_webhooks_and_leaves_merchant_ones(): void {
        $this->link_agent();
        $ids = $this->seed_store();

        Admin::deactivate_plugin();

        foreach ( $this->backend_ids( $ids ) as $id ) {
            $this->assertSame( 'paused', $this->status( $id ) );
        }
        $this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_zap'] ) );
    }

    public function test_activation_resumes_only_what_deactivation_paused(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        // Paused by the merchant before deactivation: must stay paused.
        $GLOBALS['gecx_test_webhooks'][ $ids['restored'] ]['status'] = 'paused';
        // Disabled by WooCommerce after failed deliveries: must stay disabled.
        $GLOBALS['gecx_test_webhooks'][ $ids['deleted'] ]['status'] = 'disabled';
        $GLOBALS['gecx_test_webhooks'][ $ids['merchant_erp'] ]['status'] = 'paused';

        Admin::deactivate_plugin();
        Admin::activate_plugin();

        $this->assertSame( 'active', $this->status( $ids['created'] ) );
        $this->assertSame( 'active', $this->status( $ids['updated'] ) );
        $this->assertSame( 'active', $this->status( $ids['order'] ) );
        $this->assertSame( 'paused', $this->status( $ids['restored'] ) );
        $this->assertSame( 'disabled', $this->status( $ids['deleted'] ) );
        $this->assertSame( 'paused', $this->status( $ids['merchant_erp'] ) );
        $this->assertFalse( get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );
    }

    public function test_pause_and_resume_are_idempotent(): void {
        $this->link_agent();
        $ids = $this->seed_store();

        Admin::deactivate_plugin();
        Admin::deactivate_plugin();
        $this->assertEqualsCanonicalizing( array_values( $this->backend_ids( $ids ) ), get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );

        Admin::activate_plugin();
        Admin::activate_plugin();
        foreach ( $this->backend_ids( $ids ) as $id ) {
            $this->assertSame( 'active', $this->status( $id ) );
        }
    }

    public function test_activation_keeps_webhooks_paused_for_an_unlinked_store(): void {
        $ids = $this->seed_store();
        Backend_Webhooks::pause_webhooks();

        Admin::activate_plugin();

        $this->assertSame( 'paused', $this->status( $ids['created'] ) );
        $this->assertNotEmpty( get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );
    }

    public function test_pause_and_resume_leave_the_plugins_order_webhook_to_order_webhook(): void {
        $this->link_agent();
        update_option( 'gecx_agent_enabled', 0 );
        $own = $this->create_webhook( 'GECX Agent Order Created', 'order.created', self::PROD_URL, 'paused' );
        update_option( 'gecx_webhook_id', $own );
        $catalog = $this->create_webhook( 'Product updated', 'product.updated', self::PROD_URL );

        Backend_Webhooks::pause_webhooks();
        Backend_Webhooks::resume_webhooks();

        // The widget is off, so the order webhook stays paused.
        $this->assertSame( 'paused', $this->status( $own ) );
        $this->assertSame( 'active', $this->status( $catalog ) );
    }

    public function test_pause_and_resume_without_woocommerce_write_the_table(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        $GLOBALS['gecx_test_disable_wc_webhook'] = true;
        set_transient( 'woocommerce_webhook_ids_status_active', [ 1 ] );

        Backend_Webhooks::pause_webhooks();
        $this->assertSame( 'paused', $this->status( $ids['created'] ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );
        $this->assertFalse( get_transient( 'woocommerce_webhook_ids_status_active' ) );

        Backend_Webhooks::resume_webhooks();
        $this->assertSame( 'active', $this->status( $ids['created'] ) );
    }

    public function test_unlink_pauses_backend_webhooks_and_link_resumes_them(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $admin = new Admin( dirname( __DIR__ ) . '/gecx-agent.php' );

        $_POST = [ 'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ) ];
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 200, '' );
        $admin->console_sync->ajax_unlink_agent();

        $this->assertSame( 'paused', $this->status( $ids['created'] ) );
        $this->assertSame( 'paused', $this->status( $ids['order'] ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );

        // Reactivating an unlinked store does not resume them...
        Admin::activate_plugin();
        $this->assertSame( 'paused', $this->status( $ids['created'] ) );

        // ...linking an agent again does.
        $request = new WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
        $request->set_param( 'agent_name', 'projects/123/locations/global/agents/agent-2' );
        ( new REST_API() )->console->link_agent_handler( $request );

        $this->assertSame( 'active', $this->status( $ids['created'] ) );
        $this->assertSame( 'active', $this->status( $ids['order'] ) );
    }

    public function test_uninstall_deletes_backend_webhooks_and_keeps_merchant_ones(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        $GLOBALS['gecx_test_webhooks'][ $ids['updated'] ]['status'] = 'paused';
        $GLOBALS['gecx_test_webhooks'][ $ids['deleted'] ]['status'] = 'disabled';
        $staging = $this->create_webhook( 'Product created', 'product.created', self::STAGING_URL );

        $this->run_uninstall();

        foreach ( $this->backend_ids( $ids ) as $id ) {
            $this->assertArrayNotHasKey( $id, $GLOBALS['gecx_test_webhooks'] );
        }
        $this->assertArrayNotHasKey( $staging, $GLOBALS['gecx_test_webhooks'] );
        $this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_zap'] ) );
        $this->assertFalse( get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );
    }

    public function test_uninstall_deletes_backend_webhooks_without_woocommerce(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        Backend_Webhooks::pause_webhooks();
        $GLOBALS['gecx_test_disable_wc_webhook'] = true;

        $this->run_uninstall();

        foreach ( $this->backend_ids( $ids ) as $id ) {
            $this->assertArrayNotHasKey( $id, $GLOBALS['gecx_test_webhooks'] );
        }
        $this->assertArrayHasKey( $ids['merchant_erp'], $GLOBALS['gecx_test_webhooks'] );
        $this->assertArrayHasKey( $ids['merchant_zap'], $GLOBALS['gecx_test_webhooks'] );
        $this->assertFalse( get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );
    }

    public function test_uninstall_notifies_the_backend_before_deleting_its_webhooks(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        Auth::get_or_generate_keypair();
        $this->link_agent();
        $ids = $this->seed_store();

        $present_at_notify = null;
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 200, '' );
        $GLOBALS['gecx_test_http_observer']    = static function () use ( &$present_at_notify, $ids ): void {
            $present_at_notify = isset( $GLOBALS['gecx_test_webhooks'][ $ids['created'] ] );
        };

        $this->run_uninstall();

        $this->assertCount( 1, $GLOBALS['gecx_test_http_requests'] );
        $this->assertSame( 'plugin/uninstalled', $GLOBALS['gecx_test_http_requests'][0]['args']['headers']['X-WC-Webhook-Topic'] );
        $this->assertTrue( $present_at_notify, 'The backend webhooks must still exist when plugin/uninstalled is sent.' );
        $this->assertArrayNotHasKey( $ids['created'], $GLOBALS['gecx_test_webhooks'] );
    }

    public function test_uninstall_survives_a_failing_webhook_delete(): void {
        $this->link_agent();
        $ids = $this->seed_store();
        $GLOBALS['gecx_test_webhook_delete_throws'] = $ids['created'];

        $this->run_uninstall();

        $this->assertArrayHasKey( $ids['created'], $GLOBALS['gecx_test_webhooks'] );
        $this->assertArrayNotHasKey( $ids['updated'], $GLOBALS['gecx_test_webhooks'] );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
        $this->assertNotEmpty( $GLOBALS['gecx_test_logs'] );
    }

    public function test_uninstall_deletes_backend_webhooks_on_every_site(): void {
        $GLOBALS['gecx_test_is_multisite'] = true;
        $GLOBALS['gecx_test_sites']        = [ 1, 4 ];
        $this->link_agent();
        $ids = $this->seed_store();

        $this->run_uninstall();

        $this->assertEquals( [ 1, 4 ], $GLOBALS['gecx_test_switched_blogs'] );
        $this->assertArrayNotHasKey( $ids['created'], $GLOBALS['gecx_test_webhooks'] );
        $this->assertArrayHasKey( $ids['merchant_erp'], $GLOBALS['gecx_test_webhooks'] );
    }

    public function test_network_deactivation_visits_every_site(): void {
        $GLOBALS['gecx_test_is_multisite'] = true;
        $GLOBALS['gecx_test_sites']        = [ 1, 4, 7 ];
        $this->link_agent();
        $ids = $this->seed_store();

        Admin::deactivate_plugin( true );

        $this->assertEquals( [ 1, 4, 7 ], $GLOBALS['gecx_test_switched_blogs'] );
        $this->assertEquals( [], $GLOBALS['gecx_test_blog_stack'] );
        $this->assertSame( 'paused', $this->status( $ids['created'] ) );
    }

    public function test_network_deactivation_and_activation_manage_order_and_backend_webhooks_per_subsite(): void {
        $GLOBALS['gecx_test_is_multisite']    = true;
        $GLOBALS['gecx_test_sites']           = [ 1, 4 ];
        // Simulate WooCommerce active on sub-site 4 rather than network-wide,
        // so Network Admin runs without WC_Webhook loaded.
        $GLOBALS['gecx_test_disable_wc_webhook'] = true;
        $GLOBALS['gecx_test_per_site_stores'] = [
            1 => [
                'options'          => [],
                'webhooks'         => [],
                'scheduled_events' => [],
            ],
            4 => [
                'options'          => [
                    'gecx_agent_name'                => 'projects/123/locations/global/agents/agent-4',
                    'gecx_agent_enabled'             => 1,
                    'gecx_auth_complete'             => 1,
                    'gecx_webhook_id'                => 51,
                    'gecx_dismiss_activation_notice' => 1,
                ],
                'webhooks'         => [
                    51 => [
                        'id'           => 51,
                        'name'         => 'GECX Agent Order Created',
                        'user_id'      => 1,
                        'topic'        => 'order.created',
                        'delivery_url' => self::PROD_URL,
                        'secret'       => 'cs_secret',
                        'status'       => 'active',
                        'api_version'  => 'wp_api_v3',
                    ],
                    52 => [
                        'id'           => 52,
                        'name'         => 'Product updated',
                        'user_id'      => 1,
                        'topic'        => 'product.updated',
                        'delivery_url' => self::PROD_URL,
                        'secret'       => 'cs_secret',
                        'status'       => 'active',
                        'api_version'  => 'wp_api_v3',
                    ],
                    53 => [
                        'id'           => 53,
                        'name'         => 'Merchant ERP',
                        'user_id'      => 1,
                        'topic'        => 'order.created',
                        'delivery_url' => 'https://erp.example.com/woocommerce/webhook',
                        'secret'       => 'cs_secret',
                        'status'       => 'active',
                        'api_version'  => 'wp_api_v3',
                    ],
                ],
                'scheduled_events' => [
                    [
                        'timestamp' => time() + 60,
                        'hook'      => Admin::VERSION_SYNC_CRON_HOOK,
                        'args'      => [],
                    ],
                ],
            ],
        ];

        Admin::deactivate_plugin( true );

        $site4 = $GLOBALS['gecx_test_per_site_stores'][4];
        $this->assertSame( 'paused', $site4['webhooks'][51]['status'] );
        $this->assertSame( 'paused', $site4['webhooks'][52]['status'] );
        $this->assertSame( 'active', $site4['webhooks'][53]['status'] );
        $this->assertSame( [], $site4['scheduled_events'] );

        Admin::activate_plugin( true );

        $site1 = $GLOBALS['gecx_test_per_site_stores'][1];
        $site4 = $GLOBALS['gecx_test_per_site_stores'][4];
        $this->assertArrayNotHasKey( 'gecx_do_activation_redirect', $site1['options'] );
        $this->assertArrayNotHasKey( 'gecx_do_activation_redirect', $site4['options'] );
        $this->assertArrayNotHasKey( 'gecx_dismiss_activation_notice', $site4['options'] );
        $this->assertSame( 'active', $site4['webhooks'][51]['status'] );
        $this->assertSame( 'active', $site4['webhooks'][52]['status'] );
        $this->assertSame( 'active', $site4['webhooks'][53]['status'] );
    }
}

if ( php_sapi_name() === 'cli' && isset( $argv[0] ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
    gecx_run_test_class( BackendWebhooksTest::class );
}
