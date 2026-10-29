<?php
require_once dirname( __DIR__ ) . '/includes/class-recurrente-payment-reconciliation.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-pending-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-payment-recovery.php';
require_once __DIR__ . '/stubs-recovery.php';

it( 'return: authenticated GETs recover a paid order and later signed twins never complete or renew it twice', function () {
	$order = recurrente_recovery_order();
	$order->subscriptions = array( new Stub_Subscription( 7 ) );
	$metadata = $GLOBALS['recurrente_recovery_intent']['checkout']['metadata'];
	$GLOBALS['recurrente_recovery_intent']['subscription'] = array( 'id' => 'su_original' );
	$GLOBALS['recurrente_recovery_intent']['payment'] = array( 'paymentable' => array( 'type' => 'Subscription', 'id' => 'su_original', 'metadata' => $metadata ) );
	Recurrente_Payment_Recovery::register_hooks();
	do_action( 'template_redirect' );
	do_action( 'template_redirect' );
	foreach ( array( 'intent.succeeded', 'payment_intent.succeeded' ) as $type ) {
		$event = recurrente_bound_event( $order, 'pa_recovered' );
		$event['event_type'] = $type;
		if ( 'payment_intent.succeeded' === $type ) { $event['id'] = $event['legacy_id']; unset( $event['legacy_id'] ); }
		recurrente_test_deliver( $event );
	}
	$requests = $GLOBALS['recurrente_recovery_http'];
	return $order->is_paid() && 1 === count( $order->completions ) && array() === recurrente_renewal_orders()
		&& 'pa_recovered' === Recurrente_Charge_Order_Link::charge_of( $order ) && 2 === count( $requests )
		&& Recurrente_API_Client::BASE_URL . '/checkouts/ch_test' === $requests[0][0]
		&& Recurrente_API_Client::BASE_URL . '/intents/in_recovered' === $requests[1][0]
		&& 'GET' === $requests[0][1]['method'] && 'GET' === $requests[1][1]['method']
		&& 'sk_live_fixture' === $requests[0][1]['headers']['X-SECRET-KEY'] && 10 === $requests[0][1]['timeout']
		&& 10 === $requests[1][1]['timeout'];
} );

foreach ( array( 'wrong key', 'array key', 'missing key', 'other customer', 'not received' ) as $invalid ) {
	it( "return: $invalid cannot trigger even a provider lookup", function () use ( $invalid ) {
		$order = recurrente_recovery_order();
		if ( 'wrong key' === $invalid ) { $_GET['key'] = 'wrong'; }
		if ( 'array key' === $invalid ) { $_GET['key'] = array( 'invalid' ); }
		if ( 'missing key' === $invalid ) { $_GET = array(); }
		if ( 'other customer' === $invalid ) { $order->customer = 42; $GLOBALS['recurrente_current_user'] = 43; }
		if ( 'not received' === $invalid ) { $GLOBALS['recurrente_is_received'] = false; }
		Recurrente_Payment_Recovery::buyer_return();
		return ! $order->is_paid() && array() === $GLOBALS['recurrente_recovery_http'];
	} );
}

it( 'return: the registered customer may recover their own order with its key', function () {
	$order = recurrente_recovery_order();
	$order->customer = 42;
	$GLOBALS['recurrente_current_user'] = 42;
	Recurrente_Payment_Recovery::buyer_return();
	return $order->is_paid();
} );

foreach ( array( 'unpaid', 'payment_in_progress', 'expired' ) as $status ) {
	it( "return: checkout $status is not proof of payment and never fetches an intent", function () use ( $status ) {
		$order = recurrente_recovery_order();
		$GLOBALS['recurrente_recovery_intent']['checkout']['status'] = $status;
		Recurrente_Payment_Recovery::buyer_return();
		return ! $order->is_paid() && 1 === count( $GLOBALS['recurrente_recovery_http'] ) && '' === Recurrente_Charge_Order_Link::charge_of( $order );
	} );
}

