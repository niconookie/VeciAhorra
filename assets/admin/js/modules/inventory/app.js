import { createInventoryApi } from './api.js';
import { createInventoryStore } from './store.js';
import { createInventoryView } from './view.js';
import {
    buildContextUrl,
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
        onCancel: () => returnToList(store, config.adminUrl),
        allInventoryUrl: config.adminUrl,
        contextualListUrl: (id) => buildContextUrl(config.adminUrl, id),
        contextualCreateUrl: (id) => buildContextUrl(config.adminUrl, id, 'create'),
    });

    store.subscribe(view.render);
    view.render(store.getState());
    initializeContext(store, api, readAdminContext(window.location.href));
}

function openCreateForm(store, adminUrl) {
    const context = store.getState().context;

    if (context.status === 'ready') {
        window.location.assign(buildContextUrl(adminUrl, context.product.id, 'create'));
        return;
    }

    store.openCreateForm();
}

function returnToList(store, adminUrl) {
    const context = store.getState().context;

    if (context.status === 'ready') {
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
        const response = await api.getProduct(context.productId);
        store.applyContext(context, response.data);
    } catch (error) {
        store.rejectContext(contextProductErrorMessage(error));
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

    return { restUrl: config.restUrl, nonce: config.nonce, adminUrl: config.adminUrl };
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
