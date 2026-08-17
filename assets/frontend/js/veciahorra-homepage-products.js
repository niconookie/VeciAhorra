(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};
    var endpoint = '/catalog/homepage-products';

    function positiveInteger(value) {
        return Number.isInteger(value) && value > 0;
    }

    function canonicalCatalogUrl(catalogUrl) {
        var url;

        if (!catalogUrl) { return ''; }
        try {
            url = new URL(catalogUrl, window.location.origin);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') { return ''; }
            return url.toString();
        } catch (ignore) {
            return '';
        }
    }

    function productUrl(product, catalogUrl) {
        var url = canonicalCatalogUrl(catalogUrl);

        if (!positiveInteger(product.id) || !url) { return ''; }
        url = new URL(url);
        url.searchParams.set('product_id', String(product.id));
        return url.toString();
    }

    function validProduct(product) {
        return product && typeof product === 'object'
            && positiveInteger(product.id)
            && typeof product.name === 'string'
            && product.name.trim() !== ''
            && (product.image === null || typeof product.image === 'string')
            && product.min_price !== null
            && product.min_price !== ''
            && isFinite(Number(product.min_price))
            && Number(product.min_price) > 0
            && positiveInteger(product.available_minimarkets);
    }

    function validated(payload) {
        var products;
        var ids = {};

        if (!payload || typeof payload !== 'object' || typeof payload.state !== 'string') {
            throw new Error('invalid_response');
        }
        if (['success', 'empty', 'no_sector'].indexOf(payload.state) === -1 || !Array.isArray(payload.products)) {
            throw new Error('invalid_response');
        }
        products = payload.products;
        if (products.length > 6 || !products.every(function (product) {
            if (!validProduct(product) || ids[product.id]) { return false; }
            ids[product.id] = true;
            return true;
        })) {
            throw new Error('invalid_response');
        }
        return payload.state === 'success' && products.length === 0
            ? { state: 'empty', products: [] }
            : { state: payload.state, products: products };
    }

    function mount(root) {
        var status = root.querySelector('[data-va-home-products-status]');
        var grid = root.querySelector('[data-va-home-products-grid]');
        var catalogUrl = root.getAttribute('data-catalog-url') || '';
        var sequence = 0;
        var controller = null;

        if (!status || !grid || root.dataset.ready === '1') { return; }
        root.dataset.ready = '1';

        function button(label, className, handler) {
            var action = document.createElement('button');
            action.type = 'button';
            action.className = className;
            action.textContent = label;
            action.addEventListener('click', handler);
            return action;
        }

        function showMessage(state, message, assertive) {
            status.replaceChildren();
            status.className = 'va-home-products__status va-home-products__status--' + state;
            status.setAttribute('aria-live', assertive ? 'assertive' : 'polite');
            status.appendChild(document.createTextNode(message));
        }

        function showSectorLink() {
            var url = canonicalCatalogUrl(catalogUrl);
            var action;

            if (!url) { return; }
            action = document.createElement('a');
            action.className = 'va-button va-button--secondary va-home-products__sector-link';
            action.href = url;
            action.textContent = 'Seleccionar sector';
            status.appendChild(action);
        }

        function noSector() {
            grid.replaceChildren();
            showMessage('no-sector', 'Selecciona un sector para ver productos disponibles.', false);
            showSectorLink();
        }

        function empty() {
            grid.replaceChildren();
            showMessage('empty', 'No hay productos disponibles en tu sector.', false);
        }

        function failure(classification) {
            grid.replaceChildren();
            root.dataset.errorClassification = classification;
            showMessage('error', 'No fue posible cargar los productos.', true);
            status.appendChild(button(
                'Reintentar',
                'va-button va-button--secondary va-home-products__retry',
                load
            ));
        }

        function renderSuccess(products) {
            var renderer = window.VeciAhorraProductCard;
            var fragment = document.createDocumentFragment();

            if (!renderer || typeof renderer.render !== 'function') {
                throw new Error('renderer_unavailable');
            }
            products.forEach(function (product) {
                fragment.appendChild(renderer.render(product, {
                    url: productUrl(product, catalogUrl),
                    headingTag: 'h3',
                    modifierClass: 'va-catalog-card--homepage'
                }));
            });
            grid.replaceChildren(fragment);
            showMessage('success', products.length + (products.length === 1
                ? ' producto disponible.'
                : ' productos disponibles.'), false);
        }

        function load() {
            var current = ++sequence;

            if (controller) { controller.abort(); }
            controller = typeof window.AbortController === 'function'
                ? new window.AbortController()
                : null;
            delete root.dataset.errorClassification;
            grid.replaceChildren();
            showMessage('loading', 'Cargando productos…', false);

            if (!config.api || typeof config.api.get !== 'function') {
                failure('dependency');
                return;
            }

            config.api.get(endpoint, controller ? { signal: controller.signal } : undefined)
                .then(validated)
                .then(function (payload) {
                    if (current !== sequence) { return; }
                    if (payload.state === 'no_sector') { noSector(); return; }
                    if (payload.state === 'empty') { empty(); return; }
                    renderSuccess(payload.products);
                })
                .catch(function (error) {
                    if (current !== sequence || (error && error.code === 'request_aborted')) { return; }
                    failure(error && error.message === 'invalid_response' ? 'invalid-response' : 'request');
                });
        }

        if (root.getAttribute('data-has-effective-sector') !== '1') {
            noSector();
            return;
        }
        load();
    }

    document.querySelectorAll('[data-va-home-products]').forEach(mount);
}(window, document));
