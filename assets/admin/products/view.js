import {
    FORM_MODE_CREATE,
    FORM_MODE_EDIT,
    FORM_MODE_READONLY,
    FORM_STATUS_ERROR,
    FORM_STATUS_LOADING,
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
    {
        name: 'categoryId',
        label: 'Categoría',
        type: 'select',
        catalog: 'categories',
    },
    {
        name: 'brandId',
        label: 'Marca',
        type: 'select',
        catalog: 'brands',
    },
    {
        name: 'unitId',
        label: 'Unidad',
        type: 'select',
        catalog: 'units',
    },
    { name: 'imageId', label: 'Imagen', type: 'media' },
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
    const busy = state.form.status === FORM_STATUS_LOADING
        || state.form.isSaving;

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
    productForm.render(state.form, state.catalogs);
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
    const mediaPicker = createMediaPicker(actions);

    const mainCard = createFormCard('Información principal');
    const technicalCard = createFormCard('Datos técnicos');
    const mainFields = new Set(['name', 'sku', 'description']);

    FORM_FIELD_DEFINITIONS.forEach((definition) => {
        const control = createFormControl(definition, actions);

        if (definition.type === 'media') {
            attachMediaControl(control, mediaPicker, actions);
        }

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

    function render(form, catalogs) {
        const isLoading = form.status === FORM_STATUS_LOADING;
        const isSaving = form.isSaving;
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

            if (control.catalog !== null) {
                renderCatalogControl(
                    control,
                    catalogs?.[control.catalog],
                    value
                );
            }

            if (control.input.value !== value) {
                control.input.value = value;
            }

            const error = form.fieldErrors[field] ?? '';
            control.error.textContent = error;
            control.error.hidden = error === '';
            const disabled = !editable
                || control.catalogStatus === 'loading'
                || control.catalogStatus === 'error';
            control.input.disabled = disabled;

            if (control.media !== null) {
                mediaPicker.render(control, value, disabled);
            }

            control.input.setAttribute(
                'aria-invalid',
                error === '' ? 'false' : 'true'
            );
        });

        save.textContent = isSaving ? 'Guardando...' : 'Guardar cambios';
        save.classList.toggle('is-saving', isSaving);
        save.setAttribute('aria-busy', isSaving ? 'true' : 'false');
        save.hidden = readonly || detailUnavailable;
        save.disabled = !editable
            || (form.mode === FORM_MODE_EDIT && !form.hasUnsavedChanges);

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

    let input;

    if (definition.type === 'textarea') {
        input = document.createElement('textarea');
    } else if (definition.type === 'select') {
        input = document.createElement('select');
    } else {
        input = document.createElement('input');
    }
    input.id = id;
    input.name = definition.name;
    input.className = 'regular-text';

    if (definition.type === 'media') {
        input.type = 'hidden';
    } else if (!['textarea', 'select'].includes(definition.type)) {
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

    const describedBy = [error.id];
    let catalogStatus = null;

    if (definition.catalog) {
        catalogStatus = document.createElement('p');
        catalogStatus.id = `${id}-catalog-status`;
        catalogStatus.className = 'veciahorra-products-admin__catalog-status';
        catalogStatus.hidden = true;
        describedBy.push(catalogStatus.id);
    }

    input.setAttribute('aria-describedby', describedBy.join(' '));
    if (definition.type !== 'media') {
        input.addEventListener(
            definition.type === 'select' ? 'change' : 'input',
            () => {
                emit(actions.onFormField, definition.name, input.value);
            }
        );
    }

    wrapper.append(label, input);

    if (catalogStatus !== null) {
        wrapper.append(catalogStatus);
    }

    wrapper.append(error);

    return {
        wrapper,
        input,
        error,
        catalog: definition.catalog ?? null,
        catalogStatus: null,
        catalogMessage: catalogStatus,
        media: null,
    };
}

function attachMediaControl(control, mediaPicker, actions) {
    const element = document.createElement('div');
    element.className = 'veciahorra-products-admin__media-control';

    const preview = document.createElement('div');
    preview.className = 'veciahorra-products-admin__media-preview';
    const image = document.createElement('img');
    image.className = 'veciahorra-products-admin__media-thumbnail';
    image.alt = '';
    image.hidden = true;
    const placeholder = document.createElement('span');
    placeholder.className = 'veciahorra-products-admin__media-placeholder';
    placeholder.textContent = 'Sin imagen seleccionada.';
    preview.append(image, placeholder);

    const filename = document.createElement('p');
    filename.className = 'veciahorra-products-admin__media-filename';
    filename.hidden = true;

    const status = document.createElement('p');
    status.className = 'veciahorra-products-admin__media-status';
    status.hidden = true;

    const actionsContainer = document.createElement('div');
    actionsContainer.className = 'veciahorra-products-admin__media-actions';
    const select = createButton(
        'Seleccionar imagen',
        () => mediaPicker.open(control)
    );
    select.classList.add('button', 'button-secondary');
    const change = createButton(
        'Cambiar imagen',
        () => mediaPicker.open(control)
    );
    change.classList.add('button', 'button-secondary');
    const remove = createButton('Quitar imagen', () => {
        mediaPicker.clear(control);
        emit(actions.onFormField, 'imageId', '');
    });
    remove.classList.add('button', 'button-link-delete');
    actionsContainer.append(select, change, remove);

    element.append(preview, filename, status, actionsContainer);
    control.wrapper.insertBefore(element, control.error);
    control.media = {
        element,
        image,
        placeholder,
        filename,
        status,
        select,
        change,
        remove,
    };
}

function createMediaPicker(actions) {
    const attachments = new Map();
    const requestedIds = new Set();
    let frame = null;
    let activeControl = null;

    function open(control) {
        activeControl = control;

        if (!window.wp || typeof window.wp.media !== 'function') {
            showMediaError(
                control,
                'La biblioteca multimedia no está disponible.'
            );
            return;
        }

        if (frame === null) {
            frame = window.wp.media({
                title: 'Seleccionar imagen',
                button: { text: 'Usar esta imagen' },
                library: { type: 'image' },
                multiple: false,
            });
            frame.on('select', selectAttachment);
        }

        prepareSelection(control.input.value);
        frame.open();
    }

    function selectAttachment() {
        const attachment = frame.state().get('selection').first();
        const data = normalizeAttachment(attachment);

        if (activeControl === null || data === null) {
            return;
        }

        attachments.set(String(data.id), data);
        render(activeControl, String(data.id), false);
        emit(actions.onFormField, 'imageId', String(data.id));
    }

    function prepareSelection(value) {
        const selection = frame.state().get('selection');
        selection.reset();

        if (value !== '' && typeof window.wp.media.attachment === 'function') {
            selection.add(window.wp.media.attachment(Number(value)));
        }
    }

    function render(control, value, disabled) {
        const id = String(value ?? '');
        const hasImage = id !== '';
        const cached = attachments.get(id) ?? null;

        control.media.select.hidden = hasImage;
        control.media.change.hidden = !hasImage;
        control.media.remove.hidden = !hasImage;
        control.media.select.disabled = disabled;
        control.media.change.disabled = disabled;
        control.media.remove.disabled = disabled;
        control.media.status.hidden = true;
        control.media.status.textContent = '';

        if (!hasImage) {
            showMediaPlaceholder(control, 'Sin imagen seleccionada.');
            return;
        }

        if (cached !== null) {
            showAttachment(control, cached);
            return;
        }

        showMediaPlaceholder(control, `Imagen seleccionada (ID ${id}).`);
        loadAttachment(control, id);
    }

    function loadAttachment(control, id) {
        if (
            requestedIds.has(id)
            || !window.wp?.media
            || typeof window.wp.media.attachment !== 'function'
        ) {
            return;
        }

        const attachment = window.wp.media.attachment(Number(id));
        const available = normalizeAttachment(attachment);

        if (available?.url || available?.filename) {
            attachments.set(id, available);
            showAttachment(control, available);
            return;
        }

        if (typeof attachment?.fetch !== 'function') {
            return;
        }

        requestedIds.add(id);
        Promise.resolve(attachment.fetch())
            .then(() => {
                const loaded = normalizeAttachment(attachment);

                if (loaded === null) {
                    return;
                }

                attachments.set(id, loaded);

                if (control.input.value === id) {
                    showAttachment(control, loaded);
                }
            })
            .catch(() => {
                if (control.input.value === id) {
                    showMediaError(
                        control,
                        'No fue posible cargar la vista previa.'
                    );
                }
            });
    }

    function clear(control) {
        control.input.value = '';
        showMediaPlaceholder(control, 'Sin imagen seleccionada.');
    }

    return { open, render, clear };
}

function normalizeAttachment(attachment) {
    if (!attachment) {
        return null;
    }

    const data = typeof attachment.toJSON === 'function'
        ? attachment.toJSON()
        : attachment;
    const id = Number(data.id ?? attachment.id);

    if (!Number.isSafeInteger(id) || id <= 0) {
        return null;
    }

    return {
        id,
        filename: stringValue(data.filename)
            || stringValue(data.name)
            || stringValue(data.title),
        url: stringValue(data.sizes?.thumbnail?.url)
            || stringValue(data.url),
    };
}

function showAttachment(control, attachment) {
    const hasThumbnail = attachment.url !== '';
    control.media.image.src = hasThumbnail ? attachment.url : '';
    control.media.image.hidden = !hasThumbnail;
    control.media.placeholder.hidden = hasThumbnail;
    control.media.placeholder.textContent = hasThumbnail
        ? ''
        : `Imagen seleccionada (ID ${attachment.id}).`;
    control.media.filename.textContent = attachment.filename;
    control.media.filename.hidden = attachment.filename === '';
}

function showMediaPlaceholder(control, message) {
    control.media.image.src = '';
    control.media.image.hidden = true;
    control.media.placeholder.textContent = message;
    control.media.placeholder.hidden = false;
    control.media.filename.textContent = '';
    control.media.filename.hidden = true;
}

function showMediaError(control, message) {
    control.media.status.textContent = message;
    control.media.status.hidden = false;
    control.media.status.setAttribute('role', 'alert');
}

function stringValue(value) {
    return typeof value === 'string' ? value.trim() : '';
}

function renderCatalogControl(control, catalog, currentValue) {
    const status = catalog?.status ?? 'idle';
    const data = Array.isArray(catalog?.data) ? catalog.data : [];
    const options = [createOption('', 'Seleccione...')];
    const normalizedValue = String(currentValue ?? '');
    const hasCurrentValue = normalizedValue !== '';
    const currentExists = data.some(
        (item) => String(item.id) === normalizedValue
    );

    data.forEach((item) => {
        options.push(createOption(String(item.id), item.name));
    });

    if (hasCurrentValue && !currentExists) {
        options.push(createOption(
            normalizedValue,
            `${normalizedValue} (No disponible)`
        ));
    }

    control.input.replaceChildren(...options);
    control.catalogStatus = status;
    control.catalogMessage.classList.toggle(
        'veciahorra-products-admin__catalog-status--error',
        status === 'error'
    );

    if (status === 'loading') {
        control.catalogMessage.textContent = 'Cargando opciones…';
        control.catalogMessage.hidden = false;
        control.catalogMessage.setAttribute('role', 'status');
    } else if (status === 'error') {
        control.catalogMessage.textContent = catalog?.error?.message
            || 'No fue posible cargar las opciones.';
        control.catalogMessage.hidden = false;
        control.catalogMessage.setAttribute('role', 'alert');
    } else {
        control.catalogMessage.textContent = '';
        control.catalogMessage.hidden = true;
        control.catalogMessage.removeAttribute('role');
    }
}

function createOption(value, label) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;

    return option;
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
