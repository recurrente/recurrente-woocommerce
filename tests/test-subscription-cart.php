<?php
/** WCS boundary stubs; exercise the real builder and gateway preflight. */
require_once dirname( __DIR__ ) . '/includes/class-recurrente-api-client.php';

function wcs_order_contains_subscription( $order ) {
	return $order instanceof Stub_Subscription_Cart_Order;
}

function wc_add_notice( $message, $type ) {
	$GLOBALS['recurrente_cart_notices'][] = array( $message, $type );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['recurrente_cart_requests'][] = $url;
	throw new RuntimeException( 'Unexpected HTTP request during cart preflight' );
}

class Stub_Subscription_Cart_Line extends Stub_Line_Item {
	public $product_id = 10;
	public $variation_id = 0;
	public function get_product_id() {
		return $this->product_id;
	}
	public function get_variation_id() {
		return $this->variation_id;
	}
}

class Stub_Subscription_Cart_Order extends WC_Order {
	public $subscriptions = array();
	public $total = 112.0;
	public function get_id() {
		return 1011;
	}
	public function get_total() {
		return $this->total;
	}
}

class Stub_Cart_Subscription extends WC_Order {
	public $total = 112.0;
	public $signup = 0;
	public $trial_days = 0;
	public function get_total() {
		return $this->total;
	}
	public function get_sign_up_fee() {
		return $this->signup;
	}
	public function get_billing_period() {
		return 'month';
	}
	public function get_billing_interval() {
		return 1;
	}
	public function get_time( $field ) {
		return 'trial_end' === $field ? ( $this->trial_days ? 1000000 + $this->trial_days * DAY_IN_SECONDS : 0 ) : 1000000;
	}
}

function recurrente_subscription_cart() {
	$order = new Stub_Subscription_Cart_Order();
	$order->items = array( 41 => new Stub_Subscription_Cart_Line( 'Café mensual', 2, 50, 100 ) );
	$order->shipping = array( new Stub_Shipping( 'Local', 10, 2 ) );
	$subscription = new Stub_Cart_Subscription();
	// Separate item IDs are how WCS copies order items into subscriptions.
	$subscription->items = array( 93 => clone $order->items[41] );
	$subscription->shipping = array( new Stub_Shipping( 'Local', 10, 2 ) );
	$order->subscriptions = array( 77 => $subscription );
	return $order;
}

class Stub_Subscription_Cart_Gateway extends Recurrente_Gateway {
	public function get_option( $key, $default = '' ) {
		return $default;
	}
}

function recurrente_cart_is_rejected( $order, $explanation ) {
	$builder = new Recurrente_Checkout_Builder( $order );
	try {
		$builder->items();
		return false;
	} catch ( InvalidArgumentException $e ) {
		if ( false === strpos( $e->getMessage(), $explanation ) ) {
			return false;
		}
	}
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$GLOBALS['recurrente_cart_notices'] = array();
	$GLOBALS['recurrente_cart_requests'] = array();
	$gateway = ( new ReflectionClass( 'Stub_Subscription_Cart_Gateway' ) )->newInstanceWithoutConstructor();
	$result = $gateway->process_payment( $order->get_id() );
	return array( 'result' => 'failure' ) === $result
		&& array() === $GLOBALS['recurrente_cart_requests']
		&& array( array( $builder->validation_error(), 'error' ) ) === $GLOBALS['recurrente_cart_notices'];
}

it( 'subscription: recurring total includes shipping and tax exactly once, despite different WC item IDs', function () {
	$builder = new Recurrente_Checkout_Builder( recurrente_subscription_cart(), array( 'available_installments' => array( 3 ), 'has_dynamic_pricing' => true ) );
	$items = $builder->items();
	return null === $builder->validation_error() && array( array(
		'name' => 'Café mensual', 'amount_in_cents' => 11200, 'currency' => 'GTQ',
		'quantity' => 1, 'charge_type' => 'recurring', 'billing_interval' => 'month', 'billing_interval_count' => 1,
	) ) === $items;
} );

