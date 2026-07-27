const ERROR_KINDS = new Set([
    'unauthorized',
    'forbidden',
    'not_found',
    'invalid_request',
    'server_error',
    'network_error',
    'aborted',
    'invalid_response',
]);

const BACKEND_CODES = new Set([
    'rest_not_authenticated',
    'rest_nonce_missing',
    'rest_forbidden',
    'rest_cookie_invalid_nonce',
    'order_not_found',
    'invalid_parameters',
    'orders_admin_detail_read_failed',
]);

class OrderDetailTransportError extends Error {
    constructor(kind, status = 0, code = null) {
        super(ERROR_KINDS.has(kind) ? kind : 'invalid_response');
        this.name = 'OrderDetailTransportError';
        this.kind = ERROR_KINDS.has(kind) ? kind : 'invalid_response';
        this.status = Number.isInteger(status) ? status : 0;
        this.code = BACKEND_CODES.has(code) ? code : null;
    }
}

export function createOrderDetailTransport(config) {
    const restUrl = validRestUrl(config?.restUrl);
    const nonce = typeof config?.nonce === 'string' && config.nonce !== ''
        ? config.nonce
        : null;
    const configuredId = canonicalId(config?.orderId);

    return Object.freeze({
        async getOrderDetail(orderId, { signal } = {}) {
            const id = canonicalId(orderId);
            if (
                restUrl === null
                || nonce === null
                || configuredId === null
                || id === null
                || id !== configuredId
            ) {
                throw new OrderDetailTransportError('invalid_request');
            }

            let response;
            try {
                response = await fetch(`${restUrl}/${id}/admin`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-WP-Nonce': nonce,
                    },
                    signal,
                });
            } catch (error) {
                throw new OrderDetailTransportError(
                    error?.name === 'AbortError' ? 'aborted' : 'network_error'
                );
            }

            let payload = null;
            try {
                const raw = await response.text();
                payload = raw === '' ? null : JSON.parse(raw);
            } catch (error) {
                if (error?.name === 'AbortError') {
                    throw new OrderDetailTransportError('aborted', response.status);
                }
                if (response.ok) {
                    throw new OrderDetailTransportError('invalid_response', response.status);
                }
            }

            if (! response.ok) {
                throw new OrderDetailTransportError(
                    errorKind(response.status),
                    response.status,
                    backendCode(payload)
                );
            }
            if (! validDetail(payload, Number(id))) {
                throw new OrderDetailTransportError('invalid_response', response.status);
            }

            return payload;
        },
    });
}

function validRestUrl(value) {
    if (typeof value !== 'string' || value === '' || value.includes('?') || value.includes('#')) {
        return null;
    }
    return value.replace(/\/+$/, '');
}

function canonicalId(value) {
    if (Number.isSafeInteger(value) && value > 0) {
        return String(value);
    }
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) {
        return null;
    }
    const numeric = Number(value);
    return Number.isSafeInteger(numeric) && String(numeric) === value ? value : null;
}

function errorKind(status) {
    if (status === 401) return 'unauthorized';
    if (status === 403) return 'forbidden';
    if (status === 404) return 'not_found';
    if (status === 400 || status === 422) return 'invalid_request';
    return status >= 500 ? 'server_error' : 'invalid_response';
}

function backendCode(payload) {
    if (!object(payload)) return null;
    if (typeof payload.code === 'string') return payload.code;
    return typeof payload.error?.code === 'string' ? payload.error.code : null;
}

function validDetail(payload, expectedId) {
    return object(payload)
        && object(payload.identity)
        && Number.isSafeInteger(payload.identity.id)
        && payload.identity.id === expectedId
        && object(payload.customer)
        && ['linked', 'unknown'].includes(payload.customer.relationship_status)
        && object(payload.store)
        && (payload.checkout === null || object(payload.checkout))
        && (payload.checkout_order === null || object(payload.checkout_order))
        && Array.isArray(payload.lines)
        && Array.isArray(payload.reservations)
        && object(payload.payment)
        && object(payload.processing)
        && object(payload.fulfillment)
        && object(payload.totals)
        && object(payload.navigation)
        && object(payload.operational)
        && object(payload.operational.dimensions)
        && object(payload.operational.consistency)
        && Array.isArray(payload.operational.timeline)
        && Array.isArray(payload.operational.allowed_actions)
        && payload.operational.allowed_actions.length === 1
        && payload.operational.allowed_actions[0] === 'view'
        && Array.isArray(payload.operational.mutable_actions)
        && payload.operational.mutable_actions.length === 0
        && object(payload.inspector);
}

function object(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
