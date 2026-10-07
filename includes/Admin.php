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
 * Gemini Enterprise for CX Admin
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX;

use Google\Gemini_Enterprise_For_CX\Admin\Connection;
use Google\Gemini_Enterprise_For_CX\Admin\Console_Sync;
use Google\Gemini_Enterprise_For_CX\Admin\Product_Prompts;
use Google\Gemini_Enterprise_For_CX\Admin\Settings_Page;
use Google\Gemini_Enterprise_For_CX\REST\Order_Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Creates the admin components, and holds the option names, activation hooks
 * and helpers they share.
 */
class Admin {

    /**
     * Option set when Google reports that it can no longer use this store's
     * credentials.
     *
     * The keys themselves are left in place here: Google is reporting that it
     * cannot use them, not that the merchant withdrew them, and re-authorizing
     * issues fresh ones. They are revoked locally only on uninstall. This flag
     * makes the settings page offer the authorize step again and is cleared
     * once the store is authorized. The order webhook is left alone so
     * re-authorizing reuses it instead of creating a duplicate.
     */
    public const STORE_AUTH_INVALID_OPTION = 'gecx_store_auth_invalid';

    /**
     * Option set when the merchant explicitly unlinks the agent.
     *
     * SyncState still runs while set, so credential problems are still
     * reported, but apply_sync_status() will not write an agent the backend
     * reports back into this store: no re-link, no re-enabled storefront
     * widget, no reactivated order webhook. It is cleared when the merchant
     * completes the connect flow, when an agent is linked through link-agent,
     * and when SyncState first reports that the backend holds no link.
     *
     * Known trade-off: after a local-only unlink (console base URL refused),
     * or a 403 where the backend still holds another agent, SyncState keeps
     * reporting an agent, so the flag stays set and the store ignores that
     * binding until the merchant reconnects or an agent is linked again.
     * That is deliberate: it fails closed.
     */
    public const MERCHANT_UNLINKED_OPTION = Auth::MERCHANT_UNLINKED_OPTION;

    /**
     * Option set when the merchant switches the storefront widget off.
     *
     * Adopting a binding reported by SyncState or by link-agent updates the
     * agent name but leaves the widget and the order webhook off while this
     * is set. It is cleared when the merchant switches the widget back on, and
     * by an explicit unlink.
     */
    public const MERCHANT_DISABLED_OPTION = Auth::MERCHANT_DISABLED_OPTION;

    /**
     * Option set once the merchant has authorized or connected the store.
     *
     * Gating sync_agent_state() on this option ensures no outbound SyncState
     * calls or admin JWT transmissions occur before the merchant grants consent
     * on a fresh activation, while still allowing an already-authorized store
     * to recover its binding via SyncState if gecx_agent_name is deleted.
     */
    public const AUTH_COMPLETE_OPTION = 'gecx_auth_complete';

    /**
     * Option storing the last observed plugin version so upgrades trigger an
     * immediate SyncState reconciliation.
     */
    public const PLUGIN_VERSION_OPTION = 'gecx_plugin_version';

    /**
     * Option holding the codes of sync notices that were raised while no
     * screen was available to render them.
     */
    public const PENDING_NOTICES_OPTION = 'gecx_pending_sync_notices';

    /**
     * Action hook used by WP-Cron to execute version upgrade SyncState
     * reconciliation asynchronously without blocking admin_init.
     */
    public const VERSION_SYNC_CRON_HOOK = 'gecx_scheduled_version_sync';

    /**
     * Non-autoloaded option holding the administrator user ID that triggered
     * the pending version upgrade sync.
     */
    public const VERSION_SYNC_USER_OPTION = 'gecx_version_sync_user_id';

    /** Agent state sync with the console. */
    public Console_Sync $console_sync;

    /** The store connection flow. */
    public Connection $connection;

    /** The settings page. */
    public Settings_Page $settings_page;

    /** The product editor's prompt override field. */
    public Product_Prompts $product_prompts;

    /**
     * Creates the admin components and registers their hooks.
     */
    public function __construct( string $plugin_file ) {
        $this->console_sync    = new Console_Sync();
        $this->connection      = new Connection();
        $this->settings_page   = new Settings_Page( $plugin_file, $this->console_sync );
        $this->product_prompts = new Product_Prompts();

        // In this order so callbacks sharing a hook (admin_init,
        // admin_notices) keep the order they ran in as one class.
        $this->settings_page->register_hooks();
        $this->connection->register_hooks();
        $this->console_sync->register_hooks();
        $this->product_prompts->register_hooks();
    }

    /**
     * Triggered upon plugin activation.
     */
    public static function activate_plugin(): void {
        update_option( 'gecx_do_activation_redirect', true );
        delete_option( 'gecx_dismiss_activation_notice' );
        Auth::get_or_generate_keypair();
        Order_Webhook::reconcile_webhook_on_activation();
    }

    /**
     * Triggered upon plugin deactivation.
     * Pauses the order webhook so no orders are transmitted while deactivated.
     */
    public static function deactivate_plugin(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::VERSION_SYNC_CRON_HOOK );
        }
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::VERSION_SYNC_CRON_HOOK, [], 'gecx' );
        }
        Order_Webhook::set_order_webhook_status( 'paused' );
    }

    /**
     * Whether the merchant explicitly unlinked the agent and has not re-linked.
     */
    public static function is_merchant_unlinked(): bool {
        return (bool) get_option( self::MERCHANT_UNLINKED_OPTION, false );
    }

    /**
     * Sanitize agent resource name against canonical format.
     *
     * @param mixed $agent_name Raw agent name option value.
     * @return string Validated agent resource name, or empty string if invalid.
     */
    public static function sanitize_agent_name( $agent_name ): string {
        if ( ! is_string( $agent_name ) && ! is_numeric( $agent_name ) ) {
            return '';
        }
        $sanitized = sanitize_text_field( (string) $agent_name );
        if ( '' === $sanitized || 1 !== preg_match( Auth::RESOURCE_NAME_PATTERN, $sanitized ) ) {
            return '';
        }
        return $sanitized;
    }

    /**
     * Checks whether the store's WordPress REST API is reachable at the
     * standard `<home_url>/wp-json/` path required by WooCommerce OAuth
     * (`/wc-auth/v1/authorize`) and the Google Cloud backend.
     *
     * Rejects plain permalinks (`?rest_route=/`), PATHINFO permalinks
     * (`/index.php/wp-json/`), and custom `rest_url_prefix` values.
     *
     * @return bool True when pretty permalinks and the default `/wp-json` prefix are active.
     */
    public static function is_standard_rest_api_enabled(): bool {
        $structure = (string) get_option( 'permalink_structure', '' );
        if ( '' === $structure || false !== strpos( $structure, 'index.php' ) ) {
            return false;
        }

        $prefix = function_exists( 'rest_get_url_prefix' )
            ? trim( (string) rest_get_url_prefix(), '/' )
            : 'wp-json';
        if ( 'wp-json' !== $prefix ) {
            return false;
        }

        if ( function_exists( 'rest_url' ) && function_exists( 'home_url' ) ) {
            $expected_rest_base = untrailingslashit( (string) home_url() ) . '/wp-json';
            $actual_rest_base   = untrailingslashit( (string) rest_url() );
            $expected_no_scheme = (string) preg_replace( '#^https?://#i', '', $expected_rest_base );
            $actual_no_scheme   = (string) preg_replace( '#^https?://#i', '', $actual_rest_base );
            if ( $expected_no_scheme !== $actual_no_scheme ) {
                return false;
            }
        }

        return true;
    }
}
