import {
    STATUS_EMPTY,
    STATUS_ERROR,
    STATUS_IDLE,
    STATUS_LOADING,
    STATUS_SUCCESS,
    FORM_CREATE,
    FORM_EDIT,
    VIEW_FORM,
    VIEW_DETAIL,
} from './store.js';
import { createProductSelector } from './product-selector.js';
import { createStoreSelector } from './store-selector.js';
import { safeAdministrativeRoute } from './list-navigation.js';
import { renderInventoryDetail } from './detail-view.js';

export function createInventoryView(nodes, actions) {
    const newButton = nodes.root.querySelector('.page-title-action');
    const pageHeading = nodes.root.querySelector('h1');
    const form = document.createElement('form');
    form.className = 'veciahorra-inventory-admin__filters';

    const search = createInput(
        'search',
        'Buscar ofertas',
        'Product, SKU, Store o ID'
    );
    const status = createStatusSelect();
    const availability = createAvailabilitySelect();
    const cause = createCauseSelect();
    const orderBy = createOrderSelect();
    const direction = createDirectionSelect();
    const searchButton = createButton('Buscar');
    searchButton.type = 'submit';
    searchButton.classList.add('button-primary');
    const clearButton = createButton('Limpiar', actions.onClear);
    const reloadButton = createButton('Recargar', actions.onReload);

    form.append(
        search.wrapper,
        status.wrapper,
        availability.wrapper,
        cause.wrapper,
        orderBy.wrapper,
        direction.wrapper,
        searchButton,
        clearButton
    );
    reloadButton.classList.add('veciahorra-inventory-admin__reload');
    const contextPanel = document.createElement('div');
    contextPanel.className = 'veciahorra-inventory-admin__context';
    nodes.toolbar.replaceChildren(contextPanel, form, reloadButton);

    const controls = { search, status, availability, cause, orderBy, direction };
    let searchTimer = null;

    Object.entries(controls).forEach(([name, control]) => {
        control.element.addEventListener(name === 'search' ? 'input' : 'change', () => {
            actions.onFilter(name, control.element.value);
            if (name === 'search') {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(actions.onSearch, 350);
            } else {
                if (name === 'cause' && control.element.value !== '') {
                    const expected = availabilityForCause(
                        control.element.value
                    );
                    availability.element.value = expected;
                    actions.onFilter('availability', expected);
                }
                if (
                    name === 'availability'
                    && cause.element.value !== ''
                    && availabilityForCause(cause.element.value)
                        !== control.element.value
                ) {
                    cause.element.value = '';
                    actions.onFilter('cause', '');
                }
                actions.onSearch();
            }
        });
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(searchTimer);
        actions.onSearch();
    });

    if (newButton) {
        newButton.addEventListener('click', actions.onNew);
    }

    const inventoryForm = createInventoryForm(actions);
    let focusedFormKey = null;
    let pendingPaginationFocus = false;

    function render(state) {
        if (state.currentView === VIEW_DETAIL) {
            nodes.toolbar.hidden = true;
            nodes.pagination.replaceChildren();
            nodes.messages.replaceChildren();
            setButtonDisabled(newButton, true);
            if (pageHeading) {
                pageHeading.textContent = `Detalle de Inventory #${state.detail.id}`;
            }
            const loading = state.detail.status === STATUS_LOADING;
            nodes.root.setAttribute('aria-busy', loading ? 'true' : 'false');
            nodes.table.setAttribute('aria-busy', loading ? 'true' : 'false');
            renderInventoryDetail(nodes.table, state.detail, {
                returnUrl: actions.listUrl(state.detail.returnQuery),
                editUrl: actions.actionUrl(
                    'edit',
                    state.detail.id,
                    state.detail.returnQuery
                ),
                onEdit: actions.onDetailEdit,
                onRetry: actions.onDetailRetry,
            });
            return;
        }
        if (pageHeading) pageHeading.textContent = 'Inventario';
        nodes.root.setAttribute('aria-busy', 'false');
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
        inventoryForm.deactivate();
        const loading = state.status === STATUS_LOADING;
        const hasFilters = Object.entries(state.inputs).some(([name, value]) => (
            !['page', 'perPage'].includes(name) && String(value).trim() !== ''
        ));

        nodes.toolbar.hidden = false;
        renderContext(contextPanel, state.context, {
            ...actions,
            currentQuery: state.query,
        });
        setButtonDisabled(newButton, loading);

        Object.entries(controls).forEach(([name, control]) => {
            const value = String(state.inputs[name]);

            if (control.element.value !== value) {
                control.element.value = value;
            }

            control.element.disabled = false;
        });

        searchButton.disabled = false;
        clearButton.disabled = !hasFilters;
        reloadButton.disabled = loading;
        nodes.table.classList.toggle('is-loading', loading);
        nodes.table.setAttribute('aria-busy', loading ? 'true' : 'false');
        nodes.messages.replaceChildren();
        announceListState(nodes.messages, state);
        renderContent(nodes, state, actions);
        renderPagination(nodes.pagination, state, {
            ...actions,
            onPage(page) {
                pendingPaginationFocus = true;
                return actions.onPage(page);
            },
        });
        if (pendingPaginationFocus && !loading) {
            pendingPaginationFocus = false;
            queueMicrotask(() => {
                const target = nodes.pagination.querySelector(
                    'button:not(:disabled)'
                );
                target?.focus();
            });
        }
    }

    return {
        render,
        destroy() {
            window.clearTimeout(searchTimer);
            searchTimer = null;
            inventoryForm.deactivate();
        },
    };
}

