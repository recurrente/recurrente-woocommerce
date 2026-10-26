<?php
/**
 * Stand-ins for WordPress, WooCommerce and WooCommerce Subscriptions, shared
 * by the charge-link and webhook-handler tests: an in-memory order store
 * behind wc_get_orders() / wc_get_order(), a $wpdb that emulates GET_LOCK,
 * and subscriptions whose renewals are created with wcs_create_renewal_order().
 */

require_once __DIR__ . '/stubs-wordpress.php';

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
require_once dirname( __DIR__ ) . '/includes/class-recurrente-subscription-sync.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-subscriptions.php';

/** In-memory order: meta is only visible to wc_get_orders() once saved, like the real store. */
if ( ! class_exists( 'Stub_Renewal_Order' ) ) {
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
		$this->pending_meta = array();
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
}

if ( ! class_exists( 'Stub_Subscription' ) ) {
class Stub_Subscription {
	public $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
}
}

/** Emulates MySQL GET_LOCK: a name held by "another connection" answers 0 (timeout). */
if ( ! class_exists( 'Stub_Wpdb' ) ) {
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
}

$recurrente_test_orders = array();

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args ) {
		global $recurrente_test_orders;
		$key   = $args['meta_query'][0]['key'];
		$value = $args['meta_query'][0]['value'];
		return array_values( array_filter( $recurrente_test_orders, function ( $order ) use ( $key, $value ) {
			return $order->get_meta( $key ) === $value;
		} ) );
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		global $recurrente_test_orders;
		foreach ( $recurrente_test_orders as $order ) {
			if ( $order->get_id() === $id ) {
				return $order;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
	function wcs_create_renewal_order( $subscription ) {
		global $recurrente_test_orders;
		$order                    = new Stub_Renewal_Order();
		$recurrente_test_orders[] = $order;
		return $order;
	}
}
/** An order that declares its own subscriptions owns them; anything else owns subscription 7. */
if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	function wcs_get_subscriptions_for_order( $order, $args = array() ) {
		return isset( $order->subscriptions ) ? $order->subscriptions : array( 7 => new Stub_Subscription( 7 ) );
	}
}
if ( ! class_exists( 'WC_Subscriptions' ) ) {
	class WC_Subscriptions {} // Makes Recurrente_Subscriptions::active() true.
}

function recurrente_reset_renewal_world() {
	global $recurrente_test_orders, $wpdb;
	$recurrente_test_orders = array();
	$wpdb                   = new Stub_Wpdb();
	Stub_Renewal_Order::$fail_payment_complete_once = false;
	return $wpdb;
}

/** Renewal orders created so far (by wcs_create_renewal_order), as opposed to checkout orders registered by a test. */
function recurrente_renewal_orders() {
	global $recurrente_test_orders;
	return array_values( array_filter( $recurrente_test_orders, function ( $order ) {
		return $order instanceof Stub_Renewal_Order;
	} ) );
}
