<?php
/**
 * Receives Recurrente webhooks (delivered via Svix) and reconciles orders.
 *
 * Webhooks are the source of truth for fulfillment — the buyer's success_url
 * return is never trusted to mark an order paid. The endpoint is public and
 * secured by the Svix signature; replays are de-duplicated by the svix-id.
 *
 * NOTE: Test-mode payments fire simulated webhooks (payment_intent.succeeded
 * with live_mode:false) to test endpoints, so the full loop is exercisable in
 * sandbox — as long as the site has a public URL Svix can reach.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Webhook_Handler {

	const NAMESPACE = 'recurrente/v1';
	const ROUTE     = '/webhook';

	const PAID_EVENTS = array( 'payment_intent.succeeded', 'bank_transfer_intent.succeeded', 'balance_intent.succeeded' );

	public static function url() {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public static function register_route() {
		register_rest_route( self::NAMESPACE, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle( WP_REST_Request $request ) {
		$handler = new self();
		return $handler->process( $request );
	}

	private function process( WP_REST_Request $request ) {
		if ( ! $this->verified( $request ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}
		return $this->dispatch( $request );
	}

	private function verified( WP_REST_Request $request ) {
		// A delivery may be signed with the test or the live endpoint secret;
		// both are auto-registered when the gateway settings are saved.
		return Recurrente_Svix_Verifier::any_verifies(
			Recurrente_Webhook_Registrar::secrets(),
			$request->get_body(),
			$request->get_header( 'svix-id' ),
			$request->get_header( 'svix-timestamp' ),
			$request->get_header( 'svix-signature' )
		);
	}

	private function dispatch( WP_REST_Request $request ) {
		$event   = json_decode( $request->get_body(), true );
		$svix_id = $request->get_header( 'svix-id' );
		if ( $this->already_processed( $svix_id ) ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}
		$this->route_event( $event );
		$this->mark_processed( $svix_id );
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	private function route_event( $event ) {
		$type  = isset( $event['event_type'] ) ? $event['event_type'] : '';
		$order = $this->order_from( $event );
		Recurrente_Logger::debug( 'webhook received', array( 'type' => $type, 'order' => $order ? $order->get_id() : null ) );
		$this->apply( $type, $order );
	}

	private function apply( $type, $order ) {
		if ( ! $order ) {
			return;
		}
		$this->run_handler( $type, $order );
	}

	private function run_handler( $type, $order ) {
		$handlers = $this->handlers();
		$key      = $this->handler_key( $type );
		if ( isset( $handlers[ $key ] ) ) {
			call_user_func( $handlers[ $key ], $order );
		}
	}

	/** Paid events share one handler; subscription lifecycle events map 1:1. */
	private function handlers() {
		return array(
			'paid'                  => array( $this, 'mark_paid' ),
			'refund.create'         => array( $this, 'refund_order' ),
			'subscription.create'   => array( 'Recurrente_Subscriptions', 'activate' ),
			'subscription.cancel'   => array( 'Recurrente_Subscriptions', 'cancel' ),
			'subscription.past_due' => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.paused'   => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.unpause'  => array( 'Recurrente_Subscriptions', 'reactivate' ),
		);
	}

	private function handler_key( $type ) {
		return in_array( $type, self::PAID_EVENTS, true ) ? 'paid' : $type;
	}

	/**
	 * Mark the order paid. For an already-paid subscription parent, a later paid
	 * event is a renewal, so we delegate to the subscription handler instead.
	 */
	private function mark_paid( $order ) {
		if ( $order->is_paid() ) {
			Recurrente_Subscriptions::record_renewal( $order );
			return;
		}
		$order->payment_complete( $order->get_meta( '_recurrente_checkout_id' ) );
	}

	/**
	 * A refund in Recurrente refunds and restocks the WooCommerce order, the
	 * same way the Shopify app cancels and restocks. We refund the remaining
	 * balance, so it's idempotent against Svix's at-least-once delivery.
	 */
	private function refund_order( $order ) {
		$remaining = $order->get_remaining_refund_amount();
		if ( $remaining <= 0 ) {
			return;
		}
		wc_create_refund( $this->refund_args( $order, $remaining ) );
	}

	private function refund_args( $order, $amount ) {
		return array(
			'order_id'      => $order->get_id(),
			'amount'        => $amount,
			'restock_items' => true,
			'reason'        => __( 'Reembolsado en Recurrente.', 'recurrente-for-woocommerce' ),
		);
	}

	private function order_from( $event ) {
		$metadata = isset( $event['checkout']['metadata'] ) ? $event['checkout']['metadata'] : array();
		$order    = isset( $metadata['wc_order_id'] ) ? wc_get_order( (int) $metadata['wc_order_id'] ) : false;
		return $this->valid_order( $order, $metadata );
	}

	private function valid_order( $order, $metadata ) {
		if ( ! $order || ! isset( $metadata['wc_order_key'] ) ) {
			return false;
		}
		return hash_equals( $order->get_order_key(), $metadata['wc_order_key'] ) ? $order : false;
	}

	/**
	 * De-dupe Svix's at-least-once delivery. The flag is only set AFTER the
	 * event is processed: if the handler dies mid-way, Svix's retry reprocesses
	 * instead of being swallowed as "duplicate" (the order-status guards make
	 * reprocessing harmless).
	 */
	private function already_processed( $svix_id ) {
		return (bool) get_transient( $this->dedupe_key( $svix_id ) );
	}

	private function mark_processed( $svix_id ) {
		set_transient( $this->dedupe_key( $svix_id ), 1, DAY_IN_SECONDS );
	}

	private function dedupe_key( $svix_id ) {
		return 'recurrente_wh_' . md5( (string) $svix_id );
	}
}
