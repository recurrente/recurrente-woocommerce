<?php

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type ) { $GLOBALS['recurrente_binding_notices'][] = $message; }
}
/** Signed webhook regressions; real gateway, API client, verifier, handler and charge/renewal writers. */
require_once dirname( __DIR__ ) . '/includes/class-recurrente-api-client.php';

function recurrente_binding( $order, $changes = array() ) {
	$binding = array_merge( array( 'mode' => 'live', 'amount' => 10000, 'currency' => 'GTQ', 'dynamic' => false, 'attempt' => 'attempt-current', 'base_amount' => 10000 ), $changes );
	$order->update_meta_data( '_recurrente_payment_binding', $binding );
	$order->save();
	return $binding;
}

function recurrente_bound_event( $order, $charge = 'pa_binding' ) {
	$event = recurrente_test_event( 'intent.succeeded', $order, $charge );
	$event['checkout']['metadata']['wc_payment_attempt'] = 'attempt-current';
	if ( isset( $event['payment'] ) ) {
		$event['payment']['paymentable']['metadata'] = $event['checkout']['metadata'];
	}
	return $event;
}

$invalid_binding_changes = array(
	'old checkout' => function ( &$e ) { $e['checkout']['id'] = 'ch_previous'; },
	'missing checkout' => function ( &$e ) { unset( $e['checkout']['id'] ); },
	'old attempt' => function ( &$e ) { $e['checkout']['metadata']['wc_payment_attempt'] = 'attempt-old'; },
	'missing attempt' => function ( &$e ) { unset( $e['checkout']['metadata']['wc_payment_attempt'] ); },
	'underpayment' => function ( &$e ) { $e['amount_in_cents'] = 9999; $e['checkout']['total_in_cents'] = 9999; },
	'overpayment without dynamic pricing' => function ( &$e ) { $e['amount_in_cents'] = 10500; $e['checkout']['total_in_cents'] = 10500; },
	'wrong currency' => function ( &$e ) { $e['currency'] = 'USD'; $e['checkout']['currency'] = 'USD'; },
	'checkout currency mismatch' => function ( &$e ) { $e['checkout']['currency'] = 'USD'; },
	'checkout amount mismatch' => function ( &$e ) { $e['checkout']['total_in_cents'] = 9000; },
	'string amount' => function ( &$e ) { $e['amount_in_cents'] = '10000'; },
	'wrong payload mode' => function ( &$e ) { $e['checkout']['live_mode'] = false; },
	'malformed checkout' => function ( &$e ) { $e['checkout'] = 'invalid'; },
);
foreach ( $invalid_binding_changes as $description => $change ) {
	it( "binding rejects $description without linking or completing the order", function () use ( $change ) {
		$order = recurrente_test_order( 501 );
		recurrente_binding( $order );
		$event = recurrente_bound_event( $order );
		$change( $event );
		$response = recurrente_test_deliver( $event );
		return 200 === $response->status && ! $order->paid && '' === Recurrente_Charge_Order_Link::charge_of( $order );
	} );
}

it( 'legacy installation accepts a matching checkout without new attempt metadata and pins signing mode', function () {
	$order = recurrente_test_order( 502 );
	recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_legacy_binding' ) );
	return $order->paid && array( 'mode' => 'live' ) === $order->get_meta( '_recurrente_payment_binding' );
} );

it( 'legacy installation rejects an old checkout even with valid order id and key', function () {
	$order = recurrente_test_order( 502 );
	$event = recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_old_legacy' );
	$event['checkout']['id'] = 'ch_old';
	recurrente_test_deliver( $event );
	return ! $order->paid;
} );

