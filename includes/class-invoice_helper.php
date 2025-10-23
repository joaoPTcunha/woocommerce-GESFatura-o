<?php
if (!defined('ABSPATH')) exit;

class GesFaturacao_Invoice_Helper {
    /**
     * Create (and optionally send) an invoice for a given WooCommerce order.
     *
     * @param int $order_id The WooCommerce order ID.
     * @param bool $send_email Whether to send the invoice by email (default: false).
     * @param string|null $custom_email If provided, override billing email for sending.
     * @param array $exemptions Array of exemption reasons for 0% VAT products.
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
        $highest_tax_rate = 0; // Track the highest tax rate for shipping
        $logger->info(wp_json_encode(['message' => 'Order items count', 'count' => count($order->get_items())]), $context);

        foreach ($order->get_items() as $item) {
            try {
                $logger->info(wp_json_encode(['message' => 'Processing order item', 'item_id' => $item->get_id(), 'product_id' => $item->get_product_id()]), $context);
                $product = $item->get_product();

                if (!$product) {
                    $logger->warning(wp_json_encode(['message' => 'Product not found for item', 'item_id' => $item->get_id()]), $context);
                    continue;
                }

                // Get tax percentage from WooCommerce product tax class
                $tax_class = $product->get_tax_class();
                $tax_rates = WC_Tax::get_rates_for_tax_class($tax_class);
                $rate_percent = 0;
                $item_price_without_VAT = floatval($item->get_subtotal());

                if (!empty($tax_rates)) {
                    $rate = reset($tax_rates);
                    if (is_array($rate) && isset($rate['tax_rate'])) {
                        $rate_percent = floatval($rate['tax_rate']);
                    } elseif (is_object($rate) && isset($rate->tax_rate)) {
                        $rate_percent = floatval($rate->tax_rate);
                    } else {
                        $logger->warning(wp_json_encode(['message' => 'Unexpected tax rate format', 'rate' => $rate]), $context);
                    }
                }

                $logger->info(
                    wp_json_encode([
                        'msg' => 'Taxa do produto',
                        'tax_class' => $tax_class,
                        'rate_percent' => $rate_percent,
                        'item_price_without_VAT' => $item_price_without_VAT,
                    ]),
                    $context
                );

                // Update highest tax rate for shipping
                if ($rate_percent > $highest_tax_rate) {
                    $highest_tax_rate = $rate_percent;
                }

                // Map tax rate to tax ID
                $taxMap = [23.00 => 1, 13.00 => 2, 6.00 => 3, 0.00 => 4];
                $tax_id = $taxMap[round($rate_percent, 2)] ?? 1; // Default to 1 (23% VAT)

                if (!isset($taxMap[round($rate_percent, 2)])) {
                    $logger->warning(wp_json_encode(['message' => 'Unknown tax rate, defaulting to 23%', 'rate_percent' => $rate_percent]), $context);
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

                    // Check if exemption reason is provided and valid (not 0)
                    if (isset($exemptions[$woocommerce_id]) && intval($exemptions[$woocommerce_id]) > 0) {
                        $exemption_id = intval($exemptions[$woocommerce_id]);
                    } else {
                        // Collect missing exemption for modal
                        $missing_exemptions[] = [
                            'product_id' => $woocommerce_id,
                            'product_name' => $item->get_name(),
                        ];
                        $logger->warning(wp_json_encode(['message' => 'Missing exemption reason for 0% VAT product', 'product_id' => $woocommerce_id]), $context);
                        $exemption_id = 0;
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
                $unit_price_without_vat = wc_get_price_excluding_tax($product, ['qty' => $quantity]);

                $lines[] = [
                    'id' => $product_id,
                    'description' => $item->get_name(),
                    'quantity' => $quantity,
                    'price' => $item_price_without_VAT,
                    'tax' => $tax_id, // Use correct tax_id (string)
                    'exemption' => $exemption_id,
                    'discount' => 0,
                    'retention' => 0,
                    'unit' => 1,
                    'type' => 'P'
                ];
            } catch (Exception $e) {
                $logger->error(wp_json_encode(['message' => 'Error processing order item', 'item_id' => $item->get_id(), 'error' => $e->getMessage()]), $context);
                continue;
            }
        }

        // Add shipping line if exists
        $shipping_helper = new GesFaturacao_Shipping_Helper();
        $shipping_line = $shipping_helper->get_shipping_line($order_id);
        if ($order->get_shipping_total() > 0) {
            $shipping_tax_id = $taxMap[round($highest_tax_rate, 2)] ?? 1; // Default to 23% (Normal) for shipping

            $shipping_cost = floatval($order->get_shipping_total()); // Get shipping cost without VAT
            $lines[] = [
                'id' => $shipping_line ? $shipping_line['id'] : 'shipping_' . $order_id,
                'code' => $shipping_line ? $shipping_line['code'] : 'SHIPPING',
                'description' => $shipping_line ? $shipping_line['description'] : 'Portes de Envio',
                'quantity' => 1,
                'price' => $shipping_cost,
                'tax' => $shipping_tax_id, // Use highest tax rate
                'exemption' => 0,
                'discount' => 0,
                'retention' => 0,
                'unit' => 1,
                'type' => 'S'
            ];
            $logger->info(wp_json_encode(['message' => 'Shipping line added', 'shipping_line' => $lines[count($lines) - 1]]), $context);
        } else {
            $logger->info(wp_json_encode(['message' => 'No shipping line added']), $context);
        }

        $logger->info(wp_json_encode(['message' => 'Lines built for invoice', 'lines_count' => count($lines)]), $context);

        // Check if there are missing exemptions before proceeding
        if (!empty($missing_exemptions)) {
            $api = new GesFaturacao_API();
            $exemption_reasons = $api->get_exemption_reasons();
            return [
                'success' => false,
                'error_code' => 'missing_exemption_reasons',
                'order_id' => $order_id,
                'missing_data' => $missing_exemptions,
                'exemption_data' => $exemption_reasons,
                'message' => 'Produtos com IVA 0% necessitam de motivo de isenção.',
            ];
        }

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