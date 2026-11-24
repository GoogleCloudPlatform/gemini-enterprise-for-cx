<?php
/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gemini Enterprise for CX WordPress.org review prompt
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Admin;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Asks an administrator for a WordPress.org review, sparingly.
 *
 * The prompt is a notice on the plugin's own settings screen. It appears only
 * after the agent has been linked and enabled for 14 days and at least one
 * order from a shopper with a chat session has been placed, and only to an
 * administrator who has not already answered it. Each administrator's answer
 * is kept in user meta and written through a REST route, so the server decides
 * whether the prompt shows and the script only hides it.
 *
 * Everything here is computed from this store's own data. Nothing is sent
 * anywhere, and the order signal is "an order from a shopper who had a chat
 * session", not the backend's attribution, so it is never presented as
 * attributed revenue.
 */
class Review_Prompt {

    /**
     * Option holding the Unix time the first order carrying a chat session id
     * was recorded. Written when the order is tagged, or by the backfill below
     * for orders placed before the plugin recorded it. Once set it is never
     * queried again.
     */
    public const FIRST_ATTRIBUTED_ORDER_OPTION = 'gecx_first_attributed_order_at';

    /**
     * Option holding the Unix time the agent was first linked, or the first
     * settings page load after an upgrade for a store linked before this was
     * recorded.
     */
    public const LINKED_AT_OPTION = 'gecx_linked_at';

    /**
     * Transient remembering that the backfill found no order, so the order
     * query runs at most once a day.
     */
    public const NO_ORDER_TRANSIENT = 'gecx_attributed_order_checked';

    /**
     * User meta key holding one administrator's answer.
     */
    public const USER_META_KEY = 'gecx_review_prompt';

    /**
     * Seconds the agent must have been linked before the prompt shows.
     */
    public const LINKED_DELAY = 14 * DAY_IN_SECONDS;

    /**
     * Seconds "Maybe later" hides the prompt for.
     */
    public const SNOOZE_SECONDS = 30 * DAY_IN_SECONDS;

    /**
     * Snoozes allowed before the next "Maybe later" counts as "Don't ask
     * again".
     */
    public const MAX_SNOOZES = 2;

    /**
     * Where a merchant writes the review.
     */
    public const REVIEW_URL = 'https://wordpress.org/support/plugin/gemini-enterprise-for-cx/reviews/#new-post';

    /**
     * Where a merchant asks for help.
     */
    public const SUPPORT_URL = 'https://wordpress.org/support/plugin/gemini-enterprise-for-cx/';

    /**
     * Path to the main plugin file.
     */
    private string $plugin_file;

