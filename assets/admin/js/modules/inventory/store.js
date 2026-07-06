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
    page: 1,
    perPage: 20,
};

const DEFAULT_VALUES = {
    productId: '',
    minimarketId: '',
    price: '',
    stock: '0',
    status: 'active',
};

export function createInventoryStore(api) {
    let state = {
        currentView: VIEW_LIST,
        status: STATUS_IDLE,
        inputs: { ...DEFAULT_FILTERS },
        query: { ...DEFAULT_FILTERS },
        items: [],
        meta: null,
        error: null,
        form: initialForm(),
    };
    let latestRequest = 0;
    let latestFormRequest = 0;
    let listNeedsReload = false;
    const listeners = new Set();

    function getState() {
        return snapshot(state);
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('El listener del store debe ser una funcion.');
        }

        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    function setState(next) {
        state = { ...state, ...next };
        listeners.forEach((listener) => listener(snapshot(state)));
    }

    function setForm(form) {
        setState({ form: { ...form } });
    }

    function setFilter(name, value) {
        if (!Object.hasOwn(DEFAULT_FILTERS, name) || name === 'page') {
            return;
        }

        setState({ inputs: { ...state.inputs, [name]: value } });
    }

    function applyFilters() {
        const query = {
            ...state.inputs,
            search: String(state.inputs.search).trim(),
            productId: String(state.inputs.productId).trim(),
            minimarketId: String(state.inputs.minimarketId).trim(),
            page: 1,
            perPage: Number(state.inputs.perPage),
        };

        return execute(query, { ...query });
    }

    function clearFilters() {
        return execute({ ...DEFAULT_FILTERS }, { ...DEFAULT_FILTERS });
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
            || (
                state.form.mode === FORM_EDIT
                && ['productId', 'minimarketId'].includes(field)
            )
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
                message: state.form.mode === FORM_CREATE
                    ? 'Inventario creado correctamente.'
                    : 'Inventario actualizado correctamente.',
            });
            return true;
        } catch (error) {
            setForm({
                ...state.form,
                status: STATUS_ERROR,
                error: normalizeError(error),
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

        if (listNeedsReload) {
            listNeedsReload = false;
            await execute(state.query, state.inputs);
        }

        return true;
    }

    async function execute(query, inputs) {
        const requestId = ++latestRequest;
        setState({
            status: STATUS_LOADING,
            inputs: { ...inputs },
            query: { ...query },
            items: [],
            meta: null,
            error: null,
        });

        try {
            const response = await api.getInventory(query);

            if (requestId !== latestRequest) {
                return false;
            }

            if (response.meta.total_pages > 0 && response.meta.page > response.meta.total_pages) {
                return execute({ ...query, page: response.meta.total_pages }, inputs);
            }

            const items = response.data.map(normalizeItem);
            setState({
                status: items.length === 0 ? STATUS_EMPTY : STATUS_SUCCESS,
                items,
                meta: normalizeMeta(response.meta),
            });
            return true;
        } catch (error) {
            if (requestId !== latestRequest) {
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
        save,
        returnToList,
    };
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

    if (productId === null) errors.productId = 'Ingrese un Product ID positivo.';
    if (minimarketId === null) errors.minimarketId = 'Ingrese un Minimarket ID positivo.';
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
    return /^[1-9]\d*$/.test(normalized) ? Number(normalized) : null;
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
        productId: Number(item.product_id),
        minimarketId: Number(item.minimarket_id),
        price: Number(item.price),
        stock: Number(item.stock),
        status: item.status,
        updatedAt: item.updated_at,
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
        items: source.items.map((item) => ({ ...item })),
        meta: source.meta === null ? null : { ...source.meta },
        error: source.error === null ? null : { ...source.error },
        form: {
            ...source.form,
            values: { ...source.form.values },
            initialValues: source.form.initialValues === null
                ? null
                : { ...source.form.initialValues },
            fieldErrors: { ...source.form.fieldErrors },
            error: source.form.error === null ? null : { ...source.form.error },
        },
    };
}
