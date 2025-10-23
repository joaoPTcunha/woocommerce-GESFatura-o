<?php
$api = new GESFaturacao_API();
$response = $api->get_exemption_reasons();
$reasons = [];

if ( ! is_wp_error( $response ) ) {
	$reasons = $response['data'] ?? [];
}
?>

<div id="exemptionModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0;
    background:rgba(0,0,0,0.5); z-index:9999;">
    <div style="background:#fff; padding:20px; border-radius:5px; max-width:700px; margin:5% auto; overflow:auto;">

        <h3>Motivos de Isenção</h3>

        <form id="exemptionForm">
            <table style="width:100%">
                <thead>
                <tr><th style="width: 50%">Produto</th><th style="width: 50%">Motivo</th></tr>
                </thead>
                <tbody id="exemptionTableBody">
                <!-- JavaScript will insert rows here -->
                </tbody>
            </table>

            <div style="display:flex; justify-content:flex-end; gap: 10px; margin-top:10px;">
                <button type="submit" class="custom-button" style="padding:10px;">Confirmar</button>
                <button type="button" id="cancelExemptionModal" class="custom-button" style="padding:10px;">Cancelar</button>
            </div>
        </form>

        <script>
            window.exemptionReasons = <?= json_encode( $reasons ); ?>;
        </script>
    </div>
</div>
