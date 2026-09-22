<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Gemini_Enterprise_For_CX
 */

// If run outside of WordPress uninstallation, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

global $wpdb;

// WordPress loads uninstall.php standalone, so none of the plugin's classes are
// autoloaded here. The uninstall notification is authenticated with a JWT signed
// by the store's own private key, which means the auth class has to be pulled in
// by hand before that key is deleted further down.
if ( ! class_exists( 'GECX_Auth' ) && file_exists( __DIR__ . '/includes/class-gecx-auth.php' ) ) {
    require_once __DIR__ . '/includes/class-gecx-auth.php';
}

// Options, webhooks and post meta all live in per-site tables, so on a network
// activation every site carries its own copy and cleaning only the current one
// leaves the rest behind. Each site is also a distinct store as far as the
// backend is concerned, so each one that is connected is notified separately.
//
// A null entry means "whatever site we are already on": on a single site there
// is nothing to switch to, and switch_to_blog() is not defined.
$gecx_site_ids = [ null ];
if ( is_multisite() ) {
    $gecx_site_ids = get_sites( [
        'fields'   => 'ids',
        'number'   => 0,
        'deleted'  => 0,
        'archived' => 0,
        'spam'     => 0,
    ] );
}

// Notifying the backend costs up to $gecx_notify_timeout_seconds per connected
// site, and a large network can hold more connected stores than one PHP request
// can serve before max_execution_time kills it, which would leave the remaining
// sites with their options intact and the backend never told. Spend at most
// $gecx_notify_budget_seconds in total on notifications; local cleanup always
// runs for every site.
//
// The budget is a ceiling on elapsed time, not on time already spent. A request
// dispatched at 19.9s would run to its own timeout and carry the total past the
// number stated here, so a site is only contacted while a whole timeout still
// fits inside the budget. That makes the worst case the budget itself rather
// than the budget plus one timeout.
$gecx_notify_timeout_seconds = 5.0;
$gecx_notify_budget_seconds  = 20.0;
$gecx_notify_started_at      = microtime( true );