$invalid_recovery = array(
	'underpayment' => function ( &$i ) { $i['amount_in_cents'] = 9999; $i['checkout']['total_in_cents'] = 9999; },
	'overpayment' => function ( &$i ) { $i['amount_in_cents'] = 10490; $i['checkout']['total_in_cents'] = 10490; },
	'currency' => function ( &$i ) { $i['currency'] = 'USD'; $i['checkout']['currency'] = 'USD'; },
	'attempt' => function ( &$i ) { $i['checkout']['metadata']['wc_payment_attempt'] = 'old'; },
	'checkout' => function ( &$i ) { $i['checkout']['id'] = 'ch_old'; },
	'order' => function ( &$i ) { $i['checkout']['metadata']['wc_order_id'] = '999'; },
	'order key' => function ( &$i ) { $i['checkout']['metadata']['wc_order_key'] = 'wrong'; },
	'environment' => function ( &$i ) { $i['checkout']['live_mode'] = false; },
	'missing environment' => function ( &$i ) { unset( $i['checkout']['live_mode'] ); },
	'no legacy identity' => function ( &$i ) { unset( $i['legacy_id'] ); },
	'unknown type' => function ( &$i ) { $i['type'] = 'setup'; },
	'pending intent' => function ( &$i ) { $i['status'] = 'pending'; },
	'contradictory raw status' => function ( &$i ) { $i['raw_status'] = 'requires_capture'; },
	'failed intent' => function ( &$i ) { $i['status'] = 'failed'; },
	'canceled intent' => function ( &$i ) { $i['status'] = 'canceled'; },
);
foreach ( $invalid_recovery as $invalid => $change ) {
	it( "recovery rejects $invalid using the real binding and leaves no charge link", function () use ( $change ) {
		$order = recurrente_recovery_order();
		$change( $GLOBALS['recurrente_recovery_intent'] );
		Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
		return ! $order->is_paid() && '' === Recurrente_Charge_Order_Link::charge_of( $order );
	} );
}

it( 'dynamic pricing recovery honors the saved base while retaining the merchant total', function () {
	$order = recurrente_recovery_order();
	recurrente_binding( $order, array( 'dynamic' => true ) );
	$GLOBALS['recurrente_recovery_intent']['amount_in_cents'] = 10490;
	$GLOBALS['recurrente_recovery_intent']['checkout']['total_in_cents'] = 10490;
	return 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && '100.00' === $order->get_total();
} );

it( 'paid TEST simulation without a persisted intent stays pending without fabricating a charge identity', function () {
	$order = recurrente_recovery_order();
	recurrente_binding( $order, array( 'mode' => 'test' ) );
	$GLOBALS['recurrente_recovery_intent']['checkout']['live_mode'] = false;
	$GLOBALS['recurrente_recovery_intent']['checkout']['latest_intent'] = null;
	return 'unsupported' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && ! $order->is_paid()
		&& '' === Recurrente_Charge_Order_Link::charge_of( $order ) && 1 === count( $GLOBALS['recurrente_recovery_http'] );
} );

it( 'a persisted TEST intent uses the original TEST key even after settings switch to LIVE', function () {
	$order = recurrente_recovery_order();
	recurrente_binding( $order, array( 'mode' => 'test' ) );
	update_option( 'woocommerce_recurrente_settings', array( 'test_secret_key' => 'sk_test_fixture', 'test_mode' => 'no' ) );
	$GLOBALS['recurrente_recovery_intent']['checkout']['live_mode'] = false;
	$result = Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
	return 'paid' === $result && 'sk_test_fixture' === $GLOBALS['recurrente_recovery_http'][0][1]['headers']['X-SECRET-KEY'];
} );

it( 'API failure remains retryable, throttles immediate repeats, and later GET recovery completes once', function () {
	$order = recurrente_recovery_order();
	$normal_http = $GLOBALS['recurrente_binding_response_for_request'];
	$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) {
		$GLOBALS['recurrente_recovery_http'][] = array( $url, $args );
		return new WP_Error( 'timeout', 'test timeout' );
	};
	$first = Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
	$again = Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
	$unpaid = ! $order->is_paid();
	$GLOBALS['recurrente_binding_response_for_request'] = $normal_http;
	recurrente_recovery_recheck( $order );
	return 'unavailable' === $first && 'recent' === $again && $unpaid
		&& 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && 3 === count( $GLOBALS['recurrente_recovery_http'] );
} );

