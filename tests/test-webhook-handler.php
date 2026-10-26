<?php
/**
 * Recurrente_Webhook_Handler tests.
 *
 * Drives a signed delivery through handle() end to end — only the WordPress
 * and WooCommerce globals are stubbed (stubs-renewals.php). Pins that the
 * unified `intent.succeeded` marks an order paid, and that the same charge
 * arriving again (a Svix retry, or the legacy `payment_intent.succeeded` twin
 * an older account also gets) produces no second effect: no second
 * payment_complete, no second renewal. Recurrente_Charge_Order_Link is the
 * one record of which order each charge produced.
 */

require_once __DIR__ . '/stubs-renewals.php';

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body;
		private $headers;
		public function __construct( $body, $headers ) {
			$this->body    = $body;
			$this->headers = $headers;
		}
		public function get_body() {
			return $this->body;
		}
		public function get_header( $name ) {
			return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data, $status ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

$recurrente_test_secret     = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
$recurrente_test_transients = array();

update_option( 'recurrente_webhook_secret_live', $recurrente_test_secret );
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $recurrente_test_transients;
		return isset( $recurrente_test_transients[ $key ] ) ? $recurrente_test_transients[ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value ) {
		global $recurrente_test_transients;
		$recurrente_test_transients[ $key ] = $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/** The checkout's own order: meta is only visible once saved, like the real store. */
class Stub_Webhook_Order {
	public $id;
	public $key = 'wc_order_abc';
	public $paid = false;
	public $completions = array();
	public $subscriptions = array();
	private $meta = array( '_recurrente_checkout_id' => 'ch_test' );
	private $pending_meta = array();
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_order_key() {
		return $this->key;
	}
	public function is_paid() {
		return $this->paid;
	}
	public function payment_complete( $transaction_id ) {
		$this->paid          = true;
		$this->completions[] = $transaction_id;
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->pending_meta[ $key ] = $value;
	}
	public function save() {
		$this->meta = array_merge( $this->meta, $this->pending_meta );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-svix-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-webhook-registrar.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-webhook-handler.php';

/** A fresh order world (no orders, free locks) holding one checkout order. */
function recurrente_test_order( $id ) {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	$recurrente_test_orders[] = new Stub_Webhook_Order( $id );
	return end( $recurrente_test_orders );
}

function recurrente_test_subscription_order( $id ) {
	$order                = recurrente_test_order( $id );
	$order->subscriptions = array( new Stub_Subscription( 7 ) );
	return $order;
}

/** Builds the payload Rails sends: unified events carry id + legacy_id, legacy ones only id. */
function recurrente_test_event( $type, $order, $legacy_intent_id ) {
	$event = array(
		'event_type' => $type,
		'id'         => 0 === strpos( $type, 'intent.' ) ? 'in_' . $legacy_intent_id : $legacy_intent_id,
		'checkout'   => array( 'metadata' => array( 'wc_order_id' => $order->get_id(), 'wc_order_key' => $order->get_order_key() ) ),
	);
	if ( 0 === strpos( $type, 'intent.' ) ) {
		$event['legacy_id'] = $legacy_intent_id;
	}
	return $event;
}

/** Signs the payload like Svix and delivers it through the public entry point, with a fresh svix-id each time. */
function recurrente_test_deliver( $event ) {
	global $recurrente_test_secret;
	static $sequence = 0;
	$sequence++;
	$body    = recurrente_test_json( $event );
	$id      = 'msg_' . $sequence;
	$ts      = (string) time();
	$key     = base64_decode( substr( $recurrente_test_secret, strlen( 'whsec_' ) ) );
	$sig     = 'v1,' . base64_encode( hash_hmac( 'sha256', "{$id}.{$ts}.{$body}", $key, true ) );
	$request = new WP_REST_Request( $body, array( 'svix-id' => $id, 'svix-timestamp' => $ts, 'svix-signature' => $sig ) );
	return Recurrente_Webhook_Handler::handle( $request );
}

function recurrente_test_json( $data ) {
	return json_encode( $data );
}

it( 'canonical_event_type maps every legacy intent name onto the unified one', function () {
	$pairs = array(
		'payment_intent.succeeded'       => 'intent.succeeded',
		'payment_intent.failed'          => 'intent.failed',
		'payment_intent.canceled'        => 'intent.canceled',
		'bank_transfer_intent.succeeded' => 'intent.succeeded',
		'balance_intent.succeeded'       => 'intent.succeeded',
		'crypto_intent.failed'           => 'intent.failed',
		'cash_intent.succeeded'          => 'intent.succeeded',
	);
	foreach ( $pairs as $legacy => $unified ) {
		if ( Recurrente_Webhook_Handler::canonical_event_type( $legacy ) !== $unified ) {
			return false;
		}
	}
	return true;
} );

it( 'canonical_event_type leaves unified, refund and subscription names untouched', function () {
	foreach ( array( 'intent.succeeded', 'refund.create', 'subscription.cancel', '' ) as $type ) {
		if ( Recurrente_Webhook_Handler::canonical_event_type( $type ) !== $type ) {
			return false;
		}
	}
	return true;
} );

it( 'intent.succeeded marks the order paid with its checkout id and links it to the charge', function () {
	$order    = recurrente_test_order( 101 );
	$response = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_1' ) );
	return 200 === $response->status && $order->is_paid() && array( 'ch_test' ) === $order->completions && 'pa_1' === $order->get_meta( '_recurrente_intent_id' );
} );

it( 'the same intent.succeeded delivered again produces no second effect', function () {
	$order = recurrente_test_order( 102 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_2' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_2' ) );
	return 1 === count( $order->completions );
} );

it( 'the legacy payment_intent.succeeded twin of an applied charge produces no second effect', function () {
	$order = recurrente_test_order( 103 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_3' ) );
	recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_3' ) );
	return 1 === count( $order->completions ) && 'pa_3' === $order->get_meta( '_recurrente_intent_id' );
} );

it( 'legacy payment_intent.succeeded still marks an order paid on its own', function () {
	$order    = recurrente_test_order( 104 );
	$response = recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_4' ) );
	return 200 === $response->status && $order->is_paid();
} );

it( 'a renewal charge arriving under both names creates exactly one renewal order', function () {
	$order = recurrente_test_subscription_order( 105 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_5_first' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_5_renewal' ) );
	recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_5_renewal' ) );
	$renewals = recurrente_renewal_orders();
	return 1 === count( $order->completions ) && 1 === count( $renewals ) && $renewals[0]->paid && 'pa_5_renewal' === $renewals[0]->get_meta( '_recurrente_intent_id' );
} );

it( 'a distinct intent on a paid subscription parent is still recorded as a renewal', function () {
	$order = recurrente_test_subscription_order( 106 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_first' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_second' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_third' ) );
	return 2 === count( recurrente_renewal_orders() );
} );

it( 'the first charge delivered again on a subscription parent creates no renewal', function () {
	$order = recurrente_test_subscription_order( 107 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_7' ) );
	recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_7' ) );
	return 1 === count( $order->completions ) && array() === recurrente_renewal_orders();
} );

it( 'a delivery whose charge is locked by another delivery is answered 503 and left unprocessed', function () {
	global $wpdb;
	$order                 = recurrente_test_order( 108 );
	$wpdb->held_elsewhere  = array( 'recurrente_charge_' . md5( 'pa_8|' ) );
	$response              = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_8' ) );
	$wpdb->held_elsewhere  = array();
	$retry                 = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_8' ) );
	return 503 === $response->status && 200 === $retry->status && 1 === count( $order->completions );
} );

/** Rails keeps the subscription's metadata while Intentable#checkout selects the new reminder checkout. */
function recurrente_test_reminder_event( $type, $order, $charge ) {
	$event = recurrente_test_event( $type, $order, $charge );
	$event['payment'] = array(
		'id' => 'py_original',
		'paymentable' => array(
			'type' => 'Subscription',
			'id' => 'su_original',
			'metadata' => $event['checkout']['metadata'],
		),
	);
	$event['subscription'] = array( 'id' => 'su_original' );
	$event['checkout'] = array( 'id' => 'ch_reminder', 'metadata' => array() );
	return $event;
}

foreach ( array( 'intent.succeeded', 'payment_intent.succeeded' ) as $first_format ) {
	it( "reminder renewal: {$first_format} and its twin/replays link exactly one paid renewal", function () use ( $first_format ) {
		$order = recurrente_test_subscription_order( 201 );
		recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_original' ) );
		$other = 'intent.succeeded' === $first_format ? 'payment_intent.succeeded' : 'intent.succeeded';
		foreach ( array( $first_format, $other, $first_format, $other ) as $type ) {
			$response = recurrente_test_deliver( recurrente_test_reminder_event( $type, $order, 'pa_reminder' ) );
			if ( 200 !== $response->status ) {
				return false;
			}
		}
		$renewals = recurrente_renewal_orders();
		return 1 === count( $order->completions ) && 1 === count( $renewals ) && $renewals[0]->paid
			&& 'pa_reminder' === $renewals[0]->get_meta( '_recurrente_intent_id' )
			&& 'pa_original' === $order->get_meta( '_recurrente_intent_id' );
	} );
}

it( 'reminder renewal: absent checkout metadata also resolves the original order', function () {
	$order = recurrente_test_subscription_order( 202 );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_original' ) );
	$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_reminder' );
	unset( $event['checkout']['metadata'] );
	recurrente_test_deliver( $event );
	return 1 === count( recurrente_renewal_orders() );
} );

$invalid_reminder_changes = array(
	'forged order key' => function ( &$event ) { $event['payment']['paymentable']['metadata']['wc_order_key'] = 'forged'; },
	'missing order key' => function ( &$event ) { unset( $event['payment']['paymentable']['metadata']['wc_order_key'] ); },
	'non-string order key' => function ( &$event ) { $event['payment']['paymentable']['metadata']['wc_order_key'] = array( 'forged' ); },
	'unknown order id' => function ( &$event ) { $event['payment']['paymentable']['metadata']['wc_order_id'] = 999999; },
	'malformed order id' => function ( &$event ) { $event['payment']['paymentable']['metadata']['wc_order_id'] = '203forged'; },
	'mismatched subscription id' => function ( &$event ) { $event['subscription']['id'] = 'su_other'; },
	'missing subscription id' => function ( &$event ) { unset( $event['subscription']['id'] ); },
	'missing paymentable id' => function ( &$event ) { unset( $event['payment']['paymentable']['id'] ); },
	'non-subscription paymentable' => function ( &$event ) { $event['payment']['paymentable']['type'] = 'OneTimePayment'; },
	'malformed metadata' => function ( &$event ) { $event['payment']['paymentable']['metadata'] = 'invalid'; },
	'explicit invalid checkout key' => function ( &$event ) { $event['checkout']['metadata'] = array( 'wc_order_id' => 203, 'wc_order_key' => 'forged' ); },
	'partial checkout identity' => function ( &$event ) { $event['checkout']['metadata'] = array( 'wc_order_id' => 203 ); },
	'lifecycle event' => function ( &$event ) { $event['event_type'] = 'subscription.create'; },
);
foreach ( $invalid_reminder_changes as $description => $change ) {
	it( "reminder renewal rejects {$description}", function () use ( $change ) {
		$order = recurrente_test_subscription_order( 203 );
		$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_invalid' );
		$change( $event );
		$response = recurrente_test_deliver( $event );
		return 200 === $response->status && ! $order->is_paid() && array() === recurrente_renewal_orders();
	} );
}

it( 'reminder renewal rejects an order id paired with another existing order key', function () {
	global $recurrente_test_orders;
	$order = recurrente_test_subscription_order( 204 );
	$other = new Stub_Webhook_Order( 205 );
	$other->key = 'wc_order_other';
	$recurrente_test_orders[] = $other;
	$event = recurrente_test_reminder_event( 'intent.succeeded', $order, 'pa_invalid' );
	$event['payment']['paymentable']['metadata']['wc_order_key'] = $other->key;
	recurrente_test_deliver( $event );
	return ! $order->is_paid() && ! $other->is_paid() && array() === recurrente_renewal_orders();
} );
