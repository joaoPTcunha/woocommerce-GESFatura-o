<?php
if (!defined('ABSPATH')) exit;

class GesFaturacao_Invoice_Helper {

	/**
	 * Create (and optionally send) an invoice for a given WooCommerce order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @param bool $send_email Whether to send the invoice by email (default: false).
	 * @param string|null $custom_email If provided, override billing email for sending.
	 *
	 * @return array|WP_Error
	 * @throws Exception
	 */
	public function create_invoice_from_order( $order_id, $send_email = false, $custom_email = null ) {

		global $wpdb;
		$api = new GesFaturacao_API();
		$products = new GESFaturacao_product_helper();
		$client = new GESFaturacao_client_helper();

		// Get WooCommerce order
		$order = wc_get_order($order_id);

		if ( ! function_exists( 'wc_get_order' ) ) {
			return [
				'success' => false,
				'message' => 'WooCommerce não está ativo ou carregado.'
			];
		}
		if (!$order_id) {
			return [
				'success' => false,
				'message' => 'ID da encomenda inválido.'
			];
		}
		if (!$order) {
			return [
				'success' => false,
				'message' => 'Encomenda não encontrada.'
			];
		}

		// Get client ID
		$user_id = $order->get_user_id();

			// $user_id = 0 --> CONSUMIDOR FINAL
			// Assumes this function returns either existing remote client ID or creates it
			$client_id = $client->gesfaturacao_sync_client_with_api( $user_id, $order_id );



		// Build lines from order items
		$lines = [];
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();

			if (!$product) continue;

			// Get tax percentage from WooCommerce
			/*$tax_data = $item->get_taxes();
			$rate_percent = 0;
			if ( ! empty( $tax_data['total'] ) ) {
				foreach ( $tax_data['total'] as $rate_id => $tax_amount ) {
					$rate_percent = WC_Tax::get_rate_percent( $rate_id );
					//$rate_percent = $rate['tax_rate'];
					break;
				}
			}*/
			$tax_data = $item->get_taxes();

			$rate_percent = 0;
			$item_price_without_VAT=0;
			if ( ! empty( array_filter( $tax_data['total'] ) ) ) {
				foreach ( $tax_data['total'] as $rate_id => $tax_amount ) {
					// Try fetching tax rate from WooCommerce DB
					$rate_str = WC_Tax::get_rate_percent( $rate_id );
					$rate_percent = floatval( rtrim( $rate_str, '%' ) );

					// If rate_percent is still 0, calculate it manually
					$subtotal = floatval($item->get_subtotal());
					$tax_amount = floatval($tax_amount);

						if ( $subtotal > 0 ) {
							$item_price_without_VAT = $subtotal;
							$rate_percent = ( $tax_amount / $subtotal ) * 100;
						}
					break; // Assuming you only need the first tax rate
				}
			}else{
				$item_price_without_VAT = $item->get_total();
				$rate_percent=0;
			}

			//$tax_id = $this->get_tax_id($rate_percent);
			$tax_id = 0;
			$taxes = $api->get_taxes();
			$taxes_array = $taxes['data'];
			//cycles through the api response and finds the id of the tax that has the same value of $tax_rate
			foreach ($taxes_array as $tax) {
				if (floatval($tax['value']) == round($rate_percent, 2)) {
					$tax_id = $tax['id'];
					break;
				}
			}

			$missing_exemptions=[];
			if (!$tax_id) {
				return [
					'success' => false,
					'message' => 'Taxas de imposto mal configuradas. Verifique o WooCommerce e o GESFaturação'
				];
			} /*else if ( $tax_id == 4 ) {
				$missing_exemptions[] = [
					'product_id' => $product->get_id(),
					'product_name' => $item->get_name(),
					'item_key' => $item->get_id(),
				];
			}*/

			/*if ( ! empty( $missing_exemptions ) ) {
				return [
					'success' => false,
					'error_code' => 'missing_exemption_reasons',
					'message' => 'Faltam motivos de isenção para alguns produtos.',
					'exemption_data' => $missing_exemptions
				];
			}*/

			/*if (!empty($missing_exemptions)) {
				echo json_encode([
					'success' => false,
					'error_code' => 'missing_exemption_reasons',
					'message' => 'Faltam motivos de isenção para alguns produtos.',
					'missing_data' => $missing_exemptions,
					'exemption_data' => $api->get_exemption_reason()
				]);
				wp_die();
			}*/


			// Product code check/create
			$product_id = $products->check_product_code($product->get_id());

			if (!$product_id) {
				$product_id = $products->create_product($product->get_id(),$tax_id);
			}

			$quantity = $item->get_quantity();
			$unit_price_without_vat = wc_get_price_excluding_tax($product);

			//get_formatted_item_data() -> WOOCommerce function to get item data

			$lines[] = [
				'id' => $product_id,
				'description' => $item->get_name(),
				'quantity' => $quantity,
				'price' => $item_price_without_VAT,
				'tax' => $tax_id,
				'exemption' => 0,
				'discount' => 0,
				'retention' => 0
			];
		}

