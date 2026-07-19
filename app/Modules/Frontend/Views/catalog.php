<?php
/** @var string $instanceId */
/** @var array<int, string> $productUrls */
$titleId = $instanceId . '-catalog-title';
$encodedUrls = wp_json_encode($productUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section class="va-catalog" data-va-catalog data-product-urls="<?php echo esc_attr(is_string($encodedUrls) ? $encodedUrls : '{}'); ?>" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="va-catalog__hero">
        <p class="va-catalog__eyebrow"><?php esc_html_e('Compra local, ahorra cerca', 'veciahorra'); ?></p>
        <h1 id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Catálogo VeciAhorra', 'veciahorra'); ?></h1>
        <p><?php esc_html_e('Encuentra productos cerca de ti y compara los mejores precios disponibles.', 'veciahorra'); ?></p>
    </header>
    <form class="va-catalog__filters" data-va-catalog-filters>
        <div class="va-field">
            <label for="<?php echo esc_attr($instanceId . '-search'); ?>"><?php esc_html_e('Buscar productos', 'veciahorra'); ?></label>
            <input id="<?php echo esc_attr($instanceId . '-search'); ?>" type="search" data-va-catalog-search autocomplete="off">
        </div>
        <div class="va-field">
            <label for="<?php echo esc_attr($instanceId . '-category'); ?>"><?php esc_html_e('Categoría', 'veciahorra'); ?></label>
            <select id="<?php echo esc_attr($instanceId . '-category'); ?>" data-va-catalog-category disabled>
                <option value=""><?php esc_html_e('Todas las categorías', 'veciahorra'); ?></option>
            </select>
            <p class="va-help-text" data-va-catalog-category-status role="status" aria-live="polite"><?php esc_html_e('Cargando categorías…', 'veciahorra'); ?></p>
        </div>
        <div class="va-field">
            <label for="<?php echo esc_attr($instanceId . '-order'); ?>"><?php esc_html_e('Ordenar por', 'veciahorra'); ?></label>
            <select id="<?php echo esc_attr($instanceId . '-order'); ?>" data-va-catalog-order>
                <option value="name"><?php esc_html_e('Nombre', 'veciahorra'); ?></option>
                <option value="price"><?php esc_html_e('Menor precio', 'veciahorra'); ?></option>
                <option value="newest"><?php esc_html_e('Más recientes', 'veciahorra'); ?></option>
            </select>
        </div>
        <div class="va-catalog__filter-actions">
            <button class="va-button" type="submit"><?php esc_html_e('Aplicar filtros', 'veciahorra'); ?></button>
            <button class="va-button va-button--secondary" type="button" data-va-catalog-reset><?php esc_html_e('Restablecer', 'veciahorra'); ?></button>
        </div>
    </form>
    <div class="va-loader va-catalog__loader" role="status" data-va-catalog-loading><span class="va-loader__indicator" aria-hidden="true"></span><span><?php esc_html_e('Cargando productos', 'veciahorra'); ?></span></div>
    <div class="va-alert va-alert--error va-catalog__error" role="alert" data-va-catalog-error hidden><p data-va-catalog-error-message></p><button class="va-button" type="button" data-va-catalog-retry><?php esc_html_e('Reintentar', 'veciahorra'); ?></button></div>
    <div class="va-empty-state" data-va-catalog-empty hidden><h2 class="va-empty-state__title"><?php esc_html_e('Aún no hay productos disponibles', 'veciahorra'); ?></h2><p class="va-empty-state__message"><?php esc_html_e('Vuelve pronto para descubrir nuevas ofertas.', 'veciahorra'); ?></p></div>
    <div class="va-catalog__grid" data-va-catalog-grid hidden></div>
    <p class="va-visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status></p>
</section>
