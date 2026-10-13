<?php
/**
 * RestTestCase class.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

/**
 * Base class for REST API integration tests.
 */
abstract class RestTestCase extends TestCase {
	use REST_Setup;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_up_rest();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		$this->tear_down_rest();

		parent::tear_down();
	}
}
