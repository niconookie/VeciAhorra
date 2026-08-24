<?php
/** @var string $instanceId */
/** @var array<int, string> $productUrls */
/** @var string $catalogUrl */
$titleId = $instanceId . '-catalog-title';
$filtersId = $instanceId . '-catalog-filters';
$encodedUrls = wp_json_encode($productUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section class="veciahorra-frontend va-design-system va-catalog" data-va-catalog data-product-urls="<?php echo esc_attr(is_string($encodedUrls) ? $encodedUrls : '{}'); ?>" data-catalog-url="<?php echo esc_url($catalogUrl); ?>" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="va-catalog__heading">
        <div><h1 id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Productos cerca de ti', 'veciahorra'); ?></h1><p role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status><?php esc_html_e('Cargando opciones disponibles…', 'veciahorra'); ?></p></div>
        <div class="va-field va-catalog__order"><label for="<?php echo esc_attr($instanceId . '-order'); ?>"><?php esc_html_e('Ordenar por', 'veciahorra'); ?></label><select id="<?php echo esc_attr($instanceId . '-order'); ?>" data-va-catalog-order form="<?php echo esc_attr($filtersId); ?>"><option value="price" selected><?php esc_html_e('Precio más conveniente', 'veciahorra'); ?></option><option value="name"><?php esc_html_e('Nombre', 'veciahorra'); ?></option><option value="newest"><?php esc_html_e('Más recientes', 'veciahorra'); ?></option></select></div>
    </header>
    <button class="va-catalog__filters-toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr($instanceId . '-filters-panel'); ?>" data-va-catalog-filters-toggle><span aria-hidden="true">☷</span> <?php esc_html_e('Filtros', 'veciahorra'); ?></button>
    <div class="va-catalog__layout">
        <aside class="va-catalog__filters-panel" id="<?php echo esc_attr($instanceId . '-filters-panel'); ?>" aria-label="<?php esc_attr_e('Filtros de productos', 'veciahorra'); ?>">
            <form id="<?php echo esc_attr($filtersId); ?>" class="va-catalog__filters" action="<?php echo esc_url($catalogUrl); ?>" method="get" data-va-catalog-filters>
                <div class="va-catalog__filters-title"><h2><?php esc_html_e('Filtrar por', 'veciahorra'); ?></h2><button class="va-button va-button--secondary" type="button" data-va-catalog-reset><?php esc_html_e('Limpiar', 'veciahorra'); ?></button></div>
                <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-search'); ?>"><?php esc_html_e('Buscar productos', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-search'); ?>" name="search" type="search" data-va-catalog-search autocomplete="off" placeholder="<?php esc_attr_e('Nombre del producto', 'veciahorra'); ?>"></div>
                <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-category'); ?>"><?php esc_html_e('Categoría', 'veciahorra'); ?></label><select id="<?php echo esc_attr($instanceId . '-category'); ?>" name="category" data-va-catalog-category disabled><option value=""><?php esc_html_e('Todas las categorías', 'veciahorra'); ?></option></select></div>
                <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-subcategory'); ?>"><?php esc_html_e('Subcategoría', 'veciahorra'); ?></label><select id="<?php echo esc_attr($instanceId . '-subcategory'); ?>" name="subcategory" data-va-catalog-subcategory disabled><option value=""><?php esc_html_e('Todas las subcategorías', 'veciahorra'); ?></option></select></div>
                <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-brand'); ?>"><?php esc_html_e('Marca', 'veciahorra'); ?></label><select id="<?php echo esc_attr($instanceId . '-brand'); ?>" name="brand" data-va-catalog-brand disabled><option value=""><?php esc_html_e('Todas las marcas', 'veciahorra'); ?></option></select></div>
                <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-unit'); ?>"><?php esc_html_e('Unidad o formato', 'veciahorra'); ?></label><select id="<?php echo esc_attr($instanceId . '-unit'); ?>" name="unit" data-va-catalog-unit disabled><option value=""><?php esc_html_e('Todas las unidades', 'veciahorra'); ?></option></select></div>
                <div class="va-catalog__sector-context"><span><?php esc_html_e('Microzona seleccionada', 'veciahorra'); ?></span><strong data-va-catalog-sector><?php esc_html_e('Consultando microzona…', 'veciahorra'); ?></strong><small><?php esc_html_e('Precios, stock y opciones se limitan automáticamente a esta zona.', 'veciahorra'); ?></small></div>
                <button class="va-button va-button--primary va-catalog__apply" type="submit"><?php esc_html_e('Aplicar filtros', 'veciahorra'); ?></button>
                <p class="va-help-text" data-va-catalog-filter-status role="status" aria-live="polite"><?php esc_html_e('Cargando filtros disponibles…', 'veciahorra'); ?></p>
            </form>
        </aside>
        <div class="va-catalog__results">
            <div class="va-loader va-catalog__loader" role="status" data-va-catalog-loading><span class="va-loader__indicator" aria-hidden="true"></span><span><?php esc_html_e('Cargando productos', 'veciahorra'); ?></span></div>
            <div class="va-alert va-alert--error va-catalog__error" role="alert" data-va-catalog-error hidden><p data-va-catalog-error-message></p><button class="va-button va-button--primary" type="button" data-va-catalog-retry><?php esc_html_e('Reintentar', 'veciahorra'); ?></button></div>
            <div class="va-empty-state" data-va-catalog-empty hidden><h2 class="va-empty-state__title"><?php esc_html_e('No hay productos disponibles', 'veciahorra'); ?></h2><p class="va-empty-state__message"><?php esc_html_e('Prueba con otros filtros o selecciona otra microzona.', 'veciahorra'); ?></p></div>
            <div class="va-catalog__grid" data-va-catalog-grid hidden></div>
        </div>
    </div>
</section>
