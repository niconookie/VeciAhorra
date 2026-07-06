import {
    STATUS_EMPTY,
    STATUS_ERROR,
    STATUS_IDLE,
    STATUS_LOADING,
    STATUS_SUCCESS,
} from './store.js';

export function createInventoryView(nodes, actions) {
    const form = document.createElement('form');
    form.className = 'veciahorra-inventory-admin__filters';

    const search = createInput('search', 'Buscar', 'Buscar inventario');
    const productId = createInput('productId', 'Product ID', 'Product ID', 'number');
    const minimarketId = createInput(
        'minimarketId',
        'Minimarket ID',
        'Minimarket ID',
        'number'
    );
    const status = createStatusSelect();
    const perPage = createPerPageSelect();
    const searchButton = createButton('Buscar');
    searchButton.type = 'submit';
    searchButton.classList.add('button-primary');
    const clearButton = createButton('Limpiar', actions.onClear);
    const reloadButton = createButton('Recargar', actions.onReload);

    form.append(
        search.wrapper,
        productId.wrapper,
        minimarketId.wrapper,
        status.wrapper,
        perPage.wrapper,
        searchButton,
        clearButton
    );
    reloadButton.classList.add('veciahorra-inventory-admin__reload');
    nodes.toolbar.replaceChildren(form, reloadButton);

    const controls = { search, productId, minimarketId, status, perPage };

    Object.entries(controls).forEach(([name, control]) => {
        control.element.addEventListener('input', () => {
            actions.onFilter(name, control.element.value);
        });
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        actions.onSearch();
    });

    function render(state) {
        const loading = state.status === STATUS_LOADING;
        const hasFilters = Object.entries(state.inputs).some(([name, value]) => (
            !['page', 'perPage'].includes(name) && String(value).trim() !== ''
        ));

        Object.entries(controls).forEach(([name, control]) => {
            const value = String(state.inputs[name]);

            if (control.element.value !== value) {
                control.element.value = value;
            }

            control.element.disabled = loading;
        });

        searchButton.disabled = loading;
        clearButton.disabled = loading || !hasFilters;
        reloadButton.disabled = loading;
        nodes.table.classList.toggle('is-loading', loading);
        nodes.table.setAttribute('aria-busy', loading ? 'true' : 'false');
        nodes.messages.replaceChildren();
        renderContent(nodes, state, actions);
        renderPagination(nodes.pagination, state, actions);
    }

    return { render };
}

function renderContent(nodes, state, actions) {
    switch (state.status) {
        case STATUS_LOADING:
            renderState(nodes.table, 'Cargando inventario...');
            break;
        case STATUS_SUCCESS:
            renderTable(nodes.table, state.items);
            break;
        case STATUS_EMPTY:
            renderState(
                nodes.table,
                'No hay registros de inventario para mostrar.',
                'veciahorra-inventory-admin__state--empty'
            );
            break;
        case STATUS_ERROR:
            renderError(nodes, state.error, actions.onReload);
            break;
        case STATUS_IDLE:
        default:
            nodes.table.replaceChildren();
    }
}

function renderTable(container, items) {
    const wrapper = document.createElement('div');
    wrapper.className = 'veciahorra-inventory-admin__table-scroll';
    const table = document.createElement('table');
    table.className = 'widefat fixed striped veciahorra-inventory-admin__items-table';
    const head = document.createElement('thead');
    const header = document.createElement('tr');

    ['ID', 'Product ID', 'Minimarket ID', 'Price', 'Stock', 'Status', 'Updated At']
        .forEach((label) => {
            const cell = document.createElement('th');
            cell.scope = 'col';
            cell.textContent = label;
            header.append(cell);
        });
    head.append(header);

    const body = document.createElement('tbody');

    items.forEach((item) => {
        const row = document.createElement('tr');
        appendCell(row, item.id);
        appendCell(row, item.productId);
        appendCell(row, item.minimarketId);
        appendCell(row, formatPrice(item.price));
        appendCell(row, item.stock);
        appendCell(row, statusLabel(item.status));
        appendCell(row, item.updatedAt);
        body.append(row);
    });

    table.append(head, body);
    wrapper.append(table);
    container.replaceChildren(wrapper);
}

