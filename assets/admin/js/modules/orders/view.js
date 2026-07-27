const labels = Object.freeze({ reserved: 'Reservado', paid: 'Pagado', delivered: 'Entregado', pickup: 'Retiro', delivery: 'Despacho', consistent: 'Consistente', inconsistent: 'Inconsistente' });
const text = (value, fallback = '—') => typeof value === 'string' && value !== '' ? value : fallback;

export function createOrdersView(nodes, actions) {
    nodes.form.addEventListener('submit', submit);
    nodes.clear.addEventListener('click', clear);
    nodes.pagination.addEventListener('click', page);
    function submit(event) { event.preventDefault(); actions.query(formQuery(nodes.form)); }
    function clear() { nodes.form.reset(); actions.query(formQuery(nodes.form)); }
    function page(event) {
        const button = event.target.closest('button[data-page]');
        if (button) actions.page(Number(button.dataset.page));
    }
    return {
        render(state) {
            nodes.list.setAttribute('aria-busy', String(state.status === 'loading'));
            nodes.status.textContent = message(state);
            if (state.status === 'success') {
                renderItems(nodes.list, state.data.items, state.query, actions.detailUrl);
            }
            if (state.status === 'empty') nodes.list.replaceChildren(empty(state.query));
            if (state.status === 'error') nodes.list.replaceChildren(errorNode(state.error));
            if (state.data) renderPagination(nodes.pagination, state.data.pagination);
        },
        sync(query) {
            Object.entries(query).forEach(([name, value]) => {
                const field = nodes.form.elements.namedItem(name);
                if (field) field.value = String(value ?? '');
            });
        },
        destroy() {
            nodes.form.removeEventListener('submit', submit);
            nodes.clear.removeEventListener('click', clear);
            nodes.pagination.removeEventListener('click', page);
        },
    };
}

function renderItems(root, items, query, detailUrl) {
    const list = document.createElement('ol'); list.className = 'orders-list';
    items.forEach((item) => {
        const row = document.createElement('li'); row.className = 'orders-list__item';
        const title = document.createElement('h2'); title.textContent = `Order #${item.id}`;
        const store = document.createElement('p'); store.textContent = `Store: ${text(item.store?.business_name, `#${item.store?.id ?? '—'}`)}`;
        const summary = document.createElement('p'); summary.textContent = `${text(item.created_at)} · ${text(item.currency, 'CLP')} ${text(item.total, '0')} · ${item.line_count ?? 0} líneas / ${item.unit_count ?? 0} unidades`;
        const states = document.createElement('p'); states.className = 'orders-list__states';
        states.textContent = `${label(item.primary_state)} · Financiero: ${label(item.dimensions?.financial)} · Fulfillment: ${label(item.dimensions?.fulfillment)} · ${label(item.consistency_state)}`;
        const attention = document.createElement('p');
        attention.textContent = item.requires_attention ? `Requiere atención: ${item.warning_count ?? 0} warnings, ${item.blocker_count ?? 0} blockers` : 'Sin atención requerida';
        const action = viewAction(item, query, detailUrl);
        row.append(title, store, summary, states, attention);
        if (action !== null) row.append(action);
        list.append(row);
    });
    root.replaceChildren(list);
}
function viewAction(item, query, detailUrl) {
    if (
        item === null || typeof item !== 'object' || Array.isArray(item)
        || !Number.isSafeInteger(item.id) || item.id <= 0
        || !Array.isArray(item.allowed_actions)
        || item.allowed_actions.length !== 1
        || item.allowed_actions[0] !== 'view'
        || typeof detailUrl !== 'function'
    ) return null;
    const href = detailUrl(item.id, query);
    if (typeof href !== 'string' || href === '') return null;
    const link = document.createElement('a');
    link.className = 'orders-list__view';
    link.href = href;
    link.textContent = 'Ver';
    return link;
}
function renderPagination(root, pagination) {
    root.replaceChildren();
    [['Anterior', pagination.previous_page], ['Siguiente', pagination.next_page]].forEach(([labelText, number]) => {
        const enabled = Number.isInteger(number) && number > 0;
        const button = document.createElement('button'); button.type = 'button'; button.dataset.page = String(number); button.disabled = !enabled; button.textContent = labelText; root.append(button);
    });
    const current = document.createElement('span'); current.setAttribute('aria-current', 'page'); current.textContent = `Página ${pagination.page} de ${pagination.total_pages}`; root.insertBefore(current, root.children[1]);
}
function message(state) {
    if (state.status === 'loading') return 'Cargando pedidos…';
    if (state.status === 'empty') return hasFilters(state.query) ? 'No existen coincidencias.' : 'No existen pedidos.';
    if (state.status === 'error') return safeError(state.error);
    return `${state.data.pagination.total} pedidos.`;
}
function safeError(error) {
    return ({ 401: 'La sesión expiró.', 403: 'No tienes permisos.', 422: 'Los filtros no son válidos.', 500: 'No fue posible cargar los pedidos.' })[error?.status]
        || (error?.kind === 'network' ? 'No fue posible conectar con el servidor.' : 'La respuesta del servidor no es válida.');
}
function empty(query) { const node = document.createElement('p'); node.textContent = hasFilters(query) ? 'Prueba con otros filtros.' : 'Aún no hay pedidos.'; return node; }
function errorNode(error) { const node = document.createElement('p'); node.tabIndex = -1; node.textContent = safeError(error); return node; }
function hasFilters(query) { return Boolean(query.search || query.store_id || query.order_status || query.fulfillment_mode || query.date_from || query.date_to); }
function label(value) { return labels[value] || text(value, 'Desconocido'); }
function formQuery(form) { const data = new FormData(form); return Object.fromEntries([...data.entries()].map(([key, value]) => [key, String(value)])); }