it( 'missing original mode key fails closed instead of using the current mode key', function () {
	$order = recurrente_recovery_order();
	update_option( 'woocommerce_recurrente_settings', array( 'test_secret_key' => 'sk_test_fixture' ) );
	return 'unavailable' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && ! $order->is_paid() && array() === $GLOBALS['recurrente_recovery_http'];
} );

it( 'legacy order discovers TEST mode only through an authenticated checkout after LIVE returns 404', function () {
	$order = recurrente_recovery_order();
	$order->update_meta_data( '_recurrente_payment_binding', '' ); $order->save();
	unset( $GLOBALS['recurrente_recovery_intent']['checkout']['metadata']['wc_payment_attempt'] );
	$GLOBALS['recurrente_recovery_intent']['checkout']['live_mode'] = false;
	$normal_http = $GLOBALS['recurrente_binding_response_for_request'];
	$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $normal_http ) {
		if ( 'sk_live_fixture' === $args['headers']['X-SECRET-KEY'] ) {
			$GLOBALS['recurrente_recovery_http'][] = array( $url, $args );
			return array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
		}
		return $normal_http( $url, $args );
	};
	return 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && array( 'mode' => 'test' ) === $order->get_meta( '_recurrente_payment_binding' )
		&& 3 === count( $GLOBALS['recurrente_recovery_http'] );
} );

foreach ( array( 'checkout replaced', 'attempt replaced', 'amount edited', 'currency edited', 'canceled', 'webhook won' ) as $race ) {
	it( "revalidates under the order lock when $race during the authenticated lookup", function () use ( $race ) {
		$order = recurrente_recovery_order();
		$normal_http = $GLOBALS['recurrente_binding_response_for_request'];
		$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $normal_http, $order, $race ) {
			$response = $normal_http( $url, $args );
			if ( false !== strpos( $url, '/intents/' ) ) {
				if ( 'checkout replaced' === $race ) { $order->update_meta_data( '_recurrente_checkout_id', 'ch_new' ); $order->save(); }
				if ( 'attempt replaced' === $race ) { recurrente_binding( $order, array( 'attempt' => 'another-attempt' ) ); }
				if ( 'amount edited' === $race ) { $order->amount = '110.00'; }
				if ( 'currency edited' === $race ) { $order->currency = 'USD'; }
				if ( 'canceled' === $race ) { $order->status = 'cancelled'; }
				if ( 'webhook won' === $race ) { recurrente_test_deliver( recurrente_bound_event( $order, 'pa_recovered' ) ); }
			}
			return $response;
		};
		Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
		return 'webhook won' === $race ? $order->is_paid() && 1 === count( $order->completions ) && array() === recurrente_renewal_orders()
			: ! $order->is_paid() && '' === Recurrente_Charge_Order_Link::charge_of( $order );
	} );
}

it( 'unavailable order lock performs no GET and a retry can safely recover', function () {
	$order = recurrente_recovery_order();
	$GLOBALS['wpdb']->unavailable = true;
	$first = Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
	$requests = $GLOBALS['recurrente_recovery_http'];
	$GLOBALS['wpdb']->unavailable = false;
	return 'unavailable' === $first && array() === $requests && 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
} );

foreach ( array( 'cancelled', 'refunded', 'failed', 'processing', 'completed' ) as $status ) {
	it( "recovery does not reopen an order with status $status", function () use ( $status ) {
		$order = recurrente_recovery_order();
		$order->status = $status;
		return 'skipped' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && array() === $GLOBALS['recurrente_recovery_http'];
	} );
}

