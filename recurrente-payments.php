<?php
/**
 * Plugin Name:       Recurrente for WooCommerce
 * Plugin URI:        https://recurrente.com
 * Description:        Cobra en WooCommerce con Recurrente — checkout hospedado, tarjetas, transferencias y suscripciones. Plugin oficial.
 * Version:           0.1.0
 * Author:            Recurrente
 * Author URI:        https://recurrente.com
 * Text Domain:       recurrente-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Official Recurrente gateway for WooCommerce. The buyer pays on Recurrente's
 * hosted checkout; WooCommerce owns the order and is reconciled by webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'RECURRENTE_PLUGIN_VERSION', '0.1.0' );
define( 'RECURRENTE_PLUGIN_FILE', __FILE__ );
define( 'RECURRENTE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once RECURRENTE_PLUGIN_DIR . 'includes/class-recurrente-plugin.php';

// Declare HPOS (High-Performance Order Storage) and Checkout Blocks compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', array( 'Recurrente_Plugin', 'instance' ) );
