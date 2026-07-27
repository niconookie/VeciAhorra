import { createOrdersApi } from './api.js';
import { createOrdersState } from './state.js';
import { buildOrdersUrl, readOrdersUrl } from './navigation.js';
import { buildOrderDetailUrl } from './detail-navigation.js';
import { createOrdersView } from './view.js';

initialize();

export function initialize() {
    const root = document.querySelector('#veciahorra-orders-admin');
    if (!root || root.dataset.initialized === 'true') return null;
    root.dataset.initialized = 'true';
    const config = JSON.parse(document.querySelector('#veciahorra-orders-config').textContent);
    const nodes = {
        root, form: document.querySelector('#veciahorra-orders-filters'),
        clear: document.querySelector('[data-orders-clear]'),
        status: document.querySelector('#veciahorra-orders-status'),
        list: document.querySelector('#veciahorra-orders-list'),
        pagination: document.querySelector('#veciahorra-orders-pagination'),
    };
    let query = readOrdersUrl(window.location.href);
    let view;
    const state = createOrdersState(createOrdersApi(config), (query), (next) => view.render(next));
    const apply = (next, push = true) => {
        query = { ...query, ...next, paged: Number(next.paged || 1), per_page: Number(next.per_page || 20) };
        view.sync(query);
        window.history[push ? 'pushState' : 'replaceState']({ orders: true }, '', buildOrdersUrl(config.adminUrl, query));
        state.load(query);
    };
    view = createOrdersView(nodes, {
        query: (next) => apply({ ...next, paged: 1 }),
        page: (paged) => apply({ paged }),
        detailUrl: (orderId, listContext) => buildOrderDetailUrl({
            adminUrl: config.adminUrl,
            orderId,
            listContext,
        }),
    });
    view.sync(query);
    apply(query, false);
    const pop = () => { query = readOrdersUrl(window.location.href); view.sync(query); state.load(query); };
    const destroy = () => {
        window.removeEventListener('popstate', pop); view.destroy(); state.destroy(); delete root.dataset.initialized;
    };
    window.addEventListener('popstate', pop);
    window.addEventListener('pagehide', destroy, { once: true });
    return { destroy, state };
}
