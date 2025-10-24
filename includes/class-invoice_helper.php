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
        $discount_helper = new GesFaturacao_Discount_Helper();

        $logger = wc_get_logger();
        $context = ['source' => 'GesFaturacao_Invoice_Helper'];

        $logger->info(wp_json_encode(['message' => '=== INÍCIO CRIAÇÃO FATURA ===', 'order_id' => $order_id]), $context);

        // === 1. Validação inicial ===
        if (!function_exists('wc_get_order')) {
            $logger->error(wp_json_encode(['message' => 'WooCommerce não carregado']), $context);
            return ['success' => false, 'message' => 'WooCommerce não está ativo.'];
        }

        if (!$order_id || !($order = wc_get_order($order_id))) {
            $logger->error(wp_json_encode(['message' => 'Encomenda inválida ou não encontrada', 'order_id' => $order_id]), $context);
            return ['success' => false, 'message' => 'Encomenda não encontrada.'];
        }

        $logger->info(wp_json_encode(['message' => 'Encomenda carregada com sucesso', 'order_id' => $order_id]), $context);

        // === 2. Cliente ===
        $client_id = $order->get_user_id();
        $client_id = $client->gesfaturacao_sync_client_with_api($client_id, $order_id);
        $logger->info(wp_json_encode(['message' => 'Cliente sincronizado', 'client_id' => $client_id]), $context);

        // === 3. Linhas de produtos ===
        $lines = [];
        $missing_exemptions = [];
        $highest_tax_rate = 0;
        $taxMap = [23.00 => 1, 13.00 => 2, 6.00 => 3, 0.00 => 4];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                $logger->warning(wp_json_encode(['message' => 'Produto não encontrado', 'item_id' => $item->get_id()]), $context);
                continue;
            }

            // --- IVA ---
            $tax_class = $product->get_tax_class();
            $tax_rates = WC_Tax::get_rates_for_tax_class($tax_class);
            $rate_percent = 0;

            if (!empty($tax_rates)) {
                $rate = reset($tax_rates);
                $rate_percent = is_array($rate) ? floatval($rate['tax_rate']) : floatval($rate->tax_rate);
            }

            if ($rate_percent > $highest_tax_rate) {
                $highest_tax_rate = $rate_percent;
            }

            $tax_id = $taxMap[round($rate_percent, 2)] ?? 1;
            if (!isset($taxMap[round($rate_percent, 2)])) {
                $logger->warning(wp_json_encode(['message' => 'Taxa desconhecida, usando 23%', 'rate' => $rate_percent]), $context);
            }

            // --- Isenção (IVA 0%) + Motivo ---
            $exemption_id = 0;
            if ($tax_id == 4) {
                $wc_id = $item->get_product_id();
                $api_product_id = $products->check_product_code($wc_id) ?: $products->create_product($wc_id, $tax_id);

                if (!$api_product_id) {
                    $logger->error(wp_json_encode(['message' => 'Falha ao criar produto 0% IVA', 'product_id' => $wc_id]), $context);
                    continue;
                }

                if (!empty($exemptions[$wc_id]) && $exemptions[$wc_id] > 0) {
                    $exemption_id = intval($exemptions[$wc_id]);
                } else {
                    $missing_exemptions[] = ['product_id' => $wc_id, 'product_name' => $item->get_name()];
                    $logger->warning(wp_json_encode(['message' => 'Falta motivo de isenção', 'product_id' => $wc_id]), $context);
                }
            }

            // --- Produto na API ---
            $product_id = $products->check_product_code($product->get_id()) ?: $products->create_product($product->get_id(), $tax_id);
            if (!$product_id) {
                $logger->error(wp_json_encode(['message' => 'Falha ao criar produto', 'product_id' => $product->get_id()]), $context);
                continue;
            }

            // --- Preço sem IVA (base para linha) ---
            $quantity = $item->get_quantity();
            $regular_price = (float) $product->get_regular_price();
            $regular_price_ex_tax = wc_get_price_excluding_tax($product, ['price' => $regular_price]);

            // Calculate effective discount percentage to reach current price
            $final_price_per_unit = (float) $item->get_total() / $quantity;
            $final_price_ex_tax = wc_get_price_excluding_tax($product, ['price' => $final_price_per_unit]);
            $effective_discount = 0;
            if ($regular_price_ex_tax > 0) {
                $effective_discount = round((($regular_price_ex_tax - $final_price_ex_tax) / $regular_price_ex_tax) * 100, 4);
            }

            // Distribute general discount proportionally across products
            $general_discount_amount = (float) $order->get_discount_total();
            if ($general_discount_amount > 0) {
                $order_subtotal = (float) $order->get_subtotal();
                $item_subtotal = (float) $item->get_subtotal();
                $proportional_general_discount = ($item_subtotal / $order_subtotal) * $general_discount_amount;
                $proportional_general_discount_ex_tax = wc_get_price_excluding_tax($product, ['price' => $proportional_general_discount / $quantity]);
                $general_discount_percentage = ($regular_price_ex_tax > 0) ? round(($proportional_general_discount_ex_tax / $regular_price_ex_tax) * 100, 4) : 0;
                $effective_discount += $general_discount_percentage;
            }

            $lines[] = [
                'id' => $product_id,
                'description' => $item->get_name(),
                'quantity' => $quantity,
                'price' => round($regular_price_ex_tax, 4),
                'tax' => $tax_id,
                'exemption' => $exemption_id,
                'discount' => $effective_discount,
                'retention' => 0,
                'unit' => 1,
                'type' => 'P'
            ];
        }

        // === 4. Portes de envio ===
        if ($order->get_shipping_total() > 0) {
            $shipping_helper = new GesFaturacao_Shipping_Helper();
            $shipping_line = $shipping_helper->get_shipping_line($order_id);

            $shipping_tax_id = $taxMap[round($highest_tax_rate, 4)] ?? 1;
            $shipping_cost = floatval($order->get_shipping_total());

            $lines[] = [
                'id' => $shipping_line['id'] ?? 'shipping_' . $order_id,
                'code' => $shipping_line['code'] ?? 'SHIPPING',
                'description' => $shipping_line['description'] ?? 'Portes de Envio',
                'quantity' => 1,
                'price' => round($shipping_cost, 2),
                'tax' => $shipping_tax_id,
                'exemption' => 0,
                'discount' => 0,
                'retention' => 0,
                'unit' => 1,
                'type' => 'S'
            ];

            $logger->info(wp_json_encode(['message' => 'Linha de portes adicionada']), $context);
        }

        $logger->info(wp_json_encode(['message' => 'Linhas construídas', 'total_lines' => count($lines)]), $context);

        // === 5. PROCESSAR DESCONTOS ===
        $general_discount_percentage = $discount_helper->process_order_discounts($order, $lines);

        $logger->info(wp_json_encode([
            'message' => 'Descontos aplicados',
            'general_discount' => $general_discount_percentage,
            'product_discounts' => array_filter($lines, fn($l) => ($l['discount'] ?? 0) > 0)
        ]), $context);

        // === 6. Verificar isenções pendentes ===
        if (!empty($missing_exemptions)) {
            $exemption_reasons = $api->get_exemption_reasons();
            return [
                'success' => false,
                'error_code' => 'missing_exemption_reasons',
                'order_id' => $order_id,
                'missing_data' => $missing_exemptions,
                'exemption_data' => $exemption_reasons,
                'message' => 'Faltam motivos de isenção para produtos com IVA 0%.'
            ];
        }

        // === 7. Opções e pagamento ===
        $options = get_option('gesfaturacao_options', []);
        $serie_id = $options['serie'] ?? '';
        $finalize_invoice = !empty($options['finalize']);
        $send_email_option = !empty($options['email']);

        $send_email = $custom_email !== null ? $send_email : $send_email_option;

        $payment_method = $order->get_payment_method();
        $table_name = $wpdb->prefix . 'gesfaturacao_payment_map';
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT ges_payment_id, ges_bank_id FROM $table_name WHERE id_shop = 1 AND module_name = %s",
            $payment_method
        ), ARRAY_A);

        $ges_payment_id = $mapping['ges_payment_id'] ?? '3';
        $needs_bank = !empty($mapping['ges_bank_id']);
        $ges_bank_id = $mapping['ges_bank_id'] ?? 1;

        // === 8. Payload da fatura ===
        $invoice_data = [
            'client' => $client_id,
            'serie' => $serie_id,
            'date' => current_time('d/m/Y'),
            'expiration' => current_time('d/m/Y'),
            'coin' => '1',
            'payment' => $ges_payment_id,
            'needsBank' => $needs_bank,
            'bank' => $ges_bank_id,
            'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'finalize' => $finalize_invoice,
            'discount' => $general_discount_percentage 
        ];

        $logger->info(wp_json_encode(['message' => 'Payload preparado', 'invoice_data' => $invoice_data]), $context);

        // === 9. Chamar API ===
        $api_result = $api->create_invoice($invoice_data);

        if (is_wp_error($api_result)) {
            $body = $api_result->get_error_data()['body'] ?? '';
            $error = json_decode($body, true);
            $msg = $error['errors']['message'] ?? 'Erro ao criar fatura.';

            $logger->error(wp_json_encode(['message' => 'Erro na API', 'error' => $msg]), $context);

            return [
                'success' => false,
                'error_code' => $error['errors']['code'] ?? 'unknown',
                'message' => $msg
            ];
        }

        $response = $api_result['data'];
        $logger->info(wp_json_encode(['message' => 'Fatura criada', 'invoice_id' => $response['id'], 'number' => $response['number']]), $context);

        // === 10. Guardar na BD ===
        $table_invoices = $wpdb->prefix . 'gesfaturacao_invoices';
        $wpdb->insert($table_invoices, [
            'order_id' => $order_id,
            'invoice_id' => $response['id'],
            'invoice_number' => $response['number'],
            'created_at' => current_time('mysql')
        ]);

        $logger->info(wp_json_encode(['message' => 'Fatura guardada na BD']), $context);

        return [
            'success' => true,
            'invoice_id' => $response['id'],
            'invoice_number' => $response['number'],
            'order_id' => $order_id,
            'send_email' => $send_email
        ];
    }
}