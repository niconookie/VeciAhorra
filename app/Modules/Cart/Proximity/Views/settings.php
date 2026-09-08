<?php /** @var array $stores */ ?>
<div class="wrap">
    <h1>Retiro cercano</h1>
    <?php if ($message !== ''): ?><p role="status"><?php echo esc_html($message); ?></p><?php endif; ?>
    <h2>Búsqueda de direcciones con Google Maps</h2>
    <p>Usa una clave para navegador restringida a los dominios autorizados y únicamente a Maps JavaScript API y Geocoding API. No uses una clave de servidor.</p>
    <p>Estado: <?php echo $this->browserKey() !== '' ? 'configurada' : 'sin configurar'; ?>. Sin clave se mantienen la ubicación del dispositivo y las coordenadas manuales.</p>
    <form method="post">
        <?php wp_nonce_field('va_pickup_settings'); ?><input type="hidden" name="operation" value="key">
        <label>Nueva clave de navegador <input type="password" name="browser_key" maxlength="200" autocomplete="new-password" value=""></label>
        <label><input type="checkbox" name="remove_key">Quitar la clave configurada</label>
        <button class="button button-primary">Guardar configuración</button>
    </form>
    <h2>Ubicación de minimarkets</h2>
    <p>Un minimarket con ubicación pendiente queda fuera de la búsqueda cercana. Sus zonas se administran en Sectores.</p>
    <?php foreach ($stores as $store): ?>
        <form method="post" style="margin:1rem 0;padding:1rem;background:white">
            <?php wp_nonce_field('va_pickup_settings'); ?><input type="hidden" name="operation" value="coordinates">
            <input type="hidden" name="store_id" value="<?php echo esc_attr((string)$store['id']); ?>">
            <h3><?php echo esc_html($store['business_name']); ?> — <?php echo $store['pickup_latitude'] !== null && $store['pickup_longitude'] !== null ? 'Ubicación completa' : 'Ubicación pendiente'; ?></h3>
            <p>Zonas: <?php echo esc_html($store['zones'] ?: 'Sin zona asignada'); ?></p>
            <label>Latitud <input type="number" step="0.0000001" min="-90" max="90" name="latitude" value="<?php echo esc_attr((string)$store['pickup_latitude']); ?>"></label>
            <label>Longitud <input type="number" step="0.0000001" min="-180" max="180" name="longitude" value="<?php echo esc_attr((string)$store['pickup_longitude']); ?>"></label>
            <label><input type="checkbox" name="clear_point">Dejar ubicación pendiente</label>
            <button class="button">Guardar ubicación</button>
        </form>
    <?php endforeach; ?>
</div>
