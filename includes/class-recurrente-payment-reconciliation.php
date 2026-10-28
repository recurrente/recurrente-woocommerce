<?php
/** Read-only provider recovery; the existing webhook binding owns fulfillment. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Payment_Reconciliation {
	const CHECKED_AT = '_recurrente_reconciliation_checked_at';
	const COOLDOWN = 60;

	public static function eligible( $order ) {
		return $order && ! $order->is_paid() && 'recurrente' === $order->get_payment_method()
			&& $order->has_status( array( 'pending', 'on-hold' ) )
			&& is_string( $order->get_meta( '_recurrente_checkout_id' ) )
			&& '' !== $order->get_meta( '_recurrente_checkout_id' );
	}

	/** Claim the lookup under the same lock as checkout replacement and webhooks. */
	public static function reconcile( $order_id ) {
		try {
			$lookup = Recurrente_Order_Lock::run( $order_id, array( __CLASS__, 'claim_lookup' ) );
			return is_array( $lookup ) ? self::lookup( $order_id, $lookup ) : $lookup;
		} catch ( Recurrente_API_Exception $e ) {
			return 'unavailable';
		} catch ( Recurrente_Order_Locked $e ) {
			return 'unavailable';
		} catch ( Recurrente_Charge_Locked $e ) {
			return 'unavailable';
		}
	}

	public static function claim_lookup( $order ) {
		if ( ! self::eligible( $order ) ) { return 'skipped'; }
		if ( (int) $order->get_meta( self::CHECKED_AT ) > time() - self::COOLDOWN ) { return 'recent'; }
		$order->update_meta_data( self::CHECKED_AT, time() );
		$order->save();
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		return array( 'checkout_id' => $order->get_meta( '_recurrente_checkout_id' ), 'mode' => is_array( $binding ) ? ( $binding['mode'] ?? null ) : null );
	}

	private static function lookup( $order_id, $lookup ) {
		$settings = get_option( 'woocommerce_recurrente_settings', array() );
		$modes = null === $lookup['mode'] ? array( 'live', 'test' ) : array( $lookup['mode'] );
		foreach ( $modes as $mode ) {
			if ( ! in_array( $mode, array( 'live', 'test' ), true ) || empty( $settings[ $mode . '_secret_key' ] ) ) { continue; }
			$client = new Recurrente_API_Client( $settings[ $mode . '_secret_key' ] );
			$checkout = self::find_checkout( $client, $lookup['checkout_id'] );
			if ( null === $checkout ) { continue; }
			return self::recover( $order_id, $lookup['checkout_id'], $mode, $client, $checkout );
		}
		return 'unavailable';
	}

	/** A legacy order's environment is discovered by the authenticated GET, never today's toggle. */
	private static function find_checkout( $client, $id ) {
		try {
			return $client->get_checkout( $id, 10 );
		} catch ( Recurrente_API_Exception $e ) {
			if ( 404 === $e->getCode() ) { return null; }
			throw $e;
		}
	}

	private static function recover( $order_id, $expected, $mode, $client, $checkout ) {
		if ( $expected !== ( $checkout['id'] ?? null ) || ( 'live' === $mode ) !== ( $checkout['live_mode'] ?? null ) ) { return 'mismatch'; }
		if ( 'paid' !== ( $checkout['status'] ?? null ) ) { return 'pending'; }
		$id = $checkout['latest_intent']['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '/^in_[a-zA-Z0-9]+$/D', $id ) ) { return 'unsupported'; }
		$intent = $client->get_intent( $id );
		if ( ! self::same_checkout( $checkout, $intent['checkout'] ?? null ) || $id !== ( $intent['id'] ?? null ) ) { return 'mismatch'; }
		return Recurrente_Webhook_Handler::reconcile_payment( $order_id, $mode, $intent ) ? 'paid' : 'mismatch';
	}

	private static function same_checkout( $checkout, $nested ) {
		if ( ! is_array( $nested ) ) { return false; }
		foreach ( array( 'id', 'status', 'live_mode', 'currency', 'total_in_cents', 'metadata' ) as $field ) {
			if ( ! array_key_exists( $field, $checkout ) || ! array_key_exists( $field, $nested ) || $checkout[ $field ] !== $nested[ $field ] ) { return false; }
		}
		return ( $checkout['latest_intent']['id'] ?? null ) === ( $nested['latest_intent']['id'] ?? null );
	}
}
