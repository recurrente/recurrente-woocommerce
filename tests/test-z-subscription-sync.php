<?php
/** Real signed webhook -> identity -> Woo hook -> scheduled worker -> HTTP boundary. */
require_once dirname( __DIR__ ) . '/includes/class-recurrente-api-client.php';

function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['sync_hooks'][ $hook ][] = array( $callback, $accepted );
}
function sync_action( $hook, ...$args ) {
	foreach ( isset( $GLOBALS['sync_hooks'][ $hook ] ) ? $GLOBALS['sync_hooks'][ $hook ] : array() as $handler ) {
		call_user_func_array( $handler[0], array_slice( $args, 0, $handler[1] ) );
	}
}
function as_schedule_single_action( $at, $hook, $args, $group, $unique ) {
	$GLOBALS['sync_queue'][] = array( $hook, $args, $at );
	return count( $GLOBALS['sync_queue'] );
}
function wcs_get_subscription( $id ) {
	return isset( $GLOBALS['sync_subs'][ $id ] ) ? $GLOBALS['sync_subs'][ $id ] : false;
}
function wp_remote_request( $url, $args ) {
	$GLOBALS['sync_http'][] = array( $url, $args );
	return array_shift( $GLOBALS['sync_responses'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function esc_html( $text ) { return $text; }

class Sync_Subscription extends Stub_Renewal_Order {
	public $status = 'pending';
	public $notes = array();
	public function __construct( $id ) { $this->id = $id; $this->payment_method = 'recurrente'; }
	public function get_payment_method() { return $this->payment_method; }
	public function has_status( $status ) { return $this->status === $status; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status, $note = '' ) {
		$old = $this->status;
		$this->status = $status;
		sync_action( 'woocommerce_subscription_status_updated', $this, $status, $old );
	}
}

Recurrente_Subscriptions::register_hooks();
function sync_world( $live = true ) {
	$GLOBALS['sync_queue'] = $GLOBALS['sync_http'] = $GLOBALS['sync_responses'] = $GLOBALS['sync_failed'] = array();
	update_option( 'woocommerce_recurrente_settings', array( 'live_secret_key' => 'live-original', 'test_secret_key' => 'test-original', 'test_mode' => 'yes' ) );
	$order = recurrente_test_order( 501 );
	$sub = new Sync_Subscription( 502 );
	$GLOBALS['sync_subs'] = array( 502 => $sub );
	$order->subscriptions = array( $sub );
	$event = recurrente_test_event( 'intent.succeeded', $order, 'pi_first' );
	$event['subscription'] = array( 'id' => 'su_ab12cd34' );
	$event['live_mode'] = $live;
	recurrente_test_deliver( $event );
	$sub->status = 'active';
	return array( $order, $sub, $event );
}
function sync_response( $code, $body ) { return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) ); }
function sync_run() {
	$job = array_shift( $GLOBALS['sync_queue'] );
	try {
		sync_action( $job[0], ...$job[1] );
	} catch ( Recurrente_API_Exception $e ) {
		// Like Action Scheduler, record the failed action without aborting the queue runner.
		$GLOBALS['sync_failed'][] = $e->getCode();
	}
}
function sync_remote( $status, $result = 200 ) {
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => $status ) ), sync_response( $result, array( 'message' => 'result' ) ) );
}

