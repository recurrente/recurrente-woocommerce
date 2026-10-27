<?php
/**
 * Receives Recurrente webhooks (delivered via Svix) and reconciles orders.
 *
 * Webhooks are the source of truth for fulfillment — the buyer's success_url
 * return is never trusted to mark an order paid. The endpoint is public and
 * secured by the Svix signature; replays are de-duplicated by the svix-id,
 * and paid events additionally by the charge's intent id (see mark_paid and
 * Recurrente_Charge_Order_Link).
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

require_once __DIR__ . '/class-recurrente-order-lock.php';

class Recurrente_Webhook_Handler {

	const NAMESPACE = 'recurrente/v1';
	const ROUTE     = '/webhook';

	/** Intent types whose legacy event names (`<type>_intent.<status>`) map onto the unified `intent.<status>`. */
	const LEGACY_INTENT_TYPES = array( 'payment_intent', 'bank_transfer_intent', 'crypto_intent', 'balance_intent', 'cash_intent' );

	const PAID_EVENT = 'intent.succeeded';

	/** Identity of the charge being processed; see charge_id_of(). */
	private $charge_id = '';

	private $signing_modes = array();

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
			return $this->reject( $request );
		}
		return $this->dispatch( $request );
	}

	/**
	 * Only a delivery that carries a svix-id counts as a failed webhook for the
	 * health report: the route is public and random bots must not look like a
	 * broken signing secret.
	 */
	private function reject( WP_REST_Request $request ) {
		if ( $request->get_header( 'svix-id' ) ) {
			Recurrente_Telemetry::note_error( Recurrente_Telemetry::WEBHOOK_FAILED );
		}
		return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
	}

	private function verified( WP_REST_Request $request ) {
		// A delivery may be signed with the test or the live endpoint secret;
		// both are auto-registered when the gateway settings are saved.
		$this->signing_modes = Recurrente_Svix_Verifier::matching_keys(
			Recurrente_Webhook_Registrar::secrets_by_mode(),
			$request->get_body(),
			$request->get_header( 'svix-id' ),
			$request->get_header( 'svix-timestamp' ),
			$request->get_header( 'svix-signature' )
		);
		return 1 === count( $this->signing_modes );
	}

	private function dispatch( WP_REST_Request $request ) {
		$event   = json_decode( $request->get_body(), true );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid event' ), 400 );
		}
		$svix_id = $request->get_header( 'svix-id' );
		if ( $this->already_processed( $svix_id ) ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}
		$this->charge_id = $this->charge_id_of( $event, $svix_id );
		return $this->route_and_mark( $event, $svix_id );
	}

	/** Failed local refunds and locked charges stay unprocessed so Svix retries them. */
	private function route_and_mark( $event, $svix_id ) {
		try {
			$this->route_event( $event );
		} catch ( Recurrente_Order_Locked $e ) {
			return new WP_REST_Response( array( 'status' => 'order locked' ), 503 );
		} catch ( Recurrente_Payment_Binding_Unavailable $e ) {
			return new WP_REST_Response( array( 'status' => 'payment binding unavailable' ), 503 );
		} catch ( Recurrente_Refund_Failed $e ) {
			return new WP_REST_Response( array( 'status' => 'refund failed' ), 503 );
		} catch ( Recurrente_Charge_Locked $e ) {
			return new WP_REST_Response( array( 'status' => 'locked' ), 503 );
		}
		$this->mark_processed( $svix_id );
		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	private function route_event( $event ) {
		$type  = self::canonical_event_type( isset( $event['event_type'] ) ? $event['event_type'] : '' );
		$order = 0 === strpos( $type, 'subscription.' ) ? $this->subscription_order_from( $event ) : $this->order_from( $event );
		Recurrente_Logger::debug( 'webhook received', array( 'type' => $type, 'order' => $order ? $order->get_id() : null ) );
		$this->apply( $type, $order, $event );
	}

	private function apply( $type, $order, $event ) {
		if ( ! $order ) {
			return;
		}
		Recurrente_Order_Lock::run( $order->get_id(), function ( $fresh ) use ( $type, $event ) {
			$this->apply_locked( $type, $fresh, $event );
		} );
	}

	private function apply_locked( $type, $order, $event ) {
		$matched = 0 === strpos( $type, 'subscription.' ) ? $this->subscription_order_from( $event ) : $this->order_from( $event );
		if ( ! $matched || $matched->get_id() !== $order->get_id() || ! $this->environment_matches( $order, $event ) ) {
			return;
		}
		if ( self::PAID_EVENT === $type && ! $this->payment_matches( $order, $event ) ) {
			Recurrente_Logger::error( 'payment binding mismatch', array( 'order' => $order->get_id() ) );
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

	/**
	 * Keyed by canonical event name; subscription lifecycle events map 1:1.
	 * Every handler receives the order and the decoded event.
	 */
	private function handlers() {
		return array(
			self::PAID_EVENT          => array( $this, 'mark_paid' ),
			'refund.create'           => array( $this, 'refund_order' ),
			'subscription.create'     => array( 'Recurrente_Subscriptions', 'activate' ),
			'subscription.cancel'     => array( 'Recurrente_Subscriptions', 'cancel' ),
			'subscription.past_due'   => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.pause'      => array( 'Recurrente_Subscriptions', 'on_hold' ),
			'subscription.unpause'    => array( 'Recurrente_Subscriptions', 'reactivate' ),
			'subscription.reactivate' => array( 'Recurrente_Subscriptions', 'reactivate' ),
		);
	}

	/**
	 * Mark the order paid, once per charge. An older account gets the same
	 * charge as two Svix messages (legacy and unified names, distinct svix-ids),
	 * and Svix retries on top of that; the charge id identifies the charge
	 * across all of them, and Recurrente_Charge_Order_Link is the one record of
	 * which order each charge produced. For an already-paid subscription parent,
	 * a different charge is a renewal, delegated to the subscription handler.
	 */
	private function mark_paid( $order, $event ) {
		$this->remember_identity( $order, $event );
		if ( $order->is_paid() ) {
			$this->record_renewal_unless_first_charge( $order );
			return;
		}
		$this->complete_first_payment( $order );
	}

	private function remember_identity( $order, $event ) {
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		if ( ! is_array( $binding ) ) {
			$order->update_meta_data( '_recurrente_payment_binding', array( 'mode' => $this->signing_modes[0] ) );
		}
		if ( $this->subscription_order_metadata( $event ) ) {
			$order->update_meta_data( '_recurrente_subscription_id', $event['subscription']['id'] );
		}
		$order->save();
	}

	/** The link is written before the effect, under the charge lock, so a crash here is retried against the same order. */
	private function complete_first_payment( $order ) {
		Recurrente_Charge_Order_Link::claim( $this->charge_id, $order );
		$order->payment_complete( $order->get_meta( '_recurrente_checkout_id' ) );
	}

	/** A paid parent receiving its own first charge again (a retry, or the legacy twin) is a no-op; any other charge is a renewal. */
	private function record_renewal_unless_first_charge( $order ) {
		if ( Recurrente_Charge_Order_Link::charge_of( $order ) === $this->charge_id ) {
			Recurrente_Logger::debug( 'paid event ignored; charge already applied', array( 'charge' => $this->charge_id, 'order' => $order->get_id() ) );
			return;
		}
		Recurrente_Subscriptions::record_renewal( $order, $this->charge_id );
	}

	/**
	 * The charge's id, spelled the same in both event formats: the legacy
	 * payload's `id` is the unified payload's `legacy_id` (the unified `id` is
	 * the newer `in_` prefixed one, so it can't match the two). A payload
	 * without an id falls back to the svix-id.
	 */
	private function charge_id_of( $event, $svix_id ) {
		foreach ( array( 'legacy_id', 'id' ) as $key ) {
			if ( ! empty( $event[ $key ] ) ) {
				return (string) $event[ $key ];
			}
		}
		return (string) $svix_id;
	}

	/**
	 * A refund in Recurrente refunds and restocks the WooCommerce order for
	 * exactly the refunded amount, de-duplicated by the Recurrente refund id.
	 */
	private function refund_order( $order, $event ) {
		Recurrente_Refund_Sync::apply( $order, $event );
	}

	/** SubscriptionSerializer#webhook_view has no checkout; PaymentSerializer exposes the subscription metadata on paymentable. */
	private function subscription_order_from( $event ) {
		$metadata = $this->subscription_metadata( $event );
		if ( ! is_array( $metadata ) || ! isset( $metadata['wc_order_id'], $metadata['wc_order_key'] )
			|| ! ( is_int( $metadata['wc_order_id'] ) || is_string( $metadata['wc_order_id'] ) )
			|| ! ctype_digit( (string) $metadata['wc_order_id'] )
			|| ! is_string( $metadata['wc_order_key'] ) ) {
			return false;
		}
		$order = $this->valid_order( wc_get_order( (int) $metadata['wc_order_id'] ), $metadata );
		if ( ! $order || ! $this->attempt_matches( $order, $metadata ) ) {
			return false;
		}
		$remote_id = $order->get_meta( '_recurrente_subscription_id' );
		return '' === $remote_id || $remote_id === ( $event['id'] ?? null ) ? $order : false;
	}

	/** Subscription-level order references take precedence; never bypass an invalid or partial reference with a fallback. */
	private function subscription_metadata( $event ) {
		$metadata = isset( $event['metadata'] ) ? $event['metadata'] : array();
		if ( is_array( $metadata ) && ( array_key_exists( 'wc_order_id', $metadata ) || array_key_exists( 'wc_order_key', $metadata ) ) ) {
			return $metadata;
		}
		return isset( $event['payment']['paymentable']['metadata'] ) ? $event['payment']['paymentable']['metadata'] : array();
	}

	private function order_from( $event ) {
		if ( 'refund.create' === ( $event['event_type'] ?? '' ) ) {
			return $this->refund_order_from( $event );
		}
		$metadata = isset( $event['checkout']['metadata'] ) ? $event['checkout']['metadata'] : array();
		if ( self::PAID_EVENT === self::canonical_event_type( $event['event_type'] ?? '' ) ) {
			$metadata = $this->paid_order_metadata( $event, $metadata );
		}
		$id    = $metadata['wc_order_id'] ?? null;
		$order = ( is_int( $id ) || is_string( $id ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $id ) ? wc_get_order( (int) $id ) : false;
		return $this->valid_order( $order, $metadata );
	}

	/** RefundSerializer nests the original intent; older flat envelopes remain supported. */
	private function refund_order_from( $event ) {
		$intent   = array_key_exists( 'intentable', $event ) ? $event['intentable'] : $event;
		$metadata = $intent['checkout']['metadata'] ?? array();
		$id       = $metadata['wc_order_id'] ?? null;
		if ( ! ( is_int( $id ) || is_string( $id ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id )
			|| ! isset( $metadata['wc_order_key'] ) || ! is_string( $metadata['wc_order_key'] ) ) {
			return false;
		}
		return $this->valid_order( wc_get_order( (int) $id ), $metadata );
	}

	/**
	 * Reminder checkouts have no Woo metadata. Rails keeps the original metadata
	 * on payment.paymentable (Subscription) in both intent formats. Never fall
	 * back past an explicit checkout identity, even if its key is invalid.
	 */
	private function paid_order_metadata( $event, $metadata ) {
		if ( is_array( $metadata ) && ( array_key_exists( 'wc_order_id', $metadata ) || array_key_exists( 'wc_order_key', $metadata ) ) ) {
			return $metadata;
		}
		return $this->subscription_order_metadata( $event );
	}

	private function subscription_order_metadata( $event ) {
		$paymentable = $event['payment']['paymentable'] ?? array();
		$id          = $paymentable['id'] ?? null;
		if ( 'Subscription' !== ( $paymentable['type'] ?? null ) || ! is_string( $id ) || '' === $id || $id !== ( $event['subscription']['id'] ?? null ) ) {
			return array();
		}
		$metadata = $paymentable['metadata'] ?? array();
		return is_array( $metadata ) ? $metadata : array();
	}

	private function valid_order( $order, $metadata ) {
		if ( ! $order || ! is_array( $metadata ) || ! isset( $metadata['wc_order_key'] ) || ! is_string( $metadata['wc_order_key'] ) ) {
			return false;
		}
		return hash_equals( $order->get_order_key(), $metadata['wc_order_key'] ) ? $order : false;
	}

	/** Endpoint identity is authoritative; settings may have changed since this order was created. */
	private function environment_matches( $order, $event ) {
		$mode    = $this->signing_modes[0];
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		$intent  = $event['intentable'] ?? $event;
		$checkout = $intent['checkout'] ?? array();
		if ( ! is_array( $checkout ) ) {
			return false;
		}
		if ( is_array( $binding ) && isset( $binding['mode'] ) && $binding['mode'] !== $mode ) {
			return false;
		}
		// Root live_mode identifies Sandbox accounts, not legacy TEST-key checkouts on live accounts.
		if ( array_key_exists( 'live_mode', $checkout ) ) {
			return is_bool( $checkout['live_mode'] ) && $checkout['live_mode'] === ( 'live' === $mode );
		}
		return ! array_key_exists( 'live_mode', $intent ) || ( is_bool( $intent['live_mode'] ) && $intent['live_mode'] === ( 'live' === $mode ) );
	}

	private function payment_matches( $order, $event ) {
		$checkout = $event['checkout'] ?? array();
		$expected = $order->get_meta( '_recurrente_checkout_id' );
		if ( ! is_string( $expected ) || '' === $expected || ! is_string( $checkout['id'] ?? null ) ) {
			return false;
		}
		if ( $expected !== $checkout['id'] ) {
			return $order->is_paid() && $this->renewal_matches( $order, $event );
		}
		if ( $order->is_paid() && Recurrente_Charge_Order_Link::charge_of( $order ) !== $this->charge_id ) {
			return $this->renewal_matches( $order, $event );
		}
		return $this->first_amount_matches( $order, $event );
	}

	private function first_amount_matches( $order, $event ) {
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		$amount  = (int) round( (float) $order->get_total() * 100 );
		$currency = $order->get_currency();
		if ( is_array( $binding ) && isset( $binding['amount'], $binding['currency'] ) ) {
			if ( $amount !== $binding['amount'] || $currency !== $binding['currency'] ) {
				return false;
			}
		}
		if ( ! $this->first_subscription_matches( $order, $event ) || ! $this->attempt_matches( $order, $event['checkout']['metadata'] ?? array() ) ) {
			return false;
		}
		$dynamic = is_array( $binding ) && ! empty( $binding['dynamic'] );
		if ( $dynamic && $amount !== ( $binding['base_amount'] ?? null ) ) {
			return false;
		}
		return $this->amount_matches( $event, $amount, $currency, $dynamic );
	}

	private function first_subscription_matches( $order, $event ) {
		if ( 'Subscription' !== ( $event['payment']['paymentable']['type'] ?? null ) ) {
			return true;
		}
		$metadata = $this->subscription_order_metadata( $event );
		return $this->valid_order( $order, $metadata ) && (string) $order->get_id() === (string) ( $metadata['wc_order_id'] ?? '' )
			&& $this->attempt_matches( $order, $metadata );
	}

	/** A different checkout is only a renewal of an already-paid, matching subscription. */
	private function renewal_matches( $order, $event ) {
		$metadata = $this->subscription_order_metadata( $event );
		if ( ! $this->attempt_matches( $order, $metadata ) || ! $this->valid_order( $order, $metadata ) || (string) $order->get_id() !== (string) ( $metadata['wc_order_id'] ?? '' ) ) {
			return false;
		}
		$remote_id = $order->get_meta( '_recurrente_subscription_id' );
		if ( '' !== $remote_id && $remote_id !== $event['subscription']['id'] ) {
			return false;
		}
		$subscriptions = class_exists( 'WC_Subscriptions' ) ? wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) ) : array();
		$amount = 0;
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->get_currency() !== $order->get_currency() ) {
				return false;
			}
			$amount += (int) round( (float) $subscription->get_total() * 100 );
		}
		if ( ! $this->legacy_renewal_environment_matches( $order ) ) {
			return false;
		}
		return $subscriptions && is_int( $event['amount_in_cents'] ?? null )
			&& $amount > 0 && $amount === $event['amount_in_cents'] && $order->get_currency() === ( $event['currency'] ?? null );
	}

	/** Never infer an old order's environment from today's gateway toggle or a new reminder. */
	private function legacy_renewal_environment_matches( $order ) {
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		if ( is_array( $binding ) && isset( $binding['mode'] ) ) {
			return true;
		}
		$mode     = $this->signing_modes[0];
		$settings = get_option( 'woocommerce_recurrente_settings', array() );
		$key      = $settings[ $mode . '_secret_key' ] ?? '';
		if ( ! $key ) {
			throw new Recurrente_Payment_Binding_Unavailable();
		}
		try {
			$client = new Recurrente_API_Client( $key );
			$checkout = $client->get_checkout( $order->get_meta( '_recurrente_checkout_id' ) );
		} catch ( Recurrente_API_Exception $e ) {
			if ( 404 === $e->getCode() ) {
				return false;
			}
			throw new Recurrente_Payment_Binding_Unavailable();
		}
		return $order->get_meta( '_recurrente_checkout_id' ) === ( $checkout['id'] ?? null )
			&& ( 'live' === $mode ) === ( $checkout['live_mode'] ?? null );
	}

	private function attempt_matches( $order, $metadata ) {
		$binding = $order->get_meta( '_recurrente_payment_binding' );
		return ! is_array( $binding ) || ! isset( $binding['attempt'] )
			|| $binding['attempt'] === ( $metadata['wc_payment_attempt'] ?? null );
	}

	/** Checkout totals include buyer surcharges; never use processor fees to guess the merchant amount. */
	private function amount_matches( $event, $amount, $currency, $dynamic ) {
		$charged = $event['amount_in_cents'] ?? null;
		$checkout = $event['checkout'];
		if ( ! is_int( $charged ) || $charged <= 0 || $currency !== ( $event['currency'] ?? null )
			|| $currency !== ( $checkout['currency'] ?? null ) || $charged !== ( $checkout['total_in_cents'] ?? null ) ) {
			return false;
		}
		return $dynamic ? $charged >= $amount : $charged === $amount;
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

/** A temporary identity lookup failure must leave the delivery retryable. */
class Recurrente_Payment_Binding_Unavailable extends RuntimeException {}
