(function () {
    'use strict';
    document.addEventListener('submit', async function (event) {
        const form = event.target.closest('[data-return-refund]');
        if (!form) return;
        event.preventDefault();
        const message = form.querySelector('[data-result]');
        const button = form.querySelector('button');
        if (!form.reportValidity() || !form.querySelector('[name=confirmed]').checked) return;
        if (!window.confirm('Esta acción solicita una devolución financiera irreversible. ¿Confirmas los datos de este pedido?')) return;
        button.disabled = true;
        try {
            const response = await fetch(form.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type':'application/json', 'X-WP-Nonce': form.dataset.nonce},
                body: JSON.stringify({expected_version:Number(form.dataset.version), idempotency_key:form.dataset.key,
                    retry_of:Number(form.dataset.retry), note:form.querySelector('[name=note]').value, confirmed:true})
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'No fue posible resolver la solicitud.');
            message.textContent = result.data.status === 'refunded' ? 'Devolución confirmada. Actualiza la página para ver el desglose.' :
                result.data.status === 'refund_failed' ? 'Devolución rechazada. Revisa la incidencia antes de iniciar un nuevo intento explícito.' :
                'Devolución pendiente de revisión. No inicies otro intento.';
        } catch (error) { message.textContent = error.message; }
        finally { button.disabled = false; }
        // Retain the exact key and payload after a lost response. Editing the note conflicts safely.
    });
}());
