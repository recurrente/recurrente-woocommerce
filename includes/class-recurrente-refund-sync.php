<?php
/**
 * Mirrors one Recurrente refund onto its WooCommerce order.
 *
 * `refund.create` is emitted once per refund, after it succeeds (the `refund`
 * object carries `id`, `status`, `currency` and the amount returned to the
 * buyer in cents). We refund exactly that amount — a partial refund in
 * Recurrente is a partial refund here — and remember the refund id on the
 * order so Svix's at-least-once delivery, or a re-sent event, never refunds
 * the same money twice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Local mirroring failed; the delivery must remain retryable. */
class Recurrente_Refund_Failed extends RuntimeException {}

class Recurrente_Refund_Sync {

	/** Order meta: list of Recurrente refund ids already applied. */
	const APPLIED_META = '_recurrente_refund_ids';

	private $order;
	private $refund;
	private static $creating = null;

	public static function register_hooks() {
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'stamp' ), 10, 2 );
	}

	/** Persist remote identity in the same save as the refund, before accounting hooks. */
	public static function stamp( $refund, $args ) {
		if ( self::$creating && self::$creating['order_id'] === $args['order_id'] && empty( $args['refund_payment'] ) ) {
			$refund->update_meta_data( Recurrente_Refund_Operations::REMOTE_META, self::$creating['id'] );
			$refund->update_meta_data( Recurrente_Refund_Operations::OPERATION_META, self::$creating['operation_id'] );
		}
	}

	public static function apply( $order, $event ) {
		Recurrente_Refund_Operations::without_native_request( $order->get_id(), function () use ( $order, $event ) {
			self::apply_available( $order, $event );
		} );
	}

	private static function apply_available( $order, $event ) {
		if ( Recurrente_Refund_Operations::before_mirror( $order, $event ) ) { return; }
		$refund = isset( $event['refund'] ) && is_array( $event['refund'] ) ? $event['refund'] : array();
		$sync   = new self( $order, $refund );
		$sync->run();
	}

	private function __construct( $order, $refund ) {
		$this->order  = $order;
		$this->refund = $refund;
	}

	private function run() {
		foreach ( $this->order->get_refunds() as $existing ) {
			if ( $this->id() && $this->id() === $existing->get_meta( Recurrente_Refund_Operations::REMOTE_META ) ) {
				if ( $this->currency() !== $existing->get_currency() || abs( $this->amount() - (float) $existing->get_amount() ) > 0.00001 ) {
					throw new Recurrente_Refund_Failed( 'Existing Woo refund does not match the remote amount/currency' );
				}
				$this->remember_applied();
				return;
			}
		}
		$rejection = $this->rejection();
		if ( $rejection ) {
			$this->reject( $rejection );
			return;
		}
		$this->create_refund();
	}

	/** The first check that fails names why the refund is skipped; null applies it. */
	private function rejection() {
		foreach ( $this->checks() as $reason => $passes ) {
			if ( ! $passes ) {
				return $reason;
			}
		}
		return null;
	}

	private function checks() {
		return array(
			'malformed'  => '' !== $this->id() && $this->amount() > 0,
			'duplicate'  => ! in_array( $this->id(), $this->applied_ids(), true ),
			'status'     => 'succeeded' === $this->status(),
			'currency'   => $this->currency() === $this->order->get_currency(),
			'exceeds'    => $this->amount() <= $this->order->get_remaining_refund_amount() + 0.005,
		);
	}

	private function create_refund() {
		self::$creating = array( 'order_id' => $this->order->get_id(), 'id' => $this->id(),
			'operation_id' => Recurrente_Refund_Operations::operation_id_for( $this->order, $this->id() ) );
		try {
			$refund = wc_create_refund( $this->refund_args() );
		} finally {
			self::$creating = null;
		}
		if ( is_wp_error( $refund ) ) {
			Recurrente_Logger::error( 'refund failed', $this->context( array( 'error' => $refund->get_error_message() ) ) );
			throw new Recurrente_Refund_Failed( 'WooCommerce refund could not be created' );
		}
		$this->remember_applied();
	}

	private function refund_args() {
		return array_merge( array(
			'order_id'      => $this->order->get_id(),
			'amount'        => $this->amount(),
			'refund_payment' => false,
			'restock_items' => true,
			/* translators: %s: Recurrente refund id */
			'reason'        => sprintf( __( 'Reembolsado en Recurrente (%s).', 'recurrente-for-woocommerce' ), $this->id() ),
		), Recurrente_Refund_Operations::mirror_args( $this->order, $this->id() ) );
	}

	/** The id is only recorded once the WC refund exists, so a failed attempt is retried by Svix. */
	private function remember_applied() {
		$ids   = $this->applied_ids();
		if ( ! in_array( $this->id(), $ids, true ) ) {
			$ids[] = $this->id();
			$this->order->update_meta_data( self::APPLIED_META, $ids );
			$this->order->save();
		}
		Recurrente_Refund_Operations::mirrored( $this->order, $this->id() );
		Recurrente_Logger::debug( 'refund applied', $this->context() );
	}

	private function reject( $reason ) {
		Recurrente_Logger::debug( 'refund skipped', $this->context( array( 'reason' => $reason ) ) );
		if ( in_array( $reason, array( 'exceeds', 'currency' ), true ) ) {
			$this->order->add_order_note( $this->rejection_note( $reason ) );
		}
	}

	private function rejection_note( $reason ) {
		$notes = array(
			/* translators: 1: refund id, 2: amount, 3: currency */
			'exceeds'  => __( 'Reembolso de Recurrente %1$s por %2$s %3$s no aplicado: supera el monto reembolsable restante de la orden.', 'recurrente-for-woocommerce' ),
			/* translators: 1: refund id, 2: amount, 3: currency */
			'currency' => __( 'Reembolso de Recurrente %1$s por %2$s %3$s no aplicado: la moneda no coincide con la de la orden.', 'recurrente-for-woocommerce' ),
		);
		return sprintf( $notes[ $reason ], $this->id(), number_format( $this->amount(), 2, '.', '' ), $this->currency() );
	}

	private function applied_ids() {
		$ids = $this->order->get_meta( self::APPLIED_META );
		return is_array( $ids ) ? $ids : array();
	}

	private function id() {
		return isset( $this->refund['id'] ) ? (string) $this->refund['id'] : '';
	}

	private function status() {
		return isset( $this->refund['status'] ) ? (string) $this->refund['status'] : '';
	}

	private function currency() {
		return isset( $this->refund['currency'] ) ? strtoupper( (string) $this->refund['currency'] ) : '';
	}

	/** Recurrente sends cents; WooCommerce refunds in major units. */
	private function amount() {
		$cents = isset( $this->refund['customer_refunded_amount_in_cents'] ) ? (int) $this->refund['customer_refunded_amount_in_cents'] : 0;
		return round( $cents / 100, 2 );
	}

	private function context( $extra = array() ) {
		$base = array(
			'order'  => $this->order->get_id(),
			'refund' => $this->id(),
			'amount' => $this->amount(),
			'status' => $this->status(),
		);
		return array_merge( $base, $extra );
	}
}
