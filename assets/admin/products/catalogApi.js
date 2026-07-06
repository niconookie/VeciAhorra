/**
 * Error normalizado del cliente REST de Product Catalogs.
 */
export class CatalogApiError extends Error {
    constructor({
        type,
        status = null,
        code = null,
        message,
        retryable = false,
    }) {
        super(message);
        this.name = 'CatalogApiError';
        this.type = type;
        this.status = status;
        this.code = code;
        this.retryable = retryable;
    }
}

/**
 * Crea el cliente REST de los catálogos del formulario de productos.
 */
export function createCatalogApi({ restUrl, nonce }) {
    const baseUrl = restUrl.replace(/\/+$/, '');

    async function request(path) {
        const headers = new Headers({
            Accept: 'application/json',
            'X-WP-Nonce': nonce,
        });
        let response;

        try {
            response = await fetch(
                `${baseUrl}/${String(path).replace(/^\/+/, '')}`,
                {
                    method: 'GET',
                    headers,
                    credentials: 'same-origin',
                }
            );
        } catch (error) {
            throw new CatalogApiError({
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
            throw new CatalogApiError({
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
            throw new CatalogApiError({
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

        if (payload?.success === false) {
            throw errorFromPayload(payload, 'api', response.status, false);
        }

        if (!isCatalogResponse(payload)) {
            throw new CatalogApiError({
                type: 'invalid_response',
                status: response.status,
                code: 'invalid_response',
                message: 'La respuesta del catálogo no es válida.',
                retryable: false,
            });
        }

        return {
            success: true,
            data: payload.data.map((item) => ({
                id: Number(item.id),
                name: item.name.trim(),
            })),
        };
    }

    return {
        loadCategories: () => request('/categories'),
        loadBrands: () => request('/brands'),
        loadUnits: () => request('/units'),
    };
}

function errorFromPayload(payload, type, status, retryable) {
    const moduleError = isObject(payload?.error) ? payload.error : null;
    const code = nonEmptyString(moduleError?.code)
        ? moduleError.code.trim()
        : `http_${status}`;
    const message = nonEmptyString(moduleError?.message)
        ? moduleError.message.trim()
        : `La solicitud falló con el código HTTP ${status}.`;

    return new CatalogApiError({
        type,
        status,
        code,
        message,
        retryable,
    });
}

function isCatalogResponse(payload) {
    return isObject(payload)
        && payload.success === true
        && Array.isArray(payload.data)
        && payload.data.every(isCatalogItem);
}

function isCatalogItem(item) {
    return isObject(item)
        && isPositiveInteger(item.id)
        && nonEmptyString(item.name);
}

function isPositiveInteger(value) {
    if (Number.isInteger(value)) {
        return value > 0 && Number.isSafeInteger(value);
    }

    return typeof value === 'string'
        && /^[1-9]\d*$/.test(value)
        && Number.isSafeInteger(Number(value));
}

function nonEmptyString(value) {
    return typeof value === 'string' && value.trim() !== '';
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
