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
 * Gemini Enterprise for CX backend webhooks
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Pauses, resumes and deletes the WooCommerce webhooks that the Gemini
 * Enterprise for CX backend registers on the store with its REST API keys:
 * the product.created, product.updated, product.deleted and product.restored
 * catalog webhooks, and any order.created webhook, all delivered to
 * /woocommerce/webhook on a Google console host.
 *
 * The plugin did not create these and holds no IDs for them, so they are
 * recognized by delivery URL alone, and a webhook delivered anywhere else is
 * never touched. The order webhook this plugin creates itself ("GECX Agent
 * Order Created") has the same delivery URL but its own lifecycle in
 * Order_Webhook, which follows the storefront widget setting, so pausing and
 * resuming here leave it alone. Uninstall deletes it along with the rest.
 *
 * Every method is best effort. They run from activation, deactivation, unlink
 * and uninstall, where an exception would either fatal or stop the rest of
 * the cleanup, so failures are logged and swallowed.
 */
class Backend_Webhooks {

    /**
     * Path on the console host that the backend's webhooks deliver to.
     */
    public const DELIVERY_PATH = '/woocommerce/webhook';

    /**
     * Hosts the backend registers webhooks against, in addition to the
     * console hosts Auth allows.
     *
     * This list only decides which existing webhooks count as ours; the
     * plugin never sends anything to it. The staging console is listed
     * because a non-production backend registers webhooks that point at
     * itself, and those need cleaning up too. It is deliberately not in
     * Auth::CONSOLE_ALLOWED_HOSTS, which decides where credentials may go.
     */
    public const DELIVERY_HOSTS = [
        'gecx.cloud.google.com',
        'gecx-agentis-express-console-staging.cloud.google.com',
    ];

    /**
     * Non-autoloaded option listing the IDs of the webhooks pause_webhooks()
     * paused, so that resume_webhooks() reactivates those and no others. A
     * webhook the merchant paused, or one WooCommerce disabled after failed
     * deliveries, stays as it is.
     */
    public const PAUSED_IDS_OPTION = 'gecx_paused_backend_webhook_ids';

    /**
     * Name of the order webhook managed by Order_Webhook.
     */
    private const ORDER_WEBHOOK_NAME = 'GECX Agent Order Created';

    /**
     * Whether a webhook delivery URL points at the backend's webhook endpoint.
     *
     * Only the host and path decide it. The host has to be one of ours
     * exactly, so a look-alike such as gecx.cloud.google.com.example.net does
     * not match, and the path has to be the webhook endpoint itself, not a
     * sibling such as /woocommerce/webhook/woo-auth.
     *
     * @param string $url Webhook delivery URL.
     */
    public static function is_backend_delivery_url( string $url ): bool {
        $parts = wp_parse_url( trim( $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'https', 'http' ], true ) ) {
            return false;
        }
        if ( self::DELIVERY_PATH !== untrailingslashit( (string) ( $parts['path'] ?? '' ) ) ) {
            return false;
        }

        return in_array( strtolower( (string) $parts['host'] ), self::get_delivery_hosts(), true );
    }

    /**
     * Pauses every active backend webhook and records which ones were paused.
     *
     * Paused rather than deleted, so that reactivating the plugin or linking
     * an agent again resumes catalog sync without the backend having to
     * register its webhooks a second time. Safe to call repeatedly.
     */
    public static function pause_webhooks(): void {
        try {
            $paused = self::get_paused_ids();
            $before = $paused;
            foreach ( self::find_webhooks() as $row ) {
                if ( 'active' !== $row['status'] || self::is_order_webhook( $row ) ) {
                    continue;
                }
                if ( self::change_status( $row['id'], 'active', 'paused' ) ) {
                    $paused[] = $row['id'];
                }
            }
            $paused = array_values( array_unique( $paused ) );
            if ( $paused !== $before ) {
                update_option( self::PAUSED_IDS_OPTION, $paused, false );
            }
        } catch ( \Throwable $e ) {
            Auth::log( 'Could not pause backend webhooks: ' . $e->getMessage(), 'warning' );
        }
    }

    /**
     * Reactivates the backend webhooks that pause_webhooks() paused.
     *
     * A recorded webhook that has since been deleted, re-pointed elsewhere or
     * set to another status is skipped. Safe to call repeatedly.
     */
    public static function resume_webhooks(): void {
        try {
            $ids = self::get_paused_ids();
            if ( empty( $ids ) ) {
                return;
            }
            foreach ( self::find_webhooks() as $row ) {
                if ( ! in_array( $row['id'], $ids, true ) || 'paused' !== $row['status'] || self::is_order_webhook( $row ) ) {
                    continue;
                }
                self::change_status( $row['id'], 'paused', 'active' );
            }
            delete_option( self::PAUSED_IDS_OPTION );
        } catch ( \Throwable $e ) {
            Auth::log( 'Could not resume backend webhooks: ' . $e->getMessage(), 'warning' );
        }
    }

    /**
     * Resumes the paused backend webhooks if this store is linked to an agent.
     *
     * Runs on activation. A store the merchant unlinked keeps its webhooks
     * paused until an agent is linked again.
     */
    public static function resume_webhooks_if_linked(): void {
        if ( '' === (string) get_option( 'gecx_agent_name', '' ) || get_option( Auth::MERCHANT_UNLINKED_OPTION, false ) ) {
            return;
        }
        self::resume_webhooks();
    }

    /**
     * Permanently deletes every backend webhook, including the plugin's own
     * order webhook, whatever its status.
     *
     * Works without WooCommerce loaded, which is the case when a merchant
     * deactivates WooCommerce before uninstalling this plugin: the rows are
     * then deleted directly so they cannot start firing again when
     * WooCommerce is reactivated.
     *
     * @return int Number of webhooks deleted.
     */
    public static function delete_webhooks(): int {
        $deleted = 0;
        try {
            foreach ( self::find_webhooks() as $row ) {
                if ( self::delete_webhook( $row['id'] ) ) {
                    ++$deleted;
                }
            }
            delete_option( self::PAUSED_IDS_OPTION );
        } catch ( \Throwable $e ) {
            Auth::log( 'Could not delete backend webhooks: ' . $e->getMessage(), 'warning' );
        }
        return $deleted;
    }

    /**
     * Hosts a backend delivery URL may name.
     *
     * @return string[] Lower-case host names.
     */
    private static function get_delivery_hosts(): array {
        return array_values( array_unique( array_map( 'strtolower', array_merge( self::DELIVERY_HOSTS, Auth::get_allowed_console_hosts() ) ) ) );
    }

    /**
     * IDs recorded by pause_webhooks().
     *
     * @return int[]
     */
    private static function get_paused_ids(): array {
        $ids = get_option( self::PAUSED_IDS_OPTION, [] );
        if ( ! is_array( $ids ) ) {
            return [];
        }
        return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    }

    /**
     * Whether WooCommerce's webhook API can be used.
     */
    private static function wc_available(): bool {
        return class_exists( 'WC_Webhook' ) && ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || empty( $GLOBALS['gecx_test_disable_wc_webhook'] ) );
    }

    /**
     * Whether a row is the order webhook that Order_Webhook manages.
     *
     * @param array{id: int, status: string, name: string, topic: string} $row Webhook row.
     */
    private static function is_order_webhook( array $row ): bool {
        return ( self::ORDER_WEBHOOK_NAME === $row['name'] && 'order.created' === $row['topic'] )
            || (int) get_option( 'gecx_webhook_id', 0 ) === $row['id'];
    }

    /**
     * Webhooks on this site whose delivery URL is the backend's endpoint.
     *
     * Read straight from the table: WooCommerce has no lookup by delivery
     * URL, and this also has to work when WooCommerce is not loaded.
     *
     * @return array<int, array{id: int, status: string, name: string, topic: string}>
     */
    private static function find_webhooks(): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [];
        }

        $table = $wpdb->prefix . 'wc_webhooks';

        // WooCommerce may have been deleted along with its tables.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, nothing to cache.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $found !== $table ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WooCommerce API finds webhooks by delivery URL, and the result must be current.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT webhook_id, status, name, topic, delivery_url FROM %i WHERE delivery_url LIKE %s',
                $table,
                '%' . $wpdb->esc_like( self::DELIVERY_PATH ) . '%'
            )
        );
        if ( ! is_array( $results ) ) {
            return [];
        }

        $rows = [];
        foreach ( $results as $result ) {
            if ( ! is_object( $result ) || ! isset( $result->webhook_id, $result->delivery_url ) ) {
                continue;
            }
            if ( ! self::is_backend_delivery_url( (string) $result->delivery_url ) ) {
                continue;
            }
            $rows[] = [
                'id'     => (int) $result->webhook_id,
                'status' => (string) ( $result->status ?? '' ),
                'name'   => (string) ( $result->name ?? '' ),
                'topic'  => (string) ( $result->topic ?? '' ),
            ];
        }
        return $rows;
    }

    /**
     * Moves one webhook from one status to another if it is still in the first.
     *
     * @param int    $id   Webhook ID.
     * @param string $from Status the webhook is expected to have.
     * @param string $to   Status to set.
     * @return bool True if the status was changed.
     */
    private static function change_status( int $id, string $from, string $to ): bool {
        try {
            if ( self::wc_available() ) {
                $webhook = new \WC_Webhook( $id );
                if ( $webhook->get_id() <= 0
                    || $from !== $webhook->get_status()
                    || ! self::is_backend_delivery_url( (string) $webhook->get_delivery_url() ) ) {
                    return false;
                }
                $webhook->set_status( $to );
                $webhook->save();
                return true;
            }

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce is not loaded; caches are cleared below.
            $updated = $wpdb->update(
                $wpdb->prefix . 'wc_webhooks',
                [ 'status' => $to ],
                [
                    'webhook_id' => $id,
                    'status'     => $from,
                ],
                [ '%s' ],
                [ '%d', '%s' ]
            );
            self::clear_caches( $id );
            return is_int( $updated ) && $updated > 0;
        } catch ( \Throwable $e ) {
            Auth::log( sprintf( 'Could not set backend webhook %d to %s: %s', $id, $to, $e->getMessage() ), 'warning' );
            return false;
        }
    }

    /**
     * Permanently deletes one webhook.
     *
     * @param int $id Webhook ID.
     * @return bool True if the webhook was deleted.
     */
    private static function delete_webhook( int $id ): bool {
        try {
            if ( self::wc_available() ) {
                $webhook = new \WC_Webhook( $id );
                if ( $webhook->get_id() <= 0 ) {
                    return false;
                }
                $webhook->delete( true );
                return true;
            }

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce is not loaded; caches are cleared below.
            $deleted = $wpdb->delete( $wpdb->prefix . 'wc_webhooks', [ 'webhook_id' => $id ], [ '%d' ] );
            self::clear_caches( $id );
            return is_int( $deleted ) && $deleted > 0;
        } catch ( \Throwable $e ) {
            Auth::log( sprintf( 'Could not delete backend webhook %d: %s', $id, $e->getMessage() ), 'warning' );
            return false;
        }
    }

    /**
     * Clears what WooCommerce caches about webhooks after a direct write, so
     * it does not keep loading a webhook from a stale ID list.
     *
     * @param int $id Webhook ID.
     */
    private static function clear_caches( int $id ): void {
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( 'woocommerce_webhook_ids' );
            delete_transient( 'woocommerce_webhook_ids_status_active' );
            delete_transient( 'woocommerce_webhook_ids_status_paused' );
            delete_transient( 'woocommerce_webhook_ids_status_disabled' );
        }
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $id, 'webhooks' );
        }
    }
}
