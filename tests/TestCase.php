<?php
/**
 * Base integration test case for Gemini Enterprise for CX.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Exception thrown when a redirect is caught in tests.
 */
class RedirectException extends \Exception {

	/**
	 * Redirect location.
	 */
	protected string $location;

	/**
	 * Redirect HTTP status code.
	 */
	protected int $status;

	/**
	 * Constructor.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status code.
	 */
	public function __construct( string $location, int $status = 302 ) {
		parent::__construct( sprintf( 'Redirected to %s with status %d', $location, $status ) );
		$this->location = $location;
		$this->status   = $status;
	}

	/**
	 * Gets the redirect target URL.
	 */
	public function get_location(): string {
		return $this->location;
	}

	/**
	 * Gets the HTTP status code.
	 */
	public function get_status(): int {
		return $this->status;
	}
}

/**
 * Trait to generate valid Store API Cart-Tokens in tests.
 */
trait GECX_CartTokenMinting {

	/**
	 * Generates a signed Cart-Token for tests.
	 *
	 * @param string|int  $user_id    Customer ID or user ID.
	 * @param int         $exp_offset Expiration offset in seconds.
	 * @param string      $salt       Salt.
	 * @param bool        $valid_sig  Whether to produce a valid signature.
	 * @param string|null $iss        Issuer claim.
	 * @return string JWT token.
	 */
	protected function generate_jwt(
		$user_id,
		int $exp_offset = 3600,
		?string $salt = null,
		bool $valid_sig = true,
		?string $iss = 'store-api'
	): string {
		if ( null === $salt ) {
			$salt = wp_salt();
		}

		$claims = [
			'user_id' => $user_id,
			'exp'     => time() + $exp_offset,
		];
		if ( null !== $iss ) {
			$claims['iss'] = $iss;
		}

		$header  = (string) wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'HS256' ] );
		$payload = (string) wp_json_encode( $claims );

		$to_base_64_url = static function ( string $string ) {
			return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
		};

		$header_encoded  = $to_base_64_url( $header );
		$payload_encoded = $to_base_64_url( $payload );

		$secret            = '@' . $salt;
		$signature         = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
		$signature_encoded = $to_base_64_url( $signature );

		if ( ! $valid_sig ) {
			$signature_encoded .= 'invalid';
		}

		return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
	}

	/**
	 * Mints a cart token with custom payload.
	 *
	 * @param array       $payload Payload claims.
	 * @param string|null $salt    Optional salt.
	 * @return string JWT token.
	 */
	protected function mint_token_with_payload( array $payload, ?string $salt = null ): string {
		if ( null === $salt ) {
			$salt = wp_salt();
		}
		$header       = (string) wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'HS256' ] );
		$payload_json = (string) wp_json_encode( $payload );

		$to_base_64_url = static function ( string $string ) {
			return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
		};

		$header_encoded  = $to_base_64_url( $header );
		$payload_encoded = $to_base_64_url( $payload_json );

		$secret            = '@' . $salt;
		$signature         = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
		$signature_encoded = $to_base_64_url( $signature );

		return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
	}
}

/**
 * Base TestCase class extending Yoast WPTestUtils integration test case.
 */
abstract class GECX_TestCase extends TestCase {
	use GECX_CartTokenMinting;

	/**
	 * Last redirect caught during test.
	 */
	protected ?string $last_redirect = null;

	/**
	 * Last redirect status caught during test.
	 */
	protected ?int $last_redirect_status = null;

	/**
	 * Last AJAX JSON response caught during test.
	 */
	protected ?array $last_json_response = null;

	/**
	 * Last HTTP status header code caught during test.
	 */
	protected int $last_status_header_code = 200;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->last_redirect        = null;
		$this->last_redirect_status = null;
		$this->last_json_response   = null;

		$GLOBALS['gecx_test_last_redirect']      = &$this->last_redirect;
		$GLOBALS['gecx_test_last_json_response'] = &$this->last_json_response;

		$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'index.php';
		$_SERVER['SCRIPT_NAME']     = '/index.php';

		$_REQUEST = &$_POST;

		update_option( 'permalink_structure', '/%postname%/' );

		if ( class_exists( \Google\Gemini_Enterprise_For_CX\Auth::class ) ) {
			\Google\Gemini_Enterprise_For_CX\Auth::reset_cart_token_state();
		}

		$GLOBALS['gecx_test_http_requests']  = [];
		$GLOBALS['gecx_test_http_responses'] = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http_request_handler' ], 10, 3 );

