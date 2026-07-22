<?php

declare(strict_types=1);

$enabled = is_array($config);
?>
<div class="wrap va-stores va-store-detail" data-va-store-detail aria-busy="<?= $enabled ? 'true' : 'false'; ?>">
    <nav class="va-store-detail__navigation" aria-label="Navegación del detalle">
        <a href="<?= esc_url($returnUrl); ?>">&larr; Volver a minimarkets</a>
    </nav>

    <header class="va-store-detail__header">
        <div>
            <h1>Detalle del minimarket</h1>
            <p class="va-store-detail__identity" data-va-store-detail-identity>Información pendiente de carga.</p>
        </div>
        <div data-va-store-detail-badge aria-label="Estado lifecycle"></div>
    </header>

    <div class="va-store-detail__messages" data-va-store-detail-messages role="status" aria-live="polite" tabindex="-1">
        <?php if (is_string($errorMessage)) : ?>
            <div class="notice notice-error inline"><p><?= esc_html($errorMessage); ?></p></div>
        <?php elseif ($enabled) : ?>
            <p>Detalle preparado para cargar información.</p>
        <?php endif; ?>
    </div>

    <?php if ($enabled) : ?>
        <main class="va-store-detail__main" data-va-store-detail-main>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-summary-heading" tabindex="-1">
                <h2 id="va-store-detail-summary-heading">Resumen administrativo</h2>
                <div data-va-store-detail-summary></div>
            </section>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-lifecycle-heading" tabindex="-1">
                <h2 id="va-store-detail-lifecycle-heading">Lifecycle</h2>
                <div data-va-store-detail-lifecycle></div>
            </section>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-commercial-heading" tabindex="-1">
                <h2 id="va-store-detail-commercial-heading">Información comercial</h2>
                <div data-va-store-detail-commercial></div>
            </section>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-inventory-heading" tabindex="-1" hidden>
                <h2 id="va-store-detail-inventory-heading">Ofertas del minimarket</h2>
                <div data-va-store-detail-inventory></div>
            </section>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-actions-heading" tabindex="-1">
                <h2 id="va-store-detail-actions-heading">Acciones</h2>
                <div data-va-store-detail-actions></div>
            </section>
            <section class="va-store-detail__section" aria-labelledby="va-store-detail-sensitive-heading" tabindex="-1">
                <h2 id="va-store-detail-sensitive-heading">Zona sensible</h2>
                <div data-va-store-detail-sensitive></div>
            </section>
        </main>
        <noscript>
            <div class="notice notice-warning inline"><p>JavaScript es necesario para cargar la información del minimarket. Puedes volver al listado con el enlace superior.</p></div>
        </noscript>
        <script type="application/json" id="va-store-detail-config"><?= wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php endif; ?>
</div>
