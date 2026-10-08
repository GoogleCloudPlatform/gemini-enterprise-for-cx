<?php
/**
 * Runtime integration test covering plugin uninstallation sweep.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

/**
 * Tests the complete uninstall sweep of options, transients, and webhooks.
 */
class UninstallTest extends GECX_TestCase {

	/**
	 * Asserts uninstall.php wipes all plugin options, transients, and webhooks.
	 */
	public function test_uninstall_deletes_all_options_transients_and_webhooks(): void {
		// Seed options
		update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/456' );
		update_option( 'gecx_auth_complete', 1 );
		update_option( 'gecx_agent_enabled', 'yes' );
		update_option( 'gecx_pdp_prompts_enabled', 'yes' );
		update_option( 'gecx_button_placement', 'floating' );
		update_option( 'gecx_plugin_version', '0.0.0-test' );
		update_option( 'gecx_console_base_url', 'https://127.0.0.1' );
		update_option( 'gecx_keypair', [ 'public' => 'pub', 'private' => 'priv' ] );
		update_option( 'gecx_api_secret', 'secret' );

		// Seed transients
		set_transient( 'gecx_admin_notice_error', 'temporary error', 300 );
		set_transient( 'gecx_guest_jwt_cache', 'cached jwt', 300 );

		// Seed webhook if WooCommerce is active
		if ( class_exists( 'WC_Webhook' ) ) {
			$webhook = new \WC_Webhook();
			$webhook->set_name( 'GECX Agent Order Created' );
			$webhook->set_topic( 'order.created' );
			$webhook->set_delivery_url( 'https://example.com/gecx/webhook' );
			$webhook->set_status( 'active' );
			$webhook_id = $webhook->save();
			update_option( 'gecx_webhook_id', $webhook_id );
		}

		// Run uninstall.php under WP_UNINSTALL_PLUGIN
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gemini-enterprise-for-cx/gecx-agent.php' );
		}

		require dirname( __DIR__ ) . '/uninstall.php';

		// Verify options are removed
		$options = [
			'gecx_webhook_id',
			'gecx_agent_name',
			'gecx_auth_complete',
			'gecx_agent_enabled',
			'gecx_pdp_prompts_enabled',
			'gecx_button_placement',
			'gecx_plugin_version',
			'gecx_console_base_url',
			'gecx_keypair',
			'gecx_api_secret',
		];
		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} was not deleted by uninstall." );
		}

		// Verify transients are removed
		foreach ( [ 'gecx_admin_notice_error', 'gecx_guest_jwt_cache' ] as $transient ) {
			$this->assertFalse( get_transient( $transient ), "Transient {$transient} was not deleted by uninstall." );
		}

		// Verify webhook is removed
		if ( class_exists( 'WC_Data_Store' ) ) {
			$data_store  = \WC_Data_Store::load( 'webhook' );
			$webhook_ids = $data_store->search_webhooks( [
				'search' => 'GECX Agent Order Created',
				'limit'  => 50,
			] );
			$this->assertEmpty( $webhook_ids, 'GECX Webhook was not deleted by uninstall.' );
		}
	}
}
