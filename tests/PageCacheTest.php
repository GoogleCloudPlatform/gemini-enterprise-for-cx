<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests for purging page caches after a storefront setting changes.
 *
 * @package Gemini_Enterprise_For_CX
 */

use Google\Gemini_Enterprise_For_CX\Page_Cache;

// Stand-ins for page cache plugins, recording that they were asked to purge.
if ( ! function_exists( 'rocket_clean_domain' ) ) {
    function rocket_clean_domain( $lang = '', $filesystem = null ): bool {
        $GLOBALS['gecx_test_page_cache_purges'][] = 'wp-rocket';
        return true;
    }
}
if ( ! function_exists( 'w3tc_flush_all' ) ) {
    function w3tc_flush_all(): void {
        $GLOBALS['gecx_test_page_cache_purges'][] = 'w3-total-cache';
    }
}

class PageCacheTest extends GECX_TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['gecx_test_action_priorities'] = [];
        $GLOBALS['gecx_test_page_cache_purges'] = [];
    }

    /**
     * Callbacks registered on a hook.
     *
     * @return array<int, mixed>
     */
    private function callbacks_for( string $hook ): array {
        return array_map(
            static function ( array $entry ) {
                return $entry[0];
            },
            $GLOBALS['gecx_test_action_priorities'][ $hook ] ?? []
        );
    }

    /**
     * Names of the actions fired, in order.
     *
     * @return string[]
     */
    private function fired_actions(): array {
        return array_map(
            static function ( array $entry ): string {
                return $entry[0];
            },
            $GLOBALS['gecx_test_actions']
        );
    }

    public function test_watches_every_option_write(): void {
        $cache = new Page_Cache();

        foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
            $this->assertSame( [ [ $cache, 'maybe_schedule_purge' ] ], $this->callbacks_for( $hook ), $hook );
        }
    }

    public function test_a_storefront_setting_change_schedules_one_purge_at_the_end_of_the_request(): void {
        $cache = new Page_Cache();

        // The settings page writes several options in one save.
        $cache->maybe_schedule_purge( 'gecx_button_placement' );
        $cache->maybe_schedule_purge( 'gecx_nav_menu_target' );
        $cache->maybe_schedule_purge( 'gecx_floating_position' );

        $this->assertSame( [ [ Page_Cache::class, 'purge_all' ] ], $this->callbacks_for( 'shutdown' ) );
    }

    public function test_other_options_do_not_purge(): void {
        $cache = new Page_Cache();

        $cache->maybe_schedule_purge( 'blogname' );
        $cache->maybe_schedule_purge( 'gecx_pending_sync_notices' );
        $cache->maybe_schedule_purge( null );

        $this->assertSame( [], $this->callbacks_for( 'shutdown' ) );
    }

    public function test_a_filter_can_turn_purging_off(): void {
        $seen = [];
        add_filter(
            'gecx_purge_page_caches_on_change',
            static function ( bool $purge, string $option ) use ( &$seen ): bool {
                $seen[] = $option;
                return false;
            },
            10,
            2
        );
        $cache = new Page_Cache();

        $cache->maybe_schedule_purge( 'gecx_button_placement' );

        $this->assertSame( [ 'gecx_button_placement' ], $seen );
        $this->assertSame( [], $this->callbacks_for( 'shutdown' ) );
    }

    public function test_every_option_the_storefront_renders_is_watched(): void {
        $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Storefront.php' );
        preg_match_all( "/get_option\\(\\s*'(gecx_[a-z_]+)'/", $source, $matches );
        $read = array_values( array_unique( $matches[1] ) );

        $this->assertNotEmpty( $read );
        foreach ( $read as $option ) {
            $this->assertContains( $option, Page_Cache::STOREFRONT_OPTIONS, "Storefront renders {$option}, so changing it must purge page caches." );
        }
    }

    public function test_purge_asks_installed_caches_and_then_fires_the_purge_action(): void {
        Page_Cache::purge_all();

        $this->assertSame( [ 'wp-rocket', 'w3-total-cache' ], $GLOBALS['gecx_test_page_cache_purges'] );
        $fired = $this->fired_actions();
        $this->assertContains( 'cloudflare_purge_everything', $fired );
        $this->assertContains( 'litespeed_purge_all', $fired );
        $this->assertSame( Page_Cache::PURGE_ACTION, end( $fired ) );
    }
}
