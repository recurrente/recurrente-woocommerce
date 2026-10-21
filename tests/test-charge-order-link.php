<?php
/**
 * Recurrente_Charge_Order_Link + renewal tests.
 *
 * A Recurrente charge (intent id) must produce at most one renewal order, no
 * matter how many times Svix delivers it or how many deliveries overlap. WP,
 * WC and WC Subscriptions are stood in by small stubs: an in-memory order
 * store behind wc_get_orders(), a $wpdb that emulates GET_LOCK, and a
 * subscription whose renewals are created with wcs_create_renewal_order().
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log() {}
		};
	}
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public function get_return_url( $order ) {
			return $order->received_url;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-gateway.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-charge-order-link.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-subscriptions.php';

/** In-memory order: meta is only visible to wc_get_orders() once saved, like the real store. */
class Stub_Renewal_Order {
	public static $next_id = 100;
	public $id;
	public $saved_meta = array();
	public $pending_meta = array();
	public $paid = false;
	public $payment_method = '';
	public static $fail_payment_complete_once = false;
	public function __construct() {
		$this->id = self::$next_id++;
	}
	public function get_id() {
		return $this->id;
	}
	public function update_meta_data( $key, $value ) {
		$this->pending_meta[ $key ] = $value;
	}
	public function get_meta( $key ) {
		return isset( $this->saved_meta[ $key ] ) ? $this->saved_meta[ $key ] : '';
	}
	public function save() {
		$this->saved_meta = array_merge( $this->saved_meta, $this->pending_meta );
	}
	public function set_payment_method( $method ) {
		$this->payment_method = $method;
	}
	public function payment_complete() {
		if ( self::$fail_payment_complete_once ) {
			self::$fail_payment_complete_once = false;
			throw new RuntimeException( 'simulated crash after the renewal order was created' );
		}
		$this->paid = true;
	}
}

class Stub_Subscription {
	public $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
}

/** Emulates MySQL GET_LOCK: a name held by "another connection" answers 0 (timeout). */
class Stub_Wpdb {
	public $held_elsewhere = array();
	public $on_lock = null;
	public $locks_taken = 0;
	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args );
	}
	public function get_var( $sql ) {
		preg_match( "/GET_LOCK\('([^']+)'/", $sql, $m );
		if ( in_array( $m[1], $this->held_elsewhere, true ) ) {
			return '0';
		}
		$this->locks_taken++;
		$this->held_elsewhere[] = $m[1];
		if ( $this->on_lock ) {
			call_user_func( $this->on_lock );
		}
		return '1';
	}
	public function query( $sql ) {
		preg_match( "/RELEASE_LOCK\('([^']+)'/", $sql, $m );
		$this->held_elsewhere = array_values( array_diff( $this->held_elsewhere, array( $m[1] ) ) );
		return 1;
	}
}

$recurrente_test_orders = array();

function wc_get_orders( $args ) {
	global $recurrente_test_orders;
	$key   = $args['meta_query'][0]['key'];
	$value = $args['meta_query'][0]['value'];
	return array_values( array_filter( $recurrente_test_orders, function ( $order ) use ( $key, $value ) {
		return $order->get_meta( $key ) === $value;
	} ) );
}

function wcs_create_renewal_order( $subscription ) {
	global $recurrente_test_orders;
	$order                    = new Stub_Renewal_Order();
	$recurrente_test_orders[] = $order;
	return $order;
}

function wcs_get_subscriptions_for_order( $order, $args = array() ) {
	return array( 7 => new Stub_Subscription( 7 ) );
}

class WC_Subscriptions {} // Makes Recurrente_Subscriptions::active() true.

function recurrente_reset_renewal_world() {
	global $recurrente_test_orders, $wpdb;
	$recurrente_test_orders = array();
	$wpdb                   = new Stub_Wpdb();
	Stub_Renewal_Order::$fail_payment_complete_once = false;
	return $wpdb;
}

function recurrente_record_renewal( $charge_id ) {
	Recurrente_Subscriptions::record_renewal( new stdClass(), $charge_id );
}

it( 'renewal: a repeated delivery of the same charge creates one order', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the order carries the intent id as _recurrente_intent_id', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	$order = $recurrente_test_orders[0];
	return 'pa_abc' === $order->get_meta( '_recurrente_intent_id' ) && 'recurrente' === $order->payment_method;
} );

it( 'renewal: distinct charges create distinct orders', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_one' );
	recurrente_record_renewal( 'pa_two' );
	return 2 === count( $recurrente_test_orders );
} );

it( 'renewal: a concurrent delivery arriving while the lock is held is refused and creates nothing', function () {
	global $recurrente_test_orders;
	$wpdb          = recurrente_reset_renewal_world();
	$second_result = null;
	$wpdb->on_lock = function () use ( &$second_result, $wpdb ) {
		$wpdb->on_lock = null; // Only the first acquisition spawns the "second" delivery.
		try {
			recurrente_record_renewal( 'pa_abc' );
			$second_result = 'created';
		} catch ( Recurrente_Charge_Locked $e ) {
			$second_result = 'locked';
		}
	};
	recurrente_record_renewal( 'pa_abc' );
	return 'locked' === $second_result && 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the lock is released after the delivery, so the retry then reuses the order', function () {
	global $recurrente_test_orders;
	$wpdb = recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return array() === $wpdb->held_elsewhere && 2 === $wpdb->locks_taken && 1 === count( $recurrente_test_orders );
} );

it( 'renewal: a crash after creating the order lets the retry complete that same order', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	Stub_Renewal_Order::$fail_payment_complete_once = true;
	try {
		recurrente_record_renewal( 'pa_abc' );
		return false; // The first attempt must blow up.
	} catch ( RuntimeException $e ) {
		// Svix retries.
	}
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the lock is released even when the delivery crashes', function () {
	$wpdb = recurrente_reset_renewal_world();
	Stub_Renewal_Order::$fail_payment_complete_once = true;
	try {
		recurrente_record_renewal( 'pa_abc' );
	} catch ( RuntimeException $e ) {
		// Expected.
	}
	return array() === $wpdb->held_elsewhere;
} );

it( 'renewal: without GET_LOCK support the persisted link alone still de-dupes a retry', function () {
	global $recurrente_test_orders, $wpdb;
	recurrente_reset_renewal_world();
	$wpdb = new class() extends Stub_Wpdb {
		public function get_var( $sql ) {
			return null;
		}
	};
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders );
} );