foreach ( array( 'woocommerce_save_data', 'update-order_700' ) as $nonce_action ) {
	it( "admin: existing Woo action with $nonce_action nonce performs verified recovery and records its result", function () use ( $nonce_action ) {
		$order = recurrente_recovery_order();
		$GLOBALS['recurrente_can_edit'] = true;
		$_POST[ 'woocommerce_save_data' === $nonce_action ? 'woocommerce_meta_nonce' : '_wpnonce' ] = 'valid-' . $nonce_action;
		Recurrente_Payment_Recovery::register_hooks();
		$actions = Recurrente_Payment_Recovery::order_actions( array( 'existing' => 'Existing action' ), $order );
		do_action( 'woocommerce_order_action_recurrente_reconcile_payment', $order );
		return $order->is_paid() && isset( $actions['existing'], $actions['recurrente_reconcile_payment'] ) && 1 === count( $order->notes );
	} );
}

foreach ( array( 'no permission', 'no nonce', 'bad nonce' ) as $invalid ) {
	it( "admin: $invalid performs no recovery", function () use ( $invalid ) {
		$order = recurrente_recovery_order();
		$GLOBALS['recurrente_can_edit'] = 'no permission' !== $invalid;
		if ( 'no nonce' !== $invalid ) { $_POST['_wpnonce'] = 'bad nonce' === $invalid ? 'wrong' : 'valid-update-order_700'; }
		Recurrente_Payment_Recovery::admin_recover( $order );
		return ! $order->is_paid() && array() === $GLOBALS['recurrente_recovery_http'] && array() === $order->notes;
	} );
}

it( 'scheduler: Action Scheduler recurrence is unique and removes the WP-Cron fallback', function () {
	recurrente_recovery_order();
	$GLOBALS['recurrente_cron'][ Recurrente_Pending_Payments::SCAN_HOOK ] = 'old fallback';
	Recurrente_Pending_Payments::register_hooks();
	do_action( 'init' ); do_action( 'init' );
	$event = reset( $GLOBALS['recurrente_recovery_as'] );
	return 1 === count( $GLOBALS['recurrente_recovery_as'] ) && 300 === $event['interval'] && $event['unique']
		&& ! wp_next_scheduled( Recurrente_Pending_Payments::SCAN_HOOK );
} );

it( 'worker: bounded pages progress past unpaid orders and dispatch real recovery for a later page', function () {
	recurrente_recovery_order();
	$orders = array();
	for ( $i = 1; $i <= 23; $i++ ) { $orders[] = new Stub_Recovery_Order( 700 + $i ); recurrente_binding( $orders[ $i - 1 ] ); }
	$GLOBALS['recurrente_test_orders'] = $orders;
	Recurrente_Pending_Payments::register_hooks();
	do_action( Recurrente_Pending_Payments::SCAN_HOOK );
	$first_count = count( $GLOBALS['recurrente_recovery_as'] );
	do_action( Recurrente_Pending_Payments::SCAN_HOOK );
	$intent = &$GLOBALS['recurrente_recovery_intent'];
	$intent['checkout']['metadata']['wc_order_id'] = '723';
	do_action( Recurrente_Pending_Payments::ORDER_HOOK, 723 );
	return 20 === $first_count && 23 === count( $GLOBALS['recurrente_recovery_as'] ) && $orders[22]->is_paid()
		&& 1 === get_option( Recurrente_Pending_Payments::PAGE_OPTION ) && 2 === count( $GLOBALS['recurrente_recovery_http'] )
		&& 20 === $GLOBALS['recurrente_scan_queries'][0]['limit'] && 2 === $GLOBALS['recurrente_scan_queries'][1]['paged'];
} );

it( 'worker: old, too-new, canceled and other-gateway orders are excluded', function () {
	$order = recurrente_recovery_order();
	$old = new Stub_Recovery_Order( 701 ); $old->created_at = time() - 8 * DAY_IN_SECONDS;
	$new = new Stub_Recovery_Order( 702 ); $new->created_at = time();
	$canceled = new Stub_Recovery_Order( 703 ); $canceled->status = 'cancelled';
	$other = new Stub_Recovery_Order( 704 ); $other->method = 'another_gateway';
	$held = new Stub_Recovery_Order( 705 ); $held->status = 'on-hold';
	$GLOBALS['recurrente_test_orders'] = array( $order, $old, $new, $canceled, $other, $held );
	Recurrente_Pending_Payments::scan(); Recurrente_Pending_Payments::scan();
	return 2 === count( $GLOBALS['recurrente_recovery_as'] ) && array() === $GLOBALS['recurrente_recovery_http'];
} );

