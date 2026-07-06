import {
    FORM_MODE_CREATE,
    FORM_MODE_EDIT,
    FORM_MODE_READONLY,
    FORM_STATUS_ERROR,
    FORM_STATUS_LOADING,
    FORM_STATUS_SAVING,
    STATUS_EMPTY,
    STATUS_ERROR,
    STATUS_IDLE,
    STATUS_LOADING,
    STATUS_SUCCESS,
    VIEW_PRODUCT_FORM,
} from './store.js';

const FORM_FIELD_DEFINITIONS = [
    { name: 'name', label: 'Nombre', type: 'text', required: true, maxLength: 180 },
    { name: 'sku', label: 'SKU', type: 'text', maxLength: 100 },
    { name: 'description', label: 'Descripción', type: 'textarea' },
    { name: 'wooProductId', label: 'WooCommerce ID', type: 'number' },
    { name: 'categoryId', label: 'Categoría', type: 'number' },
    { name: 'brandId', label: 'Marca', type: 'number' },
    { name: 'unitId', label: 'Unidad', type: 'number' },
    { name: 'imageId', label: 'Imagen', type: 'number' },
];

/**
 * Crea la vista de la lista de productos sobre el shell administrativo.
 */
export function createProductsView(nodes, actions) {
    const newProductButton = nodes.root.querySelector('.page-title-action');
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
    let lastView = null;
    const productForm = createProductForm(actions);

    if (newProductButton) {
        newProductButton.addEventListener('click', () => emit(actions.onNew));
    }

    function render(state) {
        if (state.currentView !== lastView) {
            lastView = state.currentView;
            lastContentKey = null;
            lastPaginationKey = null;
        }

        if (state.currentView === VIEW_PRODUCT_FORM) {
            renderProductFormView(
                nodes,
                productForm,
                state,
                newProductButton
            );
            return;
        }

        const loading = state.status === STATUS_LOADING;
        const hasSearch = state.inputTerm !== '' || state.query.term !== '';

        nodes.toolbar.hidden = false;
        nodes.table.setAttribute('aria-label', 'Tabla de productos');
        setButtonAvailability(
            newProductButton,
            loading || typeof actions.onNew !== 'function'
        );

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
            renderContent(nodes, state, actions);
        }

        const paginationKey = createPaginationKey(state);

        if (paginationKey !== lastPaginationKey) {
            lastPaginationKey = paginationKey;
            renderPagination(nodes.pagination, state, actions);
        }
    }

    return { render };
}