		$missing_exemptions = [];
		foreach ($lines as $index => $line) {
			if ($line['tax'] == 4 && empty($line['exemption'])) {
				$missing_exemptions[] = [
					'product_id' => $line['id'],
					'product_name' => $line['description'],
					'line_index' => $index
				];
			}
		}

		if (!empty($missing_exemptions)) {
				echo json_encode([
					'success' => false,
					'error_code' => 'missing_exemption_reasons',
					'message' => 'Faltam motivos de isenção para alguns produtos.',
					'order_id' => $order_id,
					'missing_data' => $missing_exemptions,
					'exemption_data' => $api->get_exemption_reason()
				]);
				wp_die();
			}


		//Get the GESFaturacao options from the WP_Options table
		$options = get_option( 'gesfaturacao_options', [] );
		$serie_id = $options['serie'];
		$finalize_invoice = $options['finalize'];
		$send_email = $options['email'];


		// Prepare payload
		$invoice_data = [
			'client' => $client_id,
			'serie' => $serie_id,
			'date' => current_time('d/m/Y'),
			'expiration' => current_time('d/m/Y'),
			'coin' => '1',
			'payment' => '2',
			'needsBank' => false,
			'lines' => json_encode($lines),
			'finalize' => (bool)$finalize_invoice,
		];

		$api_result = $api->create_invoice( $invoice_data );


