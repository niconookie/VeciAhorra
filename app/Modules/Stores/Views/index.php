<div class="wrap va-stores" id="va-stores-app" aria-busy="true">
    <div class="va-stores__heading">
        <div>
            <h1>Minimarkets</h1>
            <p>Busca y revisa el estado contractual de los minimarkets.</p>
        </div>
        <a class="page-title-action" href="<?= esc_url($config['createUrl']); ?>">Crear minimarket</a>
    </div>

    <form class="va-stores__filters" id="va-stores-filters" role="search">
        <div>
            <label for="va-stores-search">Buscar</label>
            <input id="va-stores-search" name="search" type="search" maxlength="100" placeholder="Nombre, RUT, correo o ubicación">
        </div>
        <div>
            <label for="va-stores-lifecycle">Estado lifecycle</label>
            <select id="va-stores-lifecycle" name="lifecycle_state">
                <option value="">Todos</option>
                <option value="draft">Borrador</option>
                <option value="in_review">En revisión</option>
                <option value="rejected">Rechazado</option>
                <option value="approved_inactive">Aprobado e inactivo</option>
                <option value="active">Activo</option>
                <option value="invalid">Estado inconsistente</option>
            </select>
        </div>
        <div>
            <label for="va-stores-status">Estado operativo</label>
            <select id="va-stores-status" name="status">
                <option value="">Todos</option>
                <option value="pending">Pending</option>
                <option value="inactive">Inactive</option>
                <option value="active">Active</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div>
            <label for="va-stores-sort">Orden</label>
            <select id="va-stores-sort" name="sort">
                <option value="name_asc">Nombre A–Z</option>
                <option value="newest">Más recientes</option>
                <option value="oldest">Más antiguos</option>
                <option value="updated">Actualización reciente</option>
            </select>
        </div>
        <div class="va-stores__filter-actions">
            <button class="button button-primary" type="submit">Buscar</button>
            <button class="button" id="va-stores-reset" type="button">Restablecer</button>
        </div>
    </form>

    <p id="va-stores-summary" class="va-stores__summary" aria-live="polite"></p>
    <div id="va-stores-results" class="va-stores__results" role="region" aria-label="Resultados de minimarkets" tabindex="-1"></div>
    <nav id="va-stores-pagination" class="va-stores__pagination" aria-label="Paginación de minimarkets"></nav>
    <noscript><p class="notice notice-warning">JavaScript es necesario para cargar el listado. Aún puedes crear un minimarket con el enlace superior.</p></noscript>
</div>
<script type="application/json" id="va-stores-config"><?= wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
