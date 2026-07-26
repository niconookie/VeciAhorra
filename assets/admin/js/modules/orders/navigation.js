const defaults = Object.freeze({ search: '', store_id: '', order_status: '', fulfillment_mode: '', date_from: '', date_to: '', sort: 'newest', paged: 1, per_page: 20 });
const allowed = new Set(Object.keys(defaults));
const statuses = new Set(['reserved', 'paid', 'delivered']);
const modes = new Set(['pickup', 'delivery']);
const sorts = new Set(['newest', 'oldest', 'updated', 'total_desc', 'total_asc']);

export function readOrdersUrl(url) {
    const source = new URL(url, window.location.origin);
    const query = { ...defaults };
    const unique = new Set();
    for (const [name] of source.searchParams) {
        if (name !== 'page' && (!allowed.has(name) || unique.has(name))) continue;
        unique.add(name);
        const value = source.searchParams.get(name);
        if (name === 'search' && validSearch(value)) query.search = value;
        if (name === 'store_id' && positive(value)) query.store_id = value;
        if (name === 'order_status' && statuses.has(value)) query.order_status = value;
        if (name === 'fulfillment_mode' && modes.has(value)) query.fulfillment_mode = value;
        if ((name === 'date_from' || name === 'date_to') && date(value)) query[name] = value;
        if (name === 'sort' && sorts.has(value)) query.sort = value;
        if (name === 'paged' && positive(value)) query.paged = Number(value);
        if (name === 'per_page' && ['20', '50', '100'].includes(value)) query.per_page = Number(value);
    }
    if (query.date_from && query.date_to && query.date_from > query.date_to) {
        query.date_from = '';
        query.date_to = '';
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

function positive(value) { return /^[1-9]\d*$/.test(value || ''); }
function date(value) { return /^\d{4}-\d{2}-\d{2}$/.test(value || ''); }
function validSearch(value) {
    return typeof value === 'string' && value.length > 0 && value.length <= 100
        && value.trim() === value && (!/^[+\-.]?\d/i.test(value) || positive(value))
        && (!value.toLowerCase().startsWith('checkout:') || /^checkout:[1-9]\d*$/i.test(value));
}
