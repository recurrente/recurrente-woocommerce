<?php
/**
 * Thin wrapper over WC_Logger so we log under a single source and only when
 * the gateway's debug setting is on. Find logs under WooCommerce → Status → Logs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Logger {

	const SOURCE = 'recurrente';

	private static $enabled = false;

	public static function set_enabled( $enabled ) {
		self::$enabled = (bool) $enabled;
	}

	public static function debug( $message, $context = array() ) {
		self::log( 'debug', $message, $context );
	}

	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	private static function log( $level, $message, $context ) {
		if ( ! self::$enabled && 'error' !== $level ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log( $level, self::format( $message, $context ), array( 'source' => self::SOURCE ) );
	}

	private static function format( $message, $context ) {
		if ( empty( $context ) ) {
			return $message;
		}
		return $message . ' ' . wp_json_encode( $context );
	}
}
