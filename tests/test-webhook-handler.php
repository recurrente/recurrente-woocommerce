<?php
/**
 * Recurrente_Webhook_Handler tests.
 *
 * Drives a signed delivery through handle() end to end — only the WordPress
 * and WooCommerce globals are stubbed. Pins that the unified `intent.succeeded`
 * marks an order paid, and that the same charge arriving again (a Svix retry,
 * or the legacy `payment_intent.succeeded` twin an older account also gets)
 * produces no second effect: no second payment_complete, no second renewal.
 */

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
$recurrente_test_orders     = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name ) {
		global $recurrente_test_secret;
		return 'recurrente_webhook_secret_live' === $name ? $recurrente_test_secret : false;
	}
}
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
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		global $recurrente_test_orders;
		return isset( $recurrente_test_orders[ $id ] ) ? $recurrente_test_orders[ $id ] : false;
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

// WooCommerce Subscriptions, stubbed just enough for a parent order to own one
// subscription and for renewals to be counted.
if ( ! class_exists( 'WC_Subscriptions' ) ) {
	class WC_Subscriptions {}
}
class Stub_Subscription {
	public $renewals = 0;
}
class Stub_Renewal_Order {
	public function set_payment_method( $id ) {}
	public function payment_complete() {}
}
if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	function wcs_get_subscriptions_for_order( $order ) {
		return $order->subscriptions;
	}
}
if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
	function wcs_create_renewal_order( $subscription ) {
		$subscription->renewals++;
		return new Stub_Renewal_Order();
	}
}

class Stub_Webhook_Order {
	public $id;
	public $key = 'wc_order_abc';
	public $paid = false;
	public $completions = array();
	public $subscriptions = array();
	private $meta = array( '_recurrente_checkout_id' => 'ch_test' );
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
		$this->meta[ $key ] = $value;
	}
	public function save() {}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-svix-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-webhook-registrar.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-subscriptions.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-webhook-handler.php';

function recurrente_test_order( $id ) {
	global $recurrente_test_orders;
	$recurrente_test_orders[ $id ] = new Stub_Webhook_Order( $id );
	return $recurrente_test_orders[ $id ];
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

it( 'intent.succeeded marks the order paid with its checkout id', function () {
	$order    = recurrente_test_order( 101 );
	$response = recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_1' ) );
	return 200 === $response->status && $order->is_paid() && array( 'ch_test' ) === $order->completions;
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
	return 1 === count( $order->completions ) && array( 'pa_3' ) === $order->get_meta( '_recurrente_processed_intents' );
} );

it( 'legacy payment_intent.succeeded still marks an order paid on its own', function () {
	$order    = recurrente_test_order( 104 );
	$response = recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_4' ) );
	return 200 === $response->status && $order->is_paid();
} );

it( 'a renewal charge arriving under both names creates exactly one renewal order', function () {
	$order                = recurrente_test_order( 105 );
	$order->subscriptions = array( new Stub_Subscription() );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_5_first' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_5_renewal' ) );
	recurrente_test_deliver( recurrente_test_event( 'payment_intent.succeeded', $order, 'pa_5_renewal' ) );
	return 1 === count( $order->completions ) && 1 === $order->subscriptions[0]->renewals;
} );

it( 'a distinct intent on a paid subscription parent is still recorded as a renewal', function () {
	$order                = recurrente_test_order( 106 );
	$order->subscriptions = array( new Stub_Subscription() );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_first' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_second' ) );
	recurrente_test_deliver( recurrente_test_event( 'intent.succeeded', $order, 'pa_6_third' ) );
	return 2 === $order->subscriptions[0]->renewals;
} );
