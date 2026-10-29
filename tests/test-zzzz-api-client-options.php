<?php
/** Public client transport contracts shared by recovery and durable refunds. */
it( 'recovery timeouts coexist with refund idempotency and ordinary request defaults', function () {
	$previous = $GLOBALS['recurrente_binding_response_for_request'] ?? null;
	$requests = array();
	$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( &$requests ) {
		$requests[] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"id":"re_fixture"}' );
	};
	$payload = array( 'payment_intent_id' => 'pa_original', 'amount_in_cents' => 2500,
		'refund_application_fee' => false, 'require_new_refund' => true );
	$key = 'woo_refund_11111111-1111-4111-8111-111111111111';
	try {
		$client = new Recurrente_API_Client( 'fixture_live' );
		$client->get_checkout( 'ch_legacy' );
		$client->get_checkout( 'ch_recovery', 10 );
		$client->get_intent( 'in_recovery' );
		$refund = $client->create_refund( $payload, $key );
		$client->get_refund( 're_known' );
		$client->create_customer( array( 'email' => 'ana@ejemplo.com' ) );
		foreach ( $requests as $request ) {
			if ( 'fixture_live' !== $request['args']['headers']['X-SECRET-KEY']
				|| 'application/json' !== $request['args']['headers']['Content-Type']
				|| 'application/json' !== $request['args']['headers']['Accept'] ) { return false; }
		}
		return array( 30, 10, 10, 30, 30, 30 ) === array_column( array_column( $requests, 'args' ), 'timeout' )
			&& Recurrente_API_Client::BASE_URL . '/refunds' === $requests[3]['url']
			&& 'POST' === $requests[3]['args']['method']
			&& $key === $requests[3]['args']['headers']['Idempotency-Key']
			&& $payload === json_decode( $requests[3]['args']['body'], true )
			&& 're_fixture' === $refund['id']
			&& ! isset( $requests[4]['args']['headers']['Idempotency-Key'] );
	} finally {
		$GLOBALS['recurrente_binding_response_for_request'] = $previous;
	}
} );
