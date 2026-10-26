<?php
/** Outbound lifecycle requests, persisted and retried through Woo's Action Scheduler. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Subscription_Sync {
	const HOOK = 'recurrente_sync_subscription';
	const GROUP = 'recurrente';
	const ID = '_recurrente_subscription_id';
	const ENVIRONMENT = '_recurrente_subscription_environment';
	const REQUEST = '_recurrente_subscription_request';
	const COMPLETED = '_recurrente_subscription_completed_key';
	private static $webhook_depth = 0;

	public static function register_hooks() {
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 3 );
	}

	/** Reentrant guard: incoming lifecycle changes must never generate outbound requests. */
	public static function from_webhook( callable $callback ) {
		self::$webhook_depth++;
		try {
			return $callback();
		} finally {
			self::$webhook_depth--;
		}
	}

	public static function webhook_status( $subscription, $status ) {
		if ( ! $subscription->get_meta( self::REQUEST ) ) {
			return $status;
		}
		$remote = self::client_for( $subscription )->get_subscription( $subscription->get_meta( self::ID ) );
		$statuses = array( 'active' => 'active', 'paused' => 'on-hold', 'past_due' => 'on-hold', 'unable_to_start' => 'on-hold', 'cancelled' => 'cancelled' );
		if ( ! isset( $remote['status'], $statuses[ $remote['status'] ] ) ) {
			throw new Recurrente_API_Exception( 'Cannot reconcile the current subscription status.', 502 );
		}
		return $statuses[ $remote['status'] ];
	}

	/** Called only after signature/order-key validation, before first payment completion. */
	public static function capture_identity( $order, $event ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}
		$id = isset( $event['subscription']['id'] ) ? $event['subscription']['id'] : null;
		$first_charge = Recurrente_Charge_Order_Link::charge_of( $order );
		$charge = isset( $event['legacy_id'] ) ? $event['legacy_id'] : ( isset( $event['id'] ) ? $event['id'] : '' );
		if ( ! is_string( $id ) || ! preg_match( '/^su_[a-zA-Z0-9]+$/', $id ) || ! isset( $event['live_mode'] ) || ! is_bool( $event['live_mode'] ) || ( $order->is_paid() && ! $first_charge ) || ( $first_charge && $first_charge !== $charge ) ) {
			return;
		}
		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		if ( ! $subscriptions || ( $order->get_meta( self::ID ) && $order->get_meta( self::ID ) !== $id ) ) {
			return;
		}
		$environment = $event['live_mode'] ? 'live' : 'test';
		self::save_identity( $order, $id, $environment );
		// One remote subscription can aggregate several WC subscriptions. Never guess a sibling mapping.
		if ( 1 !== count( $subscriptions ) ) {
			$order->add_order_note( __( 'Recurrente: sincronización saliente bloqueada; varias suscripciones Woo comparten el checkout. Administrá la suscripción en Recurrente.', 'recurrente-for-woocommerce' ) );
			return;
		}
		$subscription = reset( $subscriptions );
		if ( ! $subscription->get_meta( self::ID ) ) {
			self::save_identity( $subscription, $id, $environment );
			$subscription->update_meta_data( '_recurrente_subscription_order_id', $order->get_id() );
			$subscription->save();
		}
	}

	private static function save_identity( $order, $id, $environment ) {
		$order->update_meta_data( self::ID, $id );
		$order->update_meta_data( self::ENVIRONMENT, $environment );
		$order->save();
	}

	public static function status_changed( $subscription, $new_status, $old_status ) {
		if ( self::$webhook_depth || Recurrente_Gateway::ID !== $subscription->get_payment_method() ) {
			return;
		}
		$targets = array( 'cancelled' => 'cancelled', 'pending-cancel' => 'paused', 'on-hold' => 'paused', 'active' => 'active' );
		if ( ! isset( $targets[ $new_status ] ) || ( 'active' === $new_status && ! in_array( $old_status, array( 'on-hold', 'pending-cancel' ), true ) ) ) {
			return;
		}
		$request = array( 'target' => $targets[ $new_status ], 'key' => wp_generate_uuid4(), 'created_at' => time() );
		$subscription->update_meta_data( self::REQUEST, $request );
		$subscription->save();
		self::note( $subscription, 'Recurrente: cambio remoto pendiente de confirmación.' );
		self::schedule( $subscription, $request['key'], 0 );
	}

	private static function schedule( $subscription, $key, $attempt ) {
		$action = as_schedule_single_action( time() + min( 3600, 30 * pow( 2, min( $attempt, 7 ) ) ), self::HOOK, array( $subscription->get_id(), $key, $attempt ), self::GROUP, true );
		if ( ! $action ) {
			self::note( $subscription, 'Recurrente: no se pudo programar la sincronización. Revisá Action Scheduler y el estado remoto.' );
		}
	}

	public static function run( $subscription_id, $key, $attempt = 0 ) {
		global $wpdb;
		$lock = 'recurrente_subscription_' . (int) $subscription_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				self::schedule( $subscription, $key, $attempt + 1 );
			}
			return;
		}
		try {
			self::reconcile( $subscription_id, $key, $attempt );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function reconcile( $subscription_id, $key, $attempt ) {
		$subscription = wcs_get_subscription( $subscription_id );
		$request = $subscription ? $subscription->get_meta( self::REQUEST ) : null;
		if ( ! $request || $request['key'] !== $key || $subscription->get_meta( self::COMPLETED ) === $key || Recurrente_Gateway::ID !== $subscription->get_payment_method() ) {
			return;
		}
		try {
			self::send( $subscription, $request );
			self::note( $subscription, 'Recurrente: cambio remoto confirmado (' . $request['target'] . ').' );
			$subscription->update_meta_data( self::COMPLETED, $key );
			$subscription->save();
		} catch ( Recurrente_API_Exception $e ) {
			self::note( $subscription, 'Recurrente: cambio remoto sin confirmar (HTTP ' . $e->getCode() . '). Revisá el estado en Recurrente.' );
			if ( in_array( $e->getCode(), array( 0, 408, 429 ), true ) || $e->getCode() >= 500 ) {
				self::schedule( $subscription, $key, $attempt + 1 );
				return;
			}
			throw $e;
		}
	}

	private static function send( $subscription, $request ) {
		// Unpause can charge. Never reuse its idempotency key after the API's 24-hour retention.
		if ( 'active' === $request['target'] && time() - $request['created_at'] >= 23 * 3600 ) {
			throw new Recurrente_API_Exception( 'Unpause outcome needs manual verification.', 409 );
		}
		$id = $subscription->get_meta( self::ID );
		$client = self::client_for( $subscription );
		$remote = $client->get_subscription( $id );
		if ( isset( $remote['status'] ) && $remote['status'] === $request['target'] ) {
			return;
		}
		$methods = array( 'cancelled' => 'cancel_subscription', 'paused' => 'pause_subscription', 'active' => 'unpause_subscription' );
		if ( ! isset( $remote['status'] ) || ( 'active' === $request['target'] && 'paused' !== $remote['status'] ) || 'cancelled' === $remote['status'] ) {
			throw new Recurrente_API_Exception( 'Remote status cannot accept this transition.', 422 );
		}
		$client->{$methods[ $request['target'] ]}( $id, $request['key'] );
	}

	private static function client_for( $subscription ) {
		$environment = $subscription->get_meta( self::ENVIRONMENT );
		$settings = get_option( 'woocommerce_recurrente_settings', array() );
		$secret = in_array( $environment, array( 'live', 'test' ), true ) && isset( $settings[ $environment . '_secret_key' ] ) ? $settings[ $environment . '_secret_key' ] : '';
		if ( ! $subscription->get_meta( self::ID ) || ! $secret ) {
			throw new Recurrente_API_Exception( 'Missing verified subscription identity or environment key.', 400 );
		}
		return new Recurrente_API_Client( $secret );
	}

	private static function note( $subscription, $message ) {
		$subscription->add_order_note( __( $message, 'recurrente-for-woocommerce' ) );
	}
}
