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

		$logger = wc_get_logger();
		$context = array( 'source' => 'Receipt Invoice logs' );

		$logger->info( wp_json_encode( [ 'message' => '-------------------------------------------------------', 'order_id' => $order_id ] ), $context );

		// Get WooCommerce order
		$order = wc_get_order($order_id);

		if ( ! function_exists( 'wc_get_order' ) ) {
			$logger->error( wp_json_encode( [ 'message' => 'WooCommerce not active or loaded' ] ), $context );
			return [
				'success' => false,
				'message' => 'WooCommerce não está ativo ou carregado.'
			];
		}
		if (!$order_id) {
			$logger->error( wp_json_encode( [ 'message' => 'Invalid order ID', 'order_id' => $order_id ] ), $context );
			return [
				'success' => false,
				'message' => 'ID da encomenda inválido.'
			];
		}
		if (!$order) {
			$logger->error( wp_json_encode( [ 'message' => 'Order not found', 'order_id' => $order_id ] ), $context );
			return [
				'success' => false,
				'message' => 'Encomenda não encontrada.'
			];
		}

		$logger->info( wp_json_encode( [ 'message' => 'Order retrieved successfully', 'order_id' => $order_id ] ), $context );

		// Get client ID
		$client_id = $order->get_user_id();
		$logger->info( wp_json_encode( [ 'message' => 'Client ID from order', 'client_id' => $client_id ] ), $context );

			// $client_id = 0 --> CONSUMIDOR FINAL
			// Assumes this function returns either existing remote client ID or creates it
			$client_id = $client->gesfaturacao_sync_client_with_api( $client_id, $order_id );
			$logger->info( wp_json_encode( [ 'message' => 'Client synced with API', 'client_id' => $client_id ] ), $context );



		$logger->info( wp_json_encode( [ 'message' => 'Starting to build lines from order items' ] ), $context );

		// Build lines from order items
		$lines = [];
		$logger->info( wp_json_encode( [ 'message' => 'Order items count', 'count' => count($order->get_items()) ] ), $context );
		foreach ($order->get_items() as $item) {
			$logger->info( wp_json_encode( [ 'message' => 'Processing order item', 'item_id' => $item->get_id(), 'product_id' => $item->get_product_id() ] ), $context );
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
		$item_price_without_VAT = 0;

		if ( ! empty( array_filter( $tax_data['total'] ) ) ) {
			foreach ( $tax_data['total'] as $rate_id => $tax_amount ) {
				// Tenta buscar a taxa do WC
				$rate_str = WC_Tax::get_rate_percent( $rate_id );
				$rate_percent = floatval( rtrim( $rate_str, '%' ) );

				// Calcula manualmente se não existir taxa
				$subtotal   = floatval( $item->get_subtotal() );
				$tax_amount = floatval( $tax_amount );

				if ( $subtotal > 0 ) {
					$item_price_without_VAT = $subtotal;
					$rate_percent = ( $tax_amount / $subtotal ) * 100;
				}

				$logger->info(
					wp_json_encode( [
						'msg'         => 'Taxas calculadas',
						'rate_id'     => $rate_id,
						'rate_percent'=> $rate_percent,
						'subtotal'    => $subtotal,
						'tax_amount'  => $tax_amount,
						'tax_data'    => $tax_data,
					] ),
					$context
				);

				break; // Só usa a primeira taxa
			}
		} else {
			$item_price_without_VAT = floatval( $item->get_total() );
			$rate_percent = 0;

			$logger->info(
				wp_json_encode( [
					'msg'       => 'Item sem impostos',
					'total'     => $item_price_without_VAT,
					'tax_data'  => $tax_data,
				] ),
				$context
			);
		}

			//$tax_id = $this->get_tax_id($rate_percent);
			$tax_id = 0;
			$taxes = $api->get_taxes();
			$taxes_array = $taxes['data'];
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
				if (!$product_id) {
					$logger->error( wp_json_encode( [ 'message' => 'Failed to create product', 'product_id' => $product->get_id() ] ), $context );
					continue; // Skip this product if creation failed
				}
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
				'retention' => 0,
				'unit' => 1,
				'type' => 'P'
			];
		}

		// Exemption check removed for create_invoice_from_order method
		// Use create_invoice_from_order_with_exemptions for handling exemptions

		// Add shipping line if exists
		$shipping_helper = new GesFaturacao_Shipping_Helper();
		$shipping_line = $shipping_helper->get_shipping_line($order_id);
		if ($shipping_line) {
			$lines[] = $shipping_line;
			$logger->info( wp_json_encode( [ 'message' => 'Shipping line added', 'shipping_line' => $shipping_line ] ), $context );
		} else {
			$logger->info( wp_json_encode( [ 'message' => 'No shipping line added' ] ), $context );
		}

		$logger->info( wp_json_encode( [ 'message' => 'Lines built for invoice', 'lines_count' => count($lines) ] ), $context );

		$logger->info( wp_json_encode( [ 'message' => 'Calling API to create invoice' ] ), $context );

		//Get the GESFaturacao options from the WP_Options table
		$options = get_option( 'gesfaturacao_options', [] );
		$serie_id = $options['serie'];
		$finalize_invoice = $options['finalize'];
		$send_email = $options['email'];

		// Get payment mapping from database
		$payment_method = $order->get_payment_method();
		$table_name = $wpdb->prefix . 'gesfaturacao_payment_map';
		$mapping = $wpdb->get_row($wpdb->prepare(
			"SELECT ges_payment_id, ges_bank_id FROM $table_name WHERE id_shop = 1 AND module_name = %s",
			$payment_method
		), ARRAY_A);

		if ($mapping) {
			$ges_payment_id = $mapping['ges_payment_id'];
			$ges_bank_id = $mapping['ges_bank_id'];
			$needs_bank = !empty($ges_bank_id);
		} else {
			// Default if no mapping found
			$ges_payment_id = '2';
			$ges_bank_id = null;
			$needs_bank = false;
		}

		// Prepare payload
		$invoice_data = [
			'client' => $client_id,
			'serie' => $serie_id,
			'date' => current_time('d/m/Y'),
			'expiration' => current_time('d/m/Y'),
			'coin' => '1',
			'payment' => $ges_payment_id,
			'needsBank' => $needs_bank,
			'bank' => $ges_bank_id,
			'lines' => json_encode($lines),
			'finalize' => (bool)$finalize_invoice,
		];

		$logger->info( wp_json_encode( [ 'lines' => $lines ] ), $context );
		$logger->info( wp_json_encode( [ 'invoice_data' => $invoice_data ] ), $context );

		$api_result = $api->create_invoice( $invoice_data );

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
			
			$response =$api_result['data'];
			$logger->info( wp_json_encode( [ 'message' => 'Invoice created successfully', 'invoice_id' => $response['id'], 'invoice_number' => $response['number'] ] ), $context );

			$table_invoices = $wpdb->prefix . 'gesfaturacao_invoices';

			$wpdb->insert(
				$table_invoices,
				[
					'order_id'       => $order_id,
					'invoice_id'     => $response['id'],
					'invoice_number' => $response['number'],
					'created_at'     => current_time( 'mysql' ),
				]
			);

			$logger->info( wp_json_encode( [ 'message' => 'Invoice saved to database', 'order_id' => $order_id ] ), $context );

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
		$client_id = $order->get_user_id();

			// $client_id = 0 --> CONSUMIDOR FINAL
			// Assumes this function returns either existing remote client ID or creates it
			$client_id = $client->gesfaturacao_sync_client_with_api( $client_id, $order_id );


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

		// Add shipping line if exists
		$shipping_helper = new GesFaturacao_Shipping_Helper();
		$shipping_line = $shipping_helper->get_shipping_line($order_id);
		if ($shipping_line) {
			$lines[] = $shipping_line;
		}

		//Get the GESFaturacao options from the WP_Options table
		$options = get_option( 'gesfaturacao_options', [] );
		$serie_id = $options['serie'];
		$finalize_invoice = $options['finalize'];
		$send_email = $options['email'];

		// Get payment mapping from database
		$payment_method = $order->get_payment_method();
		$table_name = $wpdb->prefix . 'gesfaturacao_payment_map';
		$mapping = $wpdb->get_row($wpdb->prepare(
			"SELECT ges_payment_id, ges_bank_id FROM $table_name WHERE id_shop = 1 AND module_name = %s",
			$payment_method
		), ARRAY_A);

		if ($mapping) {
			$ges_payment_id = $mapping['ges_payment_id'];
			$ges_bank_id = $mapping['ges_bank_id'];
			$needs_bank = !empty($ges_bank_id);
		} else {
			$ges_payment_id = '2';
			$ges_bank_id = null;
			$needs_bank = false;
		}

		// Prepare payload
		$invoice_data = [
			'client' => $client_id,
			'serie' => $serie_id,
			'date' => current_time('d/m/Y'),
			'expiration' => current_time('d/m/Y'),
			'coin' => '1',
			'payment' => $ges_payment_id,
			'needsBank' => $needs_bank,
			'bank' => $ges_bank_id,
			'lines' => json_encode($lines),
			'finalize' => (bool)$finalize_invoice,
		];

		$logger->info( wp_json_encode( [ 'lines' => $lines ] ), $context );
		$logger->info( wp_json_encode( [ 'invoice_data' => $invoice_data ] ), $context );

		$logger->info( wp_json_encode( [ 'message' => 'Calling API to create invoice' ] ), $context );
		$api_result = $api->create_invoice( $invoice_data );
		$logger->info( wp_json_encode( [ 'message' => 'API call completed', 'api_result_success' => !is_wp_error($api_result) ] ), $context );

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
					'invoice_id'     => $response['id'],        
					'invoice_number' => $response['number'],   
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