it( 'test-signed delivery cannot complete a live order even when its payload claims live mode', function () {
	global $recurrente_test_secret;
	$order = recurrente_test_order( 503 );
	recurrente_binding( $order );
	$live_secret = $recurrente_test_secret;
	$recurrente_test_secret = 'whsec_' . base64_encode( 'independent-test-key' );
	update_option( 'recurrente_webhook_secret_test', $recurrente_test_secret );
	try {
		recurrente_test_deliver( recurrente_bound_event( $order ) );
	} finally {
		$recurrente_test_secret = $live_secret;
	}
	return ! $order->paid && '' === Recurrente_Charge_Order_Link::charge_of( $order );
} );

it( 'legacy TEST-key checkout on a live account uses checkout mode rather than root Sandbox mode', function () {
	global $recurrente_test_secret;
	$order = recurrente_test_order( 504 );
	recurrente_binding( $order, array( 'mode' => 'test' ) );
	$live_secret = $recurrente_test_secret;
	$recurrente_test_secret = get_option( 'recurrente_webhook_secret_test' );
	$event = recurrente_bound_event( $order );
	$event['live_mode'] = true;
	$event['checkout']['live_mode'] = false;
	try {
		recurrente_test_deliver( $event );
	} finally {
		$recurrente_test_secret = $live_secret;
	}
	return $order->paid;
} );

it( 'a secret configured for both environments is rejected as ambiguous', function () {
	$order = recurrente_test_order( 505 );
	$before = get_option( 'recurrente_webhook_secret_test' );
	update_option( 'recurrente_webhook_secret_test', get_option( 'recurrente_webhook_secret_live' ) );
	$response = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_ambiguous' ) );
	update_option( 'recurrente_webhook_secret_test', $before );
	return 400 === $response->status && ! $order->paid;
} );

it( 'dynamic pricing accepts the buyer surcharge against the base captured at checkout creation', function () {
	$order = recurrente_test_order( 506 );
	recurrente_binding( $order, array( 'dynamic' => true ) );
	$event = recurrente_bound_event( $order );
	$event['amount_in_cents'] = 10490;
	$event['checkout']['total_in_cents'] = 10490;
	recurrente_test_deliver( $event );
	return $order->paid && '100.00' === $order->get_total();
} );

it( 'dynamic surcharge cannot hide an underpriced merchant base', function () {
	$order = recurrente_test_order( 507 );
	recurrente_binding( $order, array( 'dynamic' => true, 'base_amount' => 9500 ) );
	$event = recurrente_bound_event( $order );
	$event['amount_in_cents'] = 10000;
	recurrente_test_deliver( $event );
	return ! $order->paid;
} );

it( 'a paid parent accepts a new reminder checkout for its bound subscription and dedupes both formats', function () {
	$order = recurrente_test_subscription_order( 508 );
	recurrente_binding( $order );
	recurrente_test_deliver( recurrente_bound_event( $order, 'pa_initial_bound' ) );
	foreach ( array( 'intent.succeeded', 'payment_intent.succeeded' ) as $type ) {
		$event = recurrente_test_reminder_event( $type, $order, 'pa_new_reminder' );
		$event['payment']['paymentable']['metadata']['wc_payment_attempt'] = 'attempt-current';
		recurrente_test_deliver( $event );
	}
	return 1 === count( recurrente_renewal_orders() ) && 1 === count( $order->completions );
} );

foreach ( array( 'old attempt', 'other subscription', 'underpayment', 'wrong currency' ) as $invalid ) {
	it( "renewal rejects $invalid on a different checkout", function () use ( $invalid ) {
		$order = recurrente_test_subscription_order( 509 );
		recurrente_binding( $order );
		recurrente_test_deliver( recurrente_bound_event( $order, 'pa_initial_bound' ) );
		$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_invalid_renewal' );
		$event['payment']['paymentable']['metadata']['wc_payment_attempt'] = 'attempt-current';
		if ( 'old attempt' === $invalid ) {
			$event['payment']['paymentable']['metadata']['wc_payment_attempt'] = 'attempt-old';
		} elseif ( 'other subscription' === $invalid ) {
			$event['payment']['paymentable']['id'] = 'su_other';
			$event['subscription']['id'] = 'su_other';
		} elseif ( 'underpayment' === $invalid ) {
			$event['amount_in_cents'] = 9999;
		} else {
			$event['currency'] = 'USD';
		}
		recurrente_test_deliver( $event );
		return array() === recurrente_renewal_orders();
	} );
}