    /**
     * Constructor.
     *
     * @param string $plugin_file Main plugin file.
     */
    public function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;
    }

    /**
     * Registers this component's hooks.
     *
     * The notice and the footer line are rendered by Settings_Page, which
     * knows which screen is its own. Nothing is hooked to admin_notices or
     * network_admin_notices.
     */
    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
        add_filter( 'plugin_row_meta', [ $this, 'add_plugin_row_meta' ], 10, 2 );
    }

    /**
     * Records the first order tagged with a chat session id.
     *
     * Called where `_gecx_session_id` is set on an order. add_option() does
     * nothing once the option exists, so only the first order writes. This is
     * the same for HPOS and legacy order storage because no order is read.
     */
    public static function record_attributed_order(): void {
        add_option( self::FIRST_ATTRIBUTED_ORDER_OPTION, time(), '', 'no' );
    }

    /**
     * Records when the agent was linked.
     *
     * The first link starts the clock. Linking again does not restart it, so a
     * merchant who unlinks and links the same or another agent keeps the time
     * they have already had the plugin. The one case that restarts it is
     * swapping a linked agent for a different one while linked, because that
     * agent has not yet proven itself.
     *
     * @param string $previous_agent Agent name before this link, or ''.
     * @param string $new_agent      Agent name now linked.
     */
    public static function record_linked( string $previous_agent, string $new_agent ): void {
        if ( 0 === (int) get_option( self::LINKED_AT_OPTION, 0 ) ) {
            add_option( self::LINKED_AT_OPTION, time(), '', 'no' );
            return;
        }

        if ( '' !== $previous_agent && $previous_agent !== $new_agent ) {
            update_option( self::LINKED_AT_OPTION, time(), 'no' );
        }
    }

    /**
     * Starts the clock for a store linked before the link time was recorded.
     *
     * Called when the settings page renders for a linked store, so such a
     * store waits the full delay from the upgrade.
     */
    public static function ensure_linked_at(): void {
        add_option( self::LINKED_AT_OPTION, time(), '', 'no' );
    }

    /**
     * Whether any paid order from a shopper with a chat session exists.
     *
     * Stores that took such orders before this release have no recorded
     * first-order time, so look for one. The query is limited to one processing
     * or completed order, its negative result is remembered for a day, and a hit
     * is stored in the option so that nothing is queried again.
     *
     * Call this only while rendering the plugin's settings page.
     */
    public static function has_attributed_order(): bool {
        if ( false !== get_option( self::FIRST_ATTRIBUTED_ORDER_OPTION, false ) ) {
            return true;
        }

        if ( false !== get_transient( self::NO_ORDER_TRANSIENT ) ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }

        // meta_key with meta_compare rather than meta_query: the legacy
        // order store rejects meta_query, and both stores, including the
        // custom orders table, read these.
        $order_ids = wc_get_orders(
            [
                'limit'        => 1,
                'return'       => 'ids',
                'status'       => [ 'processing', 'completed' ],
                'meta_key'     => '_gecx_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One bounded query, remembered for a day.
                'meta_compare' => 'EXISTS',
            ]
        );

        if ( is_array( $order_ids ) && ! empty( $order_ids ) ) {
            self::record_attributed_order();
            return true;
        }

        set_transient( self::NO_ORDER_TRANSIENT, 1, DAY_IN_SECONDS );
        return false;
    }

    /**
     * Whether the agent is linked, authorized and switched on.
     *
     * Uses the same authorization test as the settings page, so a store whose
     * credentials Google reported as unusable is not asked for a review.
     */
    public static function is_agent_active(): bool {
        return '' !== (string) get_option( 'gecx_agent_name', '' )
            && Admin::is_store_authorized()
            && (bool) get_option( 'gecx_agent_enabled', 0 )
            && ! Admin::is_merchant_unlinked();
    }

    /**
     * Reads one user's stored answer.
     *
     * @param int $user_id User ID.
     * @return array{state: string, snooze_until: int, snooze_count: int} The state is '' when the user has not answered.
     */
    public static function get_user_state( int $user_id ): array {
        $stored = get_user_meta( $user_id, self::USER_META_KEY, true );
        $stored = is_array( $stored ) ? $stored : [];
        $state  = isset( $stored['state'] ) ? (string) $stored['state'] : '';

        return [
            'state'        => in_array( $state, [ 'done', 'dismissed', 'snoozed' ], true ) ? $state : '',
            'snooze_until' => isset( $stored['snooze_until'] ) ? max( 0, (int) $stored['snooze_until'] ) : 0,
            'snooze_count' => isset( $stored['snooze_count'] ) ? max( 0, (int) $stored['snooze_count'] ) : 0,
        ];
    }

    /**
     * Whether a user's answer still allows asking.
     *
     * @param int $user_id User ID.
     */
    public static function user_state_allows( int $user_id ): bool {
        $state = self::get_user_state( $user_id );

        if ( 'done' === $state['state'] || 'dismissed' === $state['state'] ) {
            return false;
        }

        return 'snoozed' !== $state['state'] || time() >= $state['snooze_until'];
    }

    /**
     * Whether to show the prompt to a user now.
     *
     * Ordered so the cheap checks run first and the order lookup runs last.
     *
     * @param int $user_id User ID.
     */
    public static function is_eligible( int $user_id ): bool {
        // The settings page is a site screen, never a network admin one.
        if ( is_network_admin() ) {
            return false;
        }

        // The capability that opens the settings page.
        if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
            return false;
        }

        if ( ! self::is_agent_active() ) {
            return false;
        }

        $linked_at = (int) get_option( self::LINKED_AT_OPTION, 0 );
        if ( $linked_at <= 0 || time() - $linked_at < self::LINKED_DELAY ) {
            return false;
        }

        if ( ! self::user_state_allows( $user_id ) ) {
            return false;
        }

        return self::has_attributed_order();
    }

    /**
     * Applies an answer to a user's stored state.
     *
     * A user who has finished or dismissed the prompt stays that way. The
     * third "Maybe later" counts as "Don't ask again".
     *
     * @param int    $user_id User ID.
     * @param string $action  One of done, snooze or dismiss.
     * @return array{state: string, snooze_until: int, snooze_count: int} The stored state.
     */
    public static function apply_action( int $user_id, string $action ): array {
        $state = self::get_user_state( $user_id );

        if ( 'done' === $state['state'] || 'dismissed' === $state['state'] ) {
            return $state;
        }

        if ( 'done' === $action ) {
            $state['state']        = 'done';
            $state['snooze_until'] = 0;
        } elseif ( 'dismiss' === $action ) {
            $state['state']        = 'dismissed';
            $state['snooze_until'] = 0;
        } else {
            ++$state['snooze_count'];
            if ( $state['snooze_count'] > self::MAX_SNOOZES ) {
                $state['state']        = 'dismissed';
                $state['snooze_until'] = 0;
            } else {
                $state['state']        = 'snoozed';
                $state['snooze_until'] = time() + self::SNOOZE_SECONDS;
            }
        }

        update_user_meta( $user_id, self::USER_META_KEY, $state );
        return $state;
    }

    /**
     * Registers the route the notice's buttons call.
     */
    public function register_rest_route(): void {
        register_rest_route(
            'gecx/v1',
            '/review-prompt',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rest_update_state' ],
                // A cookie-authenticated request is only treated as logged in
                // when WordPress has verified its wp_rest nonce.
                'permission_callback' => static function (): bool {
                    if ( Auth::is_cart_token_request() ) {
                        return false;
                    }
                    return current_user_can( 'manage_options' );
                },
                'args'                => [
                    'action' => [
                        'description' => __( 'The answer to the review prompt.', 'gemini-enterprise-for-cx' ),
                        'type'        => 'string',
                        'required'    => true,
                        'enum'        => [ 'done', 'snooze', 'dismiss' ],
                    ],
                ],
            ]
        );
    }

    /**
     * REST handler: stores the current user's answer.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function rest_update_state( \WP_REST_Request $request ): \WP_REST_Response {
        $state = self::apply_action( get_current_user_id(), (string) $request->get_param( 'action' ) );

        return new \WP_REST_Response(
            [
                'success'      => true,
                'state'        => $state['state'],
                'snooze_until' => $state['snooze_until'],
            ],
            200
        );
    }

    /**
     * Prints the prompt, if the current user should see it.
     *
     * Rendered inside the settings page, so it can only appear there.
     */
    public function render_notice(): void {
        if ( ! self::is_eligible( get_current_user_id() ) ) {
            return;
        }
        ?>
        <div id="gecx-review-prompt" class="notice notice-info inline gecx-review-prompt">
            <p>
                <?php esc_html_e( 'Is Gemini Enterprise for CX helping your store? A review on WordPress.org helps other merchants find it.', 'gemini-enterprise-for-cx' ); ?>
            </p>
            <p class="gecx-review-prompt__actions">
                <a href="<?php echo esc_url( self::REVIEW_URL ); ?>"
                    class="button button-primary"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-gecx-review-action="done">
                    <?php esc_html_e( 'Leave a review', 'gemini-enterprise-for-cx' ); ?>
                </a>
                <button type="button" class="button" data-gecx-review-action="snooze">
                    <?php esc_html_e( 'Maybe later', 'gemini-enterprise-for-cx' ); ?>
                </button>
                <button type="button" class="button button-link" data-gecx-review-action="dismiss">
                    <?php esc_html_e( "Don't ask again", 'gemini-enterprise-for-cx' ); ?>
                </button>
            </p>
            <p class="gecx-review-prompt__help">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the plugin's support forum on WordPress.org. */
                        __( 'Need help? %s', 'gemini-enterprise-for-cx' ),
                        '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Contact support', 'gemini-enterprise-for-cx' ) . '</a>'
                    ),
                    [
                        'a' => [
                            'href'   => true,
                            'target' => true,
                            'rel'    => true,
                        ],
                    ]
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Footer line for the plugin's settings screen.
     *
     * Shown while the agent is active and the current user has not finished or
     * dismissed the prompt. A snoozed user still sees it: it is a plain line,
     * not a request, and "Maybe later" was an answer to the notice.
     *
     * @param mixed $footer_text Footer text before this filter.
     * @return string Footer text.
     */
    public function filter_footer_text( $footer_text ): string {
        $footer_text = (string) $footer_text;

        if ( is_network_admin() || ! current_user_can( 'manage_options' ) || ! self::is_agent_active() ) {
            return $footer_text;
        }

        $state = self::get_user_state( get_current_user_id() );
        if ( 'done' === $state['state'] || 'dismissed' === $state['state'] ) {
            return $footer_text;
        }

        return sprintf(
            /* translators: %s: link to the plugin's review page, labelled "WordPress.org". */
            esc_html__( 'If Gemini Enterprise for CX helps your store, please leave a review on %s.', 'gemini-enterprise-for-cx' ),
            '<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">WordPress.org</a>'
        );
    }

    /**
     * Adds "Rate" and "Support" links to the plugin's row on the Plugins page.
     *
     * Static links, shown whatever the store's state, like other plugins' row
     * meta.
     *
     * @param mixed  $links Row meta links.
     * @param string $file  Plugin basename of the row.
     * @return array<int|string, string> Row meta links.
     */
    public function add_plugin_row_meta( $links, $file ): array {
        $links = (array) $links;

        if ( plugin_basename( $this->plugin_file ) !== $file ) {
            return $links;
        }

        $links[] = '<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Rate', 'gemini-enterprise-for-cx' ) . '</a>';
        $links[] = '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'gemini-enterprise-for-cx' ) . '</a>';

        return $links;
    }
}
