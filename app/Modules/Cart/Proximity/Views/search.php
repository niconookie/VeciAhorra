<section data-va-pickup hidden class="va-card" aria-label="Retiro cercano">
    <p>Tu carrito no alcanza el mínimo de despacho.</p>
    <button type="button" class="va-button va-button--secondary" data-pickup-open>Buscar los mismos productos para retiro cerca de mí</button>
    <div data-pickup-controls hidden>
        <p>Elige un punto para ordenar los minimarkets por distancia aproximada. No cambia tu domicilio ni autoriza despacho.</p>
        <button type="button" class="va-button va-button--secondary" data-pickup-locate>Usar mi ubicación actual</button>
        <label>Otra dirección <input type="text" maxlength="255" autocomplete="off" data-pickup-address></label>
        <button type="button" class="va-button va-button--secondary" data-pickup-geocode>Buscar dirección</button>
        <p data-pickup-google-status></p>
        <label>Resultado de dirección <select data-pickup-address-results><option value="">Selecciona un resultado</option></select></label>
        <fieldset>
            <legend>Revisar o ajustar el punto manualmente</legend>
            <label>Latitud <input type="number" min="-90" max="90" step="any" data-pickup-latitude></label>
            <label>Longitud <input type="number" min="-180" max="180" step="any" data-pickup-longitude></label>
            <p data-pickup-point></p>
            <label><input type="checkbox" data-pickup-confirm>Confirmo este punto para buscar retiro cercano</label>
        </fieldset>
        <button type="button" class="va-button va-button--primary" data-pickup-search>Buscar minimarket</button>
        <p role="status" aria-live="polite" data-pickup-status></p>
    </div>
    <div data-pickup-proposal hidden></div>
    <button type="button" class="va-button va-button--secondary" data-pickup-cancel hidden>Mantener mi carrito</button>
</section>
