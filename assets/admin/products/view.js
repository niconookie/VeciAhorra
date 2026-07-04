import {
    STATUS_EMPTY,
    STATUS_ERROR,
    STATUS_IDLE,
    STATUS_LOADING,
    STATUS_SUCCESS,
} from './store.js';

/**
 * Crea la vista de la lista de productos sobre el shell administrativo.
 */
export function createProductsView(nodes, actions) {
    const searchForm = document.createElement('form');
    searchForm.className = 'veciahorra-products-admin__search';
    searchForm.setAttribute('role', 'search');

    const searchLabel = document.createElement('label');
    searchLabel.className = 'screen-reader-text';
    searchLabel.textContent = 'Buscar productos';
    searchLabel.htmlFor = 'veciahorra-products-search';

    const searchInput = document.createElement('input');
    searchInput.id = 'veciahorra-products-search';
    searchInput.type = 'search';
    searchInput.className = 'regular-text veciahorra-products-admin__search-input';
    searchInput.placeholder = 'Buscar productos';
    searchInput.setAttribute('aria-label', 'Buscar productos');

    const searchButton = createButton('Buscar', () => {});
    searchButton.type = 'submit';
    searchButton.classList.add('button', 'button-primary');

    const clearButton = createButton('Limpiar', actions.onClear);
    clearButton.classList.add('button', 'button-secondary');

    searchForm.append(searchLabel, searchInput, searchButton, clearButton);

    const reloadButton = createButton('Recargar', actions.onReload);
    reloadButton.classList.add('button', 'veciahorra-products-admin__reload');
    nodes.toolbar.replaceChildren(searchForm, reloadButton);

    searchInput.addEventListener('input', () => {
        actions.onInputTerm(searchInput.value);
    });
    searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        actions.onSearch();
    });

    let lastContentKey = null;
    let lastPaginationKey = null;

    function render(state) {
        const loading = state.status === STATUS_LOADING;
        const hasSearch = state.inputTerm !== '' || state.query.term !== '';

        if (searchInput.value !== state.inputTerm) {
            searchInput.value = state.inputTerm;
        }

        searchInput.disabled = loading;
        searchButton.disabled = loading;
        clearButton.disabled = loading || !hasSearch;
        reloadButton.disabled = loading;
        nodes.table.classList.toggle('is-loading', loading);
        nodes.table.setAttribute('aria-busy', loading ? 'true' : 'false');

        const contentKey = createContentKey(state);

        if (contentKey !== lastContentKey) {
            lastContentKey = contentKey;
            nodes.messages.replaceChildren();
            renderContent(nodes, state, actions.onReload);
        }

        const paginationKey = createPaginationKey(state);

        if (paginationKey !== lastPaginationKey) {
            lastPaginationKey = paginationKey;
            renderPagination(nodes.pagination, state, actions);
        }
    }

    return { render };
}

function renderLoading(container) {
    const state = document.createElement('div');
    state.className = 'veciahorra-products-admin__state';
    state.textContent = 'Cargando productos…';
    container.replaceChildren(state);
}

function renderEmpty(container, term) {
    const state = document.createElement('div');
    state.className = 'veciahorra-products-admin__state veciahorra-products-admin__state--empty';
    state.textContent = term === ''
        ? 'No hay productos para mostrar.'
        : `No se encontraron productos para «${term}».`;
    container.replaceChildren(state);
}

function renderContent(nodes, state, onReload) {
    switch (state.status) {
        case STATUS_LOADING:
            renderLoading(nodes.table);
            break;
        case STATUS_SUCCESS:
            renderTable(nodes.table, state.products);
            break;
        case STATUS_EMPTY:
            renderEmpty(nodes.table, state.query.term);
            break;
        case STATUS_ERROR:
            renderError(nodes, state.error, onReload);
            break;
        case STATUS_IDLE:
        default:
            nodes.table.replaceChildren();
    }
}

