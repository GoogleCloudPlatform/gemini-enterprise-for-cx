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
	 * Save data calls count.
	 */
	public int $save_data_calls = 0;

	/**
	 * Customer ID.
	 */
	public string $customer_id = 't_guest_session_123';

	/**
	 * Whether an active session exists.
	 */
	public bool $has_active_session = false;

	/**
	 * Constructor.
	 */
	public function __construct() {}

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
		return $this->customer_id;
	}

	/**
	 * Sets customer ID.
	 *
	 * @param mixed $id Customer ID.
	 */
	public function set_customer_id( $id ): void {
		$this->customer_id = (string) $id;
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
					if ( count( $parts) >= 4 ) {
						if ( 'forged' === $parts[3] ) {
							return false;
						}
						return [ $parts[0], (int) $parts[1], (int) $parts[2], $parts[3] ];
					}
				}
			}
		}
		return [ $this->customer_id, time() + 3600, time() + 1800, 'valid_hash' ];
	}
}

