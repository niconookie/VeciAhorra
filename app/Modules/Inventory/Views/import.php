<?php defined('ABSPATH') || exit; ?>
<div class="wrap va-inventory-import">
    <h1><?= esc_html__('Carga masiva de inventario', 'veciahorra'); ?></h1>
    <p class="description">Importa inventario existente para un solo minimarket. Primero se valida todo el archivo; ningún cambio se aplica hasta confirmar.</p>
    <?php if ($message !== ''): ?><div class="notice notice-error"><p><?= esc_html($message); ?></p></div><?php endif; ?>
    <?php if ($result !== null): ?><div class="notice notice-success"><p><strong>Importación completada correctamente.</strong> Todo el archivo fue aplicado. Creados: <?= esc_html((string) $result['created']); ?> · Actualizados: <?= esc_html((string) $result['updated']); ?> · Sin cambios: <?= esc_html((string) $result['unchanged']); ?></p></div><?php endif; ?>
    <section class="va-import-card" aria-labelledby="va-import-instructions">
        <h2 id="va-import-instructions">1. Prepara el CSV</h2>
        <p>Usa UTF-8, delimitador coma y exactamente <code>sku,precio,stock,estado</code>. Máximo 1 MB y 1000 filas. Estado: <code>active</code> o <code>inactive</code>.</p>
        <form action="<?= esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="veciahorra_inventory_csv_template"><?php wp_nonce_field('veciahorra_inventory_csv_template'); ?><button class="button" type="submit">Descargar plantilla y ejemplo</button></form>
    </section>
    <section class="va-import-card" aria-labelledby="va-import-upload">
        <h2 id="va-import-upload">2. Selecciona el minimarket y valida</h2>
        <form action="<?= esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="veciahorra_inventory_csv_preview"><?php wp_nonce_field('veciahorra_inventory_csv_preview'); ?>
            <label for="va-store"><strong>Minimarket</strong></label>
            <select id="va-store" name="store_id" required><option value="">Seleccionar…</option><?php foreach ($stores as $store): ?><option value="<?= esc_attr((string) $store['id']); ?>"><?= esc_html((string) $store['business_name']); ?> — <?= esc_html((string) $store['status']); ?></option><?php endforeach; ?></select>
            <label for="va-csv"><strong>Archivo CSV</strong></label><input id="va-csv" name="inventory_csv" type="file" accept=".csv,text/csv" required>
            <button class="button button-primary" type="submit">Validar y ver vista previa</button>
        </form>
    </section>
    <?php if (is_array($preview)): ?>
    <section class="va-import-card" aria-labelledby="va-import-preview">
        <h2 id="va-import-preview">3. Vista previa: <?= esc_html((string) $preview['store_name']); ?></h2>
        <p><strong><?= esc_html((string) count($preview['rows'])); ?></strong> válidas: <?= esc_html((string) $preview['created']); ?> por crear, <?= esc_html((string) $preview['updated']); ?> por actualizar y <?= esc_html((string) $preview['unchanged']); ?> sin cambios. <strong><?= esc_html((string) count($preview['errors'])); ?></strong> rechazadas.</p>
        <?php $statusLabels = ['active' => 'Activo', 'inactive' => 'Inactivo']; $changeLabels = ['create' => 'Crear', 'update' => 'Actualizar', 'unchanged' => 'Sin cambios']; ?>
        <?php if ($preview['rows'] !== []): ?><div class="va-import-table"><table class="widefat striped"><thead><tr><th>Fila</th><th>SKU</th><th>Precio</th><th>Stock</th><th>Estado</th><th>Cambio</th></tr></thead><tbody><?php foreach ($preview['rows'] as $row): ?><tr><td><?= esc_html((string) $row['line']); ?></td><td><?= esc_html((string) $row['sku']); ?></td><td>$<?= esc_html(number_format((int) $row['price'], 0, ',', '.')); ?></td><td><?= esc_html((string) $row['stock']); ?></td><td><?= esc_html($statusLabels[(string) $row['status']] ?? (string) $row['status']); ?></td><td><?= esc_html($changeLabels[(string) $row['change']] ?? (string) $row['change']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if ($preview['errors'] !== []): ?><div class="notice notice-error inline"><p><strong>El archivo contiene errores. Corrige todas las filas rechazadas y vuelve a cargarlo. No se aplicó ningún cambio.</strong></p></div><h3>Filas rechazadas</h3><div class="va-import-table"><table class="widefat striped"><thead><tr><th>Fila</th><th>SKU</th><th>Error</th></tr></thead><tbody><?php foreach ($preview['errors'] as $error): ?><tr><td><?= esc_html((string) $error['line']); ?></td><td><?= esc_html((string) $error['sku']); ?></td><td><?= esc_html((string) $error['message']); ?></td></tr><?php endforeach; ?></tbody></table></div><p><a class="button" href="<?= esc_url(wp_nonce_url(admin_url('admin-post.php?action=veciahorra_inventory_csv_errors&va_token=' . $token), 'veciahorra_inventory_csv_errors_' . $token)); ?>">Descargar informe de errores</a></p><?php endif; ?>
        <?php if ($preview['rows'] !== [] && $preview['errors'] === [] && empty($preview['completed'])): ?><form action="<?= esc_url(admin_url('admin-post.php')); ?>" method="post" class="va-import-confirm"><input type="hidden" name="action" value="veciahorra_inventory_csv_confirm"><input type="hidden" name="va_token" value="<?= esc_attr($token); ?>"><?php wp_nonce_field('veciahorra_inventory_csv_confirm'); ?><p>La confirmación aplica el archivo completo en una sola transacción. Si los datos cambiaron desde esta vista, se cancela todo.</p><button class="button button-primary" type="submit">Confirmar importación</button></form><?php endif; ?>
    </section>
    <?php endif; ?>
</div>