it( 'subscription: signed first paid intent saves immutable remote ID, environment and parent link', function () {
	list( $order, $sub, $event ) = sync_world( false );
	$event['subscription']['id'] = 'su_other123';
	recurrente_test_deliver( $event );
	return 'su_ab12cd34' === $sub->get_meta( Recurrente_Subscription_Sync::ID ) && 'test' === $sub->get_meta( Recurrente_Subscription_Sync::ENVIRONMENT ) && 501 === $sub->get_meta( '_recurrente_subscription_order_id' ) && 'su_ab12cd34' === $order->get_meta( Recurrente_Subscription_Sync::ID );
} );
it( 'subscription: pending cancellation pauses remote billing and preserves prepaid access using original live environment', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' );
	sync_remote( 'active' );
	sync_run();
	$request = $GLOBALS['sync_http'][1];
	Recurrente_Subscriptions::on_hold( $order );
	return 'pending-cancel' === $sub->status && 'PUT' === $request[1]['method'] && 'live-original' === $request[1]['headers']['X-SECRET-KEY'] && 'https://app.recurrente.com/api/subscriptions/su_ab12cd34' === $request[0] && array( 'act' => 'pause' ) === json_decode( $request[1]['body'], true ) && ! $GLOBALS['sync_queue'];
} );
it( 'subscription: suspension and reactivation send actual pause/unpause payloads', function () {
	list( , $sub ) = sync_world( false );
	$sub->update_status( 'on-hold' );
	sync_remote( 'active' ); sync_run();
	$pause = $GLOBALS['sync_http'][1][1];
	$sub->update_status( 'active' );
	sync_remote( 'paused' ); sync_run();
	$unpause = $GLOBALS['sync_http'][3][1];
	return 'PUT' === $pause['method'] && array( 'act' => 'pause' ) === json_decode( $pause['body'], true ) && array( 'act' => 'unpause' ) === json_decode( $unpause['body'], true ) && 'test-original' === $unpause['headers']['X-SECRET-KEY'];
} );
it( 'subscription: HTTP 503 leaves cancellation unconfirmed and schedules durable retry', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'cancelled' ); sync_remote( 'active', 503 ); sync_run();
	$first = $GLOBALS['sync_http'][1][1]['headers']['Idempotency-Key'];
	$note = end( $sub->notes );
	sync_remote( 'active' ); sync_run();
	return 0 === strpos( $note, 'Recurrente: cambio remoto sin confirmar (HTTP 503)' ) && $first === $GLOBALS['sync_http'][3][1]['headers']['Idempotency-Key'] && false !== strpos( end( $sub->notes ), 'confirmado (cancelled)' );
} );
it( 'subscription: transport timeout retries and remote read prevents repeating a completed mutation', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'cancelled' );
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => 'active' ) ), new WP_Error( 'timeout', 'Timeout' ) );
	sync_run();
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => 'cancelled' ) ) ); sync_run();
	return 3 === count( $GLOBALS['sync_http'] ) && ! $GLOBALS['sync_queue'];
} );
it( 'subscription: rate limiting retries, authentication errors do not confirm or loop', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'on-hold' ); sync_remote( 'active', 429 ); sync_run();
	if ( 1 !== count( $GLOBALS['sync_queue'] ) ) { return false; }
	sync_remote( 'active', 401 ); sync_run();
	return array( 401 ) === $GLOBALS['sync_failed'] && ! $GLOBALS['sync_queue'] && false !== strpos( end( $sub->notes ), 'sin confirmar (HTTP 401)' );
} );
it( 'subscription: stale queued status changes cannot override newer requests', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'on-hold' ); $sub->update_status( 'cancelled' );
	sync_run();
	return ! $GLOBALS['sync_http'] && 1 === count( $GLOBALS['sync_queue'] );
} );
it( 'subscription: incoming lifecycle updates have no outbound echo', function () {
	list( $order, $sub ) = sync_world();
	Recurrente_Subscriptions::on_hold( $order );
	Recurrente_Subscriptions::reactivate( $order );
	Recurrente_Subscriptions::cancel( $order );
	return 'cancelled' === $sub->status && ! $GLOBALS['sync_queue'] && ! $GLOBALS['sync_http'];
} );
it( 'subscription: webhook guard is released after an exception', function () {
	list( , $sub ) = sync_world();
	try { Recurrente_Subscription_Sync::from_webhook( function () { throw new RuntimeException(); } ); } catch ( RuntimeException $e ) {}
	$sub->update_status( 'cancelled' );
	return 1 === count( $GLOBALS['sync_queue'] );
} );
it( 'subscription: initial activation and other gateways do not issue remote requests', function () {
	list( , $sub ) = sync_world();
	$sub->status = 'pending'; $sub->update_status( 'active' );
	$sub->payment_method = 'other'; $sub->update_status( 'cancelled' );
	return ! $GLOBALS['sync_queue'];
} );
it( 'subscription: missing verified identity or environment never falls back to current mode', function () {
	list( , $sub ) = sync_world();
	$sub->update_meta_data( Recurrente_Subscription_Sync::ENVIRONMENT, '' ); $sub->save();
	$sub->update_status( 'cancelled' ); sync_run();
	return ! $GLOBALS['sync_http'] && ! $GLOBALS['sync_queue'] && false !== strpos( end( $sub->notes ), 'sin confirmar' );
} );
it( 'subscription: cancelled remote subscriptions cannot be silently reactivated', function () {
	list( , $sub ) = sync_world();
	$sub->status = 'pending-cancel'; $sub->update_status( 'active' );
	sync_remote( 'cancelled' ); sync_run();
	return 1 === count( $GLOBALS['sync_http'] ) && false !== strpos( end( $sub->notes ), 'sin confirmar (HTTP 422)' );
} );
it( 'subscription: concurrent workers schedule a retry instead of losing the request', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'cancelled' );
	$GLOBALS['wpdb']->held_elsewhere[] = 'recurrente_subscription_502'; sync_run();
	return 1 === count( $GLOBALS['sync_queue'] ) && ! $GLOBALS['sync_http'];
} );
it( 'subscription: first payment without a remote ID or explicit environment cannot bind a subscription', function () {
	list( $order, $sub, $event ) = sync_world();
	$other = recurrente_test_order( 601 );
	$other->subscriptions = array( new Sync_Subscription( 602 ) );
	$event = recurrente_test_event( 'intent.succeeded', $other, 'pi_other' );
	$event['subscription'] = array( 'id' => 'su_ab12cd34' );
	recurrente_test_deliver( $event );
	return $other->is_paid() && '' === $other->get_meta( Recurrente_Subscription_Sync::ID );
} );
it( 'subscription: ambiguous multi-subscription checkouts keep the parent link without targeting any sibling', function () {
	sync_world();
	$order = recurrente_test_order( 701 );
	$order->subscriptions = array( new Sync_Subscription( 702 ), new Sync_Subscription( 703 ) );
	$event = recurrente_test_event( 'intent.succeeded', $order, 'pi_group' );
	$event['subscription'] = array( 'id' => 'su_group123' ); $event['live_mode'] = true;
	// Boundary order supports the note used to flag the ambiguous mapping.
	$wrapper = new class( 701 ) extends Stub_Webhook_Order {
		public $notes = array();
		public function add_order_note( $note ) { $this->notes[] = $note; }
	};
	$wrapper->subscriptions = $order->subscriptions;
	$GLOBALS['recurrente_test_orders'] = array( $wrapper );
	recurrente_test_deliver( $event );
	return 'su_group123' === $wrapper->get_meta( Recurrente_Subscription_Sync::ID ) && '' === $wrapper->subscriptions[0]->get_meta( Recurrente_Subscription_Sync::ID ) && '' === $wrapper->subscriptions[1]->get_meta( Recurrente_Subscription_Sync::ID ) && 1 === count( $wrapper->notes );
} );
it( 'subscription: unpause never retries a potential charge after idempotency retention', function () {
	list( , $sub ) = sync_world();
	$sub->status = 'on-hold'; $sub->update_status( 'active' );
	$request = $sub->get_meta( Recurrente_Subscription_Sync::REQUEST );
	$request['created_at'] = time() - 24 * 3600;
	$sub->update_meta_data( Recurrente_Subscription_Sync::REQUEST, $request ); $sub->save();
	sync_run();
	return ! $GLOBALS['sync_http'] && ! $GLOBALS['sync_queue'] && false !== strpos( end( $sub->notes ), 'sin confirmar (HTTP 409)' );
} );
it( 'subscription: repeating a completed scheduled action cannot repeat the remote mutation', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'cancelled' ); $job = $GLOBALS['sync_queue'][0];
	sync_remote( 'active' ); sync_run();
	sync_action( $job[0], ...$job[1] );
	return 2 === count( $GLOBALS['sync_http'] ) && $job[1][1] === $sub->get_meta( Recurrente_Subscription_Sync::COMPLETED );
} );
it( 'subscription: payment completion from a signed webhook also suppresses outbound reactivation', function () {
	sync_world();
	$order = new class( 801 ) extends Stub_Webhook_Order {
		public function payment_complete( $transaction_id ) {
			parent::payment_complete( $transaction_id );
			$this->subscriptions[0]->update_status( 'active' );
		}
	};
	$sub = new Sync_Subscription( 802 ); $sub->status = 'on-hold';
	$order->subscriptions = array( $sub );
	$GLOBALS['recurrente_test_orders'] = array( $order );
	$event = recurrente_test_event( 'intent.succeeded', $order, 'pi_reactivate' );
	$event['subscription'] = array( 'id' => 'su_ab12cd34' ); $event['live_mode'] = true;
	recurrente_test_deliver( $event );
	return 'active' === $sub->status && ! $GLOBALS['sync_queue'];
} );

