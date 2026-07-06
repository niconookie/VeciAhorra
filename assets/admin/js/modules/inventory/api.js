export class InventoryApiError extends Error {
    constructor({ type, status = null, code = null, message, retryable = false }) {
        super(message);
        this.name = 'InventoryApiError';
        this.type = type;
        this.status = status;
        this.code = code;
        this.retryable = retryable;
    }
}

/**
 * Centraliza el transporte REST de la lista de Inventory.
 */
export function createInventoryApi({ restUrl, nonce }) {
    const baseUrl = restUrl.replace(/\/+$/, '');

    async function request(path, options = {}) {
        const headers = new Headers(options.headers || {
            Accept: 'application/json',
            'X-WP-Nonce': nonce,
        });
        headers.set('Accept', 'application/json');
        headers.set('X-WP-Nonce', nonce);
        let response;

        try {
            response = await fetch(
                `${baseUrl}/${String(path).replace(/^\/+/, '')}`,
                { ...options, headers, credentials: 'same-origin' }
            );
        } catch (error) {
            throw new InventoryApiError({
                type: 'network',
                code: 'network_error',
                message: 'No fue posible conectar con el servidor.',
                retryable: true,
            });
        }

        let payload;

        try {
            payload = JSON.parse(await response.text());
        } catch (error) {
            throw new InventoryApiError({
                type: 'invalid_json',
                status: response.status,
                code: 'invalid_json',
                message: 'El servidor devolvio una respuesta no valida.',
                retryable: response.status >= 500,
            });
        }

        if (!response.ok || payload?.success === false) {
            const apiError = payload?.error;

            throw new InventoryApiError({
                type: 'api',
                status: response.status,
                code: typeof apiError?.code === 'string'
                    ? apiError.code
                    : `http_${response.status}`,
                message: typeof apiError?.message === 'string'
                    ? apiError.message
                    : 'No fue posible cargar el inventario.',
                retryable: response.status >= 500 || response.status === 429,
            });
        }

        return { payload, status: response.status };
    }

    function getInventory(filters = {}) {
        return request(buildInventoryUrl(filters), { method: 'GET' })
            .then((response) => assertResponse(response, isInventoryResponse));
    }

    function getInventoryItem(id) {
        return request(buildItemUrl(id), { method: 'GET' })
            .then((response) => assertResponse(response, isDetailResponse));
    }

    function createInventory(payload) {
        return request('/inventory', jsonOptions('POST', payload))
            .then((response) => assertResponse(response, isCreateResponse));
    }

    function updateInventory(id, payload) {
        return request(buildItemUrl(id), jsonOptions('PATCH', payload))
            .then((response) => assertResponse(response, isUpdateResponse));
    }

    return { getInventory, getInventoryItem, createInventory, updateInventory };
}

export function buildInventoryUrl({
    search = '',
    productId = '',
    minimarketId = '',
    status = '',
    page = 1,
    perPage = 20,
} = {}) {
    const params = new URLSearchParams({
        page: String(page),
        per_page: String(perPage),
    });

    [
        ['search', search],
        ['product_id', productId],
        ['minimarket_id', minimarketId],
        ['status', status],
    ].forEach(([name, value]) => {
        const normalized = String(value ?? '').trim();

        if (normalized !== '') {
            params.set(name, normalized);
        }
    });

    return `/inventory?${params.toString()}`;
}

function isInventoryResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every(isInventoryRow)
        && isObject(payload.meta)
        && ['page', 'per_page', 'total', 'total_pages'].every(
            (field) => Number.isInteger(payload.meta[field])
        );
}

function isDetailResponse(payload) {
    return isObject(payload) && payload.success === true && isInventoryRow(payload.data);
}

function isCreateResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && isObject(payload.data)
        && isPositiveInteger(payload.data.id);
}

function isUpdateResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && isObject(payload.data)
        && isPositiveInteger(payload.data.id)
        && typeof payload.data.updated === 'boolean';
}

function assertResponse(response, validator) {
    if (!validator(response.payload)) {
        throw new InventoryApiError({
            type: 'invalid_response',
            status: response.status,
            code: 'invalid_response',
            message: 'La respuesta del servidor no tiene el formato esperado.',
        });
    }

    return response.payload;
}

function buildItemUrl(id) {
    if (!isPositiveInteger(id)) {
        throw new InventoryApiError({
            type: 'invalid_request',
            code: 'invalid_inventory_id',
            message: 'El identificador de inventario no es valido.',
        });
    }

    return `/inventory/${String(id)}`;
}

function jsonOptions(method, payload) {
    return {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    };
}

function isInventoryRow(row) {
    return isObject(row)
        && isPositiveInteger(row.id)
        && isPositiveInteger(row.product_id)
        && isPositiveInteger(row.minimarket_id)
        && isNonNegativeNumber(row.price)
        && isNonNegativeInteger(row.stock)
        && ['active', 'inactive'].includes(row.status)
        && typeof row.updated_at === 'string'
        && row.updated_at.trim() !== '';
}

function isPositiveInteger(value) {
    return (Number.isInteger(value) && value > 0)
        || (typeof value === 'string' && /^[1-9]\d*$/.test(value));
}

function isNonNegativeInteger(value) {
    return (Number.isInteger(value) && value >= 0)
        || (typeof value === 'string' && /^\d+$/.test(value));
}

function isNonNegativeNumber(value) {
    if (typeof value !== 'number' && typeof value !== 'string') {
        return false;
    }

    const number = Number(value);

    return Number.isFinite(number) && number >= 0;
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
