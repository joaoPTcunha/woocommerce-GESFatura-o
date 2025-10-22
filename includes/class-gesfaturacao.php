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

function gesfaturacao_create_invoice_and_payment_map_table() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_name_invoices = $wpdb->prefix . 'gesfaturacao_invoices';
    $sql_invoices = "CREATE TABLE $table_name_invoices (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT(20) UNSIGNED NOT NULL,
        invoice_id VARCHAR(100) NOT NULL,
        invoice_number VARCHAR(100) NOT NULL,
        sent_email TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id)
    ) $charset_collate;";

    $table_name_payment_map = $wpdb->prefix . 'gesfaturacao_payment_map';
    $sql_payment_map = "CREATE TABLE $table_name_payment_map (
        id_map int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        id_shop int(11) UNSIGNED NOT NULL,
        module_name varchar(64) NOT NULL,
        ges_payment_id varchar(64) DEFAULT NULL,
        ges_bank_id varchar(64) DEFAULT NULL,
        PRIMARY KEY (id_map),
        UNIQUE KEY id_shop_module (id_shop, module_name)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql_invoices );
    dbDelta( $sql_payment_map );

    // Get distinct payment_method_title from wp_wc_orders
    $payment_methods = $wpdb->get_col( "
        SELECT DISTINCT payment_method_title
        FROM {$wpdb->prefix}wc_orders
        WHERE payment_method_title IS NOT NULL
        AND payment_method_title <> ''
    " );

    foreach ( $payment_methods as $payment_method ) {
        $exists = $wpdb->get_var( $wpdb->prepare( 
            "SELECT id_map FROM $table_name_payment_map WHERE id_shop = %d AND module_name = %s", 
            1, 
            $payment_method 
        ) );

        if ( ! $exists ) {
            $wpdb->insert(
                $table_name_payment_map,
                array(
                    'id_shop' => 1,
                    'module_name' => $payment_method,
                    'ges_payment_id' => null,
                    'ges_bank_id' => null,
                ),
                array( '%d', '%s', '%s', '%s' )
            );
        }
    }
}
