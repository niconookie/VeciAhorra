(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function data(response) { return response && response.data ? response.data : response; }
    function positiveId(value) {
        var normalized = String(value || '');
        return /^\d+$/.test(normalized) && Number(normalized) > 0 ? normalized : '';
    }

    function catalogPath(filters) {
        var params = new URLSearchParams();
        var order = ['name', 'price', 'newest'].indexOf(filters.order) !== -1 ? filters.order : 'price';
        params.set('per_page', '100');
        params.set('order_by', order);
        ['category', 'subcategory', 'brand', 'unit'].forEach(function (key) {
            var value = positiveId(filters[key]);
            if (value) { params.set(key, value); }
        });
        if (String(filters.search || '').trim()) { params.set('search', String(filters.search).trim()); }
        return '/catalog/products?' + params.toString();
    }

    function productUrl(product, urls, catalogUrl) {
        var id = positiveId(product && product.id);
        var explicit = id ? String(urls[id] || '') : '';
        var url;
        if (explicit) { return explicit; }
        if (!id || !catalogUrl) { return ''; }
        try {
            url = new URL(catalogUrl, window.location.origin);
            url.searchParams.set('product_id', id);
            return url.toString();
        } catch (ignore) { return ''; }
    }

    function cartOptions() {
        var cart = config.cart || {};
        var headers = {};
        if (!(config.currentUser && config.currentUser.loggedIn) && cart.sessionId && cart.sessionHeader) {
            headers[String(cart.sessionHeader)] = String(cart.sessionId);
        }
        return {headers: headers};
    }

    function updateCartBadge(response) {
        var items = data(response);
        var count = Array.isArray(items) ? items.reduce(function (total, item) {
            return total + Math.max(0, Number(item && item.quantity) || 0);
        }, 0) : 0;
        var badge = document.querySelector('[data-va-header-cart-count]');
        if (badge) {
            badge.textContent = String(count);
            badge.hidden = count === 0;
        }
    }

    function mount(root) {
        var loading = root.querySelector('[data-va-catalog-loading]');
        var error = root.querySelector('[data-va-catalog-error]');
        var errorMessage = root.querySelector('[data-va-catalog-error-message]');
        var empty = root.querySelector('[data-va-catalog-empty]');
        var grid = root.querySelector('[data-va-catalog-grid]');
        var status = root.querySelector('[data-va-catalog-status]');
        var retry = root.querySelector('[data-va-catalog-retry]');
        var form = root.querySelector('[data-va-catalog-filters]');
        var search = root.querySelector('[data-va-catalog-search]');
        var category = root.querySelector('[data-va-catalog-category]');
        var subcategory = root.querySelector('[data-va-catalog-subcategory]');
        var brand = root.querySelector('[data-va-catalog-brand]');
        var unit = root.querySelector('[data-va-catalog-unit]');
        var sector = root.querySelector('[data-va-catalog-sector]');
        var filterStatus = root.querySelector('[data-va-catalog-filter-status]');
        var order = root.querySelector('[data-va-catalog-order]');
        var reset = root.querySelector('[data-va-catalog-reset]');
        var toggle = root.querySelector('[data-va-catalog-filters-toggle]');
        var initialFilters = config.catalogFilters || {};
        var pageParams = new URLSearchParams(window.location.search);
        var requestedOrder = pageParams.get('order_by');
        var filters = {
            search: String(pageParams.get('search') || ''),
            category: positiveId(pageParams.get('category')),
            subcategory: positiveId(pageParams.get('subcategory')),
            brand: positiveId(pageParams.get('brand')) || positiveId(initialFilters.brand),
            unit: positiveId(pageParams.get('unit')),
            order: ['name', 'price', 'newest'].indexOf(requestedOrder) !== -1 ? requestedOrder : 'price'
        };
        var filterMetadata = {categories: [], brands: [], units: []};
        var urls = {};
        var catalogUrl = root.getAttribute('data-catalog-url') || '';
        var requestSequence = 0;

        try { urls = JSON.parse(root.getAttribute('data-product-urls') || '{}'); } catch (ignore) {}
        search.value = filters.search;
        order.value = filters.order;

        function syncPageUrl() {
            var params = new URLSearchParams(window.location.search);
            ['search', 'category', 'subcategory', 'brand', 'unit', 'order_by'].forEach(function (key) { params.delete(key); });
            if (String(filters.search || '').trim()) { params.set('search', String(filters.search).trim()); }
            ['category', 'subcategory', 'brand', 'unit'].forEach(function (key) {
                var value = positiveId(filters[key]);
                if (value) { params.set(key, value); }
            });
            if (filters.order !== 'price') { params.set('order_by', filters.order); }
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash);
        }

        function replaceOptions(select, placeholder, items, selected) {
            select.replaceChildren(new Option(placeholder, ''));
            items.forEach(function (item) { select.add(new Option(String(item.name), String(item.id))); });
            select.disabled = items.length === 0;
            select.value = selected && Array.from(select.options).some(function (option) { return option.value === selected; }) ? selected : '';
        }

        function fillSubcategories(selected) {
            var categoryId = positiveId(category.value);
            var items = categoryId ? filterMetadata.categories.filter(function (item) {
                return String(item.parent_id || '') === categoryId;
            }) : [];
            replaceOptions(subcategory, 'Todas las subcategorías', items, selected || '');
        }

        function renderFilterMetadata(meta) {
            var available = meta && meta.filters || {};
            var categories = Array.isArray(available.categories) ? available.categories : [];
            var ids = new Set(categories.map(function (item) { return String(item.id); }));
            var roots = categories.filter(function (item) { return !item.parent_id || !ids.has(String(item.parent_id)); });
            filterMetadata = {
                categories: categories,
                brands: Array.isArray(available.brands) ? available.brands : [],
                units: Array.isArray(available.units) ? available.units : []
            };
            replaceOptions(category, 'Todas las categorías', roots, filters.category);
            fillSubcategories(filters.subcategory);
            replaceOptions(brand, 'Todas las marcas', filterMetadata.brands, filters.brand);
            replaceOptions(unit, 'Todas las unidades', filterMetadata.units, filters.unit);
            if (meta && meta.sector) {
                sector.textContent = String(meta.sector.name || 'Microzona activa') + (meta.sector.commune ? ' · ' + String(meta.sector.commune) : '');
            } else {
                sector.textContent = 'Selecciona una microzona en la cabecera';
            }
            filterStatus.textContent = 'Filtros construidos con los productos disponibles en tu microzona.';
        }

        function quickAdd(product, button, message) {
            var offerToken = product && typeof product.single_offer_token === 'string' ? product.single_offer_token : '';
            if (!/^[A-Za-z0-9_-]{40,512}$/.test(offerToken) || Number(product.eligible_offers) !== 1) {
                return Promise.reject(new Error('Debes seleccionar una opción disponible.'));
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            message.textContent = 'Agregando al carrito…';
            return config.api.post('/cart/items', {offer_token: offerToken, quantity: 1}, cartOptions())
                .then(function () { return config.api.get('/cart', cartOptions()); })
                .then(function (response) {
                    updateCartBadge(response);
                    message.textContent = 'Producto agregado al carrito.';
                }).catch(function (reason) {
                    message.textContent = reason && reason.message ? reason.message : 'No fue posible agregar el producto.';
                }).finally(function () {
                    button.disabled = false;
                    button.setAttribute('aria-busy', 'false');
                });
        }

        function loadProducts() {
            var sequence = ++requestSequence;
            loading.hidden = false;
            error.hidden = true;
            empty.hidden = true;
            grid.hidden = true;
            grid.replaceChildren();
            status.textContent = 'Actualizando opciones disponibles…';

            return config.api.get(catalogPath(filters)).then(function (response) {
                var items = Array.isArray(response && response.data) ? response.data : [];
                return {items: items, meta: response && response.meta || {}};
            }).then(function (result) {
                var fragment;
                var renderer;
                var total = Number(result.meta.total);
                if (sequence !== requestSequence) { return; }
                loading.hidden = true;
                renderFilterMetadata(result.meta);
                status.textContent = (Number.isInteger(total) ? total : result.items.length) + ((Number.isInteger(total) ? total : result.items.length) === 1 ? ' opción disponible' : ' opciones disponibles');
                if (!result.items.length) {
                    empty.hidden = false;
                    return;
                }
                renderer = window.VeciAhorraProductCard;
                if (!renderer || typeof renderer.render !== 'function') { throw new Error('No fue posible mostrar los productos.'); }
                fragment = document.createDocumentFragment();
                result.items.forEach(function (product) {
                    var url = productUrl(product, urls, catalogUrl);
                    fragment.appendChild(renderer.render(product, {
                        catalogMode: true,
                        url: url,
                        quickAdd: quickAdd,
                        openOptions: function () { if (url) { window.location.assign(url); } }
                    }));
                });
                grid.appendChild(fragment);
                grid.hidden = false;
            }).catch(function (reason) {
                if (sequence !== requestSequence) { return; }
                loading.hidden = true;
                error.hidden = false;
                errorMessage.textContent = reason && reason.message ? reason.message : 'No fue posible cargar el catálogo.';
                status.textContent = 'Error al cargar el catálogo.';
            });
        }

        function readFilters() {
            filters.search = search.value;
            filters.category = positiveId(category.value);
            filters.subcategory = positiveId(subcategory.value);
            filters.brand = positiveId(brand.value);
            filters.unit = positiveId(unit.value);
            filters.order = order.value;
        }

        form.addEventListener('submit', function (event) { event.preventDefault(); readFilters(); syncPageUrl(); loadProducts(); });
        category.addEventListener('change', function () { filters.category = positiveId(category.value); filters.subcategory = ''; fillSubcategories(''); syncPageUrl(); loadProducts(); });
        [subcategory, brand, unit, order].forEach(function (control) { control.addEventListener('change', function () { readFilters(); syncPageUrl(); loadProducts(); }); });
        reset.addEventListener('click', function () {
            form.reset();
            filters = {search: '', category: '', subcategory: '', brand: '', unit: '', order: 'price'};
            order.value = 'price';
            syncPageUrl();
            loadProducts();
        });
        toggle.addEventListener('click', function () {
            var open = !root.classList.contains('va-catalog--filters-open');
            root.classList.toggle('va-catalog--filters-open', open);
            toggle.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('va-catalog--filters-open')) {
                root.classList.remove('va-catalog--filters-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
        retry.addEventListener('click', loadProducts);
        loadProducts();
    }

    window.VeciAhorraCatalog = Object.freeze({catalogPath: catalogPath});
    document.querySelectorAll('[data-va-catalog]').forEach(mount);
}(window, document));
