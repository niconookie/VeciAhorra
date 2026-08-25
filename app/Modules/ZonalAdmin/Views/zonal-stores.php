<?php declare(strict_types=1); ?>
<div class="wrap va-zonal-stores" data-va-zonal-stores>
    <h1><?php echo esc_html__('Administración zonal', 'veciahorra'); ?></h1>
    <p><?php echo esc_html__('Consulta la actividad comercial y revisa las solicitudes correspondientes exclusivamente a tus sectores asignados.', 'veciahorra'); ?></p>
    <div class="va-zonal-stores__live" data-va-live role="status" aria-live="polite" aria-atomic="true"></div>
    <section class="va-zonal-sales" data-va-sales aria-labelledby="va-zonal-sales-title">
        <h2 id="va-zonal-sales-title"><?php echo esc_html__('Resumen de mi zona', 'veciahorra'); ?></h2>
        <form class="va-zonal-sales__filters" data-va-sales-filters>
            <label><?php echo esc_html__('Periodo', 'veciahorra'); ?><select data-va-sales-period><option value="7">Últimos 7 días</option><option value="30" selected>Últimos 30 días</option><option value="custom">Rango personalizado</option></select></label>
            <label data-va-sales-from-wrap hidden><?php echo esc_html__('Desde', 'veciahorra'); ?><input type="date" data-va-sales-from></label>
            <label data-va-sales-to-wrap hidden><?php echo esc_html__('Hasta', 'veciahorra'); ?><input type="date" data-va-sales-to></label>
            <label><?php echo esc_html__('Ordenar por', 'veciahorra'); ?><select data-va-sales-order><option value="sales">Ventas</option><option value="name">Nombre</option><option value="orders">Pedidos</option><option value="last_sale">Última venta</option></select></label>
            <label><?php echo esc_html__('Dirección', 'veciahorra'); ?><select data-va-sales-direction><option value="desc">Descendente</option><option value="asc">Ascendente</option></select></label>
            <button type="submit" class="button button-primary"><?php echo esc_html__('Aplicar', 'veciahorra'); ?></button>
        </form>
        <p class="va-zonal-sales__period" data-va-sales-period-label></p>
        <div class="va-zonal-sales__cards" data-va-sales-cards aria-live="polite">
            <article class="va-zonal-sales__card"><span>Minimarkets activos</span><strong>0</strong></article>
            <article class="va-zonal-sales__card"><span>Pedidos pagados</span><strong>0</strong></article>
            <article class="va-zonal-sales__card"><span>Ventas de productos</span><strong>$0</strong></article>
            <article class="va-zonal-sales__card"><span>Ticket promedio</span><strong>$0</strong></article>
        </div>
        <h2><?php echo esc_html__('Ventas por minimarket', 'veciahorra'); ?></h2>
        <div data-va-sales-content><p class="va-zonal-stores__loading"><?php echo esc_html__('Cargando resumen de ventas…', 'veciahorra'); ?></p></div>
        <nav class="va-zonal-sales__pagination" aria-label="Paginación de ventas"><button type="button" class="button" data-va-sales-prev>Anterior</button><button type="button" class="button" data-va-sales-next>Siguiente</button></nav>
    </section>
    <section data-va-list aria-labelledby="va-zonal-list-title">
        <h2 id="va-zonal-list-title"><?php echo esc_html__('Solicitudes', 'veciahorra'); ?></h2>
        <form data-va-filters class="va-zonal-stores__filters">
            <label><?php echo esc_html__('Buscar', 'veciahorra'); ?><input type="search" data-va-search maxlength="100"></label>
            <label><?php echo esc_html__('Estado', 'veciahorra'); ?><select data-va-state><option value="">Todos</option><option value="in_review">En revisión</option><option value="observed">Observados</option><option value="rejected">Rechazados</option><option value="approved_inactive">Aprobados</option></select></label>
            <button type="submit" class="button button-primary">Aplicar</button><button type="button" class="button" data-va-reload>Recargar</button>
        </form>
        <p data-va-total></p><div data-va-list-content></div>
        <nav aria-label="Paginación"><button type="button" class="button" data-va-prev>Anterior</button><button type="button" class="button" data-va-next>Siguiente</button></nav>
    </section>
    <section data-va-detail hidden aria-labelledby="va-zonal-detail-title"><button type="button" class="button" data-va-back>Volver al listado</button><div data-va-detail-content></div></section>
</div>
