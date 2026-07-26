import { createInventoryApi } from './api.js';
import { createInventoryStore } from './store.js';
import { createInventoryView } from './view.js';
import {
    buildContextUrl,
    buildStoreContextUrl,
    contextProductErrorMessage,
    readAdminContext,
} from './context.js';
import {
    buildInventoryActionUrl,
    buildInventoryListUrl,
    readInventoryDetailUrl,
    readInventoryEditUrl,
    readInventoryListUrl,
} from './list-navigation.js';

try {
    initialize();
} catch (error) {
    showInitializationError(error);
}

function initialize() {
    const config = readConfig();
    const nodes = findRequiredNodes();
    const api = createInventoryApi(config);
    if (nodes.root.dataset.inventoryInitialized === 'true') return;
    nodes.root.dataset.inventoryInitialized = 'true';
    const store = createInventoryStore(api, {
        onQueryChange(query, push) {
            const url = buildInventoryListUrl(config.adminUrl, query);
            window.history[push ? 'pushState' : 'replaceState'](
                { inventoryList: true },
                '',
                url
            );
        },
    });
    const view = createInventoryView(nodes, {
        onFilter: (name, value) => store.setFilter(name, value),
        onSearch: () => store.applyFilters(),
        onClear: () => store.clearFilters(),
        onReload: () => store.reload(),
        onPage: (page) => store.goToPage(page),
        onNew: () => openCreateForm(store, config.adminUrl),
        onEdit: (id) => store.openEditForm(id),
        onDetailEdit: (id) => openDetailEdit(store, config.adminUrl, id),
        onDetailRetry: () => store.retryDetail(),
        onFormField: (field, value) => store.setFormField(field, value),
        onProductSelected: (product) => store.selectProduct(product),
        onProductCleared: () => store.clearSelectedProduct(),
        searchProducts: (term) => api.searchProducts(term),
        onStoreSelected: (selectedStore) => store.selectStore(selectedStore),
        onStoreCleared: () => store.clearSelectedStore(),
        searchStores: (term, options) => api.searchStores(term, options),
        onSave: () => saveAndReturn(store, config.adminUrl),
        onCancel: () => window.history.state?.returnDetail
            ? returnFromForm(store, config.adminUrl, config.storeAdminUrl)
            : returnToList(store, config.adminUrl, config.storeAdminUrl),
        allInventoryUrl: config.adminUrl,
        contextualListUrl: (id) => buildContextUrl(config.adminUrl, id),
        contextualCreateUrl: (id) => buildContextUrl(config.adminUrl, id, 'create'),
        contextualStoreCreateUrl: (id) => buildStoreContextUrl(config.adminUrl, id, 'create'),
        storeDetailUrl: (id) => buildStoreDetailUrl(config.storeAdminUrl, id),
        actionUrl: (action, id, query) =>
            buildInventoryActionUrl(config.adminUrl, action, id, query),
        listUrl: (query) => buildInventoryListUrl(config.adminUrl, query),
    });

    const unsubscribe = store.subscribe(view.render);
    view.render(store.getState());
    initializeRoute(store, api, config);
    const onPopState = () => initializeRoute(store, api, config);
    const destroy = () => {
        window.removeEventListener('popstate', onPopState);
        unsubscribe();
        view.destroy();
        store.destroy();
        delete nodes.root.dataset.inventoryInitialized;
    };
    window.addEventListener('popstate', onPopState);
    window.addEventListener('pagehide', destroy, { once: true });
}

function initializeRoute(store, api, config) {
    const raw = new URL(window.location.href);
    if (raw.searchParams.get('action') === 'create') {
        initializeContext(store, api, readAdminContext(window.location.href));
        return;
    }
    if (raw.searchParams.get('action') === 'edit') {
        const edit = readInventoryEditUrl(window.location.href, config.adminUrl);
        if (!edit.valid) {
            store.rejectContext(edit.message);
            return;
        }
        window.history.replaceState(
            { ...(window.history.state || {}), inventoryEdit: edit.id },
            '',
            edit.canonicalUrl
        );
        store.prepareListQuery(edit.query);
        store.openEditForm(edit.id);
        return;
    }
    if (raw.searchParams.get('action') === 'view') {
        const detail = readInventoryDetailUrl(
            window.location.href,
            config.adminUrl
        );
        if (!detail.valid) {
            store.rejectContext(detail.message);
            return;
        }
        window.history.replaceState(
            {
                inventoryDetail: detail.id,
                returnQuery: detail.query,
            },
            '',
            detail.canonicalUrl
        );
        store.openDetail(detail.id, detail.query);
        return;
    }

    const parsed = readInventoryListUrl(window.location.href, config.adminUrl);
    if (!parsed.valid) {
        store.rejectContext(parsed.message);
        return;
    }
    if (parsed.query.productId) {
        store.applyListContext(
            { kind: 'product' },
            parsed.query
        );
        return;
    }
    if (parsed.query.minimarketId) {
        store.applyListContext(
            { kind: 'store' },
            parsed.query
        );
        return;
    }
    store.initializeList(parsed.query);
}

function openCreateForm(store, adminUrl) {
    const context = store.getState().context;

    if (context.status === 'ready') {
        if (context.kind === 'store') {
            window.location.assign(buildStoreContextUrl(adminUrl, context.store.id, 'create'));
            return;
        }
        window.location.assign(buildContextUrl(adminUrl, context.product.id, 'create'));
        return;
    }

    store.openCreateForm();
}

