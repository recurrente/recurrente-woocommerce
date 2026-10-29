<?php
/** Durable outbound refund requests. All journal changes run under the order lock. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-recurrente-refund-admin.php';

class Recurrente_Refund_Operations {
	const META = '_recurrente_refund_operations';
	const REMOTE_META = '_recurrente_refund_id';
	const OPERATION_META = '_recurrente_refund_operation';
	const RETRY_WINDOW = 23 * 60 * 60;

	private static $context = array();
	private static $native_locks = array();
	private static $guards = array();
	private $order;
	private $operations;

	public static function register_hooks() {
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'capture' ), 10, 2 );
		add_action( 'woocommerce_refund_created', array( __CLASS__, 'completed' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_recurrente_check_refund', array( __CLASS__, 'check_order' ) );
		add_action( 'shutdown', array( __CLASS__, 'release_native' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_script' ) );
		Recurrente_Refund_Admin::register_hooks();
	}

	public static function admin_script() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) { return; }
		$order_id = absint( $_GET['id'] ?? $_GET['post'] ?? 0 );
		$order = wc_get_order( $order_id );
		if ( ! $order || 'recurrente' !== $order->get_payment_method() || ! current_user_can( 'edit_shop_order', $order_id ) ) { return; }
		wp_enqueue_script( 'recurrente-admin-refunds', plugins_url( 'assets/js/admin-refunds.js', RECURRENTE_PLUGIN_FILE ), array( 'jquery', 'wp-a11y' ), RECURRENTE_PLUGIN_VERSION, true );
		wp_enqueue_script( 'recurrente-admin-refund-check', plugins_url( 'assets/js/admin-refund-check.js', RECURRENTE_PLUGIN_FILE ), array(), RECURRENTE_PLUGIN_VERSION, true );
		wp_enqueue_style( 'recurrente-admin-refunds', plugins_url( 'assets/css/admin-refunds.css', RECURRENTE_PLUGIN_FILE ), array(), RECURRENTE_PLUGIN_VERSION );
		$journal = new self( $order );
		$terminal = array_keys( array_filter( $journal->operations, function ( $operation ) {
			return in_array( $operation['state'], array( 'completed', 'failed', 'voided' ), true );
		} ) );
		$refund_rows = array();
		foreach ( $journal->operations as $key => $operation ) {
			if ( 'completed' === $operation['state'] ) { $refund_rows[ $key ] = $operation['wc_refund_id']; }
		}
		wp_localize_script( 'recurrente-admin-refunds', 'recurrenteRefunds', array(
			'orderId' => (string) $order_id, 'terminalKeys' => $terminal, 'refundRows' => $refund_rows,
			'success' => __( 'Reembolso confirmado y registrado en WooCommerce.', 'recurrente-for-woocommerce' ),
			'blocked' => $journal->active_key()
				? __( 'No se envió esta solicitud: el navegador no pudo guardar sus datos. Usá «Consultar reembolso de Recurrente» en Acciones del pedido para revisar la solicitud pendiente.', 'recurrente-for-woocommerce' )
				: __( 'No se envió esta solicitud: este navegador no pudo guardar sus datos. Abrí el pedido en una pestaña nueva y volvé a intentarlo.', 'recurrente-for-woocommerce' ),
		) );
	}

	/** WooCommerce validates its admin nonce / REST authorization before this hook. */
	public static function capture( $refund, $args ) {
		$order = wc_get_order( $args['order_id'] );
		if ( empty( $args['refund_payment'] ) || ! $order || 'recurrente' !== $order->get_payment_method() ) {
			return;
		}
		self::authorize( $order->get_id() );
		self::cents( $args['amount'] );
		$token = $_SERVER['HTTP_X_RECURRENTE_REFUND_OPERATION'] ?? '';
		if ( ! empty( $args['refund_id'] ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $token ) ) {
			throw new RuntimeException( __( 'No se pudo identificar esta solicitud de reembolso. Recargá el pedido antes de volver a intentarlo.', 'recurrente-for-woocommerce' ) );
		}
		self::acquire_native( $order->get_id() );
		self::$native_locks[ $order->get_id() ] = true;
		$refund->update_meta_data( self::OPERATION_META, $token );
		self::$context[ $order->get_id() ] = array( 'refund' => $refund, 'args' => $args );
	}

	/** Keep callbacks out until core finishes accounting OR deletes its provisional refund. */
	public static function release_native() {
		foreach ( self::$native_locks as $order_id => $_held ) { self::unlock_native( $order_id ); }
		self::$native_locks = array();
	}

	public static function without_native_request( $order_id, callable $body ) {
		if ( isset( self::$guards[ $order_id ] ) ) { return $body(); }
		self::acquire_native( $order_id );
		self::$guards[ $order_id ] = true;
		try { return $body(); }
		finally {
			unset( self::$guards[ $order_id ] );
			self::unlock_native( $order_id );
		}
	}

	private static function acquire_native( $order_id ) {
		global $wpdb;
		$name = 'recurrente_refund_' . md5( (string) $order_id );
		if ( isset( self::$native_locks[ $order_id ] ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 0 ) ) ) {
			throw new Recurrente_Refund_Failed( __( 'Hay un reembolso de WooCommerce en curso. Volvé a consultar en unos segundos.', 'recurrente-for-woocommerce' ) );
		}
	}

	private static function unlock_native( $order_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'recurrente_refund_' . md5( (string) $order_id ) ) );
	}

	public static function process( $order_id, $amount, $reason ) {
		try {
			self::authorize( $order_id );
			return Recurrente_Order_Lock::run( $order_id, function ( $order ) use ( $amount, $reason ) {
				return ( new self( $order ) )->initiate( $amount, $reason );
			} );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'recurrente_refund_unconfirmed', $e->getMessage() );
		} finally {
			unset( self::$context[ $order_id ] );
		}
	}

	public static function completed( $refund_id, $args ) {
		if ( empty( $args['refund_payment'] ) || 'recurrente' !== wc_get_order( $args['order_id'] )->get_payment_method() ) { return; }
		Recurrente_Order_Lock::run( $args['order_id'], function ( $order ) use ( $refund_id ) {
			$journal = new self( $order );
			$journal->finish_native( $refund_id );
		} );
	}

	public static function order_actions( $actions, $order ) {
		if ( self::can_check( $order ) ) {
			$actions['recurrente_check_refund'] = __( 'Consultar reembolso de Recurrente', 'recurrente-for-woocommerce' );
		}
		return $actions;
	}

	public static function can_check( $order ) {
		return $order && 'recurrente' === $order->get_payment_method()
			&& current_user_can( 'edit_shop_order', $order->get_id() ) && ( new self( $order ) )->active_key();
	}

	/** Explicit order action; reads a known ID, or retries the original body/key within 23h. */
	public static function check_order( $order ) {
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { return; }
		try {
			self::authorize( $order->get_id() );
			$notice = Recurrente_Order_Lock::run( $order->get_id(), function ( $fresh ) {
				return self::without_native_request( $fresh->get_id(), function () use ( $fresh ) {
					( new self( $fresh ) )->reconcile();
					$fresh->read_meta_data( true );
					return ( new self( $fresh ) )->result_message();
				} );
			} );
		} catch ( Recurrente_Order_Locked | Recurrente_Charge_Locked $e ) {
			Recurrente_Logger::error( $e->getMessage(), array( 'order_id' => $order->get_id() ) );
			$message = __( 'No se pudo consultar este reembolso. Esperá unos segundos y volvé a intentarlo.', 'recurrente-for-woocommerce' );
			$order->add_order_note( $message );
			$notice = array( 'type' => 'warning', 'message' => $message );
		} catch ( Recurrente_Refund_Failed $e ) {
			Recurrente_Logger::error( $e->getMessage(), array( 'order_id' => $order->get_id() ) );
			$message = __( 'No se pudo registrar este reembolso en WooCommerce. Contactá a soporte de Recurrente con el número de pedido.', 'recurrente-for-woocommerce' );
			$order->add_order_note( $message );
			$notice = array( 'type' => 'error', 'message' => $message );
		} catch ( RuntimeException $e ) {
			$order->add_order_note( $e->getMessage() );
			$notice = array( 'type' => 'warning', 'message' => $e->getMessage() );
		}
		Recurrente_Refund_Admin::remember_result( $order->get_id(), $notice );
	}

	/** Returns true when native Woo accounting already exists; unknown origins stay retryable. */
	public static function before_mirror( $order, $event ) {
		return ( new self( $order ) )->observe( $event );
	}

	public static function mirror_args( $order, $remote_id ) {
		$journal = new self( $order );
		foreach ( $journal->operations as $operation ) {
			if ( $remote_id === $operation['refund_id'] ) {
				$args = $operation['accounting'];
				if ( ! empty( $operation['native_accepted'] ) ) { $args['restock_items'] = false; }
				return $args;
			}
		}
		return array();
	}

	public static function operation_id_for( $order, $remote_id ) {
		$journal = new self( $order );
		foreach ( $journal->operations as $key => $operation ) {
			if ( $remote_id === $operation['refund_id'] ) { return substr( $key, strlen( 'woo_refund_' ) ); }
		}
		return '';
	}

	public static function mirrored( $order, $remote_id ) {
		$journal = new self( $order );
		foreach ( $journal->operations as $key => $operation ) {
			if ( $remote_id === $operation['refund_id'] ) {
				$journal->update( $key, array( 'state' => 'completed' ) );
			}
		}
	}

	private function __construct( $order ) {
		$this->order = $order;
		$operations = $order->get_meta( self::META );
		$this->operations = is_array( $operations ) ? $operations : array();
	}

	private static function authorize( $order_id ) {
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			throw new RuntimeException( __( 'No tenés permiso para reembolsar este pedido.', 'recurrente-for-woocommerce' ) );
		}
	}

	private function initiate( $amount, $reason ) {
		$context = $this->native_context( $amount, $reason );
		$key = 'woo_refund_' . $context['refund']->get_meta( self::OPERATION_META );
		if ( isset( $this->operations[ $key ] ) ) {
			throw new RuntimeException( $this->status_message( $key, false ) );
		}
		if ( $this->active_key() ) {
			throw new RuntimeException( __( 'Ya existe una solicitud de reembolso. Usá la acción «Consultar reembolso de Recurrente» antes de solicitar otro.', 'recurrente-for-woocommerce' ) );
		}
		$key = $this->prepare( $context );
		$resource = $this->send( $key );
		if ( 'succeeded' !== $this->operations[ $key ]['state'] ) {
			throw new RuntimeException( $this->status_message( $key, false ) );
		}
		$this->update( $key, array( 'native_accepted' => true ) );
		$context['refund']->update_meta_data( self::REMOTE_META, $resource['id'] );
		$context['refund']->save();
		return true;
	}

	private function native_context( $amount, $reason ) {
		$context = self::$context[ $this->order->get_id() ] ?? null;
		if ( ! $context || ! $context['refund']->get_id() || $context['refund']->get_parent_id() !== $this->order->get_id()
			|| self::cents( $amount ) !== self::cents( $context['refund']->get_amount() )
			|| (string) $reason !== (string) $context['refund']->get_reason() ) {
			throw new RuntimeException( __( 'Iniciá el reembolso desde el formulario de reembolsos del pedido.', 'recurrente-for-woocommerce' ) );
		}
		return $context;
	}

	/** The original confirmed charge and mode are mandatory; today's gateway toggle is irrelevant. */
	private function payment_identity() {
		$intent = Recurrente_Charge_Order_Link::charge_of( $this->order );
		$binding = $this->order->get_meta( '_recurrente_payment_binding' );
		$mode = is_array( $binding ) ? ( $binding['mode'] ?? '' ) : '';
		$orders = $this->orders_for_intent( $intent );
		if ( 'recurrente' !== $this->order->get_payment_method() || ! $this->order->is_paid()
			|| ! preg_match( '/^pa_[A-Za-z0-9]+$/D', $intent ) || ! in_array( $mode, array( 'test', 'live' ), true )
			|| 1 !== count( $orders ) || $orders[0]->get_id() !== $this->order->get_id()
			|| ! in_array( $this->order->get_currency(), array( 'GTQ', 'USD' ), true )
			|| ( isset( $binding['amount'] ) && $binding['amount'] !== self::cents( $this->order->get_total() ) )
			|| ( isset( $binding['currency'] ) && $binding['currency'] !== $this->order->get_currency() ) ) {
			throw new RuntimeException( __( 'No se puede reembolsar este pedido mediante Recurrente porque no encontramos su pago original. Contactá a soporte de Recurrente.', 'recurrente-for-woocommerce' ) );
		}
		return array( 'intent' => $intent, 'mode' => $mode, 'currency' => $this->order->get_currency() );
	}

	/** CPT deliberately ignores meta_query; keep the storage-specific query inside Woo's API. */
	private function orders_for_intent( $intent ) {
		$query = array( 'meta_key' => Recurrente_Charge_Order_Link::META_KEY, 'meta_value' => $intent );
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$query = array( 'meta_query' => array( array( 'key' => Recurrente_Charge_Order_Link::META_KEY, 'value' => $intent ) ) );
		}
		return wc_get_orders( array_merge( array( 'limit' => 2, 'status' => 'any' ), $query ) );
	}

	private function prepare( $context ) {
		$identity = $this->payment_identity();
		$amount = self::cents( $context['refund']->get_amount() );
		// Woo has already saved this provisional refund; add it back for validation.
		$refunded = $this->order->get_data_store()->get_total_refunded( $this->order );
		$remaining = self::cents( $this->order->get_total() ) - self::cents( $refunded ) + $amount;
		if ( $amount <= 0 || $amount > $remaining ) {
			throw new RuntimeException( __( 'El monto debe ser positivo y no superar el saldo reembolsable.', 'recurrente-for-woocommerce' ) );
		}
		$key = 'woo_refund_' . $context['refund']->get_meta( self::OPERATION_META );
		$this->client( $identity );
		$this->operations[ $key ] = array_merge( $identity, array(
			'amount' => $amount, 'started_at' => time(), 'state' => 'unknown', 'refund_id' => '', 'native_accepted' => false,
			'wc_refund_id' => $context['refund']->get_id(),
			'payload' => array( 'payment_intent_id' => $identity['intent'], 'amount_in_cents' => $amount,
				'refund_application_fee' => false, 'require_new_refund' => true ),
			'accounting' => array( 'reason' => $context['refund']->get_reason(), 'line_items' => $context['args']['line_items'] ?? array(),
				'restock_items' => ! empty( $context['args']['restock_items'] ) ),
		) );
		$this->persist();
		$this->order->add_order_note( $this->requested_message( $key ) );
		return $key;
	}

	private static function cents( $value ) {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || abs( (float) $value * 100 - round( (float) $value * 100 ) ) > 0.00001 ) {
			throw new RuntimeException( __( 'El monto del reembolso debe tener como máximo dos decimales.', 'recurrente-for-woocommerce' ) );
		}
		return (int) round( (float) $value * 100 );
	}

	private function client( $operation ) {
		$settings = get_option( 'woocommerce_recurrente_settings', array() );
		$key = $settings[ $operation['mode'] . '_secret_key' ] ?? '';
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			throw new RuntimeException( __( 'Falta la llave del ambiente original del pedido.', 'recurrente-for-woocommerce' ) );
		}
		return new Recurrente_API_Client( $key );
	}

	private function send( $key ) {
		$operation = $this->operations[ $key ];
		if ( time() - $operation['started_at'] >= self::RETRY_WINDOW ) {
			throw new RuntimeException( __( 'Resultado desconocido fuera de la ventana segura. Soporte debe reconciliar la solicitud original; no generés otra.', 'recurrente-for-woocommerce' ) );
		}
		try {
			$resource = $this->client( $operation )->create_refund( $operation['payload'], $key );
		} catch ( Recurrente_API_Exception $e ) {
			$data = $e->response_data();
			if ( ! self::valid_id( $data['refund_id'] ?? null ) ) {
				$this->report_status_failure( $key );
			}
			$this->update( $key, array( 'refund_id' => $data['refund_id'] ) );
			$resource = $this->read( $key );
		}
		return $this->receive( $key, $resource );
	}

	private function read( $key ) {
		$operation = $this->operations[ $key ];
		try {
			return $this->client( $operation )->get_refund( $operation['refund_id'] );
		} catch ( Recurrente_API_Exception $e ) {
			$this->report_status_failure( $key );
		}
	}

	private static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^re_[A-Za-z0-9]+$/D', $id );
	}

	private function receive( $key, $resource ) {
		$id = $resource['id'] ?? null;
		$expected = $this->operations[ $key ]['refund_id'];
		if ( ! self::valid_id( $id ) || ( $expected && $id !== $expected ) ) {
			$this->report_status_failure( $key );
		}
		$this->update( $key, array( 'refund_id' => $id ) );
		$this->validate_resource( $key, $resource );
		$this->update( $key, array( 'state' => $resource['status'] ) );
		$this->order->add_order_note( $this->status_message( $key ) );
		return $resource;
	}

	private function validate_resource( $key, $resource ) {
		$operation = $this->operations[ $key ];
		$applied = $this->order->get_meta( Recurrente_Refund_Sync::APPLIED_META );
		$used = array_filter( $this->operations, function ( $other ) use ( $operation ) {
			return $other['refund_id'] === $operation['refund_id'];
		} );
		if ( 1 !== count( $used ) || ( is_array( $applied ) && in_array( $operation['refund_id'], $applied, true )
				&& 'completed' !== $operation['state'] && ! $this->owns_accounting( $key ) )
			|| $operation['amount'] !== ( $resource['customer_refunded_amount_in_cents'] ?? null )
			|| $operation['currency'] !== ( $resource['currency'] ?? null )
			|| ! in_array( $resource['status'] ?? '', array( 'pending', 'succeeded', 'failed', 'voided' ), true )
			|| ( $resource['refund_application_fee'] ?? false ) !== false ) {
			$message = __( 'El reembolso en Recurrente no coincide con este pedido (monto o moneda). No lo registramos en WooCommerce; contactá a soporte de Recurrente.', 'recurrente-for-woocommerce' );
			$this->order->add_order_note( $message . $this->status_reference( $key, true ) );
			throw new RuntimeException( $message . $this->status_reference( $key, false ) );
		}
	}

	private function owns_accounting( $key ) {
		foreach ( $this->order->get_refunds() as $refund ) {
			if ( substr( $key, strlen( 'woo_refund_' ) ) === $refund->get_meta( self::OPERATION_META ) && $this->native_matches( $refund, $this->operations[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function reconcile() {
		$key = $this->active_key();
		if ( ! $key ) { return; }
		$operation = $this->operations[ $key ];
		$resource = $operation['refund_id'] ? $this->receive( $key, $this->read( $key ) ) : $this->send( $key );
		if ( 'succeeded' === $resource['status'] ) {
			Recurrente_Refund_Sync::apply( $this->order, array( 'refund' => $resource,
				'intentable' => array( 'id' => $operation['intent'], 'type' => 'PaymentIntent' ) ) );
		}
	}

	private function observe( $event ) {
		$id = $event['refund']['id'] ?? '';
		foreach ( $this->operations as $key => $operation ) {
			if ( '' !== $id && $operation['refund_id'] === $id ) {
				return $this->observe_known( $key, $event );
			}
		}
		$key = $this->active_key();
		if ( $key && ! $this->operations[ $key ]['refund_id'] ) {
			throw new Recurrente_Refund_Failed( 'Outbound refund identity is still unknown' );
		}
		return false;
	}

	private function observe_known( $key, $event ) {
		$operation = $this->operations[ $key ];
		if ( $operation['intent'] !== ( $event['intentable']['id'] ?? '' ) || 'PaymentIntent' !== ( $event['intentable']['type'] ?? '' ) ) {
			throw new Recurrente_Refund_Failed( 'Refund intent does not match the outbound operation' );
		}
		try {
			$this->validate_resource( $key, $event['refund'] );
		} catch ( RuntimeException $e ) {
			throw new Recurrente_Refund_Failed( $e->getMessage() );
		}
		if ( 'succeeded' !== $event['refund']['status'] ) { return true; }
		$native = wc_get_order( $operation['wc_refund_id'] );
		if ( ! $native ) { return false; }
		if ( ! $this->native_matches( $native, $operation ) ) {
			throw new Recurrente_Refund_Failed( 'Native Woo refund has not finished; retry after rollback or reconcile with support' );
		}
		$this->finish( $key );
		return true;
	}

	private function native_matches( $refund, $operation ) {
		return 'shop_order_refund' === $refund->get_type() && $refund->get_parent_id() === $this->order->get_id()
			&& $refund->get_meta( self::REMOTE_META ) === $operation['refund_id']
			&& self::cents( $refund->get_amount() ) === $operation['amount'] && $refund->get_currency() === $operation['currency'];
	}

	private function finish_native( $refund_id ) {
		foreach ( $this->operations as $key => $operation ) {
			if ( $operation['wc_refund_id'] === $refund_id && 'succeeded' === $operation['state']
				&& $this->native_matches( wc_get_order( $refund_id ), $operation ) ) {
				$this->finish( $key );
			}
		}
	}

	private function finish( $key ) {
		$ids = $this->order->get_meta( Recurrente_Refund_Sync::APPLIED_META );
		$ids = is_array( $ids ) ? $ids : array();
		$ids[] = $this->operations[ $key ]['refund_id'];
		$this->order->update_meta_data( Recurrente_Refund_Sync::APPLIED_META, array_values( array_unique( $ids ) ) );
		$this->update( $key, array( 'state' => 'completed' ) );
	}

	private function active_key() {
		foreach ( $this->operations as $key => $operation ) {
			if ( ! in_array( $operation['state'], array( 'completed', 'failed', 'voided' ), true ) ) { return $key; }
		}
		return null;
	}

	private function update( $key, $changes ) {
		$this->operations[ $key ] = array_merge( $this->operations[ $key ], $changes );
		$this->persist();
	}

	private function persist() {
		$this->order->update_meta_data( self::META, $this->operations );
		$this->order->save();
	}

	private function amount_label( $key ) {
		$operation = $this->operations[ $key ];
		return html_entity_decode( wp_strip_all_tags( wc_price( $operation['amount'] / 100, array( 'currency' => $operation['currency'] ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	private function result_message() {
		$key = $this->active_key() ?: array_key_last( $this->operations );
		if ( null === $key ) { return array( 'type' => 'info', 'message' => __( 'No hay solicitudes de reembolso pendientes.', 'recurrente-for-woocommerce' ) ); }
		$state = $this->operations[ $key ]['state'];
		$type = 'completed' === $state ? 'success' : ( in_array( $state, array( 'failed', 'voided' ), true ) ? 'error' : 'warning' );
		return array( 'type' => $type, 'message' => $this->status_message( $key, false ) );
	}

	private function status_reference( $key, $include_key ) {
		$references = array_filter( array( $this->operations[ $key ]['refund_id'], $include_key ? $key : null ) );
		return $references ? ' (' . implode( ' · ', $references ) . ')' : '';
	}

	private function requested_message( $key ) {
		/* translators: %s: refund amount formatted by WooCommerce. */
		return sprintf( __( 'Solicitud de reembolso de %s registrada.', 'recurrente-for-woocommerce' ), $this->amount_label( $key ) ) . $this->status_reference( $key, true );
	}

	private function report_status_failure( $key ) {
		$this->order->add_order_note( $this->status_message( $key ) );
		throw new RuntimeException( $this->status_message( $key, false ) );
	}

	private function status_message( $key, $include_key = true ) {
		$operation = $this->operations[ $key ];
		$states = array(
			'unknown' => __( 'sin confirmar; no solicités otro reembolso', 'recurrente-for-woocommerce' ),
			'pending' => __( 'pendiente en Recurrente; todavía no se registró en WooCommerce', 'recurrente-for-woocommerce' ),
			'succeeded' => __( 'confirmado en Recurrente', 'recurrente-for-woocommerce' ),
			'failed' => __( 'fallido; Recurrente no confirmó devolución de dinero', 'recurrente-for-woocommerce' ),
			'voided' => __( 'anulado en Recurrente', 'recurrente-for-woocommerce' ),
			'completed' => __( 'confirmado y registrado en WooCommerce', 'recurrente-for-woocommerce' ),
		);
		/* translators: 1: formatted refund amount, 2: refund status. */
		$message = sprintf( __( 'Reembolso de %1$s %2$s.', 'recurrente-for-woocommerce' ), $this->amount_label( $key ), $states[ $operation['state'] ] ?? $operation['state'] );
		if ( in_array( $operation['state'], array( 'unknown', 'pending' ), true ) ) {
			$message .= ' ' . __( 'Para actualizarlo, elegí «Consultar reembolso de Recurrente» en Acciones del pedido.', 'recurrente-for-woocommerce' );
		}
		return $message . $this->status_reference( $key, $include_key );
	}
}
