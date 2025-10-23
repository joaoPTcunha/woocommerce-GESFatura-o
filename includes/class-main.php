<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GESFaturacao_Main {
	public function __construct() {
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('wp_ajax_generate_invoice', [$this, 'handle_generate_invoice']);
		add_action('wp_ajax_get_invoice_pdf', [$this, 'handle_get_invoice_pdf']);
		add_action('wp_ajax_email_invoice', [$this, 'handle_email_invoice']);


	}

	// class-main.php
	public function add_menu() {
		add_menu_page(
			'GESFaturação',                  // Page title
			'GESFaturação',                  // Menu title
			'manage_options',                // Capability
			'gesfaturacao-main',             // Menu slug
			[ $this, 'render_page' ],        // Main plugin page content
			'dashicons-gesfaturacao-icon',      // Icon
			56                               // Menu position
		);

		// Add settings page as submenu
		add_submenu_page(
			'gesfaturacao-main',
			'Configurações',
			'Configurações',
			'manage_options',
			'gesfaturacao-settings',
			[ new GESFaturacao_Admin(), 'render_admin_page' ]
		);
	}





	//Styles for the custom GESFaturação dashicon on the wordpress admin menu
	public function enqueue_admin_styles() {
		wp_register_style('gesfaturacao_dashicons', plugins_url('gesfaturacao-woocommerce/css/gesfaturacao.css'));
		wp_enqueue_style('gesfaturacao_dashicons');

	}


	public function handle_generate_invoice() {
		//check_ajax_referer('gesfaturacao_nonce');

		$order_id = intval($_POST['order_id']);
		$send_email = isset($_POST['send_email']) ? $_POST['send_email'] : '';
		$email = isset($_POST['email']) ? $_POST['email'] : '';
		$exemptions = isset($_POST['exemption_reasons']) ? $_POST['exemption_reasons'] : [];

		if (!$order_id) {
			wp_send_json_error('Missing order ID');
		}

		// Call helper class
		$invoice = new GesFaturacao_Invoice_Helper();
		$result = $invoice->create_invoice_from_order($order_id, $send_email, $email, $exemptions);

		//If send email when creating invoice is checked in settings send the call to the api
		$email_sent=false;
		if($send_email){
			$email_data = [
				'id' => $result['invoice_id'],
				'type' => 'FR',
				'email' => $email,
				'expired' => false
			];

			// Call helper class
			$api = new GesFaturacao_API();
			$result_email = $api->send_email($email_data);
			//$response = $result['data'];

			if (is_wp_error($result_email)) {
				$email_sent = false;
			} else {
				$email_sent = true;
			}
		}


		if ($result['success']) {
			//wp_send_json_success($result);
			wp_send_json_success([/*'message' => $result['message'],*/ 'invoice_number' => $result['invoice_number'] ?? '','email_sent' => $email_sent]);
		} else {
			wp_send_json_error(array_merge(['message' => $result['message'], 'error_code' => $result['error_code'] ?? ''], $result));
		}
	}




	public function handle_get_invoice_pdf() {
		//check_ajax_referer('gesfaturacao_nonce');

		$invoice_id = intval($_POST['invoice_id']);

		if (!$invoice_id) {
			wp_send_json_error('Missing invoice ID');
		}

		// Call helper class
		$api = new GesFaturacao_API();
		$result = $api->get_invoice_pdf($invoice_id);
		$response = $result['data'];

		if (empty($response['document'])) {
			wp_send_json_error(['message' => 'PDF vazio']);
		}

		$base64_pdf = $response['document'];
		$pdf_content = base64_decode($base64_pdf);

		// Get WordPress upload directory
		$upload_dir = wp_upload_dir();

		// Define your custom plugin folder (inside uploads)
		$custom_subdir = $upload_dir['basedir'] . '/gesfaturacao_uploads';
		$custom_url    = $upload_dir['baseurl'] . '/gesfaturacao_uploads';

		// Make sure the directory exists
		if (!file_exists($custom_subdir)) {
			wp_mkdir_p($custom_subdir);
		}


		// Build full file path
		$pdf_filename = 'document_' . $invoice_id . '.pdf';
		$pdf_path = $custom_subdir . '/' . $pdf_filename;
		$pdf_url = $custom_url . '/' . $pdf_filename;

		// Save the file
		$success = file_put_contents($pdf_path, $pdf_content);

		if ($success) {
			wp_send_json_success(['pdf_url' => $pdf_url]);
		} /*else {
			wp_send_json_error(['message' => $result['message'], 'error_code' => $result['error_code'] ?? '']);
		}*/
	}

	public function handle_email_invoice() {
		//check_ajax_referer('gesfaturacao_nonce');

		$invoice_id= intval($_POST['invoice_id']);
		$email = $_POST['email'];

		if (!$invoice_id) {
			wp_send_json_error('Missing order ID');
		} else if (!$email) {
			wp_send_json_error('Missing email');
		}

		$email_data = [
			'id' => $invoice_id,
			'type' => 'FR',
			'email' => $email,
			'expired' => false
		];

		// Call helper class
		$api = new GesFaturacao_API();
		$result = $api->send_email($email_data);

		if ( is_wp_error( $result ) ) {
			// Grab the error’s data array (here the key is 'api_error')
			$error_data = $result->get_error_data( 'api_error' );

			// Now extract the response_code
			$response_code = $error_data['response_code'] ?? null;

			wp_send_json_error([
				'message' => /*$data['errors']['message'] ?? */'Erro ao enviar email. Por favor, valide o email. Se o problema persistir, contacte o administrador',
			]);
		} else {

			global $wpdb;

			$table_name = $wpdb->prefix . 'gesfaturacao_invoices';


			$invoice_id = intval($invoice_id);


			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_name WHERE invoice_id = %d",
				$invoice_id
			) );

			if ( $exists ) {
				$wpdb->update(
					$table_name,
					[ 'email_sent' => 1 ],
					[ 'id' => intval($exists) ],
					[ '%d' ], // Data format for values
					[ '%d' ]  // Data format for where
				);
			}

			wp_send_json_success($result);
		}
	}


	public function enqueue_assets($hook) {
		if ($hook !== 'toplevel_page_gesfaturacao-main') {
			return;
		}

		// DataTables
		wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/2.3.1/css/dataTables.dataTables.min.css');
		wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/2.3.1/js/dataTables.min.js', ['jquery'], null, true);

		// Select2
		wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
		wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);

		//includes

		// Enqueue WP admin CSS so our custom CSS can build on it
		wp_enqueue_style( 'wp-admin' );

		// Enqueue a small custom CSS file:
		wp_enqueue_style(
			'gesfaturacao-admin-css',
			plugin_dir_url( __FILE__ ) . '../css/gesfaturacao-admin.css',
			[],
			GESFATURACAO_VERSION
		);


		wp_enqueue_script(
			'gesfaturacao-invoice-js',
			plugin_dir_url(__FILE__) . '../js/gesfaturacao-invoice.js',
			['jquery'],
			null,
			true
		);

		wp_localize_script('gesfaturacao-invoice-js', 'gesfaturacao_ajax', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('gesfaturacao_nonce')
		]);

		wp_add_inline_script('datatables-js', '
jQuery(document).ready(function($) {
    let ordersTable = $("#gesfaturacao-orders").DataTable({
        ajax: {
            url: ajaxurl,
            type: "POST",
            data: function (d) {
                d.action = "gesfaturacao_get_orders";
                d.status_filter = $("#filter-status").val();
                d.invoiced_filter = $("input[name=\'filter-invoiced\']:checked").val();
            }
        },
        language: {
            url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json"
        },
        columns: [
            { data: "order_number" },
            { data: "customer" },
            { data: "date" },
            { data: "total" },
            { data: "status" },
            { data: "invoice_number" },
            { data: "actions" }
        ],
        order: [[0, "desc"]]
    });

    $("#filter-status, input[name=\'filter-invoiced\']").on("change", function () {
        ordersTable.ajax.reload();
    });

     $(".select2").select2({
            placeholder: "Escolha uma opção...",
            width: "100%",
            allowClear: true
        });
    
});
');


		// Enqueue WP core admin notices styles and scripts
		wp_enqueue_script('common');
		wp_enqueue_script('wp-lists');
		wp_enqueue_script('postbox');
		wp_enqueue_style('wp-admin');

	}

	public function render_page() {

		$setup_done = (int) get_option( 'gesfaturacao_setup_done', 0 );
		if ( $setup_done === 0 ) {
			// Redirect to the “Configurações” (setup) submenu of this plugin
			wp_safe_redirect( admin_url( 'admin.php?page=gesfaturacao-settings' ) );
			exit;
		}

		$logoUrl = plugin_dir_url(__FILE__) . '../assets/gesfaturacao-light.png'; // logo path

		echo '<div class="wrap"><h1>Encomendas WOOCommerce</h1>';
		echo '<div class="gesfaturacao-main-wrapper">';
		echo '<div class="is-dismissible" id="gesfaturacao-notices"></div>';
		echo '<div class="gesfaturacao-logo-container">';
		echo '<img src="' . $logoUrl . '" alt="Logo GesFaturacao" class="gesfaturacao-logo" />';
		echo '</div>';
		echo '<div class="gesfaturacao-filters" style="margin-bottom: 20px; display: flex; gap: 40px; align-items: center;">';

		// Filter by status
		echo '<div>';
		echo '<label for="filter-status" style="margin-right: 10px;">Estado:</label>';
		echo '<select class="select2" id="filter-status">';
		echo '<option value="">Todos</option>';

		foreach (wc_get_order_statuses() as $key => $label) {
			$value = str_replace('wc-', '', $key); // Remove "wc-" prefix for easier filtering
			echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
		}

		echo '</select>';
		echo '</div>';

		// Filter by invoice status
		echo '<div>';
		echo '<label  style="margin-right: 10px;">Faturado:</label><br>';
		echo '<label style="margin-right: 10px;"><input type="radio" name="filter-invoiced" value="" class="icheck"> Indiferente</label>';
		echo '<label style="margin-right: 10px;"><input type="radio" name="filter-invoiced" value="no" class="icheck" checked> Não</label>';
		echo '<label><input type="radio" name="filter-invoiced" value="yes" class="icheck"> Sim</label>';
		echo '</div>';

		echo '</div>';

		echo '<table id="gesfaturacao-orders" class="display">';
		echo '<thead>
                <tr>
                <th style="width: 10%;">N.º Encomenda</th>
                <th style="width: 35%;">Cliente</th>
                <th>Data</th>
                <th>Valor</th>
                <th>Estado</th>
                <th>Nº Fatura</th>
                <th style="width: 15%;">Opções</th>
                </tr>
              </thead>
              <tbody>';
echo '
</tbody></table></div></div>';

//include modal email
include_once plugin_dir_path(__FILE__) . 'modals/modal_email.php';
include_once plugin_dir_path(__FILE__) . 'modals/modal_exemption.php';

echo '
<style>
.dt-input {
	min-width: 45px;
}

/*.notice.is-dismissible .notice-dismiss {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
}*/
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
    
    #emailModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5); /* semi-transparent black */
    z-index: 9999;
}

.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
	background-color: #f2994b;
	color: white;
}
</style>

';
	}
}

