<?php
/**
 * WordPress configuration file for PHPUnit tests.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

// Test with WordPress debug mode.
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

$get_env = static function ( string $name, $default_value ) {
	$value = getenv( $name );
	return false !== $value ? $value : $default_value;
};

// Path to WordPress core installation.
if ( ! defined( 'ABSPATH' ) ) {
	$core_dir = $get_env( 'WP_CORE_DIR', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress/' );
	define( 'ABSPATH', rtrim( $core_dir, '/' ) . '/' );
}

// Database configuration.
define( 'DB_NAME', (string) $get_env( 'WP_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', (string) $get_env( 'WP_DB_USER', 'root' ) );
define( 'DB_PASSWORD', (string) $get_env( 'WP_DB_PASS', $get_env( 'WP_DB_PASSWORD', '' ) ) );
define( 'DB_HOST', (string) $get_env( 'WP_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = (string) $get_env( 'WP_TABLE_PREFIX', 'wptests_' );

define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

// Register the test theme directory before wp-settings.php loads.
$theme_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit/data/themedir1';
if ( is_dir( $theme_dir ) ) {
	$GLOBALS['wp_theme_directories'] = [ $theme_dir ];
}
