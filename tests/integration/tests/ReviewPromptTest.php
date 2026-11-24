<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests for the WordPress.org review prompt.
 *
 * @package GECX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Admin\Review_Prompt;
use Google\Gemini_Enterprise_For_CX\REST\REST_API;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

class ReviewPromptTest extends RestTestCase {

    private const AGENT = 'projects/123/locations/global/agents/agent-1';

    private const SCREEN = 'marketing_page_gemini-enterprise-for-cx';

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( 1 );
        delete_user_meta( 1, Review_Prompt::USER_META_KEY );
        delete_transient( Review_Prompt::NO_ORDER_TRANSIENT );
    }

    public function tear_down(): void {
        // Back to a site screen: some tests move to the network admin.
        set_current_screen( 'front' );
        parent::tear_down();
    }

    /**
     * Seeds a store whose agent is linked, authorized and enabled.
     *
     * @param int  $linked_days_ago Days since the agent was linked.
     * @param bool $with_order      Whether an order with a chat session has been recorded.
     */
    private function seed_active_store( int $linked_days_ago = 20, bool $with_order = true ): void {
        update_option( 'gecx_agent_name', self::AGENT );
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( Review_Prompt::LINKED_AT_OPTION, time() - $linked_days_ago * DAY_IN_SECONDS );
        if ( $with_order ) {
            update_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION, time() - DAY_IN_SECONDS );
        }
    }

    private function create_order( string $status, bool $tagged ): int {
        $order = new WC_Order();
        if ( $tagged ) {
            $order->update_meta_data( '_gecx_session_id', 'projects/123/locations/global/commerceSessions/sess-1' );
        }
        $order->set_status( $status );
        return (int) $order->save();
    }

    private function send_cart_token( WP_REST_Request $request, string $token ): void {
        $request->set_header( 'Cart-Token', $token );
        $_SERVER['HTTP_CART_TOKEN'] = $token;
    }

    private function post_action( string $action ) {
        $request = new WP_REST_Request( 'POST', '/gecx/v1/review-prompt' );
        $request->set_body_params( [ 'action' => $action ] );
        return rest_get_server()->dispatch( $request );
    }

    private function render_page( Admin $admin ): string {
        ob_start();
        try {
            $admin->settings_page->render_settings_page();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function new_admin(): Admin {
        return new Admin( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
    }

    private function settings_hook( Admin $admin ): string {
        $admin->settings_page->add_settings_page();
        $property = new \ReflectionProperty( $admin->settings_page, 'settings_page_hook' );
        if ( PHP_VERSION_ID < 80100 ) {
            $property->setAccessible( true );
        }
        return (string) $property->getValue( $admin->settings_page );
    }

    // ---------------------------------------------------------------- Eligibility.

    public function test_eligible_when_linked_for_14_days_with_an_order(): void {
        $this->seed_active_store( 14 );
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_when_not_linked(): void {
        $this->seed_active_store();
        delete_option( 'gecx_agent_name' );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_when_agent_is_disabled(): void {
        $this->seed_active_store();
        update_option( 'gecx_agent_enabled', 0 );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_when_authorization_is_invalid(): void {
        $this->seed_active_store();
        update_option( Admin::STORE_AUTH_INVALID_OPTION, 1 );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_when_merchant_unlinked(): void {
        $this->seed_active_store();
        update_option( Admin::MERCHANT_UNLINKED_OPTION, 1 );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_when_linked_under_14_days(): void {
        $this->seed_active_store( 13 );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_without_a_recorded_link_time(): void {
        $this->seed_active_store();
        delete_option( Review_Prompt::LINKED_AT_OPTION );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_not_eligible_after_14_days_without_an_order(): void {
        $this->seed_active_store( 30, false );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
        // The negative lookup is remembered for a day.
        $this->assertNotFalse( get_transient( Review_Prompt::NO_ORDER_TRANSIENT ) );
    }

    public function test_not_eligible_for_a_non_administrator(): void {
        $this->seed_active_store();
        foreach ( [ 'editor', 'shop_manager', 'subscriber' ] as $role ) {
            $user_id = $this->factory()->user->create( [ 'role' => $role ] );
            $this->assertFalse( Review_Prompt::is_eligible( $user_id ), $role );
        }
        $this->assertFalse( Review_Prompt::is_eligible( 0 ) );
    }

    public function test_not_eligible_in_the_network_admin(): void {
        $this->seed_active_store();
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
        set_current_screen( 'dashboard-network' );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_snoozed_user_is_not_eligible_until_the_snooze_ends(): void {
        $this->seed_active_store();

        update_user_meta( 1, Review_Prompt::USER_META_KEY, [ 'state' => 'snoozed', 'snooze_until' => time() + HOUR_IN_SECONDS, 'snooze_count' => 1 ] );
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );

        update_user_meta( 1, Review_Prompt::USER_META_KEY, [ 'state' => 'snoozed', 'snooze_until' => time() - 1, 'snooze_count' => 1 ] );
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_done_and_dismissed_users_are_never_eligible(): void {
        $this->seed_active_store();
        foreach ( [ 'done', 'dismissed' ] as $state ) {
            update_user_meta( 1, Review_Prompt::USER_META_KEY, [ 'state' => $state, 'snooze_until' => 0, 'snooze_count' => 0 ] );
            $this->assertFalse( Review_Prompt::is_eligible( 1 ), $state );
        }
    }

    public function test_state_is_per_user(): void {
        $this->seed_active_store();
        $other = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        Review_Prompt::apply_action( $other, 'dismiss' );

        $this->assertFalse( Review_Prompt::is_eligible( $other ) );
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_malformed_user_meta_counts_as_no_answer(): void {
        $this->seed_active_store();
        update_user_meta( 1, Review_Prompt::USER_META_KEY, 'garbage' );
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
        update_user_meta( 1, Review_Prompt::USER_META_KEY, [ 'state' => 'bogus' ] );
        $this->assertTrue( Review_Prompt::is_eligible( 1 ) );
    }

    // ---------------------------------------------------------------- State transitions.

    public function test_snooze_hides_for_30_days_and_counts(): void {
        $before = time();
        $state  = Review_Prompt::apply_action( 1, 'snooze' );

        $this->assertSame( 'snoozed', $state['state'] );
        $this->assertSame( 1, $state['snooze_count'] );
        $this->assertGreaterThanOrEqual( $before + 30 * DAY_IN_SECONDS, $state['snooze_until'] );
        $this->assertLessThanOrEqual( time() + 30 * DAY_IN_SECONDS, $state['snooze_until'] );
        $this->assertSame( $state, get_user_meta( 1, Review_Prompt::USER_META_KEY, true ) );
    }

    public function test_third_maybe_later_counts_as_dismissed(): void {
        $this->seed_active_store();

        $this->assertSame( 'snoozed', Review_Prompt::apply_action( 1, 'snooze' )['state'] );
        $this->assertSame( 'snoozed', Review_Prompt::apply_action( 1, 'snooze' )['state'] );
        $third = Review_Prompt::apply_action( 1, 'snooze' );

        $this->assertSame( 'dismissed', $third['state'] );
        $this->assertSame( 3, $third['snooze_count'] );
        $this->assertSame( 0, $third['snooze_until'] );

        // Past any snooze: still not asked.
        $this->assertFalse( Review_Prompt::is_eligible( 1 ) );
    }

    public function test_done_and_dismissed_are_terminal(): void {
        Review_Prompt::apply_action( 1, 'done' );
        $this->assertSame( 'done', Review_Prompt::apply_action( 1, 'snooze' )['state'] );
        $this->assertSame( 'done', Review_Prompt::apply_action( 1, 'dismiss' )['state'] );

        delete_user_meta( 1, Review_Prompt::USER_META_KEY );
        Review_Prompt::apply_action( 1, 'dismiss' );
        $this->assertSame( 'dismissed', Review_Prompt::apply_action( 1, 'done' )['state'] );
    }

    // ---------------------------------------------------------------- REST route.

    public function test_route_requires_an_administrator(): void {
        wp_set_current_user( 0 );
        $response = $this->post_action( 'dismiss' );
        $this->assertSame( 401, $response->get_status() );

        foreach ( [ 'editor', 'shop_manager', 'subscriber' ] as $role ) {
            wp_set_current_user( $this->factory()->user->create( [ 'role' => $role ] ) );
            $this->assertSame( 403, $this->post_action( 'dismiss' )->get_status(), $role );
            $this->assertSame( '', get_user_meta( get_current_user_id(), Review_Prompt::USER_META_KEY, true ) );
        }
    }

    public function test_route_rejects_a_cart_token_request(): void {
        // A shopper's Cart-Token never counts as a store operator, even when
        // the user it resolves to is an administrator.
        $state = new \ReflectionProperty( \Google\Gemini_Enterprise_For_CX\Auth::class, 'authenticated_via_cart_token' );
        if ( PHP_VERSION_ID < 80100 ) {
            $state->setAccessible( true );
        }
        $state->setValue( null, true );

        $response = $this->post_action( 'dismiss' );

        $this->assertContains( $response->get_status(), [ 401, 403 ] );
        $this->assertSame( '', get_user_meta( 1, Review_Prompt::USER_META_KEY, true ) );
    }

    public function test_route_rejects_get_and_unknown_actions(): void {
        $this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/gecx/v1/review-prompt' ) )->get_status() );
        $this->assertSame( 400, $this->post_action( 'nope' )->get_status() );
        $this->assertSame( 400, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/gecx/v1/review-prompt' ) )->get_status() );
        $this->assertSame( '', get_user_meta( 1, Review_Prompt::USER_META_KEY, true ) );
    }

    public function test_route_stores_each_action_for_the_current_user_only(): void {
        $response = $this->post_action( 'snooze' );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'snoozed', $response->get_data()['state'] );
        $this->assertSame( 'snoozed', Review_Prompt::get_user_state( 1 )['state'] );

        $this->assertSame( 'done', $this->post_action( 'done' )->get_data()['state'] );
        $this->assertSame( 'done', Review_Prompt::get_user_state( 1 )['state'] );

        $other = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertSame( '', Review_Prompt::get_user_state( $other )['state'] );

        wp_set_current_user( $other );
        $this->assertSame( 'dismissed', $this->post_action( 'dismiss' )->get_data()['state'] );
        $this->assertSame( 'done', Review_Prompt::get_user_state( 1 )['state'] );
    }

    public function test_route_makes_the_third_snooze_a_dismissal(): void {
        $this->post_action( 'snooze' );
        $this->post_action( 'snooze' );
        $this->assertSame( 'dismissed', $this->post_action( 'snooze' )->get_data()['state'] );
    }

    // ---------------------------------------------------------------- Order signal.

    public function test_classic_checkout_records_the_first_attributed_order(): void {
        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );

        WC()->session = new WC_Session_Mock();
        WC()->session->set_customer_id( 't_review_classic_1' );
        $this->set_wc_session( 't_review_classic_1', [ 'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-c1' ] );

        $order = new WC_Order();
        ( new REST_API() )->session_attribution->attach_session_to_order_metadata( $order, [] );

        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-c1', $order->get_meta( '_gecx_session_id' ) );
        $first = get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION );
        $this->assertGreaterThan( 0, (int) $first );

        // A later order does not move it.
        update_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION, 1000 );
        ( new REST_API() )->session_attribution->attach_session_to_order_metadata( new WC_Order(), [] );
        $this->assertSame( 1000, (int) get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_classic_checkout_without_a_chat_session_records_nothing(): void {
        WC()->session = new WC_Session_Mock();
        WC()->session->set_customer_id( 't_review_classic_none' );

        $order = new WC_Order();
        ( new REST_API() )->session_attribution->attach_session_to_order_metadata( $order, [] );

        $this->assertSame( '', $order->get_meta( '_gecx_session_id' ) );
        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_store_api_checkout_records_the_first_attributed_order(): void {
        WC()->session = new WC_Session_Mock();
        WC()->session->set_customer_id( '' );

        $token = $this->generate_jwt( 't_review_blocks_1' );
        $this->set_wc_session( 't_review_blocks_1', [ 'gecx_session_id' => 'projects/123/locations/global/commerceSessions/sess-b1' ] );

        $request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
        $this->send_cart_token( $request, $token );

        $order = new WC_Order();
        ( new REST_API() )->session_attribution->attach_session_to_order_metadata_store_api( $order, $request );

        $this->assertSame( 'projects/123/locations/global/commerceSessions/sess-b1', $order->get_meta( '_gecx_session_id' ) );
        $this->assertGreaterThan( 0, (int) get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_store_api_checkout_without_a_chat_session_records_nothing(): void {
        WC()->session = new WC_Session_Mock();
        WC()->session->set_customer_id( '' );

        $order = new WC_Order();
        ( new REST_API() )->session_attribution->attach_session_to_order_metadata_store_api( $order, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );

        $this->assertSame( '', $order->get_meta( '_gecx_session_id' ) );
        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_recorded_option_is_not_autoloaded(): void {
        Review_Prompt::record_attributed_order();
        Review_Prompt::record_linked( '', self::AGENT );

        global $wpdb;
        foreach ( [ Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION, Review_Prompt::LINKED_AT_OPTION ] as $name ) {
            $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
            $this->assertNotContains( $autoload, [ 'yes', 'on', 'auto', 'auto-on' ], $name );
        }
    }

    // ---------------------------------------------------------------- Backfill.

    public function test_backfill_finds_a_pre_existing_tagged_processing_order(): void {
        $this->create_order( 'processing', true );

        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
        $this->assertTrue( Review_Prompt::has_attributed_order() );
        $this->assertGreaterThan( 0, (int) get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_backfill_finds_a_completed_order(): void {
        $this->create_order( 'completed', true );
        $this->assertTrue( Review_Prompt::has_attributed_order() );
    }

    public function test_backfill_ignores_unpaid_failed_cancelled_and_untagged_orders(): void {
        $this->create_order( 'failed', true );
        $this->create_order( 'cancelled', true );
        $this->create_order( 'pending', true );
        $this->create_order( 'refunded', true );
        $this->create_order( 'processing', false );
        $this->create_order( 'completed', false );

        $this->assertFalse( Review_Prompt::has_attributed_order() );
        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
    }

    public function test_backfill_remembers_a_miss_for_a_day_then_looks_again(): void {
        $this->assertFalse( Review_Prompt::has_attributed_order() );
        $this->assertNotFalse( get_transient( Review_Prompt::NO_ORDER_TRANSIENT ) );

        // An order placed now is not seen until the transient expires.
        $this->create_order( 'processing', true );
        $this->assertFalse( Review_Prompt::has_attributed_order() );

        delete_transient( Review_Prompt::NO_ORDER_TRANSIENT );
        $this->assertTrue( Review_Prompt::has_attributed_order() );
    }

    public function test_backfill_never_queries_once_the_option_exists(): void {
        update_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION, time() );

        add_filter( 'woocommerce_order_query_args', static function () {
            throw new \RuntimeException( 'The order query ran.' );
        } );
        $this->assertTrue( Review_Prompt::has_attributed_order() );
    }

    public function test_backfill_finds_the_order_with_custom_orders_table_enabled(): void {
        if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
            $this->markTestSkipped( 'OrderUtil is not available.' );
        }

        // Switched on by filter: the option itself refuses to change while
        // orders in the posts table are not synced.
        $enable = static function () {
            return 'yes';
        };
        add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $enable );
        add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', static function () {
            return 'no';
        } );
        try {
            if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                $this->markTestSkipped( 'The custom orders table could not be enabled.' );
            }
            $this->create_order( 'failed', true );
            $this->assertFalse( Review_Prompt::has_attributed_order() );

            $processing = $this->create_order( 'processing', true );
            // Stored in the orders table, not as a post.
            $this->assertSame( 'shop_order_placehold', get_post_type( $processing ) );
            delete_transient( Review_Prompt::NO_ORDER_TRANSIENT );
            $this->assertTrue( Review_Prompt::has_attributed_order() );
        } finally {
            remove_all_filters( 'pre_option_woocommerce_custom_orders_table_enabled' );
            remove_all_filters( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled' );
        }
    }

    // ---------------------------------------------------------------- Linked time.

    public function test_link_handler_records_the_link_time_once(): void {
        $request = new WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
        $request->set_body_params( [ 'agent_name' => self::AGENT ] );
        $this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

        $first = (int) get_option( Review_Prompt::LINKED_AT_OPTION );
        $this->assertGreaterThan( 0, $first );

        // Linking the same agent again keeps the original time.
        update_option( Review_Prompt::LINKED_AT_OPTION, $first - 10 * DAY_IN_SECONDS );
        $this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
        $this->assertSame( $first - 10 * DAY_IN_SECONDS, (int) get_option( Review_Prompt::LINKED_AT_OPTION ) );
    }

    public function test_relinking_after_an_unlink_keeps_the_link_time(): void {
        update_option( Review_Prompt::LINKED_AT_OPTION, time() - 20 * DAY_IN_SECONDS );
        delete_option( 'gecx_agent_name' );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
        $request->set_body_params( [ 'agent_name' => 'projects/123/locations/global/agents/other' ] );
        rest_get_server()->dispatch( $request );

        $this->assertEqualsWithDelta( time() - 20 * DAY_IN_SECONDS, (int) get_option( Review_Prompt::LINKED_AT_OPTION ), 5 );
    }

    public function test_swapping_the_linked_agent_restarts_the_clock(): void {
        update_option( 'gecx_agent_name', self::AGENT );
        update_option( Review_Prompt::LINKED_AT_OPTION, time() - 20 * DAY_IN_SECONDS );

        $request = new WP_REST_Request( 'POST', '/gecx/v1/link-agent' );
        $request->set_body_params( [ 'agent_name' => 'projects/123/locations/global/agents/other' ] );
        rest_get_server()->dispatch( $request );

        $this->assertEqualsWithDelta( time(), (int) get_option( Review_Prompt::LINKED_AT_OPTION ), 5 );
    }

    public function test_sync_adopting_an_agent_records_the_link_time(): void {
        update_option( 'gecx_auth_complete', 1 );
        update_option( 'home', 'https://example.com' );
        update_option( 'siteurl', 'https://example.com' );
        $this->http_responses[] = gecx_test_http_response(
            200,
            ")]}'\n" . wp_json_encode(
                [
                    'syncStatus'          => 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED',
                    'actualLinkedAgentId' => 'agents/agent_a',
                    'shopDomain'          => 'example.com',
                ]
            )
        );

        $this->new_admin()->console_sync->sync_agent_state( '' );

        $this->assertSame( 'agents/agent_a', get_option( 'gecx_agent_name' ) );
        $this->assertEqualsWithDelta( time(), (int) get_option( Review_Prompt::LINKED_AT_OPTION ), 5 );
    }

    public function test_settings_page_starts_the_clock_for_a_store_linked_before_the_upgrade(): void {
        $this->seed_active_store( 400 );
        delete_option( Review_Prompt::LINKED_AT_OPTION );

        $html = $this->render_page( $this->new_admin() );

        $this->assertEqualsWithDelta( time(), (int) get_option( Review_Prompt::LINKED_AT_OPTION ), 5 );
        // Waits the full 14 days from the upgrade.
        $this->assertStringNotContainsString( 'gecx-review-prompt', $html );
    }

    public function test_settings_page_does_not_start_the_clock_for_an_unlinked_store(): void {
        delete_option( 'gecx_agent_name' );
        $this->render_page( $this->new_admin() );
        $this->assertFalse( get_option( Review_Prompt::LINKED_AT_OPTION ) );
    }

    // ---------------------------------------------------------------- Rendering.

    public function test_notice_renders_on_the_settings_page_when_eligible(): void {
        $this->seed_active_store();
        $html = $this->render_page( $this->new_admin() );

        $this->assertStringContainsString( 'id="gecx-review-prompt"', $html );
        $this->assertStringContainsString( 'Is Gemini Enterprise for CX helping your store? A review on WordPress.org helps other merchants find it.', $html );
        $this->assertStringContainsString( 'Leave a review', $html );
        $this->assertStringContainsString( 'Maybe later', $html );
        $this->assertStringContainsString( 'Don&#039;t ask again', $html );
        $this->assertStringContainsString( 'data-gecx-review-action="done"', $html );
        $this->assertStringContainsString( 'data-gecx-review-action="snooze"', $html );
        $this->assertStringContainsString( 'data-gecx-review-action="dismiss"', $html );
        $this->assertStringContainsString( 'Contact support', $html );

        // The review link opens in a new tab safely and does not pre-select a rating.
        $this->assertMatchesRegularExpression(
            '#<a href="https://wordpress\.org/support/plugin/gemini-enterprise-for-cx/reviews/\#new-post"\s+class="button button-primary"\s+target="_blank"\s+rel="noopener noreferrer"#',
            $html
        );
        $this->assertStringNotContainsString( 'rate=', $html );
        $this->assertStringNotContainsString( 'filter=', $html );

        // "Don't ask again" is a text link. Carrying the .button class as well
        // draws it as a bordered box on WordPress 7.0.
        $this->assertMatchesRegularExpression( '#<button type="button" class="button-link" data-gecx-review-action="dismiss">#', $html );
        $this->assertStringContainsString( 'https://wordpress.org/support/plugin/gemini-enterprise-for-cx/"', $html );
    }

    public function test_notice_is_not_rendered_when_not_eligible(): void {
        $this->seed_active_store( 5 );
        $this->assertStringNotContainsString( 'gecx-review-prompt', $this->render_page( $this->new_admin() ) );

        $this->seed_active_store();
        Review_Prompt::apply_action( 1, 'snooze' );
        $this->assertStringNotContainsString( 'id="gecx-review-prompt"', $this->render_page( $this->new_admin() ) );
    }

    public function test_notice_is_not_rendered_during_onboarding(): void {
        $this->seed_active_store();
        delete_option( 'gecx_agent_name' );
        $this->assertStringNotContainsString( 'id="gecx-review-prompt"', $this->render_page( $this->new_admin() ) );
    }

    public function test_notice_is_not_hooked_to_global_or_network_admin_notices(): void {
        $this->new_admin();
        foreach ( [ 'admin_notices', 'network_admin_notices', 'all_admin_notices', 'user_admin_notices' ] as $hook ) {
            global $wp_filter;
            if ( empty( $wp_filter[ $hook ] ) ) {
                continue;
            }
            foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
                foreach ( $callbacks as $callback ) {
                    $function = $callback['function'];
                    if ( is_array( $function ) && is_object( $function[0] ) ) {
                        $this->assertNotInstanceOf( Review_Prompt::class, $function[0], $hook );
                        $this->assertNotSame( 'render_notice', $function[1], $hook );
                    }
                }
            }
        }
    }

    public function test_footer_text_is_ours_only_on_our_settings_screen(): void {
        $this->seed_active_store();
        $admin = $this->new_admin();
        $hook  = $this->settings_hook( $admin );

        set_current_screen( 'dashboard' );
        $this->assertSame( 'Thank you', $admin->settings_page->filter_admin_footer_text( 'Thank you' ) );

        set_current_screen( $hook );
        $text = $admin->settings_page->filter_admin_footer_text( 'Thank you' );
        $this->assertStringContainsString( 'If Gemini Enterprise for CX helps your store, please leave a review on', $text );
        $this->assertStringContainsString( 'href="https://wordpress.org/support/plugin/gemini-enterprise-for-cx/reviews/#new-post"', $text );
        $this->assertStringContainsString( 'rel="noopener noreferrer"', $text );
    }

    public function test_footer_text_is_filtered_after_woocommerce_and_wins(): void {
        $this->seed_active_store();
        $admin = $this->new_admin();
        $hook  = $this->settings_hook( $admin );
        set_current_screen( $hook );

        $this->assertStringContainsString( 'please leave a review on', apply_filters( 'admin_footer_text', 'Thank you for creating with WordPress.' ) );
    }

    public function test_footer_text_is_left_alone_once_done_or_dismissed_or_when_not_active(): void {
        $this->seed_active_store();
        $admin = $this->new_admin();
        set_current_screen( $this->settings_hook( $admin ) );

        // Snoozed still shows the plain line.
        Review_Prompt::apply_action( 1, 'snooze' );
        $this->assertStringContainsString( 'please leave a review on', $admin->settings_page->filter_admin_footer_text( 'x' ) );

        delete_user_meta( 1, Review_Prompt::USER_META_KEY );
        Review_Prompt::apply_action( 1, 'done' );
        $this->assertSame( 'x', $admin->settings_page->filter_admin_footer_text( 'x' ) );

        delete_user_meta( 1, Review_Prompt::USER_META_KEY );
        Review_Prompt::apply_action( 1, 'dismiss' );
        $this->assertSame( 'x', $admin->settings_page->filter_admin_footer_text( 'x' ) );

        delete_user_meta( 1, Review_Prompt::USER_META_KEY );
        update_option( 'gecx_agent_enabled', 0 );
        $this->assertSame( 'x', $admin->settings_page->filter_admin_footer_text( 'x' ) );

        update_option( 'gecx_agent_enabled', 1 );
        wp_set_current_user( $this->factory()->user->create( [ 'role' => 'editor' ] ) );
        $this->assertSame( 'x', $admin->settings_page->filter_admin_footer_text( 'x' ) );
    }

    public function test_plugin_row_meta_adds_rate_and_support_for_our_plugin_only(): void {
        // The plugin's own components already hooked it; keep one instance.
        remove_all_filters( 'plugin_row_meta' );
        $this->new_admin();
        $basename = plugin_basename( dirname( __DIR__, 3 ) . '/gecx-agent.php' );

        $ours = apply_filters( 'plugin_row_meta', [ 'Version 1' ], $basename );
        $this->assertCount( 3, $ours );
        $this->assertStringContainsString( 'reviews/#new-post', $ours[1] );
        $this->assertStringContainsString( '>Rate</a>', $ours[1] );
        $this->assertStringContainsString( 'https://wordpress.org/support/plugin/gemini-enterprise-for-cx/"', $ours[2] );
        $this->assertStringContainsString( '>Support</a>', $ours[2] );

        $this->assertSame( [ 'Version 1' ], apply_filters( 'plugin_row_meta', [ 'Version 1' ], 'other/other.php' ) );
    }

    // ---------------------------------------------------------------- Uninstall.

    private function run_uninstall(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
        include dirname( __DIR__, 3 ) . '/uninstall.php';
    }

    public function test_uninstall_removes_review_prompt_options_transient_and_user_meta(): void {
        $this->seed_active_store();
        set_transient( Review_Prompt::NO_ORDER_TRANSIENT, 1, DAY_IN_SECONDS );
        $other = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        Review_Prompt::apply_action( 1, 'snooze' );
        Review_Prompt::apply_action( $other, 'dismiss' );
        update_user_meta( $other, 'unrelated_meta', 'keep' );

        $this->run_uninstall();

        $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ) );
        $this->assertFalse( get_option( Review_Prompt::LINKED_AT_OPTION ) );
        $this->assertFalse( get_transient( Review_Prompt::NO_ORDER_TRANSIENT ) );
        $this->assertSame( '', get_user_meta( 1, Review_Prompt::USER_META_KEY, true ) );
        $this->assertSame( '', get_user_meta( $other, Review_Prompt::USER_META_KEY, true ) );
        $this->assertSame( 'keep', get_user_meta( $other, 'unrelated_meta', true ) );
    }

    /**
     * @group ms-required
     */
    public function test_uninstall_removes_review_prompt_options_on_every_site(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite only.' );
        }

        $other_site = $this->factory()->blog->create();
        $this->seed_active_store();
        set_transient( Review_Prompt::NO_ORDER_TRANSIENT, 1, DAY_IN_SECONDS );
        switch_to_blog( $other_site );
        $this->seed_active_store();
        set_transient( Review_Prompt::NO_ORDER_TRANSIENT, 1, DAY_IN_SECONDS );
        restore_current_blog();
        Review_Prompt::apply_action( 1, 'dismiss' );

        // The new site has no Action Scheduler tables for uninstall to clear.
        global $wpdb;
        $suppressed = $wpdb->suppress_errors( true );
        try {
            $this->run_uninstall();
        } finally {
            $wpdb->suppress_errors( $suppressed );
        }

        foreach ( [ get_current_blog_id(), $other_site ] as $site_id ) {
            switch_to_blog( $site_id );
            $this->assertFalse( get_option( Review_Prompt::FIRST_ATTRIBUTED_ORDER_OPTION ), "site $site_id" );
            $this->assertFalse( get_option( Review_Prompt::LINKED_AT_OPTION ), "site $site_id" );
            $this->assertFalse( get_transient( Review_Prompt::NO_ORDER_TRANSIENT ), "site $site_id" );
            restore_current_blog();
        }
        $this->assertSame( '', get_user_meta( 1, Review_Prompt::USER_META_KEY, true ) );
    }
}
