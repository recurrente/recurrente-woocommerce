<?php
/**
 * Turns a WC_Order into the itemized `items` array for POST /checkouts.
 *
 * We itemize from the order's authoritative totals (never browser-supplied
 * prices) so the Recurrente total always reconciles to the WooCommerce total.
 * All amounts include tax, so GT stores with tax-inclusive pricing need no
 * separate tax line.
 *
 * Recurrente caps inline `quantity` at 9. A line with quantity > 9 (or whose
 * unit price doesn't divide evenly into the line total) is collapsed into a
 * single quantity-1 item carrying the full line total, with "× N" in the name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Checkout_Builder {

	const MAX_QUANTITY      = 9;
	const SUPPORTED_CURRENCIES = array( 'GTQ', 'USD' );

	private $order;
	private $options;

	/**
	 * @param array $options Per-item checkout config from the gateway settings:
	 *   payment_method_types (array), available_installments (array),
	 *   has_dynamic_pricing (bool). Omitted keys inherit the account defaults.
	 */
	public function __construct( WC_Order $order, array $options = array() ) {
		$this->order   = $order;
		$this->options = $options;
	}

	public function currency() {
		return $this->order->get_currency();
	}

	public function supported_currency() {
		return in_array( $this->currency(), self::SUPPORTED_CURRENCIES, true );
	}

	/**
	 * Itemized payload, dispatched by order type. Subscription orders build
	 * `recurring` items so Recurrente owns the billing cycle; everything else
	 * is a one-time sale.
	 */
	public function items() {
		$items = $this->contains_subscription() ? $this->subscription_items() : $this->one_time_items();
		return array_map( array( $this, 'apply_options' ), $items );
	}

	/**
	 * Decorate a line with the merchant's checkout config. payment_method_types
	 * applies to every item; installments and dynamic pricing are one-time-only
	 * per the API (and installments only in GTQ).
	 */
	private function apply_options( $item ) {
		if ( isset( $this->options['payment_method_types'] ) ) {
			$item['payment_method_types'] = $this->options['payment_method_types'];
		}
		if ( 'one_time' === $item['charge_type'] ) {
			if ( isset( $this->options['available_installments'] ) && 'GTQ' === $this->currency() ) {
				$item['available_installments'] = $this->options['available_installments'];
			}
			if ( ! empty( $this->options['has_dynamic_pricing'] ) ) {
				$item['has_dynamic_pricing'] = true;
			}
		}
		return $item;
	}

	public function contains_subscription() {
		return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $this->order );
	}

	/**
	 * Recurring items, one per subscription, plus its one-time sign-up fee.
	 * WC's billing periods (day/week/month/year) map 1:1 to Recurrente's.
	 */
	private function subscription_items() {
		$subscriptions = wcs_get_subscriptions_for_order( $this->order, array( 'order_type' => 'parent' ) );
		$items         = array();
		foreach ( $subscriptions as $subscription ) {
			$items[] = $this->recurring_item( $subscription );
			$items   = array_merge( $items, $this->sign_up_fee_item( $subscription ) );
		}
		return $items;
	}

	private function recurring_item( $subscription ) {
		$item = array(
			'name'                   => $this->subscription_name( $subscription ),
			'amount_in_cents'        => $this->to_cents( $subscription->get_total() ),
			'currency'               => $this->currency(),
			'quantity'               => 1,
			'charge_type'            => 'recurring',
			'billing_interval'       => $subscription->get_billing_period(),
			'billing_interval_count' => (int) $subscription->get_billing_interval(),
		);
		return array_merge( $item, $this->free_trial( $subscription ) );
	}

	private function subscription_name( $subscription ) {
		$names = array_map( function ( $item ) {
			return $item->get_name();
		}, $subscription->get_items() );
		return implode( ', ', $names );
	}

	private function free_trial( $subscription ) {
		$days = $subscription->get_time( 'trial_end' ) ? $this->trial_days( $subscription ) : 0;
		if ( $days <= 0 ) {
			return array();
		}
		return array(
			'free_trial_interval'       => 'day',
			'free_trial_interval_count' => $days,
		);
	}

	private function trial_days( $subscription ) {
		$start = $subscription->get_time( 'date_created' );
		$end   = $subscription->get_time( 'trial_end' );
		return (int) ceil( ( $end - $start ) / DAY_IN_SECONDS );
	}

	private function sign_up_fee_item( $subscription ) {
		$cents = $this->to_cents( $subscription->get_sign_up_fee() );
		if ( $cents <= 0 ) {
			return array();
		}
		return array( $this->inline_item( __( 'Cuota de inscripción', 'recurrente-for-woocommerce' ), $cents, 1 ) );
	}

	/**
	 * One-time checkout items: products + shipping + fees, all tax-inclusive.
	 */
	public function one_time_items() {
		$items = array_map( array( $this, 'product_item' ), $this->order->get_items() );
		return array_merge( array_values( $items ), $this->shipping_items(), $this->fee_items() );
	}

	private function product_item( $item ) {
		$quantity   = $item->get_quantity();
		$unit_cents = $this->to_cents( $this->order->get_item_total( $item, true, false ) );
		$line_cents = $this->to_cents( $this->order->get_line_total( $item, true, false ) );
		return $this->collapsible_item( $item->get_name(), $quantity, $unit_cents, $line_cents );
	}

	/**
	 * Keep per-unit quantity when it's <= 9 and divides the line total exactly;
	 * otherwise collapse to one line so the total still reconciles.
	 */
	private function collapsible_item( $name, $quantity, $unit_cents, $line_cents ) {
		if ( $quantity <= self::MAX_QUANTITY && ( $unit_cents * $quantity ) === $line_cents ) {
			return $this->inline_item( $name, $unit_cents, $quantity );
		}
		return $this->inline_item( "{$name} × {$quantity}", $line_cents, 1 );
	}

	private function shipping_items() {
		$items = array();
		foreach ( $this->order->get_shipping_methods() as $shipping ) {
			$cents = $this->to_cents( $shipping->get_total() + $shipping->get_total_tax() );
			/* translators: %s: nombre del método de envío */
			$name  = sprintf( __( 'Envío: %s', 'recurrente-for-woocommerce' ), $shipping->get_name() );
			$items[] = $this->inline_item( $name, $cents, 1 );
		}
		return $this->reject_zero( $items );
	}

	private function fee_items() {
		$items = array();
		foreach ( $this->order->get_fees() as $fee ) {
			$cents   = $this->to_cents( $fee->get_total() + $fee->get_total_tax() );
			$items[] = $this->inline_item( $fee->get_name(), $cents, 1 );
		}
		return $this->reject_zero( $items );
	}

	private function inline_item( $name, $amount_in_cents, $quantity ) {
		return array(
			'name'            => $name,
			'amount_in_cents' => $amount_in_cents,
			'currency'        => $this->currency(),
			'quantity'        => $quantity,
			'charge_type'     => 'one_time',
		);
	}

	private function reject_zero( $items ) {
		return array_values( array_filter( $items, function ( $item ) {
			return $item['amount_in_cents'] > 0;
		} ) );
	}

	private function to_cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}
}
