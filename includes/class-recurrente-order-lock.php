<?php
/** Serializes checkout replacement with webhook validation and fulfillment. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Order_Locked extends RuntimeException {}

class Recurrente_Order_Lock {
	public static function run( $order_id, callable $body ) {
		global $wpdb;
		$name = 'recurrente_order_' . md5( (string) $order_id );
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) );
		if ( '1' !== (string) $got ) {
			throw new Recurrente_Order_Locked( 'Order serialization unavailable.' );
		}
		try {
			return $body( self::reload( $order_id ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	private static function reload( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Recurrente_Order_Locked( 'Order unavailable.' );
		}
		// Woo's factory may return a cached object; reload both properties and metadata.
		$order->get_data_store()->read( $order );
		$order->read_meta_data( true );
		return $order;
	}
}