it( 'subscription: mixed product is rejected before customer or checkout HTTP, even if free', function () {
	$order = recurrente_subscription_cart();
	$extra = new Stub_Subscription_Cart_Line( 'Regalo de pago único', 1, 0, 0 );
	$extra->product_id = 20;
	$order->items[] = $extra;
	return recurrente_cart_is_rejected( $order, 'productos de pago único' );
} );

it( 'subscription: paid mixed product cannot silently disappear', function () {
	$order = recurrente_subscription_cart();
	$extra = new Stub_Subscription_Cart_Line( 'Chocolate', 1, 25, 25 );
	$extra->product_id = 20;
	$order->items[] = $extra;
	$order->total += 25;
	return recurrente_cart_is_rejected( $order, 'productos de pago único' );
} );

it( 'subscription: variation or quantity mismatch is rejected even with identical totals', function () {
	foreach ( array( 'variation_id', 'qty' ) as $field ) {
		$order = recurrente_subscription_cart();
		$order->items[41]->$field = 3;
		if ( ! recurrente_cart_is_rejected( $order, 'productos de pago único' ) ) {
			return false;
		}
	}
	return true;
} );

it( 'subscription: multiple WCS schedules are rejected before HTTP', function () {
	$order = recurrente_subscription_cart();
	$order->subscriptions[78] = clone $order->subscriptions[77];
	return recurrente_cart_is_rejected( $order, 'una suscripción' );
} );

it( 'subscription: missing parent subscription fails closed', function () {
	$order = recurrente_subscription_cart();
	$order->subscriptions = array();
	return recurrente_cart_is_rejected( $order, 'una suscripción' );
} );

it( 'subscription: signup fee is rejected instead of sending an unsupported mixed checkout', function () {
	$order = recurrente_subscription_cart();
	$order->subscriptions[77]->signup = 25;
	$order->total += 25;
	return recurrente_cart_is_rejected( $order, 'cuotas de inscripción' );
} );

it( 'subscription: initial shipping, tax, fee or discount differences fail closed', function () {
	foreach ( array( 113.0, 111.0, 112.01 ) as $initial_total ) {
		$order = recurrente_subscription_cart();
		$order->total = $initial_total;
		if ( ! recurrente_cart_is_rejected( $order, 'total inicial' ) ) {
			return false;
		}
	}
	return true;
} );

it( 'subscription: currency mismatch fails closed', function () {
	$order = recurrente_subscription_cart();
	$order->subscriptions[77]->currency = 'USD';
	return recurrente_cart_is_rejected( $order, 'total inicial' );
} );

it( 'subscription: zero initial trial total preserves recurring total without adding shipping again', function () {
	$order = recurrente_subscription_cart();
	$order->total = 0;
	$order->subscriptions[77]->trial_days = 7;
	$items = ( new Recurrente_Checkout_Builder( $order ) )->items();
	return 1 === count( $items ) && 11200 === $items[0]['amount_in_cents'] && 7 === $items[0]['free_trial_interval_count'];
} );

it( 'subscription: trial with upfront shipping is rejected before HTTP', function () {
	$order = recurrente_subscription_cart();
	$order->total = 12;
	$order->subscriptions[77]->trial_days = 7;
	return recurrente_cart_is_rejected( $order, 'total inicial' );
} );


it( 'subscription: gateway does not advertise multiple subscriptions', function () {
	$gateway = ( new ReflectionClass( 'Stub_Subscription_Cart_Gateway' ) )->newInstanceWithoutConstructor();
	$method = new ReflectionMethod( 'Recurrente_Gateway', 'supported_features' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$supports = $method->invoke( $gateway );
	return in_array( 'subscriptions', $supports, true ) && ! in_array( 'multiple_subscriptions', $supports, true );
} );
