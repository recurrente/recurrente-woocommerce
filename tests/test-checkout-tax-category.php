<?php
/** Fiscal classification changes the final payload, never its monetary fields. */

require_once __DIR__ . '/stubs-wordpress.php';

it( 'fiscal: no callback preserves the existing payload exactly', function () {
	$items = ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items();
	return array( 'name' => 'Café de Antigua 1lb', 'amount_in_cents' => 8500, 'currency' => 'GTQ', 'quantity' => 2, 'charge_type' => 'one_time' ) === $items[0]
		&& ! isset( $items[1]['tax_category'] ) && ! isset( $items[2]['tax_category'] );
} );

it( 'fiscal: service reaches products, shipping and fees with options and totals intact', function () {
	$order = recurrente_sample_order();
	$order->fees = array( new Stub_Shipping( 'Reserva', 5, 0 ) );
	$options = array( 'payment_method_types' => array( 'card' ), 'available_installments' => array( 3 ) );
	$before = ( new Recurrente_Checkout_Builder( $order, $options ) )->items();
	$calls = 0;
	return recurrente_with_tax_filter( function ( $category, $item, $source_order ) use ( $order, &$calls ) {
		if ( null !== $category || $order !== $source_order || array( 'card' ) !== $item['payment_method_types'] ) {
			throw new RuntimeException( 'Filter did not receive the final item and originating order.' );
		}
		$calls++;
		return 'service';
	}, function () use ( $order, $options, $before, &$calls ) {
		$after = ( new Recurrente_Checkout_Builder( $order, $options ) )->items();
		foreach ( $before as $index => $item ) {
			$item['tax_category'] = 'service';
			if ( $item !== $after[ $index ] ) { return false; }
		}
		return 4 === $calls && 25200 === recurrente_items_total( $after );
	} );
} );

it( 'fiscal: a callback can keep goods and services distinct in an itemized order', function () {
	$order = new WC_Order();
	$order->items = array( new Stub_Line_Item( 'Álbum', 1, 100, 100 ), new Stub_Line_Item( 'Sesión', 1, 200, 200 ) );
	return recurrente_with_tax_filter( function ( $category, $item ) {
		return 'Sesión' === $item['name'] ? 'service' : 'good';
	}, function () use ( $order ) {
		$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
		return 'good' === $items[0]['tax_category'] && 'service' === $items[1]['tax_category'] && 30000 === recurrente_items_total( $items );
	} );
} );

it( 'fiscal: a negative fee classifies only the final reconciled order line', function () {
	$order = new WC_Order();
	$order->items = array( new Stub_Line_Item( 'Sesión', 1, 100, 100 ) );
	$order->fees = array( new Stub_Shipping( 'Descuento', -10, 0 ) );
	$calls = 0;
	return recurrente_with_tax_filter( function ( $category, $item ) use ( &$calls ) {
		$calls++;
		if ( 'Pedido #1042' !== $item['name'] ) { throw new RuntimeException( 'Filtered an intermediate line.' ); }
		return 'service';
	}, function () use ( $order, &$calls ) {
		$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
		return 1 === $calls && 1 === count( $items ) && 9000 === recurrente_items_total( $items ) && 'service' === $items[0]['tax_category'];
	} );
} );

it( 'fiscal: collapsed quantity remains classified in USD', function () {
	$order = new WC_Order();
	$order->currency = 'USD';
	$order->items = array( new Stub_Line_Item( 'Sesión', 12, 10, 120 ) );
	return recurrente_with_tax_filter( function () { return 'service'; }, function () use ( $order ) {
		$item = ( new Recurrente_Checkout_Builder( $order ) )->items()[0];
		return 'service' === $item['tax_category'] && 'USD' === $item['currency'] && 1 === $item['quantity'] && 12000 === $item['amount_in_cents'];
	} );
} );

it( 'fiscal: a null callback result omits the field', function () {
	return recurrente_with_tax_filter( function ( $category ) { return $category; }, function () {
		foreach ( ( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items() as $item ) {
			if ( array_key_exists( 'tax_category', $item ) ) { return false; }
		}
		return true;
	} );
} );

it( 'fiscal: invalid classifications fail closed instead of silently invoicing as goods', function () {
	foreach ( array( 'Servicio', '', false, array( 'service' ) ) as $invalid ) {
		$rejected = recurrente_with_tax_filter( function () use ( $invalid ) { return $invalid; }, function () {
			try {
				( new Recurrente_Checkout_Builder( recurrente_sample_order() ) )->items();
			} catch ( UnexpectedValueException $e ) {
				return false !== strpos( $e->getMessage(), 'tax_category' );
			}
			return false;
		} );
		if ( ! $rejected ) { return false; }
	}
	return true;
} );

class Stub_Fiscal_Subscription_Order extends WC_Order {
	public $subscriptions = array();
}

class Stub_Fiscal_Subscription_Builder extends Recurrente_Checkout_Builder {
	public function contains_subscription() { return true; }
}

it( 'fiscal: recurring charges and sign-up fees both receive the classification', function () {
	$order = new Stub_Fiscal_Subscription_Order();
	$order->subscriptions = array( new class {
		public function get_total() { return 100; }
		public function get_items() { return array( new Stub_Line_Item( 'Sesión mensual', 1, 100, 100 ) ); }
		public function get_billing_period() { return 'month'; }
		public function get_billing_interval() { return 1; }
		public function get_time( $key ) { return 0; }
		public function get_sign_up_fee() { return 25; }
	} );
	$options = array( 'payment_method_types' => array( 'card' ), 'available_installments' => array( 3 ) );
	return recurrente_with_tax_filter( function () { return 'service'; }, function () use ( $order, $options ) {
		$items = ( new Stub_Fiscal_Subscription_Builder( $order, $options ) )->items();
		return 2 === count( $items ) && 'service' === $items[0]['tax_category'] && 'service' === $items[1]['tax_category']
			&& 'recurring' === $items[0]['charge_type'] && 'month' === $items[0]['billing_interval']
			&& ! isset( $items[0]['available_installments'] ) && array( 'card' ) === $items[0]['payment_method_types']
			&& 'one_time' === $items[1]['charge_type'] && 2500 === $items[1]['amount_in_cents']
			&& array( 3 ) === $items[1]['available_installments'] && 12500 === recurrente_items_total( $items );
	} );
} );
