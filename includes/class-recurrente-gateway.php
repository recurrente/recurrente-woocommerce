<?php
/**
 * The Recurrente payment gateway: settings, and the redirect-to-hosted-checkout
 * payment flow. WooCommerce owns the order; fulfillment happens by webhook, so
 * process_payment() only creates the checkout and hands back the redirect URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Gateway extends WC_Payment_Gateway {

	const ID = 'recurrente';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Recurrente', 'recurrente-for-woocommerce' );
		$this->method_description = __( 'Acepta tarjetas, transferencias y suscripciones con el checkout hospedado de Recurrente.', 'recurrente-for-woocommerce' );
		$this->icon               = self::icon_url();
		$this->has_fields         = false;
		$this->supports           = $this->supported_features();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		Recurrente_Logger::set_enabled( 'yes' === $this->get_option( 'debug' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** URL del logo de Recurrente empaquetado, para el ícono del método de pago. */
	public static function icon_url() {
		return plugins_url( 'assets/images/recurrente-logo.png', RECURRENTE_PLUGIN_FILE );
	}

	/** Ícono del checkout clásico, con alto acotado para que no se desborde. */
	public function get_icon() {
		$icon = sprintf(
			'<img src="%s" alt="%s" style="max-height:24px;width:auto;margin-left:6px;vertical-align:middle;" />',
			esc_url( $this->icon ),
			esc_attr( $this->get_title() )
		);
		// woocommerce_gateway_icon is a WooCommerce core filter (applied by the
		// parent WC_Payment_Gateway::get_icon); re-applying it keeps that contract.
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WooCommerce filter.
	}

	private function supported_features() {
		$base = array( 'products' );
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return $base;
		}
		return array_merge( $base, array(
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'multiple_subscriptions',
		) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'general_title'          => array(
				'title' => __( 'General', 'recurrente-for-woocommerce' ),
				'type'  => 'title',
			),
			'enabled'                => array(
				'title'   => __( 'Activar/Desactivar', 'recurrente-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar Recurrente', 'recurrente-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Título', 'recurrente-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Lo que ve el cliente al elegir el método de pago.', 'recurrente-for-woocommerce' ),
				'default'     => __( 'Tarjeta, transferencia o cuotas (Recurrente)', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'            => array(
				'title'   => __( 'Descripción', 'recurrente-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pagá de forma segura con Recurrente. Te redirigiremos para completar tu pago.', 'recurrente-for-woocommerce' ),
			),
			'checkout_title'         => array(
				'title'       => __( 'Opciones de checkout', 'recurrente-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Qué métodos y cuotas ve el comprador. Dejá vacío para heredar la configuración de tu cuenta de Recurrente.', 'recurrente-for-woocommerce' ),
			),
			'payment_methods'        => array(
				'title'       => __( 'Métodos de pago', 'recurrente-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'card'          => __( 'Tarjeta', 'recurrente-for-woocommerce' ),
					'bank_transfer' => __( 'Transferencia bancaria', 'recurrente-for-woocommerce' ),
					'balance'       => __( 'Balance Recurrente', 'recurrente-for-woocommerce' ),
					'stablecoins'   => __( 'Dólares digitales (stablecoins)', 'recurrente-for-woocommerce' ),
				),
				'description' => __( 'Métodos que ve el comprador en el checkout. Dejalo vacío para usar la configuración de tu cuenta de Recurrente.', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'installments'           => array(
				'title'       => __( 'Cuotas (meses)', 'recurrente-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'3'  => __( '3 meses', 'recurrente-for-woocommerce' ),
					'6'  => __( '6 meses', 'recurrente-for-woocommerce' ),
					'12' => __( '12 meses', 'recurrente-for-woocommerce' ),
					'18' => __( '18 meses', 'recurrente-for-woocommerce' ),
				),
				'description' => __( 'Opciones de cuotas a mostrar. Solo aplica a GTQ y requiere tarjeta habilitada. Vacío = sin cuotas o según tu cuenta.', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'dynamic_pricing'        => array(
				'title'       => __( 'Precio dinámico', 'recurrente-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Cobrar recargo según el método de pago', 'recurrente-for-woocommerce' ),
				'description' => __( 'Si se activa, Recurrente suma un recargo cuando el comprador elige un método más costoso (ej. tarjeta). El monto base sigue siendo el total de la orden. Nota: con precio dinámico Recurrente muestra todos los métodos y cuotas disponibles para comparar precios, ignorando las restricciones de arriba.', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => 'no',
			),
			'live_title'             => array(
				'title'       => __( 'Producción', 'recurrente-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Se usa cuando el modo de pruebas está desactivado. Cobros reales.', 'recurrente-for-woocommerce' ),
			),
			'live_secret_key'        => array(
				'title'       => __( 'Llave secreta de producción', 'recurrente-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Recurrente → Configuración → Llaves API.', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'test_title'             => array(
				'title'       => __( 'Modo de pruebas (sandbox)', 'recurrente-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Todo lo que necesitás para probar sin cobros reales: activá el modo de pruebas, pegá tu llave secreta de pruebas y pagá con la tarjeta 4242 4242 4242 4242. Los pagos de prueba disparan webhooks simulados, así que también se prueba la conciliación de la orden — para eso el sitio necesita una URL pública (Live Link o túnel); en localhost la orden queda en "pendiente".', 'recurrente-for-woocommerce' ),
			),
			'test_mode'              => array(
				'title'   => __( 'Activar modo de pruebas', 'recurrente-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Usar la llave de pruebas en vez de la de producción', 'recurrente-for-woocommerce' ),
				'default' => 'yes',
			),
			'test_secret_key'        => array(
				'title'       => __( 'Llave secreta de pruebas', 'recurrente-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Recurrente → Configuración → Llaves API (llave de test).', 'recurrente-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'advanced_title'         => array(
				'title' => __( 'Avanzado', 'recurrente-for-woocommerce' ),
				'type'  => 'title',
			),
			'debug'                  => array(
				'title'       => __( 'Registro de depuración', 'recurrente-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Registrar eventos en WooCommerce → Estado → Registros', 'recurrente-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * After WooCommerce saves the settings, register the webhook endpoint for
	 * each environment with a key configured (mirrors the Shopify app: the
	 * registration doubles as validation of the key).
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		foreach ( Recurrente_Webhook_Registrar::sync_all( $this ) as $error ) {
			WC_Admin_Settings::add_error( $error );
		}
		return $saved;
	}

	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$builder = new Recurrente_Checkout_Builder( $order, $this->checkout_options() );
		return $this->start_checkout( $order, $builder );
	}

	/**
	 * Per-item checkout config from the settings, applied to every line. Only
	 * keys the merchant actually set are included; anything omitted lets the
	 * item inherit the Recurrente account's default configuration.
	 */
	private function checkout_options() {
		$options = array();

		$methods = array_filter( (array) $this->get_option( 'payment_methods', array() ) );
		if ( $methods ) {
			$options['payment_method_types'] = array_values( $methods );
		}

		$installments = array_filter( array_map( 'intval', (array) $this->get_option( 'installments', array() ) ) );
		if ( $installments ) {
			$options['available_installments'] = array_values( $installments );
		}

		if ( 'yes' === $this->get_option( 'dynamic_pricing' ) ) {
			$options['has_dynamic_pricing'] = true;
		}

		return $options;
	}

	private function start_checkout( $order, $builder ) {
		if ( ! $builder->supported_currency() ) {
			return $this->fail( __( 'Recurrente solo acepta GTQ y USD.', 'recurrente-for-woocommerce' ) );
		}
		return $this->create_and_redirect( $order, $builder );
	}

	private function create_and_redirect( $order, $builder ) {
		try {
			$checkout = $this->client()->create_checkout( $this->checkout_payload( $order, $builder ) );
			return $this->redirect_to( $order, $checkout );
		} catch ( Recurrente_API_Exception $e ) {
			Recurrente_Logger::error( 'create_checkout failed', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );
			return $this->fail( __( 'No pudimos iniciar el pago con Recurrente. Intentá de nuevo.', 'recurrente-for-woocommerce' ) );
		}
	}

	private function redirect_to( $order, $checkout ) {
		$order->update_meta_data( '_recurrente_checkout_id', $checkout['id'] );
		$order->update_status( 'pending', __( 'Esperando pago en Recurrente.', 'recurrente-for-woocommerce' ) );
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $checkout['checkout_url'],
		);
	}

	private function checkout_payload( $order, $builder ) {
		return array(
			'items'       => $builder->items(),
			'success_url' => $this->get_return_url( $order ),
			'cancel_url'  => $order->get_cancel_order_url_raw(),
			'customer_id' => $this->customer_id_for( $order ),
			'metadata'    => array(
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'source'       => 'woocommerce',
			),
		);
	}

	/**
	 * Find-or-create the customer so the hosted checkout is prefilled. A failure
	 * here is non-fatal — the buyer can still type their details on the checkout.
	 */
	private function customer_id_for( $order ) {
		try {
			$customer = $this->client()->create_customer( $this->customer_payload( $order ) );
			return isset( $customer['id'] ) ? $customer['id'] : null;
		} catch ( Recurrente_API_Exception $e ) {
			Recurrente_Logger::error( 'create_customer failed', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );
			return null;
		}
	}

	private function customer_payload( $order ) {
		return array_filter( array(
			'email'     => $order->get_billing_email(),
			'full_name' => trim( $order->get_formatted_billing_full_name() ),
			'phone'     => $order->get_billing_phone(),
			'address'   => $order->get_billing_address_1(),
		) );
	}

	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	private function client() {
		return new Recurrente_API_Client( $this->secret_key() );
	}

	public function secret_key() {
		$key = 'yes' === $this->get_option( 'test_mode' ) ? 'test_secret_key' : 'live_secret_key';
		return $this->get_option( $key );
	}
}
