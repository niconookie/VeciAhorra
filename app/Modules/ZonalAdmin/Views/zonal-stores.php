<?php declare(strict_types=1); ?>
<div class="wrap va-zonal-stores" data-va-zonal-stores>
    <h1><?php echo esc_html__('Administración zonal', 'veciahorra'); ?></h1>
    <p><?php echo esc_html__('Revisa las solicitudes de minimarkets correspondientes a tus sectores asignados.', 'veciahorra'); ?></p>
    <div class="va-zonal-stores__live" data-va-live role="status" aria-live="polite" aria-atomic="true"></div>
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
