<?php
/**
 * Recurrente_Svix_Verifier tests.
 *
 * The signing scheme is pinned to Svix's official test vector from
 * https://docs.svix.com/receiving/verifying-payloads/how-manual — if the
 * HMAC construction ever drifts from `{id}.{timestamp}.{payload}` signed
 * with the base64-decoded whsec_ secret, the first case fails.
 */

require_once dirname( __DIR__ ) . '/includes/class-recurrente-svix-verifier.php';

// Official Svix test vector.
$secret   = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
$id       = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
$vector_ts = '1614265330';
$payload  = '{"test": 2432232314}';
$expected = 'g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

$key      = base64_decode( substr( $secret, strlen( 'whsec_' ) ) );
$sign     = function ( $ts ) use ( $id, $payload, $key ) {
	return base64_encode( hash_hmac( 'sha256', "{$id}.{$ts}.{$payload}", $key, true ) );
};
$now      = (string) time();
$verifier = new Recurrente_Svix_Verifier( $secret );

it( 'matches the official Svix test vector byte-for-byte', function () use ( $sign, $vector_ts, $expected ) {
	return $sign( $vector_ts ) === $expected;
} );

it( 'accepts a valid signature with a fresh timestamp', function () use ( $verifier, $payload, $id, $now, $sign ) {
	return true === $verifier->verify( $payload, $id, $now, 'v1,' . $sign( $now ) );
} );

it( 'accepts when a valid signature is one of several tokens in the header', function () use ( $verifier, $payload, $id, $now, $sign ) {
	return true === $verifier->verify( $payload, $id, $now, 'v1,bm90LXRoaXMtb25l v1,' . $sign( $now ) );
} );

it( 'rejects a tampered signature', function () use ( $verifier, $payload, $id, $now, $sign ) {
	return false === $verifier->verify( $payload, $id, $now, 'v1,AAAA' . substr( $sign( $now ), 4 ) );
} );

it( 'rejects a tampered payload', function () use ( $verifier, $id, $now, $sign ) {
	return false === $verifier->verify( '{"test": 999}', $id, $now, 'v1,' . $sign( $now ) );
} );

it( 'rejects a timestamp outside the tolerance window', function () use ( $verifier, $payload, $id, $vector_ts, $sign ) {
	return false === $verifier->verify( $payload, $id, $vector_ts, 'v1,' . $sign( $vector_ts ) );
} );

it( 'rejects a missing signature header', function () use ( $verifier, $payload, $id, $now ) {
	return false === $verifier->verify( $payload, $id, $now, null );
} );

// Empty/misconfigured secrets: an HMAC keyed with the empty string would be
// forgeable by anyone, so the verifier must reject everything.
$empty_key_sig = base64_encode( hash_hmac( 'sha256', "{$id}.{$now}.{$payload}", '', true ) );

it( 'rejects everything when the secret is empty', function () use ( $payload, $id, $now, $empty_key_sig ) {
	$verifier = new Recurrente_Svix_Verifier( '' );
	return false === $verifier->verify( $payload, $id, $now, "v1,{$empty_key_sig}" );
} );

it( 'rejects everything when the secret is only the whsec_ prefix', function () use ( $payload, $id, $now, $empty_key_sig ) {
	$verifier = new Recurrente_Svix_Verifier( 'whsec_' );
	return false === $verifier->verify( $payload, $id, $now, "v1,{$empty_key_sig}" );
} );

it( 'rejects a forged signature when the secret is malformed base64', function () use ( $payload, $id, $now, $empty_key_sig ) {
	$verifier = new Recurrente_Svix_Verifier( 'whsec_!!!not-base64!!!' );
	return false === $verifier->verify( $payload, $id, $now, "v1,{$empty_key_sig}" );
} );

// any_verifies: multi-secret verification (auto-registered test/live secrets
// plus optional manual one).
$other = 'whsec_' . base64_encode( 'a-completely-different-key!!' );

it( 'any_verifies accepts when one of several secrets matches', function () use ( $secret, $other, $payload, $id, $now, $sign ) {
	return true === Recurrente_Svix_Verifier::any_verifies( array( $other, $secret ), $payload, $id, $now, 'v1,' . $sign( $now ) );
} );

it( 'any_verifies rejects when no secret matches', function () use ( $other, $payload, $id, $now, $sign ) {
	return false === Recurrente_Svix_Verifier::any_verifies( array( $other, '' ), $payload, $id, $now, 'v1,' . $sign( $now ) );
} );

it( 'any_verifies rejects with an empty secret list', function () use ( $payload, $id, $now, $sign ) {
	return false === Recurrente_Svix_Verifier::any_verifies( array(), $payload, $id, $now, 'v1,' . $sign( $now ) );
} );