it( 'reminder cannot fulfill an unpaid parent with a different checkout', function () {
	$order = recurrente_test_subscription_order( 510 );
	recurrente_test_deliver( recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_reminder_unpaid' ) );
	return ! $order->paid && array() === recurrente_renewal_orders();
} );

it( 'bound lifecycle rejects an old attempt without breaking the current subscription', function () {
	$order = recurrente_test_subscription_order( 511 );
	recurrente_binding( $order );
	$subscription = new Stub_Lifecycle_Subscription( 'active' );
	$order->subscriptions = array( $subscription );
	$event = recurrente_test_lifecycle_event( 'subscription.cancel', $order, 'metadata' );
	$event['metadata']['wc_payment_attempt'] = 'attempt-old';
	recurrente_test_deliver( $event );
	$event['metadata']['wc_payment_attempt'] = 'attempt-current';
	recurrente_test_deliver( $event );
	return array( 'cancelled' ) === $subscription->updates;
} );

/** Woo and WordPress boundaries only; the gateway's internal collaborators remain real. */
class Stub_Binding_Gateway extends Recurrente_Gateway {
	public $settings = array( 'test_mode' => 'no', 'live_secret_key' => 'sk_live_fixture', 'dynamic_pricing' => 'yes' );
	public function get_option( $name, $default = '' ) {
		return $this->settings[ $name ] ?? $default;
	}
}

class Stub_Binding_Payable_Order extends WC_Order {
	public $record;
	public $received_url = 'https://shop.test/received';
	public function __construct() {
		$this->record = new Stub_Webhook_Order( 512 );
		$this->items = array( new Stub_Line_Item( 'Product', 1, 100, 100 ) );
	}
	public function __call( $method, $args ) {
		return call_user_func_array( array( $this->record, $method ), $args );
	}
	public function get_total() { return '100.00'; }
	public function get_billing_email() { return 'buyer@example.test'; }
	public function get_formatted_billing_full_name() { return 'Buyer'; }
	public function get_billing_phone() { return ''; }
	public function get_billing_address_1() { return ''; }
	public function get_checkout_payment_url() { return 'https://shop.test/pay'; }
	public function update_status( $status, $note ) {}
}

function wp_remote_request( $url, $args ) {
	if ( ! empty( $GLOBALS['recurrente_binding_on_http'] ) ) { call_user_func( $GLOBALS['recurrente_binding_on_http'] ); }
	$GLOBALS['recurrente_binding_http'][] = array( $url, $args );
	$GLOBALS['recurrente_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( isset( $GLOBALS['recurrente_binding_response'] ) ) {
		return $GLOBALS['recurrente_binding_response'];
	}
	if ( 'GET' === $args['method'] ) {
		$body = array( 'id' => 'ch_test', 'live_mode' => true );
	} elseif ( false !== strpos( $url, '/customers' ) ) {
		$body = array( 'id' => 'cus_fixture' );
	} else {
		$body = array( 'id' => 'ch_created', 'checkout_url' => 'https://pay.test/ch_created', 'total_in_cents' => 10000, 'currency' => 'GTQ' );
	}
	return array( 'response' => array( 'code' => 201 ), 'body' => json_encode( $body ) );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }

it( 'gateway to webhook: a retry replaces checkout/attempt identity, then only the current signed payment completes', function () {
	recurrente_reset_renewal_world();
	$order = new Stub_Binding_Payable_Order();
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_binding_http'] = array();
	$gateway = ( new ReflectionClass( 'Stub_Binding_Gateway' ) )->newInstanceWithoutConstructor();
	$gateway->process_payment( 512 );
	$first = $order->get_meta( '_recurrente_payment_binding' );
	$result = $gateway->process_payment( 512 );
	$current = $order->get_meta( '_recurrente_payment_binding' );
	$requests = $GLOBALS['recurrente_binding_http'];
	$payload = json_decode( $requests[3][1]['body'], true );
	$event = recurrente_test_event( 'intent.succeeded', $order->record, 'pa_gateway' );
	$event['checkout']['id'] = 'ch_created';
	$event['checkout']['metadata'] = $payload['metadata'];
	$event['checkout']['metadata']['wc_payment_attempt'] = $first['attempt'];
	recurrente_test_deliver( $event );
	if ( $order->is_paid() ) {
		return false;
	}
	$event['checkout']['metadata'] = $payload['metadata'];
	$event['amount_in_cents'] = 10490;
	$event['checkout']['total_in_cents'] = 10490;
	recurrente_test_deliver( $event );
	return 'success' === $result['result'] && $first['attempt'] !== $current['attempt']
		&& 'live' === $current['mode'] && 10000 === $current['base_amount']
		&& 10000 === $payload['items'][0]['amount_in_cents'] && $order->is_paid();
} );


function esc_html( $text ) { return $text; }

it( 'legacy paid parent: original checkout lookup verifies environment before accepting a new reminder', function () {
	$order = recurrente_test_subscription_order( 513 );
	$order->paid = true;
	update_option( 'woocommerce_recurrente_settings', array( 'live_secret_key' => 'sk_live_fixture' ) );
	$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_upgrade' );
	recurrente_test_deliver( $event );
	return 1 === count( recurrente_renewal_orders() ) && array( 'mode' => 'live' ) === $order->get_meta( '_recurrente_payment_binding' );
} );

it( 'legacy paid parent: wrong original checkout environment creates no renewal', function () {
	$order = recurrente_test_subscription_order( 514 );
	$order->paid = true;
	$GLOBALS['recurrente_binding_response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'id' => 'ch_test', 'live_mode' => false ) ) );
	try {
		recurrente_test_deliver( recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_wrong_upgrade' ) );
	} finally {
		unset( $GLOBALS['recurrente_binding_response'] );
	}
	return array() === recurrente_renewal_orders() && '' === $order->get_meta( '_recurrente_payment_binding' );
} );

it( 'legacy paid parent: temporary original checkout lookup failure retries the same signed delivery', function () {
	$order = recurrente_test_subscription_order( 515 );
	$order->paid = true;
	$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_retry_upgrade' );
	$GLOBALS['recurrente_binding_response'] = new WP_Error( 'temporary', 'connection failed' );
	try {
		$failed = recurrente_test_deliver( $event, 'msg_upgrade_retry' );
	} finally {
		unset( $GLOBALS['recurrente_binding_response'] );
	}
	$processed = get_transient( 'recurrente_wh_' . md5( 'msg_upgrade_retry' ) );
	$retry = recurrente_test_deliver( $event, 'msg_upgrade_retry' );
	return 503 === $failed->status && ! $processed && 200 === $retry->status && 1 === count( recurrente_renewal_orders() );
} );

it( 'original-checkout renewal validates recurring amount rather than a stale checkout total containing signup fees', function () {
	$order = recurrente_test_subscription_order( 516 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_signup_first' ) );
	$event = recurrente_test_event( 'intent.succeeded', $order, 'pa_signup_renewal' );
	$event['checkout']['total_in_cents'] = 12500;
	recurrente_test_deliver( $event );
	return 1 === count( recurrente_renewal_orders() );
} );

it( 'dynamic pricing rejects underpayment even with the correct base snapshot', function () {
	$order = recurrente_test_order( 517 );
	recurrente_binding( $order, array( 'dynamic' => true ) );
	$event = recurrente_bound_event( $order );
	$event['amount_in_cents'] = 9900;
	$event['checkout']['total_in_cents'] = 9900;
	recurrente_test_deliver( $event );
	return ! $order->paid;
} );

it( 'first subscription payment cannot pin an unrelated remote subscription reference', function () {
	$order = recurrente_test_subscription_order( 518 );
	recurrente_binding( $order );
	$event = recurrente_bound_event( $order );
	$event['payment']['paymentable']['metadata']['wc_order_id'] = '999';
	recurrente_test_deliver( $event );
	return ! $order->paid && '' === $order->get_meta( '_recurrente_subscription_id' );
} );

it( 'changed Woo order amount invalidates the checkout snapshot', function () {
	$order = recurrente_test_order( 519 );
	recurrente_binding( $order, array( 'amount' => 9000 ) );
	recurrente_test_deliver( recurrente_bound_event( $order ) );
	return ! $order->paid;
} );

it( 'dynamic pricing requires a recorded base instead of guessing from processor fees', function () {
	$order = recurrente_test_order( 520 );
	recurrente_binding( $order, array( 'dynamic' => true, 'base_amount' => null ) );
	$event = recurrente_bound_event( $order );
	$event['details'] = array( 'fee' => 490 );
	$event['amount_in_cents'] = 10490;
	$event['checkout']['total_in_cents'] = 10490;
	recurrente_test_deliver( $event );
	return ! $order->paid;
} );

it( 'legacy renewal missing its environment API key remains retryable', function () {
	$order = recurrente_test_subscription_order( 521 );
	$order->paid = true;
	$settings = get_option( 'woocommerce_recurrente_settings' );
	update_option( 'woocommerce_recurrente_settings', array() );
	try {
		$response = recurrente_test_deliver( recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_no_key' ), 'msg_missing_key' );
	} finally {
		update_option( 'woocommerce_recurrente_settings', $settings );
	}
	return 503 === $response->status && ! get_transient( 'recurrente_wh_' . md5( 'msg_missing_key' ) ) && array() === recurrente_renewal_orders();
} );


it( 'reloads the order after acquiring its lock before validating a delayed webhook', function () {
	global $wpdb, $recurrente_test_orders;
	$old = recurrente_test_order( 530 );
	recurrente_binding( $old );
	$event = recurrente_bound_event( $old );
	$current = clone $old;
	$current->update_meta_data( '_recurrente_checkout_id', 'ch_replaced_before_lock' );
	$current->save();
	$wpdb->on_lock = function () use ( $current ) {
		$GLOBALS['wpdb']->on_lock = null;
		$GLOBALS['recurrente_test_orders'] = array( $current );
	};
	$response = recurrente_test_deliver( $event );
	return 200 === $response->status && ! $current->is_paid() && ! $old->is_paid()
		&& '' === Recurrente_Charge_Order_Link::charge_of( $current );
} );

it( 'checkout creation and binding replacement exclude webhook fulfillment until the lock is released', function () {
	recurrente_reset_renewal_world();
	$order = new Stub_Binding_Payable_Order();
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_binding_http'] = array();
	$event = recurrente_test_event( 'intent.succeeded', $order->record, 'pa_during_create' );
	$blocked = null;
	$GLOBALS['recurrente_binding_on_http'] = function () use ( $event, &$blocked ) {
		$GLOBALS['recurrente_binding_on_http'] = null;
		$blocked = recurrente_test_deliver( $event, 'msg_during_create' );
	};
	$gateway = ( new ReflectionClass( 'Stub_Binding_Gateway' ) )->newInstanceWithoutConstructor();
	try { $created = $gateway->process_payment( 512 ); }
	finally { unset( $GLOBALS['recurrente_binding_on_http'] ); }
	$retry = recurrente_test_deliver( $event, 'msg_during_create' );
	return 'success' === $created['result'] && 503 === $blocked->status && 200 === $retry->status
		&& ! $order->is_paid() && '' === Recurrente_Charge_Order_Link::charge_of( $order );
} );

it( 'fulfillment retains the order lock and a later retry does not replace a paid checkout', function () {
	recurrente_reset_renewal_world();
	$order = new Stub_Binding_Payable_Order();
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_binding_http'] = array();
	$gateway = ( new ReflectionClass( 'Stub_Binding_Gateway' ) )->newInstanceWithoutConstructor();
	$blocked = null;
	$order->record->on_payment_complete = function () use ( $gateway, &$blocked ) {
		$blocked = $gateway->process_payment( 512 );
	};
	$response = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order->record, 'pa_during_complete' ) );
	$retry = $gateway->process_payment( 512 );
	return 200 === $response->status && $order->is_paid() && 'failure' === $blocked['result']
		&& 'success' === $retry['result'] && array() === $GLOBALS['recurrente_binding_http'];
} );

it( 'unavailable order serialization fails closed and leaves the same webhook retryable', function () {
	global $wpdb;
	$order = recurrente_test_order( 531 );
	$event = recurrente_test_event( 'intent.succeeded', $order, 'pa_lock_unavailable' );
	$wpdb->unavailable = true;
	$response = recurrente_test_deliver( $event, 'msg_lock_unavailable' );
	$processed = get_transient( 'recurrente_wh_' . md5( 'msg_lock_unavailable' ) );
	$wpdb->unavailable = false;
	$retry = recurrente_test_deliver( $event, 'msg_lock_unavailable' );
	return 503 === $response->status && ! $processed && 200 === $retry->status && $order->is_paid();
} );


class Stub_Binding_Cached_Order_Store extends Stub_Binding_Order_Data_Store {
	public function clear_cached_data( $ids ) {
		foreach ( $ids as $id ) { unset( $GLOBALS['recurrente_binding_cache']['hpos'][ $id ] ); }
	}
	public function read( $order ) {
		$id = $order->get_id();
		foreach ( array( 'posts', 'post_meta', 'hpos' ) as $group ) {
			if ( isset( $GLOBALS['recurrente_binding_cache'][ $group ][ $id ] ) ) {
				$order->record->paid = false;
				return;
			}
		}
		$order->record->paid = $GLOBALS['recurrente_binding_persisted_paid'];
	}
}
class Stub_Binding_Cached_Payable_Order extends Stub_Binding_Payable_Order {
	public $status_updates = array();
	public function get_data_store() { return new Stub_Binding_Cached_Order_Store(); }
	public function update_status( $status, $note ) { $this->status_updates[] = $status; }
}

foreach ( array( 'posts', 'post_meta', 'hpos' ) as $cache_group ) {
	it( 'does not replace a paid checkout after waiting with stale ' . $cache_group . ' cache', function () use ( $cache_group ) {
		global $wpdb;
		recurrente_reset_renewal_world();
		$order = new Stub_Binding_Cached_Payable_Order();
		$GLOBALS['recurrente_test_orders'] = array( $order );
		$GLOBALS['recurrente_binding_cache'] = array( $cache_group => array( 512 => 'pending' ) );
		$GLOBALS['recurrente_binding_persisted_paid'] = false;
		$GLOBALS['recurrente_binding_http'] = array();
		$wpdb->on_lock = function () {
			$GLOBALS['wpdb']->on_lock = null;
			$GLOBALS['recurrente_binding_persisted_paid'] = true;
		};
		$gateway = ( new ReflectionClass( 'Stub_Binding_Gateway' ) )->newInstanceWithoutConstructor();
		try { $response = $gateway->process_payment( 512 ); }
		finally { $GLOBALS['recurrente_binding_cache'] = array(); }
		return 'success' === $response['result'] && $order->received_url === $response['redirect']
			&& $order->is_paid() && array() === $GLOBALS['recurrente_binding_http'] && array() === $order->status_updates;
	} );
}
