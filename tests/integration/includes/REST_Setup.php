<?php
/**
 * REST_Setup trait.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Spy_REST_Server;
use WP_Error;
use WP_REST_Response;

/**
 * Trait providing REST API test setup and assertions.
 */
trait REST_Setup {

	/**
	 * Set up REST server for tests.
	 */
	protected function set_up_rest(): void {
		/** @var \WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Tear down REST server after tests.
	 */
	protected function tear_down_rest(): void {
		/** @var \WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Asserts that the REST API response has the specified error.
	 *
	 * @param string|int                     $code     Expected error code.
	 * @param WP_REST_Response|WP_Error|bool $response REST API response.
	 * @param int|null                       $status   Optional. Status code.
	 */
	protected function assertErrorResponse( $code, $response, ?int $status = null ): void {
		if ( $response instanceof WP_REST_Response ) {
			$response = $response->as_error();
		}

		$this->assertWPError( $response );
		$this->assertSame( $code, $response->get_error_code() );

		if ( null !== $status ) {
			$data = $response->get_error_data();
			$this->assertIsArray( $data );
			$this->assertArrayHasKey( 'status', $data );
			$this->assertSame( $status, $data['status'] );
		}
	}
}
