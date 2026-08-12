<?php
/** @var string $content Trusted output from an allowlisted internal view. */
/** @var string $instanceId Controller-generated identifier. */
$mainId = $instanceId . '-main';
?>
<div id="<?php echo esc_attr($instanceId); ?>" class="veciahorra-frontend" data-va-frontend>
    <a class="va-skip-link" href="#<?php echo esc_attr($mainId); ?>"><?php esc_html_e('Saltar al contenido', 'veciahorra'); ?></a>
    <section class="va-sector-selector" data-va-sector-selector aria-label="Sector de compra">
        <label>Estás comprando en: <select data-va-sector-select><option value="">Selecciona un sector</option></select></label>
        <span data-va-sector-message aria-live="polite"></span>
    </section>
    <main id="<?php echo esc_attr($mainId); ?>" class="va-container" tabindex="-1">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </main>
    <div class="va-announcer" aria-live="polite" aria-atomic="true"></div>
</div>
