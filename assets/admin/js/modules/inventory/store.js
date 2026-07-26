export const STATUS_IDLE = 'idle';
export const STATUS_LOADING = 'loading';
export const STATUS_SUCCESS = 'success';
export const STATUS_EMPTY = 'empty';
export const STATUS_ERROR = 'error';

export const VIEW_LIST = 'list';
export const VIEW_FORM = 'form';
export const FORM_CREATE = 'create';
export const FORM_EDIT = 'edit';

const DEFAULT_FILTERS = {
    search: '',
    productId: '',
    minimarketId: '',
    status: '',
    availability: '',
    cause: '',
    page: 1,
    perPage: 20,
    orderBy: 'updated_at',
    direction: 'DESC',
};

const DEFAULT_VALUES = {
    productId: '',
    minimarketId: '',
    price: '',
    stock: '0',
    status: 'active',
};

export function createInventoryStore(api, { onQueryChange = null } = {}) {
    let state = {
        currentView: VIEW_LIST,
        status: STATUS_IDLE,
        inputs: { ...DEFAULT_FILTERS },
        query: { ...DEFAULT_FILTERS },
        items: [],
        meta: null,
        error: null,
        context: { status: 'none', intent: 'list', kind: null, product: null, store: null, message: null },
        form: initialForm(),
    };
    let latestRequest = 0;
    let listController = null;
    let latestFormRequest = 0;
    let listNeedsReload = false;
    let destroyed = false;
    const listeners = new Set();

    function getState() {
        return snapshot(state);
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('El listener del store debe ser una funcion.');
        }

        if (destroyed) return () => {};
        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    function setState(next) {
        if (destroyed) return;
        state = { ...state, ...next };
        listeners.forEach((listener) => listener(snapshot(state)));
    }

    function setForm(form) {
        setState({ form: { ...form } });
    }

    function setFilter(name, value) {
        if (
            !Object.hasOwn(DEFAULT_FILTERS, name)
            || name === 'page'
            || (state.context.status === 'ready' && ((name === 'productId' && state.context.kind !== 'store') || (name === 'minimarketId' && state.context.kind === 'store')))
        ) {
            return;
        }

        setState({ inputs: { ...state.inputs, [name]: value } });
    }

    function applyFilters() {
        const contextualProductId = state.context.status === 'ready' && state.context.kind !== 'store'
            ? String(state.context.product.id)
            : String(state.inputs.productId).trim();
        const query = {
            ...state.inputs,
            search: String(state.inputs.search).trim(),
            productId: contextualProductId,
            minimarketId: state.context.status === 'ready' && state.context.kind === 'store' ? String(state.context.store.id) : String(state.inputs.minimarketId).trim(),
            page: 1,
            perPage: Number(state.inputs.perPage),
        };

        return execute(query, { ...query });
    }

    function clearFilters() {
        const productId = state.context.status === 'ready' && state.context.kind !== 'store'
            ? String(state.context.product.id)
            : '';
        const minimarketId = state.context.status === 'ready' && state.context.kind === 'store' ? String(state.context.store.id) : '';
        return execute(
            { ...DEFAULT_FILTERS, productId, minimarketId },
            { ...DEFAULT_FILTERS, productId, minimarketId }
        );
    }

    function initializeList(query) {
        latestFormRequest++;
        setState({
            currentView: VIEW_LIST,
            form: initialForm(),
        });
        return execute({ ...DEFAULT_FILTERS, ...query }, {
            ...DEFAULT_FILTERS,
            ...query,
        }, false);
    }

    function prepareListQuery(query) {
        setState({
            inputs: { ...DEFAULT_FILTERS, ...query },
            query: { ...DEFAULT_FILTERS, ...query },
        });
    }

    function reload() {
        return execute(state.query, state.inputs);
    }

    function goToPage(page) {
        if (
            !Number.isInteger(page)
            || page < 1
            || state.status === STATUS_LOADING
            || (state.meta !== null && page > state.meta.totalPages)
        ) {
            return Promise.resolve(false);
        }

        return execute({ ...state.query, page }, state.inputs);
    }

    function openCreateForm() {
        if (state.form.isSaving) {
            return false;
        }

        latestFormRequest++;
        setState({ currentView: VIEW_FORM, form: initialForm(FORM_CREATE) });
        return true;
    }

    async function openEditForm(id) {
        if (state.form.isSaving) {
            return false;
        }

        const requestId = ++latestFormRequest;
        setState({
            currentView: VIEW_FORM,
            form: {
                ...initialForm(FORM_EDIT),
                status: STATUS_LOADING,
                inventoryId: Number(id),
            },
        });

        try {
            const response = await api.getInventoryItem(id);

            if (requestId !== latestFormRequest) {
                return false;
            }

            setForm(formFromItem(response.data));
            return true;
        } catch (error) {
            if (requestId !== latestFormRequest) {
                return false;
            }

            setForm({
                ...state.form,
                status: STATUS_ERROR,
                error: normalizeError(error),
            });
            return false;
        }
    }

    function setFormField(field, value) {
        if (
            state.currentView !== VIEW_FORM
            || state.form.isSaving
            || !Object.hasOwn(DEFAULT_VALUES, field)
            || ['productId', 'minimarketId'].includes(field)
        ) {
            return;
        }

        setForm({
            ...state.form,
            values: { ...state.form.values, [field]: value },
            fieldErrors: { ...state.form.fieldErrors, [field]: undefined },
            error: null,
            message: null,
        });
    }

    function selectProduct(product) {
        const normalizedProduct = normalizeAdministrativeProduct(product);

        if (
            state.currentView !== VIEW_FORM
            || state.form.mode !== FORM_CREATE
            || state.form.productLocked
            || normalizedProduct === null
        ) {
            return false;
        }

        setForm({
            ...state.form,
            values: {
                ...state.form.values,
                productId: String(normalizedProduct.id),
            },
            selectedProduct: normalizedProduct,
            fieldErrors: { ...state.form.fieldErrors, productId: undefined },
            error: null,
            message: null,
        });
        return true;
    }

    function clearSelectedProduct() {
        if (
            state.currentView !== VIEW_FORM
            || state.form.mode !== FORM_CREATE
            || state.form.productLocked
            || state.form.isSaving
        ) {
            return false;
        }

        setForm({
            ...state.form,
            values: { ...state.form.values, productId: '' },
            selectedProduct: null,
            fieldErrors: { ...state.form.fieldErrors, productId: undefined },
            error: null,
            message: null,
        });
        return true;
    }

    function selectStore(store) {
        const normalizedStore = normalizeAdministrativeStore(store);
        if (state.currentView !== VIEW_FORM || state.form.mode !== FORM_CREATE
            || state.form.isSaving || state.form.storeLocked || normalizedStore === null) return false;
        setForm({
            ...state.form,
            values: { ...state.form.values, minimarketId: String(normalizedStore.id) },
            selectedStore: normalizedStore,
            fieldErrors: { ...state.form.fieldErrors, minimarketId: undefined },
            error: null,
            message: null,
        });
        return true;
    }

    function clearSelectedStore() {
        if (state.currentView !== VIEW_FORM || state.form.mode !== FORM_CREATE
            || state.form.isSaving || state.form.storeLocked) return false;
        setForm({
            ...state.form,
            values: { ...state.form.values, minimarketId: '' },
            selectedStore: null,
            fieldErrors: { ...state.form.fieldErrors, minimarketId: undefined },
            error: null,
            message: null,
        });
        return true;
    }

    async function save() {
        if (state.currentView !== VIEW_FORM || state.form.isSaving) {
            return false;
        }

        const validation = validate(state.form.values);

        if (!validation.valid) {
            setForm({
                ...state.form,
                status: STATUS_ERROR,
                fieldErrors: validation.errors,
                error: {
                    code: 'validation_error',
                    message: 'Revise los campos indicados.',
                    retryable: false,
                },
            });
            return false;
        }

        setForm({
            ...state.form,
            status: STATUS_LOADING,
            isSaving: true,
            fieldErrors: {},
            error: null,
            message: null,
        });

        try {
            let id = state.form.inventoryId;
            const contextProduct = state.form.contextProduct;
            const productLocked = state.form.productLocked;
            const contextStore = state.form.contextStore;
            const storeLocked = state.form.storeLocked;

            if (state.form.mode === FORM_CREATE) {
                const created = await api.createInventory(validation.payload);
                id = Number(created.data.id);
            } else {
                await api.updateInventory(id, {
                    price: validation.payload.price,
                    stock: validation.payload.stock,
                    status: validation.payload.status,
                });
            }

            const detail = await api.getInventoryItem(id);
            listNeedsReload = true;
            setForm({
                ...formFromItem(detail.data),
                contextProduct,
                productLocked,
                contextStore,
                storeLocked,
                message: state.form.mode === FORM_CREATE
                    ? 'Inventario creado correctamente.'
                    : 'Inventario actualizado correctamente.',
            });
            return true;
        } catch (error) {
            const normalizedError = normalizeError(error);
            const productRemoved = [
                'inventory_invalid_product_id',
                'inventory_product_not_found',
            ].includes(normalizedError.code);
            const replaceableProduct = productRemoved
                && state.form.mode === FORM_CREATE
                && !state.form.productLocked;
            const replaceableStore = [
                'inventory_invalid_store_id',
                'inventory_store_not_found',
                'inventory_store_incompatible',
            ].includes(normalizedError.code) && state.form.mode === FORM_CREATE && !state.form.storeLocked;
            setForm({
                ...state.form,
                values: {
                    ...state.form.values,
                    ...(replaceableProduct ? { productId: '' } : {}),
                    ...(replaceableStore ? { minimarketId: '' } : {}),
                },
                selectedProduct: replaceableProduct ? null : state.form.selectedProduct,
                selectedStore: replaceableStore ? null : state.form.selectedStore,
                fieldErrors: {
                    ...state.form.fieldErrors,
                    ...(replaceableProduct ? {
                        productId: 'El producto seleccionado ya no esta disponible. Seleccione otro.',
                    } : {}),
                    ...(replaceableStore ? {
                        minimarketId: 'El minimarket seleccionado ya no esta disponible. Seleccione otro.',
                    } : {}),
                },
                status: STATUS_ERROR,
                error: normalizedError,
            });
            return false;
        } finally {
            if (state.form.isSaving) {
                setForm({ ...state.form, isSaving: false });
            }
        }
    }

    async function returnToList() {
        if (state.form.isSaving) {
            return false;
        }

        latestFormRequest++;
        setState({ currentView: VIEW_LIST, form: initialForm() });

        if (state.context.status === 'ready') {
            if (state.context.kind === 'store') {
                const minimarketId = String(state.context.store.id);
                await execute({ ...DEFAULT_FILTERS, minimarketId }, { ...DEFAULT_FILTERS, minimarketId });
                return true;
            }
            const productId = String(state.context.product.id);
            await execute(
                { ...DEFAULT_FILTERS, productId },
                { ...DEFAULT_FILTERS, productId }
            );
            return true;
        }

        if (listNeedsReload) {
            listNeedsReload = false;
            await execute(state.query, state.inputs);
        }

        if (state.items.length === 0) {
            await execute(state.query, state.inputs, false);
        }

        return true;
    }

    function loadContext(context) {
        setState({
            status: STATUS_LOADING,
            context: { status: 'loading', intent: context.intent, kind: context.kind || 'product', product: null, store: null, message: null },
        });
    }

    function rejectContext(message) {
        setState({
            status: STATUS_ERROR,
            context: { status: 'error', intent: 'list', kind: null, product: null, store: null, message },
            error: { code: 'invalid_product_context', message, retryable: false },
        });
    }

    function applyContext(context, product) {
        const normalizedProduct = normalizeAdministrativeProduct(product);

        if (normalizedProduct === null) {
            rejectContext('El Product contextual devolvio una respuesta no valida.');
            return false;
        }
        setState({
            context: { status: 'ready', intent: context.intent, kind: 'product', product: normalizedProduct, store: null, message: null },
        });

        if (context.intent === 'create') {
            const form = initialForm(FORM_CREATE);
            form.values.productId = String(normalizedProduct.id);
            form.contextProduct = { ...normalizedProduct };
            form.productLocked = true;
            latestFormRequest++;
            setState({ currentView: VIEW_FORM, form });
            return true;
        }

        const productId = String(normalizedProduct.id);
        return execute(
            { ...DEFAULT_FILTERS, productId },
            { ...DEFAULT_FILTERS, productId }
        );
    }

    function applyStoreContext(context, store) {
        const normalizedStore = normalizeAdministrativeStore({ id: store?.id, name: store?.business_name, status: store?.status });
        if (normalizedStore === null) {
            rejectContext('El Store contextual devolvio una respuesta no valida.');
            return false;
        }
        setState({ context: { status: 'ready', intent: context.intent, kind: 'store', product: null, store: normalizedStore, message: null } });
        if (context.intent === 'create') {
            const form = initialForm(FORM_CREATE);
            form.values.minimarketId = String(normalizedStore.id);
            form.contextStore = { ...normalizedStore };
            form.selectedStore = { ...normalizedStore };
            form.storeLocked = true;
            latestFormRequest++;
            setState({ currentView: VIEW_FORM, form });
            return true;
        }
        const minimarketId = String(normalizedStore.id);
        return execute({ ...DEFAULT_FILTERS, minimarketId }, { ...DEFAULT_FILTERS, minimarketId });
    }

    function applyListContext(context, query) {
        const id = context.kind === 'store'
            ? Number(query.minimarketId)
            : Number(query.productId);
        setState({
            context: {
                status: 'ready',
                intent: 'list',
                kind: context.kind,
                product: context.kind === 'product'
                    ? { id, name: `Product #${id}`, status: 'unknown' }
                    : null,
                store: context.kind === 'store'
                    ? { id, name: `Store #${id}`, status: 'unknown' }
                    : null,
                message: null,
            },
        });
        return initializeList(query);
    }

    async function execute(query, inputs, pushHistory = true) {
        if (destroyed) return false;
        const requestId = ++latestRequest;
        listController?.abort();
        listController = typeof AbortController === 'function'
            ? new AbortController()
            : null;
        if (typeof onQueryChange === 'function') {
            onQueryChange({ ...query }, pushHistory);
        }
        setState({
            status: STATUS_LOADING,
            inputs: { ...inputs },
            query: { ...query },
            items: [],
            meta: null,
            error: null,
        });

        try {
            const response = await api.getInventory(query, {
                signal: listController?.signal,
            });

            if (destroyed || requestId !== latestRequest) {
                return false;
            }

            if (response.meta.total_pages > 0 && response.meta.page > response.meta.total_pages) {
                return execute(
                    { ...query, page: 1 },
                    { ...inputs, page: 1 }
                );
            }

            const items = response.data.map(normalizeItem);
            refreshContextFromItems(items);
            setState({
                status: items.length === 0 ? STATUS_EMPTY : STATUS_SUCCESS,
                items,
                meta: normalizeMeta(response.meta),
            });
            return true;
        } catch (error) {
            if (destroyed || requestId !== latestRequest) {
                return false;
            }
            if (error?.name === 'AbortError') {
                return false;
            }

            setState({
                status: STATUS_ERROR,
                items: [],
                meta: null,
                error: normalizeError(error),
            });
            return false;
        }
    }

    return {
        getState,
        subscribe,
        setFilter,
        applyFilters,
        clearFilters,
        reload,
        goToPage,
        openCreateForm,
        openEditForm,
        setFormField,
        selectProduct,
        clearSelectedProduct,
        selectStore,
        clearSelectedStore,
        save,
        returnToList,
        loadContext,
        rejectContext,
        applyContext,
        applyStoreContext,
        applyListContext,
        initializeList,
        prepareListQuery,
        destroy() {
            if (destroyed) return;
            destroyed = true;
            latestRequest++;
            latestFormRequest++;
            listController?.abort();
            listController = null;
            listeners.clear();
        },
    };

    function refreshContextFromItems(items) {
        if (state.context.status !== 'ready' || items.length === 0) return;
        if (state.context.kind === 'product') {
            const product = items.find(
                (item) => item.product.id === state.context.product.id
            )?.product;
            if (product) state.context.product = {
                id: product.id,
                name: product.name || `Product #${product.id}`,
                status: product.status || 'unknown',
            };
        } else {
            const store = items.find(
                (item) => item.store.id === state.context.store.id
            )?.store;
            if (store) state.context.store = {
                id: store.id,
                name: store.name || `Store #${store.id}`,
                status: store.status || 'unknown',
            };
        }
    }
}

