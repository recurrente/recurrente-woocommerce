<?php
/**
 * Recurrente_Charge_Order_Link + renewal tests.
 *
 * A Recurrente charge (intent id) must produce at most one renewal order, no
 * matter how many times Svix delivers it or how many deliveries overlap. WP,
 * WC and WC Subscriptions are stood in by the stubs in stubs-renewals.php: an
 * in-memory order store behind wc_get_orders(), a $wpdb that emulates
 * GET_LOCK, and a subscription whose renewals are created with
 * wcs_create_renewal_order().
 */

require_once __DIR__ . '/stubs-renewals.php';

function recurrente_record_renewal( $charge_id ) {
	Recurrente_Subscriptions::record_renewal( new stdClass(), $charge_id );
}

it( 'renewal: a repeated delivery of the same charge creates one order', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the order carries the intent id as _recurrente_intent_id', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	$order = $recurrente_test_orders[0];
	return 'pa_abc' === $order->get_meta( '_recurrente_intent_id' ) && 'recurrente' === $order->payment_method;
} );

it( 'renewal: distinct charges create distinct orders', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_one' );
	recurrente_record_renewal( 'pa_two' );
	return 2 === count( $recurrente_test_orders );
} );

it( 'renewal: a concurrent delivery arriving while the lock is held is refused and creates nothing', function () {
	global $recurrente_test_orders;
	$wpdb          = recurrente_reset_renewal_world();
	$second_result = null;
	$wpdb->on_lock = function () use ( &$second_result, $wpdb ) {
		$wpdb->on_lock = null; // Only the first acquisition spawns the "second" delivery.
		try {
			recurrente_record_renewal( 'pa_abc' );
			$second_result = 'created';
		} catch ( Recurrente_Charge_Locked $e ) {
			$second_result = 'locked';
		}
	};
	recurrente_record_renewal( 'pa_abc' );
	return 'locked' === $second_result && 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the lock is released after the delivery, so the retry then reuses the order', function () {
	global $recurrente_test_orders;
	$wpdb = recurrente_reset_renewal_world();
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return array() === $wpdb->held_elsewhere && 2 === $wpdb->locks_taken && 1 === count( $recurrente_test_orders );
} );

it( 'renewal: a crash after creating the order lets the retry complete that same order', function () {
	global $recurrente_test_orders;
	recurrente_reset_renewal_world();
	Stub_Renewal_Order::$fail_payment_complete_once = true;
	try {
		recurrente_record_renewal( 'pa_abc' );
		return false; // The first attempt must blow up.
	} catch ( RuntimeException $e ) {
		// Svix retries.
	}
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders ) && $recurrente_test_orders[0]->paid;
} );

it( 'renewal: the lock is released even when the delivery crashes', function () {
	$wpdb = recurrente_reset_renewal_world();
	Stub_Renewal_Order::$fail_payment_complete_once = true;
	try {
		recurrente_record_renewal( 'pa_abc' );
	} catch ( RuntimeException $e ) {
		// Expected.
	}
	return array() === $wpdb->held_elsewhere;
} );

it( 'renewal: without GET_LOCK support the persisted link alone still de-dupes a retry', function () {
	global $recurrente_test_orders, $wpdb;
	recurrente_reset_renewal_world();
	$wpdb = new class() extends Stub_Wpdb {
		public function get_var( $sql ) {
			return null;
		}
	};
	recurrente_record_renewal( 'pa_abc' );
	recurrente_record_renewal( 'pa_abc' );
	return 1 === count( $recurrente_test_orders );
} );
