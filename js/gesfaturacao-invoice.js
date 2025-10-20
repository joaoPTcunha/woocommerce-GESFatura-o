console.log('gesfaturacao-invoice.js loaded');
jQuery(document).ready(function($){
    let modal_email = $('#emailModal');
    let modal_exemption = $('#exemptionModal');

    $('#gesfaturacao-orders').on('click', '.invoice-create', function() {
            //GENERATE INVOICE
            let button = $(this);
            let modal = $('#emailModal');
            let orderId = button.data('order-id');
            let invoiceId = button.data('invoice-id') || null;
            debugger
            let send_email = button.data('send_email') || null;


            if(send_email === 1){
                // Show modal
                openEmailModal(modal);

                // Set up listener for send
                $('#sendEmailConfirm').off('click').on('click', function() {
                    let email = $('#email').val().trim();

                    if (!email) {
                        alert('Por favor, insira um email.');
                        return;
                    }

                    setTimeout(function(){
                        closeEmailModal(modal);
                    }, 500);

                    $.ajax({
                        url: gesfaturacao_ajax.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'generate_invoice',
                            order_id: orderId,
                            invoice_id: invoiceId,
                            send_email: send_email,
                            email: email,
                            security: gesfaturacao_ajax.nonce
                        },
                        success: function(response){
                            if (response.success) {
                                if(response.data.email_sent){
                                    wpAdminNotice('Fatura criada, email enviado', 'success');
                                } else{
                                    wpAdminNotice('Fatura criada, email não enviado', 'success');
                                }
                                closeEmailModal(modal);
                                $('#gesfaturacao-orders').DataTable().ajax.reload(null, false); // false = keep pagination
                            } else {
                                wpAdminNotice('Ocorreu um erro ao criar a fatura: ' + (response.data.message || 'Erro desconhecido, por favor contacte o Administrador'), 'error');
                            }
                        }
                    });
                });
            } else {
                $.ajax({
                    url: gesfaturacao_ajax.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'generate_invoice',
                        order_id: orderId,
                        invoice_id: invoiceId,
                        security: gesfaturacao_ajax.nonce
                    },
                    success: function(response){
                        // If response is a string, try to parse it
                        if (typeof response === 'string') {
                            try {
                                response = JSON.parse(response);
                            } catch (e) {
                                console.error('Invalid JSON:', response);
                                // handle error here, or return
                                return;
                            }
                        }

                        if (response.success) {
                            wpAdminNotice('Fatura criada', 'success');
                            $('#gesfaturacao-orders').DataTable().ajax.reload(null, false); // false = keep pagination
                        } else {
                            if (response.error_code === 'missing_exemption_reasons') {
                                showExemptionModal(response.order_id,response.missing_data, response.exemption_data.data);
                            } else{
                                //show error
                                wpAdminNotice('Ocorreu um erro ao criar a fatura: ' + (response.data.message || 'Erro desconhecido, por favor contacte o Administrador'), 'error');
                            }

                        }
                    }
                });
            }
    });

    $('#gesfaturacao-orders').on('click', '.invoice-download', function() {

    //DOWNLOAD PDF
   // let invoiceId = $(this).find('option:selected').data('invoice-id');
        let invoiceId = $(this).data('invoice-id');
    $.ajax({
        url: gesfaturacao_ajax.ajax_url,
        method: 'POST',
        data: {
            action: 'get_invoice_pdf',
            invoice_id: invoiceId,
            security: gesfaturacao_ajax.nonce
        },

        success: function(response) {
            if (response.success) {
                window.open(response.data.pdf_url, '_blank');
            } else {
                alert('Erro: ' + response.data.message);
            }
        },
        error: function() {
            alert('Erro ao contactar o servidor.');
        }
    });

    });

    $('#gesfaturacao-orders').on('click', '.invoice-send-email', function() {
        let invoiceId = $(this).data('invoice-id');

    // Show modal
    openEmailModal(modal_email);

    // Set up listener for send
    $('#sendEmailConfirm').off('click').on('click', function() {
        let email = $('#email').val().trim();

        if (!email) {
            alert('Por favor, insira um email.');
            return;
        }

        setTimeout(function(){
            closeEmailModal(modal_email);
        }, 500);

        // Send AJAX request with custom email
        $.ajax({
            url: gesfaturacao_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'email_invoice',
                invoice_id: invoiceId,
                email: email,
                security: gesfaturacao_ajax.nonce
            },
            success: function(response) {
                closeEmailModal(modal_email);
                if (response.success) {
                    wpAdminNotice('Email enviado', 'success');
                } else {
                    wpAdminNotice(response.data.message, 'error');
                }
                $('#gesfaturacao-orders').DataTable().ajax.reload(null, false); // false = keep pagination
            },
            error: function() {
                closeEmailModal(modal_email);
                alert('Erro ao contactar o servidor.');
            }
        });
    });
    });

    // Close if clicking outside inner box
    modal_email.on('click', function(e) {
        if ($(e.target).is(modal_email)) {
            closeEmailModal(modal_email);
        }
    });
    modal_exemption.on('click', function(e) {
        if ($(e.target).is(modal_exemption)) {
            closeEmailModal(modal_exemption);
        }
    });

    // “Cancelar” button
    $('#cancelEmailModal').on('click', function(e) {
        e.preventDefault();
        closeEmailModal(modal_email);

    });
    $('#cancelExemptionModal').on('click', function(e) {
        e.preventDefault();
        closeEmailModal(modal_exemption);

    });

    $('#exemptionModal').on('submit', '#exemptionForm', function (e) {
        e.preventDefault();
        let exemptions = {};
        let order_id= 0;
        $(this).find('select[name^="exemption_reason"]').each(function () {
            console.log('Raw name:', $(this).attr('name'));
            const match = $(this).attr('name').match(/\[(.*?)\]/);
             order_id = $(this).data('order_id');
            if (match && match[1]) {
                const key = match[1];
                const value = $(this).val();
                exemptions[key] = value;
            }
        });

        $.ajax({
            url: gesfaturacao_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'create_invoice_from_order_with_exemptions',
                order_id: order_id,
                exemption_reasons: exemptions,
                security: gesfaturacao_ajax.nonce
            },
            success: function (response) {
                $('#exemptionModal').fadeOut();
                if (response.success) {
                    wpAdminNotice('Fatura criada', 'success');
                    $('#gesfaturacao-orders').DataTable().ajax.reload(null, false); // false = keep pagination
                } else {
                 //show error
                 wpAdminNotice('Ocorreu um erro ao criar a fatura: ' + (response.data.message || 'Erro desconhecido, por favor contacte o Administrador'), 'error');
                }
            }
        });
    });

    function showExemptionModal(order_id, missingItems, exemptionReasons) {
        let html = '';
        missingItems.forEach(item => {
            html += `<tr>
            <td style="width:50%">${item.product_name}</td>
            <td style="width:50%">
                <select name="exemption_reason[${item.product_id}]" data-order_id="${order_id}" class="select2Modal" style="width:100%">
                    <option value="">Selecione...</option>
                    ${exemptionReasons.map(reason =>
                `<option value="${reason.id}">${reason.code} - ${reason.name}</option>`
            ).join('')}
                </select>
            </td>
        </tr>`;
        });

        $('#exemptionTableBody').html(html);

        $(".select2Modal").select2({
            placeholder: "Escolha uma opção...",
            width: "100%",
            allowClear: true,
            dropdownParent: $("#exemptionModal")
        });

        $('#exemptionModal').fadeIn();
    }
});


function openEmailModal(modal) {
    modal.show();
}
function closeEmailModal(modal) {
    modal.hide();
    modal.find('#email').val('');
}

function wpAdminNotice(message, type = 'success') {
    const container = document.getElementById('gesfaturacao-notices');
    if (!container) return;

    // Build the same HTML that settings_errors() would output:
    const notice = document.createElement('div');
    notice.className = `notice notice-${type} is-dismissible`;
    notice.innerHTML = `
    <p>${message}</p>
    <button type="button" class="notice-dismiss">
      <span class="screen-reader-text">Dismiss this notice.</span>
    </button>
  `;

    container.appendChild(notice);

    // Dismiss on click of the “X”
    jQuery(notice).on('click', '.notice-dismiss', function() {
        jQuery(this).closest('.notice').fadeOut();
    });

    // Auto‐dismiss after 4 seconds
    setTimeout(() => {
        jQuery(notice).fadeOut();
    }, 4000);
}

