<?php
/**
 * Verifies Svix webhook signatures (Recurrente delivers webhooks via Svix).
 *
 * Svix signs `{id}.{timestamp}.{body}` with HMAC-SHA256 using the base64 part
 * of the `whsec_…` secret, and sends the result in the `svix-signature` header
 * as a space-separated list of `v1,<base64sig>` tokens.
 *
 * See https://docs.svix.com/receiving/verifying-payloads/how-manual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Svix_Verifier {

	const TOLERANCE_SECONDS = 300;

	private $secret_bytes;

	public function __construct( $signing_secret ) {
		$this->secret_bytes = $this->decode_secret( $signing_secret );
	}

	private function decode_secret( $signing_secret ) {
		$base64 = preg_replace( '/^whsec_/', '', (string) $signing_secret );
		return base64_decode( $base64 );
	}

	/**
	 * True if the signature verifies against ANY of the given secrets. Used to
	 * accept deliveries signed with either the test or the live endpoint secret
	 * (auto-registered per environment) or a manually configured one.
	 */
	public static function any_verifies( array $secrets, $payload, $id, $timestamp, $signature_header ) {
		foreach ( $secrets as $secret ) {
			$verifier = new self( $secret );
			if ( $verifier->verify( $payload, $id, $timestamp, $signature_header ) ) {
				return true;
			}
		}
		return false;
	}

	public function verify( $payload, $id, $timestamp, $signature_header ) {
		// No configured secret means nothing can be verified: an HMAC keyed with
		// the empty string is forgeable by anyone, so reject everything.
		if ( '' === (string) $this->secret_bytes ) {
			return false;
		}
		if ( ! $this->fresh( $timestamp ) ) {
			return false;
		}
		return $this->signature_matches( $payload, $id, $timestamp, $signature_header );
	}

	private function fresh( $timestamp ) {
		return abs( time() - (int) $timestamp ) <= self::TOLERANCE_SECONDS;
	}

	private function signature_matches( $payload, $id, $timestamp, $signature_header ) {
		$expected = $this->sign( "{$id}.{$timestamp}.{$payload}" );
		foreach ( $this->sent_signatures( $signature_header ) as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	private function sign( $signed_content ) {
		return base64_encode( hash_hmac( 'sha256', $signed_content, $this->secret_bytes, true ) );
	}

	/**
	 * The header is "v1,sigA v1,sigB"; we keep the part after each "v1,".
	 */
	private function sent_signatures( $signature_header ) {
		$tokens = explode( ' ', (string) $signature_header );
		return array_map( function ( $token ) {
			$parts = explode( ',', $token, 2 );
			return end( $parts );
		}, $tokens );
	}
}
