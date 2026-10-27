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

	private static function invalidate_caches( $order_id, $store ) {
		wp_cache_delete( $order_id, 'posts' );
		wp_cache_delete( $order_id, 'post_meta' );
		if ( method_exists( $store->get_current_class_name(), 'clear_cached_data' ) ) {
			$store->clear_cached_data( array( $order_id ) );
		}
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
	}

	private static function reload( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Recurrente_Order_Locked( 'Order unavailable.' );
		}
		$store = $order->get_data_store();
		self::invalidate_caches( $order_id, $store );
		$store->read( $order );
		$order->read_meta_data( true );
		return $order;
	}
}
