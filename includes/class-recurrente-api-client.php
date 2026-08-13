<?php
/**
 * Thin client over the Recurrente public REST API.
 *
 * Docs:  https://app.recurrente.com/api  (X-SECRET-KEY auth)
 * Spec:  recurrente-app/docs/api/openapi.yaml
 *
 * Test vs live is decided purely by which secret key you configure — both
 * environments share the same base URL. A TEST key manages test webhook
 * endpoints, which receive simulated events with live_mode:false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurrente_API_Client {

	const BASE_URL = 'https://app.recurrente.com/api';

	private $secret_key;

	public function __construct( $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Create a hosted checkout session. Returns the decoded body
	 * ( ['id' => 'ch_…', 'checkout_url' => '…'] ) or throws Recurrente_API_Exception.
	 */
	public function create_checkout( array $payload ) {
		return $this->post( '/checkouts', $payload );
	}

	/**
	 * Find-or-create a customer so the hosted checkout prefills name/email/phone.
	 * Recurrente keys customers by email, so repeat buyers reuse the same record.
	 */
	public function create_customer( array $payload ) {
		return $this->post( '/customers', $payload );
	}

	/**
	 * Register a webhook endpoint. The `signingSecret` is only returned by this
	 * creation response, so the caller must persist it immediately. The key's
	 * environment (test/live) decides where the endpoint is registered.
	 */
	public function create_webhook_endpoint( $url ) {
		return $this->post( '/webhook_endpoints', array(
			'url'         => $url,
			'description' => 'WooCommerce plugin',
		) );
	}

	public function list_webhook_endpoints() {
		return $this->request( 'GET', '/webhook_endpoints' );
	}

	public function delete_webhook_endpoint( $id ) {
		return $this->request( 'DELETE', '/webhook_endpoints/' . rawurlencode( $id ) );
	}

	private function post( $path, array $payload ) {
		return $this->request( 'POST', $path, $payload );
	}

	private function request( $method, $path, ?array $payload = null ) {
		$args = array(
			'method'  => $method,
			'headers' => $this->headers(),
			'timeout' => 30,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		return $this->parse( $response );
	}

	private function headers() {
		return array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-SECRET-KEY' => $this->secret_key,
		);
	}

	private function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			throw new Recurrente_API_Exception( esc_html( $response->get_error_message() ), 0 );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		return $this->result_or_raise( $status, $body );
	}

	private function result_or_raise( $status, $body ) {
		if ( $status >= 200 && $status < 300 ) {
			return is_array( $body ) ? $body : array();
		}
		throw new Recurrente_API_Exception( esc_html( $this->error_message( $body ) ), (int) $status );
	}

	private function error_message( $body ) {
		if ( is_array( $body ) && isset( $body['message'] ) ) {
			return $body['message'];
		}
		return __( 'Error al comunicarse con Recurrente.', 'recurrente-for-woocommerce' );
	}
}

class Recurrente_API_Exception extends Exception {}