function initialForm(mode = FORM_CREATE) {
    return {
        mode,
        status: STATUS_IDLE,
        inventoryId: null,
        values: { ...DEFAULT_VALUES },
        initialValues: null,
        fieldErrors: {},
        error: null,
        message: null,
        isSaving: false,
        contextProduct: null,
        productLocked: false,
        contextStore: null,
        storeLocked: false,
        selectedProduct: null,
        selectedStore: null,
    };
}

function formFromItem(item) {
    const values = {
        productId: String(item.product_id),
        minimarketId: String(item.minimarket_id),
        price: String(item.price),
        stock: String(item.stock),
        status: item.status,
    };

    return {
        ...initialForm(FORM_EDIT),
        status: STATUS_SUCCESS,
        inventoryId: Number(item.id),
        values,
        initialValues: { ...values },
    };
}

function validate(values) {
    const errors = {};
    const productId = positiveInteger(values.productId);
    const minimarketId = positiveInteger(values.minimarketId);
    const price = nonNegativeNumber(values.price);
    const stock = nonNegativeInteger(values.stock);

    if (productId === null) errors.productId = 'Seleccione un producto.';
    if (minimarketId === null) errors.minimarketId = 'Seleccione un minimarket.';
    if (price === null) errors.price = 'Ingrese un precio mayor o igual a 0.';
    if (stock === null) errors.stock = 'Ingrese un stock entero mayor o igual a 0.';
    if (!['active', 'inactive'].includes(values.status)) {
        errors.status = 'Seleccione un estado valido.';
    }

    return {
        valid: Object.keys(errors).length === 0,
        errors,
        payload: {
            product_id: productId,
            minimarket_id: minimarketId,
            price,
            stock,
            status: values.status,
        },
    };
}

