<?php
/** One-shot feedback for native Woo refund queries; no payment or journal writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Refund_Admin {
	const NOTICE = 'recurrente_refund_notice_';

	public static function register_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
		add_action( 'woocommerce_order_actions_end', array( __CLASS__, 'pending_row' ) );
	}

	public static function remember_result( $order_id, $notice ) {
		set_transient( self::notice_key( $order_id ), $notice, 5 * MINUTE_IN_SECONDS );
	}

	private static function notice_key( $order_id ) {
		return self::NOTICE . get_current_user_id() . '_' . absint( $order_id );
	}

	public static function result_notice() {
		$order_id = self::edited_order_id();
		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) { return; }
		$notice = get_transient( self::notice_key( $order_id ) );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) { return; }
		delete_transient( self::notice_key( $order_id ) );
		printf( '<div id="recurrente-refund-result" class="notice notice-%1$s is-dismissible" role="status"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
	}

	public static function pending_row( $order_id ) {
		if ( ! Recurrente_Refund_Operations::can_check( wc_get_order( $order_id ) ) ) { return; }
		printf( '<li class="wide recurrente-refund-check-pending" hidden role="status"><span class="spinner is-active" aria-hidden="true"></span>%s</li>', esc_html__( 'Consultando el reembolso en Recurrente…', 'recurrente-for-woocommerce' ) );
	}

	private static function edited_order_id() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! $screen || $order_screen !== $screen->id || ( $_GET['action'] ?? null ) !== 'edit' ) { return 0; }
		$param = 'shop_order' === $order_screen ? 'post' : 'id';
		return isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 0;
	}
}
