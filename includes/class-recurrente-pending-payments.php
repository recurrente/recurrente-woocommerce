<?php
/** Bounded discovery, one lookup job per order, using Woo's storage-independent query API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Pending_Payments {
	const SCAN_HOOK = 'recurrente_scan_pending_payments';
	const ORDER_HOOK = 'recurrente_reconcile_pending_payment';
	const GROUP = 'recurrente';
	const PAGE_OPTION = 'recurrente_pending_payment_page';
	const INTERVAL = 300;
	const BATCH_SIZE = 20;

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::SCAN_HOOK, array( __CLASS__, 'scan' ) );
		add_action( self::ORDER_HOOK, array( 'Recurrente_Payment_Reconciliation', 'reconcile' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['recurrente_five_minutes'] = array( 'interval' => self::INTERVAL, 'display' => __( 'Cada cinco minutos', 'recurrente-for-woocommerce' ) );
		return $schedules;
	}

	/** init priority 20 is after Action Scheduler initializes its datastore. */
	public static function ensure_scheduled() {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			$scheduled = as_next_scheduled_action( self::SCAN_HOOK, array(), self::GROUP )
				|| as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::SCAN_HOOK, array(), self::GROUP, true );
			if ( $scheduled ) { wp_clear_scheduled_hook( self::SCAN_HOOK ); return; }
		}
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, 'recurrente_five_minutes', self::SCAN_HOOK );
		}
	}

	/** Advance before dispatch: a crashed or permanently unpaid page cannot starve later orders. */
	public static function scan() {
		$page = max( 1, (int) get_option( self::PAGE_OPTION, 1 ) );
		$orders = wc_get_orders( array(
			'type' => 'shop_order', 'status' => array( 'pending', 'on-hold' ), 'payment_method' => 'recurrente',
			'date_created' => ( time() - 7 * DAY_IN_SECONDS ) . '...' . ( time() - 120 ),
			'limit' => self::BATCH_SIZE, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC',
		) );
		update_option( self::PAGE_OPTION, count( $orders ) < self::BATCH_SIZE ? 1 : $page + 1, false );
		foreach ( $orders as $order ) {
			if ( Recurrente_Payment_Reconciliation::eligible( $order ) ) { self::enqueue( $order->get_id() ); }
		}
	}

	private static function enqueue( $order_id ) {
		$args = array( $order_id );
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			$scheduled = as_next_scheduled_action( self::ORDER_HOOK, $args, self::GROUP )
				|| as_schedule_single_action( time() + 1, self::ORDER_HOOK, $args, self::GROUP, true );
			if ( $scheduled ) { return; }
		}
		if ( ! wp_next_scheduled( self::ORDER_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::ORDER_HOOK, $args );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_unschedule_hook( self::ORDER_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SCAN_HOOK, null, self::GROUP );
			as_unschedule_all_actions( self::ORDER_HOOK, null, self::GROUP );
		}
	}
}