function renderProductFormView(nodes, productForm, state, newProductButton) {
    const busy = [FORM_STATUS_LOADING, FORM_STATUS_SAVING]
        .includes(state.form.status);

    nodes.toolbar.hidden = true;
    nodes.pagination.replaceChildren();
    nodes.table.classList.toggle(
        'is-loading',
        state.form.status === FORM_STATUS_LOADING
    );
    nodes.table.setAttribute('aria-busy', busy ? 'true' : 'false');
    nodes.table.setAttribute('aria-label', formTitle(state.form.mode));
    setButtonAvailability(newProductButton, true);

    if (nodes.table.firstElementChild !== productForm.element) {
        nodes.table.replaceChildren(productForm.element);
    }

    renderFormMessage(nodes.messages, state.form);
    productForm.render(state.form);
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

function renderContent(nodes, state, actions) {
    switch (state.status) {
        case STATUS_LOADING:
            renderLoading(nodes.table);
            break;
        case STATUS_SUCCESS:
            renderTable(nodes.table, state.products, actions);
            break;
        case STATUS_EMPTY:
            renderEmpty(nodes.table, state.query.term);
            break;
        case STATUS_ERROR:
            renderError(nodes, state.error, actions.onReload);
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

function renderTable(container, products, actions) {
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
        appendProductNameCell(row, product, actions);
        appendCell(row, product.sku, 'veciahorra-products-admin__column-sku');
        appendCell(row, statusLabel(product.status), 'veciahorra-products-admin__column-status');
        appendCell(row, product.updatedAt, 'veciahorra-products-admin__column-updated');
        body.append(row);
    });

    table.append(head, body);
    wrapper.append(table);
    container.replaceChildren(wrapper);
}

function appendProductNameCell(row, product, actions) {
    const cell = document.createElement('td');
    cell.className = 'veciahorra-products-admin__column-name';

    const name = document.createElement('strong');
    name.textContent = product.name;
    cell.append(name);

    if (typeof actions.onEdit === 'function') {
        const rowActions = document.createElement('div');
        rowActions.className = 'row-actions';
        const edit = createButton(
            'Editar',
            () => emit(actions.onEdit, product.id)
        );
        edit.classList.add('button-link');
        rowActions.append(edit);
        cell.append(rowActions);
    }

    row.append(cell);
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

function createProductForm(actions) {
    const element = document.createElement('form');
    element.className = 'veciahorra-products-admin__product-form';
    element.noValidate = true;

    const header = document.createElement('header');
    header.className = 'veciahorra-products-admin__form-header';

    const headerBack = createButton(
        '← Volver al listado',
        () => emit(actions.onBack)
    );
    headerBack.classList.add(
        'button',
        'button-link',
        'veciahorra-products-admin__form-back'
    );

    const headingGroup = document.createElement('div');
    headingGroup.className = 'veciahorra-products-admin__form-heading';

    const heading = document.createElement('h2');
    heading.className = 'veciahorra-products-admin__form-title';

    const productName = document.createElement('p');
    productName.className = 'veciahorra-products-admin__form-product-name';

    const status = document.createElement('span');
    status.className = 'veciahorra-products-admin__product-status';
    headingGroup.append(heading, productName, status);
    header.append(headerBack, headingGroup);

    const loading = document.createElement('div');
    loading.className = 'veciahorra-products-admin__state';
    loading.textContent = 'Cargando producto…';

    const fields = document.createElement('div');
    fields.className = 'veciahorra-products-admin__form-fields';
    const controls = new Map();

    const mainCard = createFormCard('Información principal');
    const technicalCard = createFormCard('Datos técnicos');
    const mainFields = new Set(['name', 'sku', 'description']);

    FORM_FIELD_DEFINITIONS.forEach((definition) => {
        const control = createFormControl(definition, actions);
        controls.set(definition.name, control);
        const card = mainFields.has(definition.name)
            ? mainCard
            : technicalCard;
        card.body.append(control.wrapper);
    });
    fields.append(mainCard.element, technicalCard.element);

    const buttons = document.createElement('div');
    buttons.className = 'veciahorra-products-admin__form-actions';
    const primaryActions = document.createElement('div');
    primaryActions.className = 'veciahorra-products-admin__form-actions-primary';
    const secondaryActions = document.createElement('div');
    secondaryActions.className = 'veciahorra-products-admin__form-actions-secondary';

    const save = createButton('Guardar cambios');
    save.type = 'submit';
    save.classList.add('button', 'button-primary');
    const activate = createButton(
        'Activar',
        () => emit(actions.onStatus, 'active')
    );
    activate.classList.add('button', 'button-secondary');
    const deactivate = createButton(
        'Desactivar',
        () => emit(actions.onStatus, 'inactive')
    );
    deactivate.classList.add('button', 'button-secondary');
    const back = createButton('Volver', () => emit(actions.onBack));
    back.classList.add('button');

    primaryActions.append(save);
    secondaryActions.append(back, activate, deactivate);
    buttons.append(primaryActions, secondaryActions);
    element.append(header, loading, fields, buttons);
    element.addEventListener('submit', (event) => {
        event.preventDefault();
        emit(actions.onSave);
    });

    function render(form) {
        const isLoading = form.status === FORM_STATUS_LOADING;
        const isSaving = form.status === FORM_STATUS_SAVING;
        const readonly = form.mode === FORM_MODE_READONLY;
        const detailUnavailable = (
            form.mode === FORM_MODE_EDIT
            && form.initialValues === null
            && form.status === FORM_STATUS_ERROR
        );
        const hasReliableStatus = (
            form.mode === FORM_MODE_CREATE
            || form.initialValues !== null
        ) && !isLoading && !detailUnavailable;
        const editable = !isLoading && !isSaving && !readonly
            && !detailUnavailable;

        heading.textContent = formTitle(form.mode);
        productName.textContent = form.values.name ?? '';
        productName.hidden = productName.textContent === '';
        status.textContent = statusLabel(form.productStatus);
        status.dataset.status = form.productStatus;
        status.hidden = !hasReliableStatus;
        loading.hidden = !isLoading;
        fields.hidden = isLoading || detailUnavailable;

        controls.forEach((control, field) => {
            const value = form.values[field] ?? '';

            if (control.input.value !== value) {
                control.input.value = value;
            }

            const error = form.fieldErrors[field] ?? '';
            control.error.textContent = error;
            control.error.hidden = error === '';
            control.input.disabled = !editable;
            control.input.setAttribute(
                'aria-invalid',
                error === '' ? 'false' : 'true'
            );
        });

        save.textContent = isSaving ? 'Guardando…' : 'Guardar cambios';
        save.hidden = readonly || detailUnavailable;
        save.disabled = !editable
            || (form.mode === FORM_MODE_EDIT && !form.dirty);

        const canChangeStatus = (
            form.mode === FORM_MODE_EDIT
            && form.initialValues !== null
            && !isSaving
            && !isLoading
        );
        const hideStatusActions = form.mode !== FORM_MODE_EDIT
            || readonly
            || detailUnavailable;
        activate.hidden = hideStatusActions;
        deactivate.hidden = hideStatusActions;
        activate.disabled = !canChangeStatus
            || form.productStatus === 'active';
        deactivate.disabled = !canChangeStatus
            || form.productStatus === 'inactive';
        back.disabled = isSaving;
        headerBack.disabled = isSaving;
    }

    return { element, render };
}

function createFormCard(title) {
    const element = document.createElement('section');
    element.className = 'veciahorra-products-admin__form-card';

    const heading = document.createElement('h3');
    heading.className = 'veciahorra-products-admin__form-card-title';
    heading.textContent = title;

    const body = document.createElement('div');
    body.className = 'veciahorra-products-admin__form-card-fields';

    element.append(heading, body);

    return { element, body };
}

function createFormControl(definition, actions) {
    const wrapper = document.createElement('div');
    wrapper.className = 'veciahorra-products-admin__form-field';

    const id = `veciahorra-product-${definition.name}`;
    const label = document.createElement('label');
    label.htmlFor = id;
    label.textContent = definition.required
        ? `${definition.label} *`
        : definition.label;

    const input = definition.type === 'textarea'
        ? document.createElement('textarea')
        : document.createElement('input');
    input.id = id;
    input.name = definition.name;
    input.className = 'regular-text';

    if (definition.type !== 'textarea') {
        input.type = definition.type;
    }

    if (definition.type === 'number') {
        input.min = '1';
        input.step = '1';
        input.inputMode = 'numeric';
    }

    input.required = definition.required === true;

    if (definition.maxLength) {
        input.maxLength = definition.maxLength;
    }

    const error = document.createElement('p');
    error.id = `${id}-error`;
    error.className = 'veciahorra-products-admin__field-error';
    error.hidden = true;
    input.setAttribute('aria-describedby', error.id);
    input.addEventListener('input', () => {
        emit(actions.onFormField, definition.name, input.value);
    });

    wrapper.append(label, input, error);

    return { wrapper, input, error };
}

function renderFormMessage(container, form) {
    container.replaceChildren();

    const message = form.error?.message || form.message;

    if (!message) {
        return;
    }

    const notice = document.createElement('div');
    notice.className = form.error
        ? 'notice notice-error inline veciahorra-products-admin__notice'
        : 'notice notice-success inline veciahorra-products-admin__notice';
    const text = document.createElement('p');
    text.textContent = message;
    notice.append(text);
    container.replaceChildren(notice);
}

function formTitle(mode) {
    const titles = {
        [FORM_MODE_CREATE]: 'Nuevo producto',
        [FORM_MODE_EDIT]: 'Editar producto',
        [FORM_MODE_READONLY]: 'Ver producto',
    };

    return titles[mode] || 'Producto';
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

    if (typeof handler === 'function') {
        button.addEventListener('click', handler);
    }

    return button;
}

function setButtonAvailability(button, disabled) {
    if (!button) {
        return;
    }

    button.disabled = disabled;
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
}

function emit(callback, ...args) {
    if (typeof callback === 'function') {
        callback(...args);
    }
}
