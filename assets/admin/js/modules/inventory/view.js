import {
    STATUS_EMPTY,
    STATUS_ERROR,
    STATUS_IDLE,
    STATUS_LOADING,
    STATUS_SUCCESS,
    FORM_CREATE,
    FORM_EDIT,
    VIEW_FORM,
} from './store.js';
import { createProductSelector } from './product-selector.js';

export function createInventoryView(nodes, actions) {
    const newButton = nodes.root.querySelector('.page-title-action');
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
    const contextPanel = document.createElement('div');
    contextPanel.className = 'veciahorra-inventory-admin__context';
    nodes.toolbar.replaceChildren(contextPanel, form, reloadButton);

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

    if (newButton) {
        newButton.addEventListener('click', actions.onNew);
    }

    const inventoryForm = createInventoryForm(actions);
    let focusedFormKey = null;

    function render(state) {
        if (state.currentView === VIEW_FORM) {
            nodes.toolbar.hidden = true;
            nodes.pagination.replaceChildren();
            nodes.table.classList.toggle(
                'is-loading',
                state.form.status === STATUS_LOADING
            );
            nodes.table.setAttribute(
                'aria-busy',
                state.form.status === STATUS_LOADING || state.form.isSaving
                    ? 'true'
                    : 'false'
            );
            nodes.table.replaceChildren(inventoryForm.element);
            renderFormMessage(nodes.messages, state.form);
            inventoryForm.render(state.form);
            setButtonDisabled(newButton, true);

            const focusKey = state.form.mode === FORM_CREATE
                ? 'create'
                : `edit-${state.form.inventoryId}`;

            if (
                focusedFormKey !== focusKey
                && state.form.status !== STATUS_LOADING
                && !(state.form.mode === FORM_EDIT && state.form.initialValues === null)
            ) {
                focusedFormKey = focusKey;
                queueMicrotask(() => inventoryForm.focusPrimary(state.form.mode));
            }
            return;
        }

        focusedFormKey = null;
        const loading = state.status === STATUS_LOADING;
        const hasFilters = Object.entries(state.inputs).some(([name, value]) => (
            !['page', 'perPage'].includes(name) && String(value).trim() !== ''
        ));

        nodes.toolbar.hidden = false;
        renderContext(contextPanel, state.context, actions);
        setButtonDisabled(newButton, loading);

        Object.entries(controls).forEach(([name, control]) => {
            const value = String(state.inputs[name]);

            if (control.element.value !== value) {
                control.element.value = value;
            }

            control.element.disabled = loading || (
                name === 'productId' && state.context.status === 'ready'
            );
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
    if (state.context.status === 'error') {
        renderContextError(nodes.table, state.context.message, actions.allInventoryUrl);
        return;
    }

    if (state.context.status === 'loading') {
        renderState(nodes.table, 'Cargando producto seleccionado...');
        return;
    }

    switch (state.status) {
        case STATUS_LOADING:
            renderState(nodes.table, 'Cargando inventario...');
            break;
        case STATUS_SUCCESS:
            renderTable(nodes.table, state.items, actions);
            break;
        case STATUS_EMPTY:
            renderState(
                nodes.table,
                state.context.status === 'ready'
                    ? 'Este producto todavia no tiene ofertas.'
                    : 'No hay registros de inventario para mostrar.',
                'veciahorra-inventory-admin__state--empty'
            );
            if (state.context.status === 'ready') {
                nodes.table.firstElementChild.append(
                    createLink(
                        'Crear primera oferta',
                        actions.contextualCreateUrl(state.context.product.id),
                        'button button-primary'
                    )
                );
            }
            break;
        case STATUS_ERROR:
            renderError(nodes, state.error, actions.onReload);
            break;
        case STATUS_IDLE:
        default:
            nodes.table.replaceChildren();
    }
}

function renderTable(container, items, actions) {
    const wrapper = document.createElement('div');
    wrapper.className = 'veciahorra-inventory-admin__table-scroll';
    const table = document.createElement('table');
    table.className = 'widefat fixed striped veciahorra-inventory-admin__items-table';
    const head = document.createElement('thead');
    const header = document.createElement('tr');

    ['ID', 'Product ID', 'Minimarket ID', 'Price', 'Stock', 'Status', 'Updated At', 'Acciones']
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
        const actionsCell = document.createElement('td');
        const edit = createButton('Editar', () => actions.onEdit(item.id));
        edit.classList.add('button-link');
        actionsCell.append(edit);
        row.append(actionsCell);
        body.append(row);
    });

    table.append(head, body);
    wrapper.append(table);
    container.replaceChildren(wrapper);
}

function createInventoryForm(actions) {
    const element = document.createElement('form');
    element.className = 'veciahorra-inventory-admin__form';
    const header = document.createElement('div');
    header.className = 'veciahorra-inventory-admin__form-header';
    const back = createButton('Volver al listado', actions.onCancel);
    back.classList.add('button-link');
    const title = document.createElement('h2');
    header.append(back, title);

    const fields = document.createElement('div');
    fields.className = 'veciahorra-inventory-admin__form-fields';
    const formState = document.createElement('div');
    formState.className = 'veciahorra-inventory-admin__state';
    const controls = {
        productId: createFormInput('productId', 'Product ID', 'number', '1'),
        minimarketId: createFormInput('minimarketId', 'Minimarket ID', 'number', '1'),
        price: createFormInput('price', 'Price', 'number', '0.01'),
        stock: createFormInput('stock', 'Stock', 'number', '1'),
        status: createFormStatus(),
    };
    const productSelector = createProductSelector(actions);

    Object.entries(controls).forEach(([name, control]) => {
        control.input.addEventListener('input', () => {
            actions.onFormField(name, control.input.value);
        });
        fields.append(control.wrapper);
    });
    fields.insertBefore(productSelector.element, controls.productId.wrapper);

    const buttons = document.createElement('div');
    buttons.className = 'veciahorra-inventory-admin__form-actions';
    const save = createButton('Guardar');
    save.type = 'submit';
    save.classList.add('button-primary');
    const cancel = createButton('Cancelar', actions.onCancel);
    buttons.append(save, cancel);
    const productContext = document.createElement('p');
    productContext.className = 'veciahorra-inventory-admin__context-product';
    element.append(header, productContext, formState, fields, buttons);
    element.addEventListener('submit', (event) => {
        event.preventDefault();
        actions.onSave();
    });

    function render(form) {
        const loading = form.status === STATUS_LOADING && !form.isSaving;
        const disabled = loading || form.isSaving;
        const detailUnavailable = form.mode === FORM_EDIT
            && form.initialValues === null
            && form.status === STATUS_ERROR;
        title.textContent = form.mode === FORM_CREATE
            ? 'Nuevo inventario'
            : `Editar inventario #${form.inventoryId}`;
        back.disabled = form.isSaving;
        cancel.disabled = disabled;
        save.disabled = disabled;
        save.textContent = form.isSaving ? 'Guardando...' : 'Guardar';
        formState.hidden = !loading && !detailUnavailable;
        formState.textContent = detailUnavailable
            ? 'No fue posible cargar el inventario.'
            : 'Cargando inventario...';
        fields.hidden = loading || detailUnavailable;
        buttons.hidden = loading || detailUnavailable;
        productSelector.render(form);
        controls.productId.wrapper.hidden = form.mode === FORM_CREATE;
        productContext.hidden = form.contextProduct === null;
        productContext.textContent = form.contextProduct === null
            ? ''
            : `Producto: ${form.contextProduct.name} (#${form.contextProduct.id}) â€” ${statusLabel(form.contextProduct.status)}`;

        Object.entries(controls).forEach(([name, control]) => {
            const value = String(form.values[name]);

            if (control.input.value !== value) {
                control.input.value = value;
            }

            control.input.disabled = disabled || (
                name === 'productId' && form.productLocked
            ) || (
                form.mode === FORM_EDIT
                && ['productId', 'minimarketId'].includes(name)
            );
            control.input.setAttribute(
                'aria-invalid',
                form.fieldErrors[name] ? 'true' : 'false'
            );
            control.error.textContent = form.fieldErrors[name] || '';
        });
    }

    function focusPrimary(mode) {
        if (mode === FORM_CREATE && !productSelector.element.hidden) {
            productSelector.focus();
            return;
        }
        const control = controls.price;
        if (control.input.isConnected) {
            control.input.focus();
        }
    }

    return { element, render, focusPrimary };
}

function renderContext(container, context, actions) {
    if (context.status !== 'ready') {
        container.replaceChildren();
        container.hidden = true;
        return;
    }

    const label = document.createElement('strong');
    label.textContent = `Ofertas de: ${context.product.name} (#${context.product.id})`;
    const status = document.createElement('span');
    status.textContent = ` Estado: ${statusLabel(context.product.status)}. `;
    const all = createLink('Ver todas las ofertas', actions.allInventoryUrl);
    const create = createLink(
        'Crear oferta',
        actions.contextualCreateUrl(context.product.id),
        'button button-secondary'
    );
    container.hidden = false;
    container.replaceChildren(label, status, all, create);
}

function renderContextError(container, message, allInventoryUrl) {
    const state = document.createElement('div');
    state.className = 'notice notice-error inline veciahorra-inventory-admin__notice';
    state.setAttribute('role', 'alert');
    state.tabIndex = -1;
    const text = document.createElement('p');
    text.textContent = message;
    state.append(text, createLink('Ver todas las ofertas', allInventoryUrl));
    container.replaceChildren(state);
    queueMicrotask(() => state.focus());
}

function createFormInput(name, label, type, step) {
    const control = createFormControl(name, label);
    const input = document.createElement('input');
    input.id = `veciahorra-inventory-${name}`;
    input.type = type;
    input.min = ['productId', 'minimarketId'].includes(name) ? '1' : '0';
    input.step = step;
    input.className = 'regular-text';
    control.label.htmlFor = input.id;
    control.wrapper.insertBefore(input, control.error);

    return { ...control, input };
}

function createFormStatus() {
    const control = createFormControl('status', 'Status');
    const input = document.createElement('select');
    input.id = 'veciahorra-inventory-status';
    [['active', 'Activo'], ['inactive', 'Inactivo']].forEach(([value, text]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        input.append(option);
    });
    control.label.htmlFor = input.id;
    control.wrapper.insertBefore(input, control.error);

    return { ...control, input };
}

function createFormControl(name, labelText) {
    const wrapper = document.createElement('div');
    wrapper.className = 'veciahorra-inventory-admin__form-field';
    const label = document.createElement('label');
    label.textContent = labelText;
    const error = document.createElement('p');
    error.className = 'veciahorra-inventory-admin__field-error';
    error.id = `veciahorra-inventory-${name}-error`;
    wrapper.append(label, error);

    return { wrapper, label, error };
}

function renderFormMessage(container, form) {
    if (!form.error && !form.message) {
        container.replaceChildren();
        return;
    }

    const notice = document.createElement('div');
    notice.className = form.error
        ? 'notice notice-error inline veciahorra-inventory-admin__notice'
        : 'notice notice-success inline veciahorra-inventory-admin__notice';
    const message = document.createElement('p');
    notice.setAttribute('role', form.error ? 'alert' : 'status');

    if (form.error) {
        notice.tabIndex = -1;
    }
    message.textContent = form.error?.message || form.message;
    notice.append(message);
    container.replaceChildren(notice);

    if (form.error) {
        queueMicrotask(() => notice.focus());
    }
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

function createLink(label, href, className = 'button-link') {
    const link = document.createElement('a');
    link.href = href;
    link.className = className;
    link.textContent = label;
    return link;
}

function setButtonDisabled(button, disabled) {
    if (!button) return;
    button.disabled = disabled;
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
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
    return ({ active: 'Activo', inactive: 'Inactivo', draft: 'Borrador' })[status]
        || status;
}
