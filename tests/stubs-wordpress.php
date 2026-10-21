<?php
/**
 * Stand-ins for the WordPress options store and the WooCommerce logger,
 * shared by every test file that touches them: options live in
 * $GLOBALS['recurrente_options'] and every log line is appended to
 * $GLOBALS['recurrente_logged'], so a test can set a secret or assert on
 * what was logged regardless of which file loaded first.
 */

if ( ! isset( $GLOBALS['recurrente_options'] ) ) {
	$GLOBALS['recurrente_options'] = array();
}
if ( ! isset( $GLOBALS['recurrente_logged'] ) ) {
	$GLOBALS['recurrente_logged'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['recurrente_options'] ) ? $GLOBALS['recurrente_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['recurrente_options'][ $name ] = $value;
		return true;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log( $level, $message, $context = array() ) {
				$GLOBALS['recurrente_logged'][] = array( 'level' => $level, 'message' => $message );
			}
		};
	}
}
