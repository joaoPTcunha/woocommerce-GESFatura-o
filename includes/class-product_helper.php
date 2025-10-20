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

		$code = "wc-$product_id";

		$api_result = $api->check_product_exists($code);

		$exists = false;
		if (is_wp_error($api_result)) {
			$error_data = $api_result->get_error_data();
			$body = json_decode($error_data['body'], true); // decode JSON string to array
			die(json_encode($body));
			$error_message = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : '';
			$error_code = isset($body['errors'][0]['code']) ? $body['errors'][0]['code'] : '';

			if ($error_code == '"PV_CODE_11"') $exists=false;
		}else{
			$response = $api_result['data'];

			// Check if the client already exists (you may need to adapt this)

			$id = $response['id'] ?? null;

			$exists = ($id > 0);
		}

		if ($exists) {
			return $id;
		} else {
			return false;
		}
	}


	public function create_product($product_id, $tax_id) {
		global $wpdb;
		$api = new GESFaturacao_API();


		//Get the order with the order ID
		$product = wc_get_product($product_id);

		//API payload
		$product_data = [
			'code' => "wc-$product_id",
			'name' => $product->get_name(),
			'type' => 'P',
			'unit' => '1',//1 is UN
			'pvp' => $product->get_price(), //1 is EUR
			'tax' => $tax_id, //1 is 23% IVA
		];

		$api_result = $api->create_product($product_data);

		if (is_wp_error($api_result)) {
			error_log( 'Client creation error: ' . $api_result->get_error_message());
		}

		$response = $api_result['data'];

		return $response['id'];

	}
}
