<?php
/**
 * Recurrente_Refund_Sync tests.
 *
 * A `refund.create` event mirrors exactly the refunded amount onto the order
 * (partial refunds stay partial), applies each Recurrente refund id once, only
 * applies succeeded refunds, and refuses amounts above what the order can
 * still refund. WooCommerce is stood in by a minimal order stub and a
 * recording wc_create_refund.
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
	class Stub_Null_Logger {
		public function log( $level, $message, $context = array() ) {}
	}
	function wc_get_logger() {
		return new Stub_Null_Logger();
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/** Records every refund requested and moves the order's refunded total, like WooCommerce would. */
$GLOBALS['recurrente_refund_calls']  = array();
$GLOBALS['recurrente_refund_result'] = null;
function wc_create_refund( $args ) {
	$GLOBALS['recurrente_refund_calls'][] = $args;
	if ( $GLOBALS['recurrente_refund_result'] instanceof WP_Error ) {
		return $GLOBALS['recurrente_refund_result'];
	}
	Stub_Refundable_Order::$registry[ $args['order_id'] ]->refunded += $args['amount'];
	return (object) array( 'amount' => $args['amount'] );
}

class Stub_Refundable_Order {
	public static $registry = array();
	public $id       = 21669;
	public $currency = 'GTQ';
	public $total    = 247.00;
	public $refunded = 0.0;
	public $meta     = array();
	public $notes    = array();
	public $saved    = 0;
	public function __construct() {
		self::$registry[ $this->id ] = $this;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_remaining_refund_amount() {
		return $this->total - $this->refunded;
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		$this->saved++;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-refund-sync.php';

function recurrente_refund_event( $overrides = array() ) {
	$refund = array_merge( array(
		'id'                                => 're_ab12cd34',
		'status'                            => 'succeeded',
		'currency'                          => 'GTQ',
		'customer_refunded_amount_in_cents' => 8500,
		'account_refunded_amount_in_cents'  => 8000,
	), $overrides );
	return array( 'event_type' => 'refund.create', 'refund' => $refund );
}

function recurrente_sync_refund( $order, $overrides = array() ) {
	$GLOBALS['recurrente_refund_calls']  = array();
	$GLOBALS['recurrente_refund_result'] = null;
	Recurrente_Refund_Sync::apply( $order, recurrente_refund_event( $overrides ) );
	return $GLOBALS['recurrente_refund_calls'];
}

it( 'partial refund: refunds exactly the amount Recurrente returned to the buyer', function () {
	$calls = recurrente_sync_refund( new Stub_Refundable_Order() );
	return 1 === count( $calls ) && 85.00 === $calls[0]['amount'] && 21669 === $calls[0]['order_id'];
} );

it( 'partial refund: restocks and names the Recurrente refund id in the reason', function () {
	$calls = recurrente_sync_refund( new Stub_Refundable_Order() );
	return true === $calls[0]['restock_items'] && false !== strpos( $calls[0]['reason'], 're_ab12cd34' );
} );

it( 'partial refund: leaves the rest of the order refundable', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order );
	return 162.00 === $order->get_remaining_refund_amount();
} );

it( 'dedupe: the same refund id delivered twice creates one WooCommerce refund', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order );
	$second = recurrente_sync_refund( $order );
	return 0 === count( $second ) && 85.00 === $order->refunded;
} );

it( 'dedupe: the applied id is persisted on the order', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order );
	return array( 're_ab12cd34' ) === $order->get_meta( Recurrente_Refund_Sync::APPLIED_META ) && 1 === $order->saved;
} );

it( 'dedupe: a different refund id on the same order is applied', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order );
	$second = recurrente_sync_refund( $order, array( 'id' => 're_second', 'customer_refunded_amount_in_cents' => 1000 ) );
	return 1 === count( $second ) && 95.00 === $order->refunded;
} );

it( 'dedupe: a WooCommerce failure is not recorded, so a retry can apply it', function () {
	$order = new Stub_Refundable_Order();
	$GLOBALS['recurrente_refund_calls']  = array();
	$GLOBALS['recurrente_refund_result'] = new WP_Error( 'fail', 'boom' );
	Recurrente_Refund_Sync::apply( $order, recurrente_refund_event() );
	return '' === $order->get_meta( Recurrente_Refund_Sync::APPLIED_META ) && 0.0 === $order->refunded;
} );

it( 'status: a pending refund is not applied', function () {
	return 0 === count( recurrente_sync_refund( new Stub_Refundable_Order(), array( 'status' => 'pending' ) ) );
} );

it( 'status: a failed refund is not applied', function () {
	return 0 === count( recurrente_sync_refund( new Stub_Refundable_Order(), array( 'status' => 'failed' ) ) );
} );

it( 'status: a voided refund is not applied', function () {
	return 0 === count( recurrente_sync_refund( new Stub_Refundable_Order(), array( 'status' => 'voided' ) ) );
} );

it( 'status: an unapplied refund can still be applied once it succeeds', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order, array( 'status' => 'pending' ) );
	return 1 === count( recurrente_sync_refund( $order ) );
} );

it( 'over-refund: an amount above the remaining balance is not applied', function () {
	$order = new Stub_Refundable_Order();
	$calls = recurrente_sync_refund( $order, array( 'customer_refunded_amount_in_cents' => 24701 ) );
	return 0 === count( $calls ) && 0.0 === $order->refunded;
} );

it( 'over-refund: leaves a note on the order and does not mark the id applied', function () {
	$order = new Stub_Refundable_Order();
	recurrente_sync_refund( $order, array( 'customer_refunded_amount_in_cents' => 24701 ) );
	return 1 === count( $order->notes )
		&& false !== strpos( $order->notes[0], 're_ab12cd34' )
		&& false !== strpos( $order->notes[0], '247.01' )
		&& '' === $order->get_meta( Recurrente_Refund_Sync::APPLIED_META );
} );

it( 'over-refund: a refund for exactly the remaining balance is applied', function () {
	$order = new Stub_Refundable_Order();
	$calls = recurrente_sync_refund( $order, array( 'customer_refunded_amount_in_cents' => 24700 ) );
	return 1 === count( $calls ) && 0.0 === $order->get_remaining_refund_amount();
} );

it( 'currency: a refund in another currency is not applied and leaves a note', function () {
	$order = new Stub_Refundable_Order();
	$calls = recurrente_sync_refund( $order, array( 'currency' => 'USD' ) );
	return 0 === count( $calls ) && 1 === count( $order->notes );
} );

it( 'malformed: a refund without id is not applied', function () {
	return 0 === count( recurrente_sync_refund( new Stub_Refundable_Order(), array( 'id' => '' ) ) );
} );

it( 'malformed: an event without a refund object is not applied', function () {
	$GLOBALS['recurrente_refund_calls'] = array();
	Recurrente_Refund_Sync::apply( new Stub_Refundable_Order(), array( 'event_type' => 'refund.create' ) );
	return 0 === count( $GLOBALS['recurrente_refund_calls'] );
} );
