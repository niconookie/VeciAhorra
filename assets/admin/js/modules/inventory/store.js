export const STATUS_IDLE = 'idle';
export const STATUS_LOADING = 'loading';
export const STATUS_SUCCESS = 'success';
export const STATUS_EMPTY = 'empty';
export const STATUS_ERROR = 'error';

const DEFAULT_FILTERS = {
    search: '',
    productId: '',
    minimarketId: '',
    status: '',
    page: 1,
    perPage: 20,
};

export function createInventoryStore(api) {
    let state = {
        status: STATUS_IDLE,
        inputs: { ...DEFAULT_FILTERS },
        query: { ...DEFAULT_FILTERS },
        items: [],
        meta: null,
        error: null,
    };
    let latestRequest = 0;
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

    function setFilter(name, value) {
        if (!Object.hasOwn(DEFAULT_FILTERS, name) || name === 'page') {
            return;
        }

        setState({
            inputs: {
                ...state.inputs,
                [name]: value,
            },
        });
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
        return execute(
            { ...DEFAULT_FILTERS },
            { ...DEFAULT_FILTERS }
        );
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

            if (
                response.meta.total_pages > 0
                && response.meta.page > response.meta.total_pages
            ) {
                return execute(
                    { ...query, page: response.meta.total_pages },
                    inputs
                );
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
    };
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
            : 'No fue posible cargar el inventario.',
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
    };
}
