import { createStoreDetailApi } from './detail-api.js';
import { validateDetailPayload } from './detail-contract.js';
import { createStoreDetailView, detailErrorMessage } from './detail-view.js';

const root = document.querySelector('[data-va-store-detail]');

if (root && root.dataset.vaStoreDetailInitialized !== 'true') {
    initialize(root);
}

function initialize(rootNode) {
    const configNode = document.getElementById('va-store-detail-config');
    let view = null;

    try {
        view = createStoreDetailView(rootNode);
        const config = readConfig(configNode);
        rootNode.dataset.vaStoreDetailInitialized = 'true';

        window.VeciAhorra = window.VeciAhorra || {};
        window.VeciAhorra.stores = window.VeciAhorra.stores || {};
        window.VeciAhorra.stores.detail = Object.assign(
            window.VeciAhorra.stores.detail || {},
            config
        );

        const api = createStoreDetailApi(config);
        const coordinator = createStoreDetailCoordinator({
            rootNode,
            config,
            api,
            view,
        });

        rootNode.dataset.vaStoreId = String(config.storeId);
        window.addEventListener('pagehide', coordinator.destroy, { once: true });
        coordinator.load();
    } catch (error) {
        if (view) {
            view.error('No fue posible preparar el detalle del minimarket.');
        } else {
            rootNode.dataset.vaStoreDetailState = 'error';
            rootNode.setAttribute('aria-busy', 'false');
        }
    }
}

export function createStoreDetailCoordinator({ rootNode, config, api, view }) {
    let sequence = 0;
    let active = true;
    let controller = null;

    const load = () => {
        if (!active) return;
        controller?.abort();
        controller = new AbortController();
        const requestId = ++sequence;
        view.loading();

        api.get(controller.signal)
            .then((payload) => {
                if (!active || requestId !== sequence || !rootNode.isConnected) return;
                view.render(validateDetailPayload(payload, config.storeId));
            })
            .catch((error) => {
                if (
                    !active
                    || requestId !== sequence
                    || error?.name === 'AbortError'
                    || !rootNode.isConnected
                ) return;
                view.error(detailErrorMessage(error));
            });
    };

    const destroy = () => {
        if (!active) return;
        active = false;
        sequence++;
        controller?.abort();
    };

    return Object.freeze({ load, destroy });
}

function readConfig(configNode) {
    if (!configNode) throw new Error('missing_detail_config');
    const config = JSON.parse(configNode.textContent || '{}');
    if (
        config.enabled !== true
        || !Number.isSafeInteger(config.storeId)
        || config.storeId <= 0
        || typeof config.nonce !== 'string'
        || config.nonce.trim() === ''
    ) {
        throw new Error('invalid_detail_config');
    }
    const detailUrl = new URL(config.detailUrl, window.location.href);
    const returnUrl = new URL(config.returnUrl, window.location.href);
    const expectedSuffix = `/stores/${config.storeId}`;
    const allowedProtocols = window.location.protocol === 'file:'
        ? ['file:']
        : ['http:', 'https:'];
    if (
        !allowedProtocols.includes(detailUrl.protocol)
        || detailUrl.origin !== window.location.origin
        || !detailUrl.pathname.endsWith(expectedSuffix)
        || detailUrl.search !== ''
        || detailUrl.hash !== ''
        || !allowedProtocols.includes(returnUrl.protocol)
        || returnUrl.origin !== window.location.origin
        || detailUrl.username !== ''
        || detailUrl.password !== ''
        || returnUrl.username !== ''
        || returnUrl.password !== ''
    ) {
        throw new Error('invalid_detail_urls');
    }
    return Object.freeze({ ...config, detailUrl: detailUrl.toString(), returnUrl: returnUrl.toString() });
}
