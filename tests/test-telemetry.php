<?php
/**
 * Recurrente_Telemetry tests.
 *
 * Pins the createPluginEvent contract (recurrente-app/docs/api/openapi.yaml):
 * the body is `plugin_event` with exactly the permitted fields, the request is
 * signed with the account's secret key and never blocks, and a failed report
 * is logged instead of thrown — a telemetry outage can't break a checkout.
 */

if ( ! defined( 'RECURRENTE_PLUGIN_VERSION' ) ) {
	define( 'RECURRENTE_PLUGIN_VERSION', '0.1.3' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// In-memory stand-ins for the WordPress functions the reporter touches
// (options and the logger come from stubs-wordpress.php).
require_once __DIR__ . '/stubs-wordpress.php';

$GLOBALS['recurrente_requests'] = array();
$GLOBALS['recurrente_cron']     = array();
$GLOBALS['recurrente_http']     = null; // A callable makes wp_remote_post misbehave on purpose.

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args ) {
		$GLOBALS['recurrente_requests'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['recurrente_http'] ? call_user_func( $GLOBALS['recurrente_http'] ) : array( 'response' => array( 'code' => 201 ) );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://tienda.example.com/shop/';
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) {
		return 'name' === $show ? 'La Surf Office' : '6.6.2';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return 'uuid-' . count( $GLOBALS['recurrente_requests'] );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['recurrente_cron'][ $hook ] ) ? $GLOBALS['recurrente_cron'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['recurrente_cron'][ $hook ] = array( 'at' => $timestamp, 'every' => $recurrence );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		unset( $GLOBALS['recurrente_cron'][ $hook ] );
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-recurrente-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-recurrente-telemetry.php';

function recurrente_telemetry_reset( $settings = array( 'live_secret_key' => 'sk_live_abc', 'test_secret_key' => 'sk_test_xyz' ) ) {
	$GLOBALS['recurrente_options']  = array( Recurrente_Telemetry::SETTINGS_OPTION => $settings );
	$GLOBALS['recurrente_requests'] = array();
	$GLOBALS['recurrente_logged']   = array();
	$GLOBALS['recurrente_cron']     = array();
	$GLOBALS['recurrente_http']     = null;
}

function recurrente_last_request() {
	$requests = $GLOBALS['recurrente_requests'];
	return $requests ? end( $requests ) : null;
}

function recurrente_last_event() {
	$request = recurrente_last_request();
	return $request ? json_decode( $request['args']['body'], true )['plugin_event'] : null;
}

// ── Payload ──────────────────────────────────────────────────────────────

it( 'payload carries exactly the fields createPluginEvent permits', function () {
	recurrente_telemetry_reset();
	$event = Recurrente_Telemetry::payload( 'connected' )['plugin_event'];
	return array( 'plugin', 'installation_id', 'event', 'version', 'site' ) === array_keys( $event )
		&& 'woocommerce' === $event['plugin']
		&& 'connected' === $event['event']
		&& RECURRENTE_PLUGIN_VERSION === $event['version'];
} );

it( 'payload identifies the installation by host and path, and the site by its name', function () {
	$event = Recurrente_Telemetry::payload( 'heartbeat' )['plugin_event'];
	return 'tienda.example.com/shop' === $event['installation_id'] && 'La Surf Office' === $event['site'];
} );

it( 'payload includes error_code only for errors', function () {
	$error = Recurrente_Telemetry::payload( 'error', Recurrente_Telemetry::WEBHOOK_FAILED )['plugin_event'];
	$plain = Recurrente_Telemetry::payload( 'heartbeat' )['plugin_event'];
	return 'webhook_failed' === $error['error_code'] && ! array_key_exists( 'error_code', $plain );
} );

it( 'platform versions travel in the user agent, not in the body', function () {
	$version = preg_quote( RECURRENTE_PLUGIN_VERSION, '#' );
	return 1 === preg_match( '#^recurrente-woocommerce/' . $version . ' \(WordPress/6\.6\.2; WooCommerce/unknown; PHP/[\d.]+#', Recurrente_Telemetry::user_agent() );
} );

// ── Transport ────────────────────────────────────────────────────────────

it( 'a report posts to /plugin_events signed with the live key and never blocks', function () {
	recurrente_telemetry_reset();
	$sent    = Recurrente_Telemetry::report( 'connected' );
	$request = recurrente_last_request();
	return true === $sent
		&& Recurrente_Telemetry::ENDPOINT === $request['url']
		&& false === $request['args']['blocking']
		&& 'sk_live_abc' === $request['args']['headers']['X-SECRET-KEY']
		&& 'application/json' === $request['args']['headers']['Content-Type']
		&& ! empty( $request['args']['headers']['Idempotency-Key'] );
} );

it( 'falls back to the test key when no live key is configured', function () {
	recurrente_telemetry_reset( array( 'test_secret_key' => 'sk_test_xyz' ) );
	Recurrente_Telemetry::report( 'connected' );
	return 'sk_test_xyz' === recurrente_last_request()['args']['headers']['X-SECRET-KEY'];
} );

it( 'sends nothing when no key is configured', function () {
	recurrente_telemetry_reset( array() );
	return false === Recurrente_Telemetry::report( 'connected' ) && array() === $GLOBALS['recurrente_requests'];
} );

it( 'a given key wins over the configured ones', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::report( 'connected', null, 'sk_test_just_validated' );
	return 'sk_test_just_validated' === recurrente_last_request()['args']['headers']['X-SECRET-KEY'];
} );

// ── Connection and disconnection ─────────────────────────────────────────

it( 'activation schedules the daily heartbeat and reports the connection', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::activate();
	return 'daily' === $GLOBALS['recurrente_cron'][ Recurrente_Telemetry::CRON_HOOK ]['every']
		&& 'connected' === recurrente_last_event()['event'];
} );

it( 'deactivation clears the heartbeat and reports the disconnection', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::activate();
	Recurrente_Telemetry::deactivate();
	return ! isset( $GLOBALS['recurrente_cron'][ Recurrente_Telemetry::CRON_HOOK ] )
		&& 'disconnected' === recurrente_last_event()['event'];
} );

