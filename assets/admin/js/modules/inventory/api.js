export class InventoryApiError extends Error {
    constructor({
        type,
        status = null,
        code = null,
        field = null,
        reason = null,
        message,
        retryable = false,
    }) {
        super(message);
        this.name = 'InventoryApiError';
        this.type = type;
        this.status = status;
        this.code = code;
        this.field = field;
        this.reason = reason;
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
            if (error?.name === 'AbortError') {
                throw error;
            }

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
                field: typeof apiError?.details?.field === 'string'
                    ? apiError.details.field
                    : null,
                reason: typeof apiError?.details?.reason === 'string'
                    ? apiError.details.reason
                    : null,
                message: typeof apiError?.message === 'string'
                    ? apiError.message
                    : 'No fue posible cargar el inventario.',
                retryable: response.status >= 500 || response.status === 429,
            });
        }

        return { payload, status: response.status };
    }

    function getInventory(filters = {}, { signal } = {}) {
        return request(buildInventoryUrl(filters), { method: 'GET', signal })
            .then((response) => assertResponse(
                response,
                isInventoryAdminResponse
            ));
    }

    function getInventoryItem(id) {
        return request(buildItemUrl(id), { method: 'GET' })
            .then((response) => assertResponse(response, isDetailResponse));
    }

    function getInventoryAdminDetail(id, { signal } = {}) {
        if (!isPositiveInteger(id)) {
            throw new InventoryApiError({
                type: 'invalid_request',
                code: 'invalid_inventory_id',
                message: 'El identificador de Inventory no es válido.',
            });
        }
        return request(`/inventory/${String(id)}/admin`, {
            method: 'GET',
            signal,
        }).then((response) => assertResponse(
            response,
            (payload) => isInventoryAdminDetailResponse(payload, Number(id))
        ));
    }

    function getProduct(id) {
        if (!isPositiveInteger(id)) {
            throw new InventoryApiError({
                type: 'invalid_request',
                code: 'invalid_product_id',
                message: 'El identificador del producto no es valido.',
            });
        }

        return request(`/products/${String(id)}`, { method: 'GET' })
            .then((response) => assertResponse(response, isProductResponse))
            .then((payload) => {
                if (Number(payload.data.id) !== Number(id)) {
                    throw new InventoryApiError({
                        type: 'invalid_response',
                        code: 'invalid_response',
                        message: 'La respuesta del servidor no tiene el formato esperado.',
                    });
                }
                return payload;
            });
    }

    function getStore(id) {
        if (!isPositiveInteger(id)) {
            throw new InventoryApiError({ type: 'invalid_request', code: 'invalid_store_id', message: 'El identificador del minimarket no es valido.' });
        }
        return request(`/stores/${String(id)}`, { method: 'GET' })
            .then((response) => assertResponse(response, isStoreContextResponse))
            .then((payload) => {
                if (Number(payload.data.id) !== Number(id)) throw new InventoryApiError({ type: 'invalid_response', code: 'invalid_response', message: 'La respuesta del servidor no tiene el formato esperado.' });
                return payload;
            });
    }

    function searchProducts(term, { page = 1, perPage = 10 } = {}) {
        const normalizedTerm = String(term ?? '').trim();

        if (normalizedTerm.length < 2) {
            throw new InventoryApiError({
                type: 'invalid_request',
                code: 'invalid_product_search',
                message: 'La busqueda requiere al menos dos caracteres.',
            });
        }

        const params = new URLSearchParams({
            term: normalizedTerm,
            page: String(page),
            per_page: String(perPage),
        });

        return request(`/products/search?${params.toString()}`, { method: 'GET' })
            .then((response) => assertResponse(response, isProductSearchResponse));
    }

    function searchStores(term, { page = 1, perPage = 10, signal } = {}) {
        const normalizedTerm = String(term ?? '').trim();

        if (normalizedTerm.length < 2) {
            throw new InventoryApiError({
                type: 'invalid_request',
                code: 'invalid_store_search',
                message: 'La busqueda requiere al menos dos caracteres.',
            });
        }

        const params = new URLSearchParams({
            search: normalizedTerm,
            page: String(page),
            per_page: String(perPage),
            order_by: 'business_name',
            direction: 'ASC',
        });

        return request(`/stores?${params.toString()}`, { method: 'GET', signal })
            .then((response) => assertResponse(response, isStoreSearchResponse));
    }

    function createInventory(payload) {
        return request('/inventory', jsonOptions('POST', payload))
            .then((response) => assertResponse(response, isCreateResponse));
    }

    function updateInventory(id, payload) {
        return request(buildItemUrl(id), jsonOptions('PATCH', payload))
            .then((response) => assertResponse(response, isUpdateResponse));
    }

    return {
        getInventory,
        getInventoryItem,
        getInventoryAdminDetail,
        getProduct,
        getStore,
        searchProducts,
        searchStores,
        createInventory,
        updateInventory,
    };
}

