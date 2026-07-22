import { createInventoryApi } from './api.js';
import { createInventoryStore } from './store.js';
import { createInventoryView } from './view.js';
import {
    buildContextUrl,
    buildStoreContextUrl,
    contextProductErrorMessage,
    readAdminContext,
} from './context.js';

try {
    initialize();
} catch (error) {
    showInitializationError(error);
}

function initialize() {
    const config = readConfig();
    const nodes = findRequiredNodes();
    const api = createInventoryApi(config);
    const store = createInventoryStore(api);
    const view = createInventoryView(nodes, {
        onFilter: (name, value) => store.setFilter(name, value),
        onSearch: () => store.applyFilters(),
        onClear: () => store.clearFilters(),
        onReload: () => store.reload(),
        onPage: (page) => store.goToPage(page),
        onNew: () => openCreateForm(store, config.adminUrl),
        onEdit: (id) => store.openEditForm(id),
        onFormField: (field, value) => store.setFormField(field, value),
        onProductSelected: (product) => store.selectProduct(product),
        onProductCleared: () => store.clearSelectedProduct(),
        searchProducts: (term) => api.searchProducts(term),
        onStoreSelected: (selectedStore) => store.selectStore(selectedStore),
        onStoreCleared: () => store.clearSelectedStore(),
        searchStores: (term, options) => api.searchStores(term, options),
        onSave: () => store.save(),
        onCancel: () => returnToList(store, config.adminUrl, config.storeAdminUrl),
        allInventoryUrl: config.adminUrl,
        contextualListUrl: (id) => buildContextUrl(config.adminUrl, id),
        contextualCreateUrl: (id) => buildContextUrl(config.adminUrl, id, 'create'),
        contextualStoreCreateUrl: (id) => buildStoreContextUrl(config.adminUrl, id, 'create'),
        storeDetailUrl: (id) => buildStoreDetailUrl(config.storeAdminUrl, id),
    });

    store.subscribe(view.render);
    view.render(store.getState());
    initializeContext(store, api, readAdminContext(window.location.href));
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

    store.returnToList();
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
