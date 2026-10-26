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

/** WooCommerce boundary: the real Recurrente_Subscriptions class performs the status transitions. */
class Stub_Lifecycle_Subscription extends Stub_Subscription {
	public $status;
	public $updates = array();
	public function __construct( $status ) {
		parent::__construct( 7 );
		$this->status = $status;
	}
	public function has_status( $status ) {
		return $status === $this->status;
	}
	public function update_status( $status, $note ) {
		$this->status    = $status;
		$this->updates[] = $status;
	}
}

/** SubscriptionSerializer#webhook_view + PaymentSerializer#api_view (Rails origin/master eb1b3ae8). No checkout property. */
function recurrente_test_lifecycle_event( $type, $order, $location ) {
	$metadata = array( 'wc_order_id' => (string) $order->get_id(), 'wc_order_key' => $order->get_order_key() );
	return array(
		'event_type'              => $type,
		'id'                      => 'su_test',
		'api_version'             => '2024-04-24',
		'created_at'              => '2026-10-26T12:00:00Z',
		'customer_id'             => 'cus_test',
		'user_id'                 => 'us_test',
		'customer_name'           => 'Comprador de prueba',
		'customer_email'          => 'buyer@example.com',
		'product'                 => array( 'id' => 'prod_test' ),
		'items'                   => array(),
		'payment'                 => 'payment' === $location ? array(
			'id'          => 'pay_test',
			'paymentable' => array( 'type' => 'Checkout', 'id' => 'ch_test', 'metadata' => $metadata ),
		) : null,
		'resumes_on'              => null,
		'next_payment_attempt_at' => null,
		'payment_retries'         => 0,
		'metadata'                => 'metadata' === $location ? $metadata : array(),
		'test_clock_id'           => null,
	);
}

foreach ( array( 'metadata', 'payment' ) as $location ) {
	foreach ( array(
		'subscription.create'     => 'active',
		'subscription.pause'      => 'on-hold',
		'subscription.unpause'    => 'active',
		'subscription.reactivate' => 'active',
		'subscription.cancel'     => 'cancelled',
		'subscription.past_due'   => 'on-hold',
	) as $type => $expected ) {
		it( "$type synchronizes subscriptions using $location, once per state change", function () use ( $location, $type, $expected ) {
			$order                = recurrente_test_order( 201 );
			$subscription         = new Stub_Lifecycle_Subscription( 'active' === $expected ? 'on-hold' : 'active' );
			$order->subscriptions = array( $subscription );
			$event                = recurrente_test_lifecycle_event( $type, $order, $location );
			$response             = recurrente_test_deliver( $event );
			recurrente_test_deliver( $event ); // Distinct Svix delivery of the same lifecycle change.
			return 200 === $response->status && $expected === $subscription->status
				&& array( $expected ) === $subscription->updates && ! $order->is_paid() && array() === recurrente_renewal_orders();
		} );
	}
}

foreach ( array( 'metadata', 'payment' ) as $location ) {
	foreach ( array(
		array(),
		array( 'wc_order_id' => '201' ),
		array( 'wc_order_key' => 'wc_order_abc' ),
		array( 'wc_order_id' => '201', 'wc_order_key' => 'wrong_key' ),
		array( 'wc_order_id' => '999', 'wc_order_key' => 'wc_order_abc' ),
		array( 'wc_order_id' => '201garbage', 'wc_order_key' => 'wc_order_abc' ),
		array( 'wc_order_id' => array( 201 ), 'wc_order_key' => 'wc_order_abc' ),
		array( 'wc_order_id' => '201', 'wc_order_key' => array( 'wc_order_abc' ) ),
		array( 'wc_order_id' => true, 'wc_order_key' => 'wc_order_abc' ),
		'not metadata',
		null,
	) as $index => $invalid ) {
		it( "lifecycle rejects unverified $location order reference #$index", function () use ( $location, $invalid ) {
			$order                = recurrente_test_order( 201 );
			$subscription         = new Stub_Lifecycle_Subscription( 'active' );
			$order->subscriptions = array( $subscription );
			$event                = recurrente_test_lifecycle_event( 'subscription.pause', $order, $location );
			if ( 'metadata' === $location ) {
				$event['metadata'] = $invalid;
			} else {
				$event['payment']['paymentable']['metadata'] = $invalid;
			}
			$response = recurrente_test_deliver( $event );
			return 200 === $response->status && array() === $subscription->updates;
		} );
	}
}

it( 'subscription metadata is authoritative over a different valid payment order reference', function () {
	global $recurrente_test_orders;
	$order                = recurrente_test_order( 201 );
	$subscription         = new Stub_Lifecycle_Subscription( 'active' );
	$order->subscriptions = array( $subscription );
	$other                = new Stub_Webhook_Order( 202 );
	$other_subscription   = new Stub_Lifecycle_Subscription( 'active' );
	$other->subscriptions = array( $other_subscription );
	$recurrente_test_orders[] = $other;
	$event                = recurrente_test_lifecycle_event( 'subscription.pause', $other, 'payment' );
	$event['metadata']    = array( 'wc_order_id' => 201, 'wc_order_key' => $order->get_order_key() );
	recurrente_test_deliver( $event );
	return 'on-hold' === $subscription->status && array() === $other_subscription->updates;
} );

foreach ( array(
	array( 'wc_order_id' => 201, 'wc_order_key' => 'wrong_key' ),
	array( 'wc_order_id' => 201 ),
	array( 'wc_order_id' => null ),
) as $index => $invalid ) {
	it( "invalid subscription reference #$index cannot fall back to valid payment metadata", function () use ( $invalid ) {
		$order                = recurrente_test_order( 201 );
		$subscription         = new Stub_Lifecycle_Subscription( 'active' );
		$order->subscriptions = array( $subscription );
		$event                = recurrente_test_lifecycle_event( 'subscription.pause', $order, 'payment' );
		$event['metadata']    = $invalid;
		recurrente_test_deliver( $event );
		return array() === $subscription->updates;
	} );
}

it( 'unrelated subscription metadata permits resolving the original checkout through payment', function () {
	$order                = recurrente_test_order( 201 );
	$subscription         = new Stub_Lifecycle_Subscription( 'active' );
	$order->subscriptions = array( $subscription );
	$event                = recurrente_test_lifecycle_event( 'subscription.pause', $order, 'payment' );
	$event['metadata']    = array( 'merchant_reference' => 'example' );
	recurrente_test_deliver( $event );
	return 'on-hold' === $subscription->status;
} );

it( 'unsupported subscription.paused does not change a subscription', function () {
	$order                = recurrente_test_order( 201 );
	$subscription         = new Stub_Lifecycle_Subscription( 'active' );
	$order->subscriptions = array( $subscription );
	recurrente_test_deliver( recurrente_test_lifecycle_event( 'subscription.paused', $order, 'metadata' ) );
	return array() === $subscription->updates;
} );

it( 'subscription metadata lookup does not expand intent order resolution', function () {
	$order                = recurrente_test_order( 201 );
	$event                = recurrente_test_lifecycle_event( 'intent.succeeded', $order, 'payment' );
	$event['metadata']    = $event['payment']['paymentable']['metadata'];
	recurrente_test_deliver( $event );
	return ! $order->is_paid() && array() === recurrente_renewal_orders();
} );
