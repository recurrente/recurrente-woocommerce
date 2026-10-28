<?php
/** WordPress/WooCommerce and outbound HTTP boundaries only; plugin classes remain real. */
class Stub_Recovery_Order extends Stub_Webhook_Order {
	public $status = 'pending';
	public $method = 'recurrente';
	public $customer = 0;
	public $amount = '100.00';
	public $currency = 'GTQ';
	public $created_at;
	public $notes = array();
	public function __construct( $id ) { parent::__construct( $id ); $this->created_at = time() - 600; }
	public function get_payment_method() { return $this->method; }
	public function has_status( $statuses ) { return in_array( $this->paid ? 'processing' : $this->status, (array) $statuses, true ); }
	public function get_customer_id() { return $this->customer; }
	public function get_total() { return $this->amount; }
	public function get_currency() { return $this->currency; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['recurrente_recovery_hooks'][ $hook ][] = array( $callback, $priority, $accepted_args ); }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { add_action( $hook, $callback, $priority, $accepted_args ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['recurrente_recovery_hooks'][ $hook ] ?? array() as $entry ) { call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) ); }
}
function is_order_received_page() { return $GLOBALS['recurrente_is_received']; }
function get_query_var( $key ) { return $GLOBALS['recurrente_received_order']; }
function absint( $value ) { return abs( (int) $value ); }
function wc_clean( $value ) { return trim( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function get_current_user_id() { return $GLOBALS['recurrente_current_user']; }
function current_user_can( $cap, $id ) { return $GLOBALS['recurrente_can_edit']; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-' . $action === $nonce; }
function wp_schedule_single_event( $at, $hook, $args ) {
	$GLOBALS['recurrente_recovery_cron'][ $hook . json_encode( $args ) ] = array( 'at' => $at, 'hook' => $hook, 'args' => $args );
	return true;
}
function wp_unschedule_hook( $hook ) {
	foreach ( $GLOBALS['recurrente_recovery_cron'] as $key => $event ) { if ( $event['hook'] === $hook ) { unset( $GLOBALS['recurrente_recovery_cron'][ $key ] ); } }
}
function as_next_scheduled_action( $hook, $args, $group ) { return $GLOBALS['recurrente_recovery_as'][ $hook . json_encode( $args ) ]['at'] ?? false; }
function as_schedule_recurring_action( $at, $interval, $hook, $args, $group, $unique ) {
	if ( $GLOBALS['recurrente_as_fails'] ) { return 0; }
	$GLOBALS['recurrente_recovery_as'][ $hook . json_encode( $args ) ] = compact( 'at', 'interval', 'hook', 'args', 'group', 'unique' );
	return count( $GLOBALS['recurrente_recovery_as'] );
}
function as_schedule_single_action( $at, $hook, $args, $group, $unique ) { return as_schedule_recurring_action( $at, null, $hook, $args, $group, $unique ); }
function as_unschedule_all_actions( $hook, $args, $group ) {
	foreach ( $GLOBALS['recurrente_recovery_as'] as $key => $event ) { if ( $event['hook'] === $hook && $event['group'] === $group ) { unset( $GLOBALS['recurrente_recovery_as'][ $key ] ); } }
}

function recurrente_recovery_order( $id = 700 ) {
	recurrente_reset_renewal_world();
	$order = new Stub_Recovery_Order( $id );
	$GLOBALS['recurrente_test_orders'] = array( $order );
	recurrente_binding( $order );
	$GLOBALS['recurrente_recovery_http'] = array();
	$GLOBALS['recurrente_recovery_as'] = array();
	$GLOBALS['recurrente_recovery_cron'] = array();
	$GLOBALS['recurrente_recovery_hooks'] = array();
	$GLOBALS['recurrente_cron'] = array();
	$GLOBALS['recurrente_as_fails'] = false;
	$GLOBALS['recurrente_scan_queries'] = array();
	$GLOBALS['recurrente_is_received'] = true;
	$GLOBALS['recurrente_received_order'] = $id;
	$GLOBALS['recurrente_current_user'] = 0;
	$GLOBALS['recurrente_can_edit'] = false;
	$GLOBALS['recurrente_binding_cache'] = array();
	$_GET = array( 'key' => $order->get_order_key() );
	$_POST = array();
	update_option( Recurrente_Pending_Payments::PAGE_OPTION, 1 );
	update_option( 'woocommerce_recurrente_settings', array( 'live_secret_key' => 'sk_live_fixture', 'test_secret_key' => 'sk_test_fixture', 'test_mode' => 'yes' ) );
	$GLOBALS['recurrente_recovery_intent'] = recurrente_bound_event( $order, 'pa_recovered' );
	unset( $GLOBALS['recurrente_recovery_intent']['event_type'] );
	$GLOBALS['recurrente_recovery_intent']['id'] = 'in_recovered';
	$GLOBALS['recurrente_recovery_intent']['type'] = 'payment';
	$GLOBALS['recurrente_recovery_intent']['status'] = 'succeeded';
	$GLOBALS['recurrente_recovery_intent']['raw_status'] = 'succeeded';
	$GLOBALS['recurrente_recovery_intent']['checkout']['status'] = 'paid';
	$GLOBALS['recurrente_recovery_intent']['checkout']['latest_intent'] = array( 'id' => 'in_recovered', 'type' => 'PaymentIntent', 'data' => array( 'auth_code' => null ) );
	$GLOBALS['recurrente_binding_response_for_request'] = function ( $url, $args ) {
		$GLOBALS['recurrente_recovery_http'][] = array( $url, $args );
		$intent = $GLOBALS['recurrente_recovery_intent'];
		$body = false !== strpos( $url, '/checkouts/' ) ? $intent['checkout'] : $intent;
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
	};
	return $order;
}

function recurrente_recovery_recheck( $order ) {
	$order->update_meta_data( Recurrente_Payment_Reconciliation::CHECKED_AT, time() - 61 );
	$order->save();
}
