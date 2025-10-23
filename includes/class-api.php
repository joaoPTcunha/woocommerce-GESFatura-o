<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GesFaturacao_API {

	private $domain;
	private $token;
	private $api_version;
	private $finalize;
	private $email;
	private $serie;

	public function __construct() {
		// Load options from DB (same option name as admin class)
		$options = get_option( 'gesfaturacao_options', [] );

		$this->domain      = isset( $options['domain'] ) ? sanitize_text_field( $options['domain'] ) : '';
		$this->token       = isset( $options['token'] ) ? sanitize_text_field( $options['token'] ) : '';
		$this->api_version = isset( $options['api_version'] ) ? sanitize_text_field( $options['api_version'] ) : 'v1.0.4';
		$this->finalize    = ! empty( $options['finalize'] ) ? true : false;
		$this->email       = ! empty( $options['email'] ) ? true : false;
		$this->serie       = isset( $options['serie'] ) ? sanitize_text_field( $options['serie'] ) : '';
		$this->shipping    = isset( $options['shipping'] ) ? sanitize_text_field( $options['shipping'] ) : '';
	}

	/**
	 * Build base URL for the API calls
	 * Example: https://{domain}.gesfaturacao.pt/api/{version}/
	 */
	private function get_base_url($domain = null, $api_version = null) {
		$domain = $domain ?? $this->domain ;
		$api_version = $api_version ?? $this->api_version ;
		if ( empty( $domain ) ) {
			return new WP_Error( 'no_domain', 'Domain not configured in plugin settings.' );
		}

		$url = "https://{$domain}.gesfaturacao.pt/api/$api_version/";
		return $url;
	}

	/**
	 * Prepare HTTP request args (headers, body, method)
	 */
	private function prepare_request_args( $method = 'GET', $body = null, $token = null ) {

		$headers = [
			'Authorization' => $token ?? $this->token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		];


		$args = [
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 20,
		];

		if ( $body && in_array( $method, ['POST', 'PUT', 'PATCH'], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		return $args;
	}

	/**
	 * Perform API request to given endpoint
	 *
	 * @param string $endpoint API endpoint, e.g. 'invoices', 'series'
	 * @param string $method HTTP method GET, POST, etc.
	 * @param array|null $body Request payload for POST/PUT
	 * @return mixed Decoded JSON response or WP_Error on failure
	 */
	public function request( $endpoint, $method = 'GET', $body = null, $token = null, $domain = null, $api_version = null ) {
		$base_url = $this->get_base_url($domain, $api_version);

		if ( is_wp_error( $base_url ) ) {
			return $base_url;
		}

		$url = trailingslashit( $base_url ) . ltrim( $endpoint, '/' );

		$args = $this->prepare_request_args( $method, $body, $token);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'api_error',
				"HTTP error code {$code}",
				[
					'response_code' => $code,
					'body'          => $response_body,
				]
			);
		}

		$decoded = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Invalid JSON response: ' . json_last_error_msg() );
		}

		return $decoded;
	}

	/**
	 * Get Series list from API
	 */
	public function get_series() {
		return $this->request( 'series', 'GET' );
	}

	/**
	 * Get Shipping list from API
	 */
	public function get_shipping() {
		return $this->request( 'products/type/service', 'GET' );
	}


	/**
	 *  Create a new invoice
	 *
	 * @param array $invoice_data Invoice details per API spec
	 * @return mixed API response or WP_Error
	 */
	public function create_invoice( array $invoice_data ) {
		// Add default settings from class, like finalize and email flags
		//$invoice_data['finalize'] = $this->finalize;
		//$invoice_data['email']    = $this->email;
		/*if ( ! empty( $this->serie ) ) {
			$invoice_data['serie'] = $this->serie;
		}*/

		return $this->request( '/sales/receipt-invoices', 'POST', $invoice_data );
	}

	/**
	 * Example: Get an invoice by ID
	 */
	public function get_invoice( $invoice_id ) {
		return $this->request( "invoices/{$invoice_id}", 'GET' );
	}

	/**
	 * Example: Update an invoice
	 */
	public function update_invoice( $invoice_id, array $data ) {
		return $this->request( "invoices/{$invoice_id}", 'PUT', $data );
	}

	/**
	 * Checks if the client already exists in the API and returns the client ID
	 *
	 * @return mixed Client ID or WP_Error
	 */
	public function check_client_exists($vat_number, $name) {
		return $this->request( "clients/tin/search/{$vat_number}/{$name}", 'GET' );
	}

	/**
	 * Create a new client
	 *
	 * @param array $client_data Invoice details per API spec
	 * @return mixed API response or WP_Error
	 */
	
