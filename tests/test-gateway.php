<?php
/**
 * Recurrente_Gateway tests.
 *
 * Pins where the hosted checkout sends the buyer. The checkout's "Atrás" link
 * follows cancel_url, so it must point at the order's pay page, never at
 * WooCommerce's cancel_order URL (which cancels the order and restocks it).
 */

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public function get_return_url( $order ) {
			return $order->received_url;
		}
	}
}

class Stub_Payable_Order {
	public $received_url = 'https://tienda.test/checkout/order-received/21669/?key=wc_order_abc';
	public function get_checkout_payment_url() {
		return 'https://tienda.test/checkout/order-pay/21669/?pay_for_order=true&key=wc_order_abc';
	}
	public function get_cancel_order_url_raw() {
		return 'https://tienda.test/cart/?cancel_order=true&order=wc_order_abc&order_id=21669';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-gateway.php';

function recurrente_buyer_urls( $order ) {
	$gateway = ( new ReflectionClass( 'Recurrente_Gateway' ) )->newInstanceWithoutConstructor();
	$method  = new ReflectionMethod( 'Recurrente_Gateway', 'buyer_urls' );
	$method->setAccessible( true );
	return $method->invoke( $gateway, $order );
}

it( 'buyer urls: "Atrás" returns to the order pay page', function () {
	$urls = recurrente_buyer_urls( new Stub_Payable_Order() );
	return false !== strpos( $urls['cancel_url'], 'pay_for_order=true' );
} );

it( 'buyer urls: "Atrás" never cancels the order', function () {
	$urls = recurrente_buyer_urls( new Stub_Payable_Order() );
	return false === strpos( $urls['cancel_url'], 'cancel_order' );
} );

it( 'buyer urls: success goes to the order-received page', function () {
	$order = new Stub_Payable_Order();
	return $order->received_url === recurrente_buyer_urls( $order )['success_url'];
} );
