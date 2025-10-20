<?php

function gesfaturacao_setup_page() {
	global $wpdb;

	$logoUrl = plugin_dir_url(__FILE__) . '../assets/gesfaturacao-light.png'; // Change this to your actual logo path

	if (isset($_POST['submitLoginForm'])) {
		$domain = sanitize_text_field($_POST['ges_dom_licenca'] ?? '');
		$token = sanitize_text_field($_POST['ges_token'] ?? '');
		$api_version = sanitize_text_field($_POST['api_version'] ?? '');

		// Calls the API to validate the Domain
		$api_url_domain = "https://licencas.gesfaturacao.pt/server/auto/global_api.php";

		// Prepare request payload
		$body = [
			'subdomain' => $domain,
			'opcao' => 2
		];

		// Make the API request
		$response = wp_remote_post($api_url_domain, [
			'headers' => [],
			'body' => json_encode($body),
			'timeout' => 10,
		]);

		// Default API result value
		$api_result = null;

		if (is_wp_error($response)) {
			echo '<div class="notice notice-error"><p>Erro na comunicação com a API: ' . esc_html($response->get_error_message()) . '</p></div>';
		}else{
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($code !== 200) {
				echo '<div class="notice notice-error"><p><strong>Erro:</strong> A validação do token falhou. Código HTTP: ' . esc_html($code) . '</p></div>';
			} else {

			}
		}

		// Calls the API to validate the token
		$api_url_login = "https://$domain.gesfaturacao.pt/api/$api_version/validate-token";

		// Make the API request
		$response = wp_remote_post($api_url_login, [
			'headers' => [
				'Authorization' => $token,
			],
			'timeout' => 10,
		]);

		// Default API result value
		$api_result = null;

		if (is_wp_error($response)) {
			echo '<div class="notice notice-error"><p>Erro na comunicação com a API: ' . esc_html($response->get_error_message()) . '</p></div>';
		} else {
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($code === 200) {
				$decoded = json_decode($body, true);
				$api_result = $decoded['_token'] ?? ''; // customize based on API response

				$series = gesfaturacao_get_series($domain, $api_version,  $api_result);


					foreach ($series as $item) {
						if (isset($item['isPredefined']) && $item['isPredefined'] == "1") {
							$predefined_series = [
								'id' => $item['id'],
								'name' => $item['name']
							];
							break; // Stop after the first predefined one
						}
					}



				// Save to gesfaturacao_settings table
				$table_name = $wpdb->prefix . 'gesfaturacao_settings';
				// check if there is already a record in the table
				$exists = $wpdb->get_var("SELECT fauracao_id FROM $table_name LIMIT 1");
				if ($exists) {
					$wpdb->update(
						$table_name,
						[
							'faturacao_link' => $domain,
							'faturacao_token' => $api_result,
							'faturacao_api_version' => $api_version,
							'faturacao_serie_id' => $predefined_series['id'],
							'faturacao_serie_name' => $predefined_series['name'],
						],
						['fauracao_id' => $exists]
					);
				} else {
					$wpdb->insert(
						$table_name,
						[
							'faturacao_link' => $domain,
							'faturacao_token' => $api_result,
							'faturacao_api_version' => $api_version,
							'faturacao_serie_id' => $predefined_series['id'],
							'faturacao_serie_name' => $predefined_series['name'],
						]
					);
				}

				update_option('gesfaturacao_setup_done', true);

				wp_safe_redirect(admin_url('admin.php?page=gesfaturacao-main&gesfaturacao_success=1'));
				exit;
			} else {
				$api_result='ERROR';
			}
		}
	}

	echo '
<div style="display: flex; justify-content: center; margin-top: 100px;">
	<form id="loginForm" method="POST" action="" style="width: 50%; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
		<div style="text-align: center;">
			<img src="' . esc_url($logoUrl) . '" alt="Logo" style="width: 225px; margin-bottom: 20px;">
		</div>
		<label for="ges_dom_licenca">Domínio da Licença:</label>
		<div class="input-container">
			<input type="text" name="ges_dom_licenca" id="ges_dom_licenca" required autocomplete="off" style="width: 100%; padding: 5px; margin-bottom: 10px;">
			<div class="input-addon">
				<span class="input-addon-text">.gesfaturacao.pt</span>
			</div>
		</div>
		<div>
			<label for="ges_token">Token:</label>
			<input type="text" name="ges_token" id="ges_token" required autocomplete="off" style="width: 100%; padding: 5px; margin-bottom: 10px;">

			<label for="ges_token">Versão API:</label>
			<select name="api_version" id="api_version">
			<option value="v1.0.4" selected>v1.0.4</option>
			<option value="v1.0.4">v1.0.3</option>
			<option value="v1.0.4">v1.0.2</option>
</select>
			<br><br>
		</div>
		<div style="text-align: center;">
			<button class="btn custom-button" type="submit" name="submitLoginForm" style="width: 35%; padding: 10px;">Gravar</button>
		</div>
	</form>
</div>

<style>
    .custom-button {
        background-color: #000000;
        color: #ffffff;
        transition: background-color 0.3s;
        cursor: pointer;
        border: none;
        border-radius: 5px;
    }

    .custom-button:hover {
        background-color: #f2994b;
    }

    .input-container {
        position: relative;
        display: inline-block;
        width: 100%;
    }

    .input-addon {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        padding-right: 2%;
        display: flex;
        align-items: center;
        pointer-events: none;
        color: #f2994b;
    }

    .input-addon-text {
        margin-left: 5px;
    }
</style>
';
}