function renderContent(nodes, state, actions) {
    if (state.context.status === 'error') {
        renderContextError(nodes.table, state.context.message, actions.allInventoryUrl);
        return;
    }

    if (state.context.status === 'loading') {
        renderState(nodes.table, 'Cargando contexto seleccionado...');
        return;
    }

    switch (state.status) {
        case STATUS_LOADING:
            renderState(nodes.table, 'Cargando inventario...');
            break;
        case STATUS_SUCCESS:
            renderTable(nodes.table, state.items, {
                ...actions,
                currentQuery: state.query,
            });
            break;
        case STATUS_EMPTY:
            renderEmpty(nodes.table, state, actions);
            if (state.context.status === 'ready') {
                nodes.table.firstElementChild.append(
                    createLink(
                        'Crear primera oferta',
                        state.context.kind === 'store' ? actions.contextualStoreCreateUrl(state.context.store.id) : actions.contextualCreateUrl(state.context.product.id),
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

    const caption = document.createElement('caption');
    caption.textContent = 'Ofertas de Inventory';
    ['Product', 'Store', 'Oferta', 'Disponibilidad', 'Referencias', 'Acciones']
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
        row.append(entityCell('Product', item.product, item.routes.product, 'veciahorra-products'));
        row.append(storeCell(item.store, item.routes.store));
        row.append(offerCell(item));
        row.append(availabilityCell(item.availability));
        row.append(referencesCell(item.references));
        const actionsCell = document.createElement('td');
        actionsCell.dataset.label = 'Acciones';
        actionsCell.className = 'veciahorra-inventory-admin__actions';
        if (item.actions.view) {
            actionsCell.append(createLink(
                `Ver oferta #${item.id}`,
                actions.actionUrl('view', item.id, actions.currentQuery)
            ));
        }
        if (item.actions.edit) {
            const editUrl = actions.actionUrl(
                'edit',
                item.id,
                actions.currentQuery
            );
            const edit = createLink(`Editar oferta #${item.id}`, editUrl);
            edit.addEventListener('click', (event) => {
                event.preventDefault();
                window.history.pushState({ inventoryEdit: item.id }, '', editUrl);
                actions.onEdit(item.id);
            });
            actionsCell.append(edit);
        }
        row.append(actionsCell);
        body.append(row);
    });

    table.append(caption, head, body);
    wrapper.append(table);
    container.replaceChildren(wrapper);
}

function entityCell(label, entity, route, expectedPage) {
    const cell = document.createElement('td');
    cell.dataset.label = label;
    const name = document.createElement('strong');
    name.textContent = entity.exists
        ? (entity.name || `${label} #${entity.id}`)
        : `${label} no disponible`;
    const safeRoute = safeAdministrativeRoute(route, expectedPage);
    if (safeRoute && entity.exists) {
        const link = createLink(name.textContent, safeRoute);
        link.className = 'veciahorra-inventory-admin__entity-link';
        cell.append(link);
    } else {
        cell.append(name);
    }
    const meta = document.createElement('span');
    meta.className = 'veciahorra-inventory-admin__meta';
    meta.textContent = [
        entity.sku ? `SKU ${entity.sku}` : null,
        `#${entity.id}`,
        entity.status ? stateText(entity.status) : 'Estado desconocido',
    ].filter(Boolean).join(' · ');
    cell.append(meta);
    return cell;
}

function storeCell(store, route) {
    const cell = entityCell('Store', store, route, 'veciahorra-stores');
    if (store.locationLabel) {
        const location = document.createElement('span');
        location.className = 'veciahorra-inventory-admin__meta';
        location.textContent = store.locationLabel;
        cell.append(location);
    }
    return cell;
}

function offerCell(item) {
    const cell = document.createElement('td');
    cell.dataset.label = 'Oferta';
    const price = document.createElement('strong');
    price.textContent = `$${formatPrice(item.price)}`;
    const stock = document.createElement('span');
    stock.textContent = `Stock registrado: ${item.stock}`;
    cell.append(price, stock, badge(
        item.status === 'active'
            ? 'Inventory activa'
            : item.status === 'inactive'
                ? 'Inventory inactiva'
                : 'Estado Inventory desconocido',
        item.status === 'active'
            ? 'success'
            : item.status === 'inactive' ? 'neutral' : 'error'
    ));
    const id = document.createElement('span');
    id.className = 'veciahorra-inventory-admin__meta';
    id.textContent = `Inventory #${item.id}`;
    cell.append(id);
    return cell;
}

function availabilityCell(availability) {
    const cell = document.createElement('td');
    cell.dataset.label = 'Disponibilidad';
    const primaryCode = availability.primary_cause.code;
    cell.append(badge(
        availability.is_publicly_available
            ? 'Disponible públicamente'
            : 'No disponible públicamente',
        availability.is_publicly_available
            ? 'success'
            : causeSeverity(primaryCode)
    ));
    const cause = document.createElement('span');
    cause.className = 'veciahorra-inventory-admin__cause';
    cause.textContent = availability.primary_cause.label
        || causeLabel(primaryCode);
    cell.append(cause);
    availability.blocking_codes
        .filter((code) => code !== primaryCode)
        .forEach((code) => {
            cell.append(badge(
                `Bloqueo adicional: ${causeLabel(code)}`,
                causeSeverity(code)
            ));
        });
    availability.warning_codes.forEach((code) => {
        cell.append(badge(`Advertencia: ${warningLabel(code)}`, 'warning'));
    });
    return cell;
}

function referencesCell(references) {
    const cell = document.createElement('td');
    cell.dataset.label = 'Referencias';
    if (references.inspection_status !== 'complete') {
        cell.append(badge('Inspección no disponible', 'error'));
        return cell;
    }
    const labels = [
        references.has_cart_items ? 'Tiene referencias en Cart' : null,
        references.has_active_reservations ? 'Tiene reservas activas' : null,
        references.has_history ? 'Tiene historial' : null,
    ].filter(Boolean);
    if (labels.length === 0) {
        const empty = document.createElement('span');
        empty.textContent = 'Sin referencias conocidas';
        cell.append(empty);
    } else {
        labels.forEach((label) => cell.append(badge(label, 'neutral')));
    }
    return cell;
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
        price: createFormInput('price', 'Price', 'number', '0.01'),
        stock: createFormInput('stock', 'Stock', 'number', '1'),
        status: createFormStatus(),
    };
    const productSelector = createProductSelector(actions);
    const storeControl = createStoreControl();
    let storeSelector = null;

    Object.entries(controls).forEach(([name, control]) => {
        control.input.addEventListener('input', () => {
            actions.onFormField(name, control.input.value);
        });
        fields.append(control.wrapper);
    });
    fields.insertBefore(productSelector.element, controls.productId.wrapper);
    fields.insertBefore(storeControl.element, controls.price.wrapper);

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
        renderStore(form, disabled);
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

    function renderStore(form, disabled) {
        const creating = form.mode === FORM_CREATE;
        const activeStoreView = creating && !form.storeLocked ? storeControl.create : storeControl.readonly;
        if (storeControl.element.firstElementChild !== activeStoreView) {
            storeControl.element.replaceChildren(activeStoreView);
        }
        if (creating && !form.storeLocked && storeSelector === null) {
            storeSelector = createStoreSelector({
                searchStores: actions.searchStores,
                onStoreSelected: actions.onStoreSelected,
                elements: storeControl.elements,
            });
        } else if (!creating || form.storeLocked) {
            destroyStoreSelector();
        }
        const selected = form.selectedStore;
        storeControl.search.hidden = selected !== null;
        storeControl.selected.hidden = selected === null;
        storeControl.input.disabled = disabled;
        storeControl.change.disabled = disabled;
        storeControl.remove.disabled = disabled;
        const error = form.fieldErrors.minimarketId || '';
        storeControl.input.setAttribute('aria-invalid', error ? 'true' : 'false');
        storeControl.error.textContent = error;
        if (selected !== null) {
            storeControl.selectedText.textContent = `${selected.name} (#${selected.id}) - ${storeStatusLabel(selected.status)}`;
        }
        if (!creating || form.storeLocked) {
            storeControl.readonlyName.textContent = form.contextStore ? `${form.contextStore.name} (#${form.contextStore.id})` : `Minimarket ID #${form.values.minimarketId}`;
            storeControl.readonlyMeta.textContent = form.storeLocked ? 'Minimarket fijado por el contexto de navegación.' : 'El minimarket asociado no puede modificarse en este inventario.';
        }
    }

    function clearStoreSelection() {
        storeSelector?.reset();
        actions.onStoreCleared?.();
        queueMicrotask(() => storeSelector?.focus());
    }

    function destroyStoreSelector() {
        storeSelector?.destroy();
        storeSelector = null;
    }

    storeControl.change.addEventListener('click', clearStoreSelection);
    storeControl.remove.addEventListener('click', clearStoreSelection);

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

    return { element, render, focusPrimary, deactivate: destroyStoreSelector };
}

function createStoreControl() {
    const element = document.createElement('div');
    element.className = 'veciahorra-inventory-admin__store-field';
    const create = document.createElement('div');
    create.className = 'veciahorra-inventory-admin__store-selector';
    const label = document.createElement('label');
    label.htmlFor = 'veciahorra-inventory-store-search';
    label.textContent = 'Minimarket (obligatorio)';
    const search = document.createElement('div');
    search.className = 'veciahorra-inventory-admin__store-search';
    const input = document.createElement('input');
    input.id = 'veciahorra-inventory-store-search';
    input.type = 'search';
    input.className = 'regular-text';
    input.placeholder = 'Buscar minimarket';
    input.setAttribute('aria-required', 'true');
    input.setAttribute('aria-describedby', 'veciahorra-inventory-store-help veciahorra-inventory-minimarketId-error');
    const help = document.createElement('p');
    help.id = 'veciahorra-inventory-store-help';
    help.className = 'description';
    help.textContent = 'Escriba al menos 2 caracteres y seleccione un resultado.';
    const status = document.createElement('p');
    status.className = 'veciahorra-inventory-admin__store-search-status';
    const results = document.createElement('div');
    results.className = 'veciahorra-inventory-admin__store-results';
    const error = document.createElement('p');
    error.id = 'veciahorra-inventory-minimarketId-error';
    error.className = 'veciahorra-inventory-admin__field-error';
    error.setAttribute('role', 'alert');
    search.append(input, help, status, results, error);
    const selected = document.createElement('div');
    selected.className = 'veciahorra-inventory-admin__selected-store';
    selected.setAttribute('role', 'status');
    selected.setAttribute('aria-live', 'polite');
    const selectedText = document.createElement('p');
    const change = createButton('Cambiar minimarket');
    const remove = createButton('Quitar seleccion');
    change.classList.add('button-secondary');
    remove.classList.add('button-link-delete');
    selected.append(selectedText, change, remove);
    create.append(label, search, selected);
    const readonly = document.createElement('section');
    readonly.className = 'veciahorra-inventory-admin__store-readonly';
    readonly.setAttribute('aria-label', 'Minimarket asociado');
    const readonlyLabel = document.createElement('strong');
    readonlyLabel.textContent = 'Minimarket';
    const readonlyName = document.createElement('p');
    const readonlyMeta = document.createElement('p');
    readonly.append(readonlyLabel, readonlyName, readonlyMeta);
    element.append(create);
    return { element, create, search, input, status, results, error, selected, selectedText, change, remove, readonly, readonlyName, readonlyMeta, elements: { input, results, status } };
}

function storeStatusLabel(status) {
    return { pending: 'Pendiente', active: 'Activo', inactive: 'Inactivo', rejected: 'Rechazado' }[status] || status;
}

function renderContext(container, context, actions) {
    if (context.status !== 'ready') {
        container.replaceChildren();
        container.hidden = true;
        return;
    }

    const label = document.createElement('strong');
    const entity = context.kind === 'store' ? context.store : context.product;
    label.textContent = `Ofertas de: ${entity.name} (#${entity.id})`;
    const status = document.createElement('span');
    status.textContent = ` Estado: ${context.kind === 'store' ? storeStatusLabel(entity.status) : statusLabel(entity.status)}. `;
    const withoutContext = {
        ...actions.currentQuery,
        productId: '',
        minimarketId: '',
        page: 1,
    };
    const all = createLink(
        context.kind === 'store'
            ? 'Retirar contexto Store'
            : 'Retirar contexto Product',
        actions.listUrl(withoutContext)
    );
    const create = createLink(
        'Crear oferta',
        context.kind === 'store' ? actions.contextualStoreCreateUrl(entity.id) : actions.contextualCreateUrl(entity.id),
        'button button-secondary'
    );
    container.hidden = false;
    const back = context.kind === 'store' ? createLink('Volver al minimarket', actions.storeDetailUrl(entity.id)) : null;
    container.replaceChildren(...[label, status, all, create, back].filter(Boolean));
}

function renderEmpty(container, state, actions) {
    const hasSearch = String(state.query.search).trim() !== '';
    const hasFilters = [
        'status',
        'availability',
        'cause',
        'productId',
        'minimarketId',
    ].some((key) => String(state.query[key] ?? '').trim() !== '');
    const outOfRange = state.meta !== null
        && state.meta.total > 0
        && state.meta.page > state.meta.totalPages;
    const message = outOfRange
        ? 'Esta página quedó fuera de rango por cambios concurrentes.'
        : hasSearch
            ? 'No hay ofertas que coincidan con la búsqueda.'
            : hasFilters
                ? 'No hay ofertas que coincidan con los filtros.'
                : 'Inventory todavía no tiene ofertas.';
    renderState(
        container,
        message,
        'veciahorra-inventory-admin__state--empty'
    );
    const stateNode = container.firstElementChild;
    if (outOfRange) {
        stateNode.append(createButton(
            'Volver a la primera página',
            () => actions.onPage(1)
        ));
    } else if (hasSearch || hasFilters) {
        stateNode.append(createButton(
            hasSearch ? 'Limpiar búsqueda y filtros' : 'Retirar filtros',
            actions.onClear
        ));
    }
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
    notice.setAttribute('role', 'alert');
    notice.tabIndex = -1;
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
    queueMicrotask(() => notice.focus());
}

function announceListState(container, state) {
    if (![STATUS_LOADING, STATUS_SUCCESS, STATUS_EMPTY].includes(state.status)) {
        return;
    }
    const announcement = document.createElement('span');
    announcement.className = 'screen-reader-text';
    announcement.textContent = state.status === STATUS_LOADING
        ? 'Cargando ofertas de Inventory.'
        : state.status === STATUS_EMPTY
            ? 'No se encontraron ofertas.'
            : `Se cargaron ${state.items.length} ofertas.`;
    container.append(announcement);
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
    return createSelect('status', 'Estado Inventory', [
        ['', 'Todos'],
        ['active', 'Activo'],
        ['inactive', 'Inactivo'],
        ['unknown', 'Desconocido'],
    ]);
}

function createAvailabilitySelect() {
    return createSelect('availability', 'Disponibilidad', [
        ['', 'Todas'],
        ['public', 'Disponible públicamente'],
        ['not_public', 'No disponible'],
        ['diagnostic_error', 'Error de diagnóstico'],
    ]);
}

function createCauseSelect() {
    return createSelect('cause', 'Causa primaria', [
        ['', 'Todas las causas'],
        ['inventory_inactive', 'Inventory inactiva'],
        ['product_not_public', 'Product no público'],
        ['store_not_active', 'Store no activo'],
        ['invalid_public_price', 'Precio no publicable'],
        ['out_of_stock', 'Sin stock'],
        ['product_missing', 'Product inexistente'],
        ['store_missing', 'Store inexistente'],
        ['inventory_status_unknown', 'Estado Inventory desconocido'],
        ['product_status_unknown', 'Estado Product desconocido'],
        ['store_status_unknown', 'Estado Store desconocido'],
        ['reference_mismatch', 'Referencia contradictoria'],
        ['publicly_available', 'Pública'],
    ]);
}

function createOrderSelect() {
    return createSelect('orderBy', 'Ordenar por', [
        ['updated_at', 'Última actualización'],
        ['product_name', 'Product'],
        ['store_name', 'Store'],
        ['price', 'Precio'],
        ['stock', 'Stock'],
        ['status', 'Estado'],
        ['id', 'Inventory ID'],
    ]);
}

function createDirectionSelect() {
    return createSelect('direction', 'Dirección', [
        ['DESC', 'Descendente'],
        ['ASC', 'Ascendente'],
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

function badge(label, severity) {
    const element = document.createElement('span');
    element.className = [
        'veciahorra-inventory-admin__badge',
        `veciahorra-inventory-admin__badge--${severity}`,
    ].join(' ');
    element.textContent = label;
    return element;
}

function warningLabel(code) {
    return {
        store_lifecycle_inconsistent: 'Lifecycle Store inconsistente',
        reference_mismatch: 'Referencias inconsistentes',
    }[code] || 'Advertencia operacional';
}

function causeLabel(code) {
    return {
        product_reference_invalid: 'Referencia Product inválida',
        store_reference_invalid: 'Referencia Store inválida',
        product_missing: 'Product inexistente',
        store_missing: 'Store inexistente',
        reference_mismatch: 'Referencia contradictoria',
        inventory_status_unknown: 'Estado Inventory desconocido',
        inventory_inactive: 'Inventory inactiva',
        product_status_unknown: 'Estado Product desconocido',
        product_not_public: 'Product no público',
        store_status_unknown: 'Estado Store desconocido',
        store_not_active: 'Store no activo',
        invalid_public_price: 'Precio no publicable',
        out_of_stock: 'Sin stock',
        publicly_available: 'Pública',
    }[code] || `Causa no reconocida: ${code}`;
}

function causeSeverity(code) {
    if (code === 'publicly_available') return 'success';
    if (code === 'inventory_inactive') return 'neutral';
    if ([
        'product_reference_invalid',
        'store_reference_invalid',
        'product_missing',
        'store_missing',
        'reference_mismatch',
        'inventory_status_unknown',
        'product_status_unknown',
        'store_status_unknown',
    ].includes(code)) return 'error';
    return 'warning';
}

function stateText(status) {
    return {
        active: 'Activo',
        inactive: 'Inactivo',
        draft: 'Borrador',
        pending: 'Pendiente',
        rejected: 'Rechazado',
    }[status] || 'Estado desconocido';
}

function availabilityForCause(cause) {
    if (cause === 'publicly_available') return 'public';
    if ([
        'product_reference_invalid',
        'store_reference_invalid',
        'product_missing',
        'store_missing',
        'reference_mismatch',
        'inventory_status_unknown',
        'product_status_unknown',
        'store_status_unknown',
    ].includes(cause)) return 'diagnostic_error';
    return 'not_public';
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
