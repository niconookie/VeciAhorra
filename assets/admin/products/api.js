/**
 * Error normalizado del cliente REST de Products.
 */
export class ProductsApiError extends Error {
    constructor({
        type,
        status = null,
        code = null,
        message,
        retryable = false,
    }) {
        super(message);
        this.name = 'ProductsApiError';
        this.type = type;
        this.status = status;
        this.code = code;
        this.retryable = retryable;
    }
}

/**
 * Crea el cliente REST central de la pantalla Products.
 */
export function createProductsApi({ restUrl, nonce }) {
    const baseUrl = restUrl.replace(/\/+$/, '');

    async function request(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-WP-Nonce', nonce);

        let response;

        try {
            response = await fetch(
                `${baseUrl}/${String(path).replace(/^\/+/, '')}`,
                {
                    ...options,
                    headers,
                    credentials: 'same-origin',
                }
            );
        } catch (error) {
            throw new ProductsApiError({
                type: 'network',
                code: 'network_error',
                message: 'No fue posible conectar con el servidor.',
                retryable: true,
            });
        }

        let rawBody;

        try {
            rawBody = await response.text();
        } catch (error) {
            throw new ProductsApiError({
                type: 'network',
                status: response.status,
                code: 'network_error',
                message: 'No fue posible leer la respuesta del servidor.',
                retryable: true,
            });
        }

        let payload;

        try {
            payload = JSON.parse(rawBody);
        } catch (error) {
            throw new ProductsApiError({
                type: 'invalid_json',
                status: response.status,
                code: 'invalid_json',
                message: 'El servidor devolvió una respuesta no válida.',
                retryable: response.status >= 500,
            });
        }

        if (!response.ok) {
            throw errorFromPayload(
                payload,
                'http',
                response.status,
                response.status >= 500 || response.status === 429
            );
        }

        if (isObject(payload) && payload.success === false) {
            throw errorFromPayload(payload, 'api', response.status, false);
        }

        if (!isProductsResponse(payload)) {
            throw new ProductsApiError({
                type: 'invalid_response',
                status: response.status,
                code: 'invalid_response',
                message: 'La respuesta del servidor no tiene el formato esperado.',
                retryable: false,
            });
        }

        return payload;
    }

    function getProducts({ term = '', page = 1, perPage = 20 } = {}) {
        return request(
            buildProductsUrl({ term, page, perPage }),
            { method: 'GET' }
        );
    }

    return { request, getProducts };
}

function buildProductsUrl({ term, page, perPage }) {
    const normalizedTerm = typeof term === 'string' ? term.trim() : '';
    const endpoint = normalizedTerm === '' ? '/products' : '/products/search';
    const params = new URLSearchParams({
        page: String(page),
        per_page: String(perPage),
    });

    if (normalizedTerm !== '') {
        params.set('term', normalizedTerm);
    }

    return `${endpoint}?${params.toString()}`;
}

function errorFromPayload(payload, type, status, retryable) {
    const moduleError = isObject(payload?.error) ? payload.error : null;
    const code = stringOrNull(moduleError?.code)
        || stringOrNull(payload?.code)
        || `http_${status}`;
    const message = stringOrNull(moduleError?.message)
        || stringOrNull(payload?.message)
        || `La solicitud falló con el código HTTP ${status}.`;

    return new ProductsApiError({
        type,
        status,
        code,
        message,
        retryable,
    });
}

function isProductsResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every(isProduct)
        && isObject(payload.meta)
        && Number.isInteger(payload.meta.page)
        && Number.isInteger(payload.meta.per_page)
        && Number.isInteger(payload.meta.total)
        && Number.isInteger(payload.meta.total_pages);
}

function isProduct(product) {
    return isObject(product)
        && isPositiveInteger(product.id)
        && isNonEmptyString(product.name)
        && (typeof product.sku === 'string' || product.sku === null)
        && isNonEmptyString(product.status)
        && isNonEmptyString(product.updated_at);
}

function isPositiveInteger(value) {
    if (Number.isInteger(value)) {
        return value > 0;
    }

    return typeof value === 'string'
        && /^[1-9]\d*$/.test(value);
}

function isNonEmptyString(value) {
    return typeof value === 'string' && value.trim() !== '';
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function stringOrNull(value) {
    return typeof value === 'string' && value.trim() !== ''
        ? value
        : null;
}
