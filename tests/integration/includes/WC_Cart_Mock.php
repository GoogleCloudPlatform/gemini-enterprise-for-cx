<?php
/**
 * WooCommerce Cart Mock for integration tests.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

/**
 * Mock WooCommerce cart class.
 */
class WC_Cart_Mock extends \WC_Cart {

	/**
	 * Session cart items.
	 *
	 * @var array
	 */
	public array $session_cart = [];

	/**
	 * Cart for session.
	 *
	 * @var array
	 */
	public array $cart_for_session = [];

	/**
	 * Number of persistent cart updates called.
	 */
	public int $persistent_cart_updates = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Records persistent cart update call.
	 */
	public function persistent_cart_update(): void {
		$this->persistent_cart_updates++;
	}

	/**
	 * Gets cart for session.
	 *
	 * @return array
	 */
	public function get_cart_for_session(): array {
		return ! empty( $this->cart_for_session ) ? $this->cart_for_session : $this->session_cart;
	}

	/**
	 * Checks if cart is empty.
	 */
	public function is_empty(): bool {
		return empty( $this->get_cart_for_session() );
	}

	/**
	 * Gets cart hash.
	 */
	public function get_cart_hash(): string {
		$cart = $this->get_cart_for_session();
		return $cart ? md5( (string) wp_json_encode( $cart ) ) : '';
	}
}

