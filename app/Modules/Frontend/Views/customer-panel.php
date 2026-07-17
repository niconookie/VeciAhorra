<?php
/** @var string $instanceId */
/** @var bool $loggedIn */
/** @var string $loginUrl */
$mainId = $instanceId . '-main';
$titleId = $instanceId . '-title';
?>
<div id="<?php echo esc_attr($instanceId); ?>" class="veciahorra-frontend va-customer-panel" data-va-customer-panel<?php echo $loggedIn ? ' data-va-customer-panel-mount' : ''; ?>>
    <a class="va-skip-link" href="#<?php echo esc_attr($mainId); ?>"><?php esc_html_e('Saltar al contenido', 'veciahorra'); ?></a>
    <main id="<?php echo esc_attr($mainId); ?>" class="va-container va-customer-panel__main" tabindex="-1" aria-labelledby="<?php echo esc_attr($titleId); ?>">
        <header class="va-customer-panel__header">
            <h1 id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Mis compras', 'veciahorra'); ?></h1>
            <?php if ($loggedIn) : ?>
                <p><?php esc_html_e('Consulta tus compras realizadas en VeciAhorra.', 'veciahorra'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('Debes iniciar sesión para consultar tus compras.', 'veciahorra'); ?></p>
            <?php endif; ?>
        </header>

        <?php if ($loggedIn) : ?>
            <div class="va-customer-panel__status" role="status" aria-live="polite" data-va-customer-panel-status>
                <?php esc_html_e('Panel de compras preparado.', 'veciahorra'); ?>
            </div>
            <section class="va-customer-panel__content" aria-label="<?php esc_attr_e('Contenido de compras', 'veciahorra'); ?>" data-va-customer-panel-content>
                <noscript><?php esc_html_e('Activa JavaScript para consultar tus compras', 'veciahorra'); ?></noscript>
            </section>
            <div class="va-announcer" aria-live="polite" aria-atomic="true" data-va-customer-panel-announcer></div>
        <?php else : ?>
            <div class="va-customer-panel__access va-alert va-alert--info">
                <a class="va-button" href="<?php echo esc_url($loginUrl); ?>"><?php esc_html_e('Iniciar sesión', 'veciahorra'); ?></a>
                <noscript><?php esc_html_e('Activa JavaScript para consultar tus compras', 'veciahorra'); ?></noscript>
            </div>
        <?php endif; ?>
    </main>
</div>