it( 'subscription: pending cancellation can be reactivated without cancelling the remote subscription', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' );
	sync_remote( 'active' ); sync_run();
	Recurrente_Subscriptions::on_hold( $order );
	if ( 'pending-cancel' !== $sub->status || $GLOBALS['sync_queue'] ) { return false; }
	$sub->update_status( 'active' );
	sync_remote( 'paused' ); sync_run();
	Recurrente_Subscriptions::reactivate( $order );
	$methods = array_column( array_column( $GLOBALS['sync_http'], 1 ), 'method' );
	return array( 'GET', 'PUT', 'GET', 'PUT' ) === $methods && array( 'act' => 'unpause' ) === json_decode( $GLOBALS['sync_http'][3][1]['body'], true ) && 'active' === $sub->status && ! $GLOBALS['sync_queue'];
} );
it( 'subscription: Woo final cancellation after pending cancellation permanently cancels the paused remote subscription', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' );
	sync_remote( 'active' ); sync_run();
	Recurrente_Subscriptions::on_hold( $order );
	$sub->update_status( 'cancelled' );
	sync_remote( 'paused' ); sync_run();
	Recurrente_Subscriptions::cancel( $order );
	return 'cancelled' === $sub->status && 'DELETE' === $GLOBALS['sync_http'][3][1]['method'] && ! $GLOBALS['sync_queue'];
} );
it( 'subscription: immediate undo of pending cancellation skips the stale pause and does not mutate an active remote subscription', function () {
	list( , $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' );
	$sub->update_status( 'active' );
	sync_run();
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => 'active' ) ) );
	sync_run();
	return 1 === count( $GLOBALS['sync_http'] ) && 'GET' === $GLOBALS['sync_http'][0][1]['method'] && ! $GLOBALS['sync_queue'];
} );