function positiveInteger(value) {
    const normalized = String(value).trim();
    const number = Number(normalized);
    return /^[1-9]\d*$/.test(normalized)
        && Number.isSafeInteger(number)
        ? number
        : null;
}

function normalizeAdministrativeProduct(product) {
    const id = positiveInteger(product?.id);
    const name = typeof product?.name === 'string' ? product.name.trim() : '';
    const status = String(product?.status ?? '');

    return id !== null
        && name !== ''
        && ['active', 'inactive', 'draft'].includes(status)
        ? { id, name, status }
        : null;
}

function normalizeAdministrativeStore(store) {
    const id = positiveInteger(store?.id);
    const name = typeof store?.name === 'string' ? store.name.trim() : '';
    const status = String(store?.status ?? '');
    return id !== null && name !== ''
        && ['pending', 'active', 'inactive', 'rejected'].includes(status)
        ? { id, name, status }
        : null;
}

function nonNegativeInteger(value) {
    const normalized = String(value).trim();
    return /^\d+$/.test(normalized) ? Number(normalized) : null;
}

function nonNegativeNumber(value) {
    const normalized = String(value).trim();
    const number = Number(normalized);
    return normalized !== '' && Number.isFinite(number) && number >= 0
        ? number
        : null;
}

function normalizeItem(item) {
    return {
        id: Number(item.id),
        product: {
            id: Number(item.product.id),
            exists: item.product.exists,
            name: item.product.name,
            sku: item.product.sku ?? null,
            status: item.product.status,
        },
        store: {
            id: Number(item.store.id),
            exists: item.store.exists,
            name: item.store.name,
            locationLabel: item.store.location_label ?? null,
            status: item.store.status,
            lifecycleState: item.store.lifecycle_state ?? null,
        },
        price: Number(item.price),
        stock: Number(item.stock),
        status: item.status,
        availability: {
            ...item.availability,
            primary_cause: { ...item.availability.primary_cause },
            blocking_causes: Array.isArray(item.availability.blocking_causes)
                ? item.availability.blocking_causes.map((cause) => ({ ...cause }))
                : [],
            blocking_codes: [...item.availability.blocking_codes],
            warnings: Array.isArray(item.availability.warnings)
                ? item.availability.warnings.map((cause) => ({ ...cause }))
                : [],
            warning_codes: [...item.availability.warning_codes],
        },
        references: { ...item.references },
        actions: { ...item.actions },
        routes: { ...item.routes },
        createdAt: item.created_at,
        updatedAt: item.updated_at,
        version: item.version,
    };
}

