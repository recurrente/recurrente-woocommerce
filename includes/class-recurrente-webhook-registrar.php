<?php
/**
 * Auto-registers the Recurrente webhook endpoint, mirroring the Shopify app:
 * saving the gateway settings registers a fresh endpoint per environment (the
 * call doubles as validation of the key), persists its signing secret, and
 * then best-effort deletes stale endpoints pointing at this site.
 *
 * Recurrente manages webhook endpoints per key environment (a TEST key
 * registers test endpoints, a LIVE key production ones) and each endpoint has
 * its own signing secret, so one secret is stored per mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_Webhook_Registrar {

	const MODES = array( 'test', 'live' );

	/**
	 * Sync every environment that has a key configured. Returns an array of
	 * error messages (empty on full success) for the settings screen.
	 */
	public static function sync_all( $gateway ) {
		$errors = array();
		foreach ( self::MODES as $mode ) {
			$key = $gateway->get_option( $mode . '_secret_key' );
			if ( ! $key ) {
				continue;
			}
			$error = self::sync( $key, $mode );
			if ( $error ) {
				$errors[] = $error;
			}
		}
		return $errors;
	}

	/** Signing secrets captured at registration, for webhook verification. */
	public static function secrets() {
		return array_values( array_filter( array(
			get_option( 'recurrente_webhook_secret_test' ),
			get_option( 'recurrente_webhook_secret_live' ),
		) ) );
	}

	private static function sync( $secret_key, $mode ) {
		$client = new Recurrente_API_Client( $secret_key );
		try {
			$endpoint = $client->create_webhook_endpoint( Recurrente_Webhook_Handler::url() );
		} catch ( Recurrente_API_Exception $e ) {
			Recurrente_Logger::error( 'webhook endpoint registration failed', array( 'mode' => $mode, 'error' => $e->getMessage() ) );
			return self::error_message( $e, $mode );
		}
		if ( empty( $endpoint['signingSecret'] ) ) {
			Recurrente_Logger::error( 'webhook endpoint response missing signingSecret', array( 'mode' => $mode ) );
			return self::generic_error( $mode );
		}
		self::store( $endpoint, $mode );
		self::cleanup_stale( $client, $endpoint );
		return null;
	}

	private static function store( $endpoint, $mode ) {
		update_option( 'recurrente_webhook_secret_' . $mode, $endpoint['signingSecret'] );
		update_option( 'recurrente_webhook_endpoint_' . $mode, $endpoint['id'] );
	}

	/**
	 * Each save registers a fresh endpoint, so older ones for this site keep
	 * delivering with secrets we no longer have. Remove them; best effort only —
	 * the new endpoint is already registered and its secret persisted.
	 */
	private static function cleanup_stale( $client, $endpoint ) {
		try {
			foreach ( $client->list_webhook_endpoints() as $existing ) {
				if ( self::stale( $existing, $endpoint ) ) {
					$client->delete_webhook_endpoint( $existing['id'] );
				}
			}
		} catch ( Recurrente_API_Exception $e ) {
			Recurrente_Logger::error( 'stale webhook endpoint cleanup failed', array( 'error' => $e->getMessage() ) );
		}
	}

	private static function stale( $existing, $endpoint ) {
		return isset( $existing['url'], $existing['id'] )
			&& $existing['url'] === Recurrente_Webhook_Handler::url()
			&& $existing['id'] !== $endpoint['id'];
	}

	private static function error_message( $e, $mode ) {
		if ( in_array( $e->getCode(), array( 401, 403 ), true ) ) {
			return sprintf(
				/* translators: %s: "pruebas" o "producción" */
				__( 'La llave secreta de %s es inválida. Verificá que sea tu llave secreta de Recurrente (Configuración → Llaves API).', 'recurrente-for-woocommerce' ),
				self::mode_label( $mode )
			);
		}
		return self::generic_error( $mode );
	}

	private static function generic_error( $mode ) {
		return sprintf(
			/* translators: %s: "pruebas" o "producción" */
			__( 'No se pudo registrar el webhook de Recurrente (%s). Intentá guardar de nuevo en unos minutos.', 'recurrente-for-woocommerce' ),
			self::mode_label( $mode )
		);
	}

	private static function mode_label( $mode ) {
		return 'test' === $mode ? __( 'pruebas', 'recurrente-for-woocommerce' ) : __( 'producción', 'recurrente-for-woocommerce' );
	}
}