it( 'subscription: delayed activation echo cannot undo a newer pending cancellation', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' ); sync_remote( 'active' ); sync_run();
	$sub->update_status( 'active' ); sync_remote( 'paused' ); sync_run();
	$sub->update_status( 'pending-cancel' );
	Recurrente_Subscriptions::reactivate( $order );
	sync_remote( 'active' ); sync_run();
	Recurrente_Subscriptions::on_hold( $order );
	return 'pending-cancel' === $sub->status && ! $GLOBALS['sync_queue'];
} );

it( 'subscription: completing an older request cannot erase a concurrently saved cancellation', function () {
	list( $order, $original ) = sync_world();
	$sub = new class( 502 ) extends Sync_Subscription {
		public $storage;
		public $before_save;
		public function save() {
			$callback = $this->before_save;
			$this->before_save = null;
			if ( $callback ) { $callback(); }
			$this->storage->meta = array_merge( $this->storage->meta, $this->pending_meta );
			$this->saved_meta = $this->storage->meta;
			$this->pending_meta = array();
		}
	};
	$sub->storage = (object) array( 'meta' => $original->saved_meta );
	$sub->saved_meta = $original->saved_meta;
	$sub->status = 'active';
	$GLOBALS['sync_subs'][502] = $sub;
	$order->subscriptions = array( $sub );
	$sub->update_status( 'on-hold' );
	$producer = clone $sub;
	$sub->before_save = function () use ( $producer ) { $producer->update_status( 'cancelled' ); };
	sync_remote( 'active' ); sync_run();
	$latest = $sub->storage->meta[ Recurrente_Subscription_Sync::REQUEST ];
	if ( ! $latest || 'cancelled' !== $latest['target'] || 1 !== count( $GLOBALS['sync_queue'] ) ) { return false; }
	sync_remote( 'paused' ); sync_run();
	return 4 === count( $GLOBALS['sync_http'] ) && 'DELETE' === $GLOBALS['sync_http'][3][1]['method'] && ! $GLOBALS['sync_queue'];
} );

it( 'subscription: late pause echo cannot revoke access after cancellation undo has completed', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'pending-cancel' ); sync_remote( 'active' ); sync_run();
	$sub->update_status( 'active' ); sync_remote( 'paused' ); sync_run();
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => 'active' ) ) );
	Recurrente_Subscriptions::on_hold( $order );
	return 'active' === $sub->status && 5 === count( $GLOBALS['sync_http'] ) && ! $GLOBALS['sync_queue'];
} );

it( 'subscription: a later real remote pause still reaches Woo after local unpause completed', function () {
	list( $order, $sub ) = sync_world();
	$sub->update_status( 'on-hold' ); sync_remote( 'active' ); sync_run();
	$sub->update_status( 'active' ); sync_remote( 'paused' ); sync_run();
	$GLOBALS['sync_responses'] = array( sync_response( 200, array( 'status' => 'paused' ) ) );
	Recurrente_Subscriptions::on_hold( $order );
	return 'on-hold' === $sub->status && 5 === count( $GLOBALS['sync_http'] ) && ! $GLOBALS['sync_queue'];
} );
