import { toApiParams } from './navigation.js';

export class OrdersApiError extends Error {
    constructor(kind, status = 0) { super(kind); this.kind = kind; this.status = status; }
}

export function createOrdersApi(config) {
    return {
        async list(query, { signal } = {}) {
            let response;
            try {
                response = await fetch(`${config.restUrl}?${toApiParams(query)}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
                    signal,
                });
            } catch (error) {
                if (error?.name === 'AbortError') throw error;
                throw new OrdersApiError('network');
            }
            let payload;
            try { payload = JSON.parse(await response.text()); }
            catch { throw new OrdersApiError('invalid_json', response.status); }
            if (!response.ok) throw new OrdersApiError(`http_${response.status}`, response.status);
            if (!validPayload(payload)) throw new OrdersApiError('invalid_response', response.status);
            return payload;
        },
    };
}

function validPayload(payload) {
    return object(payload) && Array.isArray(payload.items) && payload.items.every(validItem)
        && object(payload.pagination)
        && ['page', 'per_page', 'total', 'total_pages'].every((key) => Number.isInteger(payload.pagination[key]));
}
function validItem(item) {
    return object(item) && Number.isInteger(item.id) && item.id > 0
        && typeof item.primary_state === 'string' && object(item.dimensions)
        && Array.isArray(item.allowed_actions)
        && item.allowed_actions.length === 1
        && item.allowed_actions[0] === 'view'
        && Array.isArray(item.mutable_actions) && item.mutable_actions.length === 0;
}
function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
