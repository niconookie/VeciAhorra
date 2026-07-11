(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};
    var REQUEST_TIMEOUT = 12000;

    function positiveInteger(value) {
        var normalized;
        var number;

        if (typeof value === 'number') {
            return Number.isSafeInteger(value) && value > 0 ? value : null;
        }
        if (typeof value !== 'string') {
            return null;
        }
        normalized = value.trim();
        if (!/^[1-9]\d*$/.test(normalized)) {
            return null;
        }
        number = Number(normalized);
        return Number.isSafeInteger(number) ? number : null;
    }

    function decimalToCents(value) {
        var normalized;
        var parts;
        var whole;
        var decimal;
        var cents;

        if (typeof value !== 'string' && typeof value !== 'number') {
            return null;
        }
        normalized = String(value).trim();
        if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
            return null;
        }
        parts = normalized.split('.');
        whole = Number(parts[0]);
        decimal = Number((parts[1] || '').padEnd(2, '0'));
        cents = (whole * 100) + decimal;
        return Number.isSafeInteger(cents) && cents >= 0 ? cents : null;
    }

    function moneyFromCents(cents) {
        if (!Number.isSafeInteger(cents) || cents < 0) {
            return '—';
        }
        return new Intl.NumberFormat(config.locale || 'es-CL', {
            style: 'currency',
            currency: config.currency || 'CLP',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(cents / 100);
    }

    function requestOptions(signal) {
        var cart = config.cart || {};
        var headers = {};

        if (
            !(config.currentUser && config.currentUser.loggedIn)
            && typeof cart.sessionId === 'string'
            && cart.sessionId !== ''
            && typeof cart.sessionHeader === 'string'
            && cart.sessionHeader !== ''
        ) {
            headers[cart.sessionHeader] = cart.sessionId;
        }
        return { headers: headers, signal: signal };
    }

    function loadCart() {
        var controller = new AbortController();
        var timedOut = false;
        var timer = window.setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, REQUEST_TIMEOUT);

        return config.api.get('/cart', requestOptions(controller.signal))
            .catch(function (error) {
                if (timedOut) {
                    throw { code: 'timeout', message: 'La solicitud tardó demasiado. Intenta nuevamente.' };
                }
                throw error;
            })
            .finally(function () { window.clearTimeout(timer); });
    }

    function element(tag, className, value) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (value !== undefined) {
            node.textContent = value;
        }
        return node;
    }

    function normalizedGroups(items) {
        var groups = new Map();
        var inventories = new Set();
        var invalid = false;
        var totalCents = 0;

        items.forEach(function (item) {
            var itemId = positiveInteger(item && item.id);
            var productId = positiveInteger(item && item.product_id);
            var inventoryId = positiveInteger(item && item.inventory_id);
            var minimarketId = positiveInteger(item && item.minimarket_id);
            var quantity = positiveInteger(item && item.quantity);
            var unitCents = decimalToCents(item && item.unit_price_snapshot);
            var receivedSubtotal = decimalToCents(item && item.subtotal);
            var subtotalCents = null;
            var key = minimarketId === null ? 0 : minimarketId;
            var group;

            if (
                quantity !== null
                && unitCents !== null
                && unitCents > 0
                && unitCents <= Math.floor(Number.MAX_SAFE_INTEGER / quantity)
            ) {
                subtotalCents = unitCents * quantity;
                if (receivedSubtotal !== subtotalCents) {
                    subtotalCents = null;
                }
            }

            if (
                itemId === null
                || productId === null
                || inventoryId === null
                || minimarketId === null
                || quantity === null
                || inventories.has(inventoryId)
            ) {
                subtotalCents = null;
            } else {
                inventories.add(inventoryId);
            }

            if (!groups.has(key)) {
                groups.set(key, {
                    id: key,
                    name: typeof item.minimarket_name === 'string' && item.minimarket_name.trim() !== ''
                        ? item.minimarket_name.trim()
                        : 'Minimarket no disponible',
                    items: [],
                    subtotalCents: 0
                });
            }
            group = groups.get(key);
            group.items.push({
                item: item,
                itemId: itemId,
                productId: productId,
                inventoryId: inventoryId,
                quantity: quantity,
                unitCents: unitCents,
                subtotalCents: subtotalCents
            });
            if (subtotalCents === null) {
                invalid = true;
            } else if (
                Number.isSafeInteger(group.subtotalCents + subtotalCents)
                && Number.isSafeInteger(totalCents + subtotalCents)
            ) {
                group.subtotalCents += subtotalCents;
                totalCents += subtotalCents;
            } else {
                invalid = true;
            }
        });

        groups.forEach(function (group) {
            group.items.sort(function (a, b) {
                return (a.inventoryId || 0) - (b.inventoryId || 0)
                    || (a.productId || 0) - (b.productId || 0)
                    || (a.itemId || 0) - (b.itemId || 0);
            });
        });

        return {
            groups: Array.from(groups.values()).sort(function (a, b) {
                return a.id - b.id || a.name.localeCompare(b.name);
            }),
            totalCents: totalCents,
            valid: !invalid
        };
    }

    function mount(root) {
        var loading = root.querySelector('[data-va-checkout-loading]');
        var error = root.querySelector('[data-va-checkout-error]');
        var errorMessage = root.querySelector('[data-va-checkout-error-message]');
        var retry = root.querySelector('[data-va-checkout-retry]');
        var empty = root.querySelector('[data-va-checkout-empty]');
        var content = root.querySelector('[data-va-checkout-content]');
        var groupsRoot = root.querySelector('[data-va-checkout-groups]');
        var totalRoot = root.querySelector('[data-va-checkout-total]');
        var optionsRoot = root.querySelector('[data-va-delivery-options]');
        var minimumMessage = root.querySelector('[data-va-delivery-minimum]');
        var deliveryFields = root.querySelector('[data-va-delivery-fields]');
        var form = root.querySelector('[data-va-checkout-form]');
        var submit = root.querySelector('[data-va-checkout-submit]');
        var status = root.querySelector('[data-va-checkout-status]');
        var minimum = config.checkout && config.checkout.minimumDeliveryAmount;
        var minimumUnits = typeof minimum === 'number' && Number.isSafeInteger(minimum) && minimum >= 0
            ? minimum
            : 8000;
        var minimumCents = minimumUnits * 100;
        var calculated = null;
        var deliveryEligible = false;
        var submitted = false;

        function showError(message) {
            loading.hidden = true;
            empty.hidden = true;
            content.hidden = true;
            error.hidden = false;
            errorMessage.textContent = message;
        }

        function renderLine(entry) {
            var item = entry.item;
            var row = element('div', 'va-checkout-line');
            var name = typeof item.product_name === 'string' && item.product_name.trim() !== ''
                ? item.product_name.trim()
                : 'Producto no disponible';
            var quantity = entry.quantity !== null
                ? String(entry.quantity)
                : '—';

            row.append(
                element('strong', 'va-checkout-line__product', name),
                element('span', '', 'Cantidad: ' + quantity),
                element('span', '', 'Precio unitario: ' + moneyFromCents(entry.unitCents)),
                element('span', 'va-checkout-line__subtotal', 'Subtotal: ' + moneyFromCents(entry.subtotalCents))
            );
            return row;
        }

        function renderGroups(summary) {
            groupsRoot.replaceChildren();
            summary.groups.forEach(function (group) {
                var section = element('section', 'va-checkout-group');
                var title = element('h3', '', group.name);
                var lines = element('div', 'va-checkout-group__items');
                group.items.forEach(function (entry) { lines.append(renderLine(entry)); });
                section.append(
                    title,
                    lines,
                    element('p', 'va-checkout-group__subtotal', 'Subtotal minimarket: ' + moneyFromCents(group.subtotalCents))
                );
                groupsRoot.append(section);
            });
            totalRoot.textContent = moneyFromCents(summary.totalCents);
        }

        function deliveryOption(value, label, checked) {
            var wrapper = element('label', 'va-delivery-option');
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'delivery_method';
            radio.value = value;
            radio.checked = checked;
            wrapper.append(radio, element('span', '', label));
            return wrapper;
        }

        function selectedMethod() {
            var selected = form.querySelector('input[name="delivery_method"]:checked');
            return selected ? selected.value : 'pickup';
        }

        function renderDelivery(summary) {
            deliveryEligible = summary.totalCents >= minimumCents;
            optionsRoot.replaceChildren(deliveryOption('pickup', 'Retiro en minimarket', true));
            if (deliveryEligible) {
                optionsRoot.append(deliveryOption('delivery', 'Despacho', false));
                minimumMessage.hidden = true;
            } else {
                minimumMessage.textContent = 'El despacho está disponible para compras desde ' + moneyFromCents(minimumCents) + '.';
                minimumMessage.hidden = false;
            }
            updateDeliveryFields();
        }

        function fieldError(input, message) {
            var field = input.closest('.va-field');
            var messageNode = field ? field.querySelector('[data-va-field-error]') : null;
            if (!messageNode) {
                return;
            }
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (message) {
                messageNode.textContent = message;
                messageNode.hidden = false;
                input.setAttribute('aria-describedby', messageNode.id);
            } else {
                messageNode.textContent = '';
                messageNode.hidden = true;
                input.removeAttribute('aria-describedby');
            }
        }

        function validateField(input) {
            var value = input.value.trim();
            var required = input.required;
            var message = '';
            if (required && value === '') {
                message = 'Este campo es obligatorio.';
            } else if (input.name === 'email' && value !== '' && !input.validity.valid) {
                message = 'Ingresa un correo electrónico válido.';
            } else if (input.name === 'phone' && value !== '' && !/^[+0-9][0-9\s()-]{6,19}$/.test(value)) {
                message = 'Ingresa un teléfono válido.';
            }
            fieldError(input, message);
            return message === '';
        }

        function validateForm(showErrors) {
            var valid = calculated !== null && calculated.valid && calculated.groups.length > 0;
            form.querySelectorAll('[data-va-field]').forEach(function (input) {
                var fieldValid = validateField(input);
                if (!showErrors && !fieldValid && input.value.trim() === '') {
                    fieldError(input, '');
                }
                valid = fieldValid && valid;
            });
            submit.disabled = submitted || !valid;
            return valid;
        }

        function updateDeliveryFields() {
            var delivery = deliveryEligible && selectedMethod() === 'delivery';
            deliveryFields.hidden = !delivery;
            ['address', 'commune'].forEach(function (name) {
                var input = form.elements[name];
                input.required = delivery;
                if (!delivery) {
                    fieldError(input, '');
                }
            });
            validateForm(false);
        }

        function render(payload) {
            var items = payload && Array.isArray(payload.data) ? payload.data : null;
            if (items === null) {
                showError('El servidor devolvió un carrito no válido.');
                return;
            }
            loading.hidden = true;
            error.hidden = true;
            if (items.length === 0) {
                empty.hidden = false;
                content.hidden = true;
                return;
            }
            calculated = normalizedGroups(items);
            renderGroups(calculated);
            renderDelivery(calculated);
            empty.hidden = true;
            content.hidden = false;
            if (!calculated.valid) {
                status.hidden = false;
                status.textContent = 'Algunos importes no son válidos. Revisa el carrito antes de continuar.';
            }
            validateForm(false);
        }

        function load() {
            loading.hidden = false;
            error.hidden = true;
            empty.hidden = true;
            content.hidden = true;
            submit.disabled = true;
            return loadCart().then(render).catch(function (requestError) {
                showError(requestError && requestError.message
                    ? requestError.message
                    : 'No fue posible cargar el checkout. Intenta nuevamente.');
            });
        }

        form.addEventListener('input', function (event) {
            if (event.target.matches('[data-va-field]')) {
                validateForm(false);
            }
        });
        form.addEventListener('change', function (event) {
            if (event.target.name === 'delivery_method') {
                updateDeliveryFields();
            } else if (event.target.matches('[data-va-field]')) {
                validateForm(false);
            }
        });
        form.addEventListener('submit', function (event) {
            var firstInvalid;
            event.preventDefault();
            if (submitted || !validateForm(true)) {
                firstInvalid = form.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return;
            }
            submitted = true;
            submit.disabled = true;
            status.hidden = false;
            status.textContent = 'La conexión con el flujo transaccional se incorporará en 28.7.2. No se guardaron datos ni se modificó el carrito.';
            window.setTimeout(function () {
                submitted = false;
                validateForm(false);
            }, 0);
        });
        retry.addEventListener('click', load);

        root.vaCheckout = {
            load: load,
            getSummary: function () { return calculated; },
            validate: function () { return validateForm(true); }
        };
        load();
    }

    function initialize() {
        document.querySelectorAll('[data-va-checkout]').forEach(function (root) {
            if (!root.vaCheckout) {
                mount(root);
            }
        });
    }

    config.publicCheckout = {
        positiveInteger: positiveInteger,
        decimalToCents: decimalToCents,
        normalizedGroups: normalizedGroups,
        initialize: initialize
    };
    window.VeciAhorra = config;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}(window, document));
