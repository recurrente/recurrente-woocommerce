<?php
/**
 * Reports the plugin's own lifecycle to Recurrente (POST /plugin_events) so the
 * adoption dashboard sees connections, versions and health without anyone
 * asking the merchant. Money never travels here: Rails records payments itself.
 *
 * Contract: recurrente-app/docs/api/openapi.yaml → createPluginEvent. The body
 * is `plugin_event: { plugin, installation_id, event, version, site, error_code }`
 * authenticated with the account's secret key (X-SECRET-KEY), like the API
 * client. Rails stamps the time of receipt; client dates are not accepted.
 *
 * Every send is fire-and-forget (`blocking => false`) and swallows failures into
 * the log, so a telemetry outage can never slow down or break a checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Telemetry {

	const ENDPOINT        = 'https://app.recurrente.com/api/plugin_events';
	const PLUGIN          = 'woocommerce';
	const CRON_HOOK       = 'recurrente_telemetry_heartbeat';
	const HEALTH_OPTION   = 'recurrente_telemetry_health';
	const SETTINGS_OPTION = 'woocommerce_recurrente_settings'; // Where WC_Settings_API stores the gateway form.

	// Error codes Rails accepts (PluginEvent::ERROR_CODES).
	const AUTHENTICATION_FAILED = 'authentication_failed';
	const WEBHOOK_FAILED        = 'webhook_failed';
	const API_ERROR             = 'api_error';
	const CONNECTION_FAILED     = 'connection_failed';

	/* ── Lifecycle hooks ─────────────────────────────────────────────── */

	/** Plugin activation: start the daily heartbeat and, if keys exist already, say we're back. */
	public static function activate() {
		self::ensure_scheduled();
		self::report( 'connected' );
	}

	/** Plugin deactivation: stop the heartbeat and report the disconnection while a key can still sign it. */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::report( 'disconnected' );
	}

	public static function register_hooks() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'heartbeat' ) );
		// Sites that updated the plugin never ran the activation hook; `cron` is an autoloaded option, so this is cheap.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function ensure_scheduled() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/* ── Events ──────────────────────────────────────────────────────── */

	/**
	 * Daily health report. An error noted since the last run is reported as
	 * `error`; the first clean run after one is `recovered`; otherwise a plain
	 * `heartbeat`. Rails only leaves an error state through an explicit recovery.
	 */
	public static function heartbeat() {
		$health = self::health();
		list( $event, $error_code ) = self::health_event( $health );
		self::report( $event, $error_code );
		self::save_health( self::after_report( $health, $event ) );
	}

	/** A key removed from the settings is a disconnection; the old key is the last credential able to say so. */
	public static function report_removed_keys( array $before, array $after ) {
		foreach ( $before as $mode => $key ) {
			if ( $key && empty( $after[ $mode ] ) ) {
				self::report( 'disconnected', null, $key );
			}
		}
	}

	/** Remember a failure for the next heartbeat. Cheap: one option write, only on failures. */
	public static function note_error( $code ) {
		$health               = self::health();
		$health['last_error'] = $code;
		self::save_health( $health );
	}

	/**
	 * Send one event. Fire-and-forget: returns true when a request was dispatched
	 * and false when there is no key to authenticate with (nothing configured yet).
	 * Pass $secret_key to sign with a specific key (e.g. the one just validated).
	 */
	public static function report( $event, $error_code = null, $secret_key = null ) {
		$key = $secret_key ? $secret_key : self::secret_key();
		if ( ! $key ) {
			return false;
		}
		self::send( self::payload( $event, $error_code ), $key );
		return true;
	}

	/** The request body, exactly the fields createPluginEvent permits. */
	public static function payload( $event, $error_code = null ) {
		return array( 'plugin_event' => array_filter( array(
			'plugin'          => self::PLUGIN,
			'installation_id' => self::installation_id(),
			'event'           => $event,
			'version'         => RECURRENTE_PLUGIN_VERSION,
			'site'            => self::site(),
			'error_code'      => $error_code,
		) ) );
	}

	/**
	 * The contract has no fields for the platform versions, so they travel in
	 * the user agent (as Stripe's plugins do) where request logs can read them.
	 */
	public static function user_agent() {
		return sprintf(
			'recurrente-woocommerce/%s (WordPress/%s; WooCommerce/%s; PHP/%s)',
			RECURRENTE_PLUGIN_VERSION,
			get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			PHP_VERSION
		);
	}

	/* ── Health state ────────────────────────────────────────────────── */

	private static function health_event( $health ) {
		if ( $health['last_error'] ) {
			return array( 'error', $health['last_error'] );
		}
		return array( $health['reported_error'] ? 'recovered' : 'heartbeat', null );
	}

	private static function after_report( $health, $event ) {
		$health['last_error']     = null;
		$health['reported_error'] = 'error' === $event;
		return $health;
	}

	private static function health() {
		$stored = get_option( self::HEALTH_OPTION, array() );
		return array_merge(
			array( 'last_error' => null, 'reported_error' => false ),
			is_array( $stored ) ? $stored : array()
		);
	}

	private static function save_health( $health ) {
		update_option( self::HEALTH_OPTION, $health, false );
	}

	/* ── Transport ───────────────────────────────────────────────────── */

	private static function send( $payload, $secret_key ) {
		try {
			self::dispatch( $payload, $secret_key );
		} catch ( Throwable $e ) {
			self::log( $e->getMessage() );
		}
	}

	private static function dispatch( $payload, $secret_key ) {
		$response = wp_remote_post( self::ENDPOINT, self::request_args( $payload, $secret_key ) );
		if ( is_wp_error( $response ) ) {
			self::log( $response->get_error_message() );
		}
	}

	private static function request_args( $payload, $secret_key ) {
		return array(
			'blocking'   => false, // Never wait on telemetry: the buyer may be mid-checkout.
			'timeout'    => 5,
			'user-agent' => self::user_agent(),
			'headers'    => self::headers( $secret_key ),
			'body'       => wp_json_encode( $payload ),
		);
	}

	private static function headers( $secret_key ) {
		return array(
			'Content-Type'    => 'application/json',
			'Accept'          => 'application/json',
			'X-SECRET-KEY'    => $secret_key,
			'Idempotency-Key' => wp_generate_uuid4(),
		);
	}

	/** Errors are logged, never raised: a failed report must not surface anywhere a buyer or admin waits. */
	private static function log( $message ) {
		if ( ! class_exists( 'Recurrente_Logger' ) ) {
			return;
		}
		Recurrente_Logger::error( 'telemetry report failed', array( 'error' => $message ) );
	}

	/* ── Identity ────────────────────────────────────────────────────── */

	/** The live key when configured, the test key otherwise; both belong to the same account. */
	private static function secret_key() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$live     = self::setting( $settings, 'live_secret_key' );
		return $live ? $live : self::setting( $settings, 'test_secret_key' );
	}

	private static function setting( $settings, $name ) {
		return is_array( $settings ) && ! empty( $settings[ $name ] ) ? $settings[ $name ] : '';
	}

	/** Host plus path, stable across key rotations: "tienda.example.com" or "example.com/tienda". */
	private static function installation_id() {
		$url = home_url();
		$id  = wp_parse_url( $url, PHP_URL_HOST ) . rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return substr( $id, 0, 255 );
	}

	/** The store's name as the merchant wrote it; the domain when it has none. */
	private static function site() {
		$name = trim( (string) get_bloginfo( 'name' ) );
		return substr( $name ? $name : self::installation_id(), 0, 255 );
	}
}