		// 9) Handle API response
		if ( is_wp_error( $api_result ) ) {
			$response_data = $api_result->get_error_data();

			// Try to parse the body if it's there
			$body = $response_data['body'] ?? null;
			$decoded_body = json_decode($body, true);
			// Get the inner message
			$error_message = $decoded_body['errors']['message'] ?? 'Ocorreu um erro ao criar a fatura.';

			return [
				'success' => false,
				'error_code' => $decoded_body['errors']['code'] ?? 'unknown_error',
				'message' => $error_message,
			];
		} else {
			//Code 200
			$response =$api_result['data'];
			// 10) Save invoice in custom table
			$table_invoices = $wpdb->prefix . 'gesfaturacao_invoices';

			$wpdb->insert(
				$table_invoices,
				[
					'order_id'       => $order_id,
					'invoice_id'     => $response['id'],         // from API
					'invoice_number' => $response['number'],   // from API
					'created_at'     => current_time( 'mysql' ),
				]
			);

			return [
				'success'        => true,
				'invoice_id'     => $response['id'],
				'invoice_number' => $response['number'],
				'order_id' => $order_id,
				'send_email' => $send_email,
			];
		}
	}

	public function create_invoice_from_order_with_exemptions( ) {

		global $wpdb;
		$api = new GesFaturacao_API();
		$products = new GESFaturacao_product_helper();
		$client = new GESFaturacao_client_helper();

		// Read posted exemption reasons
		$order_id = $_POST['order_id'] ?? 0;
		$exemptions = $_POST['exemption_reasons'] ?? [];

		// Get WooCommerce order
		$order = wc_get_order($order_id);
		if ( ! function_exists( 'wc_get_order' ) ) {
			return [
				'success' => false,
				'message' => 'WooCommerce não está ativo ou carregado.'
			];
		}
		if (!$order_id) {
			return [
				'success' => false,
				'message' => 'ID da encomenda inválido.'
			];
		}
		if (!$order) {
			return [
				'success' => false,
				'message' => 'Encomenda não encontrada.'
			];
		}

		// Get client ID
		$user_id = $order->get_user_id();

			// $user_id = 0 --> CONSUMIDOR FINAL
			// Assumes this function returns either existing remote client ID or creates it
			$client_id = $client->gesfaturacao_sync_client_with_api( $user_id, $order_id );


		// Build lines from order items
		$lines = [];
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			$item_key = $item->get_id();

			if (!$product) continue;

			// Get tax percentage from WooCommerce
			$tax_data = $item->get_taxes();

			$rate_percent = 0;
			$item_price_without_VAT=0;
			if ( ! empty( array_filter( $tax_data['total'] ) ) ) {
				foreach ( $tax_data['total'] as $rate_id => $tax_amount ) {
					// Try fetching tax rate from WooCommerce DB
					$rate_str = WC_Tax::get_rate_percent( $rate_id );
					$rate_percent = floatval( rtrim( $rate_str, '%' ) );

					// If rate_percent is still 0, calculate it manually
					$subtotal = floatval($item->get_subtotal());
					$tax_amount = floatval($tax_amount);
					if ( $subtotal > 0 ) {
						$item_price_without_VAT = $subtotal;
						$rate_percent = ( $tax_amount / $subtotal ) * 100;
					}
					break; // Assuming you only need the first tax rate
				}
			}else{
				$item_price_without_VAT = $item->get_total();
				$rate_percent=0;
			}

			//$tax_id = $this->get_tax_id($rate_percent);
			$tax_id = 0;
			$taxes = $api->get_taxes();
			$taxes_array = $taxes['data'];
			//cycles through the api response and finds the id of the tax that has the same value of $tax_rate
			foreach ($taxes_array as $tax) {
				if (floatval($tax['value']) == round($rate_percent, 2)) {
					$tax_id = $tax['id'];
					break;
				}
			}

			$exemption_id=0; // 0 for no tax exemption

			if (!$tax_id) {
				return [
					'success' => false,
					'message' => 'Taxas de imposto mal configuradas. Verifique o WooCommerce e o GESFaturação'
				];
			} /*else if ($tax_id == 0 && isset($exemption_reasons[$item_key])) {
				$exemption_id = intval($exemption_reasons[$item_key]);
			}*/

			/*if ( ! empty( $missing_exemptions ) ) {
				return [
					'success' => false,
					'code' => 'missing_exemption_reasons',
					'message' => 'Faltam motivos de isenção para alguns produtos.',
					'data' => $missing_exemptions
				];
			}*/

			// Product code check/create
			$product_id = $products->check_product_code($product->get_id());
			if (!$product_id) {
				$product_id = $products->create_product($product->get_id(),$tax_id);
			}

			$quantity = $item->get_quantity();
			$unit_price_without_vat = wc_get_price_excluding_tax($product);

			$lines[] = [
				'id' => $product_id,
				'description' => $item->get_name(),
				'quantity' => $quantity,
				'price' => $unit_price_without_vat,
				'tax' => $tax_id,
				'exemption' => $exemption_id,
				'discount' => 0,
				'retention' => 0
			];
		}

		//Add the exemption reasons to the lines
		foreach ($lines as &$line) {
			if (isset($exemptions[$line['id']])) {
				$line['exemption'] = $exemptions[$line['id']];
			}
		}
		unset($line);


		//Get the GESFaturacao options from the WP_Options table
		$options = get_option( 'gesfaturacao_options', [] );
		$serie_id = $options['serie'];
		$finalize_invoice = $options['finalize'];
		$send_email = $options['email'];


		// Prepare payload
		$invoice_data = [
			'client' => $client_id,
			'serie' => $serie_id,
			'date' => current_time('d/m/Y'),
			'expiration' => current_time('d/m/Y'),
			'coin' => '1',
			'payment' => '2',
			'needsBank' => false,
			'lines' => json_encode($lines),
			'finalize' => (bool)$finalize_invoice,
		];

		$api_result = $api->create_invoice( $invoice_data );


		// 9) Handle API response
		if ( is_wp_error( $api_result ) ) {
			$response_data = $api_result->get_error_data();

			// Try to parse the body if it's there
			$body = $response_data['body'] ?? null;
			$decoded_body = json_decode($body, true);

			// Get the inner message
			$error_message = $decoded_body['errors']['message'] ?? 'Ocorreu um erro ao criar a fatura.';

			return [
				'success' => false,
				'error_code' => $decoded_body['errors']['code'] ?? 'unknown_error',
				'message' => $error_message,
			];
		}
		else {
			//Code 200
			$response =$api_result['data'];

			// 10) Save invoice in custom table
			$table_invoices = $wpdb->prefix . 'gesfaturacao_invoices';

			$wpdb->insert(
				$table_invoices,
				[
					'order_id'       => $order_id,
					'invoice_id'     => $response['id'],         // from API
					'invoice_number' => $response['number'],   // from API
					'created_at'     => current_time( 'mysql' ),
				]
			);

			return [
				'success'        => true,
				'invoice_id'     => $response['id'],
				'invoice_number' => $response['number'],
				'order_id' => $order_id,
				'send_email' => $send_email,
			];
		}
	}
}
