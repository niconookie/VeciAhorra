(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function data(response) {
        return response && response.data ? response.data : response;
    }

    function positiveId(value) {
        var normalized = String(value || '');
        return /^\d+$/.test(normalized) && Number(normalized) > 0 ? normalized : '';
    }

    function catalogPath(filters) {
        var params = new URLSearchParams();
        var category = positiveId(filters.category);
        var brand = positiveId(filters.brand);
        var search = String(filters.search || '').trim();
        var order = ['name', 'price', 'newest'].indexOf(filters.order) !== -1
            ? filters.order
            : 'name';

        params.set('per_page', '100');
        params.set('order_by', order);
        if (search) { params.set('search', search); }
        if (brand) { params.set('brand', brand); }
        if (category) { params.set('category', category); }

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
        } catch (ignore) {
            return '';
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
        var categoryStatus = root.querySelector('[data-va-catalog-category-status]');
        var order = root.querySelector('[data-va-catalog-order]');
        var reset = root.querySelector('[data-va-catalog-reset]');
        var initialFilters = config.catalogFilters || {};
        var filters = { search: '', category: '', brand: positiveId(initialFilters.brand), order: 'name', page: 1 };
        var urls = {};
        var catalogUrl = root.getAttribute('data-catalog-url') || '';
        var requestSequence = 0;

        try { urls = JSON.parse(root.getAttribute('data-product-urls') || '{}'); } catch (ignore) {}

        function loadCategories() {
            category.disabled = true;
            categoryStatus.hidden = false;
            categoryStatus.textContent = 'Cargando categorías…';

            return config.api.get('/catalog/categories').then(function (response) {
                var categories = data(response);
                categories = Array.isArray(categories) ? categories : [];
                categories.forEach(function (item) {
                    var id = positiveId(item && item.id);
                    if (id && item.name) {
                        category.appendChild(el('option', '', String(item.name))).value = id;
                    }
                });
                category.disabled = false;
                categoryStatus.textContent = categories.length
                    ? 'Categorías disponibles.'
                    : 'No hay categorías con productos disponibles.';
            }).catch(function () {
                category.disabled = true;
                categoryStatus.textContent = 'Las categorías no están disponibles. Puedes seguir usando el catálogo.';
            });
        }

        function loadProducts() {
            var sequence = ++requestSequence;
            loading.hidden = false;
            error.hidden = true;
            empty.hidden = true;
            grid.hidden = true;
            grid.replaceChildren();
            status.textContent = 'Actualizando resultados…';

            return config.api.get(catalogPath(filters)).then(function (response) {
                var catalog = data(response) || {};
                var items = Array.isArray(catalog)
                    ? catalog
                    : (Array.isArray(catalog.items) ? catalog.items : []);

                return items;
            }).then(function (products) {
                var fragment;
                var renderer;

                if (sequence !== requestSequence) { return; }
                loading.hidden = true;
                if (! products.length) {
                    empty.hidden = false;
                    status.textContent = '0 productos encontrados';
                    return;
                }
                renderer = window.VeciAhorraProductCard;
                if (!renderer || typeof renderer.render !== 'function') {
                    throw new Error('No fue posible mostrar los productos.');
                }
                fragment = document.createDocumentFragment();
                products.forEach(function (product) {
                    fragment.appendChild(renderer.render(product, {
                        url: productUrl(product, urls, catalogUrl)
                    }));
                });
                grid.appendChild(fragment);
                grid.hidden = false;
                status.textContent = products.length + (products.length === 1 ? ' producto encontrado' : ' productos encontrados');
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
            filters.order = order.value;
            filters.page = 1;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            readFilters();
            loadProducts();
        });
        category.addEventListener('change', function () {
            readFilters();
            loadProducts();
        });
        order.addEventListener('change', function () {
            readFilters();
            loadProducts();
        });
        reset.addEventListener('click', function () {
            form.reset();
            filters.search = '';
            filters.category = '';
            filters.brand = '';
            filters.order = 'name';
            filters.page = 1;
            loadProducts();
        });
        retry.addEventListener('click', loadProducts);

        loadCategories();
        loadProducts();
    }

    window.VeciAhorraCatalog = Object.freeze({ catalogPath: catalogPath });
    document.querySelectorAll('[data-va-catalog]').forEach(mount);
}(window, document));
