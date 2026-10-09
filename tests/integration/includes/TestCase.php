<?php
/**
 * Base integration test case for Gemini Enterprise for CX.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\REST\Console_API;
use Yoast\WPTestUtils\WPIntegration\TestCase as PolyfilledTestCase;

/**
 * Base TestCase class extending Yoast WPTestUtils integration test case.
 */
abstract class TestCase extends PolyfilledTestCase {
	use CartTokenMinting;

	/**
	 * Last redirect caught during test.
	 */
	protected ?string $last_redirect = null;

	/**
	 * Last redirect status caught during test.
	 */
	protected ?int $last_redirect_status = null;

	/**
	 * Recorded HTTP requests.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	protected array $http_requests = [];

	/**
	 * Queued mock HTTP responses.
	 *
	 * @var array<int, array|\WP_Error>
	 */
	protected array $http_responses = [];

	/**
	 * Captured WooCommerce cookies.
	 *
	 * @var array<string, array{value: string, expire: int, secure: bool}>
	 */
	protected array $cookies = [];

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->last_redirect        = null;
		$this->last_redirect_status = null;
		$this->http_requests        = [];
		$this->http_responses       = [];
		$this->cookies              = [];

		$this->set_permalink_structure( '/%postname%/' );

		Auth::reset_cart_token_state();
		Console_API::reset_wc_auth_state();

		if ( function_exists( 'WC' ) ) {
			WC()->session = new WC_Session_Mock();
			WC()->cart    = new WC_Cart_Mock();
		}

		global $wpdb;
		if ( isset( $wpdb ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions" );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'gecx_%' OR option_name LIKE '_transient_gecx_%' OR option_name LIKE '_transient_timeout_gecx_%'" );
			wp_cache_flush();
		}

		add_filter( 'pre_http_request', [ $this, 'mock_http_request_handler' ], 10, 3 );
		add_filter( 'woocommerce_set_cookie_enabled', [ $this, 'capture_wc_cookies' ], 10, 5 );

		add_filter( 'wp_redirect', [ $this, 'catch_redirect' ], 1, 2 );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		$this->http_requests  = [];
		$this->http_responses = [];
		$this->cookies        = [];

		$this->disable_ajax();
		Auth::reset_cart_token_state();
		Console_API::reset_wc_auth_state();

		// Clean up custom server headers, superglobals, cookies, and settings errors.
		$_GET                           = [];
		$_POST                          = [];
		$_REQUEST                       = [];
		$_COOKIE                        = [];
		$GLOBALS['wp_settings_errors'] = [];
		unset( $_SERVER['HTTP_CART_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_FETCH_SITE'] );

