<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GesFaturacao_Shipping_Helper {

    public function get_shipping_product_id() {
        $logger = wc_get_logger();
        $context = ['source' => 'GESFaturacao_Shipping'];

        $options = get_option('gesfaturacao_options', []);
        $product_id = isset($options['shipping']) ? intval($options['shipping']) : null;

        $logger->info(wp_json_encode([
            'message' => 'Obtendo ID do produto de portes',
            'product_id' => $product_id
        ]), $context);

        return $product_id ?: null;
    }

    public function get_shipping_details($order_id) {
        global $wpdb;
        $logger = wc_get_logger();
        $context = ['source' => 'GESFaturacao_Shipping'];

        $query = $wpdb->prepare("
            SELECT
              i.order_id,
              i.order_item_name AS shipping_method,
              MAX(CASE WHEN m.meta_key = 'cost' THEN m.meta_value END) AS cost,
              MAX(CASE WHEN m.meta_key = 'total_tax' THEN m.meta_value END) AS total_tax,
              CASE
                WHEN MAX(CASE WHEN m.meta_key = 'total_tax' THEN m.meta_value END) > 0
                THEN 'Tributável'
                ELSE 'Isento'
              END AS tax_status
            FROM {$wpdb->prefix}woocommerce_order_items i
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
              ON i.order_item_id = m.order_item_id
            WHERE i.order_item_type = 'shipping' AND i.order_id = %d
            GROUP BY i.order_item_id
        ", $order_id);

        $results = $wpdb->get_results($query, ARRAY_A);

        $logger->info(wp_json_encode([
            'message' => 'Detalhes de portes obtidos',
            'order_id' => $order_id,
            'results' => $results
        ]), $context);

        return $results ?: [];
    }

    public function get_shipping_from_api($shipping_product_id) {
        $logger = wc_get_logger();
        $context = ['source' => 'GESFaturacao_Shipping'];

        if ( ! class_exists('GESFaturacao_API') ) {
            require_once plugin_dir_path(__FILE__) . 'class-api.php';
        }

        $api = new GESFaturacao_API();
        $response = $api->get_shipping(); // usa a função já existente da API

        if (!empty($response['data'])) {
            foreach ($response['data'] as $product) {
                if ((int)$product['id'] === (int)$shipping_product_id) {
                    $logger->info(wp_json_encode([
                        'message' => 'Produto de portes obtido da API GesFaturacao',
                        'product_id' => $shipping_product_id,
                        'api_product' => $product
                    ]), $context);
                    return $product;
                }
            }
        }

        $logger->error(wp_json_encode([
            'message' => 'Falha ao obter produto de portes na API GesFaturacao',
            'product_id' => $shipping_product_id,
            'response' => $response
        ]), $context);

        return null;
    }

    public function get_shipping_line($order_id) {
        $logger = wc_get_logger();
        $context = ['source' => 'GESFaturacao_Shipping'];

        $logger->info(wp_json_encode([
            'message' => 'Construindo linha de portes para a fatura',
            'order_id' => $order_id
        ]), $context);

        $details = $this->get_shipping_details($order_id);
        if (empty($details)) {
            $logger->warning(wp_json_encode([
                'message' => 'Nenhum detalhe de portes encontrado',
                'order_id' => $order_id
            ]), $context);
            return null;
        }

        $shipping_product_id = $this->get_shipping_product_id();
        if (!$shipping_product_id) return null;

        $shipping_product = $this->get_shipping_from_api($shipping_product_id);

        $shipping_name = $shipping_product['description'] ?? $shipping_product['name'];
        $shipping_code = $shipping_product['code'];

        // 🔹 Aqui pegamos o valor **exatamente como está no WooCommerce**
        $cost = floatval($details[0]['cost'] ?? 0);       

        $tax_status = $details[0]['tax_status'] ?? 'Isento';

        $tax_id = ($tax_status === 'Tributável') ? 1 : 4;
        $exemption = ($tax_status === 'Tributável') ? 0 : 10;

        $shipping_line = [
            'id' => $shipping_product_id,
            'code' => $shipping_code,
            'description' => substr($shipping_name, 0, 100),
            'quantity' => 1,
            'price' => round($cost, 4), // ✅ envia o preço **exato dos portes**
            'tax' => $tax_id,
            'discount' => 0.0,
            'retention' => 0.0,
            'exemption' => $exemption,
            'unit' => 1,
            'type' => 'S'
        ];

        // 🔹 LOG FINAL
        $logger->info(wp_json_encode([
            'message' => 'Linha de portes final para envio à fatura',
            'shipping_line' => $shipping_line
        ]), $context);

        return $shipping_line;
    }

}