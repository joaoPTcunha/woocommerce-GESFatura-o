<?php


if (!defined('ABSPATH')) exit;

class GESFaturacao_client_helper
{
	function gesfaturacao_sync_client_with_api($client_id, $order_id) {
		global $wpdb;
		$api = new GESFaturacao_API();

		$order = wc_get_order($order_id);
		//get order client meta data
		//$user_meta = get_user_meta($user_id);
		//$first_name = get_user_meta($client_id, 'billing_first_name', true) ?? "";
		//$last_name  = get_user_meta($client_id, 'billing_last_name', true) ?? "";
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();

		$name = trim($first_name . ' ' . $last_name);
		error_log('GESF: Checking client exists for VAT: ' . $vat_number . ', Name: ' . $name);
		//$user_meta = get_user_meta($client_id);
		//echo '<pre>'; print_r($user_meta); echo '</pre>';

		// Common meta keys used by VAT plugins:
		$vat_number = $order->get_meta('_billing_eu_vat_number') ?? '999999990';



		$api_result = $api->check_client_exists($vat_number, $name);
		error_log('GESF: API result for check_client_exists: ' . print_r($api_result, true));

		$exists = false;

		if ( is_wp_error( $api_result ) ) {
			$error_data = $api_result->get_error_data();
			$response_code = $error_data['response_code'] ?? 0;
			if($response_code == 404){
				$exists=false;
			} else{
				$body = json_decode($error_data['body'], true); // decode JSON string to array
				$error_message = isset($body['errors']['message']) ? $body['errors']['message'] : '';
				$error_code = isset($body['errors']['code']) ? $body['errors']['code'] : '';
				if ($error_code == 'CLC_CLIENT_NOT_FOUND') $exists=false;
			}
		} else{
			$response = $api_result['data'];

			// Handle if response is an array (multiple clients) or object (single client)
			if (is_array($response) && !empty($response) && isset($response[0]['id'])) {
				$id = $response[0]['id']; // Take the first matching client
				$exists = true;
			} elseif (isset($response['id'])) {
				$id = $response['id'];
				$exists = true;
			} else {
				$exists = false;
			}

			if ($exists) {
				return $id;
			}
		}

		// If not found, prepare the data to create the client
		/*$client = new WC_Customer($client_id);

		$client_data = [
			'name'       => "$first_name $last_name",
			'vatNumber'  => $vat_number,
			'country'  => $client->get_billing_country(),
			'email'      => $client->get_billing_email(),
			'telefone'   => $client->get_billing_phone(),
			'address'     => $client->get_billing_address_1(),
			'city' => $client->get_billing_city(),
			'region' => $client->get_billing_state(),
			'postalCode' => $client->get_billing_postcode(),
			'local' => $client->get_billing_state(),
		];*/

		$client_data = [
			'name'       => "$first_name $last_name",
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
		$api_result = $api->create_client($client_data);
		if (is_wp_error($api_result)) {
			error_log( 'Client creation error: ' . $api_result->get_error_message());
			wp_send_json_error(['message' =>$api_result->get_error_message(), 'error_code' => $api_result['error_code'] ?? '']);
		}

		$response = $api_result['data'];

		return json_encode($response['id']);
	}

}