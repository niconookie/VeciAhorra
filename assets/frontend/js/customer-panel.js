(function (window, document) {
    'use strict';

    var ENDPOINT = 'customer-panel/purchases';
    var DETAIL_ENDPOINT = ENDPOINT + '/';
    var TIMEOUT_MS = 12000;
    var PUBLIC_ID_PATTERN = /^chk_[A-Za-z0-9_-]{43}$/;
    var TIMELINE_DECORATION = {
        checkout_created: 'completed',
        payment_confirmed: 'completed',
        payment_reconciled: 'completed',
        orders_materialized: 'completed',
        delivery_created: 'completed'
    };

    function canonicalListUrl(config) {
        var url = new URL(config.pages.orders, window.location.href);

        url.search = '';
        url.hash = '';

        return url;
    }

    function canonicalDetailUrl(publicId, config) {
        var url = canonicalListUrl(config);

        url.searchParams.set('compra', publicId);

        return url;
    }

    function readRoute() {
        var url = new URL(window.location.href);
        var values = url.searchParams.getAll('compra');

        if (values.length === 0) {
            return {name: 'list'};
        }

        if (values.length === 1 && PUBLIC_ID_PATTERN.test(values[0])) {
            return {name: 'detail', publicId: values[0]};
        }

        return {name: 'not_found'};
    }

    function canonicalizeInitialRoute(route, config) {
        var canonical;

        if (route.name === 'list') {
            canonical = canonicalListUrl(config);
        } else if (route.name === 'detail') {
            canonical = canonicalDetailUrl(route.publicId, config);
        } else {
            return;
        }

        if (canonical.href !== window.location.href) {
            window.history.replaceState(null, '', canonical.href);
        }
    }

    function createNavigationState(mount, root, config, api) {
        return {
            mount: mount,
            root: root,
            config: config,
            api: api,
            activeController: null,
            requestGeneration: 0,
            activeMode: 'booting',
            activePublicId: null,
            activeCanonicalUrl: '',
            listSnapshot: null,
            originLink: null,
            scrollPosition: 0,
            destroyed: false
        };
    }

    function beginRequest(state, mode, publicId, canonicalUrl) {
        if (state.activeController) {
            state.activeController.abort();
        }

        state.requestGeneration += 1;
        state.activeController = typeof window.AbortController === 'function'
            ? new window.AbortController()
            : null;
        state.activeMode = mode;
        state.activePublicId = publicId;
        state.activeCanonicalUrl = canonicalUrl;

        return {
            generation: state.requestGeneration,
            mode: mode,
            publicId: publicId,
            canonicalUrl: canonicalUrl,
            controller: state.activeController,
            signal: state.activeController ? state.activeController.signal : undefined
        };
    }

    function isCurrentRequest(state, request) {
        return !state.destroyed
            && state.requestGeneration === request.generation
            && state.activeMode === request.mode
            && state.activePublicId === request.publicId
            && state.activeCanonicalUrl === request.canonicalUrl;
    }

    function saveListSnapshot(state, originLink) {
        state.listSnapshot = Array.from(state.root.content.childNodes);
        state.originLink = originLink;
        state.scrollPosition = window.scrollY;
    }

    function restoreListSnapshot(state) {
        var heading;

        if (state.listSnapshot) {
            state.root.content.replaceChildren.apply(state.root.content, state.listSnapshot);
        } else {
            heading = element('h2', 'va-customer-panel__list-title', 'Tus compras');
            heading.tabIndex = -1;
            state.root.content.replaceChildren(heading);
        }

        state.root.content.setAttribute('aria-busy', 'false');
        state.root.status.hidden = true;
        window.setTimeout(function () {
            window.scrollTo(0, state.scrollPosition);

            if (state.originLink && state.originLink.isConnected) {
                state.originLink.focus();
            } else {
                heading = state.root.content.querySelector('.va-customer-panel__list-title');
                if (heading) {
                    heading.focus();
                }
            }
        }, 0);
    }

    function renderDetailLoading(state) {
        var heading = element('h2', 'va-customer-panel__detail-title', 'Detalle de compra');
        var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');
        var loader = element('div', 'va-loader');
        var indicator = element('span', 'va-loader__indicator');

        heading.tabIndex = -1;
        back.href = canonicalListUrl(state.config).href;
        loader.setAttribute('role', 'status');
        loader.setAttribute('aria-live', 'polite');
        indicator.setAttribute('aria-hidden', 'true');
        loader.append(
            indicator,
            element('span', '', 'Cargando detalle de compra…')
        );
        state.root.content.replaceChildren(heading, back, loader);
        state.root.content.setAttribute('aria-busy', 'true');
        state.root.status.hidden = true;
        heading.focus();
    }

    function renderDetailNotFound(state) {
        var heading = element('h2', 'va-customer-panel__detail-title', 'Detalle de compra');
        var message = element('p', 'va-alert va-alert--error', 'La compra no está disponible.');
        var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');

        heading.tabIndex = -1;
        back.href = canonicalListUrl(state.config).href;
        state.root.content.replaceChildren(heading, message, back);
        state.root.content.setAttribute('aria-busy', 'false');
        state.root.status.hidden = true;
        state.root.announcer.textContent = 'La compra no está disponible.';
        heading.focus();
    }

    function renderDetailRecoverableError(state) {
        var heading = element('h2', 'va-customer-panel__detail-title', 'Detalle de compra');
        var message = element('p', 'va-alert va-alert--error', 'No pudimos cargar tus compras. Inténtalo nuevamente.');
        var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');

        heading.tabIndex = -1;
        back.href = canonicalListUrl(state.config).href;
        state.root.content.replaceChildren(heading, message, back);
        state.root.content.setAttribute('aria-busy', 'false');
        state.root.status.hidden = true;
        state.root.announcer.textContent = 'No pudimos cargar tus compras. Inténtalo nuevamente.';
        heading.focus();
    }

    function enterDetail(state, route, canonicalUrl) {
        if (state.activeMode === 'detail' && state.activeCanonicalUrl === canonicalUrl) {
            return;
        }

        var request = beginRequest(state, 'detail', route.publicId, canonicalUrl);

        renderDetailLoading(state);
        requestDetail(state.api, request, route.publicId)
            .then(validateDetailEnvelope)
            .then(function (detail) {
                if (!isCurrentRequest(state, request)) {
                    return;
                }

                state.activeController = null;
                renderDetail(state, detail);
            })
            .catch(function (error) {
                if (!isCurrentRequest(state, request)) {
                    return;
                }

                state.activeController = null;
                if (error && error.status === 404) {
                    renderDetailNotFound(state);
                    return;
                }

                renderDetailRecoverableError(state);
            });
    }

    function enterList(state, canonicalUrl) {
        if (state.activeMode === 'list' && state.activeCanonicalUrl === canonicalUrl) {
            return;
        }

        beginRequest(state, 'list', null, canonicalUrl);
        restoreListSnapshot(state);
    }

    function enterNotFound(state) {
        beginRequest(state, 'not_found', null, window.location.href);
        renderDetailNotFound(state);
    }

    function navigate(state, route, canonicalUrl, push) {
        if (push) {
            window.history.pushState(null, '', canonicalUrl);
        }

        if (route.name === 'detail') {
            enterDetail(state, route, canonicalUrl);
        } else if (route.name === 'list') {
            enterList(state, canonicalUrl);
        } else {
            enterNotFound(state);
        }
    }

    function handlePopState(state) {
        var route = readRoute();
        var canonicalUrl = route.name === 'detail'
            ? canonicalDetailUrl(route.publicId, state.config).href
            : (route.name === 'list' ? canonicalListUrl(state.config).href : window.location.href);

        navigate(state, route, canonicalUrl, false);
    }

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

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function isString(value) {
        return typeof value === 'string';
    }

    function isNullableString(value) {
        return value === null || isString(value);
    }

    function isNonNegativeInteger(value) {
        return Number.isInteger(value) && value >= 0;
    }

    function validDetailItem(item) {
        return isObject(item)
            && isString(item.name)
            && typeof item.name_historical === 'boolean'
            && isNullableString(item.image)
            && typeof item.image_historical === 'boolean'
            && isNonNegativeInteger(item.quantity)
            && isString(item.unit_price)
            && isString(item.subtotal);
    }

    function validDetailOrder(order) {
        return isObject(order)
            && isObject(order.minimarket)
            && isString(order.minimarket.name)
            && typeof order.minimarket.historical === 'boolean'
            && isString(order.subtotal)
            && Array.isArray(order.items)
            && order.items.every(validDetailItem);
    }

    function validPayment(payment) {
        return payment === null || (isObject(payment)
            && isString(payment.status)
            && isString(payment.label)
            && isString(payment.amount)
            && isString(payment.currency)
            && isNullableString(payment.paid_at)
            && isNullableString(payment.method));
    }

    function validTimelineEvent(event) {
        return isObject(event)
            && isString(event.code)
            && isString(event.label)
            && isString(event.occurred_at);
    }

    function validateDetailEnvelope(payload) {
        var detail = payload && payload.data;
        var summary = detail && detail.summary;

        if (!payload || payload.success !== true || !isObject(detail)
            || !isString(detail.checkout_public_id)
            || !isString(detail.created_at)
            || !isObject(detail.visible_status)
            || !isString(detail.visible_status.code)
            || !isString(detail.visible_status.label)
            || !isString(detail.visible_status.message)
            || typeof detail.requires_review !== 'boolean'
            || !isObject(detail.fulfillment)
            || !isNullableString(detail.fulfillment.method)
            || !isString(detail.fulfillment.label)
            || !isObject(summary)
            || !isString(summary.subtotal)
            || !isString(summary.total)
            || !isString(summary.currency)
            || !isNonNegativeInteger(summary.product_quantity)
            || !isNonNegativeInteger(summary.line_count)
            || !isNonNegativeInteger(summary.order_count)
            || !isNonNegativeInteger(summary.minimarket_count)
            || !Array.isArray(detail.orders)
            || !detail.orders.every(validDetailOrder)
            || !validPayment(detail.payment)
            || !isObject(detail.delivery)
            || !isNullableString(detail.delivery.method)
            || !isString(detail.delivery.status)
            || !isString(detail.delivery.label)
            || !Array.isArray(detail.timeline)
            || !detail.timeline.every(validTimelineEvent)) {
            throw new Error('invalid_contract');
        }

        return detail;
    }

    function detailValue(label, value, modifier) {
        var className = 'va-customer-panel__detail-row';
        var row;

        if (modifier) {
            className += ' va-customer-panel__detail-row--' + modifier;
        }

        row = element('div', className);
        row.append(element('dt', '', label), element('dd', '', value));
        return row;
    }

    function safeImageUrl(value) {
        var url;

        try {
            url = new URL(value, window.location.href);
        } catch (error) {
            return null;
        }

        return (url.protocol === 'https:' || url.protocol === 'http:')
            && url.username === '' && url.password === ''
            ? url.href
            : null;
    }

    function productImagePlaceholder() {
        var placeholder = element('span', 'va-customer-panel__detail-image-placeholder');

        placeholder.setAttribute('aria-hidden', 'true');
        return placeholder;
    }

    function renderProductImage(item) {
        var imageUrl = item.image === null ? null : safeImageUrl(item.image);
        var image;

        if (imageUrl !== null) {
            image = element('img', 'va-customer-panel__detail-image');
            image.src = imageUrl;
            image.alt = '';
            image.loading = 'lazy';
            image.onerror = function () {
                image.onerror = null;
                image.replaceWith(productImagePlaceholder());
            };
            return image;
        }

        return productImagePlaceholder();
    }

    function renderDetailItem(item, currency, config) {
        var listItem = element('li', 'va-customer-panel__detail-item');
        var content = element('div', 'va-customer-panel__detail-item-content');
        var values = element('dl', 'va-customer-panel__detail-values');
        content.append(renderProductImage(item));

        values.append(
            detailValue('Producto', item.name),
            detailValue('Cantidad', String(item.quantity)),
            detailValue('Precio unitario', formatTotal({amount: item.unit_price, currency: currency}, config)),
            detailValue('Subtotal', formatTotal({amount: item.subtotal, currency: currency}, config))
        );
        content.append(values);
        listItem.append(content);
        return listItem;
    }

    function renderTimelineEntry(entry, config) {
        var decoration = TIMELINE_DECORATION[entry.code] || 'neutral';
        var listItem = element(
            'li',
            'va-customer-panel__timeline-entry va-customer-panel__timeline-entry--' + decoration
        );
        var time = element('time', 'va-customer-panel__timeline-time', formatDate(entry.occurred_at, config));

        listItem.append(element('p', 'va-customer-panel__timeline-label', entry.label));
        if (typeof entry.message === 'string') {
            listItem.append(element('p', 'va-customer-panel__timeline-message', entry.message));
        }
        time.dateTime = entry.occurred_at;
        listItem.append(time);
        return listItem;
    }

    function renderTimeline(entries, config) {
        var section = element('section', 'va-customer-panel__detail-section va-customer-panel__timeline');
        var list = element('ol', 'va-customer-panel__timeline-list');

        section.append(element('h3', '', 'Timeline'));
        if (entries.length === 0) {
            section.append(element('p', '', 'No hay eventos para mostrar.'));
            return section;
        }

        entries.forEach(function (entry) {
            list.append(renderTimelineEntry(entry, config));
        });
        section.append(list);
        return section;
    }

    function renderDetailOrder(order, currency, config) {
        var listItem = element('li', 'va-customer-panel__detail-order va-card');
        var orderHeader = element('div', 'va-customer-panel__detail-order-header');
        var heading = element('h4', '', order.minimarket.name);
        var subtotal = element('p', 'va-customer-panel__detail-order-subtotal', 'Subtotal: ' + formatTotal({amount: order.subtotal, currency: currency}, config));
        var productsHeading = element('h5', '', 'Productos');
        var products = element('ul', 'va-customer-panel__detail-items');

        order.items.forEach(function (item) {
            products.append(renderDetailItem(item, currency, config));
        });
        orderHeader.append(heading, subtotal);
        listItem.append(orderHeader, productsHeading, products);
        return listItem;
    }

    function renderDetail(state, detail) {
        var heading = element('h2', 'va-customer-panel__detail-title', 'Detalle de compra');
        var back = element('a', 'va-customer-panel__back-link', 'Volver a mis compras');
        var headingRow = element('div', 'va-customer-panel__detail-heading-row');
        var overview = element('div', 'va-customer-panel__detail-overview');
        var header = element('section', 'va-customer-panel__detail-header');
        var headerValues = element('dl', 'va-customer-panel__detail-values');
        var summarySection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-summary');
        var summary = element('dl', 'va-customer-panel__detail-values');
        var ordersSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-orders-section');
        var orders = element('ol', 'va-customer-panel__detail-orders');
        var paymentSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-payment');
        var paymentValues;
        var deliverySection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-delivery');
        var services = element('div', 'va-customer-panel__detail-services');
        var timelineSection;

        heading.tabIndex = -1;
        back.href = canonicalListUrl(state.config).href;
        headerValues.append(
            detailValue('Identificador', detail.checkout_public_id, 'identifier'),
            detailValue('Fecha', formatDate(detail.created_at, state.config), 'date'),
            detailValue('Estado', detail.visible_status.label, 'status'),
            detailValue('Información', detail.visible_status.message, 'message'),
            detailValue('Entrega', detail.fulfillment.label, 'fulfillment')
        );
        if (detail.requires_review) {
            headerValues.append(detailValue('Revisión', 'Requiere revisión'));
        }
        header.append(headerValues);

        summarySection.append(element('h3', '', 'Resumen'));
        summary.append(
            detailValue('Subtotal', formatTotal({amount: detail.summary.subtotal, currency: detail.summary.currency}, state.config)),
            detailValue('Total', formatTotal({amount: detail.summary.total, currency: detail.summary.currency}, state.config), 'total'),
            detailValue('Moneda', detail.summary.currency),
            detailValue('Cantidad de productos', String(detail.summary.product_quantity)),
            detailValue('Líneas', String(detail.summary.line_count)),
            detailValue('Pedidos', String(detail.summary.order_count)),
            detailValue('Minimarkets', String(detail.summary.minimarket_count))
        );
        summarySection.append(summary);

        ordersSection.append(element('h3', '', 'Órdenes'));
        detail.orders.forEach(function (order) {
            orders.append(renderDetailOrder(order, detail.summary.currency, state.config));
        });
        ordersSection.append(orders);

        paymentSection.append(element('h3', '', 'Pago'));
        if (detail.payment === null) {
            paymentSection.append(element('p', '', 'Información de pago no disponible.'));
        } else {
            paymentValues = element('dl', 'va-customer-panel__detail-values');
            paymentValues.append(
                detailValue('Estado', detail.payment.label),
                detailValue('Monto', formatTotal({amount: detail.payment.amount, currency: detail.payment.currency}, state.config)),
                detailValue('Moneda', detail.payment.currency)
            );
            if (detail.payment.paid_at !== null) {
                paymentValues.append(detailValue('Fecha de pago', formatDate(detail.payment.paid_at, state.config)));
            }
            if (detail.payment.method !== null) {
                paymentValues.append(detailValue('Método', detail.payment.method));
            }
            paymentSection.append(paymentValues);
        }

        deliverySection.append(
            element('h3', '', 'Entrega'),
            element('p', '', detail.delivery.label)
        );
        timelineSection = renderTimeline(detail.timeline, state.config);
        headingRow.append(heading, back);
        overview.append(header, summarySection);
        services.append(paymentSection, deliverySection);
        state.root.content.replaceChildren(
            headingRow, overview, ordersSection, services, timelineSection
        );
        state.root.content.setAttribute('aria-busy', 'false');
        state.root.status.hidden = true;
        state.root.announcer.textContent = 'El detalle de la compra se cargó correctamente.';
        heading.focus();
    }

    function renderPurchase(item, config) {
        var listItem = element('li', 'va-customer-panel__item');
        var article = element('article', 'va-customer-panel__purchase va-card');
        var link = element('a', 'va-customer-panel__purchase-link');
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
        link.href = canonicalDetailUrl(item.checkout_public_id, config).href;
        link.append(date, publicId, stores, status, quantities, total);
        article.append(link);
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

    function requestWithTimeout(request, operation) {
        var timer;
        var timeout = new Promise(function (resolve, reject) {
            timer = window.setTimeout(function () {
                if (request.controller) {
                    request.controller.abort();
                }

                reject(new Error('timeout'));
            }, TIMEOUT_MS);
        });
        var apiPromise = operation(request.signal ? {signal: request.signal} : {});

        return Promise.race([apiPromise, timeout]).finally(function () {
            window.clearTimeout(timer);
        });
    }

    function requestPurchases(api, request) {
        return requestWithTimeout(request, function (options) {
            return api.get(ENDPOINT, options);
        });
    }

    function requestDetail(api, request, publicId) {
        return requestWithTimeout(request, function (options) {
            return api.get(DETAIL_ENDPOINT + encodeURIComponent(publicId), options);
        });
    }

    function loadList(state) {
        var canonicalUrl = canonicalListUrl(state.config).href;
        var request = beginRequest(state, 'list', null, canonicalUrl);

        renderLoading(state.root);
        requestPurchases(state.api, request)
            .then(purchasesFrom)
            .then(function (purchases) {
                if (!isCurrentRequest(state, request)) {
                    return;
                }

                state.activeController = null;
                if (purchases.length === 0) {
                    state.listSnapshot = null;
                    state.originLink = null;
                    state.scrollPosition = 0;
                    renderEmpty(state.root);
                    return;
                }

                state.listSnapshot = null;
                state.originLink = null;
                state.scrollPosition = 0;
                renderList(state.root, purchases, state.config);
            })
            .catch(function () {
                if (isCurrentRequest(state, request)) {
                    state.activeController = null;
                    renderError(state.root);
                }
            });
    }

    function handlePanelClick(event, state) {
        var link = event.target.closest('a');
        var url;
        var values;
        var route;

        if (!link
            || !link.matches('.va-customer-panel__purchase-link, .va-customer-panel__back-link')
            || event.defaultPrevented || event.button !== 0
            || (link.target && link.target !== '_self')
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        url = new URL(link.href, window.location.href);

        if (url.origin !== window.location.origin) {
            return;
        }

        values = url.searchParams.getAll('compra');
        route = values.length === 1 && PUBLIC_ID_PATTERN.test(values[0])
            ? {name: 'detail', publicId: values[0]}
            : {name: 'list'};
        event.preventDefault();

        if (route.name === 'detail') {
            saveListSnapshot(state, link);
            navigate(state, route, canonicalDetailUrl(route.publicId, state.config).href, true);
        } else {
            navigate(state, route, canonicalListUrl(state.config).href, true);
        }
    }

    function initialize() {
        var mount = document.querySelector('[data-va-customer-panel-mount]');
        var config = window.VeciAhorra || {};
        var api = config.api;
        var root;
        var route;
        var state;

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

        route = readRoute();
        canonicalizeInitialRoute(route, config);
        state = createNavigationState(mount, root, config, api);
        mount.addEventListener('click', function (event) {
            handlePanelClick(event, state);
        });
        window.addEventListener('popstate', function () {
            handlePopState(state);
        });

        if (route.name === 'detail') {
            enterDetail(state, route, canonicalDetailUrl(route.publicId, config).href);
        } else if (route.name === 'list') {
            loadList(state);
        } else {
            enterNotFound(state);
        }
    }

    initialize();
}(window, document));
