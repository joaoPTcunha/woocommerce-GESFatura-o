<!--modal email-->
<!-- 1. Modal wrapper -->
<div id="emailModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0;
    background:rgba(0,0,0,0.5); z-index:9999;">
	<!-- 2. Inner box -->
	<div  style="background:#fff; padding:20px; border-radius:5px; max-width:400px; margin:0 auto;position: absolute;top: 300px;left: 0;right: 0;bottom: 0;max-height: 160px;">
		<h3>Enviar Fatura por Email</h3>
		<input type="email" id="email" placeholder="Introduza o email" style="width:100%; padding:8px; margin:10px 0;" />
		<div style="display:flex; justify-content:flex-end; gap: 10px; margin-top:10px;">
			<button class="custom-button" id="sendEmailConfirm" style="padding:10px;">Enviar</button>
			<button class="custom-button" id="cancelEmailModal" style="padding:10px;">Cancelar</button>
		</div>
	</div>
</div>

