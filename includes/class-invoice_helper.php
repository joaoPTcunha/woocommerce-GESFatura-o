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
	public function create_invoice_from_order($order_id, $send_email = false, $custom_email = null, $exemptions = []) {
		global $wpdb;
		$api = new GesFaturacao_API();
		$products = new GESFaturacao_product_helper();
		$client = new GESFaturacao_client_helper();

		$logger = wc_get_logger();
		$context = array('source' => 'Receipt Invoice logs');

		$logger->info(wp_json_encode(['message' => '-------------------------------------------------------', 'order_id' => $order_id]), $context);

		// Get WooCommerce order
		$order = wc_get_order($order_id);

		if (!function_exists('wc_get_order')) {
			$logger->error(wp_json_encode(['message' => 'WooCommerce not active or loaded']), $context);
			return [
				'success' => false,
				'message' => 'WooCommerce não está ativo ou carregado.'
			];
		}
		if (!$order_id) {
			$logger->error(wp_json_encode(['message' => 'Invalid order ID', 'order_id' => $order_id]), $context);
			return [
				'success' => false,
				'message' => 'ID da encomenda inválido.'
			];
		}
		if (!$order) {
			$logger->error(wp_json_encode(['message' => 'Order not found', 'order_id' => $order_id]), $context);
			return [
				'success' => false,
				'message' => 'Encomenda não encontrada.'
			];
		}

		$logger->info(wp_json_encode(['message' => 'Order retrieved successfully', 'order_id' => $order_id]), $context);

		// Get client ID
		$client_id = $order->get_user_id();
		$logger->info(wp_json_encode(['message' => 'Client ID from order', 'client_id' => $client_id]), $context);

		// $client_id = 0 --> CONSUMIDOR FINAL
		$client_id = $client->gesfaturacao_sync_client_with_api($client_id, $order_id);
		$logger->info(wp_json_encode(['message' => 'Client synced with API', 'client_id' => $client_id]), $context);

		$logger->info(wp_json_encode(['message' => 'Starting to build lines from order items']), $context);

		// Build lines from order items
		$lines = [];
		$missing_exemptions = [];
		$logger->info(wp_json_encode(['message' => 'Order items count', 'count' => count($order->get_items())]), $context);

		foreach ($order->get_items() as $item) {
			$logger->info(wp_json_encode(['message' => 'Processing order item', 'item_id' => $item->get_id(), 'product_id' => $item->get_product_id()]), $context);
			$product = $item->get_product();

			if (!$product) continue;

			// Get tax percentage from WooCommerce
			$tax_data = $item->get_taxes();
			$rate_percent = 0;
			$item_price_without_VAT = 0;

			if (!empty(array_filter($tax_data['total']))) {
				foreach ($tax_data['total'] as $rate_id => $tax_amount) {
					$rate_str = WC_Tax::get_rate_percent($rate_id);
					$rate_percent = floatval(rtrim($rate_str, '%'));

					$subtotal = floatval($item->get_subtotal());
					$tax_amount = floatval($tax_amount);

					if ($subtotal > 0) {
						$item_price_without_VAT = $subtotal;
						$rate_percent = ($tax_amount / $subtotal) * 100;
					}

					$logger->info(
						wp_json_encode([
							'msg' => 'Taxas calculadas',
							'rate_id' => $rate_id,
							'rate_percent' => $rate_percent,
							'subtotal' => $subtotal,
							'tax_amount' => $tax_amount,
							'tax_data' => $tax_data,
						]),
						$context
					);
					break;
				}
			} else {
				$item_price_without_VAT = floatval($item->get_total());
				$rate_percent = 0;

				$logger->info(
					wp_json_encode([
						'msg' => 'Item sem impostos',
						'total' => $item_price_without_VAT,
						'tax_data' => $tax_data,
					]),
					$context
				);
			}

			// Map tax rate to tax ID
			$tax_id = 0;
			$taxes = $api->get_taxes();
			$taxes_array = $taxes['data'];
			foreach ($taxes_array as $tax) {
				if (floatval($tax['value']) == round($rate_percent, 2)) {
					$tax_id = $tax['id'];
					break;
				}
			}

			if (!$tax_id) {
				$logger->error(wp_json_encode(['message' => 'Tax configuration error', 'rate_percent' => $rate_percent]), $context);
				return [
					'success' => false,
					'message' => 'Taxas de imposto mal configuradas. Verifique o WooCommerce e o GESFaturação'
				];
			}

			// Check for tax ID 4 (0% VAT) and handle exemptions
			$exemption_id = 0;
			if ($tax_id == 4) {
				$woocommerce_id = $item->get_product_id();
				$api_product_id = $products->check_product_code($woocommerce_id);
				if (!$api_product_id) {
					$api_product_id = $products->create_product($woocommerce_id, $tax_id);
					if (!$api_product_id) {
						$logger->error(wp_json_encode(['message' => 'Failed to create product', 'product_id' => $woocommerce_id]), $context);
						continue;
					}
				}

				// Check if exemption reason is provided
				if (isset($exemptions[$woocommerce_id])) {
					$exemption_id = intval($exemptions[$woocommerce_id]);
				} else {
					$missing_exemptions[] = [
						'product_id' => $woocommerce_id,
						'product_name' => $item->get_name(),
						'item_key' => $item->get_id(),
					];
				}
			}

			// Product code check/create
			$product_id = $products->check_product_code($product->get_id());
			if (!$product_id) {
				$product_id = $products->create_product($product->get_id(), $tax_id);
				if (!$product_id) {
					$logger->error(wp_json_encode(['message' => 'Failed to create product', 'product_id' => $product->get_id()]), $context);
					continue;
				}
			}

			$quantity = $item->get_quantity();
			$unit_price_without_vat = wc_get_price_excluding_tax($product);

			$lines[] = [
				'id' => $product_id,
				'description' => $item->get_name(),
				'quantity' => $quantity,
				'price' => $item_price_without_VAT,
				'tax' => $tax_id,
				'exemption' => $exemption_id,
				'discount' => 0,
				'retention' => 0,
				'unit' => 1,
				'type' => 'P'
			];
		}

		// If there are missing exemptions, return to trigger modal
		if (!empty($missing_exemptions)) {
			$product_names = array_column($missing_exemptions, 'product_name');
			$message = 'Faltam motivos de isenção para os produtos: ' . implode(', ', $product_names) . '.';
			return [
				'success' => false,
				'error_code' => 'missing_exemption_reasons',
				'message' => $message,
				'order_id' => $order_id,
				'missing_data' => $missing_exemptions,
				'exemption_data' => $api->get_exemption_reason()
			];
		}

		// Add shipping line if exists
		$shipping_helper = new GesFaturacao_Shipping_Helper();
		$shipping_line = $shipping_helper->get_shipping_line($order_id);
		if ($shipping_line) {
			$lines[] = $shipping_line;
			$logger->info(wp_json_encode(['message' => 'Shipping line added', 'shipping_line' => $shipping_line]), $context);
		} else {
			$logger->info(wp_json_encode(['message' => 'No shipping line added']), $context);
		}

		$logger->info(wp_json_encode(['message' => 'Lines built for invoice', 'lines_count' => count($lines)]), $context);

		// Get GESFaturacao options
		$options = get_option('gesfaturacao_options', []);
		$serie_id = $options['serie'];
		$finalize_invoice = $options['finalize'];
		$send_email_option = $options['email'];

		// Override send_email if custom_email is provided
		$send_email = $custom_email !== null ? $send_email : $send_email_option;

		// Get payment mapping from database
		$payment_method = $order->get_payment_method();
		$table_name = $wpdb->prefix . 'gesfaturacao_payment_map';
		$mapping = $wpdb->get_row($wpdb->prepare(
			"SELECT ges_payment_id, ges_bank_id FROM $table_name WHERE id_shop = 1 AND module_name = %s",
			$payment_method
		), ARRAY_A);

		if ($mapping) {
			$ges_payment_id = $mapping['ges_payment_id'];
			$needs_bank = !empty($mapping['ges_bank_id']);
			$ges_bank_id = $mapping['ges_bank_id'];
		} else {
			$ges_payment_id = '3';
			$needs_bank = true;
			$ges_bank_id = 1;
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

		$logger->info(wp_json_encode(['lines' => $lines]), $context);
		$logger->info(wp_json_encode(['invoice_data' => $invoice_data]), $context);
		$logger->info(wp_json_encode(['message' => 'Calling API to create invoice']), $context);

		// Call API to create invoice
		$api_result = $api->create_invoice($invoice_data);
		$logger->info(wp_json_encode(['message' => 'API call completed', 'api_result_success' => !is_wp_error($api_result)]), $context);

		if (is_wp_error($api_result)) {
			$response_data = $api_result->get_error_data();
			$body = $response_data['body'] ?? null;
			$decoded_body = json_decode($body, true);
			$error_message = $decoded_body['errors']['message'] ?? 'Ocorreu um erro ao criar a fatura.';

			return [
				'success' => false,
				'error_code' => $decoded_body['errors']['code'] ?? 'unknown_error',
				'message' => $error_message,
			];
		}

		$response = $api_result['data'];
		$logger->info(wp_json_encode(['message' => 'Invoice created successfully', 'invoice_id' => $response['id'], 'invoice_number' => $response['number']]), $context);

		// Save invoice in custom table
		$table_invoices = $wpdb->prefix . 'gesfaturacao_invoices';
		$wpdb->insert(
			$table_invoices,
			[
				'order_id' => $order_id,
				'invoice_id' => $response['id'],
				'invoice_number' => $response['number'],
				'created_at' => current_time('mysql'),
			]
		);

		$logger->info(wp_json_encode(['message' => 'Invoice saved to database', 'order_id' => $order_id]), $context);

		return [
			'success' => true,
			'invoice_id' => $response['id'],
			'invoice_number' => $response['number'],
			'order_id' => $order_id,
			'send_email' => $send_email,
		];
	}
}