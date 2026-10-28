<?php
/** Run with `wp eval-file tests/integration/refund-operations.php` in an isolated fixture store. */
if ( ! defined( 'RECURRENTE_REFUND_TEST_STORE' ) || ! RECURRENTE_REFUND_TEST_STORE ) {
	throw new RuntimeException( 'Requires a dedicated disposable WordPress/WooCommerce store.' );
}
global $cases, $failures, $fixture_orders, $calls;

$original_settings = get_option( 'woocommerce_recurrente_settings', array() );
$original_live = get_option( 'recurrente_webhook_secret_live', '' );
$original_test = get_option( 'recurrente_webhook_secret_test', '' );
$original_user = get_current_user_id();
$admin = get_user_by( 'login', 'woo13' );
if ( ! $admin || ! user_can( $admin, 'manage_woocommerce' ) ) { throw new RuntimeException( 'Fixture admin woo13 is required.' ); }
wp_set_current_user( $admin->ID );

$calls = array();
$remote = null;
$fixture_orders = array();
$failures = 0;
$cases = 0;
$network = function ( $pre, $args, $url ) use ( &$calls, &$remote ) {
	if ( 0 !== strpos( $url, Recurrente_API_Client::BASE_URL . '/refunds' ) ) {
		return new WP_Error( 'fixture_network_blocked', 'Only mocked refund HTTP is allowed.' );
	}
	$calls[] = array( 'url' => $url, 'args' => $args );
	return $remote ? $remote( $url, $args ) : new WP_Error( 'fixture_missing_response', 'No fixture response.' );
};
add_filter( 'pre_http_request', $network, 1, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
update_option( 'woocommerce_recurrente_settings', array( 'enabled' => 'yes', 'test_mode' => 'yes', 'live_secret_key' => 'fixture_live', 'test_secret_key' => 'fixture_test' ) );
update_option( 'recurrente_webhook_secret_live', 'whsec_' . base64_encode( str_repeat( 'l', 32 ) ) );
update_option( 'recurrente_webhook_secret_test', 'whsec_' . base64_encode( str_repeat( 't', 32 ) ) );

function woo13_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function woo13_case( $name, $test ) {
	global $cases, $failures, $calls;
	$cases++;
	$calls = array();
	try { $test(); echo "PASS — $name\n"; }
	catch ( Throwable $e ) { $failures++; echo 'FAIL — ' . $name . ': ' . $e->getMessage() . "\n"; }
	finally { Recurrente_Refund_Operations::release_native(); }
}
function woo13_response( $body, $code = 200 ) {
	return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ) );
}
function woo13_resource( $id = 'reFixture', $status = 'succeeded', $amount = 2500 ) {
	return array( 'id' => 're_' . $id, 'status' => $status, 'currency' => 'GTQ',
		'customer_refunded_amount_in_cents' => $amount, 'account_refunded_amount_in_cents' => $amount - 100, 'refund_application_fee' => false );
}
function woo13_deliver( $event, $mode = 'live', $message_id = null ) {
	$request = new WP_REST_Request( 'POST', '/recurrente/v1/webhook' );
	$body = wp_json_encode( $event );
	$id = $message_id ?: 'msg_' . wp_generate_uuid4();
	$timestamp = (string) time();
	$key = base64_decode( substr( get_option( 'recurrente_webhook_secret_' . $mode ), 6 ) );
	$request->set_body( $body );
	$request->set_header( 'svix-id', $id );
	$request->set_header( 'svix-timestamp', $timestamp );
	$request->set_header( 'svix-signature', 'v1,' . base64_encode( hash_hmac( 'sha256', "$id.$timestamp.$body", $key, true ) ) );
	return Recurrente_Webhook_Handler::handle( $request );
}
function woo13_order( $mode = 'live' ) {
	global $fixture_orders;
	$order = wc_create_order();
	$fixture_orders[] = $order->get_id();
	$order->set_payment_method( 'recurrente' );
	$order->set_currency( 'GTQ' );
	$order->set_total( 100 );
	$order->update_meta_data( '_recurrente_checkout_id', 'ch_' . $order->get_id() );
	$order->update_meta_data( '_recurrente_payment_binding', array( 'mode' => $mode, 'amount' => 10000, 'currency' => 'GTQ', 'dynamic' => false ) );
	$order->save();
	$event = array( 'event_type' => 'intent.succeeded', 'id' => 'in_' . $order->get_id(), 'legacy_id' => 'pa_' . $order->get_id(),
		'amount_in_cents' => 10000, 'currency' => 'GTQ', 'live_mode' => true,
		'checkout' => array( 'id' => 'ch_' . $order->get_id(), 'live_mode' => 'live' === $mode,
			'currency' => 'GTQ', 'total_in_cents' => 10000, 'metadata' => array( 'wc_order_id' => (string) $order->get_id(), 'wc_order_key' => $order->get_order_key() ) ) );
	woo13_assert( 200 === woo13_deliver( $event, $mode )->get_status(), 'Signed original payment failed' );
	$order = wc_get_order( $order->get_id() );
	woo13_assert( $order->is_paid(), 'Original payment was not confirmed' );
	return $order;
}
function woo13_event( $order, $resource ) {
	return array( 'event_type' => 'refund.create', 'refund' => $resource, 'intentable' => array(
		'id' => Recurrente_Charge_Order_Link::charge_of( $order ), 'type' => 'PaymentIntent',
		'checkout' => array( 'live_mode' => 'live' === $order->get_meta( '_recurrente_payment_binding' )['mode'],
			'id' => $order->get_meta( '_recurrente_checkout_id' ), 'metadata' => array( 'wc_order_id' => (string) $order->get_id(), 'wc_order_key' => $order->get_order_key() ) ),
	) );
}
function woo13_native( $order, $amount = 25, $extra = array(), $token = null ) {
	$_SERVER['HTTP_X_RECURRENTE_REFUND_OPERATION'] = $token ?? wp_generate_uuid4();
	return wc_create_refund( array_merge( array( 'order_id' => $order->get_id(), 'amount' => $amount,
		'reason' => 'Solicitud de prueba', 'refund_payment' => true, 'restock_items' => false ), $extra ) );
}
class Woo13_Ajax_Done extends RuntimeException {}
function woo13_ajax( $order, $amount, $token ) {
	$before_post = $_POST;
	$before_request = $_REQUEST;
	$level = ob_get_level();
	$die = function () { return function () { throw new Woo13_Ajax_Done(); }; };
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST = array( 'order_id' => $order->get_id(), 'refund_amount' => (string) $amount,
		'refunded_amount' => '0', 'refund_reason' => 'Solicitud de prueba', 'api_refund' => 'true' );
	$_REQUEST['security'] = wp_create_nonce( 'order-item' );
	$_SERVER['HTTP_X_RECURRENTE_REFUND_OPERATION'] = $token;
	ob_start();
	try { WC_AJAX::refund_line_items(); }
	catch ( Woo13_Ajax_Done $e ) { /* Capture the real AJAX JSON without terminating the test process. */ }
	finally {
		$output = '';
		while ( ob_get_level() > $level ) { $output = ob_get_clean() . $output; }
		$_POST = $before_post;
		$_REQUEST = $before_request;
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	return json_decode( $output, true );
}
function woo13_operations( $order ) {
	$fresh = wc_get_order( $order->get_id() );
	$fresh->read_meta_data( true );
	return $fresh->get_meta( Recurrente_Refund_Operations::META );
}
function woo13_accounted( $order, $amount, $count ) {
	$fresh = wc_get_order( $order->get_id() );
	woo13_assert( (float) $amount === (float) $fresh->get_total_refunded(), 'Wrong accounting amount: ' . $fresh->get_total_refunded() );
	woo13_assert( $count === count( $fresh->get_refunds() ), 'Wrong Woo refund count' );
}

try {
	woo13_case( 'AJAX validation failure permits a corrected amount with the same browser UUID', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$token = wp_generate_uuid4();
		$invalid = woo13_ajax( $order, 150, $token );
		woo13_assert( false === $invalid['success'] && 0 === count( $calls ) && empty( woo13_operations( $order ) ), 'Invalid amount reached the journal or API' );
		$remote = function () { return woo13_response( woo13_resource( 'Corrected' ) ); };
		$corrected = woo13_ajax( $order, 25, $token );
		woo13_assert( true === $corrected['success'] && 1 === count( $calls ), 'Corrected form stayed blocked or sent twice' );
		woo13_assert( 'woo_refund_' . $token === $calls[0]['args']['headers']['Idempotency-Key'], 'Correction rotated the browser identity' );
		woo13_accounted( $order, 25, 1 );
	} );

	woo13_case( 'AJAX correction after a pending operation cannot replace its persisted parameters', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$token = wp_generate_uuid4();
		$remote = function () { return woo13_response( woo13_resource( 'Frozen', 'pending' ), 202 ); };
		woo13_assert( false === woo13_ajax( $order, 25, $token )['success'], 'Pending form falsely succeeded' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( false === woo13_ajax( $order, 30, $token )['success'], 'Changed amount replaced a pending refund' );
		$operations = woo13_operations( $order );
		woo13_assert( 1 === count( $calls ) && 1 === count( $operations ) && 2500 === current( $operations )['payload']['amount_in_cents'], 'Changed form mutated or resent the refund' );
		woo13_accounted( $order, 0, 0 );
	} );

	woo13_case( 'native partial uses confirmed original pa_ intent and LIVE key despite TEST toggle; echoes never double account', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$resource = woo13_resource();
		$remote = function ( $url, $args ) use ( $order, $resource ) {
			$operations = woo13_operations( $order );
			$key = array_key_first( $operations );
			woo13_assert( $args['headers']['Idempotency-Key'] === $key && 'fixture_live' === $args['headers']['X-SECRET-KEY'], 'Wrong persisted key/environment' );
			woo13_assert( json_decode( $args['body'], true ) === $operations[ $key ]['payload'], 'Payload was not persisted before HTTP' );
			woo13_assert( 'pa_' . $order->get_id() === $operations[ $key ]['intent'] && 2500 === $operations[ $key ]['amount'], 'Wrong original charge/amount' );
			return woo13_response( $resource );
		};
		$result = woo13_native( $order );
		woo13_assert( $result instanceof WC_Order_Refund && 're_reFixture' === $result->get_meta( Recurrente_Refund_Operations::REMOTE_META ), is_wp_error( $result ) ? $result->get_error_message() : 'Confirmed refund not bound to native record' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( 200 === woo13_deliver( woo13_event( $order, $resource ) )->get_status(), 'Echo rejected' );
		woo13_deliver( woo13_event( $order, $resource ) );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( 1 === count( $calls ) && 'completed' === current( woo13_operations( $order ) )['state'], 'Echo made HTTP or did not finish' );
	} );

	woo13_case( 'an independent pending partial cannot be adopted by a new native operation', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$external = woo13_resource( 'IndependentPending', 'pending' );
		$remote = function ( $url, $args ) use ( $external ) {
			$payload = json_decode( $args['body'], true );
			return ! empty( $payload['require_new_refund'] )
				? woo13_response( array( 'message' => 'An independent refund is pending.' ), 422 )
				: woo13_response( $external, 202 );
		};
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'An independent pending refund became native success' );
		Recurrente_Refund_Operations::release_native();
		$operation = current( woo13_operations( $order ) );
		woo13_assert( '' === $operation['refund_id'] && 'unknown' === $operation['state'], 'An independent refund identity was adopted' );
		woo13_assert( 1 === count( $calls ), 'Conflict caused another monetary request' );
		woo13_accounted( $order, 0, 0 );
		$external['status'] = 'succeeded';
		woo13_assert( 503 === woo13_deliver( woo13_event( $order, $external ) )->get_status(), 'Ambiguous external completion was acknowledged' );
		woo13_accounted( $order, 0, 0 );
	} );

	woo13_case( 'equal partials receive distinct durable operation identities', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$sequence = 0;
		$remote = function () use ( &$sequence ) { return woo13_response( woo13_resource( 'Partial' . ++$sequence ) ); };
		woo13_assert( ! is_wp_error( woo13_native( $order ) ), 'First partial failed' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( ! is_wp_error( woo13_native( wc_get_order( $order->get_id() ) ) ), 'Second partial failed' );
		woo13_accounted( $order, 50, 2 );
		woo13_assert( $calls[0]['args']['headers']['Idempotency-Key'] !== $calls[1]['args']['headers']['Idempotency-Key'], 'Distinct refunds shared a key' );
	} );

	woo13_case( 'full confirmed refund closes the order for the exact customer amount', function () use ( &$remote ) {
		$order = woo13_order();
		$resource = woo13_resource( 'Full', 'succeeded', 10000 );
		$remote = function () use ( $resource ) { return woo13_response( $resource ); };
		woo13_assert( ! is_wp_error( woo13_native( $order, 100 ) ), 'Full native refund failed' );
		Recurrente_Refund_Operations::release_native();
		woo13_accounted( $order, 100, 1 );
		woo13_assert( 'refunded' === wc_get_order( $order->get_id() )->get_status(), 'Full refund left wrong order status' );
		woo13_deliver( woo13_event( $order, $resource ) );
		woo13_accounted( $order, 100, 1 );
	} );

	woo13_case( 'replayed completed UUID cannot initiate a second equal partial or change its amount', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$token = wp_generate_uuid4();
		$remote = function () { return woo13_response( woo13_resource( 'Replay' ) ); };
		woo13_assert( ! is_wp_error( woo13_native( $order, 25, array(), $token ) ), 'Original request failed' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( is_wp_error( woo13_native( $order, 25, array(), $token ) ), 'Replay returned false success' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( is_wp_error( woo13_native( $order, 30, array(), $token ) ), 'Reused identity changed amount' );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( 1 === count( $calls ) && 1 === count( woo13_operations( $order ) ), 'Replay created another operation' );
	} );

	woo13_case( 'pending partial preserves selected lines and restocks only after confirmed GET', function () use ( &$remote ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Refund fixture product' );
		$product->set_regular_price( 100 );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
		$order = woo13_order();
		$item_id = $order->add_product( $product, 1, array( 'subtotal' => 100, 'total' => 100 ) );
		$item = $order->get_item( $item_id );
		$item->update_meta_data( '_reduced_stock', 1 );
		$item->save();
		$order->save();
		$resource = woo13_resource( 'Stock', 'pending' );
		$remote = function () use ( &$resource ) { return woo13_response( $resource ); };
		$args = array( 'restock_items' => true, 'line_items' => array( $item_id => array( 'qty' => 1, 'refund_total' => 25, 'refund_tax' => array() ) ) );
		woo13_assert( is_wp_error( woo13_native( $order, 25, $args ) ), 'Pending line refund succeeded' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( 10 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Pending refund restocked prematurely' );
		$resource['status'] = 'succeeded';
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( 11 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Confirmed refund did not restock selected line' );
		woo13_deliver( woo13_event( $order, $resource ) );
		woo13_assert( 11 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Echo restocked twice' );
		$product->delete( true );
	} );

	woo13_case( 'callback before response remains 503 until native core finishes, then accounts once', function () use ( &$remote ) {
		$order = woo13_order();
		$resource = woo13_resource( 'Race' );
		$event = woo13_event( $order, $resource );
		$message_id = 'msg_race_' . wp_generate_uuid4();
		$remote = function () use ( $event, $resource, $message_id ) {
			woo13_assert( 503 === woo13_deliver( $event, 'live', $message_id )->get_status(), 'Callback raced provisional accounting' );
			return woo13_response( $resource );
		};
		woo13_assert( ! is_wp_error( woo13_native( $order ) ), 'Native refund failed' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( 200 === woo13_deliver( $event, 'live', $message_id )->get_status(), 'Deferred callback did not retry' );
		woo13_accounted( $order, 25, 1 );
	} );

	woo13_case( 'pending creates no Woo refund, blocks another amount, and order action GET confirms once', function () use ( &$remote, &$calls ) {
		$order = woo13_order( 'test' );
		$resource = woo13_resource( 'Pending', 'pending' );
		$remote = function ( $url, $args ) use ( &$resource ) {
			woo13_assert( 'fixture_test' === $args['headers']['X-SECRET-KEY'], 'TEST order used LIVE key' );
			return woo13_response( $resource, 'POST' === $args['method'] ? 202 : 200 );
		};
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Pending falsely returned success' );
		Recurrente_Refund_Operations::release_native();
		woo13_accounted( $order, 0, 0 );
		woo13_assert( is_wp_error( woo13_native( $order, 30 ) ) && 1 === count( $calls ), 'Second pending operation moved money' );
		Recurrente_Refund_Operations::release_native();
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_accounted( $order, 0, 0 );
		$resource['status'] = 'succeeded';
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_accounted( $order, 25, 1 );
		woo13_deliver( woo13_event( $order, $resource ), 'test' );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( array( 'POST', 'GET', 'GET' ) === array_column( array_column( $calls, 'args' ), 'method' ), 'Pending recovery re-POSTed money' );
	} );

	woo13_case( 'lost response retains original body/key, defers uncorrelated echo, and recovers without a new operation', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$resource = woo13_resource( 'Lost' );
		$remote = function () { return new WP_Error( 'timeout', 'Fixture lost response' ); };
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Timeout returned success' );
		Recurrente_Refund_Operations::release_native();
		woo13_accounted( $order, 0, 0 );
		woo13_assert( 503 === woo13_deliver( woo13_event( $order, $resource ) )->get_status(), 'Unknown origin was acknowledged' );
		$remote = function () use ( $resource ) { return woo13_response( $resource ); };
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( $calls[0]['args']['body'] === $calls[1]['args']['body'] && $calls[0]['args']['headers']['Idempotency-Key'] === $calls[1]['args']['headers']['Idempotency-Key'], 'Recovery rotated identity' );
	} );

	foreach ( array( 'unknown', 'pending' ) as $state ) {
		woo13_case( '23h expiry: ' . $state . ' uses GET if known and quarantines an unknown outcome', function () use ( &$remote, &$calls, $state ) {
			$order = woo13_order();
			$resource = woo13_resource( 'Expired', 'pending' );
			$remote = function () use ( $state, $resource ) { return 'unknown' === $state ? new WP_Error( 'timeout', 'Fixture timeout' ) : woo13_response( $resource, 202 ); };
			woo13_native( $order );
			Recurrente_Refund_Operations::release_native();
			$operations = woo13_operations( $order );
			$key = array_key_first( $operations );
			$operations[ $key ]['started_at'] = time() - 25 * HOUR_IN_SECONDS;
			$fresh = wc_get_order( $order->get_id() );
			$fresh->update_meta_data( Recurrente_Refund_Operations::META, $operations );
			$fresh->save();
			$resource['status'] = 'succeeded';
			$remote = function () use ( $resource ) { return woo13_response( $resource ); };
			do_action( 'woocommerce_order_action_recurrente_check_refund', $fresh );
			if ( 'unknown' === $state ) {
				woo13_accounted( $order, 0, 0 );
				woo13_assert( is_wp_error( woo13_native( $order ) ) && 1 === count( $calls ), 'Expired unknown request could move money' );
			} else {
				woo13_accounted( $order, 25, 1 );
				woo13_assert( 2 === count( $calls ) && 'GET' === $calls[1]['args']['method'], 'Expired known request re-POSTed' );
			}
		} );
	}

	foreach ( array( 'pending', 'failed', 'succeeded' ) as $status ) {
		woo13_case( '422 linked resource is queried and classified as ' . $status, function () use ( &$remote, &$calls, $status ) {
			$order = woo13_order();
			$resource = woo13_resource( 'Linked', $status );
			$remote = function ( $url, $args ) use ( $resource ) {
				return 'POST' === $args['method'] ? woo13_response( array( 'refund_id' => $resource['id'], 'message' => 'provider_outcome_unknown' ), 422 ) : woo13_response( $resource );
			};
			$result = woo13_native( $order );
			woo13_assert( ( 'succeeded' === $status ) === ( $result instanceof WC_Order_Refund ), 'Wrong linked-resource success result' );
			woo13_accounted( $order, 'succeeded' === $status ? 25 : 0, 'succeeded' === $status ? 1 : 0 );
			woo13_assert( 2 === count( $calls ) && 'GET' === $calls[1]['args']['method'], 'Linked error was not queried' );
		} );
	}

	$invalid = array( 'wrong amount' => array( 'customer_refunded_amount_in_cents' => 2499 ), 'wrong currency' => array( 'currency' => 'USD' ),
		'non-integer amount' => array( 'customer_refunded_amount_in_cents' => '2500' ), 'unknown status' => array( 'status' => 'processing' ),
		'missing id' => array( 'id' => '' ), 'unexpected fee' => array( 'refund_application_fee' => true ) );
	foreach ( $invalid as $name => $changes ) {
		woo13_case( 'unconfirmed API resource: ' . $name, function () use ( &$remote, $changes ) {
			$order = woo13_order();
			$resource = array_merge( woo13_resource(), $changes );
			$remote = function () use ( $resource ) { return woo13_response( $resource ); };
			woo13_assert( is_wp_error( woo13_native( $order ) ), 'Malformed response returned success' );
			woo13_accounted( $order, 0, 0 );
		} );
	}

	woo13_case( 'duplicate remote identity across operations cannot account twice', function () use ( &$remote ) {
		$order = woo13_order();
		$remote = function () { return woo13_response( woo13_resource( 'Reused' ) ); };
		woo13_native( $order );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Duplicate remote identity returned success' );
		woo13_accounted( $order, 25, 1 );
	} );

	woo13_case( 'authorization and missing original identity fail before API calls', function () use ( &$calls ) {
		$order = woo13_order();
		wp_set_current_user( 0 );
		try {
			woo13_assert( is_wp_error( woo13_native( $order ) ), 'Anonymous request could refund' );
			do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		} finally { wp_set_current_user( get_user_by( 'login', 'woo13' )->ID ); }
		$order->delete_meta_data( '_recurrente_payment_binding' );
		$order->save();
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Missing original environment was inferred' );
		woo13_assert( empty( $calls ) && ! woo13_operations( $order ), 'Unauthorized/unbound request persisted money operation' );
	} );

	woo13_case( 'direct gateway invocation has no provisional refund and cannot move money', function () use ( &$calls ) {
		$order = woo13_order();
		$gateway = WC()->payment_gateways()->payment_gateways()['recurrente'];
		woo13_assert( $gateway->supports( 'refunds' ) && is_wp_error( $gateway->process_refund( $order->get_id(), 25, 'Bypass' ) ) && empty( $calls ), 'Direct invocation bypassed native context' );
	} );

	woo13_case( 'missing request UUID and excess decimal precision cannot create money operations', function () use ( &$calls ) {
		$order = woo13_order();
		woo13_assert( is_wp_error( woo13_native( $order, 25, array(), '' ) ), 'Missing request identity accepted' );
		Recurrente_Refund_Operations::release_native();
		woo13_assert( is_wp_error( woo13_native( $order, 1.005 ) ), 'More than two decimals silently rounded' );
		woo13_assert( empty( $calls ) && ! woo13_operations( $order ), 'Invalid input reached API' );
	} );

	woo13_case( 'a provider rejection without an ID remains unconfirmed and cannot rotate its key', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$remote = function () { return woo13_response( array( 'message' => 'Partial refunds unsupported for fixture provider' ), 422 ); };
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Provider rejection returned success' );
		Recurrente_Refund_Operations::release_native();
		woo13_accounted( $order, 0, 0 );
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_assert( 2 === count( $calls ) && $calls[0]['args']['headers']['Idempotency-Key'] === $calls[1]['args']['headers']['Idempotency-Key'], 'Rejection recovery rotated its key' );
	} );

	woo13_case( 'a crash after mirror save is recovered by refund metadata without duplicate accounting', function () use ( &$remote ) {
		$order = woo13_order();
		$resource = woo13_resource( 'MirrorCrash', 'pending' );
		$remote = function () use ( &$resource ) { return woo13_response( $resource ); };
		woo13_native( $order );
		Recurrente_Refund_Operations::release_native();
		$resource['status'] = 'succeeded';
		$crash = function ( $refund_id, $args ) {
			if ( empty( $args['refund_payment'] ) ) { throw new Error( 'Fixture process death after mirror save' ); }
		};
		add_action( 'woocommerce_refund_created', $crash, 100, 2 );
		try { do_action( 'woocommerce_order_action_recurrente_check_refund', $order ); }
		catch ( Error $e ) { woo13_assert( false !== strpos( $e->getMessage(), 'Fixture process death' ), 'Unexpected crash' ); }
		finally { remove_action( 'woocommerce_refund_created', $crash, 100 ); }
		woo13_accounted( $order, 25, 1 );
		woo13_assert( 200 === woo13_deliver( woo13_event( $order, $resource ) )->get_status(), 'Crash recovery callback failed' );
		woo13_accounted( $order, 25, 1 );
	} );

	woo13_case( 'a crash after applied-ID persistence recovers the same operation and accounting', function () use ( &$remote ) {
		$order = woo13_order();
		$resource = woo13_resource( 'AppliedCrash', 'pending' );
		$remote = function () use ( &$resource ) { return woo13_response( $resource ); };
		woo13_native( $order );
		Recurrente_Refund_Operations::release_native();
		$resource['status'] = 'succeeded';
		$crash = function ( $saved ) use ( $order, $resource ) {
			$ids = $saved->get_meta( Recurrente_Refund_Sync::APPLIED_META );
			$operations = $saved->get_meta( Recurrente_Refund_Operations::META );
			if ( $saved->get_id() === $order->get_id() && is_array( $ids ) && in_array( $resource['id'], $ids, true )
				&& 'completed' !== current( $operations )['state'] ) {
				throw new Error( 'Fixture process death after applied-ID persistence' );
			}
		};
		add_action( 'woocommerce_after_order_object_save', $crash );
		try { do_action( 'woocommerce_order_action_recurrente_check_refund', $order ); }
		catch ( Error $e ) { woo13_assert( false !== strpos( $e->getMessage(), 'Fixture process death' ), 'Unexpected crash' ); }
		finally { remove_action( 'woocommerce_after_order_object_save', $crash ); }
		woo13_assert( 200 === woo13_deliver( woo13_event( $order, $resource ) )->get_status(), 'Applied-ID recovery could not finish its own operation' );
		woo13_accounted( $order, 25, 1 );
		woo13_assert( 'completed' === current( woo13_operations( $order ) )['state'], 'Recovered operation remained active' );
	} );

	woo13_case( 'an independently applied refund cannot be consumed by an outbound operation', function () use ( &$remote ) {
		$order = woo13_order();
		$resource = woo13_resource( 'AlreadyApplied' );
		woo13_deliver( woo13_event( $order, $resource ) );
		$remote = function () use ( $resource ) { return woo13_response( $resource ); };
		woo13_assert( is_wp_error( woo13_native( $order ) ), 'Independent refund was reused as native success' );
		woo13_accounted( $order, 25, 1 );
	} );

	woo13_case( 'known pending identity rejects an unrelated GET resource without POST', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$resource = woo13_resource( 'Original', 'pending' );
		$remote = function () use ( &$resource ) { return woo13_response( $resource ); };
		woo13_native( $order );
		Recurrente_Refund_Operations::release_native();
		$resource = woo13_resource( 'Other' );
		do_action( 'woocommerce_order_action_recurrente_check_refund', $order );
		woo13_accounted( $order, 0, 0 );
		woo13_assert( 're_Original' === current( woo13_operations( $order ) )['refund_id'] && 'GET' === $calls[1]['args']['method'], 'GET replaced immutable remote identity' );
	} );

	woo13_case( 'independent inbound partial after a completed outbound refund is preserved', function () use ( &$remote, &$calls ) {
		$order = woo13_order();
		$remote = function () { return woo13_response( woo13_resource( 'Outbound' ) ); };
		woo13_native( $order );
		Recurrente_Refund_Operations::release_native();
		$event = woo13_event( $order, woo13_resource( 'Independent', 'succeeded', 1000 ) );
		woo13_assert( 200 === woo13_deliver( $event )->get_status(), 'Independent partial rejected' );
		woo13_deliver( $event );
		woo13_accounted( $order, 35, 2 );
		woo13_assert( 1 === count( $calls ), 'Inbound accounting echoed a money request' );
	} );
} finally {
	Recurrente_Refund_Operations::release_native();
	update_option( 'woocommerce_recurrente_settings', $original_settings );
	update_option( 'recurrente_webhook_secret_live', $original_live );
	update_option( 'recurrente_webhook_secret_test', $original_test );
	wp_set_current_user( $original_user );
	remove_filter( 'pre_http_request', $network, 1 );
}
echo "$cases integration tests, $failures failures; storage=" . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'CPT' ) . "\n";
if ( $failures ) { throw new RuntimeException( 'Refund integration regressions failed.' ); }