function renderPagination(container, state, actions) {
    if (
        ![STATUS_SUCCESS, STATUS_EMPTY].includes(state.status)
        || state.meta === null
    ) {
        container.replaceChildren();
        return;
    }

    const summary = document.createElement('span');
    summary.textContent = state.meta.total === 1
        ? '1 registro'
        : `${state.meta.total} registros`;

    if (state.meta.totalPages === 0) {
        container.replaceChildren(summary);
        return;
    }

    const controls = document.createElement('div');
    controls.className = 'veciahorra-inventory-admin__pagination-controls';
    const previous = createButton('Anterior', () => actions.onPage(state.meta.page - 1));
    previous.disabled = state.meta.page <= 1;
    const indicator = document.createElement('span');
    indicator.textContent = `Pagina ${state.meta.page} de ${state.meta.totalPages}`;
    const next = createButton('Siguiente', () => actions.onPage(state.meta.page + 1));
    next.disabled = state.meta.page >= state.meta.totalPages;
    controls.append(previous, indicator, next);
    container.replaceChildren(summary, controls);
}

function renderError(nodes, error, onReload) {
    const notice = document.createElement('div');
    notice.className = 'notice notice-error inline veciahorra-inventory-admin__notice';
    const message = document.createElement('p');
    message.textContent = error?.message || 'No fue posible cargar el inventario.';
    const retry = createButton('Reintentar', onReload);
    notice.append(message, retry);
    nodes.messages.replaceChildren(notice);
    renderState(
        nodes.table,
        'La lista de inventario no esta disponible.',
        'veciahorra-inventory-admin__state--error'
    );
}

function renderState(container, message, modifier = '') {
    const element = document.createElement('div');
    element.className = ['veciahorra-inventory-admin__state', modifier]
        .filter(Boolean)
        .join(' ');
    element.textContent = message;
    container.replaceChildren(element);
}

function createInput(name, label, placeholder, type = 'search') {
    const wrapper = document.createElement('label');
    wrapper.className = 'veciahorra-inventory-admin__filter';
    const caption = document.createElement('span');
    caption.textContent = label;
    const element = document.createElement('input');
    element.name = name;
    element.type = type;
    element.placeholder = placeholder;
    element.className = 'regular-text';

    if (type === 'number') {
        element.min = '1';
        element.step = '1';
    }

    wrapper.append(caption, element);

    return { wrapper, element };
}

function createStatusSelect() {
    return createSelect('status', 'Status', [
        ['', 'Todos'],
        ['active', 'Activo'],
        ['inactive', 'Inactivo'],
    ]);
}

function createPerPageSelect() {
    return createSelect('perPage', 'Por pagina', [
        ['20', '20'],
        ['50', '50'],
        ['100', '100'],
    ]);
}

function createSelect(name, label, options) {
    const wrapper = document.createElement('label');
    wrapper.className = 'veciahorra-inventory-admin__filter';
    const caption = document.createElement('span');
    caption.textContent = label;
    const element = document.createElement('select');
    element.name = name;

    options.forEach(([value, text]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        element.append(option);
    });
    wrapper.append(caption, element);

    return { wrapper, element };
}

function createButton(label, callback = null) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button';
    button.textContent = label;

    if (typeof callback === 'function') {
        button.addEventListener('click', callback);
    }

    return button;
}

function appendCell(row, value) {
    const cell = document.createElement('td');
    cell.textContent = String(value);
    row.append(cell);
}

function formatPrice(value) {
    return Number(value).toLocaleString('es-CL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusLabel(status) {
    return status === 'active' ? 'Activo' : 'Inactivo';
}
