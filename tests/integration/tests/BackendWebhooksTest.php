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

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\Backend_Webhooks;
use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use WC_Webhook;
use WP_REST_Request;

class BackendWebhooksTest extends RestTestCase {

    private const PROD_URL    = 'https://gecx.cloud.google.com/woocommerce/webhook';
    private const STAGING_URL = 'https://gecx-agentis-express-console-staging.cloud.google.com/woocommerce/webhook';

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( 1 );
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_webhooks" );
    }

    private function create_webhook( string $name, string $topic, string $delivery_url, string $status = 'active' ): int {
        $webhook = new WC_Webhook();
        $webhook->set_name( $name );
        $webhook->set_user_id( 1 );
        $webhook->set_topic( $topic );
        $webhook->set_delivery_url( $delivery_url );
        $webhook->set_secret( 'cs_secret' );
        $webhook->set_status( $status );
        $webhook->set_api_version( 'wp_api_v3' );
        return (int) $webhook->save();
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
        global $wpdb;
        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id = %d",
                $id
            )
        );
        return null !== $status ? (string) $status : null;
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
		include dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	/**
	 * Creates temporary tables on the current subsite for wc_webhooks and
	 * Action Scheduler, and answers SHOW TABLES LIKE for the temporary
	 * wc_webhooks table (MySQL's SHOW TABLES omits temporary tables).
	 */
	private function create_subsite_tables(): void {
		global $wpdb;
		foreach ( [ 'wc_webhooks', 'actionscheduler_actions', 'actionscheduler_groups' ] as $suffix ) {
			$base_table = $wpdb->base_prefix . $suffix;
			$sub_table  = $wpdb->prefix . $suffix;
			$wpdb->query( "CREATE TEMPORARY TABLE IF NOT EXISTS {$sub_table} LIKE {$base_table}" );
		}
		$webhooks_table = $wpdb->prefix . 'wc_webhooks';
		$expected_sql   = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $webhooks_table ) );
		add_filter(
			'query',
			static function ( string $sql ) use ( $webhooks_table, $expected_sql ): string {
				if ( $expected_sql === $sql ) {
					return "SELECT '{$webhooks_table}'";
				}
				return $sql;
			}
		);
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
        $wh_restored = new WC_Webhook( $ids['restored'] );
        $wh_restored->set_status( 'paused' );
        $wh_restored->save();
        // Disabled by WooCommerce after failed deliveries: must stay disabled.
        $wh_deleted = new WC_Webhook( $ids['deleted'] );
        $wh_deleted->set_status( 'disabled' );
        $wh_deleted->save();
        $wh_erp = new WC_Webhook( $ids['merchant_erp'] );
        $wh_erp->set_status( 'paused' );
        $wh_erp->save();

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

    public function test_unlink_pauses_backend_webhooks_and_link_resumes_them(): void {
        $this->link_agent();
        $ids   = $this->seed_store();
        $admin = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        $this->http_responses[] = gecx_test_http_response( 200, '' );
        $response               = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/unlink-agent' ) );
        $this->assertSame( 200, $response->get_status() );

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
        $wh_updated = new WC_Webhook( $ids['updated'] );
        $wh_updated->set_status( 'paused' );
        $wh_updated->save();
        $wh_deleted = new WC_Webhook( $ids['deleted'] );
        $wh_deleted->set_status( 'disabled' );
        $wh_deleted->save();
        $staging = $this->create_webhook( 'Product created', 'product.created', self::STAGING_URL );

        $this->run_uninstall();

        foreach ( $this->backend_ids( $ids ) as $id ) {
            $this->assertNull( $this->status( $id ) );
        }
        $this->assertNull( $this->status( $staging ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );
        $this->assertSame( 'active', $this->status( $ids['merchant_zap'] ) );
        $this->assertFalse( get_option( Backend_Webhooks::PAUSED_IDS_OPTION ) );
    }

    public function test_uninstall_notifies_the_backend_before_deleting_its_webhooks(): void {
        Auth::get_or_generate_keypair();
        $this->link_agent();
        $ids = $this->seed_store();

        $present_at_notify = null;
        $unsubscribe       = $this->subscribe_to_wp_http_requests(
            function () use ( &$present_at_notify, $ids ): void {
                $present_at_notify = null !== $this->status( $ids['created'] );
            },
            gecx_test_http_response( 200, '' )
        );

        try {
            $this->run_uninstall();
        } finally {
            $unsubscribe();
        }

        $this->assertTrue( $present_at_notify, 'The backend webhooks must still exist when plugin/uninstalled is sent.' );
        $this->assertNull( $this->status( $ids['created'] ) );
    }

    /**
     * @group ms-required
     */
    public function test_uninstall_deletes_backend_webhooks_on_every_site(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite only.' );
        }

        $this->link_agent();
        $ids = $this->seed_store();

		$site_2 = (int) self::factory()->blog->create();
		switch_to_blog( $site_2 );
		$this->create_subsite_tables();
		$this->link_agent();
		$ids_site_2 = $this->seed_store();
		restore_current_blog();

		$this->run_uninstall();

		$this->assertNull( $this->status( $ids['created'] ) );
		$this->assertSame( 'active', $this->status( $ids['merchant_erp'] ) );

		switch_to_blog( $site_2 );
		$this->assertNull( $this->status( $ids_site_2['created'] ) );
		$this->assertSame( 'active', $this->status( $ids_site_2['merchant_erp'] ) );
		restore_current_blog();
	}

	/**
	 * @group ms-required
	 */
	public function test_network_deactivation_and_activation_manage_order_and_backend_webhooks_per_subsite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$site_2 = (int) self::factory()->blog->create();
		switch_to_blog( $site_2 );
		$this->create_subsite_tables();
		$this->link_agent();
        update_option( 'gecx_dismiss_activation_notice', 1 );
        $order_wh  = $this->create_webhook( 'GECX Agent Order Created', 'order.created', self::PROD_URL );
        update_option( 'gecx_webhook_id', $order_wh );
        $catalog   = $this->create_webhook( 'Product updated', 'product.updated', self::PROD_URL );
        $merchant  = $this->create_webhook( 'Merchant ERP', 'order.created', 'https://erp.example.com/woocommerce/webhook' );
        wp_schedule_single_event( time() + 60, Admin::VERSION_SYNC_CRON_HOOK );
        restore_current_blog();

        Admin::deactivate_plugin( true );

        switch_to_blog( $site_2 );
        $this->assertSame( 'paused', $this->status( $order_wh ) );
        $this->assertSame( 'paused', $this->status( $catalog ) );
        $this->assertSame( 'active', $this->status( $merchant ) );
        $this->assertFalse( wp_next_scheduled( Admin::VERSION_SYNC_CRON_HOOK ) );
        restore_current_blog();

        Admin::activate_plugin( true );

        $this->assertFalse( get_option( 'gecx_do_activation_redirect' ) );
        switch_to_blog( $site_2 );
        $this->assertFalse( get_option( 'gecx_do_activation_redirect' ) );
        $this->assertFalse( get_option( 'gecx_dismiss_activation_notice' ) );
        $this->assertSame( 'active', $this->status( $order_wh ) );
        $this->assertSame( 'active', $this->status( $catalog ) );
        $this->assertSame( 'active', $this->status( $merchant ) );
        restore_current_blog();
    }
}
