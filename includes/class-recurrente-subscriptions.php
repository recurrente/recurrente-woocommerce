<?php
/**
 * WooCommerce Subscriptions integration.
 *
 * Recurrente owns the billing cycle (like Stripe Checkout's subscription mode),
 * NOT WooCommerce. So WC must not try to auto-charge renewals — the scheduled
 * payment hook is a deliberate no-op. Instead, Recurrente sends webhooks that
 * we translate into WC subscription status changes and renewal orders.
 *
 * The class is all static so the webhook handler can call it without state.
 *
 * OPEN QUESTION (needs live verification): which event fires on a *successful
 * renewal charge*? We assume a `payment_intent.succeeded` carrying the parent
 * order's metadata, handled here by record_renewal(). Confirm against a real
 * live payload before release.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Subscriptions {

	public static function register_hooks() {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . Recurrente_Gateway::ID, array( __CLASS__, 'skip_scheduled_payment' ) );
	}

	private static function active() {
		return class_exists( 'WC_Subscriptions' );
	}

	/**
	 * Recurrente charges renewals itself, so WooCommerce should not. We log and
	 * wait for Recurrente's paid webhook, which records the renewal order.
	 */
	public static function skip_scheduled_payment( $amount ) {
		Recurrente_Logger::debug( 'scheduled payment skipped; Recurrente drives renewals', array( 'amount' => $amount ) );
	}

	public static function activate( $order ) {
		self::set_status( $order, 'active' );
	}

	public static function cancel( $order ) {
		self::set_status( $order, 'cancelled' );
	}

	public static function on_hold( $order ) {
		self::set_status( $order, 'on-hold' );
	}

	public static function reactivate( $order ) {
		self::set_status( $order, 'active' );
	}

	private static function set_status( $order, $status ) {
		foreach ( self::subscriptions_for( $order ) as $subscription ) {
			self::update_status( $subscription, $status );
		}
	}

	private static function update_status( $subscription, $status ) {
		if ( $subscription->has_status( $status ) ) {
			return;
		}
		$subscription->update_status( $status, __( 'Actualizado por webhook de Recurrente.', 'recurrente-for-woocommerce' ) );
	}

	/**
	 * A paid webhook on an already-paid subscription parent is a renewal: create
	 * the WC renewal order and complete it so WC's history mirrors Recurrente.
	 */
	public static function record_renewal( $order ) {
		foreach ( self::subscriptions_for( $order ) as $subscription ) {
			self::create_paid_renewal( $subscription );
		}
	}

	private static function create_paid_renewal( $subscription ) {
		$renewal = wcs_create_renewal_order( $subscription );
		$renewal->set_payment_method( Recurrente_Gateway::ID );
		$renewal->payment_complete();
	}

	private static function subscriptions_for( $order ) {
		if ( ! self::active() ) {
			return array();
		}
		return wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) );
	}
}