/* 	public function create_client( array $client_data ) {
		return $this->request( 'clients', 'POST', $client_data);
	} */


	/**
	 * Get all taxes from API
	 *
	 * @return mixed API response or WP_Error
	 */

	public function get_taxes() {
		return $this->request( "taxes", 'GET' );
	}

	/**
	 * Get all taxes exemption reasons from API
	 *
	 * @return mixed API response or WP_Error
	 */

	public function get_exemption_reason() {
		return $this->request( "/exemption-reasons", 'GET' );
	}

	/**
	 * Get all taxes from API
	 *
	 * @return mixed API response or WP_Error
    */

	public function check_product_exists($code) {
		return $this->request( "products/code/{$code}", 'GET' );
	}


	/**
	 * Create a new product
	 *
	 * @param array $product_data Product details per API spec
	 * @return mixed API response or WP_Error
	 */
	public function create_product( array $product_data) {
		return $this->request( 'products', 'POST', $product_data);
	}

	/**
	 * Get invoice PDF
	 *
	 * @param array $email_data Email details per API spec
	 * @return mixed API response or WP_Error
	 *
	 */
	public function get_invoice_pdf($document, $type='FR') {
		return $this->request( "sales/documents/{$document}/type/{$type}", 'GET');
	}

	/**
	 * Send invoice by email
	 *
	 * @param array $email_data Email details per API spec
	 * @return mixed API response or WP_Error
	 *
	 */
	public function send_email(array $email_data) {
		return $this->request( 'sales/documents/send-email', 'POST', $email_data);
	}

	/**
	 * Get list of banks
	 *
	 * @param array $banks Optional parameters for the API request
	 * @return mixed API response or WP_Error
	 */
	public function get_banks() {
		return $this->request('/banks', 'GET');
	}

	/**
	 * Get list of payment methods
	 *
	 * @param array $payment_methods Optional parameters for the API request
	 * @return mixed API response or WP_Error
	 */
	public function get_payment_methods() {
		return $this->request('/payment-methods', 'GET');
	}

	/**
	 * Validate the API token
	 *
	 * @return mixed API response or WP_Error
	 *
	 */
	
	public function validate_token($token = null, $domain =null, $api_version=null) {
		return $this->request( 'validate-token', 'POST', null, $token, $domain, $api_version);
	}

	/**
	 * Validate the API token
	 *
	 * @return mixed API response or WP_Error
	 *
	 */

	public function validate_domain($domain_data) {
		// Calls the API to validate the Domain
		$api_url_domain = "https://licencas.gesfaturacao.pt/server/auto/global_api.php";


		$username='R0VTRkFUVVJBQ0FP';
		$password='MXY4OGJid2drZXI3bjkyaWQ3MTk=';
		$auth = base64_encode( $username . ':' . $password );

		$headers = [
			'Authorization' => "Basic $auth",
			'Content-Type'  => 'application/x-www-form-urlencoded'
		];


		// Make the API request
		$response = wp_remote_post($api_url_domain, [
			'headers' => $headers,
			'body' => $domain_data,
			'timeout' => 10,
		]);

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($code !== 200) {
			return new WP_Error('invalid_response', 'Erro: A validação do domínio falhou. Código HTTP: ' . $code);
		}
		return true;
	}
}