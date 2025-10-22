<?php

if (!defined('ABSPATH')) exit;

class GESFaturacao_client_helper
{
    function gesfaturacao_sync_client_with_api($client_id, $order_id) {
        global $wpdb;
        $api = new GESFaturacao_API();

        // Initialize WooCommerce logger
        $logger = wc_get_logger();
        $context = array('source' => 'gesfaturacao-client-sync');

        // Load the order
        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error("Order ID {$order_id} not found.", $context);
            return new WP_Error('invalid_order', 'Order not found', ['order_id' => $order_id]);
        }

        // Get billing information from order
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $name = trim($first_name . ' ' . $last_name);
        $vat_number = $order->get_meta('_billing_eu_vat_number') ?: '999999990';

        // Check if client exists in the API
        $api_result = $api->check_client_exists($vat_number, $name);
        $logger->debug('API result for check_client_exists: ' . print_r($api_result, true), $context);

        $exists = false;
        $client_id = null;

        if (is_wp_error($api_result)) {
            $error_data = $api_result->get_error_data();
            $response_code = $error_data['response_code'] ?? 0;
            $error_message = $api_result->get_error_message();
            $logger->error("API error checking client existence: {$error_message}, Response Code: {$response_code}", $context);

            if ($response_code == 404) {
                $exists = false;
            } else {
                $body = json_decode($error_data['body'], true) ?? [];
                $error_message = $body['errors']['message'] ?? 'Unknown error';
                $error_code = $body['errors']['code'] ?? '';

                if ($error_code == 'CLC_CLIENT_NOT_FOUND') {
                    $exists = false;
                } else {
                    // Return error if not a "not found" case
                    return new WP_Error('api_error', $error_message, ['error_code' => $error_code]);
                }
            }
        } else {
            $response = $api_result['data'];

            if (is_array($response) && !empty($response) && isset($response[0]['id'])) {
                $client_id = $response[0]['id']; 
                $exists = true;
            } elseif (isset($response['id'])) {
                $client_id = $response['id'];
                $exists = true;
                $logger->info("Client found. ID: {$client_id}", $context);
            } else {
                $exists = false;
            }

            if ($exists) {
                return $client_id;
            }
        }

        $client_data = [
            'name'       => $name,
            'vatNumber'  => $vat_number,
            'country'    => $order->get_billing_country(),
            'address'    => $order->get_billing_address_1(),
            'zipCode'    => $order->get_billing_postcode(),
            'region'     => $order->get_billing_state(),
            'city'       => $order->get_billing_city(),
            'local'      => $order->get_billing_state(),
            'email'      => $order->get_billing_email(),
            'telephone'  => $order->get_billing_phone(),
        ];

        $logger->info("Creating new client with data: " . print_r($client_data, true), $context);

        // Create client via API
        $api_result = $api->create_client($client_data);
        if (is_wp_error($api_result)) {
            $error_message = $api_result->get_error_message();
            $error_code = $api_result->get_error_data('error_code') ?? '';
            wp_send_json_error(['message' => $error_message, 'error_code' => $error_code]);
        }

        $response = $api_result['data'];
        $client_id = $response['id'] ?? null;

        if ($client_id) {
            $logger->info("Client created successfully. ID: {$client_id}", $context);
            return json_encode($client_id);
        } else {
            wp_send_json_error(['message' => 'Client creation failed: No ID returned', 'error_code' => 'no_id']);
        }
    }
}