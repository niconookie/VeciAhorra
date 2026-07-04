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

        return {
            payload,
            status: response.status,
        };
    }

    async function getProducts({ term = '', page = 1, perPage = 20 } = {}) {
        const response = await request(
            buildProductsUrl({ term, page, perPage }),
            { method: 'GET' }
        );

        assertResponse(response, isProductsResponse);

        return response.payload;
    }

    async function getProduct(id) {
        const response = await request(
            buildProductUrl(id),
            { method: 'GET' }
        );

        assertResponse(response, isProductDetailResponse);

        return response.payload;
    }

    async function createProduct(payload) {
        const response = await request(
            '/products',
            jsonRequestOptions('POST', payload)
        );

        assertResponse(response, isCreateProductResponse);

        return response.payload;
    }

    async function updateProduct(id, payload) {
        const response = await request(
            buildProductUrl(id),
            jsonRequestOptions('PATCH', payload)
        );

        assertResponse(response, isUpdateProductResponse);

        return response.payload;
    }

    async function updateProductStatus(id, status) {
        const response = await request(
            buildProductUrl(id, '/status'),
            jsonRequestOptions('PATCH', { status })
        );

        assertResponse(response, isUpdateProductStatusResponse);

        return response.payload;
    }

    return {
        request,
        getProducts,
        getProduct,
        createProduct,
        updateProduct,
        updateProductStatus,
    };
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

function buildProductUrl(id, suffix = '') {
    if (!isPositiveInteger(id)) {
        throw new ProductsApiError({
            type: 'invalid_request',
            code: 'invalid_product_id',
            message: 'El identificador del producto no es válido.',
            retryable: false,
        });
    }

    return `/products/${String(id)}${suffix}`;
}

function jsonRequestOptions(method, payload) {
    let body;

    try {
        body = JSON.stringify(payload);
    } catch (error) {
        throw new ProductsApiError({
            type: 'invalid_request',
            code: 'invalid_payload',
            message: 'No fue posible serializar los datos del producto.',
            retryable: false,
        });
    }

    return {
        method,
        headers: {
            'Content-Type': 'application/json',
        },
        body,
    };
}

function assertResponse(response, validator) {
    if (validator(response.payload)) {
        return;
    }

    throw new ProductsApiError({
        type: 'invalid_response',
        status: response.status,
        code: 'invalid_response',
        message: 'La respuesta del servidor no tiene el formato esperado.',
        retryable: false,
    });
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

function isProductDetailResponse(payload) {
    return isSuccessfulDataResponse(payload)
        && isProductDetail(payload.data);
}

function isCreateProductResponse(payload) {
    return isSuccessfulDataResponse(payload)
        && isPositiveInteger(payload.data.id);
}

function isUpdateProductResponse(payload) {
    return isSuccessfulDataResponse(payload)
        && isPositiveInteger(payload.data.id)
        && payload.data.updated === true;
}

function isUpdateProductStatusResponse(payload) {
    return isSuccessfulDataResponse(payload)
        && isPositiveInteger(payload.data.id)
        && ['active', 'inactive'].includes(payload.data.status);
}

function isSuccessfulDataResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && isObject(payload.data);
}

function isProductDetail(product) {
    return isProduct(product)
        && isNonEmptyString(product.slug)
        && isNullableString(product.description)
        && isNullablePositiveInteger(product.woo_product_id)
        && isNullablePositiveInteger(product.category_id)
        && isNullablePositiveInteger(product.brand_id)
        && isNullablePositiveInteger(product.unit_id)
        && isNullablePositiveInteger(product.image_id)
        && ['draft', 'active', 'inactive'].includes(product.status)
        && isNonEmptyString(product.created_at);
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

function isNullablePositiveInteger(value) {
    return value === null || isPositiveInteger(value);
}

function isNullableString(value) {
    return value === null || typeof value === 'string';
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
