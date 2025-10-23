<?php
if (!defined('ABSPATH')) exit;

class GESFaturacao_product_helper
{
    /**
     * Check if product exists in API
     * @param $product_id
     * @return false|mixed|null|object
     */
    public function check_product_code($product_id) {
        global $wpdb;
        $api = new GESFaturacao_API();

        $logger = wc_get_logger();
        $context = array('source' => 'Product Check Logs');

        $code = "wc-$product_id";
        $logger->info(wp_json_encode(['message' => 'Checking product code', 'product_id' => $product_id, 'code' => $code]), $context);

        $api_result = $api->check_product_exists($code);

        $exists = false;
        if (is_wp_error($api_result)) {
            $error_data = $api_result->get_error_data();
            $body = json_decode($error_data['body'], true);
            $error_message = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : '';
            $error_code = isset($body['errors'][0]['code']) ? $body['errors'][0]['code'] : '';

            $logger->error(wp_json_encode(['message' => 'API error checking product', 'product_id' => $product_id, 'error_code' => $error_code, 'error_message' => $error_message]), $context);

            if ($error_code == '"PV_CODE_11"') $exists = false;
        } else {
            $response = $api_result['data'];
            $id = $response['id'] ?? null;
            $exists = ($id > 0);
            $logger->info(wp_json_encode(['message' => 'Product check result', 'product_id' => $product_id, 'exists' => $exists, 'api_id' => $id]), $context);
        }

        if ($exists) {
            return $id;
        } else {
            return false;
        }
    }

    /**
     * Create a product in the GesFaturacao API with the correct VAT rate
     * @param int $product_id WooCommerce product ID
     * @param string $tax_id GesFaturacao tax ID (1, 2, 3, or 4)
     * @param string $type Product type (default: 'P')
     * @return false|mixed|null
     */
    public function create_product($product_id, $tax_id, $type = 'P') {
        global $wpdb;
        $api = new GESFaturacao_API();

        $logger = wc_get_logger();
        $context = array('source' => 'Product Create Logs');

        $logger->info(wp_json_encode(['message' => 'Creating product', 'product_id' => $product_id, 'tax_id' => $tax_id, 'type' => $type]), $context);

        // Get the WooCommerce product
        $product = wc_get_product($product_id);

        if (!$product) {
            $logger->error(wp_json_encode(['message' => 'Product not found', 'product_id' => $product_id]), $context);
            return false;
        }

        $name = $product->get_name();
        if (!$name) {
            $name = 'Produto ' . $product_id;
        }

        // Get the product's tax rate from WooCommerce
        $tax_rates = WC_Tax::get_rates_for_tax_class($product->get_tax_class());
        $rate_percent = 0;
        if (!empty($tax_rates)) {
            $rate = reset($tax_rates); // Get the first applicable tax rate
            if (is_array($rate) && isset($rate['tax_rate'])) {
                $rate_percent = floatval($rate['tax_rate']);
            } elseif (is_object($rate) && isset($rate->tax_rate)) {
                $rate_percent = floatval($rate->tax_rate);
            } else {
                $logger->warning(wp_json_encode(['message' => 'Unexpected tax rate format in create_product', 'rate' => $rate]), $context);
            }
        }

        $logger->info(wp_json_encode(['message' => 'Product tax rate', 'product_id' => $product_id, 'rate_percent' => $rate_percent]), $context);

        // Map WooCommerce tax rate to GesFaturacao tax ID
        $taxMap = [23.00 => 1, 13.00 => 2, 6.00 => 3, 0.00 => 4];
        $mapped_tax_id = $taxMap[round($rate_percent, 2)] ?? $tax_id; // Default to provided tax_id

        $logger->info(wp_json_encode(['message' => 'Mapped tax ID', 'product_id' => $product_id, 'mapped_tax_id' => $mapped_tax_id]), $context);

        // Validate tax_id
        if (!in_array($mapped_tax_id, ['1', '2', '3', '4'])) {
            $logger->error(wp_json_encode(['message' => 'Invalid tax ID', 'product_id' => $product_id, 'mapped_tax_id' => $mapped_tax_id]), $context);
            return false;
        }

        // Get price excluding VAT
        $price_excluding_vat = wc_get_price_excluding_tax($product);

        // API payload
        $product_data = [
            'code' => "wc-$product_id",
            'name' => $name,
            'type' => $type,
            'unit' => '1', // 1 is UN
            'pvp' => $price_excluding_vat, // Price without VAT
            'tax' => $mapped_tax_id, // Correct GesFaturacao tax ID
        ];

        $logger->info(wp_json_encode(['message' => 'Calling API to create product', 'product_id' => $product_id, 'product_data' => $product_data]), $context);

        $api_result = $api->create_product($product_data);

        if (is_wp_error($api_result)) {
            $logger->error(wp_json_encode(['message' => 'Product creation error', 'product_id' => $product_id, 'error' => $api_result->get_error_message()]), $context);
            return false;
        }

        $response = $api_result['data'];
        $logger->info(wp_json_encode(['message' => 'Product created successfully', 'product_id' => $product_id, 'api_id' => $response['id']]), $context);
        return $response['id'];
    }
}