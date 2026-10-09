<?php
/**
 * PHPUnit test bootstrap file.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

use function Yoast\WPTestUtils\WPIntegration\bootstrap_it;
use function Yoast\WPTestUtils\WPIntegration\get_path_to_wp_test_dir;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

// Detect or configure WP tests directory.
if ( ! getenv( 'WP_TESTS_DIR' ) ) {
	putenv( 'WP_TESTS_DIR=' . dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit' );
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$_tests_dir = get_path_to_wp_test_dir();
if ( false === $_tests_dir ) {
	$_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit/';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . 'includes/functions.php';

/**
 * Manually load WooCommerce and Gemini Enterprise for CX.
 */
tests_add_filter( 'muplugins_loaded', static function () {
	// Locate and load WooCommerce.
	$wc_candidates = [
		dirname( __DIR__, 2 ) . '/vendor/woocommerce/woocommerce/woocommerce.php',
		dirname( __DIR__, 3 ) . '/woocommerce/woocommerce.php',
		'/var/www/html/wp-content/plugins/woocommerce/woocommerce.php',
	];
	foreach ( $wc_candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			require_once $candidate;
			break;
		}
	}

	// Load plugin under test.
	require_once dirname( __DIR__, 2 ) . '/gecx-agent.php';
} );

// Bootstrap WordPress and testing framework.
bootstrap_it();

\WC_Install::create_tables();
\WC_Install::create_roles();
