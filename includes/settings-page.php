<?php
function gesfaturacao_settings_page() {
	global $wpdb;

	$logoUrl = plugin_dir_url(__FILE__) . '../assets/gesfaturacao-light.png'; // logo path

	$gesfaturacao_settings_table = $wpdb->prefix . 'gesfaturacao_settings';

	if (isset($_POST['submitLoginForm'])) {
		$domain = sanitize_text_field($_POST['ges_dom_licenca'] ?? '');
		$token = sanitize_text_field($_POST['ges_token'] ?? '');
		$api_version = sanitize_text_field($_POST['api_version'] ?? '');
		$serie_id = sanitize_text_field($_POST['ges_serie'] ?? '');
		$serie_name = sanitize_text_field($_POST['ges_serie_name'] ?? '');
		$finalize = isset($_POST['ges_finalize']) ? 1 : 0;
		$email    = isset($_POST['ges_email']) ? 1 : 0;


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



				// Save to gesfaturacao_settings table
				// check if there is already a record in the table
				$exists = $wpdb->get_var("SELECT faturacao_id FROM $gesfaturacao_settings_table LIMIT 1");
				if ($exists) {
					$wpdb->update(
						$gesfaturacao_settings_table,
						[
							'faturacao_link' => $domain,
							'faturacao_token' => $api_result,
							'faturacao_api_version' => $api_version,
							'faturacao_serie_id' => $serie_id,
							'faturacao_serie_name' => $serie_name,
							'faturacao_finalize' => $finalize,
							'faturacao_email' => $email,
							'updated_at'     => current_time('mysql', 1), // UTC time
						],
						['faturacao_id' => $exists]
					);
				} else {
					$wpdb->insert(
						$gesfaturacao_settings_table,
						[
							'faturacao_link' => $domain,
							'faturacao_token' => $api_result,
							'faturacao_api_version' => $api_version,
							'faturacao_serie_id' => $serie_id,
							'faturacao_serie_name' => $serie_name,
							'faturacao_finalize' => $finalize,
							'faturacao_email' => $email,
						]
					);
				}

				update_option('gesfaturacao_setup_done', true);

				wp_safe_redirect(admin_url('admin.php?page=gesfaturacao-main&settings_success=1'));

				/*wp_safe_redirect(
					add_query_arg(
						array(
							'settings_success' => 1
						),
						remove_query_arg(
							array(
								'alg_wc_eu_vat_validate_user_profile',
								'_alg_wc_eu_vat_validate_user_profile_nonce',
							)
						)
					)
				);*/
				exit;
			} else {
				$api_result='ERROR';
			}
		}
	}

	//get the options from the database to populate the inputs
	$settings = $wpdb->get_row("SELECT * FROM $gesfaturacao_settings_table LIMIT 1");

	$domain      = $settings ? esc_attr($settings->faturacao_link)       : '';
	$token       = $settings ? esc_attr($settings->faturacao_token)      : '';
	$api_version = $settings ? esc_attr($settings->faturacao_api_version): '';
	$serie_id    = $settings ? esc_attr($settings->faturacao_serie_id)   : '';
	$finalize    = $settings ? (int) $settings->faturacao_finalize       : 0;
	$email       = $settings ? (int) $settings->faturacao_email          : 0;

	//build the series select
	$series_options = '';
	$series = gesfaturacao_get_series();
	if (is_wp_error($series)) {
		$series_options = '<option disabled>' . esc_html($series->get_error_message()) . '</option>';
	} else {
		foreach ($series as $serie) {
			$id = esc_attr($serie['id']);
			$name = esc_html($serie['name']);
			$predefined = !empty($serie['isPredefined']); // true if 1 or truthy

			$selected = '';
			if (!empty($serie_id)) {
				$selected = ($serie_id == $id) ? ' selected' : '';
			} elseif ($predefined) {
				$selected = ' selected';
			}


			$series_options .= "<option value=\"$id\" data-name=\"$name\" $selected>$name</option>";
		}
	}

	//Page messages
	if (isset($_GET['settings_success']) && $_GET['settings_success'] == '1') {
		add_settings_error(
			'gesfaturacao_messages',
			'success_message',
			'Dados alterados',
			'success'
		);
	}


	echo '
