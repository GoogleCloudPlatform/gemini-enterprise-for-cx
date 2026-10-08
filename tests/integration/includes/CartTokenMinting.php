<?php
/**
 * CartTokenMinting trait for integration tests.
 *
 * @package Google\Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

/**
 * Trait to generate valid Store API Cart-Tokens in tests.
 */
trait CartTokenMinting {

	/**
	 * Generates a signed Cart-Token for tests.
	 *
	 * @param string|int  $user_id    Customer ID or user ID.
	 * @param int         $exp_offset Expiration offset in seconds.
	 * @param string|null $salt       Salt.
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

