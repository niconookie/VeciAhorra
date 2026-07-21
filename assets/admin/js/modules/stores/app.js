const root = document.getElementById('va-stores-app');
const configNode = document.getElementById('va-stores-config');

if (root && configNode && root.dataset.initialized !== 'true') {
    root.dataset.initialized = 'true';
    let config;
    try {
        config = JSON.parse(configNode.textContent || '{}');
        ['restUrl', 'nonce', 'adminUrl', 'createUrl', 'editUrl'].forEach((key) => {
            if (typeof config[key] !== 'string' || config[key].trim() === '') throw new Error('invalid_config');
        });
        [config.restUrl, config.adminUrl, config.createUrl, config.editUrl].forEach((value) => new URL(value));
    } catch (error) {
        root.setAttribute('aria-busy', 'false');
        const message = document.createElement('p');
        message.className = 'notice notice-error';
        message.textContent = 'No fue posible iniciar el listado de minimarkets.';
        root.append(message);
        throw error;
    }
    const form = document.getElementById('va-stores-filters');
    const results = document.getElementById('va-stores-results');
    const summary = document.getElementById('va-stores-summary');
    const pagination = document.getElementById('va-stores-pagination');
    const reset = document.getElementById('va-stores-reset');
    const fields = {
        search: document.getElementById('va-stores-search'),
        lifecycle_state: document.getElementById('va-stores-lifecycle'),
        status: document.getElementById('va-stores-status'),
        sort: document.getElementById('va-stores-sort'),
    };
    const labels = {
        draft: ['Borrador', 'neutral'],
        in_review: ['En revisión', 'info'],
        rejected: ['Rechazado', 'critical'],
        approved_inactive: ['Aprobado e inactivo', 'warning'],
        active: ['Activo', 'positive'],
        invalid: ['Estado inconsistente', 'critical'],
    };
    const sortMap = {
        name_asc: ['business_name', 'ASC'],
        newest: ['created_at', 'DESC'],
        oldest: ['created_at', 'ASC'],
        updated: ['updated_at', 'DESC'],
    };
    let controller = null;
    let latestRequest = 0;

    const allowedLifecycle = new Set(['', 'draft', 'in_review', 'rejected', 'approved_inactive', 'active', 'invalid']);
    const allowedStatus = new Set(['', 'pending', 'inactive', 'active', 'rejected']);
    const allowedSort = new Set(Object.keys(sortMap));

    const current = () => {
        const url = new URL(window.location.href);
        const lifecycle = url.searchParams.get('lifecycle_state') || '';
        const status = url.searchParams.get('status') || '';
        const sort = url.searchParams.get('sort') || 'name_asc';
        const rawPage = url.searchParams.get('paged') || '1';
        const page = /^[1-9]\d{0,6}$/.test(rawPage) ? Number(rawPage) : 1;
        const rawStoreId = url.searchParams.get('store_id') || '';
        const storeId = /^[1-9]\d*$/.test(rawStoreId) && Number.isSafeInteger(Number(rawStoreId))
            ? Number(rawStoreId)
            : null;
        return {
            search: url.searchParams.get('search') || '',
            lifecycle_state: allowedLifecycle.has(lifecycle) ? lifecycle : '',
            status: allowedStatus.has(status) ? status : '',
            sort: allowedSort.has(sort) ? sort : 'name_asc',
            page: Number.isSafeInteger(page) && page <= 1000000 ? page : 1,
            storeId,
        };
    };

    const setUrl = (state, replace = false) => {
        const url = new URL(window.location.href);
        url.searchParams.set('page', new URL(config.adminUrl).searchParams.get('page') || 'veciahorra-stores');
        Object.entries({
            search: state.search,
            lifecycle_state: state.lifecycle_state,
            status: state.status,
            sort: state.sort === 'name_asc' ? '' : state.sort,
            paged: state.page > 1 ? String(state.page) : '',
            store_id: state.storeId ? String(state.storeId) : '',
        }).forEach(([key, value]) => value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
        window.history[replace ? 'replaceState' : 'pushState']({}, '', url);
    };

    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };

    const link = (text, href, className = '') => {
        const element = node('a', text, className);
        element.href = href;
        return element;
    };

    const requestUrl = (state) => {
        const url = new URL(`${config.restUrl}/stores`);
        const [orderBy, direction] = sortMap[state.sort] || sortMap.name_asc;
        url.searchParams.set('context', 'admin_list');
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('per_page', '20');
        url.searchParams.set('order_by', orderBy);
        url.searchParams.set('direction', direction);
        if (state.search) url.searchParams.set('search', state.search);
        if (state.lifecycle_state) url.searchParams.set('lifecycle_state', state.lifecycle_state);
        if (state.status) url.searchParams.set('status', state.status);
        return url;
    };

    const detailUrl = (state, id) => {
        const url = new URL(window.location.href);
        url.searchParams.set('store_id', String(id));
        return url.toString();
    };

    const renderDetail = (item, state) => {
        if (!item || state.storeId !== Number(item.id)) return null;
        const section = node('section', undefined, 'va-stores__detail');
        const heading = node('div', undefined, 'va-stores__detail-heading');
        heading.append(node('h2', item.business_name));
        const closeUrl = new URL(window.location.href);
        closeUrl.searchParams.delete('store_id');
        heading.append(link('Cerrar detalle', closeUrl.toString(), 'button'));
        section.append(heading);
        const list = node('dl');
        [
            ['Razón social', item.legal_name || '—'],
            ['RUT', item.rut || '—'],
            ['Correo', item.email || '—'],
            ['Teléfono', item.phone || '—'],
            ['Ubicación', [item.commune, item.city].filter(Boolean).join(', ') || '—'],
            ['Estado', labels[item.lifecycle_state]?.[0] || item.lifecycle_state],
            ['Actualizado', item.updated_at || '—'],
        ].forEach(([term, value]) => {
            list.append(node('dt', term), node('dd', value));
        });
        section.append(list);
        const edit = new URL(config.editUrl);
        edit.searchParams.set('id', String(item.id));
        section.append(link('Editar minimarket', edit.toString(), 'button button-primary'));
        return section;
    };

    const render = (payload, state) => {
        results.replaceChildren();
        pagination.replaceChildren();
        const items = Array.isArray(payload.data) ? payload.data.map(normalizeItem).filter(Boolean) : [];
        const meta = payload.meta || {};
        summary.textContent = `${Number(meta.total) || 0} minimarket${Number(meta.total) === 1 ? '' : 's'}`;

        const selected = items.find((item) => Number(item.id) === state.storeId);
        const detail = renderDetail(selected, state);
        if (detail) results.append(detail);

        if (items.length === 0) {
            const filtered = Boolean(state.search || state.lifecycle_state || state.status);
            const outOfRange = Number(meta.total_pages) > 0 && state.page > Number(meta.total_pages);
            const empty = node('div', undefined, 'va-stores__notice');
            empty.append(node('h2', outOfRange ? 'Página fuera de rango' : filtered ? 'No hay resultados' : 'Aún no hay minimarkets'));
            empty.append(node('p', outOfRange
                ? 'Vuelve a una página disponible.'
                : filtered ? 'Cambia o restablece los filtros.' : 'Crea el primer minimarket para comenzar.'));
            results.append(empty);
            if (!filtered && !outOfRange) empty.append(link('Crear minimarket', config.createUrl, 'button button-primary'));
            if (filtered) {
                const clear = node('button', 'Restablecer filtros', 'button');
                clear.type = 'button';
                clear.addEventListener('click', () => navigate({ search: '', lifecycle_state: '', status: '', sort: 'name_asc', page: 1, storeId: null }));
                empty.append(clear);
            }
            if (outOfRange) pagination.append(pageButton('Ir a la última página', Number(meta.total_pages), state));
            return;
        }

        const wrapper = node('div', undefined, 'va-stores__table-wrap');
        const table = node('table', undefined, 'widefat striped va-stores__table');
        const head = node('thead');
        const headingRow = node('tr');
        ['Minimarket', 'Identificación', 'Ubicación', 'Estado', 'Última actualización', 'Acciones'].forEach((title) => {
            const th = node('th', title);
            th.scope = 'col';
            headingRow.append(th);
        });
        head.append(headingRow);
        const body = node('tbody');
        items.forEach((item) => {
            const row = node('tr');
            const market = node('td');
            market.append(node('strong', item.business_name));
            if (item.legal_name && item.legal_name !== item.business_name) market.append(node('small', item.legal_name));
            const identity = node('td');
            identity.append(node('span', item.rut || 'Sin RUT'), node('small', item.email || item.phone || 'Sin contacto'));
            const location = node('td', [item.commune, item.city].filter(Boolean).join(', ') || 'Sin ubicación');
            const status = node('td');
            const [label, tone] = labels[item.lifecycle_state] || labels.invalid;
            status.append(node('span', label, `va-stores__badge va-stores__badge--${tone}`));
            status.append(node('small', `${item.status} · ${item.onboarding_status}`));
            const updated = node('td');
            const time = node('time', item.updated_at || '—');
            if (item.updated_at) time.dateTime = item.updated_at;
            updated.append(time);
            const actions = node('td', undefined, 'va-stores__actions');
            actions.append(link('Ver detalle', detailUrl(state, item.id)));
            const edit = new URL(config.editUrl);
            edit.searchParams.set('id', String(item.id));
            actions.append(link('Editar', edit.toString()));
            row.append(market, identity, location, status, updated, actions);
            body.append(row);
        });
        table.append(head, body);
        wrapper.append(table);
        results.append(wrapper);

        const totalPages = Number(meta.total_pages) || 0;
        if (totalPages > 1) {
            if (state.page > 1) pagination.append(pageButton('Anterior', state.page - 1, state));
            pagination.append(node('span', `Página ${state.page} de ${totalPages}`));
            if (state.page < totalPages) pagination.append(pageButton('Siguiente', state.page + 1, state));
        }
    };

    const pageButton = (text, page, state) => {
        const button = node('button', text, 'button');
        button.type = 'button';
        button.addEventListener('click', () => navigate({ ...state, page, storeId: null }));
        return button;
    };

    const showError = () => {
        results.replaceChildren();
        const error = node('div', undefined, 'notice notice-error va-stores__notice');
        error.append(node('p', 'No fue posible cargar los minimarkets. Puedes reintentar o restablecer los filtros.'));
        const retry = node('button', 'Reintentar', 'button button-primary');
        retry.type = 'button';
        retry.addEventListener('click', () => load(current()));
        error.append(retry);
        results.append(error);
        summary.textContent = '';
        results.focus({ preventScroll: true });
    };

    const syncFields = (state) => Object.entries(fields).forEach(([key, field]) => { field.value = state[key]; });

    const load = async (state) => {
        if (controller) controller.abort();
        controller = new AbortController();
        const requestId = ++latestRequest;
        root.setAttribute('aria-busy', 'true');
        results.replaceChildren(node('p', 'Cargando minimarkets…', 'va-stores__loading'));
        pagination.replaceChildren();
        try {
            const response = await fetch(requestUrl(state), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
                signal: controller.signal,
            });
            const payload = await response.json();
            if (requestId !== latestRequest) return;
            if (!response.ok || payload.success !== true) throw new Error('store_list_failed');
            render(payload, state);
            results.focus({ preventScroll: true });
        } catch (error) {
            if (requestId === latestRequest && error.name !== 'AbortError') showError();
        } finally {
            if (requestId === latestRequest) root.setAttribute('aria-busy', 'false');
        }
    };

    const navigate = (state, replace = false) => {
        setUrl(state, replace);
        syncFields(state);
        load(state);
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        navigate({
            search: fields.search.value.trim(),
            lifecycle_state: fields.lifecycle_state.value,
            status: fields.status.value,
            sort: fields.sort.value,
            page: 1,
            storeId: null,
        });
    });
    reset.addEventListener('click', () => navigate({ search: '', lifecycle_state: '', status: '', sort: 'name_asc', page: 1, storeId: null }));
    window.addEventListener('popstate', () => { const state = current(); syncFields(state); load(state); });
    results.addEventListener('click', (event) => {
        const anchor = event.target.closest('a');
        if (!anchor) return;
        const url = new URL(anchor.href);
        const id = Number(url.searchParams.get('store_id'));
        if (!id) return;
        event.preventDefault();
        navigate({ ...current(), storeId: id });
    });

    const initial = current();
    syncFields(initial);
    navigate(initial, true);
}

function normalizeItem(item) {
    const id = Number(item?.id);
    if (!Number.isSafeInteger(id) || id <= 0) return null;
    const text = (value) => typeof value === 'string' ? value : '';
    return {
        id,
        business_name: text(item.business_name),
        legal_name: text(item.legal_name),
        rut: text(item.rut),
        email: text(item.email),
        phone: text(item.phone),
        commune: text(item.commune),
        city: text(item.city),
        status: text(item.status),
        onboarding_status: text(item.onboarding_status),
        approved_at: item.approved_at === null ? null : text(item.approved_at),
        lifecycle_state: ['draft', 'in_review', 'rejected', 'approved_inactive', 'active', 'invalid'].includes(item.lifecycle_state)
            ? item.lifecycle_state
            : 'invalid',
        allowed_actions: Array.isArray(item.allowed_actions) ? [...item.allowed_actions] : [],
        created_at: text(item.created_at),
        updated_at: text(item.updated_at),
    };
}