export function buildInventoryUrl({
    search = '',
    productId = '',
    minimarketId = '',
    status = '',
    availability = '',
    cause = '',
    page = 1,
    perPage = 20,
    orderBy = 'updated_at',
    direction = 'DESC',
} = {}) {
    const params = new URLSearchParams({
        page: String(page),
        per_page: String(perPage),
        order_by: String(orderBy),
        direction: String(direction),
    });

    [
        ['search', search],
        ['product_id', productId],
        ['minimarket_id', minimarketId],
        ['status', status],
        ['availability', availability],
        ['cause', cause],
    ].forEach(([name, value]) => {
        const normalized = String(value ?? '').trim();

        if (normalized !== '') {
            params.set(name, normalized);
        }
    });

    return `/inventory/admin?${params.toString()}`;
}

function isInventoryAdminResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every(isInventoryAdminRow)
        && isObject(payload.meta)
        && ['page', 'per_page', 'total', 'total_pages'].every(
            (field) => Number.isInteger(payload.meta[field])
        )
        && typeof payload.meta.snapshot_consistent === 'boolean';
}

function isDetailResponse(payload) {
    return isObject(payload) && payload.success === true && isInventoryRow(payload.data);
}

function isProductResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && isObject(payload.data)
        && isPositiveInteger(payload.data.id)
        && typeof payload.data.name === 'string'
        && payload.data.name.trim() !== ''
        && ['draft', 'active', 'inactive'].includes(payload.data.status);
}

function isStoreContextResponse(payload) {
    return isObject(payload) && payload.success === true && isObject(payload.data)
        && isPositiveInteger(payload.data.id)
        && typeof payload.data.business_name === 'string' && payload.data.business_name.trim() !== ''
        && ['pending', 'active', 'inactive', 'rejected'].includes(String(payload.data.status))
        && ['pending', 'active', 'inactive', 'rejected', 'invalid'].includes(String(payload.data.lifecycle_state));
}

function isProductSearchResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every((product) => (
            isObject(product)
            && isPositiveInteger(product.id)
            && typeof product.name === 'string'
            && product.name.trim() !== ''
            && ['draft', 'active', 'inactive'].includes(product.status)
        ))
        && isObject(payload.meta)
        && ['page', 'per_page', 'total', 'total_pages'].every(
            (field) => Number.isInteger(payload.meta[field])
        );
}

function isStoreSearchResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every((store) => (
            isObject(store)
            && isPositiveInteger(store.id)
            && typeof store.name === 'string'
            && store.name.trim() !== ''
            && ['pending', 'active', 'inactive', 'rejected'].includes(store.status)
            && typeof store.onboarding_status === 'string'
            && store.onboarding_status.trim() !== ''
            && (store.approved_at === null || typeof store.approved_at === 'string')
            && isStoreLocation(store.location)
        ))
        && isObject(payload.meta)
        && ['page', 'per_page', 'total', 'total_pages'].every(
            (field) => Number.isInteger(payload.meta[field])
        )
        && typeof payload.meta.has_next === 'boolean';
}

