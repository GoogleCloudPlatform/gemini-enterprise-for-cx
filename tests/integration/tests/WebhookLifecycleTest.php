<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Unit tests for GECX order webhook privacy minimization and lifecycle management.
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\REST\Order_Webhook;
use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use WC_Data_Store;
use WC_Order;
use WC_Order_Item_Product;
use WC_Webhook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class WebhookLifecycleTest extends TestCase {

    private REST_API $rest_api;
    private Admin $admin;

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( 1 );
        $this->rest_api = new REST_API();
        $this->admin    = new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
    }

    private function create_gecx_webhook( string $status = 'active', string $secret = 'wh_secret_abc' ): int {
        $webhook = new WC_Webhook();
        $webhook->set_name( 'GECX Agent Order Created' );
        $webhook->set_topic( 'order.created' );
        $webhook->set_status( $status );
        $webhook->set_secret( $secret );
        $id = $webhook->save();
        update_option( 'gecx_webhook_id', $id );
        return $id;
    }

    public function test_payload_minimized_to_backend_contract(): void {
        $webhook_id = $this->create_gecx_webhook();

        $full_payload = [
            'id'                   => 4242,
            'status'               => 'processing',
            'currency'             => 'EUR',
            'total'                => '125.50',
            'total_tax'            => '15.50',
            'shipping_total'       => '10.00',
            'customer_id'          => 99,
            'customer_ip_address'  => '203.0.113.50',
            'customer_user_agent'  => 'Mozilla/5.0',
            'customer_note'        => 'Leave at back door',
            'payment_method'       => 'stripe',
            'payment_method_title' => 'Credit Card',
            'billing'              => [
                'first_name' => 'Alice',
                'last_name'  => 'Smith',
                'email'      => 'alice@example.com',
                'phone'      => '+15550199',
                'address_1'  => '123 Secret St',
            ],
            'shipping'             => [
                'first_name' => 'Alice',
                'address_1'  => '123 Secret St',
            ],
            'meta_data'            => [
                [
                    'id'    => 1,
                    'key'   => '_stripe_intent_id',
                    'value' => 'pi_secret_123',
                ],
                [
                    'id'    => 2,
                    'key'   => '_gecx_session_id',
                    'value' => 'projects/12345/locations/global/commerceSessions/sess-abc-999',
                ],
                [
                    'id'    => 3,
                    'key'   => '_internal_note',
                    'value' => 'VIP customer',
                ],
            ],
            'line_items'           => [
                [
                    'id'           => 555,
                    'product_id'   => 101,
                    'variation_id' => 202,
                    'name'         => ' Merino Wool Sweater ',
                    'price'        => 50.0,
                    'quantity'     => 2,
                    'sku'          => 'MWS-01',
                    'subtotal'     => '100.00',
                    'taxes'        => [ 'total' => '15.50' ],
                    'meta_data'    => [ [ 'key' => 'size', 'value' => 'L' ] ],
                ],
            ],
            'coupon_lines'         => [ [ 'code' => 'VIP20' ] ],
            '_links'               => [ 'self' => [ [ 'href' => 'https://example.com/wp-json/wc/v3/orders/4242' ] ] ],
        ];

        $minimized = $this->rest_api->order_webhook->minimize_order_webhook_payload( $full_payload, 'order', 4242, $webhook_id );

        $this->assertSame(
            [
                'id',
                'currency',
                'total',
                'total_tax',
                'shipping_total',
                'meta_data',
                'line_items',
            ],
            array_keys( $minimized )
        );

        $this->assertSame( 4242, $minimized['id'] );
        $this->assertSame( 'EUR', $minimized['currency'] );
        $this->assertSame( '125.50', $minimized['total'] );
        $this->assertSame( '15.50', $minimized['total_tax'] );
        $this->assertSame( '10.00', $minimized['shipping_total'] );
        $this->assertSame(
            [
                [
                    'key'   => '_gecx_session_id',
                    'value' => 'projects/12345/locations/global/commerceSessions/sess-abc-999',
                ],
            ],
            $minimized['meta_data']
        );
        $this->assertSame(
            [
                [
                    'product_id'   => 101,
                    'variation_id' => 202,
                    'name'         => ' Merino Wool Sweater ',
                    'price'        => 50.0,
                    'quantity'     => 2,
                ],
            ],
            $minimized['line_items']
        );
    }

    public function test_payload_untouched_for_foreign_webhook(): void {
        $this->create_gecx_webhook();

        $foreign = new WC_Webhook();
        $foreign->set_name( 'Merchant ERP Webhook' );
        $foreign->set_topic( 'order.created' );
        $foreign_id = $foreign->save();

        $full_payload = [
            'id'      => 99,
            'billing' => [ 'email' => 'keepme@example.com' ],
        ];

        $result = $this->rest_api->order_webhook->minimize_order_webhook_payload( $full_payload, 'order', 99, $foreign_id );
        $this->assertSame( $full_payload, $result );
    }

    public function test_delivery_suppressed_without_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order    = wc_create_order();
        $order_id = $order->get_id();

        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order_id ) );
    }

    public function test_delivery_suppressed_with_invalid_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order = wc_create_order();
        $order->update_meta_data( '_gecx_session_id', 'invalid session spaces <script>' );
        $order->save();
        $order_id = $order->get_id();

        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order_id ) );
    }

    public function test_delivery_allowed_with_valid_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order = wc_create_order();
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-123' );
        $order->save();
        $order_id = $order->get_id();

        $this->assertTrue( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order_id ) );
    }

    public function test_delivery_suppressed_when_widget_disabled_or_unlinked(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        $order = wc_create_order();
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-123' );
        $order->save();
        $order_id = $order->get_id();

        // Linked but widget disabled
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 0 );
        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order_id ) );

        // Enabled but unlinked
        delete_option( 'gecx_agent_name' );
        update_option( 'gecx_agent_enabled', 1 );
        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order_id ) );
    }

    public function test_deactivation_pauses_webhook(): void {
        $webhook_id = $this->create_gecx_webhook( 'active' );

        Admin::deactivate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'paused', $webhook->get_status() );
    }

    public function test_activation_reconciles_webhook_when_linked(): void {
        $webhook_id = $this->create_gecx_webhook( 'paused' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        Admin::activate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'active', $webhook->get_status() );

        // When linked but widget disabled, activation keeps/sets it paused
        update_option( 'gecx_agent_enabled', 0 );
        $webhook->set_status( 'active' );
        $webhook->save();

        Admin::activate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'paused', $webhook->get_status() );
    }

    public function test_activation_sweeps_orphaned_webhooks_when_unlinked(): void {
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_webhook_id' );

        $orphan = new WC_Webhook();
        $orphan->set_name( 'GECX Agent Order Created' );
        $orphan->set_topic( 'order.created' );
        $orphan_id = $orphan->save();

        Admin::activate_plugin();

        $deleted_orphan = new WC_Webhook( $orphan_id );
        $this->assertSame( 0, $deleted_orphan->get_id() );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
    }

    public function test_widget_toggle_pauses_and_resumes_webhook(): void {
        $this->enable_ajax();
        $webhook_id = $this->create_gecx_webhook( 'active' );

        // Disable widget
        $_POST = [
            'nonce'   => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'enabled' => '0',
        ];
        $this->admin->settings_page->ajax_toggle_app_embed();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'paused', $webhook->get_status() );
        $this->assertSame( 0, get_option( 'gecx_agent_enabled' ) );

        // Enable widget
        $_POST = [
            'nonce'   => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'enabled' => '1',
        ];
        $this->admin->settings_page->ajax_toggle_app_embed();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'active', $webhook->get_status() );
        $this->assertSame( 1, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_unlink_pauses_webhook_and_preserves_secret(): void {
        $this->enable_ajax();
        $webhook_id = $this->create_gecx_webhook( 'active' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_api_secret', 'legacy_secret_to_be_deleted' );
        update_option( 'gecx_auth_complete', 1 );

        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];
        $this->http_responses[] = gecx_test_http_response( 200, '' );
        $this->admin->console_sync->ajax_unlink_agent();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( $webhook_id, $webhook->get_id() );
        $this->assertSame( 'paused', $webhook->get_status() );
        $this->assertSame( $webhook_id, get_option( 'gecx_webhook_id' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertSame( 1, get_option( 'gecx_auth_complete' ) );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_webhook_registration_does_not_end_merchant_unlink(): void {
        update_option( Admin::MERCHANT_UNLINKED_OPTION, 1 );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/webhooks/order-created' );
        $request->set_param( 'consumer_secret', 'cs_' . str_repeat( 'a', 40 ) );
        $response = ( new REST_API() )->order_webhook->order_created_webhooks_handler( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        // The WooCommerce keys survive an unlink, so this call is not evidence
        // that the merchant chose to re-link.
        $this->assertSame( 1, (int) get_option( Admin::MERCHANT_UNLINKED_OPTION ) );
    }

    public function test_link_agent_ends_unlink_but_respects_merchant_disable(): void {
        $webhook_id = $this->create_gecx_webhook( 'paused' );
        update_option( Admin::MERCHANT_UNLINKED_OPTION, 1 );
        update_option( Admin::MERCHANT_DISABLED_OPTION, 1 );
        update_option( 'gecx_agent_enabled', 0 );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
        $request->set_param( 'agent_name', 'projects/123/locations/global/agents/agent-2' );
        ( new REST_API() )->console->link_agent_handler( $request );

        $this->assertSame( 'projects/123/locations/global/agents/agent-2', get_option( 'gecx_agent_name' ) );
        $this->assertFalse( get_option( Admin::MERCHANT_UNLINKED_OPTION ) );
        $this->assertSame( 0, (int) get_option( 'gecx_agent_enabled' ) );
        $this->assertSame( 'paused', ( new WC_Webhook( $webhook_id ) )->get_status() );
    }

    public function test_ensure_order_webhook_refuses_disallowed_console_host(): void {
        delete_option( 'gecx_webhook_id' );
        update_option( 'gecx_console_base_url', 'https://attacker.example' );

        $result = Order_Webhook::ensure_order_webhook( 'cs_' . str_repeat( 'b', 40 ) );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'console_url_refused', $result->get_error_code() );
        $data_store = WC_Data_Store::load( 'webhook' );
        foreach ( $data_store->get_webhooks_ids() as $wh_id ) {
            $wh = new WC_Webhook( $wh_id );
            $this->assertStringNotContainsString( 'attacker.example', $wh->get_delivery_url() );
        }
    }

    public function test_uninstall_revokes_wc_auth_api_keys(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        $wpdb->insert( $table, [ 'key_id' => 7, 'user_id' => 1, 'description' => 'Gemini Enterprise For CX - API (2026-09-01 10:00:00)', 'permissions' => 'read_write', 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1', 'truncated_key' => '1' ] );
        $wpdb->insert( $table, [ 'key_id' => 8, 'user_id' => 1, 'description' => 'Some other app - API (2026-09-01 10:00:00)', 'permissions' => 'read_write', 'consumer_key' => 'ck_2', 'consumer_secret' => 'cs_2', 'truncated_key' => '2' ] );
        $wpdb->insert( $table, [ 'key_id' => 9, 'user_id' => 1, 'description' => 'gemini enterprise for cx - API (reporting)', 'permissions' => 'read', 'consumer_key' => 'ck_3', 'consumer_secret' => 'cs_3', 'truncated_key' => '3' ] );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $remaining_ids = array_map( 'intval', $wpdb->get_col( "SELECT key_id FROM {$table} ORDER BY key_id ASC" ) );
        $this->assertSame( [ 8, 9 ], $remaining_ids );
    }

    public function test_uninstall_does_not_notify_store_that_only_holds_a_keypair(): void {
        // Activation generates a keypair on every site, connected or not.
        Auth::get_or_generate_keypair();
        delete_option( 'gecx_webhook_id' );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_auth_complete' );
        $this->http_requests = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertCount( 0, $this->http_requests );
        $this->assertFalse( get_option( 'gecx_keypair' ) );
    }

    public function test_uninstall_does_not_notify_disallowed_console_host(): void {
        Auth::get_or_generate_keypair();
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_console_base_url', 'https://attacker.example' );
        $this->http_requests = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertCount( 0, $this->http_requests );
    }

    public function test_activation_pauses_webhook_when_unlinked_but_authorized(): void {
        $webhook_id = $this->create_gecx_webhook( 'active' );
        delete_option( 'gecx_agent_name' );
        update_option( 'gecx_auth_complete', 1 );

        Admin::activate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( $webhook_id, $webhook->get_id() );
        $this->assertSame( 'paused', $webhook->get_status() );
        $this->assertSame( $webhook_id, get_option( 'gecx_webhook_id' ) );
    }

    public function test_uninstall_deletes_row_and_notifies_with_bearer_without_woocommerce(): void {
        Auth::get_or_generate_keypair();
        $webhook_id = $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        delete_option( 'gecx_api_secret' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $deleted = new WC_Webhook( $webhook_id );
        $this->assertSame( 0, $deleted->get_id() );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );

        // Verify that the uninstall webhook notification was sent using the RS256 Bearer JWT without X-WC-Webhook-Signature
        $this->assertTrue( ! empty( $this->http_requests ) );
        $last_req = end( $this->http_requests );
        $this->assertSame( 'plugin/uninstalled', $last_req['args']['headers']['X-WC-Webhook-Topic'] );
        $this->assertTrue( isset( $last_req['args']['headers']['Authorization'] ) );
        $this->assertArrayNotHasKey( 'X-WC-Webhook-Signature', $last_req['args']['headers'] );
    }

    public function test_uninstall_sends_bearer_jwt_without_hmac_signature(): void {
        Auth::get_or_generate_keypair();
        $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $last_req = end( $this->http_requests );
        $headers  = $last_req['args']['headers'];

        // The RS256 Bearer JWT is the sole credential on plugin/uninstalled.
        $this->assertTrue( isset( $headers['Authorization'] ) );
        $this->assertSame( 0, strpos( $headers['Authorization'], 'Bearer ' ) );
        $this->assertArrayNotHasKey( 'X-WC-Webhook-Signature', $headers );

        $jwt   = substr( $headers['Authorization'], strlen( 'Bearer ' ) );
        $parts = explode( '.', $jwt );
        $this->assertCount( 3, $parts );

        $header = json_decode( Auth::from_base_64_url( $parts[0] ), true );
        $this->assertSame( 'RS256', $header['alg'] );

        $claims = json_decode( Auth::from_base_64_url( $parts[1] ), true );
        $this->assertSame( Auth::get_sanitized_store_domain(), $claims['iss'] );
        $this->assertSame( 'gecx.cloud.google.com', $claims['aud'] );
        $this->assertTrue( $claims['is_admin'] );
        $this->assertTrue( $claims['exp'] > $claims['iat'] );
    }

    public function test_uninstall_notifies_with_the_jwt_when_the_api_secret_is_gone(): void {
        Auth::get_or_generate_keypair();
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertTrue( ! empty( $this->http_requests ) );
        $last_req = end( $this->http_requests );
        $headers  = $last_req['args']['headers'];

        $this->assertSame( 'plugin/uninstalled', $headers['X-WC-Webhook-Topic'] );
        $this->assertTrue( isset( $headers['Authorization'] ) );
        $this->assertArrayNotHasKey( 'X-WC-Webhook-Signature', $headers );
    }

    public function test_uninstall_skips_webhook_and_keypair_generation_for_unconnected_store(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_auth_complete' );
        delete_option( 'gecx_keypair' );
        $this->http_requests = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertCount( 0, $this->http_requests );
        $this->assertFalse( get_option( 'gecx_keypair' ) );
    }

    public function test_uninstall_skips_notification_when_stored_keypair_is_corrupt(): void {
        $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option(
            'gecx_keypair',
            [
                'version'     => 1,
                'public_key'  => '-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----',
                'private_key' => [ 'iv' => 'bad', 'tag' => 'bad', 'ciphertext' => 'unreadable' ],
            ]
        );
        $this->http_requests = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        // Without a readable RS256 private key, uninstall must not mint an
        // ephemeral keypair or send an unauthenticated request.
        $this->assertCount( 0, $this->http_requests );
    }

    public function test_delivery_suppressed_with_alphanumeric_or_raw_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        // Raw UUID (non-resource name format)
        $order1 = wc_create_order();
        $order1->update_meta_data( '_gecx_session_id', 'TestSessionToken0123456789abcdefghijklmnopq' );
        $order1->save();
        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order1->get_id() ) );

        // Alphanumeric project ID instead of numeric project number
        $order2 = wc_create_order();
        $order2->update_meta_data( '_gecx_session_id', 'projects/my-cool-project/locations/global/commerceSessions/sess-123' );
        $order2->save();
        $this->assertFalse( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order2->get_id() ) );

        // Numeric project number (canonical resource name format) succeeds
        $order3 = wc_create_order();
        $order3->update_meta_data( '_gecx_session_id', 'projects/123456789012/locations/global/commerceSessions/sess-123' );
        $order3->save();
        $this->assertTrue( $this->rest_api->order_webhook->gate_order_webhook_delivery( true, $webhook, $order3->get_id() ) );
    }

    public function test_delete_order_webhook_clears_transients_on_direct_sql(): void {
        $this->create_gecx_webhook();
        set_transient( 'woocommerce_webhook_ids', [ 1 ] );
        set_transient( 'woocommerce_webhook_ids_status_active', [ 1 ] );

        Order_Webhook::delete_order_webhook();

        $this->assertFalse( get_transient( 'woocommerce_webhook_ids' ) );
        $this->assertFalse( get_transient( 'woocommerce_webhook_ids_status_active' ) );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
    }

    public function test_set_order_webhook_status_ignores_foreign_webhook_in_stored_id(): void {
        // Create an unrelated merchant webhook
        $foreign = new WC_Webhook();
        $foreign->set_name( 'Merchant Order Sync' );
        $foreign->set_topic( 'order.created' );
        $foreign->set_status( 'active' );
        $foreign_id = $foreign->save();

        // Simulate stale or hijacked gecx_webhook_id option
        update_option( 'gecx_webhook_id', $foreign_id );

        // Create the real GECX webhook
        $real_gecx = new WC_Webhook();
        $real_gecx->set_name( 'GECX Agent Order Created' );
        $real_gecx->set_topic( 'order.created' );
        $real_gecx->set_status( 'disabled' );
        $real_id = $real_gecx->save();

        // Pause webhooks
        Order_Webhook::set_order_webhook_status( 'paused' );

        // Foreign webhook must be completely untouched
        $foreign_reloaded = new WC_Webhook( $foreign_id );
        $this->assertSame( 'active', $foreign_reloaded->get_status(), 'Foreign webhook status must not be modified' );
        $this->assertSame( $foreign_id, $foreign_reloaded->get_id(), 'Foreign webhook must not be deleted' );

        // Real GECX webhook must be updated
        $real_reloaded = new WC_Webhook( $real_id );
        $this->assertSame( 'paused', $real_reloaded->get_status(), 'Real GECX webhook must be updated' );

        // Primary stored option must be updated to the real webhook ID
        $this->assertSame( $real_id, (int) get_option( 'gecx_webhook_id' ), 'Stored ID must be updated to real GECX webhook' );
    }

    public function test_minimize_order_webhook_payload_from_order_items_using_get_item_total(): void {
        $webhook_id = $this->create_gecx_webhook();

        $order = wc_create_order();
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-701' );
        $order->set_currency( 'USD' );

        $product_id = $this->factory()->post->create( [ 'post_type' => 'product' ] );

        $item = new WC_Order_Item_Product();
        $item->set_product_id( $product_id );
        $item->set_name( 'Custom T-Shirt' );
        $item->set_quantity( 3 );
        $item->set_total( '99.99' );
        $order->add_item( $item );
        $order->calculate_totals();
        $order->save();
        $order_id = $order->get_id();

        // When payload has no line_items, it falls back to $order->get_items()
        $payload = [
            'id'         => $order_id,
            'line_items' => null,
        ];

        $minimized = $this->rest_api->order_webhook->minimize_order_webhook_payload( $payload, 'order', $order_id, $webhook_id );

        $this->assertCount( 1, $minimized['line_items'] );
        $this->assertSame( $product_id, $minimized['line_items'][0]['product_id'] );
        $this->assertSame( 3, $minimized['line_items'][0]['quantity'] );
        // 99.99 / 3 = 33.33
        $this->assertSame( 33.33, $minimized['line_items'][0]['price'] );
    }

    public function test_uninstall_cleans_up_transients_and_woocommerce_session_rows(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        update_option( 'gecx_pending_oauth_states', [ 'tok123' => time() ] );
        set_transient( 'gecx_oauth_state_tok123', 1 );
        set_transient( 'gecx_oauth_state_orphan', 1 );
        set_transient( 'gecx_unknown_iss_hash1', 1 );
        set_transient( 'gecx_admin_notice_error', 'Some error' );
        set_transient( 'gecx_guest_jwt_cache', [ 'jwt' => 'x' ] );

        $this->set_wc_session(
            't_shopper_42',
            [
                'cart'            => 'serialized_cart',
                'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-42',
            ]
        );

        include dirname( __DIR__, 3 ) . '/uninstall.php';

        $this->assertFalse( get_transient( 'gecx_oauth_state_tok123' ) );
        $this->assertFalse( get_transient( 'gecx_oauth_state_orphan' ) );
        $this->assertFalse( get_transient( 'gecx_unknown_iss_hash1' ) );
        $this->assertFalse( get_transient( 'gecx_admin_notice_error' ) );
        $this->assertFalse( get_transient( 'gecx_guest_jwt_cache' ) );

        $updated_session = $this->get_wc_session( 't_shopper_42' );
        $this->assertIsArray( $updated_session );
        $this->assertArrayNotHasKey( 'gecx_session_id', $updated_session );
        $this->assertSame( 'serialized_cart', $updated_session['cart'] );
    }
}
