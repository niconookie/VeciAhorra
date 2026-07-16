(function (window, document) {
    'use strict';
    var config = window.VeciAhorra || {};
    var money = new Intl.NumberFormat(config.locale || 'es-CL', { style: 'currency', currency: config.currency || 'CLP', maximumFractionDigits: 0 });
    function el(tag, className, text) { var node = document.createElement(tag); if (className) { node.className = className; } if (text !== undefined) { node.textContent = text; } return node; }
    function data(response) { return response && response.data ? response.data : response; }
    function card(product, detail, urls) {
        var article = el('article', 'va-catalog-card');
        var media = el('div', 'va-catalog-card__media');
        var body = el('div', 'va-catalog-card__body');
        var offer = detail && Array.isArray(detail.offers) ? detail.offers[0] : null;
        var url = String(urls[product.id] || urls[String(product.id)] || '');
        var name = String(product.name || 'Producto');
        var price = offer ? offer.price : product.min_price;
        var image;
        var link;
        if (product.image) {
            image = el('img', 'va-catalog-card__image'); image.src = String(product.image); image.alt = name; image.loading = 'lazy'; image.decoding = 'async'; media.appendChild(image);
        } else { media.appendChild(el('span', 'va-catalog-card__image-missing', 'Imagen no disponible')); }
        body.appendChild(el('h2', 'va-catalog-card__title', name));
        if (offer && offer.minimarket) { body.appendChild(el('p', 'va-catalog-card__store', String(offer.minimarket))); }
        if (price !== null && price !== undefined && price !== '' && isFinite(Number(price))) { body.appendChild(el('p', 'va-catalog-card__price', money.format(Number(price)))); }
        if (offer && Number.isFinite(Number(offer.stock))) { body.appendChild(el('p', 'va-catalog-card__stock', 'Stock disponible: ' + Number(offer.stock))); }
        if (url) { link = el('a', 'va-button va-catalog-card__action', 'Ver producto'); link.href = url; }
        else { link = el('span', 'va-catalog-card__unavailable', 'Ficha no disponible'); }
        body.appendChild(link); article.appendChild(media); article.appendChild(body); return article;
    }
    function mount(root) {
        var loading = root.querySelector('[data-va-catalog-loading]'); var error = root.querySelector('[data-va-catalog-error]'); var errorMessage = root.querySelector('[data-va-catalog-error-message]');
        var empty = root.querySelector('[data-va-catalog-empty]'); var grid = root.querySelector('[data-va-catalog-grid]'); var status = root.querySelector('[data-va-catalog-status]'); var retry = root.querySelector('[data-va-catalog-retry]'); var urls = {};
        try { urls = JSON.parse(root.getAttribute('data-product-urls') || '{}'); } catch (ignore) {}
        function load() {
            loading.hidden = false; error.hidden = true; empty.hidden = true; grid.hidden = true; grid.replaceChildren();
            config.api.get('/catalog/products?per_page=100&order_by=name').then(function (response) {
                var catalog = data(response) || {}; var items = Array.isArray(catalog) ? catalog : (Array.isArray(catalog.items) ? catalog.items : []);
                return Promise.all(items.map(function (product) { return config.api.get('/catalog/products/' + encodeURIComponent(product.id)).then(function (responseDetail) { return { product: product, detail: data(responseDetail) }; }).catch(function () { return { product: product, detail: null }; }); }));
            }).then(function (products) {
                loading.hidden = true;
                if (!products.length) { empty.hidden = false; status.textContent = 'No hay productos disponibles.'; return; }
                products.forEach(function (item) { grid.appendChild(card(item.product, item.detail, urls)); }); grid.hidden = false;
                status.textContent = products.length + (products.length === 1 ? ' producto disponible.' : ' productos disponibles.');
            }).catch(function (reason) { loading.hidden = true; error.hidden = false; errorMessage.textContent = reason && reason.message ? reason.message : 'No fue posible cargar el catálogo.'; status.textContent = 'Error al cargar el catálogo.'; });
        }
        retry.addEventListener('click', load); load();
    }
    document.querySelectorAll('[data-va-catalog]').forEach(mount);
}(window, document));
