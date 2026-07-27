import { normalizeOrdersListContext } from './navigation.js';

const RETURN_FIELDS = Object.freeze([
    ['search', 'return_search'],
    ['store_id', 'return_store_id'],
    ['order_status', 'return_order_status'],
    ['fulfillment_mode', 'return_fulfillment_mode'],
    ['date_from', 'return_date_from'],
    ['date_to', 'return_date_to'],
    ['sort', 'return_sort'],
    ['paged', 'return_paged'],
    ['per_page', 'return_per_page'],
]);

export function buildOrderDetailUrl({ adminUrl, orderId, listContext } = {}) {
    const id = canonicalId(orderId);
    const base = safeUrl(adminUrl);
    if (id === null || base === null) return null;

    const { url, relative } = base;
    url.hash = '';
    url.search = '';
    url.searchParams.set('page', 'veciahorra-orders');
    url.searchParams.set('action', 'view');
    url.searchParams.set('order_id', String(id));

    const context = normalizeOrdersListContext(listContext);
    for (const [source, target] of RETURN_FIELDS) {
        if (Object.hasOwn(context, source) && context[source] !== '') {
            url.searchParams.set(target, String(context[source]));
        }
    }

    return relative ? `${url.pathname}${url.search}` : url.toString();
}

function canonicalId(value) {
    const literal = typeof value === 'number' ? String(value) : value;
    if (typeof literal !== 'string' || !/^[1-9]\d*$/.test(literal)) return null;
    const numeric = Number(literal);
    return Number.isSafeInteger(numeric) && String(numeric) === literal ? numeric : null;
}

function safeUrl(value) {
    if (typeof value !== 'string' || value === '') return null;
    const relative = value.startsWith('/');
    let url;
    try {
        url = new URL(value, 'http://localhost');
    } catch {
        return null;
    }
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    return { url, relative };
}
