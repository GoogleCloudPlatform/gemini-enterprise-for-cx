<?php
/**
 * Redirect exception for integration tests.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

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

