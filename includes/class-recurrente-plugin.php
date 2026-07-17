<?php
/**
 * Plugin bootstrap: loads dependencies and wires WooCommerce hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

	private function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	private function load_dependencies() {
		$dir = RECURRENTE_PLUGIN_DIR . 'includes/';
		require_once $dir . 'class-recurrente-logger.php';
		require_once $dir . 'class-recurrente-api-client.php';
		require_once $dir . 'class-recurrente-checkout-builder.php';
		require_once $dir . 'class-recurrente-svix-verifier.php';
		require_once $dir . 'class-recurrente-webhook-registrar.php';
		require_once $dir . 'class-recurrente-webhook-handler.php';
		require_once $dir . 'class-recurrente-subscriptions.php';
		require_once $dir . 'class-recurrente-gateway.php';
	}

	private function register_hooks() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'rest_api_init', array( 'Recurrente_Webhook_Handler', 'register_route' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RECURRENTE_PLUGIN_FILE ), array( $this, 'settings_link' ) );
		Recurrente_Subscriptions::register_hooks();
	}

	/** Register the gateway with the block-based checkout (WC 8.3+ default). */
	public function register_blocks_support() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once RECURRENTE_PLUGIN_DIR . 'includes/class-recurrente-blocks-support.php';
		add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
			$registry->register( new Recurrente_Blocks_Support() );
		} );
	}

	public function register_gateway( $gateways ) {
		$gateways[] = 'Recurrente_Gateway';
		return $gateways;
	}

	public function settings_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=recurrente' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configuración', 'recurrente-for-woocommerce' ) . '</a>' );
		return $links;
	}

	public function woocommerce_missing_notice() {
		echo '<div class="error"><p>';
		echo esc_html__( 'Recurrente para WooCommerce requiere que WooCommerce esté instalado y activo.', 'recurrente-for-woocommerce' );
		echo '</p></div>';
	}
}
