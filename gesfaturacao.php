<?php
/**
 * Plugin Name: GESFaturação
 * Description: Converta as Encomendas em Faturas com um só clique
 * Version: 1.0
 * Author: FTKode
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-gesfaturacao.php';

new GESFaturacao();

register_activation_hook( __FILE__, 'gesfaturacao_create_invoice_table' );