it( 'scheduler failure falls back to unique WP-Cron jobs which also run real recovery; deactivation clears both queues', function () {
	$order = recurrente_recovery_order();
	$GLOBALS['recurrente_as_fails'] = true;
	Recurrente_Pending_Payments::register_hooks();
	do_action( 'init' ); do_action( 'init' );
	Recurrente_Pending_Payments::scan(); Recurrente_Pending_Payments::scan();
	$count = count( $GLOBALS['recurrente_recovery_cron'] );
	$event = reset( $GLOBALS['recurrente_recovery_cron'] );
	do_action( $event['hook'], ...$event['args'] );
	$scheduled = wp_next_scheduled( Recurrente_Pending_Payments::SCAN_HOOK );
	Recurrente_Pending_Payments::deactivate();
	return 1 === $count && $order->is_paid() && $scheduled && array() === $GLOBALS['recurrente_recovery_cron']
		&& array() === $GLOBALS['recurrente_recovery_as'] && ! wp_next_scheduled( Recurrente_Pending_Payments::SCAN_HOOK );
} );



foreach ( array( 'id', 'checkout', 'currency', 'total', 'metadata', 'latest intent' ) as $changed ) {
	it( "the checkout and intent GETs must agree on $changed before fulfillment", function () use ( $changed ) {
		$order = recurrente_recovery_order();
		$normal_http = $GLOBALS['recurrente_binding_response_for_request'];
		$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $normal_http, $changed ) {
			$response = $normal_http( $url, $args );
			if ( false !== strpos( $url, '/intents/' ) ) {
				$body = json_decode( $response['body'], true );
				if ( 'id' === $changed ) { $body['id'] = 'in_another'; }
				if ( 'checkout' === $changed ) { $body['checkout']['id'] = 'ch_another'; }
				if ( 'currency' === $changed ) { $body['checkout']['currency'] = 'USD'; }
				if ( 'total' === $changed ) { $body['checkout']['total_in_cents'] = 9999; }
				if ( 'metadata' === $changed ) { $body['checkout']['metadata']['wc_order_key'] = 'other'; }
				if ( 'latest intent' === $changed ) { $body['checkout']['latest_intent']['id'] = 'in_another'; }
				$response['body'] = json_encode( $body );
			}
			return $response;
		};
		return 'mismatch' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && ! $order->is_paid() && '' === Recurrente_Charge_Order_Link::charge_of( $order );
	} );
}

it( 'recovery keeps Sandbox account mode separate from the authoritative checkout environment', function () {
	$order = recurrente_recovery_order();
	$GLOBALS['recurrente_recovery_intent']['live_mode'] = false;
	return 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && $order->is_paid();
} );

foreach ( array( 401, 403, 429, 500 ) as $status ) {
	it( "provider HTTP $status keeps recovery pending and does not fall back to another environment", function () use ( $status ) {
		$order = recurrente_recovery_order();
		$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $status ) {
			$GLOBALS['recurrente_recovery_http'][] = array( $url, $args );
			return array( 'response' => array( 'code' => $status ), 'body' => '{}' );
		};
		return 'unavailable' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && ! $order->is_paid() && 1 === count( $GLOBALS['recurrente_recovery_http'] );
	} );
}

it( 'a payment completion interrupted after the durable charge claim retries against the same charge', function () {
	$order = recurrente_recovery_order();
	$order->on_payment_complete = function () { throw new RuntimeException( 'interrupted after claim' ); };
	try {
		Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
		return false;
	} catch ( RuntimeException $e ) {
		if ( 'interrupted after claim' !== $e->getMessage() ) { throw $e; }
	}
	$claimed = 'pa_recovered' === Recurrente_Charge_Order_Link::charge_of( $order ) && ! $order->is_paid();
	$order->on_payment_complete = null;
	recurrente_recovery_recheck( $order );
	return $claimed && 'paid' === Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) && 1 === count( $order->completions );
} );

