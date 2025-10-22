<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GesFaturacao_Shipping_Helper {

    /**
     * Get shipping product ID from settings.
     *
     * @return int|null The product ID or null if not set.
     */
    public function get_shipping_product_id() {
        $options = get_option('gesfaturacao_options', []);
        $product_id = isset($options['shipping']) ? intval($options['shipping']) : null;

        if (!$product_id) {
            return null;
        }

        return $product_id;
    }

    /**
     * Get shipping cost for an order (including tax).
     *
     * @param int $order_id The WooCommerce order ID.
     * @return float The shipping cost including tax.
     */
    public function get_shipping_cost($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return 0;

        $shipping_total = $order->get_shipping_total();
        return floatval($shipping_total);
    }

    /**
     * Get shipping cost without VAT for an order.
     *
     * @param int $order_id The WooCommerce order ID.
     * @return float The shipping cost excluding tax.
     */
    public function get_shipping_cost_without_vat($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return 0;

        $shipping_total = $order->get_shipping_total();
        $shipping_tax = $order->get_shipping_tax();
        return floatval($shipping_total - $shipping_tax);
    }

    /**
     * Build shipping line for invoice.
     *
     * @param int $order_id The WooCommerce order ID.
     * @return array|null The shipping line array or null if no shipping.
     */
    public function get_shipping_line($order_id) {
        $shipping_cost = $this->get_shipping_cost($order_id);
        if ($shipping_cost <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order) return null;

        $shipping_product_id = $this->get_shipping_product_id();
        if (!$shipping_product_id) {
            return null; // Failed to get shipping product
        }

        $products_helper = new GESFaturacao_product_helper();
        $api_product_id = $products_helper->check_product_code($shipping_product_id);
        if (!$api_product_id) {
            $tax_id = 1; // Assume tax 1 for shipping
            $api_product_id = $products_helper->create_product($shipping_product_id, $tax_id, 'S');
        }

        $options = get_option('gesfaturacao_options', []);
        $shipping_name = isset($options['shipping_name']) ? $options['shipping_name'] : $order->get_shipping_method();

        $shipping_cost_without_vat = $this->get_shipping_cost_without_vat($order_id);

        $shipping_line = [
            'id' => $api_product_id,
            'description' => substr($shipping_name, 0, 100),
            'quantity' => 1,
            'price' => floatval($shipping_cost_without_vat),
            'tax' => 1,
            'discount' => 0.0,
            'retention' => 0.0,
            'exemption' => 0,
            'unit' => 1,
            'type' => 'S'
        ];
        return $shipping_line;
    }
}