function normalizeMeta(meta) {
    return {
        page: meta.page,
        perPage: meta.per_page,
        total: meta.total,
        totalPages: meta.total_pages,
    };
}

function normalizeError(error) {
    return {
        code: typeof error?.code === 'string' ? error.code : 'unknown_error',
        field: typeof error?.field === 'string' ? error.field : null,
        reason: typeof error?.reason === 'string' ? error.reason : null,
        message: typeof error?.message === 'string' && error.message.trim() !== ''
            ? error.message
            : 'No fue posible completar la operacion.',
        retryable: error?.retryable === true,
    };
}

function snapshot(source) {
    return {
        ...source,
        inputs: { ...source.inputs },
        query: { ...source.query },
        items: source.items.map((item) => ({
            ...item,
            product: { ...item.product },
            store: { ...item.store },
            availability: {
                ...item.availability,
                primary_cause: { ...item.availability.primary_cause },
                blocking_causes: item.availability.blocking_causes.map(
                    (cause) => ({ ...cause })
                ),
                blocking_codes: [...item.availability.blocking_codes],
                warnings: item.availability.warnings.map(
                    (cause) => ({ ...cause })
                ),
                warning_codes: [...item.availability.warning_codes],
            },
            references: { ...item.references },
            actions: { ...item.actions },
            routes: { ...item.routes },
        })),
        meta: source.meta === null ? null : { ...source.meta },
        error: source.error === null ? null : { ...source.error },
        context: {
            ...source.context,
            product: source.context.product === null ? null : { ...source.context.product },
            store: source.context.store === null ? null : { ...source.context.store },
        },
        form: {
            ...source.form,
            values: { ...source.form.values },
            initialValues: source.form.initialValues === null
                ? null
                : { ...source.form.initialValues },
            fieldErrors: { ...source.form.fieldErrors },
            error: source.form.error === null ? null : { ...source.form.error },
            contextProduct: source.form.contextProduct === null
                ? null
                : { ...source.form.contextProduct },
            contextStore: source.form.contextStore === null
                ? null
                : { ...source.form.contextStore },
            selectedProduct: source.form.selectedProduct === null
                ? null
                : { ...source.form.selectedProduct },
            selectedStore: source.form.selectedStore === null
                ? null
                : { ...source.form.selectedStore },
        },
    };
}
