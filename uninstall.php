<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Gemini_Enterprise_For_CX
 */

// If run outside of WordPress uninstallation, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

global $wpdb;

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

foreach ( $gecx_site_ids as $gecx_site_id ) {
    if ( null !== $gecx_site_id ) {
        switch_to_blog( (int) $gecx_site_id );
    }

    try {
        $gecx_agent_name  = get_option( 'gecx_agent_name', '' );
        $gecx_console_url = get_option( 'gecx_console_base_url', 'https://gecx.cloud.google.com' );
        $gecx_store_url   = function_exists( 'home_url' ) ? home_url() : '';

        // 1. Retrieve secret from webhook if available, then delete the webhook.
        $gecx_webhook_id   = get_option( 'gecx_webhook_id' );
        $gecx_secret       = '';
        $gecx_wc_available = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_TESTING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );

        if ( $gecx_wc_available ) {
            if ( ! empty( $gecx_webhook_id ) ) {
                try {
                    $gecx_webhook = new \WC_Webhook( (int) $gecx_webhook_id );
                    if ( $gecx_webhook->get_id() ) {
                        $gecx_secret = $gecx_webhook->get_secret();
                        $gecx_webhook->delete( true );
                    }
                } catch ( \Exception $e ) {
                    // Suppress exception during uninstallation cleanup.
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
                                if ( empty( $gecx_secret ) ) {
                                    $gecx_secret = $gecx_candidate->get_secret();
                                }
                                $gecx_candidate->delete( true );
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Suppress query error.
                }
            }
        } else {
            // WooCommerce is deactivated or unavailable: clean up directly via $wpdb
            // so the webhook row does not survive and resume firing when WooCommerce
            // is re-activated later.
            if ( isset( $wpdb ) && is_object( $wpdb ) ) {
                $gecx_table_name   = $wpdb->prefix . 'wc_webhooks';
                $gecx_table_exists = true;
                if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                    $gecx_escaped_like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $gecx_table_name ) : $gecx_table_name;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $gecx_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gecx_escaped_like ) ) === $gecx_table_name );
                }
                if ( $gecx_table_exists ) {
                    if ( ! empty( $gecx_webhook_id ) ) {
                        if ( empty( $gecx_secret ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $gecx_found_secret = $wpdb->get_var(
                                $wpdb->prepare(
                                    'SELECT secret FROM %i WHERE webhook_id = %d',
                                    $gecx_table_name,
                                    (int) $gecx_webhook_id
                                )
                            );
                            if ( ! empty( $gecx_found_secret ) ) {
                                $gecx_secret = (string) $gecx_found_secret;
                            }
                        }
                        if ( method_exists( $wpdb, 'delete' ) ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $wpdb->delete( $gecx_table_name, [ 'webhook_id' => (int) $gecx_webhook_id ], [ '%d' ] );
                        }
                        if ( function_exists( 'wp_cache_delete' ) ) {
                            wp_cache_delete( (int) $gecx_webhook_id, 'webhooks' );
                        }
                    }

                    if ( empty( $gecx_secret ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $gecx_found_secret = $wpdb->get_var(
                            $wpdb->prepare(
                                'SELECT secret FROM %i WHERE name = %s AND topic = %s LIMIT 1',
                                $gecx_table_name,
                                'GECX Agent Order Created',
                                'order.created'
                            )
                        );
                        if ( ! empty( $gecx_found_secret ) ) {
                            $gecx_secret = (string) $gecx_found_secret;
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
        }
        delete_option( 'gecx_webhook_id' );

        if ( empty( $gecx_secret ) ) {
            $gecx_secret = get_option( 'gecx_api_secret', '' );
        }
        if ( function_exists( 'apply_filters' ) ) {
            $gecx_secret = (string) apply_filters( 'gecx_api_secret', $gecx_secret );
        }

        // 2. Notify Google Backend securely via WooCommerce-compatible webhook signature.
        if ( ! empty( $gecx_secret ) && ! empty( $gecx_store_url ) ) {
            $gecx_payload_data = [ 'event' => 'uninstall' ];
            if ( ! empty( $gecx_agent_name ) ) {
                $gecx_payload_data['agent_name'] = $gecx_agent_name;
            }
            $gecx_payload = wp_json_encode( $gecx_payload_data );
            // Our C++ Backend relies on a standard HMAC-SHA256 signature, base64 encoded.
            $gecx_signature = base64_encode( hash_hmac( 'sha256', $gecx_payload, $gecx_secret, true ) );

            $gecx_webhook_url = esc_url_raw( rtrim( (string) $gecx_console_url, '/' ) . '/woocommerce/webhook' );
            $gecx_scheme      = (string) wp_parse_url( $gecx_webhook_url, PHP_URL_SCHEME );

            if ( 'https' === $gecx_scheme && ( function_exists( 'wp_http_validate_url' ) ? wp_http_validate_url( $gecx_webhook_url ) : filter_var( $gecx_webhook_url, FILTER_VALIDATE_URL ) ) ) {
                // Bounded at 5 seconds per connected site. Only sites that hold
                // a secret reach this, so an uninstall costs time in proportion
                // to the number of stores actually connected to the backend.
                wp_remote_post( $gecx_webhook_url, [
                    'timeout'     => 5,
                    'headers'     => [
                        'Content-Type'           => 'application/json',
                        'X-WC-Webhook-Source'    => $gecx_store_url,
                        'X-WC-Webhook-Topic'     => 'plugin/uninstalled',
                        'X-WC-Webhook-Signature' => $gecx_signature,
                    ],
                    'body'        => $gecx_payload,
                    'data_format' => 'body',
                ] );
            }
        }

        // 3. Clear all plugin options from the local WordPress database.
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        delete_option( 'gecx_keypair_lock' );
        delete_option( 'gecx_agent_id' );
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
        delete_option( 'gecx_do_activation_redirect' );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_console_base_url' );
        delete_option( 'gecx_pending_oauth_states' );
        delete_option( 'gecx_sync_last_attempt' );
        delete_option( 'gecx_store_auth_invalid' );
        delete_option( 'gecx_auth_complete' );

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