function renderError(nodes, error, onReload) {
    const notice = document.createElement('div');
    notice.className = 'notice notice-error inline veciahorra-products-admin__notice';

    const message = document.createElement('p');
    message.textContent = error?.message || 'No fue posible cargar los productos.';

    const retry = createButton('Reintentar', onReload);
    retry.classList.add('button', 'button-secondary');

    notice.append(message, retry);
    nodes.messages.replaceChildren(notice);

    const state = document.createElement('div');
    state.className = 'veciahorra-products-admin__state veciahorra-products-admin__state--error';
    state.textContent = 'La lista de productos no está disponible.';
    nodes.table.replaceChildren(state);
}

function renderTable(container, products) {
    const wrapper = document.createElement('div');
    wrapper.className = 'veciahorra-products-admin__table-scroll';

    const table = document.createElement('table');
    table.className = 'widefat fixed striped veciahorra-products-admin__products-table';

    const head = document.createElement('thead');
    const headerRow = document.createElement('tr');
    ['ID', 'Nombre', 'SKU', 'Estado', 'Actualizado'].forEach((label) => {
        const cell = document.createElement('th');
        cell.scope = 'col';
        cell.textContent = label;
        headerRow.append(cell);
    });
    head.append(headerRow);

    const body = document.createElement('tbody');
    products.forEach((product) => {
        const row = document.createElement('tr');
        appendCell(row, product.id, 'veciahorra-products-admin__column-id');
        appendCell(row, product.name, 'veciahorra-products-admin__column-name');
        appendCell(row, product.sku, 'veciahorra-products-admin__column-sku');
        appendCell(row, statusLabel(product.status), 'veciahorra-products-admin__column-status');
        appendCell(row, product.updatedAt, 'veciahorra-products-admin__column-updated');
        body.append(row);
    });

    table.append(head, body);
    wrapper.append(table);
    container.replaceChildren(wrapper);
}

function renderPagination(container, state, actions) {
    if (
        (state.status !== STATUS_SUCCESS && state.status !== STATUS_EMPTY)
        || state.meta === null
    ) {
        container.replaceChildren();
        return;
    }

    const summary = document.createElement('span');
    summary.className = 'veciahorra-products-admin__results-count';
    summary.textContent = state.meta.total === 1
        ? '1 producto'
        : `${state.meta.total} productos`;

    if (state.meta.totalPages === 0) {
        container.replaceChildren(summary);
        return;
    }

    const controls = document.createElement('div');
    controls.className = 'veciahorra-products-admin__pagination-controls';

    const previous = createButton(
        'Anterior',
        () => actions.onPage(state.meta.page - 1)
    );
    previous.classList.add('button');
    previous.disabled = state.meta.page <= 1;

    const indicator = document.createElement('span');
    indicator.className = 'veciahorra-products-admin__page-indicator';
    indicator.textContent = `Página ${state.meta.page} de ${state.meta.totalPages}`;

    const next = createButton(
        'Siguiente',
        () => actions.onPage(state.meta.page + 1)
    );
    next.classList.add('button');
    next.disabled = state.meta.page >= state.meta.totalPages;

    controls.append(previous, indicator, next);
    container.replaceChildren(summary, controls);
}

function createContentKey(state) {
    return JSON.stringify({
        status: state.status,
        term: state.query.term,
        products: state.products,
        error: state.error,
    });
}

function createPaginationKey(state) {
    return JSON.stringify({
        status: state.status,
        meta: state.meta,
    });
}

function appendCell(row, value, className) {
    const cell = document.createElement('td');
    cell.className = className;
    cell.textContent = value;
    row.append(cell);
}

function statusLabel(status) {
    const labels = {
        active: 'Activo',
        inactive: 'Inactivo',
        draft: 'Borrador',
    };

    return labels[status] || status;
}

function createButton(label, handler) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', handler);
    return button;
}