it( 'removing a key reports a disconnection signed with the removed key', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::report_removed_keys(
		array( 'test' => 'sk_test_xyz', 'live' => 'sk_live_abc' ),
		array( 'test' => 'sk_test_xyz', 'live' => '' )
	);
	$request = recurrente_last_request();
	return 1 === count( $GLOBALS['recurrente_requests'] )
		&& 'disconnected' === recurrente_last_event()['event']
		&& 'sk_live_abc' === $request['args']['headers']['X-SECRET-KEY'];
} );

it( 'keys that stay or were never set do not report anything', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::report_removed_keys( array( 'test' => '', 'live' => 'sk_live_abc' ), array( 'test' => '', 'live' => 'sk_live_abc' ) );
	return array() === $GLOBALS['recurrente_requests'];
} );

it( 'ensure_scheduled does not duplicate an existing schedule', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::ensure_scheduled();
	$first = $GLOBALS['recurrente_cron'][ Recurrente_Telemetry::CRON_HOOK ]['at'];
	$GLOBALS['recurrente_cron'][ Recurrente_Telemetry::CRON_HOOK ]['at'] = $first - 1;
	Recurrente_Telemetry::ensure_scheduled();
	return $first - 1 === $GLOBALS['recurrente_cron'][ Recurrente_Telemetry::CRON_HOOK ]['at'];
} );

// ── Health ───────────────────────────────────────────────────────────────

it( 'a quiet day is a plain heartbeat', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::heartbeat();
	return 'heartbeat' === recurrente_last_event()['event'];
} );

it( 'a noted error is reported once, then recovered, then plain heartbeats', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::note_error( Recurrente_Telemetry::AUTHENTICATION_FAILED );
	Recurrente_Telemetry::heartbeat();
	$first = recurrente_last_event();
	Recurrente_Telemetry::heartbeat();
	$second = recurrente_last_event();
	Recurrente_Telemetry::heartbeat();
	$third = recurrente_last_event();
	return 'error' === $first['event'] && 'authentication_failed' === $first['error_code']
		&& 'recovered' === $second['event'] && ! isset( $second['error_code'] )
		&& 'heartbeat' === $third['event'];
} );

it( 'an error that keeps happening is reported again instead of recovered', function () {
	recurrente_telemetry_reset();
	Recurrente_Telemetry::note_error( Recurrente_Telemetry::API_ERROR );
	Recurrente_Telemetry::heartbeat();
	Recurrente_Telemetry::note_error( Recurrente_Telemetry::CONNECTION_FAILED );
	Recurrente_Telemetry::heartbeat();
	$event = recurrente_last_event();
	return 'error' === $event['event'] && 'connection_failed' === $event['error_code'];
} );

// ── Failures never surface ───────────────────────────────────────────────

it( 'a network error is logged, not thrown', function () {
	recurrente_telemetry_reset();
	$GLOBALS['recurrente_http'] = function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28: connection timed out' );
	};
	$sent = Recurrente_Telemetry::report( 'heartbeat' );
	return true === $sent && 'error' === $GLOBALS['recurrente_logged'][0]['level']
		&& false !== strpos( $GLOBALS['recurrente_logged'][0]['message'], 'timed out' );
} );

it( 'an exception inside the HTTP layer is logged, not thrown', function () {
	recurrente_telemetry_reset();
	$GLOBALS['recurrente_http'] = function () {
		throw new RuntimeException( 'transport exploded' );
	};
	Recurrente_Telemetry::report( 'heartbeat' );
	return false !== strpos( $GLOBALS['recurrente_logged'][0]['message'], 'transport exploded' );
} );
