const defaults = Object.freeze({ search: '', store_id: '', order_status: '', fulfillment_mode: '', date_from: '', date_to: '', sort: 'newest', paged: 1, per_page: 20 });
const allowed = new Set(Object.keys(defaults));
const statuses = new Set(['reserved', 'paid', 'delivered']);
const modes = new Set(['pickup', 'delivery']);
const sorts = new Set(['newest', 'oldest', 'updated', 'total_desc', 'total_asc']);

export function readOrdersUrl(url) {
    const source = new URL(url, window.location.origin);
    const values = {};
    const unique = new Set();
    for (const [name] of source.searchParams) {
        if (name !== 'page' && (!allowed.has(name) || unique.has(name))) continue;
        unique.add(name);
        values[name] = source.searchParams.get(name);
    }
    return { ...defaults, ...normalizeOrdersListContext(values) };
}

export function normalizeOrdersListContext(source) {
    if (source === null || typeof source !== 'object' || Array.isArray(source)) return {};
    const query = {};
    if (validSearch(source.search)) query.search = source.search;
    if (positive(source.store_id)) query.store_id = String(source.store_id);
    if (statuses.has(source.order_status)) query.order_status = source.order_status;
    if (modes.has(source.fulfillment_mode)) query.fulfillment_mode = source.fulfillment_mode;
    if (date(source.date_from)) query.date_from = source.date_from;
    if (date(source.date_to)) query.date_to = source.date_to;
    if (sorts.has(source.sort)) query.sort = source.sort;
    if (positive(source.paged)) query.paged = Number(source.paged);
    if (['20', '50', '100'].includes(String(source.per_page))) query.per_page = Number(source.per_page);
    if (query.date_from && query.date_to && query.date_from > query.date_to) {
        delete query.date_from;
        delete query.date_to;
    }
    return query;
}

export function buildOrdersUrl(base, query) {
    const url = new URL(base, window.location.origin);
    url.search = '';
    url.searchParams.set('page', 'veciahorra-orders');
    Object.entries(query).forEach(([name, value]) => {
        if (!allowed.has(name) || value === '' || value === null) return;
        if (name === 'sort' && value === defaults.sort) return;
        if (name === 'paged' && Number(value) === 1) return;
        if (name === 'per_page' && Number(value) === 20) return;
        url.searchParams.set(name, String(value));
    });
    return url.toString();
}

export function toApiParams(query) {
    const params = new URLSearchParams();
    Object.entries(query).forEach(([name, value]) => {
        if (value !== '' && value !== null) params.set(name, String(value));
    });
    return params;
}

function positive(value) {
    const literal = typeof value === 'number' ? String(value) : value;
    return typeof literal === 'string' && /^[1-9]\d*$/.test(literal)
        && Number.isSafeInteger(Number(literal));
}
function date(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const parsed = new Date(`${value}T00:00:00Z`);
    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}
function validSearch(value) {
    return typeof value === 'string' && Array.from(value).length > 0
        && Array.from(value).length <= 100 && value.trim() === value
        && (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value) || positive(value))
        && (!value.toLowerCase().startsWith('checkout:') || /^checkout:[1-9]\d*$/i.test(value));
}