<div style="display: flex; justify-content: center; margin-top: 100px;">
	<form id="loginForm" method="POST" action="" style="width: 50%; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
		<div style="text-align: center;">
			<img src="' . esc_url($logoUrl) . '" alt="Logo" style="width: 225px; margin-bottom: 20px;">
		</div>
		<label for="ges_dom_licenca">Domínio da Licença:</label>
		<div class="input-container">
			<input type="text" name="ges_dom_licenca" id="ges_dom_licenca" required autocomplete="off" style="width: 100%; padding: 5px; margin-bottom: 10px;" value=" ' . $domain . '">
			<div class="input-addon">
				<span class="input-addon-text">.gesfaturacao.pt</span>
			</div>
		</div>
		<div class="input-container">
			<label for="ges_token">Token:</label>
			<input type="text" name="ges_token" id="ges_token" required autocomplete="off" style="width: 100%; padding: 5px; margin-bottom: 10px;"  value=" ' . $token . '">
			
			<label for="ges_token">Versão API:</label>
			<select name="api_version" id="api_version">
			<option value="v1.0.4" selected>v1.0.4</option>
			<option value="v1.0.4">v1.0.3</option>
			<option value="v1.0.4">v1.0.2</option>
			</select>
			
			<label for="ges_serie">Série:</label>
			<select name="ges_serie" id="ges_serie">
   			 ' . $series_options . '
			</select>
			<label for="ges_finalize">Finaliza Fatura:</label>
  			<input type="checkbox" name="ges_finalize" id="ges_finalize" ' . ($finalize ? 'checked' : '') . '>

  			 
  			<label for="ges_email">Envia por email:</label>
  			<input type="checkbox" name="ges_email" id="ges_email" ' . ($email ? 'checked' : '') . '>
			<br><br>
		</div>	
	
		<div style="text-align: center;">
			<button class="btn custom-button" type="submit" name="submitLoginForm" style="width: 35%; padding: 10px;">Gravar</button>
		</div>
	</form>
</div>

<script>
document.querySelector("#loginForm").addEventListener("submit", function(e) {
    const selectedOption = document.querySelector("#ges_serie option:checked");
    const serieName = selectedOption.getAttribute("data-name");

    // Optional: Add it to a hidden input before submit
    let input = document.createElement("input");
    input.type = "hidden";
    input.name = "ges_serie_name";
    input.value = serieName;
    this.appendChild(input);
});

</script>

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

function gesfaturacao_get_series($domain = null, $api_version = null , $token = null) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'gesfaturacao_settings';

	// Get settings (assuming only one row exists)
	$settings = $wpdb->get_row("SELECT faturacao_link, faturacao_api_version, faturacao_token FROM $table_name LIMIT 1");

	if ($settings) {
		$domain = $settings->faturacao_link;
		$api_version = $settings->faturacao_api_version;
		$token = $settings->faturacao_token;
	}

	$api_url = "https://$domain.gesfaturacao.pt/api/$api_version/series";

	// Prepare headers
	$response = wp_remote_get($api_url, [
		'headers' => [
			'Authorization' => $token,
			'Accept'        => 'application/json',
		],
		'timeout' => 10,
	]);

	// Check for errors
	if (is_wp_error($response)) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code($response);
	$body = wp_remote_retrieve_body($response);

	if ($code !== 200) {
		return new WP_Error('api_error', "API returned code $code: $body");
	}

	// Decode response
	$data = json_decode($body, true);

	if (!is_array($data)) {
		return new WP_Error('invalid_json', 'API response was not valid JSON.');
	}

	// Return the series list
	return $data['data'];
}


