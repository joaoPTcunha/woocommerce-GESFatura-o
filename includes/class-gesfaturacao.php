<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GESFaturacao {

	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	public function enqueue_admin_styles() {
		wp_register_style(
			'my_plugin_name_dashicons',
			plugins_url('css/my-plugin-name.css', dirname(__FILE__, 2) )
		);
		wp_enqueue_style('my_plugin_name_dashicons');
	}


	private function define_constants() {
		define( 'GESFATURACAO_VERSION', '1.0.0' );
		define( 'GESFATURACAO_PLUGIN_DIR', plugin_dir_path( __DIR__ ) );
		define( 'GESFATURACAO_PLUGIN_URL', plugin_dir_url( __DIR__ ) );
	}

	private function includes() {
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-main.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-admin.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-api.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-invoice_helper.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-product_helper.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-client_helper.php';
		require_once GESFATURACAO_PLUGIN_DIR . 'includes/class-orders_table.php';
	}

	public function init_hooks() {
		if ( is_admin() ) {
			new GESFaturacao_Main();  // Main datatable page
			//new GESFaturacao_Admin();// Settings page
			new GEFaturacao_Orders_Table(); // Datatable Ajax request
			if ( get_option('gesfaturacao_setup_done') === false ) {
				add_option('gesfaturacao_setup_done', 0);
			}
		}
	}


}

function gesfaturacao_create_invoice_table() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'gesfaturacao_invoices';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		invoice_id VARCHAR(100) NOT NULL,
		invoice_number VARCHAR(100) NOT NULL,
		sent_email TINYINT NOT NULL DEFAULT 0,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY order_id (order_id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}