function returnToList(store, adminUrl, storeAdminUrl) {
    const context = store.getState().context;

    if (context.status === 'ready') {
        if (context.kind === 'store') {
            window.location.assign(buildStoreDetailUrl(storeAdminUrl, context.store.id));
            return;
        }
        window.location.assign(buildContextUrl(adminUrl, context.product.id));
        return;
    }

    window.history.replaceState(
        { inventoryList: true },
        '',
        buildInventoryListUrl(adminUrl, store.getState().query)
    );
    store.returnToList();
}

function openDetailEdit(store, adminUrl, id) {
    const query = store.getState().detail.returnQuery;
    const editUrl = buildInventoryActionUrl(adminUrl, 'edit', id, query);
    window.history.pushState(
        { inventoryEdit: id, returnDetail: id, returnQuery: query },
        '',
        editUrl
    );
    store.prepareListQuery(query);
    store.openEditForm(id);
}

function returnFromForm(store, adminUrl, storeAdminUrl) {
    const historyState = window.history.state;
    if (
        Number.isSafeInteger(Number(historyState?.returnDetail))
        && Number(historyState.returnDetail) > 0
    ) {
        const id = Number(historyState.returnDetail);
        const query = historyState.returnQuery || store.getState().query;
        const url = buildInventoryActionUrl(adminUrl, 'view', id, query);
        window.history.replaceState(
            { inventoryDetail: id, returnQuery: query },
            '',
            url
        );
        store.openDetail(id, query);
        return;
    }
    returnToList(store, adminUrl, storeAdminUrl);
}

async function saveAndReturn(store, adminUrl) {
    const historyState = window.history.state;
    const saved = await store.save();
    if (
        saved
        && Number.isSafeInteger(Number(historyState?.returnDetail))
        && Number(historyState.returnDetail) > 0
    ) {
        const id = Number(historyState.returnDetail);
        const query = historyState.returnQuery || store.getState().query;
        const url = buildInventoryActionUrl(adminUrl, 'view', id, query);
        window.history.replaceState(
            { inventoryDetail: id, returnQuery: query },
            '',
            url
        );
        await store.openDetail(id, query);
    }
    return saved;
}

async function initializeContext(store, api, context) {
    if (context.status === 'none') {
        store.reload();
        return;
    }

    if (context.status === 'invalid') {
        store.rejectContext('El contexto de producto indicado no es valido.');
        return;
    }

    store.loadContext(context);

    try {
        if (context.kind === 'store') {
            const response = await api.getStore(context.storeId);
            store.applyStoreContext(context, response.data);
            return;
        }
        const response = await api.getProduct(context.productId);
        store.applyContext(context, response.data);
    } catch (error) {
        store.rejectContext(context.kind === 'store'
            ? (error?.status === 404 || error?.code === 'store_not_found' ? 'El minimarket indicado no existe o ya no esta disponible.' : 'No fue posible cargar el minimarket seleccionado.')
            : contextProductErrorMessage(error));
    }
}

function readConfig() {
    const element = document.getElementById('veciahorra-inventory-config');

    if (!element) {
        throw new Error('No se encontro la configuracion de Inventory.');
    }

    const config = JSON.parse(element.textContent);

    if (
        !config
        || typeof config.restUrl !== 'string'
        || config.restUrl.trim() === ''
        || typeof config.nonce !== 'string'
        || config.nonce.trim() === ''
    ) {
        throw new Error('La configuracion de Inventory no es valida.');
    }

    if (typeof config.adminUrl !== 'string' || config.adminUrl.trim() === '') {
        throw new Error('La configuracion no contiene la URL administrativa.');
    }

    if (window.location.protocol === 'file:' && (!config.storeAdminUrl || config.storeAdminUrl.trim() === '')) config.storeAdminUrl = './admin.php?page=veciahorra-stores';
    if (typeof config.storeAdminUrl !== 'string' || config.storeAdminUrl.trim() === '') throw new Error('La configuracion no contiene la URL de Store.');
    return { restUrl: config.restUrl, nonce: config.nonce, adminUrl: config.adminUrl, storeAdminUrl: config.storeAdminUrl };
}

function buildStoreDetailUrl(baseUrl, id) {
    const url = new URL(baseUrl, window.location.origin);
    if (url.origin !== window.location.origin || !url.pathname.endsWith('/admin.php')
        || url.searchParams.get('page') !== 'veciahorra-stores'
        || [...url.searchParams.keys()].some((key) => key !== 'page')
        || !Number.isSafeInteger(Number(id)) || Number(id) <= 0) throw new TypeError('Store invalido.');
    url.searchParams.set('action', 'view');
    url.searchParams.set('id', String(id));
    return url.toString();
}

function findRequiredNodes() {
    const ids = {
        root: 'veciahorra-inventory-admin',
        messages: 'veciahorra-inventory-messages',
        toolbar: 'veciahorra-inventory-toolbar',
        table: 'veciahorra-inventory-table',
        pagination: 'veciahorra-inventory-pagination',
    };
    const nodes = {};

    Object.entries(ids).forEach(([name, id]) => {
        const node = document.getElementById(id);

        if (!node) {
            throw new Error(`Falta el nodo requerido #${id}.`);
        }

        nodes[name] = node;
    });

    return nodes;
}

function showInitializationError(error) {
    const notice = document.createElement('div');
    notice.className = 'notice notice-error inline';
    const message = document.createElement('p');
    message.textContent = error instanceof Error
        ? error.message
        : 'No fue posible inicializar Inventory.';
    notice.append(message);
    const target = document.getElementById('veciahorra-inventory-messages')
        || document.getElementById('veciahorra-inventory-admin')
        || document.body;
    target.append(notice);
}
