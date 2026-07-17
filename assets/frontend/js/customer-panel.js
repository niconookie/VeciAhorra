(function (window, document) {
    'use strict';

    var ENDPOINT = 'customer-panel/purchases';
    var TIMEOUT_MS = 12000;

    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function validPurchase(item) {
        return item && typeof item === 'object'
            && typeof item.checkout_public_id === 'string'
            && typeof item.created_at === 'string'
            && item.total && typeof item.total.amount === 'string'
            && typeof item.total.currency === 'string'
            && Number.isInteger(item.product_quantity)
            && Number.isInteger(item.order_count)
            && Number.isInteger(item.minimarket_count)
            && Array.isArray(item.minimarkets)
            && item.minimarkets.every(function (name) { return typeof name === 'string'; })
            && item.visible_status && typeof item.visible_status.label === 'string'
            && typeof item.visible_status.message === 'string';
    }

    function purchasesFrom(payload) {
        if (!payload || payload.success !== true || !Array.isArray(payload.data)) {
            throw new Error('invalid_contract');
        }

        if (!payload.data.every(validPurchase)) {
            throw new Error('invalid_contract');
        }

        return payload.data;
    }

    function formatDate(value, config) {
        var date = new Date(value);
        var locale = String(config.locale || 'es-CL');
        var timeZone = String(config.timeZone || 'UTC');

        if (Number.isNaN(date.getTime())) {
            return 'Fecha no disponible';
        }

        try {
            return new Intl.DateTimeFormat(locale, {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: timeZone
            }).format(date);
        } catch (error) {
            return new Intl.DateTimeFormat('es-CL', {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: 'UTC'
            }).format(date) + ' UTC';
        }
    }

    function formatTotal(total, config) {
        var amount = total.amount;
        var currency = total.currency;
        var locale = String(config.locale || 'es-CL');
        var integerAmount;

        if (currency === 'CLP' && /^(0|[1-9]\d*)\.00$/.test(amount)) {
            integerAmount = Number(amount.slice(0, -3));

            if (!Number.isSafeInteger(integerAmount)) {
                return amount + ' ' + currency;
            }

            try {
                return new Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: 'CLP',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                }).format(integerAmount);
            } catch (error) {
                return amount + ' ' + currency;
            }
        }

        return amount + ' ' + currency;
    }

    function renderPurchase(item, config) {
        var listItem = element('li', 'va-customer-panel__item');
        var article = element('article', 'va-customer-panel__purchase va-card');
        var date = element('p', 'va-customer-panel__date', formatDate(item.created_at, config));
        var publicId = element('p', 'va-customer-panel__public-id', item.checkout_public_id);
        var stores = element(
            'p',
            'va-customer-panel__stores',
            item.minimarkets.length > 0 ? item.minimarkets.join(', ') : 'Minimarket no disponible'
        );
        var status = element('div', 'va-customer-panel__purchase-status');
        var quantities = element('p', 'va-customer-panel__quantities');
        var total = element('p', 'va-customer-panel__total', formatTotal(item.total, config));

        status.append(element('strong', '', item.visible_status.label));
        status.append(element('p', '', item.visible_status.message));
        quantities.append(
            document.createTextNode(item.product_quantity + (item.product_quantity === 1 ? ' producto · ' : ' productos · ')),
            document.createTextNode(item.order_count + (item.order_count === 1 ? ' pedido' : ' pedidos'))
        );
        article.append(date, publicId, stores, status, quantities, total);
        listItem.append(article);

        return listItem;
    }

    function renderLoading(root) {
        root.content.setAttribute('aria-busy', 'true');
        root.status.hidden = false;
        root.statusText.textContent = 'Cargando tus compras…';
    }

    function renderEmpty(root) {
        var empty = element('div', 'va-empty-state');

        empty.append(element('p', 'va-empty-state__message', 'Aún no tienes compras para mostrar.'));
        root.content.replaceChildren(empty);
        root.content.setAttribute('aria-busy', 'false');
        root.status.hidden = true;
        root.announcer.textContent = 'Aún no tienes compras para mostrar.';
    }

    function renderList(root, purchases, config) {
        var heading = element('h2', 'va-customer-panel__list-title', 'Tus compras');
        var list = element('ol', 'va-customer-panel__list');

        heading.tabIndex = -1;
        purchases.forEach(function (purchase) {
            list.append(renderPurchase(purchase, config));
        });
        root.content.replaceChildren(heading, list);
        root.content.setAttribute('aria-busy', 'false');
        root.status.hidden = true;
        root.announcer.textContent = 'Tus compras se cargaron correctamente.';
        heading.focus();
    }

    function renderError(root) {
        root.content.replaceChildren(element(
            'p',
            'va-alert va-alert--error',
            'No pudimos cargar tus compras. Inténtalo nuevamente.'
        ));
        root.content.setAttribute('aria-busy', 'false');
        root.status.hidden = true;
        root.announcer.textContent = 'No pudimos cargar tus compras. Inténtalo nuevamente.';
    }

    function requestPurchases(api) {
        var controller = typeof window.AbortController === 'function'
            ? new window.AbortController()
            : null;
        var timer;
        var timeout = new Promise(function (resolve, reject) {
            timer = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }

                reject(new Error('timeout'));
            }, TIMEOUT_MS);
        });
        var request = api.get(ENDPOINT, controller ? {signal: controller.signal} : {});

        return Promise.race([request, timeout]).finally(function () {
            window.clearTimeout(timer);
        });
    }

    function initialize() {
        var mount = document.querySelector('[data-va-customer-panel-mount]');
        var config = window.VeciAhorra || {};
        var api = config.api;
        var root;

        if (!mount || mount.dataset.vaCustomerPanelInitialized === 'true') {
            return;
        }

        mount.dataset.vaCustomerPanelInitialized = 'true';
        mount.classList.add('va-customer-panel--initialized');
        root = {
            content: mount.querySelector('[data-va-customer-panel-content]'),
            status: mount.querySelector('[data-va-customer-panel-status]'),
            statusText: mount.querySelector('[data-va-customer-panel-status-text]'),
            announcer: mount.querySelector('[data-va-customer-panel-announcer]')
        };

        if (!root.content || !root.status || !root.statusText || !root.announcer) {
            return;
        }

        if (!api || typeof api.get !== 'function') {
            renderError(root);
            return;
        }

        renderLoading(root);
        requestPurchases(api)
            .then(purchasesFrom)
            .then(function (purchases) {
                if (purchases.length === 0) {
                    renderEmpty(root);
                    return;
                }

                renderList(root, purchases, config);
            })
            .catch(function () {
                renderError(root);
            });
    }

    initialize();
}(window, document));
