<?php
/**
 * Unit tests for GECX order webhook privacy minimization and lifecycle management.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-rest-api.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-admin.php';

use PHPUnit\Framework\TestCase;

class WebhookLifecycleTest extends TestCase {

    private GECX_Rest_API $rest_api;
    private GECX_Admin $admin;

    protected function setUp(): void {
        gecx_reset_test_globals();
        $this->rest_api = new GECX_Rest_API();
        $this->admin    = new GECX_Admin( dirname( __DIR__ ) . '/gecx-agent.php' );
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

        $minimized = $this->rest_api->minimize_order_webhook_payload( $full_payload, 'order', 4242, $webhook_id );

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

        $result = $this->rest_api->minimize_order_webhook_payload( $full_payload, 'order', 99, $foreign_id );
        $this->assertSame( $full_payload, $result );
    }

    public function test_delivery_suppressed_without_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order = new WC_Order( 500 );
        $order->save();

        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 500 ) );
    }

    public function test_delivery_suppressed_with_invalid_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order = new WC_Order( 501 );
        $order->update_meta_data( '_gecx_session_id', 'invalid session spaces <script>' );
        $order->save();

        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 501 ) );
    }

    public function test_delivery_allowed_with_valid_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $order = new WC_Order( 502 );
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-123' );
        $order->save();

        $this->assertTrue( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 502 ) );
    }

    public function test_delivery_suppressed_when_widget_disabled_or_unlinked(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );

        $order = new WC_Order( 503 );
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-123' );
        $order->save();

        // Linked but widget disabled
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 0 );
        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 503 ) );

        // Enabled but unlinked
        delete_option( 'gecx_agent_name' );
        update_option( 'gecx_agent_enabled', 1 );
        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 503 ) );
    }

    public function test_deactivation_pauses_webhook(): void {
        $webhook_id = $this->create_gecx_webhook( 'active' );

        GECX_Admin::deactivate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'paused', $webhook->get_status() );
    }

    public function test_activation_reconciles_webhook_when_linked(): void {
        $webhook_id = $this->create_gecx_webhook( 'paused' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        GECX_Admin::activate_plugin();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'active', $webhook->get_status() );

        // When linked but widget disabled, activation keeps/sets it paused
        update_option( 'gecx_agent_enabled', 0 );
        $webhook->set_status( 'active' );
        $webhook->save();

        GECX_Admin::activate_plugin();

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

        GECX_Admin::activate_plugin();

        $this->assertArrayNotHasKey( $orphan_id, $GLOBALS['gecx_test_webhooks'] );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
    }

    public function test_widget_toggle_pauses_and_resumes_webhook(): void {
        $webhook_id = $this->create_gecx_webhook( 'active' );
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );

        // Disable widget
        $_POST = [
            'nonce'   => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'enabled' => '0',
        ];
        $this->admin->ajax_toggle_app_embed();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'paused', $webhook->get_status() );
        $this->assertSame( 0, get_option( 'gecx_agent_enabled' ) );

        // Enable widget
        $_POST = [
            'nonce'   => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'enabled' => '1',
        ];
        $this->admin->ajax_toggle_app_embed();

        $webhook = new WC_Webhook( $webhook_id );
        $this->assertSame( 'active', $webhook->get_status() );
        $this->assertSame( 1, get_option( 'gecx_agent_enabled' ) );
    }

    public function test_unlink_deletes_webhook_and_secret(): void {
        $webhook_id = $this->create_gecx_webhook( 'active' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_api_secret', 'secret_to_be_deleted' );
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );

        $_POST = [
            'nonce' => wp_create_nonce( 'gecx_save_agent_nonce' ),
        ];
        $GLOBALS['gecx_test_http_responses'][] = gecx_test_http_response( 200, '' );
        $this->admin->ajax_unlink_agent();

        $this->assertArrayNotHasKey( $webhook_id, $GLOBALS['gecx_test_webhooks'] );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );
        $this->assertFalse( get_option( 'gecx_api_secret' ) );
        $this->assertFalse( get_option( 'gecx_agent_name' ) );
    }

    public function test_uninstall_deletes_row_and_extracts_secret_without_woocommerce(): void {
        $webhook_id = $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        delete_option( 'gecx_api_secret' );

        // Simulate WooCommerce being deactivated before plugin uninstall
        $GLOBALS['gecx_test_disable_wc_webhook'] = true;

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertArrayNotHasKey( $webhook_id, $GLOBALS['gecx_test_webhooks'] );
        $this->assertFalse( get_option( 'gecx_webhook_id' ) );

        // Verify that the uninstall webhook notification was sent and signed using the secret extracted via $wpdb
        $this->assertTrue( ! empty( $GLOBALS['gecx_test_http_requests'] ) );
        $last_req = end( $GLOBALS['gecx_test_http_requests'] );
        $this->assertSame( 'plugin/uninstalled', $last_req['args']['headers']['X-WC-Webhook-Topic'] );
        $expected_sig = base64_encode( hash_hmac( 'sha256', $last_req['args']['body'], 'wh_db_secret_789', true ) );
        $this->assertSame( $expected_sig, $last_req['args']['headers']['X-WC-Webhook-Signature'] );
    }

    public function test_uninstall_sends_a_bearer_jwt_alongside_the_hmac_signature(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        GECX_Auth::get_or_generate_keypair();
        $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $last_req = end( $GLOBALS['gecx_test_http_requests'] );
        $headers  = $last_req['args']['headers'];

        // The JWT is what the backend authenticates on.
        $this->assertTrue( isset( $headers['Authorization'] ) );
        $this->assertSame( 0, strpos( $headers['Authorization'], 'Bearer ' ) );

        $jwt   = substr( $headers['Authorization'], strlen( 'Bearer ' ) );
        $parts = explode( '.', $jwt );
        $this->assertCount( 3, $parts );

        $header = json_decode( GECX_Auth::from_base_64_url( $parts[0] ), true );
        $this->assertSame( 'RS256', $header['alg'] );

        $claims = json_decode( GECX_Auth::from_base_64_url( $parts[1] ), true );
        $this->assertSame( GECX_Auth::get_sanitized_store_domain(), $claims['iss'] );
        $this->assertSame( 'gecx.cloud.google.com', $claims['aud'] );
        $this->assertTrue( $claims['is_admin'] );
        $this->assertTrue( $claims['exp'] > $claims['iat'] );

        // The legacy signature stays until the backend drops that path.
        $expected_sig = base64_encode( hash_hmac( 'sha256', $last_req['args']['body'], 'wh_db_secret_789', true ) );
        $this->assertSame( $expected_sig, $headers['X-WC-Webhook-Signature'] );
    }

    public function test_uninstall_notifies_with_the_jwt_when_the_api_secret_is_gone(): void {
        // A store that has been unlinked no longer holds an API secret or an
        // order webhook, so the HMAC path has nothing to sign with. The
        // uninstall still has to reach the backend using the existing RS256 keypair.
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        GECX_Auth::get_or_generate_keypair();
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertTrue( ! empty( $GLOBALS['gecx_test_http_requests'] ) );
        $last_req = end( $GLOBALS['gecx_test_http_requests'] );
        $headers  = $last_req['args']['headers'];

        $this->assertSame( 'plugin/uninstalled', $headers['X-WC-Webhook-Topic'] );
        $this->assertTrue( isset( $headers['Authorization'] ) );
        $this->assertArrayNotHasKey( 'X-WC-Webhook-Signature', $headers );
    }

    public function test_uninstall_skips_webhook_and_keypair_generation_for_unconnected_store(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_webhook_id' );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_auth_complete' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        $GLOBALS['gecx_test_http_requests'] = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertCount( 0, $GLOBALS['gecx_test_http_requests'] );
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    public function test_uninstall_omits_authorization_header_when_stored_keypair_is_corrupt(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 1, 'admin@example.com', [ 'administrator' ] );
        $this->create_gecx_webhook( 'active', 'wh_db_secret_789' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_public_key', '-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----' );
        update_option( 'gecx_private_key', [ 'iv' => 'bad', 'tag' => 'bad', 'ciphertext' => 'unreadable' ] );
        $GLOBALS['gecx_test_http_requests'] = [];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        include dirname( __DIR__ ) . '/uninstall.php';

        $this->assertTrue( ! empty( $GLOBALS['gecx_test_http_requests'] ) );
        $last_req = end( $GLOBALS['gecx_test_http_requests'] );
        $headers  = $last_req['args']['headers'];

        // Must NOT attach an unverified/freshly-minted or HS256 Authorization header,
        // which would cause backend VerifyUninstallAuth to fail before accepting the valid HMAC.
        $this->assertArrayNotHasKey( 'Authorization', $headers );
        $expected_sig = base64_encode( hash_hmac( 'sha256', $last_req['args']['body'], 'wh_db_secret_789', true ) );
        $this->assertSame( $expected_sig, $headers['X-WC-Webhook-Signature'] );
    }

    public function test_delivery_suppressed_with_alphanumeric_or_raw_session_id(): void {
        $webhook_id = $this->create_gecx_webhook();
        $webhook    = new WC_Webhook( $webhook_id );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        // Raw UUID (non-resource name format)
        $order1 = new WC_Order( 601 );
        $order1->update_meta_data( '_gecx_session_id', '8HKwcSo3FGLhndGcdue72VapwOt6iVW4up9c1ma7e1Y' );
        $order1->save();
        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 601 ) );

        // Alphanumeric project ID instead of numeric project number
        $order2 = new WC_Order( 602 );
        $order2->update_meta_data( '_gecx_session_id', 'projects/my-cool-project/locations/global/commerceSessions/sess-123' );
        $order2->save();
        $this->assertFalse( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 602 ) );

        // Numeric project number (canonical resource name format) succeeds
        $order3 = new WC_Order( 603 );
        $order3->update_meta_data( '_gecx_session_id', 'projects/380470877508/locations/global/commerceSessions/sess-123' );
        $order3->save();
        $this->assertTrue( $this->rest_api->gate_order_webhook_delivery( true, $webhook, 603 ) );
    }

    public function test_delete_order_webhook_clears_transients_on_direct_sql(): void {
        $this->create_gecx_webhook();
        set_transient( 'woocommerce_webhook_ids', [ 1 ] );
        set_transient( 'woocommerce_webhook_ids_status_active', [ 1 ] );

        $GLOBALS['gecx_test_disable_wc_webhook'] = true;
        GECX_Rest_API::delete_order_webhook();

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
        GECX_Rest_API::set_order_webhook_status( 'paused' );

        // Foreign webhook must be completely untouched
        $foreign_reloaded = new WC_Webhook( $foreign_id );
        $this->assertSame( 'active', $foreign_reloaded->get_status(), 'Foreign webhook status must not be modified' );
        $this->assertArrayHasKey( $foreign_id, $GLOBALS['gecx_test_webhooks'], 'Foreign webhook must not be deleted' );

        // Real GECX webhook must be updated
        $real_reloaded = new WC_Webhook( $real_id );
        $this->assertSame( 'paused', $real_reloaded->get_status(), 'Real GECX webhook must be updated' );

        // Primary stored option must be updated to the real webhook ID
        $this->assertSame( $real_id, (int) get_option( 'gecx_webhook_id' ), 'Stored ID must be updated to real GECX webhook' );
    }

    public function test_minimize_order_webhook_payload_from_order_items_using_get_item_total(): void {
        $webhook_id = $this->create_gecx_webhook();

        $order = new WC_Order( 701 );
        $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-701' );
        $order->set_currency( 'USD' );
        $order->set_total( '99.99' );

        $item = new class {
            public function get_product_id(): int { return 501; }
            public function get_variation_id(): int { return 0; }
            public function get_name(): string { return 'Custom T-Shirt'; }
            public function get_quantity(): int { return 3; }
            public function get_total(): float { return 99.99; }
        };
        $order->set_items( [ $item ] );
        $order->save();

        // When payload has no line_items, it falls back to $order->get_items()
        $payload = [
            'id'         => 701,
            'line_items' => null,
        ];

        $minimized = $this->rest_api->minimize_order_webhook_payload( $payload, 'order', 701, $webhook_id );

        $this->assertCount( 1, $minimized['line_items'] );
        $this->assertSame( 501, $minimized['line_items'][0]['product_id'] );
        $this->assertSame( 3, $minimized['line_items'][0]['quantity'] );
        // 99.99 / 3 = 33.33
        $this->assertSame( 33.33, $minimized['line_items'][0]['price'] );
    }
}

if ( php_sapi_name() === 'cli' && isset( $argv[0] ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
    gecx_run_test_class( WebhookLifecycleTest::class );
}
