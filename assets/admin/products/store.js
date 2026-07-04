export const STATUS_IDLE = 'idle';
export const STATUS_LOADING = 'loading';
export const STATUS_SUCCESS = 'success';
export const STATUS_EMPTY = 'empty';
export const STATUS_ERROR = 'error';

/**
 * Crea el estado mínimo de la lista de productos.
 */
export function createProductsStore(api) {
    let state = {
        status: STATUS_IDLE,
        products: [],
        meta: null,
        error: null,
    };
    let latestRequest = 0;
    const listeners = new Set();

    function getState() {
        return createSnapshot(state);
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('El listener del store debe ser una función.');
        }

        listeners.add(listener);

        return () => listeners.delete(listener);
    }

    function setState(nextState) {
        state = { ...state, ...nextState };
        listeners.forEach((listener) => listener(createSnapshot(state)));
    }

    async function loadProducts() {
        const requestId = ++latestRequest;

        setState({
            status: STATUS_LOADING,
            products: [],
            meta: null,
            error: null,
        });

        try {
            const response = await api.getProducts();

            if (requestId !== latestRequest) {
                return;
            }

            const products = response.data.map(normalizeProduct);

            setState({
                status: products.length === 0 ? STATUS_EMPTY : STATUS_SUCCESS,
                products,
                meta: normalizeMeta(response.meta),
                error: null,
            });
        } catch (error) {
            if (requestId !== latestRequest) {
                return;
            }

            setState({
                status: STATUS_ERROR,
                products: [],
                meta: null,
                error: normalizeError(error),
            });
        }
    }

    return { getState, subscribe, loadProducts };
}

function normalizeProduct(product) {
    return {
        id: displayValue(product.id),
        name: displayValue(product.name),
        sku: displayValue(product.sku),
        status: displayValue(product.status),
        updatedAt: displayValue(product.updated_at),
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
    if (error && typeof error === 'object') {
        return {
            type: typeof error.type === 'string' ? error.type : 'unknown',
            status: Number.isInteger(error.status) ? error.status : null,
            code: typeof error.code === 'string' ? error.code : 'unknown_error',
            message: typeof error.message === 'string' && error.message !== ''
                ? error.message
                : 'No fue posible cargar los productos.',
            retryable: error.retryable === true,
        };
    }

    return {
        type: 'unknown',
        status: null,
        code: 'unknown_error',
        message: 'No fue posible cargar los productos.',
        retryable: false,
    };
}

function displayValue(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}

function createSnapshot(state) {
    return {
        ...state,
        products: state.products.map((product) => ({ ...product })),
        meta: state.meta === null ? null : { ...state.meta },
        error: state.error === null ? null : { ...state.error },
    };
}