function isStoreLocation(location) {
    return isObject(location)
        && ['commune', 'city', 'region'].every((field) => (
            location[field] === null || typeof location[field] === 'string'
        ));
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

function isInventoryAdminRow(row) {
    return isObject(row)
        && isPositiveInteger(row.id)
        && isAdministrativeEntity(row.product)
        && isAdministrativeEntity(row.store)
        && isNonNegativeNumber(row.price)
        && isNonNegativeInteger(row.stock)
        && typeof row.status === 'string'
        && isObject(row.availability)
        && typeof row.availability.is_publicly_available === 'boolean'
        && isCause(row.availability.primary_cause)
        && (!Object.hasOwn(row.availability, 'blocking_causes')
            || (
                Array.isArray(row.availability.blocking_causes)
                && row.availability.blocking_causes.every(isCause)
            ))
        && Array.isArray(row.availability.blocking_codes)
        && row.availability.blocking_codes.every(isSafeCode)
        && Array.isArray(row.availability.warning_codes)
        && row.availability.warning_codes.every(isSafeCode)
        && (!Object.hasOwn(row.availability, 'warnings')
            || (
                Array.isArray(row.availability.warnings)
                && row.availability.warnings.every(isCause)
            ))
        && isObject(row.references)
        && ['has_cart_items', 'has_active_reservations', 'has_history']
            .every((field) => typeof row.references[field] === 'boolean')
        && ['complete', 'failed', 'partial'].includes(
            row.references.inspection_status
        )
        && typeof row.updated_at === 'string'
        && isObject(row.actions)
        && typeof row.actions.view === 'boolean'
        && typeof row.actions.edit === 'boolean'
        && !Object.hasOwn(row.actions, 'delete')
        && isObject(row.routes);
}

function isInventoryAdminDetailResponse(payload, expectedId) {
    const item = payload?.data;
    const availability = item?.availability;
    const references = item?.references;
    return isObject(payload)
        && payload.success === true
        && isObject(item)
        && isObject(item.identity)
        && Number(item.identity.id) === expectedId
        && isPositiveInteger(item.identity.id)
        && typeof item.identity.created_at === 'string'
        && typeof item.identity.updated_at === 'string'
        && isObject(item.offer)
        && isNonNegativeInteger(item.offer.product_id)
        && isNonNegativeInteger(item.offer.minimarket_id)
        && isObservedPrice(item.offer.price)
        && Number.isInteger(Number(item.offer.stock))
        && typeof item.offer.status === 'string'
        && isDetailProduct(item.product)
        && isDetailStore(item.store)
        && isObject(availability)
        && typeof availability.is_publicly_available === 'boolean'
        && isCause(availability.primary_cause)
        && causeCollection(availability, 'blocking_causes', 'blocking_codes')
        && causeCollection(availability, 'warnings', 'warning_codes')
        && isObject(availability.dimensions)
        && ['references', 'inventory', 'product', 'store', 'price', 'stock']
            .every((key) => isObject(availability.dimensions[key]))
        && typeof availability.evaluated_policy === 'string'
        && isDetailReferences(references)
        && isObject(item.lifecycle)
        && typeof item.lifecycle.status === 'string'
        && Array.isArray(item.lifecycle.allowed_actions)
        && item.lifecycle.allowed_actions.every(isSafeCode)
        && isObject(item.concurrency)
        && typeof item.concurrency.version === 'string'
        && item.concurrency.mode === 'last_write_wins'
        && typeof item.concurrency.last_observed_at === 'string'
        && isObject(item.actions)
        && typeof item.actions.edit === 'boolean'
        && !Object.hasOwn(item.actions, 'delete')
        && isObject(item.routes);
}

function isDetailProduct(product) {
    return isObject(product)
        && typeof product.exists === 'boolean'
        && isNonNegativeInteger(product.id)
        && ['name', 'slug', 'sku', 'status'].every(
            (key) => product[key] === null || typeof product[key] === 'string'
        )
        && isObject(product.image)
        && (product.image.id === null || isPositiveInteger(product.image.id))
        && (product.image.url === null || typeof product.image.url === 'string')
        && ['absent', 'missing_attachment', 'unavailable', 'valid']
            .includes(product.image.status);
}

function isDetailStore(store) {
    return isObject(store)
        && typeof store.exists === 'boolean'
        && isNonNegativeInteger(store.id)
        && ['name', 'status', 'onboarding_status', 'approved_at', 'lifecycle_state']
            .every((key) => store[key] === null || typeof store[key] === 'string')
        && isObject(store.location)
        && ['commune', 'city', 'region'].every(
            (key) => store.location[key] === null
                || typeof store.location[key] === 'string'
        );
}

function causeCollection(availability, objectsKey, codesKey) {
    const objectsPresent = Object.hasOwn(availability, objectsKey);
    const codesPresent = Object.hasOwn(availability, codesKey);
    return (objectsPresent || codesPresent)
        && (!objectsPresent || (
            Array.isArray(availability[objectsKey])
            && availability[objectsKey].every(isCause)
        ))
        && (!codesPresent || (
            Array.isArray(availability[codesKey])
            && availability[codesKey].every(isSafeCode)
        ));
}

function isObservedPrice(value) {
    return (typeof value === 'string' && value.trim() !== '')
        || (typeof value === 'number' && Number.isFinite(value));
}

function isDetailReferences(references) {
    const reservations = references?.reservations;
    return isObject(references)
        && ['complete', 'failed', 'partial'].includes(
            references.inspection_status
        )
        && typeof references.classification === 'string'
        && isObject(references.cart)
        && isNonNegativeInteger(references.cart.total)
        && isObject(reservations)
        && ['total', 'active', 'released', 'expired', 'consumed', 'unknown']
            .every((key) => isNonNegativeInteger(reservations[key]))
        && (
            reservations.active_quantity === null
            || isNonNegativeInteger(reservations.active_quantity)
        )
        && isObject(references.order_items)
        && isNonNegativeInteger(references.order_items.total)
        && Array.isArray(references.warning_codes)
        && references.warning_codes.every(isSafeCode);
}

function isAdministrativeEntity(entity) {
    return isObject(entity)
        && isPositiveInteger(entity.id)
        && typeof entity.exists === 'boolean'
        && (entity.name === null || typeof entity.name === 'string')
        && (entity.status === null || typeof entity.status === 'string');
}

function isCause(cause) {
    return isObject(cause)
        && isSafeCode(cause.code)
        && typeof cause.label === 'string'
        && typeof cause.severity === 'string';
}

function isSafeCode(value) {
    return typeof value === 'string' && /^[a-z][a-z0-9_]*$/.test(value);
}

function isPositiveInteger(value) {
    if (Number.isSafeInteger(value)) return value > 0;
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) return false;
    return Number.isSafeInteger(Number(value));
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
