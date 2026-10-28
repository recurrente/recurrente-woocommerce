<?php
/** Recovery through the existing order confirmation and Woo admin order actions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Payment_Recovery {
	const ACTION = 'recurrente_reconcile_payment';
	const NOTICE = 'recurrente_recovery_notice_';

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'buyer_return' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( __CLASS__, 'admin_recover' ) );
		add_action( 'woocommerce_order_actions_end', array( __CLASS__, 'pending_row' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_script' ) );
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
	}

	/** The order key authorizes a lookup, never payment; registered customers must also own the order. */
	public static function buyer_return() {
		if ( ! is_order_received_page() ) { return; }
		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || ! self::buyer_can_access( $order ) ) { return; }
		Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
	}

	private static function buyer_can_access( $order ) {
		return ! $order->get_customer_id() || get_current_user_id() === $order->get_customer_id()
			|| current_user_can( 'edit_shop_order', $order->get_id() );
	}

	public static function order_actions( $actions, $order ) {
		if ( self::offered( $order ) ) {
			$actions[ self::ACTION ] = __( 'Consultar pago en Recurrente', 'recurrente-for-woocommerce' );
		}
		return $actions;
	}

	private static function offered( $order ) {
		return Recurrente_Payment_Reconciliation::eligible( $order ) && current_user_can( 'edit_shop_order', $order->get_id() );
	}

	/** Woo verifies its save nonce in both CPT and HPOS before dispatching order actions. */
	public static function admin_recover( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) || ! self::valid_admin_nonce( $order ) ) { return; }
		$outcome = self::outcome( Recurrente_Payment_Reconciliation::reconcile( $order->get_id() ) );
		if ( $outcome['note'] ) {
			$order->add_order_note( $outcome['message'], 0, true );
		}
		set_transient( self::notice_key( $order->get_id() ), array( 'type' => $outcome['type'], 'message' => $outcome['message'] ), MINUTE_IN_SECONDS );
	}

	/** A lookup that changed nothing (throttled, already settled) gets a notice but no permanent note. */
	private static function outcome( $result ) {
		$outcomes = array(
			'paid'        => array( 'success', true, __( 'Pago confirmado al consultar Recurrente.', 'recurrente-for-woocommerce' ) ),
			'pending'     => array( 'warning', true, __( 'Recurrente aún no confirma el pago.', 'recurrente-for-woocommerce' ) ),
			'recent'      => array( 'warning', false, __( 'Este pago se consultó hace menos de un minuto. Intentá de nuevo en un momento.', 'recurrente-for-woocommerce' ) ),
			'skipped'     => array( 'info', false, __( 'Esta orden ya no está pendiente de pago.', 'recurrente-for-woocommerce' ) ),
			'mismatch'    => array( 'error', true, __( 'El pago en Recurrente no coincide con esta orden. Revisalo en Recurrente antes de cobrar otra vez.', 'recurrente-for-woocommerce' ) ),
			'unsupported' => array( 'error', true, __( 'Este pago no se puede verificar desde WooCommerce. Revisalo en Recurrente antes de cambiar el estado de la orden.', 'recurrente-for-woocommerce' ) ),
			'unavailable' => array( 'error', true, __( 'No se pudo consultar Recurrente. Intentá de nuevo en unos minutos.', 'recurrente-for-woocommerce' ) ),
		);
		list( $type, $note, $message ) = $outcomes[ $result ] ?? $outcomes['unavailable'];
		return compact( 'type', 'note', 'message' );
	}

	/** One merchant, one order: the result shows only on that order's edit screen, for the user who ran it. */
	private static function notice_key( $order_id ) {
		return self::NOTICE . get_current_user_id() . '_' . absint( $order_id );
	}

	public static function result_notice() {
		$order_id = self::edited_order_id();
		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) { return; }
		$notice = get_transient( self::notice_key( $order_id ) );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) { return; }
		delete_transient( self::notice_key( $order_id ) );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
	}

	/** Hidden until the action is submitted; the script reveals it and blocks a second submit. */
	public static function pending_row( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::offered( $order ) ) { return; }
		printf(
			'<li class="wide recurrente-recovery-pending" style="display:none" role="status"><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span>%s</li>',
			esc_html__( 'Consultando a Recurrente…', 'recurrente-for-woocommerce' )
		);
	}

	public static function enqueue_admin_script() {
		if ( ! self::edited_order_id() ) { return; }
		wp_enqueue_script(
			'recurrente-admin-payment-recovery',
			plugins_url( 'assets/js/admin-payment-recovery.js', RECURRENTE_PLUGIN_FILE ),
			array(),
			RECURRENTE_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * The order open in Woo's edit screen: post.php?post=ID&action=edit (CPT) or
	 * admin.php?page=wc-orders&action=edit&id=ID (HPOS, whose list shares the screen ID).
	 */
	private static function edited_order_id() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! $screen || $order_screen !== $screen->id || ! isset( $_GET['action'] ) || 'edit' !== $_GET['action'] ) { return 0; }
		$param = 'shop_order' === $order_screen ? 'post' : 'id';
		return isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 0;
	}

	private static function valid_admin_nonce( $order ) {
		$legacy = isset( $_POST['woocommerce_meta_nonce'] ) && is_string( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( wp_unslash( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' );
		$hpos = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] )
			&& wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'update-order_' . $order->get_id() );
		return $legacy || $hpos;
	}
}
