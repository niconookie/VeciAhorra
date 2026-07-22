const root = document.querySelector('[data-va-store-detail]');

if (root && root.dataset.vaStoreDetailInitialized !== 'true') {
    const configNode = document.getElementById('va-store-detail-config');

    try {
        if (!configNode) {
            throw new Error('missing_detail_config');
        }

        const config = JSON.parse(configNode.textContent || '{}');
        const idIsValid = Number.isSafeInteger(config.storeId) && config.storeId > 0;
        const enabled = config.enabled === true;
        const detailUrl = new URL(config.detailUrl, window.location.href);
        const returnUrl = new URL(config.returnUrl, window.location.href);

        if (
            !enabled
            || !idIsValid
            || detailUrl.origin !== window.location.origin
            || returnUrl.origin !== window.location.origin
            || typeof config.nonce !== 'string'
            || config.nonce.trim() === ''
        ) {
            throw new Error('invalid_detail_config');
        }

        root.dataset.vaStoreDetailInitialized = 'true';

        window.VeciAhorra = window.VeciAhorra || {};
        window.VeciAhorra.stores = window.VeciAhorra.stores || {};
        window.VeciAhorra.stores.detail = Object.assign(
            window.VeciAhorra.stores.detail || {},
            config
        );

        root.dataset.vaStoreId = String(config.storeId);
        root.dataset.vaStoreDetailState = 'ready';
        root.setAttribute('aria-busy', 'false');
    } catch (error) {
        const messages = root.querySelector('[data-va-store-detail-messages]');
        if (messages) {
            const notice = document.createElement('div');
            const message = document.createElement('p');
            notice.className = 'notice notice-error inline';
            message.textContent = 'No fue posible preparar el detalle del minimarket.';
            notice.append(message);
            messages.replaceChildren(notice);
        }
        root.dataset.vaStoreDetailState = 'error';
        root.setAttribute('aria-busy', 'false');
    }
}
