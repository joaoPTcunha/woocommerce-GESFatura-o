<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GEFaturacao_Orders_Table
{

	public function __construct() {
		add_action( 'wp_ajax_gesfaturacao_get_orders', [ $this, 'gesfaturacao_get_orders' ] );
	}


	function gesfaturacao_get_orders() {
		global $wpdb;

		$status_filter = $_POST['status_filter'] ?? '';
		$invoiced_filter = $_POST['invoiced_filter'] ?? '';

		$orders = wc_get_orders([
			'limit' => -1,
			/*'orderby' => 'ID',
			'order' => 'DESC',*/
			'status' => $status_filter ? [$status_filter] : array_keys(wc_get_order_statuses())
		]);


		$table_name = $wpdb->prefix . 'gesfaturacao_invoices';
		$data = [];


		foreach ($orders as $order) {
			$order_id = $order->get_id();
			$order_status = $order->get_status();

			$invoice = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM $table_name WHERE order_id = %d", $order_id
			));

			// Filtro: Sim/Não/Indiferente
			if ($invoiced_filter === 'yes' && empty($invoice)) continue;
			if ($invoiced_filter === 'no' && !empty($invoice)) continue;

			// Only generate actions if status is 'processing' or 'completed'
			if (in_array($order_status, ['processing', 'completed'])) {
				$actions = $this->get_actions($invoice, $order_id);
			} else {
				$actions = ''; // No actions shown for other statuses
			}

			$data[] = [
				'order_number' => '<a href="' . esc_url(get_edit_post_link($order_id)) . '" target="_blank">#' . $order_id . '</a>',
				'customer' => $order->get_formatted_billing_full_name(),
				'date' => $order->get_date_created()->format('d/m/Y H:i'),
				'total' => wc_price($order->get_total()),
				'status' => wc_get_order_status_name($order->get_status()),
				'invoice_number' => esc_html($invoice->invoice_number ?? ''),
				'actions' => $actions,

			];
		}

		wp_send_json(['data' => $data]);
	}

	function get_actions($invoice, $order_id) {

		$options = get_option('gesfaturacao_options');
		$send_email = (isset($options['email']) && $options['email'] == true) ? 1 : 0;

		$buttons = '';

		if ($invoice && $invoice->invoice_id) {
			$invoice_id = esc_attr($invoice->invoice_id);

			$buttons .= '<button class="button button-small invoice-download button-ges button-pdf" data-action="download" data-invoice-id="' . $invoice_id . '" data-order-id="' . esc_attr($order_id) . '" title="Download Fatura" style="line-height: 0">
			<span class="dashicons dashicons-pdf"></span>
		</button>';


			if ( $invoice->email_sent == 1 ) {
				$class = 'button-email-sent';
			}else {
				$class = 'button-email';
			}

			$buttons .= '<button class="button button-small invoice-send-email button-ges '. $class.'" data-action="send_email" data-invoice-id="' . esc_attr($invoice_id) . '" data-order-id="' . esc_attr($order_id) . '" title="Enviar por Email" >
			    <span class="dashicons dashicons-email-alt"></span>
			</button>';
		} else {
			$buttons .= '<button class="button button-small invoice-create button-ges" data-action="create" data-order-id="' . esc_attr($order_id) . '" data-send_email="' . esc_attr($send_email) . '"   title="Criar Fatura" style="line-height: 0">
			<span class="dashicons dashicons-media-document"></span>
		</button>';
		}

		return $buttons;
	}
}