class Stub_Recovery_Cached_Order_Store extends Stub_Binding_Order_Data_Store {
	public function clear_cached_data( $ids ) {
		foreach ( $ids as $id ) { unset( $GLOBALS['recurrente_binding_cache']['hpos'][ $id ] ); }
	}
	public function read( $order ) {
		foreach ( array( 'posts', 'post_meta', 'hpos' ) as $group ) {
			if ( isset( $GLOBALS['recurrente_binding_cache'][ $group ][ $order->get_id() ] ) ) { $order->paid = false; return; }
		}
		$order->paid = $GLOBALS['recurrente_recovery_persisted_paid'];
	}
}
class Stub_Recovery_Cached_Order extends Stub_Recovery_Order {
	public function get_data_store() { return new Stub_Recovery_Cached_Order_Store(); }
}
foreach ( array( 'posts', 'post_meta', 'hpos' ) as $group ) {
	it( "recovery invalidates stale $group cache after the GET and reloads a payment another request completed", function () use ( $group ) {
		recurrente_recovery_order();
		$order = new Stub_Recovery_Cached_Order( 700 ); recurrente_binding( $order );
		$GLOBALS['recurrente_test_orders'] = array( $order );
		$GLOBALS['recurrente_recovery_persisted_paid'] = false;
		$normal_http = $GLOBALS['recurrente_binding_response_for_request'];
		$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $normal_http, $group ) {
			$response = $normal_http( $url, $args );
			if ( false !== strpos( $url, '/intents/' ) ) {
				$GLOBALS['recurrente_binding_cache'][ $group ][700] = 'pending';
				$GLOBALS['recurrente_recovery_persisted_paid'] = true;
			}
			return $response;
		};
		return 'paid' === Recurrente_Payment_Reconciliation::reconcile( 700 ) && $order->is_paid() && array() === $order->completions;
	} );
}

it( 'scheduler deactivation removes pending Action Scheduler scan and order jobs', function () {
	recurrente_recovery_order();
	Recurrente_Pending_Payments::ensure_scheduled(); Recurrente_Pending_Payments::scan();
	$before = count( $GLOBALS['recurrente_recovery_as'] );
	Recurrente_Pending_Payments::deactivate();
	return 2 === $before && array() === $GLOBALS['recurrente_recovery_as'];
} );


it( 'a new checkout attempt clears the previous recovery cooldown', function () {
	$order = new Stub_Binding_Payable_Order();
	recurrente_reset_renewal_world();
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_binding_http'] = array();
	unset( $GLOBALS['recurrente_binding_response_for_request'] );
	$order->update_meta_data( Recurrente_Payment_Reconciliation::CHECKED_AT, time() ); $order->save();
	$gateway = ( new ReflectionClass( 'Stub_Binding_Gateway' ) )->newInstanceWithoutConstructor();
	$result = $gateway->process_payment( $order->get_id() );
	return 'success' === $result['result'] && 0 === $order->get_meta( Recurrente_Payment_Reconciliation::CHECKED_AT );
} );

unset( $GLOBALS['recurrente_binding_response_for_request'] );

/** Run the admin action as Woo dispatches it: authorized merchant, valid HPOS save nonce. */
function recurrente_run_admin_action( $order, $user = 5 ) {
	$GLOBALS['recurrente_can_edit'] = true;
	$GLOBALS['recurrente_current_user'] = $user;
	$_POST['_wpnonce'] = 'valid-update-order_' . $order->get_id();
	Recurrente_Payment_Recovery::admin_recover( $order );
}