foreach ( $gecx_site_ids as $gecx_site_id ) {
    if ( null !== $gecx_site_id ) {
        switch_to_blog( (int) $gecx_site_id );
    }

    try {
        $gecx_agent_name  = get_option( 'gecx_agent_name', '' );
        $gecx_console_url = get_option( 'gecx_console_base_url', 'https://gecx.cloud.google.com' );
        $gecx_store_url   = function_exists( 'home_url' ) ? home_url() : '';

        // 1. Delete the order.created webhook if present.
        $gecx_webhook_id   = get_option( 'gecx_webhook_id' );
        $gecx_wc_available = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );

        if ( $gecx_wc_available ) {
            if ( ! empty( $gecx_webhook_id ) ) {
                try {
                    $gecx_webhook = new \WC_Webhook( (int) $gecx_webhook_id );
                    if ( $gecx_webhook->get_id() ) {
                        $gecx_webhook->delete( true );
                    }
                } catch ( \Exception $e ) {
                    // Uninstall must finish regardless, but record why the webhook survived.
                    if ( class_exists( 'GECX_Auth' ) ) {
                        GECX_Auth::log( 'Uninstall could not delete the stored webhook: ' . $e->getMessage(), 'debug' );
                    }
                }
            }

            if ( function_exists( 'wc_get_webhooks' ) ) {
                try {
                    $gecx_webhooks = wc_get_webhooks( [
                        'status' => 'any',
                        'search' => 'GECX Agent Order Created',
                        'limit'  => 25,
                    ] );
                    if ( is_array( $gecx_webhooks ) ) {
                        foreach ( $gecx_webhooks as $gecx_candidate ) {
                            if ( $gecx_candidate instanceof \WC_Webhook && 'order.created' === $gecx_candidate->get_topic() && 'GECX Agent Order Created' === $gecx_candidate->get_name() ) {
                                $gecx_candidate->delete( true );
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    if ( class_exists( 'GECX_Auth' ) ) {
                        GECX_Auth::log( 'Uninstall could not query webhooks: ' . $e->getMessage(), 'debug' );
                    }
                }
            }
        } elseif ( isset( $wpdb ) && is_object( $wpdb ) ) {
            // WooCommerce is deactivated or unavailable: clean up directly via $wpdb
            // so the webhook row does not survive and resume firing when WooCommerce
            // is re-activated later.
            $gecx_table_name   = $wpdb->prefix . 'wc_webhooks';
            $gecx_table_exists = true;
            if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                $gecx_escaped_like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $gecx_table_name ) : $gecx_table_name;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $gecx_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gecx_escaped_like ) ) === $gecx_table_name );
            }
            if ( $gecx_table_exists ) {
                if ( ! empty( $gecx_webhook_id ) ) {
                    if ( method_exists( $wpdb, 'delete' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->delete( $gecx_table_name, [ 'webhook_id' => (int) $gecx_webhook_id ], [ '%d' ] );
                    }
                    if ( function_exists( 'wp_cache_delete' ) ) {
                        wp_cache_delete( (int) $gecx_webhook_id, 'webhooks' );
                    }
                }

                if ( method_exists( $wpdb, 'delete' ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->delete(
                        $gecx_table_name,
                        [
                            'name'  => 'GECX Agent Order Created',
                            'topic' => 'order.created',
                        ],
                        [ '%s', '%s' ]
                    );
                }
                if ( function_exists( 'delete_transient' ) ) {
                    delete_transient( 'woocommerce_webhook_ids' );
                    delete_transient( 'woocommerce_webhook_ids_status_active' );
                    delete_transient( 'woocommerce_webhook_ids_status_paused' );
                    delete_transient( 'woocommerce_webhook_ids_status_disabled' );
                }
            }
        }
        delete_option( 'gecx_webhook_id' );

        // 2. Notify Google Backend via RS256 Bearer JWT.
        //
        // Only notify Google if this site was actually connected and holds a
        // valid RSA keypair. Calling generate_admin_jwt() unconditionally would
        // generate a fresh 2048-bit RSA keypair on every unconnected site (and
        // every Multisite subsite) whose public key is unknown to the backend.
        $gecx_was_connected = ! empty( $gecx_agent_name )
            || ! empty( get_option( 'gecx_keypair' ) )
            // Pre-0.3.15 layout, for a store uninstalled before anything read
            // the keypair and migrated it.
            || ! empty( get_option( 'gecx_private_key' ) )
            || ! empty( get_option( 'gecx_auth_complete', 0 ) );

        $gecx_jwt = '';
        if ( $gecx_was_connected && class_exists( 'GECX_Auth' ) ) {
            $gecx_jwt = (string) GECX_Auth::generate_existing_rs256_admin_jwt();
        }

        if ( $gecx_was_connected && ! empty( $gecx_jwt ) && ! empty( $gecx_store_url ) ) {
            $gecx_payload_data = [ 'event' => 'uninstall' ];
            if ( ! empty( $gecx_agent_name ) ) {
                $gecx_payload_data['agent_name'] = $gecx_agent_name;
            }
            $gecx_payload = wp_json_encode( $gecx_payload_data );

            $gecx_headers = [
                'Content-Type'        => 'application/json',
                'X-WC-Webhook-Source' => $gecx_store_url,
                'X-WC-Webhook-Topic'  => 'plugin/uninstalled',
                'Authorization'       => 'Bearer ' . $gecx_jwt,
            ];

            $gecx_webhook_url = esc_url_raw( rtrim( (string) $gecx_console_url, '/' ) . '/woocommerce/webhook' );
            $gecx_scheme      = (string) wp_parse_url( $gecx_webhook_url, PHP_URL_SCHEME );

            $gecx_notify_elapsed = microtime( true ) - $gecx_notify_started_at;
            $gecx_budget_spent   = ( $gecx_notify_elapsed + $gecx_notify_timeout_seconds ) > $gecx_notify_budget_seconds;

            if ( $gecx_budget_spent ) {
                if ( class_exists( 'GECX_Auth' ) ) {
                    GECX_Auth::log( 'Uninstall notification skipped for ' . $gecx_store_url . ': too little of the notification time budget remains to complete a request.', 'warning' );
                }
            } elseif ( 'https' === $gecx_scheme && ( function_exists( 'wp_http_validate_url' ) ? wp_http_validate_url( $gecx_webhook_url ) : filter_var( $gecx_webhook_url, FILTER_VALIDATE_URL ) ) ) {
                // Bounded at $gecx_notify_timeout_seconds for this site, and at
                // $gecx_notify_budget_seconds across the whole network. Only
                // sites that hold a store-signed RS256 JWT reach this.
                //
                // wp_safe_remote_post() rather than wp_remote_post(), matching
                // the two GECX_Admin call sites: the destination comes from an
                // option, so the resolved host is validated against the private
                // and loopback ranges. redirection 0 because the request
                // carries the webhook HMAC signature and, on stores that have
                // one, a store-signed JWT; a 30x would hand both to whatever
                // host the redirect names.
                wp_safe_remote_post( $gecx_webhook_url, [
                    'timeout'             => $gecx_notify_timeout_seconds,
                    'headers'             => $gecx_headers,
                    'body'                => $gecx_payload,
                    'data_format'         => 'body',
                    'redirection'         => 0,
                    'limit_response_size' => 10240,
                ] );
            }
        }

        // 3. Clear all plugin options and scheduled hooks from the local WordPress database.
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( 'gecx_scheduled_version_sync' );
        }
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'gecx_scheduled_version_sync', [], 'gecx' );
        }
        // Legacy shared secret, retired in favour of the store's RSA keypair.
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_keypair' );
        // Pre-0.3.15 layout, deleted too for stores that never migrated.
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        delete_option( 'gecx_keypair_lock' );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_token_broker_name' );
        delete_option( 'gecx_agent_enabled' );
        delete_option( 'gecx_pdp_prompts_enabled' );
        delete_option( 'gecx_button_placement' );
        delete_option( 'gecx_floating_position' );
        delete_option( 'gecx_button_display_style' );
        delete_option( 'gecx_button_label' );
        delete_option( 'gecx_button_short_label' );
        delete_option( 'gecx_button_enable_shimmer' );
        delete_option( 'gecx_defer_widget_until_interaction' );
        delete_option( 'gecx_do_activation_redirect' );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_console_base_url' );
        $gecx_pending_states = get_option( 'gecx_pending_oauth_states', [] );
        if ( is_array( $gecx_pending_states ) && function_exists( 'delete_transient' ) ) {
            foreach ( array_keys( $gecx_pending_states ) as $gecx_state_token ) {
                delete_transient( 'gecx_oauth_state_' . (string) $gecx_state_token );
            }
        }
        delete_option( 'gecx_pending_oauth_states' );
        delete_option( 'gecx_sync_last_attempt' );
        delete_option( 'gecx_store_auth_invalid' );
        delete_option( 'gecx_auth_complete' );
        delete_option( 'gecx_plugin_version' );
        delete_option( 'gecx_version_sync_user_id' );
        delete_option( 'gecx_pending_sync_notices' );

        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( 'gecx_admin_notice_error' );
            delete_transient( class_exists( 'GECX_Auth' ) && defined( 'GECX_Auth::GUEST_JWT_CACHE_TRANSIENT' ) ? GECX_Auth::GUEST_JWT_CACHE_TRANSIENT : 'gecx_guest_jwt_cache' );
        }

        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
            $gecx_options_table = $wpdb->prefix . 'options';
            $gecx_esc_oauth     = ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_gecx_oauth_state_' ) : '_transient_gecx_oauth_state_' ) . '%';
            $gecx_esc_oauth_t   = ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_timeout_gecx_oauth_state_' ) : '_transient_timeout_gecx_oauth_state_' ) . '%';
            $gecx_esc_iss       = ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_gecx_unknown_iss_' ) : '_transient_gecx_unknown_iss_' ) . '%';
            $gecx_esc_iss_t     = ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_timeout_gecx_unknown_iss_' ) : '_transient_timeout_gecx_unknown_iss_' ) . '%';

            // Clean up unindexed transient rows from wp_options on non-object-cache stores.
            // On persistent object cache stores (Redis/Memcached), transients expire by TTL
            // while tracked transients were already evicted via delete_transient() above.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
                    $gecx_options_table,
                    $gecx_esc_oauth,
                    $gecx_esc_oauth_t,
                    $gecx_esc_iss,
                    $gecx_esc_iss_t
                )
            );
        }

        // Strip gecx_session_id from active WooCommerce customer session rows.
        // Note: session_value LIKE '%gecx_session_id%' performs an unindexed full scan of
        // woocommerce_sessions, acceptable only as a one-shot batch cleanup during uninstall.
        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'update' ) && method_exists( $wpdb, 'prepare' ) ) {
            $gecx_sessions_table = $wpdb->prefix . 'woocommerce_sessions';
            $gecx_sessions_like  = '%' . ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( 'gecx_session_id' ) : 'gecx_session_id' ) . '%';
            $gecx_batch_limit    = 500;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $gecx_session_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT session_key, session_value FROM %i WHERE session_value LIKE %s LIMIT %d',
                        $gecx_sessions_table,
                        $gecx_sessions_like,
                        $gecx_batch_limit
                    )
                );
                if ( ! is_array( $gecx_session_rows ) || empty( $gecx_session_rows ) ) {
                    break;
                }
                foreach ( $gecx_session_rows as $gecx_row ) {
                    if ( ! isset( $gecx_row->session_key, $gecx_row->session_value ) || ! function_exists( 'maybe_unserialize' ) || ! function_exists( 'maybe_serialize' ) ) {
                        continue;
                    }
                    $gecx_session_data = maybe_unserialize( $gecx_row->session_value );
                    if ( is_array( $gecx_session_data ) && array_key_exists( 'gecx_session_id', $gecx_session_data ) ) {
                        unset( $gecx_session_data['gecx_session_id'] );
                        $gecx_serialized = maybe_serialize( $gecx_session_data );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->update(
                            $gecx_sessions_table,
                            [ 'session_value' => $gecx_serialized ],
                            [ 'session_key' => (string) $gecx_row->session_key ],
                            [ '%s' ],
                            [ '%s' ]
                        );
                    }
                }
                $gecx_fetched_count = count( $gecx_session_rows );
            } while ( $gecx_fetched_count === $gecx_batch_limit );
        }

        // 4. Clear plugin post meta.
        //
        // Order meta is deliberately left in place. _gecx_session_id records
        // which agent session an order came from, and an order is a financial
        // record that should not be edited because a plugin was removed;
        // _gecx_suggested_prompts_override is plugin configuration attached to
        // a product and has no meaning once the plugin is gone.
        delete_post_meta_by_key( '_gecx_suggested_prompts_override' );
    } finally {
        if ( null !== $gecx_site_id ) {
            restore_current_blog();
        }
    }
}
