import { createOrderDetailTransport } from './detail-api.js';
import { createOrderDetailState } from './detail-state.js';
import { createOrderDetailView } from './detail-view.js';

const INSTANCE_KEY = '__veciahorraOrderDetailApplication';
const SELECTORS = Object.freeze({
    root: '#veciahorra-order-detail',
    loadingRegion: '#veciahorra-order-detail-loading',
    errorRegion: '#veciahorra-order-detail-error',
    contentRegion: '#veciahorra-order-detail-content',
});

export function initializeOrderDetailApp() {
    const source = window.VeciAhorra?.ordersAdminDetail;
    if (object(source) && object(source[INSTANCE_KEY])) return source[INSTANCE_KEY];

    const config = validConfig(source);
    const shell = resolveShell();
    if (config === null || shell === null) {
        if (config === null) showInitializationFailure(shell);
        return null;
    }

    let state = null;
    let unsubscribe = null;
    let cleaned = false;
    let instance = null;

    const cleanup = () => {
        if (cleaned) return;
        cleaned = true;
        window.removeEventListener('pagehide', cleanup);
        if (unsubscribe !== null) unsubscribe();
        unsubscribe = null;
        if (state !== null) state.destroy();
    };

    try {
        const transport = createOrderDetailTransport(config);
        state = createOrderDetailState({ orderId: config.orderId, transport });
        const view = createOrderDetailView(shell);
        view.render(state.getSnapshot());
        unsubscribe = state.subscribe((snapshot) => {
            if (cleaned) return;
            try {
                view.render(snapshot);
            } catch {
                cleanup();
            }
        });
        instance = Object.freeze({ destroy: cleanup });
        source[INSTANCE_KEY] = instance;
        window.addEventListener('pagehide', cleanup, { once: true });
        void state.load();
        return instance;
    } catch {
        cleanup();
        showInitializationFailure(shell);
        return null;
    }
}

function validConfig(source) {
    if (!object(source) || source.enabled !== true) return null;
    if (!Number.isSafeInteger(source.orderId) || source.orderId <= 0) return null;
    if (typeof source.nonce !== 'string' || source.nonce === '') return null;
    if (typeof source.restUrl !== 'string' || source.restUrl === '') return null;

    let url;
    try {
        url = new URL(source.restUrl);
    } catch {
        return null;
    }
    if (
        url.origin !== window.location.origin
        || url.search !== ''
        || url.hash !== ''
        || !/\/veciahorra\/v1\/orders\/?$/.test(url.pathname)
    ) return null;
    url.pathname = url.pathname.replace(/\/+$/, '');

    return Object.freeze({
        orderId: source.orderId,
        restUrl: url.toString().replace(/\/+$/, ''),
        nonce: source.nonce,
    });
}

function resolveShell() {
    const found = {};
    for (const [name, selector] of Object.entries(SELECTORS)) {
        const matches = document.querySelectorAll(selector);
        if (matches.length !== 1) return null;
        found[name] = matches[0];
    }
    if (
        !found.root.contains(found.loadingRegion)
        || !found.root.contains(found.errorRegion)
        || !found.root.contains(found.contentRegion)
        || found.loadingRegion.getAttribute('role') !== 'status'
        || found.errorRegion.getAttribute('role') !== 'alert'
        || found.contentRegion.tagName !== 'MAIN'
    ) return null;
    return found;
}

function showInitializationFailure(shell) {
    if (shell === null) return;
    shell.root.setAttribute('aria-busy', 'false');
    shell.loadingRegion.replaceChildren();
    shell.loadingRegion.hidden = true;
    shell.contentRegion.replaceChildren();
    shell.contentRegion.hidden = true;
    shell.errorRegion.replaceChildren();
    const message = shell.root.ownerDocument.createElement('p');
    message.textContent = 'No fue posible iniciar el detalle administrativo.';
    shell.errorRegion.append(message);
    shell.errorRegion.hidden = false;
}

function object(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

initializeOrderDetailApp();
