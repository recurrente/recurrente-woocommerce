<?php
/** Actors for refund-concurrency.py; only run inside the dedicated fixture store. */
if ( ! defined( 'RECURRENTE_REFUND_TEST_STORE' ) || ! RECURRENTE_REFUND_TEST_STORE ) { throw new RuntimeException( 'Fixture store required.' ); }
wp_set_current_user( get_user_by( 'login', 'woo13' )->ID );
$actor = $args[0];
if ( 'setup' === $actor ) {
	$order = wc_create_order();
	$order->set_payment_method( 'recurrente' );
	$order->set_currency( 'GTQ' );
	$order->set_total( 100 );
	$order->update_meta_data( '_recurrente_checkout_id', 'ch_concurrency' . $order->get_id() );
	$order->update_meta_data( '_recurrente_payment_binding', array( 'mode' => 'live', 'amount' => 10000, 'currency' => 'GTQ' ) );
	$order->update_meta_data( '_recurrente_intent_id', 'pa_concurrency' . $order->get_id() );
	$order->save();
	$order->payment_complete();
	echo wp_json_encode( array( 'order_id' => $order->get_id() ) ) . "\n";
	return;
}
$order = wc_get_order( (int) $args[1] );
$resource = array( 'id' => 're_concurrency' . $order->get_id(), 'status' => 'succeeded', 'currency' => 'GTQ', 'customer_refunded_amount_in_cents' => 2500, 'refund_application_fee' => false );
if ( 'native' === $actor || 'overlap' === $actor ) {
	$original = get_option( 'woocommerce_recurrente_settings', array() );
	update_option( 'woocommerce_recurrente_settings', array( 'live_secret_key' => 'fixture_live' ) );
	$_SERVER['HTTP_X_RECURRENTE_REFUND_OPERATION'] = wp_generate_uuid4();
	$calls = 0;
	add_filter( 'pre_http_request', function ( $pre, $http, $url ) use ( &$calls, $resource, $actor ) {
		if ( Recurrente_API_Client::BASE_URL . '/refunds' !== $url ) { return new WP_Error( 'fixture_blocked', 'Unexpected network' ); }
		$calls++;
		if ( 'native' === $actor ) {
			echo "NATIVE_IN_HTTP\n";
			flush();
			if ( "continue\n" !== fgets( STDIN ) ) { throw new RuntimeException( 'Test coordinator ended before response.' ); }
		}
		$pending = array_merge( $resource, array( 'status' => 'pending' ) );
		return array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( $pending ) );
	}, 1, 3 );
	try {
		$result = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 25, 'refund_payment' => true ) );
		echo wp_json_encode( array( 'unconfirmed' => is_wp_error( $result ), 'http_calls' => $calls ) ) . "\n";
	} finally { update_option( 'woocommerce_recurrente_settings', $original ); }
	return;
}
$original = get_option( 'recurrente_webhook_secret_live', '' );
$secret = str_repeat( 'c', 32 );
update_option( 'recurrente_webhook_secret_live', 'whsec_' . base64_encode( $secret ) );
try {
	$event = array( 'event_type' => 'refund.create', 'refund' => $resource, 'intentable' => array( 'type' => 'PaymentIntent', 'id' => $order->get_meta( '_recurrente_intent_id' ),
		'checkout' => array( 'live_mode' => true, 'id' => $order->get_meta( '_recurrente_checkout_id' ), 'metadata' => array( 'wc_order_id' => (string) $order->get_id(), 'wc_order_key' => $order->get_order_key() ) ) ) );
	$request = new WP_REST_Request( 'POST', '/recurrente/v1/webhook' );
	$body = wp_json_encode( $event );
	$id = 'msg_concurrency' . $order->get_id();
	$timestamp = (string) time();
	$request->set_body( $body );
	$request->set_header( 'svix-id', $id );
	$request->set_header( 'svix-timestamp', $timestamp );
	$request->set_header( 'svix-signature', 'v1,' . base64_encode( hash_hmac( 'sha256', "$id.$timestamp.$body", $secret, true ) ) );
	$response = Recurrente_Webhook_Handler::handle( $request );
	$fresh = wc_get_order( $order->get_id() );
	echo wp_json_encode( array( 'status' => $response->get_status(), 'refunded' => (float) $fresh->get_total_refunded(), 'refunds' => count( $fresh->get_refunds() ) ) ) . "\n";
} finally { update_option( 'recurrente_webhook_secret_live', $original ); }
