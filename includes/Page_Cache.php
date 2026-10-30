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
 * Gemini Enterprise for CX Page Cache Purging
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Purges full-page caches after a setting that changes storefront markup.
 *
 * The launcher, its placement and gecxStorefrontConfig are rendered into
 * every storefront page, and page caches keep serving the copy they stored
 * before a change. Without a purge, a merchant who changes the launcher
 * placement sees the old launcher on some pages and the new one on others,
 * depending on when each page was cached, until the cache expires.
 */
class Page_Cache {

    /**
     * Options whose values are rendered into storefront pages.
     */
    public const STOREFRONT_OPTIONS = [
        'gecx_agent_name',
        'gecx_agent_enabled',
        'gecx_token_broker_name',
        'gecx_button_placement',
        'gecx_nav_menu_target',
        'gecx_floating_position',
        'gecx_button_display_style',
        'gecx_button_label',
        'gecx_button_short_label',
        'gecx_button_enable_shimmer',
        'gecx_pdp_prompts_enabled',
        'gecx_price_history_enabled',
        'gecx_defer_widget_until_interaction',
    ];

    /**
     * Fired after the plugin asks the page caches it knows to purge, so hosts
     * and CDNs it does not know can purge theirs.
     */
    public const PURGE_ACTION = 'gecx_purge_page_caches';

    /**
     * Whether a purge is already scheduled for this request.
     */
    private bool $purge_scheduled = false;

    /**
     * Watches option writes. Every save path counts: the settings page, the
     * Settings API form and the console sync all go through update_option(),
     * which fires updated_option only when the value actually changed.
     */
    public function __construct() {
        add_action( 'added_option', [ $this, 'maybe_schedule_purge' ], 10, 1 );
        add_action( 'updated_option', [ $this, 'maybe_schedule_purge' ], 10, 1 );
        add_action( 'deleted_option', [ $this, 'maybe_schedule_purge' ], 10, 1 );
    }

    /**
     * Schedules one purge at the end of the request when a storefront option
     * changes, so a save that writes several options purges once.
     *
     * @param mixed $option Name of the option that changed.
     */
    public function maybe_schedule_purge( $option ): void {
        if ( $this->purge_scheduled || ! is_string( $option ) || ! in_array( $option, self::STOREFRONT_OPTIONS, true ) ) {
            return;
        }

        /**
         * Filters whether the plugin purges page caches after a setting that
         * changes storefront pages is saved.
         *
         * @param bool   $purge  Default true.
         * @param string $option Option that changed.
         */
        if ( ! apply_filters( 'gecx_purge_page_caches_on_change', true, $option ) ) {
            return;
        }

        $this->purge_scheduled = true;
        add_action( 'shutdown', [ self::class, 'purge_all' ] );
    }

    /**
     * Asks every page cache the plugin knows to purge, then fires
     * PURGE_ACTION. Each call is guarded, so caches that are not installed
     * are skipped.
     */
    public static function purge_all(): void {
        // WP Rocket. Also purges Cloudflare and RocketCDN when they are
        // connected in WP Rocket's settings.
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        // W3 Total Cache.
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
        // WP Super Cache.
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        // WP Fastest Cache.
        if ( function_exists( 'wpfc_clear_all_cache' ) ) {
            wpfc_clear_all_cache( true );
        }
        // SiteGround Speed Optimizer.
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();
        }
        // SpinupWP.
        if ( function_exists( 'spinupwp_purge_site' ) ) {
            spinupwp_purge_site();
        }
        // Pantheon Advanced Page Cache.
        if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
            pantheon_wp_clear_edge_all();
        }
        // FlyingPress.
        if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_pages' ) ) {
            \FlyingPress\Purge::purge_pages();
        }
        // WP Engine.
        if ( class_exists( '\WpeCommon' ) ) {
            if ( method_exists( '\WpeCommon', 'purge_memcached' ) ) {
                \WpeCommon::purge_memcached();
            }
            if ( method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
                \WpeCommon::purge_varnish_cache();
            }
        }
        // Kinsta MU plugin.
        if ( isset( $GLOBALS['kinsta_cache'] ) && is_object( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) && is_object( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) && method_exists( $GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_complete_caches' ) ) {
            $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
        }

        // Caches that purge on an action of their own. Firing one that no
        // plugin listens to does nothing.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official Cloudflare plugin's purge action.
        do_action( 'cloudflare_purge_everything' );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's purge action.
        do_action( 'litespeed_purge_all' );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's purge action.
        do_action( 'breeze_clear_all_cache' );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's purge action.
        do_action( 'cache_enabler_clear_complete_cache' );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's purge action.
        do_action( 'rt_nginx_helper_purge_all' );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's purge action.
        do_action( 'wphb_clear_page_cache' );

        /**
         * Fires after the plugin asked the page caches it knows to purge.
         * Hook it to purge a host or CDN cache the plugin does not know.
         */
        do_action( 'gecx_purge_page_caches' );
    }
}
