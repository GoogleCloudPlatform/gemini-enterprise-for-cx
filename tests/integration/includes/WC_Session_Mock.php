<?php
/**
 * WooCommerce Session Mock for integration tests.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

/**
 * Mock WooCommerce session handler.
 */
class WC_Session_Mock extends \WC_Session_Handler {

	/**
	 * Whether customer session cookie was set.
	 */
	public bool $cookie_set = false;

	/**
	 * Cookie set calls count.
	 */
	public int $cookie_set_calls = 0;

	/**
	 * Session expiration calls count.
	 */
	public int $session_expiration_calls = 0;

	/**
	 * Session timestamp updates recorded.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	public array $session_timestamp_updates = [];

	/**
	 * Save data calls count.
	 */
	public int $save_data_calls = 0;

	/**
	 * Whether an active session exists.
	 */
	public bool $has_active_session = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->_customer_id = 't_guest_session_123';
	}

	/**
	 * Magic getter to support direct `$session->customer_id` access in tests.
	 *
	 * @param mixed $key Key to get.
	 * @return mixed
	 */
	public function __get( $key ) {
		if ( 'customer_id' === $key ) {
			return (string) $this->_customer_id;
		}
		return parent::__get( $key );
	}

	/**
	 * Magic setter to support direct `$session->customer_id` assignment in tests.
	 *
	 * @param mixed $key   Key to set.
	 * @param mixed $value Value to set.
	 */
	public function __set( $key, $value ) {
		if ( 'customer_id' === $key ) {
			$this->_customer_id = (string) $value;
			return;
		}
		parent::__set( $key, $value );
	}

	/**
	 * Init mock.
	 */
	public function init(): void {}

	/**
	 * Records customer session cookie call.
	 *
	 * @param mixed $val Value.
	 */
	public function set_customer_session_cookie( $val ): void {
		$this->cookie_set = (bool) $val;
		$this->cookie_set_calls++;
	}

	/**
	 * Sets session expiration and records call.
	 */
	public function set_session_expiration(): void {
		$this->session_expiration_calls++;
		$this->_session_expiring   = time() + DAY_IN_SECONDS;
		$this->_session_expiration = time() + 2 * DAY_IN_SECONDS;
	}

	/**
	 * Records session timestamp update.
	 *
	 * @param string $customer_id Customer ID.
	 * @param int    $timestamp   Timestamp.
	 */
	public function update_session_timestamp( $customer_id, $timestamp ): void {
		$this->session_timestamp_updates[] = [ (string) $customer_id, (int) $timestamp ];
	}

	/**
	 * Exposes session data for tests.
	 */
	public function get_session_data_for_test(): array {
		return is_array( $this->_data ) ? $this->_data : [];
	}

	/**
	 * Exposes session expiration for tests.
	 */
	public function get_session_expiration_for_test(): int {
		return (int) ( $this->_session_expiration ?? 0 );
	}

	/**
	 * Records save data call.
	 *
	 * @param string $old_session_key Old session key.
	 */
	public function save_data( $old_session_key = '' ): void {
		$this->save_data_calls++;
	}

	/**
	 * Gets customer ID.
	 */
	public function get_customer_id(): string {
		return (string) $this->_customer_id;
	}

	/**
	 * Sets customer ID.
	 *
	 * @param mixed $id Customer ID.
	 */
	public function set_customer_id( $id ): void {
		$this->_customer_id = (string) $id;
	}

	/**
	 * Whether session exists.
	 */
	public function has_session(): bool {
		return $this->has_active_session;
	}

	/**
	 * Gets session cookie.
	 *
	 * @return array|false
	 */
	public function get_session_cookie() {
		if ( ! empty( $_COOKIE ) ) {
			foreach ( $_COOKIE as $k => $v ) {
				if ( 0 === strpos( (string) $k, 'wp_woocommerce_session_' ) ) {
					$parts = explode( '||', (string) $v );
					if ( count( $parts ) >= 4 ) {
						if ( 'forged' === $parts[3] ) {
							return false;
						}
						return [ $parts[0], (int) $parts[1], (int) $parts[2], $parts[3] ];
					}
				}
			}
		}
		return [ (string) $this->_customer_id, time() + 3600, time() + 1800, 'valid_hash' ];
	}
}

