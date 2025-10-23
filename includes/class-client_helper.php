<?php

if (!defined('ABSPATH')) exit;

class GESFaturacao_client_helper {

	public function gesfaturacao_sync_client_with_api($client_id, $order_id) {
		global $wpdb;
		$api = new GESFaturacao_API();

		$logger  = wc_get_logger();
		$context = [ 'source' => 'GESFaturacao_Client' ];

		$logger->info(wp_json_encode([
			'message'   => '-------------------- Início da sincronização de cliente ---------------------------',
			'order_id'  => $order_id,
			'client_id' => $client_id
		]), $context);

		$order = wc_get_order($order_id);
		if (!$order) {
			$logger->error(wp_json_encode([
				'message'   => 'Encomenda não encontrada',
				'order_id'  => $order_id
			]), $context);
			return;
		}

		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		$name       = trim($first_name . ' ' . $last_name);
		$vat_number = trim($order->get_meta('_billing_eu_vat_number') ?: '999999990');

		$logger->info(wp_json_encode([
			'message' => 'Verificar se o cliente já existe',
			'name'    => $name,
			'vat'     => $vat_number
		]), $context);

		$encoded_name = rawurlencode($name);
		$api_result = $api->check_client_exists($vat_number, $name);

		$logger->info(wp_json_encode([
			'message' => 'Resposta check_client_exists',
			'endpoint' => "clients/tin/search/{$vat_number}/{$encoded_name}",
			'result' => $api_result
		]), $context);

		$exists = false;
		$id     = null;

		if (!is_wp_error($api_result) && isset($api_result['data'])) {
			$data = $api_result['data'];

			if (isset($data['id'])) {
				$id = $data['id'];
				$exists = true;
			} elseif (is_array($data) && isset($data[0]['id'])) {
				$id = $data[0]['id'];
				$exists = true;
			}
		}

		if ($exists && !empty($id)) {
			$logger->info(wp_json_encode([
				'message'   => 'Cliente já existe — não será criado novamente',
				'client_id' => $id
			]), $context);

			return $id;
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

		$logger->info(wp_json_encode([
			'message'     => 'Cliente não encontrado — criando novo',
			'client_data' => $client_data
		]), $context);

		// Cria o cliente
		$api_result = $api->create_client($client_data);

		if (is_wp_error($api_result)) {
			$logger->error(wp_json_encode([
				'message' => 'Erro ao criar cliente',
				'error'   => $api_result->get_error_message(),
			]), $context);

			wp_send_json_error([
				'message' => $api_result->get_error_message(),
			]);
			return;
		}

		$response = $api_result['data'] ?? [];
		$logger->info(wp_json_encode([
			'message'      => 'Cliente criado com sucesso',
			'api_response' => $response
		]), $context);

		$logger->info(wp_json_encode([
			'message' => '--- Fim da sincronização de cliente ---'
		]), $context);

		return $response['id'] ?? null;
	}
}
