<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GesFaturacao_Discount_Helper {
    private $logger;
    private $context;

    public function __construct() {
        $this->logger = wc_get_logger();
        $this->context = ['source' => 'GesFaturacao_Discount_Helper'];
    }

    private function log($message) {
        $this->logger->info($message, $this->context);
    }

    /**
     * Processa descontos do pedido:
     * - Descontos por produto → aplicados nas LINHAS (campo 'discount')
     * - Desconto geral → retornado para o payload da fatura
     *
     * @param WC_Order $order
     * @param array &$lines
     * @return float Desconto geral em percentagem (0 se não houver)
     */
    public function process_order_discounts( $order, &$lines ) {
        $this->logger->info(wp_json_encode(['message' => '========= Descontos =========']), $this->context);

        $general_discount_percentage = 0.0; // General discount is incorporated into product discounts

        // 1. Aplica descontos por produto nas linhas (includes general discounts proportionally)
        $has_product_discounts = $this->apply_product_discounts_to_lines( $order, $lines );

        // 2. Log descontos aplicados
        $this->logger->info(
            wp_json_encode([
                'message' => 'Descontos aplicados.',
                'detalhes' => [
                    'percentual_desconto_geral' => $general_discount_percentage,
                    'tinha_descontos_por_produto' => $has_product_discounts,
                ],
            ]),
            $this->context
        );

        return $general_discount_percentage;
    }

    /**
     * Aplica descontos por produto nas linhas da fatura
     */
    private function apply_product_discounts_to_lines( $order, &$lines ) {
        $has_any = false;

        foreach ( $order->get_items() as $item ) {
            $subtotal = (float) $item->get_subtotal();
            $total    = (float) $item->get_total();
            $discount_amount = $subtotal - $total;

            if ( $discount_amount <= 0 ) {
                continue;
            }

            $has_any = true;
            $percentage = $subtotal > 0 ? round( ( $discount_amount / $subtotal ) * 100, 4) : 0;

            foreach ( $lines as &$line ) {
                if ( $line['type'] === 'P' && strpos( $line['description'], $item->get_name() ) !== false ) {
                    $line['discount'] = $percentage;
                    $this->log( "Desconto por produto: {$item->get_name()} → {$percentage}%" );
                    break;
                }
            }
        }

        return $has_any;
    }

    /**
     * Calcula % de desconto com base em preço normal vs preço atual (para uso fora do pedido)
     */
    public function calculate_product_discount_percentage( $regular_price, $current_price ) {
        $regular = (float) $regular_price;
        $current = (float) $current_price;

        if ( $regular <= 0 || $current >= $regular ) {
            return 0.0;
        }

        $discount = $regular - $current;
        return round( ( $discount / $regular ) * 100, 4 );
    }

    /**
     * Aplica desconto a um item específico e retorna a percentagem de desconto
     */
    public function apply_product_discount( $item ) {
        $subtotal = (float) $item->get_subtotal();
        $total    = (float) $item->get_total();
        $discount_amount = $subtotal - $total;

        if ( $discount_amount <= 0 ) {
            return 0.0;
        }

        $percentage = $subtotal > 0 ? round( ( $discount_amount / $subtotal ) * 100, 4 ) : 0;
        $this->log( "Desconto aplicado ao produto: {$item->get_name()} → {$percentage}%" );

        return $percentage;
    }


}
