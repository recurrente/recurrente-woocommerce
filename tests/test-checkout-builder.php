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
	public $tax;
	public function __construct( $name, $qty, $unit, $line, $tax = 0 ) {
		$this->name = $name;
		$this->qty  = $qty;
		$this->unit = $unit;
		$this->line = $line;
		$this->tax  = $tax;
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
		public $total    = null;
		public $items    = array();
		public $shipping = array();
		public $fees     = array();
		public function get_total() {
			if ( null !== $this->total ) {
				return $this->total;
			}
			$total = array_sum( array_map( function ( $item ) { return $item->line + $item->tax; }, $this->items ) );
			foreach ( array_merge( $this->shipping, $this->fees ) as $item ) {
				$total += $item->get_total() + $item->get_total_tax();
			}
			return round( $total, 2 );
		}

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
			return $item->unit + ( $inc_tax ? $item->tax / $item->qty : 0 );
		}
		public function get_line_total( $item, $inc_tax = false, $round = false ) {
			return $item->line + ( $inc_tax ? $item->tax : 0 );
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

function recurrente_items_total( $items ) {
	return array_sum( array_map( function ( $item ) {
		return $item['amount_in_cents'] * $item['quantity'];
	}, $items ) );
}

it( 'negative fee: Q100 minus Q10 produces one positive Q90 item with checkout options', function () {
	$order        = new WC_Order();
	$order->items = array( new Stub_Line_Item( 'Producto', 1, 100, 100 ) );
	$order->fees  = array( new Stub_Shipping( 'Descuento', -10, 0 ) );
	$order->total = '90.00';
	$items = ( new Recurrente_Checkout_Builder( $order, array( 'available_installments' => array( 3 ) ) ) )->items();
	return 1 === count( $items ) && 9000 === $items[0]['amount_in_cents'] && 1 === $items[0]['quantity']
		&& array( 3 ) === $items[0]['available_installments'];
} );

it( 'tax, shipping and positive and negative fees are included exactly once', function () {
	$order           = new WC_Order();
	$order->items    = array( new Stub_Line_Item( 'Producto con IVA', 2, 50, 100, 12 ) );
	$order->shipping = array( new Stub_Shipping( 'Local', 20, 2.40 ) );
	$order->fees     = array( new Stub_Shipping( 'Descuento', -10, -1.20 ), new Stub_Shipping( 'Servicio', 5, 0.60 ) );
	$order->total    = '128.80';
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 1 === count( $items ) && 12880 === recurrente_items_total( $items );
} );

it( 'ordinary coupon: discounted line totals stay itemized without discounting twice', function () {
	$order           = new WC_Order();
	$order->items    = array( new Stub_Line_Item( 'Producto con cupón e IVA', 2, 45, 90, 10.80 ) );
	$order->shipping = array( new Stub_Shipping( 'Local', 20, 2.40 ) );
	$order->total    = '123.20';
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 2 === count( $items ) && 2 === $items[0]['quantity'] && 5040 === $items[0]['amount_in_cents']
		&& 12320 === recurrente_items_total( $items );
} );

it( 'quantity: uneven division collapses a line without losing a cent', function () {
	$order        = new WC_Order();
	$order->items = array( new Stub_Line_Item( 'Producto', 3, 10 / 3, 10 ) );
	$order->total = '10.00';
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 1 === $items[0]['quantity'] && 1000 === recurrente_items_total( $items );
} );

it( 'quantity: more than nine units collapse without losing the total', function () {
	$order        = new WC_Order();
	$order->items = array( new Stub_Line_Item( 'Producto', 12, 10, 120 ) );
	$order->total = '120.00';
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 1 === $items[0]['quantity'] && 12000 === recurrente_items_total( $items );
} );

foreach ( array( array( 10.004, '20.01' ), array( 10.005, '20.01' ) ) as $rounding_case ) {
	it( 'rounding: reconcile independent line rounding for ' . $rounding_case[0], function () use ( $rounding_case ) {
		$order        = new WC_Order();
		$order->items = array( new Stub_Line_Item( 'A', 1, $rounding_case[0], $rounding_case[0] ), new Stub_Line_Item( 'B', 1, $rounding_case[0], $rounding_case[0] ) );
		$order->total = $rounding_case[1];
		$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
		return 1 === count( $items ) && 2001 === recurrente_items_total( $items );
	} );
}

it( 'zero-price gifts and free shipping do not produce zero-price API lines', function () {
	$order           = recurrente_sample_order();
	$order->items[]  = new Stub_Line_Item( 'Regalo', 1, 0, 0 );
	$order->shipping = array( new Stub_Shipping( 'Gratis', 0, 0 ) );
	$order->total    = '215.00';
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 2 === count( $items ) && 21500 === recurrente_items_total( $items );
} );
