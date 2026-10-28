<?php
/** Recovery through the existing order confirmation and Woo admin order actions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Recurrente_Payment_Recovery {
	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'buyer_return' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_recurrente_reconcile_payment', array( __CLASS__, 'admin_recover' ) );
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
		if ( Recurrente_Payment_Reconciliation::eligible( $order ) && current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			$actions['recurrente_reconcile_payment'] = __( 'Consultar pago en Recurrente', 'recurrente-for-woocommerce' );
		}
		return $actions;
	}

	/** Woo verifies its save nonce in both CPT and HPOS before dispatching order actions. */
	public static function admin_recover( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) || ! self::valid_admin_nonce( $order ) ) { return; }
		$result = Recurrente_Payment_Reconciliation::reconcile( $order->get_id() );
		$messages = array(
			'paid' => __( 'Recurrente confirmó el pago y se concilió la orden.', 'recurrente-for-woocommerce' ),
			'recent' => __( 'Ya se consultó este pago hace menos de un minuto. Esperá antes de consultar otra vez.', 'recurrente-for-woocommerce' ),
			'skipped' => __( 'La orden ya no está pendiente de conciliación con Recurrente.', 'recurrente-for-woocommerce' ),
			'pending' => __( 'Recurrente aún no confirma el pago. No se inició otro cobro.', 'recurrente-for-woocommerce' ),
			'unsupported' => __( 'El checkout no tiene un intent consultable. Revisá el webhook y el pago en Recurrente antes de modificar la orden.', 'recurrente-for-woocommerce' ),
		);
		$order->add_order_note( $messages[ $result ] ?? __( 'No se pudo conciliar el pago. Revisá el ambiente, la referencia, el monto y la moneda en Recurrente; no inicies otro cobro.', 'recurrente-for-woocommerce' ) );
	}

	private static function valid_admin_nonce( $order ) {
		$legacy = isset( $_POST['woocommerce_meta_nonce'] ) && is_string( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( wp_unslash( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' );
		$hpos = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] )
			&& wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'update-order_' . $order->get_id() );
		return $legacy || $hpos;
	}
}
