import { createProductsApi } from './api.js';
import {
    createProductsStore,
    FORM_STATUS_SAVING,
} from './store.js';
import { createProductsView } from './view.js';

try {
    initialize();
} catch (error) {
    showInitializationError(error);
}

function initialize() {
    const config = readConfig();
    const nodes = findRequiredNodes();
    const api = createProductsApi(config);
    const store = createProductsStore(api);
    const view = createProductsView(nodes, {
        onInputTerm: (term) => store.setInputTerm(term),
        onSearch: () => store.search(),
        onClear: () => store.search(''),
        onReload: () => store.reload(),
        onPage: (page) => store.goToPage(page),
        onNew: () => store.openCreateForm(),
        onEdit: (id) => store.openEditForm(id),
        onFormField: (field, value) => store.setFormField(field, value),
        onSave: () => store.saveProduct(),
        onStatus: (status) => store.changeProductStatus(status),
        onBack: () => returnToList(store),
    });

    store.subscribe(view.render);
    view.render(store.getState());
    store.reload();
}

async function returnToList(store) {
    if (await store.returnToList()) {
        return;
    }

    const state = store.getState();

    if (
        state.form.dirty
        && state.form.status !== FORM_STATUS_SAVING
        && window.confirm('Hay cambios sin guardar. ¿Quieres volver al listado?')
    ) {
        await store.returnToList({ force: true });
    }
}

function readConfig() {
    const element = document.getElementById('veciahorra-products-config');

    if (!element) {
        throw new Error('No se encontró la configuración de Products.');
    }

    let config;

    try {
        config = JSON.parse(element.textContent);
    } catch (error) {
        throw new Error('La configuración de Products no contiene JSON válido.');
    }

    if (!config || typeof config !== 'object' || Array.isArray(config)) {
        throw new Error('La configuración de Products no es válida.');
    }

    if (typeof config.restUrl !== 'string' || config.restUrl.trim() === '') {
        throw new Error('La configuración no contiene una URL REST válida.');
    }

    let restUrl;

    try {
        restUrl = new URL(config.restUrl, window.location.origin);
    } catch (error) {
        throw new Error('La URL REST de Products no es válida.');
    }

    if (!['http:', 'https:'].includes(restUrl.protocol)) {
        throw new Error('La URL REST de Products no usa un protocolo permitido.');
    }

    if (typeof config.nonce !== 'string' || config.nonce.trim() === '') {
        throw new Error('La configuración no contiene un nonce REST válido.');
    }

    return {
        restUrl: restUrl.toString(),
        nonce: config.nonce,
    };
}

function findRequiredNodes() {
    const selectors = {
        root: 'veciahorra-products-admin',
        messages: 'veciahorra-products-messages',
        toolbar: 'veciahorra-products-toolbar',
        table: 'veciahorra-products-table',
        pagination: 'veciahorra-products-pagination',
    };
    const nodes = {};

    Object.entries(selectors).forEach(([name, id]) => {
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
    notice.className = 'notice notice-error inline veciahorra-products-admin__notice';

    const message = document.createElement('p');
    message.textContent = error instanceof Error
        ? error.message
        : 'No fue posible inicializar la pantalla de Products.';
    notice.append(message);

    const messages = document.getElementById('veciahorra-products-messages');
    const root = document.getElementById('veciahorra-products-admin');

    if (messages) {
        messages.replaceChildren(notice);
    } else if (root) {
        root.prepend(notice);
    } else {
        document.body.append(notice);
    }
}