$recurrente_admin_outcomes = array(
	'paid'        => array( 'success', 'Pago confirmado al consultar Recurrente.', null ),
	'pending'     => array( 'warning', 'Recurrente aún no confirma el pago.', function () { $GLOBALS['recurrente_recovery_intent']['checkout']['status'] = 'unpaid'; } ),
	'mismatch'    => array( 'error', 'El pago en Recurrente no coincide con esta orden. Revisalo en Recurrente antes de cobrar otra vez.', function () { $GLOBALS['recurrente_recovery_intent']['checkout']['total_in_cents'] += 1000; } ),
	'unsupported' => array( 'error', 'Este pago no se puede verificar desde WooCommerce. Revisalo en Recurrente antes de cambiar el estado de la orden.', function () { $GLOBALS['recurrente_recovery_intent']['checkout']['latest_intent'] = null; } ),
	'unavailable' => array( 'error', 'No se pudo consultar Recurrente. Intentá de nuevo en unos minutos.', function () {
		$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) {
			$GLOBALS['recurrente_recovery_http'][] = array( $url, $args );
			return array( 'response' => array( 'code' => 503 ), 'body' => '{}' );
		};
	} ),
);
foreach ( $recurrente_admin_outcomes as $result => list( $type, $message, $arrange ) ) {
	it( "admin: $result writes one attributed note and a $type notice for that order", function () use ( $type, $message, $arrange ) {
		$order = recurrente_recovery_order();
		if ( $arrange ) { $arrange(); }
		recurrente_run_admin_action( $order );
		recurrente_admin_screen( 700 );
		$html = recurrente_admin_notice_html();
		return array( $message ) === $order->notes && array( array( 0, true ) ) === $order->note_args
			&& "<div class=\"notice notice-$type is-dismissible\"><p>$message</p></div>" === $html
			&& '' === recurrente_admin_notice_html();
	} );
}


it( 'admin: a repeat within the cooldown makes no lookup and leaves no note, only a warning notice', function () {
	$order = recurrente_recovery_order();
	$GLOBALS['recurrente_recovery_intent']['checkout']['status'] = 'unpaid';
	recurrente_run_admin_action( $order );
	recurrente_admin_screen( 700 );
	recurrente_admin_notice_html();
	$lookups = count( $GLOBALS['recurrente_recovery_http'] );
	recurrente_run_admin_action( $order );
	return 1 === count( $order->notes ) && $lookups === count( $GLOBALS['recurrente_recovery_http'] )
		&& false !== strpos( recurrente_admin_notice_html(), 'notice-warning is-dismissible"><p>Este pago se consultó hace menos de un minuto.' );
} );

it( 'admin: an order that is no longer pending gets an info notice and no note', function () {
	$order = recurrente_recovery_order();
	$order->status = 'processing';
	recurrente_run_admin_action( $order );
	recurrente_admin_screen( 700 );
	return array() === $order->notes && array() === $GLOBALS['recurrente_recovery_http']
		&& false !== strpos( recurrente_admin_notice_html(), 'notice-info is-dismissible"><p>Esta orden ya no está pendiente de pago.' );
} );

foreach ( array(
	'another order'  => function () { recurrente_admin_screen( 701 ); },
	'another user'   => function () { recurrente_admin_screen( 700 ); $GLOBALS['recurrente_current_user'] = 6; },
	'the HPOS list'  => function () { recurrente_admin_screen( 700, 'hpos', null ); },
	'a CPT non-edit' => function () { recurrente_admin_screen( 700, 'cpt', 'trash' ); },
	'no permission'  => function () { recurrente_admin_screen( 700 ); $GLOBALS['recurrente_can_edit'] = false; },
	'another screen' => function () { recurrente_admin_screen( 700 ); $GLOBALS['recurrente_screen'] = (object) array( 'id' => 'dashboard' ); },
) as $where => $arrange ) {
	it( "admin: the result notice is not shown or consumed on $where", function () use ( $arrange ) {
		$order = recurrente_recovery_order();
		recurrente_run_admin_action( $order );
		$arrange();
		$elsewhere = recurrente_admin_notice_html();
		$GLOBALS['recurrente_current_user'] = 5;
		$GLOBALS['recurrente_can_edit'] = true;
		recurrente_admin_screen( 700 );
		return '' === $elsewhere && false !== strpos( recurrente_admin_notice_html(), 'Pago confirmado al consultar Recurrente.' );
	} );
}

it( 'admin: the CPT edit screen of the same order shows its notice', function () {
	$order = recurrente_recovery_order();
	recurrente_run_admin_action( $order );
	recurrente_admin_screen( 700, 'cpt' );
	return false !== strpos( recurrente_admin_notice_html(), 'notice-success' );
} );
