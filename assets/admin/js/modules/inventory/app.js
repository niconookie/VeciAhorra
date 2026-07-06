import { createInventoryApi } from './api.js';
import { createInventoryStore } from './store.js';
import { createInventoryView } from './view.js';

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
        onNew: () => store.openCreateForm(),
        onEdit: (id) => store.openEditForm(id),
        onFormField: (field, value) => store.setFormField(field, value),
        onSave: () => store.save(),
        onCancel: () => store.returnToList(),
    });

    store.subscribe(view.render);
    view.render(store.getState());
    store.reload();
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

    return { restUrl: config.restUrl, nonce: config.nonce };
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
