(function (window, document) {
    'use strict';

    function json(response) {
        return response.text().then(function (body) {
            var payload = body ? JSON.parse(body) : null;
            if (!response.ok || (payload && payload.success === false)) throw new Error(payload && payload.error && payload.error.message || 'No fue posible completar la solicitud.');
            return payload;
        });
    }

    document.querySelectorAll('[data-va-global-header]').forEach(function (root) {
        var menuButton = root.querySelector('.va-global-header__menu-toggle');
        var navigation = root.querySelector('#va-global-navigation');
        var sectorButton = root.querySelector('.va-global-header__sector');
        var sectorPanel = root.querySelector('#va-global-sector-panel');
        var sectorSelect = root.querySelector('[data-va-header-sector-select]');
        var sectorMessage = root.querySelector('[data-va-header-sector-message]');
        var searchForm = root.querySelector('[data-va-header-search]');
        var searchScope = searchForm.querySelector('[data-va-header-search-scope]');
        var searchInput = searchForm.elements.search;
        var searchButton = searchForm.querySelector('[type="submit"]');
        var searchContext = searchForm.querySelector('[data-va-header-search-context]');
        var productQuery = searchInput.value;
        var restUrl = String(root.dataset.restUrl || '').replace(/\/+$/, '') + '/';
        var sectorsLoaded = false;

        function closeMenu(focus) {
            navigation.classList.remove('is-open');
            menuButton.setAttribute('aria-expanded', 'false');
            if (focus) menuButton.focus();
        }
        function closeSector(focus) {
            sectorPanel.hidden = true;
            sectorButton.setAttribute('aria-expanded', 'false');
            if (focus) sectorButton.focus();
        }
        function loadSectors() {
            if (sectorsLoaded) return;
            sectorsLoaded = true;
            Promise.all([
                window.fetch(restUrl + 'sectors', {credentials:'same-origin'}).then(json),
                window.fetch(restUrl + 'sector/current', {credentials:'same-origin'}).then(json)
            ]).then(function (values) {
                var zones = values[0] && values[0].data || [];
                var current = values[1] && values[1].data || null;
                sectorSelect.replaceChildren(new Option('Selecciona una microzona', ''));
                zones.forEach(function (zone) { sectorSelect.append(new Option(String(zone.name) + ' · ' + String(zone.commune), String(zone.id))); });
                if (current) sectorSelect.value = String(current.id);
            }).catch(function () {
                sectorMessage.textContent = 'No fue posible cargar las microzonas.';
                sectorsLoaded = false;
            });
        }

        menuButton.addEventListener('click', function () {
            var open = !navigation.classList.contains('is-open');
            closeSector(false);
            navigation.classList.toggle('is-open', open);
            menuButton.setAttribute('aria-expanded', String(open));
            if (open) {
                var first = navigation.querySelector('a');
                if (first) first.focus();
            }
        });
        sectorButton.addEventListener('click', function () {
            var open = sectorPanel.hidden;
            closeMenu(false);
            sectorPanel.hidden = !open;
            sectorButton.setAttribute('aria-expanded', String(open));
            if (open) { loadSectors(); sectorSelect.focus(); }
        });
        sectorSelect.addEventListener('change', function () {
            if (!/^\d+$/.test(sectorSelect.value)) return;
            sectorSelect.disabled = true;
            sectorMessage.textContent = 'Actualizando microzona…';
            window.fetch(restUrl + 'sector/current/' + encodeURIComponent(sectorSelect.value), {method:'POST',credentials:'same-origin',headers:{'Accept':'application/json'}})
                .then(json).then(function () { window.location.reload(); })
                .catch(function (error) { sectorMessage.textContent = error.message; sectorSelect.disabled = false; });
        });
        function updateSearchScope() {
            var services = searchScope.value === 'services';
            searchForm.action = services ? searchForm.dataset.servicesUrl : searchForm.dataset.productsUrl;
            if (services) {
                productQuery = searchInput.value;
                searchInput.value = '';
            } else {
                searchInput.value = productQuery;
            }
            searchInput.disabled = services;
            searchInput.placeholder = services ? 'Servicios disponibles en tu comuna' : '¿Qué producto necesitas?';
            searchButton.textContent = services ? 'Ver servicios' : 'Buscar';
            searchContext.textContent = services
                ? 'Se mostrarán los servicios disponibles en la comuna de tu microzona. El texto de productos no se enviará y se conservará al volver a Productos.'
                : 'Busca productos disponibles en tu microzona mediante la búsqueda GET productiva.';
        }
        searchScope.addEventListener('change', updateSearchScope);
        updateSearchScope();
        searchForm.addEventListener('submit', function (event) {
            if (searchScope.value === 'services') {
                event.preventDefault();
                var servicesUrl = new URL(searchForm.dataset.servicesUrl, window.location.href);
                var commune = String(searchForm.dataset.currentCommune || '').trim();
                if (commune) servicesUrl.searchParams.set('commune', commune);
                window.location.assign(servicesUrl.toString());
                return;
            }
            var query = searchInput.value.trim();
            if (!query) {
                event.preventDefault();
                searchInput.focus();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (!sectorPanel.hidden) closeSector(true);
                else if (navigation.classList.contains('is-open')) closeMenu(true);
            }
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) { closeMenu(false); closeSector(false); }
        });

        var params = new URLSearchParams(window.location.search);
        var search = String(params.get('search') || '').trim();
        var catalogSearch = document.querySelector('[data-va-catalog-search]');
        var catalogForm = document.querySelector('[data-va-catalog-filters]');
        if (search && catalogSearch && catalogForm) {
            catalogSearch.value = search;
            window.setTimeout(function () { catalogForm.requestSubmit(); }, 0);
        }
    });
}(window, document));
