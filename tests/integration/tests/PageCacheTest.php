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

declare(strict_types=1);

namespace {
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
	if ( ! function_exists( 'spinupwp_purge_site' ) ) {
		function spinupwp_purge_site(): bool {
			$GLOBALS['gecx_test_page_cache_purges'][] = 'spinupwp';
			return true;
		}
	}
}

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration {

	use Google\Gemini_Enterprise_For_CX\Page_Cache;

	class PageCacheTest extends TestCase {

		public function set_up(): void {
			parent::set_up();
			$GLOBALS['gecx_test_page_cache_purges'] = [];
		}

		public function tear_down(): void {
			remove_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] );
			unset( $GLOBALS['gecx_test_page_cache_purges'] );
			parent::tear_down();
		}

		public function test_watches_every_option_write(): void {
			$cache = new Page_Cache();

			foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
				$this->assertSame( 10, has_action( $hook, [ $cache, 'maybe_schedule_purge' ] ), $hook );
			}
		}

		public function test_a_storefront_setting_change_schedules_one_purge_at_the_end_of_the_request(): void {
			remove_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] );
			$cache = new Page_Cache();

			// The settings page writes several options in one save.
			$cache->maybe_schedule_purge( 'gecx_button_placement' );
			$cache->maybe_schedule_purge( 'gecx_nav_menu_target' );
			$cache->maybe_schedule_purge( 'gecx_floating_position' );

			$this->assertSame( 10, has_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] ) );
		}

		public function test_other_options_do_not_purge(): void {
			remove_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] );
			$cache = new Page_Cache();

			$cache->maybe_schedule_purge( 'blogname' );
			$cache->maybe_schedule_purge( 'gecx_pending_sync_notices' );
			$cache->maybe_schedule_purge( null );

			$this->assertFalse( has_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] ) );
		}

		public function test_a_filter_can_turn_purging_off(): void {
			remove_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] );
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
			$this->assertFalse( has_action( 'shutdown', [ Page_Cache::class, 'purge_all' ] ) );
		}

		public function test_every_option_the_storefront_renders_is_watched(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Storefront.php' );
			preg_match_all( "/get_option\\(\\s*'(gecx_[a-z_]+)'/", $source, $matches );
			$read = array_values( array_unique( $matches[1] ) );

			$this->assertNotEmpty( $read );
			foreach ( $read as $option ) {
				$this->assertContains( $option, Page_Cache::STOREFRONT_OPTIONS, "Storefront renders {$option}, so changing it must purge page caches." );
			}
		}

		public function test_purge_asks_installed_caches_and_then_fires_the_purge_action(): void {
			$fired = [];
			$record_action = static function () use ( &$fired ): void {
				$fired[] = current_action();
			};
			foreach ( [ 'cloudflare_purge_everything', 'litespeed_purge_all', Page_Cache::PURGE_ACTION ] as $action ) {
				add_action( $action, $record_action );
			}

			Page_Cache::purge_all();

			$this->assertSame( [ 'wp-rocket', 'w3-total-cache', 'spinupwp' ], $GLOBALS['gecx_test_page_cache_purges'] );
			$this->assertContains( 'cloudflare_purge_everything', $fired );
			$this->assertContains( 'litespeed_purge_all', $fired );
			$this->assertSame( Page_Cache::PURGE_ACTION, end( $fired ) );
		}

		public function test_purge_flushes_batcache_when_present(): void {
			wp_cache_set( 'cached_home', '<html>cached</html>', 'batcache' );
			$GLOBALS['batcache'] = (object) [ 'group' => 'batcache' ];

			try {
				Page_Cache::purge_all();
				$this->assertFalse( wp_cache_get( 'cached_home', 'batcache' ) );
			} finally {
				unset( $GLOBALS['batcache'] );
			}
		}

		public function test_plugin_deactivation_purges_page_caches(): void {
			$purged = 0;
			$listener = static function () use ( &$purged ): void {
				$purged++;
			};
			add_action( Page_Cache::PURGE_ACTION, $listener );

			try {
				\Google\Gemini_Enterprise_For_CX\Admin::deactivate_plugin( false );
				$this->assertSame( 1, $purged );
			} finally {
				remove_action( Page_Cache::PURGE_ACTION, $listener );
			}
		}
	}
}
