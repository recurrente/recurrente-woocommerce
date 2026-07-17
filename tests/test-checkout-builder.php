<?php
/**
 * Recurrente_Checkout_Builder tests.
 *
 * Verifies the itemized payload without a full WordPress: minimal WC_Order /
 * line-item stubs stand in for WooCommerce. Covers multi-product itemization
 * (one line per product, totals reconcile) and the per-item checkout options
 * (payment methods, installments, dynamic pricing) from the gateway settings.
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

class Stub_Line_Item {
	public $name;
	public $qty;
	public $unit;
	public $line;
	public function __construct( $name, $qty, $unit, $line ) {
		$this->name = $name;
		$this->qty  = $qty;
		$this->unit = $unit;
		$this->line = $line;
	}
	public function get_quantity() {
		return $this->qty;
	}
	public function get_name() {
		return $this->name;
	}
}

class Stub_Shipping {
	public $name;
	public $total;
	public $tax;
	public function __construct( $name, $total, $tax ) {
		$this->name  = $name;
		$this->total = $total;
		$this->tax   = $tax;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_total_tax() {
		return $this->tax;
	}
	public function get_name() {
		return $this->name;
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public $currency = 'GTQ';
		public $items    = array();
		public $shipping = array();
		public $fees     = array();
		public function get_currency() {
			return $this->currency;
		}
		public function get_items() {
			return $this->items;
		}
		public function get_shipping_methods() {
			return $this->shipping;
		}
		public function get_fees() {
			return $this->fees;
		}
		public function get_item_total( $item, $inc_tax = false, $round = false ) {
			return $item->unit;
		}
		public function get_line_total( $item, $inc_tax = false, $round = false ) {
			return $item->line;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-checkout-builder.php';

// A 2-product order (café ×2 + chocolate) plus a shipping line.
function recurrente_sample_order() {
	$order           = new WC_Order();
	$order->items    = array(
		new Stub_Line_Item( 'Café de Antigua 1lb', 2, 85.00, 170.00 ),
		new Stub_Line_Item( 'Chocolate artesanal', 1, 45.00, 45.00 ),
	);
	$order->shipping = array( new Stub_Shipping( 'Local', 32.00, 0.0 ) );
	return $order;
}

// --- Task #2: multiple products ---

it( 'multi-product: one line item per product plus shipping', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items();
	return 3 === count( $items );
} );

it( 'multi-product: amounts in cents reconcile to the order total (Q247.00)', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items();
	$total = 0;
	foreach ( $items as $i ) {
		$total += $i['amount_in_cents'] * $i['quantity'];
	}
	return 24700 === $total; // 8500*2 + 4500 + 3200
} );

it( 'multi-product: per-unit price and quantity kept when they divide evenly', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items();
	return 8500 === $items[0]['amount_in_cents'] && 2 === $items[0]['quantity'];
} );

// --- Task #1: checkout options applied per item ---

it( 'options: payment_method_types applied to every item', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order(), array( 'payment_method_types' => array( 'card', 'bank_transfer' ) ) ) )->items();
	foreach ( $items as $i ) {
		if ( array( 'card', 'bank_transfer' ) !== $i['payment_method_types'] ) {
			return false;
		}
	}
	return true;
} );

it( 'options: installments applied to one-time items in GTQ', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order(), array( 'available_installments' => array( 3, 6 ) ) ) )->items();
	foreach ( $items as $i ) {
		if ( array( 3, 6 ) !== $i['available_installments'] ) {
			return false;
		}
	}
	return true;
} );

it( 'options: installments NOT sent when currency is not GTQ', function () {
	$order           = new WC_Order();
	$order->currency = 'USD';
	$order->items    = array( new Stub_Line_Item( 'X', 1, 10.0, 10.0 ) );
	$items           = ( new Recurrente_Checkout_Builder( $order, array( 'available_installments' => array( 3 ) ) ) )->items();
	return ! isset( $items[0]['available_installments'] );
} );

it( 'options: has_dynamic_pricing applied to one-time items', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order(), array( 'has_dynamic_pricing' => true ) ) )->items();
	foreach ( $items as $i ) {
		if ( empty( $i['has_dynamic_pricing'] ) ) {
			return false;
		}
	}
	return true;
} );

it( 'options: nothing set leaves items untouched (inherit account defaults)', function () {
	$i = ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items()[0];
	return ! isset( $i['payment_method_types'] ) && ! isset( $i['available_installments'] ) && ! isset( $i['has_dynamic_pricing'] );
} );
