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
export function createProductsView(nodes, onReload) {
    const reloadButton = createButton('Recargar', onReload);
    reloadButton.classList.add('button', 'veciahorra-products-admin__reload');
    nodes.toolbar.replaceChildren(reloadButton);

    function render(state) {
        const loading = state.status === STATUS_LOADING;
        reloadButton.disabled = loading;
        nodes.table.classList.toggle('is-loading', loading);
        nodes.table.setAttribute('aria-busy', loading ? 'true' : 'false');
        nodes.messages.replaceChildren();
        nodes.pagination.replaceChildren();

        switch (state.status) {
            case STATUS_LOADING:
                renderLoading(nodes.table);
                break;
            case STATUS_SUCCESS:
                renderTable(nodes.table, state.products);
                break;
            case STATUS_EMPTY:
                renderEmpty(nodes.table);
                break;
            case STATUS_ERROR:
                renderError(nodes, state.error, onReload);
                break;
            case STATUS_IDLE:
            default:
                nodes.table.replaceChildren();
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

function renderEmpty(container) {
    const state = document.createElement('div');
    state.className = 'veciahorra-products-admin__state veciahorra-products-admin__state--empty';
    state.textContent = 'No hay productos para mostrar.';
    container.replaceChildren(state);
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