		$this->last_status_header_code = 200;
		add_filter( 'status_header', [ $this, 'record_status_header' ], 10, 2 );

		add_filter( 'wp_redirect', [ $this, 'catch_redirect' ], 1, 2 );
		add_filter( 'wp_die_handler', [ $this, 'get_custom_wp_die_handler' ], 1 );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_custom_wp_die_handler' ], 1 );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'status_header', [ $this, 'record_status_header' ], 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_doing_ajax', '__return_false' );
		remove_filter( 'wp_redirect', [ $this, 'catch_redirect' ], 1 );
		remove_filter( 'wp_die_handler', [ $this, 'get_custom_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_custom_wp_die_handler' ], 1 );
		remove_filter( 'pre_http_request', [ $this, 'mock_http_request_handler' ], 10 );

		$GLOBALS['gecx_test_http_requests']  = [];
		$GLOBALS['gecx_test_http_responses'] = [];

		if ( class_exists( \Google\Gemini_Enterprise_For_CX\Auth::class ) ) {
			\Google\Gemini_Enterprise_For_CX\Auth::reset_cart_token_state();
		}

		// Clean up superglobals.
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		$_COOKIE  = [];
		unset( $_SERVER['HTTP_CART_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_FETCH_SITE'] );

		// Reset current user to 0.
		wp_set_current_user( 0 );

		parent::tear_down();
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
	 * Intercepts status_header calls to record the response status code.
	 *
	 * @param string $status_header Status header string.
	 * @param int    $code          Status code.
	 * @return string Original header string.
	 */
	public function record_status_header( $status_header, $code ) {
		$this->last_status_header_code = (int) $code;
		return $status_header;
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
	 * Returns custom die handler.
	 *
	 * @return callable
	 */
	public function get_custom_wp_die_handler() {
		return [ $this, 'custom_wp_die_handler' ];
	}

	/**
	 * Handles wp_die calls during tests to intercept AJAX JSON responses.
	 *
	 * @param string|\WP_Error $message The wp_die message or WP_Error.
	 * @param string           $title   The wp_die title.
	 * @param string|array     $args    The wp_die arguments.
	 */
	public function custom_wp_die_handler( $message, $title = '', $args = [] ) {
		$args_arr    = is_array( $args ) ? $args : [];
		$status_code = ! empty( $args_arr['response'] ) ? (int) $args_arr['response'] : 0;

		if ( $status_code <= 0 ) {
			$trace = debug_backtrace( 0, 10 );
			foreach ( $trace as $frame ) {
				$func = $frame['function'] ?? '';
				if ( 'wp_send_json' === $func && isset( $frame['args'][1] ) && null !== $frame['args'][1] ) {
					$status_code = (int) $frame['args'][1];
					break;
				}
				if ( ( 'wp_send_json_error' === $func || 'wp_send_json_success' === $func ) && isset( $frame['args'][1] ) && null !== $frame['args'][1] ) {
					$status_code = (int) $frame['args'][1];
					break;
				}
			}
		}

		if ( $status_code <= 0 ) {
			$status_code = $this->last_status_header_code ?: 200;
		}

		$buffered = ob_get_contents();
		ob_clean();
		if ( ! empty( $buffered ) ) {
			$json = json_decode( $buffered, true );
			if ( is_array( $json ) ) {
				$this->last_json_response = $json;
				if ( ! isset( $this->last_json_response['status'] ) && $status_code > 0 ) {
					$this->last_json_response['status'] = $status_code;
				}
			}
		}

		if ( null === $this->last_json_response && ! empty( $message ) ) {
			$this->last_json_response = [
				'success' => false,
				'data'    => is_wp_error( $message ) ? $message->get_error_message() : (string) $message,
				'status'  => $status_code,
			];
		}
	}

	/**
	 * Handler for pre_http_request to simulate HTTP requests.
	 *
	 * @param mixed  $preempt     Existing preempt response.
	 * @param array  $parsed_args Request arguments.
	 * @param string $url         Target URL.
	 * @return array Mock response.
	 */
	public function mock_http_request_handler( $preempt, array $parsed_args, string $url ): array {
		$GLOBALS['gecx_test_http_requests'][] = [
			'url'  => $url,
			'args' => $parsed_args,
		];

		if ( ! empty( $GLOBALS['gecx_test_http_responses'] ) ) {
			return array_shift( $GLOBALS['gecx_test_http_responses'] );
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
	 * Subscribes to HTTP requests made via WP HTTP.
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
