<?php
/**
 * Persists the link between a Recurrente charge (payment intent) and the
 * WooCommerce order it produced, so a charge creates at most one order.
 *
 * Svix delivers at least once and the webhook endpoint can run twice at the
 * same time (retry racing a slow first delivery, or a redelivery from the
 * dashboard). Stripe's guidance for this is to process by the event's object
 * id idempotently; here that id is the intent id, persisted on the order as
 * `_recurrente_intent_id` BEFORE any side effect, under a per-charge MySQL
 * lock (GET_LOCK). The lock covers concurrency; the persisted link covers a
 * retry after a failure (the retry finds the order and completes it instead
 * of creating a second one). The 24h svix-id transient in the webhook handler
 * remains as a fast "already processed" layer on top, not as the only guard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Thrown when another delivery holds the charge lock; the handler answers non-2xx so Svix retries. */
class Recurrente_Charge_Locked extends RuntimeException {}

class Recurrente_Charge_Order_Link {

	const META_KEY       = '_recurrente_intent_id';
	const SCOPE_META_KEY = '_recurrente_intent_scope'; // Which WC subscription of the order this charge's order belongs to.
	const LOCK_PREFIX    = 'recurrente_charge_';
	const LOCK_TIMEOUT   = 10; // Seconds to wait for a concurrent delivery before giving up.

	/**
	 * Returns the order linked to $charge_id, creating it with $create when none
	 * exists. $scope distinguishes orders the same charge legitimately produces
	 * (one per WC subscription). The link is written before returning, so any
	 * side effect the caller runs afterwards is retry-safe.
	 *
	 * @param string   $charge_id Recurrente intent id (or svix-id as a fallback).
	 * @param string   $scope     Subscription id or any other discriminator.
	 * @param callable $create    Returns a saved WC order. Called at most once per charge+scope.
	 * @return WC_Order
	 * @throws Recurrente_Charge_Locked when the lock is held by another delivery.
	 */
	public static function find_or_create( $charge_id, $scope, callable $create ) {
		return self::locked( $charge_id, $scope, function () use ( $charge_id, $scope, $create ) {
			return self::find_or_link( $charge_id, $scope, $create );
		} );
	}

	/**
	 * Links $charge_id to an order that already exists (the checkout's own
	 * order receiving its first charge), under the same lock, so the parent
	 * records its charge the way a renewal order does. Scope is empty: a first
	 * charge belongs to exactly one order.
	 */
	public static function claim( $charge_id, $order ) {
		return self::locked( $charge_id, '', function () use ( $charge_id, $order ) {
			return self::link( $charge_id, '', $order );
		} );
	}

	/** The charge an order was linked to, or '' when it was paid before this link existed. */
	public static function charge_of( $order ) {
		return (string) $order->get_meta( self::META_KEY );
	}

	private static function locked( $charge_id, $scope, callable $body ) {
		$lock = self::lock_name( $charge_id, $scope );
		self::acquire( $lock );
		try {
			return $body();
		} finally {
			self::release( $lock );
		}
	}

	private static function find_or_link( $charge_id, $scope, callable $create ) {
		$existing = self::find( $charge_id, $scope );
		if ( $existing ) {
			Recurrente_Logger::debug( 'charge already linked to an order', array( 'charge' => $charge_id, 'order' => $existing->get_id() ) );
			return $existing;
		}
		return self::link( $charge_id, $scope, $create() );
	}

	/** Looks the charge up by meta; the meta key is indexed in both CPT and HPOS storage. */
	private static function find( $charge_id, $scope ) {
		foreach ( self::orders_for_charge( $charge_id ) as $order ) {
			if ( self::scope_of( $order ) === (string) $scope ) {
				return $order;
			}
		}
		return null;
	}

	private static function orders_for_charge( $charge_id ) {
		return wc_get_orders( array(
			'limit'      => -1,
			'status'     => 'any',
			'meta_query' => array( array( 'key' => self::META_KEY, 'value' => (string) $charge_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
	}

	private static function link( $charge_id, $scope, $order ) {
		$order->update_meta_data( self::META_KEY, (string) $charge_id );
		$order->update_meta_data( self::SCOPE_META_KEY, (string) $scope );
		$order->save();
		return $order;
	}

	private static function scope_of( $order ) {
		return (string) $order->get_meta( self::SCOPE_META_KEY );
	}

	private static function lock_name( $charge_id, $scope ) {
		return self::LOCK_PREFIX . md5( $charge_id . '|' . $scope ); // Under MySQL's 64-char lock name limit.
	}

	/**
	 * GET_LOCK is released by MySQL itself if the request dies, so a crash never
	 * leaves a stale lock. A timeout (0) means another delivery is still working
	 * on this charge: we bail and let Svix retry. NULL (lock unsupported, e.g.
	 * SQLite) degrades to the persisted link alone, which still de-dupes retries.
	 */
	private static function acquire( $lock ) {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );
		if ( '0' === (string) $got ) {
			throw new Recurrente_Charge_Locked( 'charge is being processed by another delivery' );
		}
		self::warn_if_unsupported( $got, $lock );
	}

	private static function warn_if_unsupported( $got, $lock ) {
		if ( null !== $got ) {
			return;
		}
		Recurrente_Logger::error( 'GET_LOCK unavailable; relying on the persisted charge link only', array( 'lock' => $lock ) );
	}

	private static function release( $lock ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}
