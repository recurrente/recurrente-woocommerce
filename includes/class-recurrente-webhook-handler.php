<?php
/**
 * Receives Recurrente webhooks (delivered via Svix) and reconciles orders.
 *
 * Webhooks are the source of truth for fulfillment — the buyer's success_url
 * return is never trusted to mark an order paid. The endpoint is public and
 * secured by the Svix signature; replays are de-duplicated by the svix-id,
 * and paid events additionally by the charge's intent id (see mark_paid).
 *
 * Recurrente emits two event-name formats for the same charge. Accounts
 * created after the unified format shipped receive only `intent.<status>`;
 * older accounts receive BOTH the legacy `<type>_intent.<status>` and the
 * unified one, as two separate Svix messages. Both are accepted here.
 *
 * NOTE: Test-mode payments fire simulated webhooks (`intent.succeeded` /
 * `payment_intent.succeeded` with live_mode:false) to test endpoints, so the
 * full loop is exercisable in sandbox — as long as the site has a public URL
 * Svix can reach.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Webhook_Handler {

	const NAMESPACE = 'recurrente/v1';
	const ROUTE     = '/webhook';

	/** Intent types whose legacy event names (`<type>_intent.<status>`) map onto the unified `intent.<status>`. */
	const LEGACY_INTENT_TYPES = array( 'payment_intent', 'bank_transfer_intent', 'crypto_intent', 'balance_intent', 'cash_intent' );

	const PAID_EVENT = 'intent.succeeded';

	/** Order meta listing the intent ids already applied to the order, so one charge is never processed twice. */
	const PROCESSED_INTENTS_META = '_recurrente_processed_intents';

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

	/**
	 * Translate a legacy event name into its unified equivalent, so both spell
	 * the same branch: `payment_intent.succeeded` → `intent.succeeded`. Names
	 * without a legacy twin (refund.*, subscription.*, intent.*) pass through.
	 */
	public static function canonical_event_type( $type ) {
		$parts = explode( '.', (string) $type, 2 );
		if ( 2 !== count( $parts ) || ! in_array( $parts[0], self::LEGACY_INTENT_TYPES, true ) ) {
			return $type;
		}
		return 'intent.' . $parts[1];
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
		$type  = self::canonical_event_type( isset( $event['event_type'] ) ? $event['event_type'] : '' );
		$order = $this->order_from( $event );
		Recurrente_Logger::debug( 'webhook received', array( 'type' => $type, 'order' => $order ? $order->get_id() : null ) );
		$this->apply( $type, $order, $event );
	}

	private function apply( $type, $order, $event ) {
		if ( ! $order ) {
			return;
		}
		$this->run_handler( $type, $order, $event );
	}

	private function run_handler( $type, $order, $event ) {
		$handlers = $this->handlers();
		if ( isset( $handlers[ $type ] ) ) {
			call_user_func( $handlers[ $type ], $order, $event );
		}
	}

	/** Keyed by canonical event name; subscription lifecycle events map 1:1. */
	private function handlers() {
		return array(
			self::PAID_EVENT        => array( $this, 'mark_paid' ),
			'refund.create'         => array( $this, 'refund_order' ),
			'subscription.create'   => array( 'Recurrente_Subscriptions', 'activate' ),
			'subscription.cancel'   => array( 'Recurrente_Subscriptions', 'cancel' ),
			'subscription.past_due' => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.paused'   => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.unpause'  => array( 'Recurrente_Subscriptions', 'reactivate' ),
		);
	}

	/**
	 * Mark the order paid, once per charge. An older account gets the same
	 * charge as two Svix messages (legacy and unified names, distinct svix-ids),
	 * and Svix retries on top of that; the intent id is what identifies the
	 * charge across all of them. The id is recorded AFTER the effect so a
	 * handler that dies mid-way is retried rather than swallowed.
	 */
	private function mark_paid( $order, $event ) {
		$intent_id = $this->intent_id( $event );
		if ( $this->intent_processed( $order, $intent_id ) ) {
			Recurrente_Logger::debug( 'paid event ignored; intent already applied', array( 'intent' => $intent_id, 'order' => $order->get_id() ) );
			return;
		}
		$this->complete_payment( $order );
		$this->record_intent( $order, $intent_id );
	}

	/**
	 * For an already-paid subscription parent, a later paid event is a renewal,
	 * so we delegate to the subscription handler instead.
	 */
	private function complete_payment( $order ) {
		if ( $order->is_paid() ) {
			Recurrente_Subscriptions::record_renewal( $order );
			return;
		}
		$order->payment_complete( $order->get_meta( '_recurrente_checkout_id' ) );
	}

	/**
	 * The charge's id, spelled the same in both formats: the legacy payload's
	 * `id` is the unified payload's `legacy_id` (the unified `id` is the newer
	 * `in_` prefixed one, so it can't be used to match the two).
	 */
	private function intent_id( $event ) {
		if ( ! empty( $event['legacy_id'] ) ) {
			return (string) $event['legacy_id'];
		}
		return isset( $event['id'] ) ? (string) $event['id'] : '';
	}

	private function intent_processed( $order, $intent_id ) {
		return '' !== $intent_id && in_array( $intent_id, $this->processed_intents( $order ), true );
	}

	private function processed_intents( $order ) {
		$ids = $order->get_meta( self::PROCESSED_INTENTS_META );
		return is_array( $ids ) ? $ids : array();
	}

	private function record_intent( $order, $intent_id ) {
		if ( '' === $intent_id ) {
			return;
		}
		$order->update_meta_data( self::PROCESSED_INTENTS_META, array_merge( $this->processed_intents( $order ), array( $intent_id ) ) );
		$order->save();
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
