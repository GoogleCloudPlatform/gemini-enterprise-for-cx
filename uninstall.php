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

        // 1. Retrieve secret from webhook if available, then delete the webhook.
        $gecx_webhook_id   = get_option( 'gecx_webhook_id' );
        $gecx_secret       = '';
        $gecx_wc_available = class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );

        if ( $gecx_wc_available ) {
            if ( ! empty( $gecx_webhook_id ) ) {
                try {
                    $gecx_webhook = new \WC_Webhook( (int) $gecx_webhook_id );
                    if ( $gecx_webhook->get_id() ) {
                        $gecx_secret = $gecx_webhook->get_secret();
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
                                if ( empty( $gecx_secret ) ) {
                                    $gecx_secret = $gecx_candidate->get_secret();
                                }
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
        delete_option( 'gecx_webhook_id' );

        // 2. Notify Google Backend.
        //
        // The HMAC signature is derived from the WooCommerce webhook secret,
        // which is symmetric and readable from the backend's own storage, so on
        // its own it lets anyone who reads that storage forge an uninstall and
        // delete a merchant's installation. The store-signed RS256 JWT closes
        // that, and is what the backend is moving to; today's deployed handler
        // still requires the signature and ignores the Authorization header.
        //
        // Both are sent. Dropping the signature would break every backend that
        // has not picked up the JWT path yet, and dropping the JWT would leave
        // the forgery open, so they overlap until the legacy path is removed.
        // Only notify Google if this site was actually connected. Calling
        // generate_admin_jwt() unconditionally would generate a fresh 2048-bit
        // RSA keypair on every unconnected site (and every Multisite subsite)
        // and send its domain + admin email to Google on plugin deletion.
        // Furthermore, during uninstall the store's public-key endpoint is
        // about to be torn down, so a newly minted RSA keypair would fail
        // backend JWT verification and cause VerifyUninstallAuth to reject an
        // otherwise valid HMAC-signed uninstall webhook.
        $gecx_was_connected = ! empty( $gecx_secret )
            || ! empty( $gecx_agent_name )
            || ! empty( get_option( 'gecx_private_key' ) )
            || ! empty( get_option( 'gecx_auth_complete', 0 ) );

        $gecx_jwt = '';
        if ( $gecx_was_connected && class_exists( 'GECX_Auth' ) ) {
            $gecx_jwt = (string) GECX_Auth::generate_existing_rs256_admin_jwt();
        }

        if ( $gecx_was_connected && ( ! empty( $gecx_jwt ) || ! empty( $gecx_secret ) ) && ! empty( $gecx_store_url ) ) {
            $gecx_payload_data = [ 'event' => 'uninstall' ];
            if ( ! empty( $gecx_agent_name ) ) {
                $gecx_payload_data['agent_name'] = $gecx_agent_name;
            }
            $gecx_payload = wp_json_encode( $gecx_payload_data );

            $gecx_headers = [
                'Content-Type'        => 'application/json',
                'X-WC-Webhook-Source' => $gecx_store_url,
                'X-WC-Webhook-Topic'  => 'plugin/uninstalled',
            ];
            if ( ! empty( $gecx_secret ) ) {
                // Our C++ Backend relies on a standard HMAC-SHA256 signature, base64 encoded.
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- standard base64 HMAC signature encoding, not obfuscation.
                $gecx_headers['X-WC-Webhook-Signature'] = base64_encode( hash_hmac( 'sha256', $gecx_payload, $gecx_secret, true ) );
            }
            if ( ! empty( $gecx_jwt ) ) {
                $gecx_headers['Authorization'] = 'Bearer ' . $gecx_jwt;
            }

            $gecx_webhook_url = esc_url_raw( rtrim( (string) $gecx_console_url, '/' ) . '/woocommerce/webhook' );
            $gecx_scheme      = (string) wp_parse_url( $gecx_webhook_url, PHP_URL_SCHEME );

            $gecx_notify_elapsed = microtime( true ) - $gecx_notify_started_at;
            $gecx_budget_spent   = ( $gecx_notify_elapsed + $gecx_notify_timeout_seconds ) > $gecx_notify_budget_seconds;

            if ( $gecx_budget_spent ) {
                if ( class_exists( 'GECX_Auth' ) ) {
                    GECX_Auth::log( 'Uninstall notification skipped for ' . $gecx_store_url . ': too little of the notification time budget remains to complete a request.', 'warning' );
                }
            } elseif ( 'https' === $gecx_scheme && ( function_exists( 'wp_http_validate_url' ) ? wp_http_validate_url( $gecx_webhook_url ) : filter_var( $gecx_webhook_url, FILTER_VALIDATE_URL ) ) ) {
                if ( empty( $gecx_secret ) && class_exists( 'GECX_Auth' ) ) {
                    // Sent JWT-only. Whether the backend accepts it depends on
                    // the backend's deployed version, so this is recorded
                    // rather than skipped: suppressing the request here would
                    // permanently opt this store out of the JWT-only path on
                    // every backend that does support it.
                    GECX_Auth::log( 'Uninstall notification for ' . $gecx_store_url . ' carries no webhook secret and is signed only with the store JWT; older backends will reject it and the installation entry will survive.', 'warning' );
                }

                // Bounded at $gecx_notify_timeout_seconds for this site, and at
                // $gecx_notify_budget_seconds across the whole network. Only
                // sites that hold a credential reach this.
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

        // 3. Clear all plugin options from the local WordPress database.
        // Legacy shared secret, retired in favour of the store's RSA keypair.
        delete_option( 'gecx_api_secret' );
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
        delete_option( 'gecx_do_activation_redirect' );
        delete_option( 'gecx_dismiss_activation_notice' );
        delete_option( 'gecx_console_base_url' );
        delete_option( 'gecx_pending_oauth_states' );
        delete_option( 'gecx_sync_last_attempt' );
        delete_option( 'gecx_store_auth_invalid' );
        delete_option( 'gecx_auth_complete' );
        delete_option( 'gecx_plugin_version' );
        delete_option( 'gecx_pending_sync_notices' );

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
