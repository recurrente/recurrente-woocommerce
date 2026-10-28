<?php
/** Drive process_payment through the real builder, payload, client and redirect.
 * Only the WP/WC runtime and outbound WordPress HTTP boundary are stand-ins.
 */
require_once __DIR__ . '/stubs-renewals.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-api-client.php';

class Stub_Checkout_Order extends WC_Order {
	public $meta = array();
	public $status = 'pending';
	public $saved = false;
	public $received_url = 'https://tienda.test/received';
	public function get_id() { return 321; }
	public function get_data_store() { return new Stub_Binding_Order_Data_Store(); }
	public function read_meta_data( $force = false ) {}
	public function is_paid() { return false; }
	public function get_order_key() { return 'wc_order_test'; }
	public function get_checkout_payment_url() { return 'https://tienda.test/pay'; }
	public function get_billing_email() { return 'buyer@example.test'; }
	public function get_formatted_billing_full_name() { return 'Test Buyer'; }
	public function get_billing_phone() { return ''; }
	public function get_billing_address_1() { return ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function update_status( $status, $note ) { $this->status = $status; }
	public function save() { $this->saved = true; }
}

class Stub_Checkout_Gateway extends Recurrente_Gateway {
	public function get_option( $key, $default = '' ) {
		$options = array( 'test_mode' => 'yes', 'test_secret_key' => 'sk_test_fixture' );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}
}

function recurrente_process_test_order( $order ) {
	recurrente_reset_renewal_world();
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_checkout_requests'] = array();
	$GLOBALS['recurrente_binding_notices'] = array();
	$GLOBALS['recurrente_binding_http'] = array();
	$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) use ( $order ) {
		$GLOBALS['recurrente_checkout_requests'][] = array( 'url' => $url, 'args' => $args );
		$body = substr( $url, -10 ) === '/customers'
			? array( 'id' => 'cus_test' )
			: array( 'id' => 'ch_test', 'checkout_url' => 'https://pay.example.test/ch_test', 'total_in_cents' => (int) round( $order->get_total() * 100 ) );
		return array( 'response' => array( 'code' => 201 ), 'body' => json_encode( $body ) );
	};
	$gateway = ( new ReflectionClass( 'Stub_Checkout_Gateway' ) )->newInstanceWithoutConstructor();
	try {
		return $gateway->process_payment( $order->get_id() );
	} finally {
		unset( $GLOBALS['recurrente_binding_response_for_request'] );
	}
}

it( 'gateway: negative fee reaches POST /checkouts as Q90 and stores checkout before redirect', function () {
	$order        = new Stub_Checkout_Order();
	$order->items = array( new Stub_Line_Item( 'Producto', 1, 100, 100 ) );
	$order->fees  = array( new Stub_Shipping( 'Descuento', -10, 0 ) );
	$order->total = '90.00';
	$result = recurrente_process_test_order( $order );
	$requests = $GLOBALS['recurrente_checkout_requests'];
	$payload = json_decode( $requests[1]['args']['body'], true );
	return 'success' === $result['result'] && 'https://pay.example.test/ch_test' === $result['redirect']
		&& 2 === count( $requests ) && Recurrente_API_Client::BASE_URL . '/customers' === $requests[0]['url']
		&& Recurrente_API_Client::BASE_URL . '/checkouts' === $requests[1]['url']
		&& 'cus_test' === $payload['customer_id'] && 9000 === recurrente_items_total( $payload['items'] )
		&& 'ch_test' === $order->meta['_recurrente_checkout_id'] && $order->saved && 'pending' === $order->status
		&& 9000 === $order->meta['_recurrente_payment_binding']['amount']
		&& 9000 === $order->meta['_recurrente_payment_binding']['base_amount']
		&& $payload['metadata']['wc_payment_attempt'] === $order->meta['_recurrente_payment_binding']['attempt'];
} );

foreach ( array( '0.00', '-10.00', '89.00', '101.00', '100.02' ) as $invalid_total ) {
	it( 'gateway: total ' . $invalid_total . ' fails before customer or checkout HTTP and order mutation', function () use ( $invalid_total ) {
		$order        = new Stub_Checkout_Order();
		$order->items = array( new Stub_Line_Item( 'Producto', 1, 100, 100 ) );
		$order->total = $invalid_total;
		$result = recurrente_process_test_order( $order );
		$notice = $GLOBALS['recurrente_binding_notices'][0];
		return array( 'result' => 'failure' ) === $result && ! $GLOBALS['recurrente_checkout_requests']
			&& 1 === count( $GLOBALS['recurrente_binding_notices'] ) && ! $order->saved && ! $order->meta
			&& 'No pudimos iniciar el pago con Recurrente. Elegí otro método de pago o contactá a la tienda.' === $notice
			&& 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'no coincide con la suma' );
	} );
}

it( 'gateway: fully discounted order fails without creating a customer', function () {
	$order        = new Stub_Checkout_Order();
	$order->items = array( new Stub_Line_Item( 'Producto', 1, 100, 100 ) );
	$order->fees  = array( new Stub_Shipping( 'Descuento', -100, 0 ) );
	$order->total = '0.00';
	return array( 'result' => 'failure' ) === recurrente_process_test_order( $order ) && ! $GLOBALS['recurrente_checkout_requests'];
} );

it( 'gateway: empty positive order fails before any HTTP', function () {
	$order        = new Stub_Checkout_Order();
	$order->total = '10.00';
	return array( 'result' => 'failure' ) === recurrente_process_test_order( $order ) && ! $GLOBALS['recurrente_checkout_requests'];
} );