		parent::tear_down();
	}

	/**
	 * Reads a session from the real woocommerce_sessions table.
	 *
	 * @param string $session_key Session key.
	 * @return array|null Unserialized session array or null.
	 */
	protected function get_wc_session( string $session_key ): ?array {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
				$session_key
			)
		);
		return null !== $val ? maybe_unserialize( $val ) : null;
	}

	/**
	 * Sets a session in the real woocommerce_sessions table.
	 *
	 * @param string   $session_key Session key.
	 * @param array    $data        Session data.
	 * @param int|null $expiry      Session expiry timestamp.
	 */
	protected function set_wc_session( string $session_key, array $data, ?int $expiry = null ): void {
		global $wpdb;
		$wpdb->replace(
			"{$wpdb->prefix}woocommerce_sessions",
			[
				'session_key'    => $session_key,
				'session_value'  => serialize( $data ),
				'session_expiry' => $expiry ?? ( time() + 2 * DAY_IN_SECONDS ),
			]
		);
	}

	/**
	 * Intercepts WooCommerce cookies to record them and prevent header output during tests.
	 *
	 * @param bool   $enabled Whether setting cookie is enabled.
	 * @param string $name    Cookie name.
	 * @param string $value   Cookie value.
	 * @param int    $expire  Expiry timestamp.
	 * @param bool   $secure  Whether cookie is HTTPS only.
	 * @return bool Always false to prevent header sending in tests.
	 */
	public function capture_wc_cookies( $enabled, $name, $value, $expire = 0, $secure = false ): bool {
		$this->cookies[ $name ] = [
			'value'  => (string) $value,
			'expire' => (int) $expire,
			'secure' => (bool) $secure,
		];
		return false;
	}

	/**
	 * Ensures a user with a specific ID exists (used for tests with fixed token subjects).
	 *
	 * @param int    $id    User ID.
	 * @param string $login Username.
	 * @param string $role  Role name.
	 * @param string $email Optional email.
	 * @return \WP_User User instance.
	 */
	protected function ensure_user_with_id( int $id, string $login, string $role = 'customer', string $email = '' ): \WP_User {
		$user = get_userdata( $id );
		if ( $user instanceof \WP_User ) {
			return $user;
		}

		global $wpdb;
		$user_email = empty( $email ) ? "{$login}@example.org" : $email;
		$wpdb->insert(
			$wpdb->users,
			[
				'ID'              => $id,
				'user_login'      => $login,
				'user_pass'       => 'password',
				'user_nicename'   => $login,
				'user_email'      => $user_email,
				'user_registered' => current_time( 'mysql' ),
				'user_status'     => 0,
				'display_name'    => $login,
			]
		);
		clean_user_cache( $id );
		$new_user = new \WP_User( $id );
		$new_user->set_role( $role );
		if ( is_multisite() ) {
			add_user_to_blog( (int) get_current_blog_id(), $id, $role );
		}
		return $new_user;
	}

	/**
	 * Intercepts wp_redirect calls and throws RedirectException.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $status   HTTP status.
	 * @throws RedirectException Always throws to halt execution.
	 */
	public function catch_redirect( $location, $status = 302 ) {
		$this->last_redirect        = (string) $location;
		$this->last_redirect_status = (int) $status;
		throw new RedirectException( (string) $location, (int) $status );
	}

	/**
	 * Enable AJAX mode for simulating wp-ajax requests.
	 */
	public function enable_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Disable AJAX mode.
	 */
	public function disable_ajax(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Handler for pre_http_request to simulate HTTP requests.
	 *
	 * @param mixed  $preempt     Existing preempt response.
	 * @param array  $parsed_args Request arguments.
	 * @param string $url         Target URL.
	 * @return array|\WP_Error Mock response.
	 */
	public function mock_http_request_handler( $preempt, array $parsed_args, string $url ) {
		$this->http_requests[] = [
			'url'  => $url,
			'args' => $parsed_args,
		];

		if ( ! empty( $this->http_responses ) ) {
			return array_shift( $this->http_responses );
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'headers'  => [],
			'body'     => '',
			'cookies'  => [],
		];
	}

	/**
	 * Queues a mock HTTP response.
	 *
	 * @param int          $status_code HTTP status code.
	 * @param string|array $body        Response body.
	 * @param array        $headers     Response headers.
	 * @return array WP HTTP response array.
	 */
	protected function mock_http_response( int $status_code, $body = '', array $headers = [] ): array {
		$response = [
			'response' => [
				'code'    => $status_code,
				'message' => 'OK',
			],
			'headers'  => $headers,
			'body'     => is_array( $body ) ? (string) wp_json_encode( $body ) : (string) $body,
			'cookies'  => [],
		];
		$this->http_responses[] = $response;
		return $response;
	}

	/**
	 * Subscribes to HTTP requests made via WP HTTP.
	 *
	 * @param \Closure   $listener Callback.
	 * @param mixed|null $response Mock response object.
	 * @return \Closure Unsubscribe function.
	 */
	protected function subscribe_to_wp_http_requests( \Closure $listener, $response = null ): \Closure {
		$capture_callback = static function ( $_, $args, $url ) use ( $listener, $response ) {
			$listener( $url, $args );
			return $response ?: $_;
		};

		add_filter( 'pre_http_request', $capture_callback, 0, 3 );

		return static function () use ( $capture_callback ) {
			remove_filter( 'pre_http_request', $capture_callback, 0 );
		};
	}

	/**
	 * URL-safe base64 decode helper.
	 */
	protected function base64_url_decode( string $string ): string {
		$remainder = strlen( $string ) % 4;
		if ( $remainder ) {
			$string .= str_repeat( '=', 4 - $remainder );
		}
		return (string) base64_decode( strtr( $string, '-_', '+/' ) );
	}

	/**
	 * Decodes JWT payload segment to an array.
	 */
	protected function decode_jwt_payload( string $jwt ): ?array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		return json_decode( $this->base64_url_decode( $parts[1] ), true );
	}

	/**
	 * Verifies HMAC SHA-256 JWT signature.
	 */
	protected function verify_jwt_signature( string $jwt, string $secret ): bool {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		$expected_sig   = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );
		$to_base_64_url = static function ( string $string ) {
			return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
		};
		return hash_equals( $to_base_64_url( $expected_sig ), $parts[2] );
	}

	/**
	 * Verifies RS256 JWT signature against PEM public key.
	 */
	protected function verify_rs256_jwt( string $jwt, string $public_key_pem ): bool {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		$signing_input = $parts[0] . '.' . $parts[1];
		$signature     = $this->base64_url_decode( $parts[2] );
		$pub_key_res   = openssl_pkey_get_public( $public_key_pem );
		if ( false === $pub_key_res ) {
			return false;
		}
		return 1 === openssl_verify( $signing_input, $signature, $pub_key_res, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Sets up a dummy block theme directory so tests can switch to a block theme.
	 */
	public function set_up_block_theme(): void {
		$theme_root = sys_get_temp_dir() . '/gecx-test-themes/block-theme';
		if ( ! is_dir( $theme_root . '/templates' ) ) {
			@mkdir( $theme_root . '/templates', 0777, true );
			file_put_contents( $theme_root . '/style.css', "/*\nTheme Name: Block Theme\n*/\n" );
			touch( $theme_root . '/templates/index.html' );
		}
		register_theme_directory( sys_get_temp_dir() . '/gecx-test-themes' );
	}

	/**
	 * Switches theme to a block theme or back to the default classic theme.
	 *
	 * @param bool $is_block True for block theme, false for classic.
	 */
	protected function set_block_theme( bool $is_block ): void {
		if ( $is_block ) {
			$this->set_up_block_theme();
			switch_theme( 'block-theme' );
		} else {
			switch_theme( 'default' );
		}
	}

	/**
	 * Retrieves localized script data from wp_scripts.
	 *
	 * @param string $handle      Script handle.
	 * @param string $object_name JavaScript variable name.
	 * @return array<string, mixed> Decoded data array or empty array.
	 */
	protected function get_localized_script( string $handle, string $object_name ): array {
		$data = wp_scripts()->get_data( $handle, 'data' );
		if ( ! is_string( $data ) || '' === $data ) {
			return [];
		}
		if ( preg_match( '/var\s+' . preg_quote( $object_name, '/' ) . '\s*=\s*(.+?);\s*$/s', $data, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return [];
	}

	/**
	 * Retrieves inline styles added to a style handle.
	 *
	 * @param string $handle Style handle.
	 * @return string Concatenated inline styles.
	 */
	protected function get_inline_styles( string $handle ): string {
		$after = wp_styles()->get_data( $handle, 'after' );
		return is_array( $after ) ? implode( "\n", $after ) : '';
	}
}

if ( ! function_exists( 'gecx_test_http_response' ) ) {
	/**
	 * Generates a mock HTTP response array for tests.
	 *
	 * @param int          $status_code HTTP response status code.
	 * @param string|array $body        Response body.
	 * @param array        $headers     Response headers.
	 * @return array WP HTTP response array.
	 */
	function gecx_test_http_response( int $status_code, $body = '', array $headers = [] ): array {
		return [
			'response' => [
				'code'    => $status_code,
				'message' => 'OK',
			],
			'headers'  => $headers,
			'body'     => is_array( $body ) ? (string) wp_json_encode( $body ) : (string) $body,
			'cookies'  => [],
		];
	}
}
