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


		//$user_meta = get_user_meta($client_id);
		//echo '<pre>'; print_r($user_meta); echo '</pre>';

		// Common meta keys used by VAT plugins:
		$vat_number = get_user_meta($client_id, 'billing_eu_vat_number', true);

		if ($first_name == '' && $last_name == '') {
			wp_send_json_error(['message' => 'Não é possível verificar o nome do cliente. Por favor verifique os dados da encomenda']);
		}

		$api_result = $api->check_client_exists($vat_number, "$first_name%20$last_name");

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
			//$response = json_decode(wp_remote_retrieve_body($api_result), true);
			$response = $api_result['data'];

			// Check if the client already exists (you may need to adapt this)

			$id = isset($response['id']) ? $response['id'] : null;

			$exists = ($id > 0);

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
			'name'       => "$first_name $last_name",//$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'vatNumber'  => $order->get_meta('_billing_eu_vat_number') ?? '999999990', // $vat_number // or your VAT meta key
			'country'    => $order->get_billing_country(),
			'email'      => $order->get_billing_email(),
			'telefone'   => $order->get_billing_phone(),
			'address'    => $order->get_billing_address_1(),
			'city'       => $order->get_billing_city(),
			'region'     => $order->get_billing_state(),
			'zipCode' => $order->get_billing_postcode(),
			'local'      => $order->get_billing_state(),
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
