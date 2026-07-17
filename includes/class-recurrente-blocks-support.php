<?php
/**
 * Checkout Blocks integration: registers the gateway with the block-based
 * checkout (the default since WooCommerce 8.3). Without this, the gateway
 * only appears on the classic shortcode checkout.
 *
 * The gateway is redirect-based, so the JS side (assets/js/blocks-checkout.js)
 * only renders the method's title/description; payment still flows through
 * Recurrente_Gateway::process_payment() exactly like the classic checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Recurrente_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = Recurrente_Gateway::ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_recurrente_settings', array() );
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'recurrente-blocks',
			plugins_url( 'assets/js/blocks-checkout.js', RECURRENTE_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			RECURRENTE_PLUGIN_VERSION,
			true
		);
		return array( 'recurrente-blocks' );
	}

	public function get_payment_method_data() {
		$gateway = new Recurrente_Gateway();
		return array(
			'title'       => $gateway->title,
			'description' => $gateway->description,
			'icon'        => Recurrente_Gateway::icon_url(),
			'supports'    => $gateway->supports,
		);
	}
}
