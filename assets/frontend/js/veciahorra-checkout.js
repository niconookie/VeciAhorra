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

    function nonNegativeInteger(value) {
        return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
            ? value
            : null;
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

    function normalizedGroups(items, allowDomainInvalid) {
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
            var domainInvalid = allowDomainInvalid === true && item.valid === false;

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
                if (!domainInvalid) {
                    invalid = true;
                }
            } else if (item.valid === false) {
                // Backend excludes invalid domain items from its authoritative total.
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

    function normalizedValidation(payload, previousItems) {
        var data = payload && payload.success === true ? payload.data : null;
        var metadata = new Map();
        var items;
        var errors;
        var summary;
        var normalized;
        var summaryCents;

        previousItems.forEach(function (item) {
            var id = positiveInteger(item && item.id);
            if (id !== null) {
                metadata.set(id, item);
            }
        });

        if (
            !data
            || typeof data.valid !== 'boolean'
            || !Array.isArray(data.items)
            || !Array.isArray(data.errors)
            || !data.summary
            || typeof data.summary !== 'object'
        ) {
            throw { code: 'invalid_response', message: 'El servidor entregó una respuesta incompleta. Inténtalo nuevamente.' };
        }

        items = data.items.map(function (item) {
            var id = positiveInteger(item && item.id);
            var previous = id === null ? null : metadata.get(id);
            if (!item || typeof item !== 'object' || typeof item.valid !== 'boolean' || !Array.isArray(item.errors)) {
                throw { code: 'invalid_response', message: 'El servidor entregó ítems de validación incompletos.' };
            }
            return Object.assign({}, item, {
                product_name: previous && typeof previous.product_name === 'string' ? previous.product_name : null,
                minimarket_name: previous && typeof previous.minimarket_name === 'string' ? previous.minimarket_name : null
            });
        });

        errors = data.errors.map(function (error) {
            if (
                !error
                || typeof error !== 'object'
                || typeof error.code !== 'string'
                || typeof error.message !== 'string'
                || error.message.trim() === ''
            ) {
                throw { code: 'invalid_response', message: 'El servidor entregó errores de validación incompletos.' };
            }
            return {
                code: error.code,
                message: error.message.trim(),
                cartItemId: error.cart_item_id === undefined
                    ? null
                    : positiveInteger(error.cart_item_id)
            };
        });

        summary = data.summary;
        summaryCents = decimalToCents(summary.total);
        if (
            nonNegativeInteger(summary.item_count) === null
            || nonNegativeInteger(summary.valid_item_count) === null
            || nonNegativeInteger(summary.invalid_item_count) === null
            || summaryCents === null
            || summary.item_count !== items.length
            || summary.valid_item_count + summary.invalid_item_count !== summary.item_count
        ) {
            throw { code: 'invalid_response', message: 'El resumen validado está incompleto.' };
        }

        normalized = normalizedGroups(items, true);
        if (!normalized.valid || normalized.totalCents !== summaryCents) {
            throw { code: 'invalid_response', message: 'Los importes validados no son consistentes.' };
        }
        if (
            data.valid !== (
                summary.item_count > 0
                && summary.invalid_item_count === 0
            )
            || (data.valid && errors.length !== 0)
        ) {
            throw { code: 'invalid_response', message: 'El estado de validación no es consistente.' };
        }

        return {
            valid: data.valid,
            errors: errors,
            items: items,
            groups: normalized.groups,
            totalCents: summaryCents
        };
    }

    function normalizedCheckout(payload) {
        var data = payload && payload.success === true ? payload.data : null;
        var totalCents;
        if (!data || typeof data.valid !== 'boolean') {
            throw { code: 'invalid_response', message: 'El servidor entregó una respuesta de checkout incompleta.' };
        }
        if (!data.valid) {
            return { valid: false, data: data };
        }
        if (data.order_created !== true || data.reservation_created !== true || !Array.isArray(data.orders) || data.orders.length === 0 || !data.summary) {
            throw { code: 'invalid_response', message: 'El servidor no confirmó la creación completa del pedido.' };
        }
        totalCents = decimalToCents(data.summary.total);
        if (totalCents === null) {
            throw { code: 'invalid_response', message: 'El total creado está incompleto.' };
        }
        data.orders.forEach(function (order) {
            if (!order || positiveInteger(order.id) === null || typeof order.status !== 'string') {
                throw { code: 'invalid_response', message: 'El servidor entregó pedidos incompletos.' };
            }
        });
        return { valid: true, data: data, totalCents: totalCents };
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
        var validationErrors = root.querySelector('[data-va-checkout-validation-errors]');
        var validationErrorList = root.querySelector('[data-va-checkout-validation-error-list]');
        var checkoutResult = root.querySelector('[data-va-checkout-result]');
        var checkoutResultDetails = root.querySelector('[data-va-checkout-result-details]');
        var minimum = config.checkout && config.checkout.minimumDeliveryAmount;
        var minimumUnits = typeof minimum === 'number' && Number.isSafeInteger(minimum) && minimum >= 0
            ? minimum
            : 8000;
        var minimumCents = minimumUnits * 100;
        var calculated = null;
        var visibleItems = [];
        var deliveryEligible = false;
        var submitted = false;
        var validated = false;
        var validating = false;
        var creating = false;
        var created = false;
        var checkoutIdempotencyKey = 'checkout:' + String(Date.now()) + ':'
            + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        var ambiguousAttempt = false;
        var requestSequence = 0;
        var activeController = null;
        var defaultSubmitText = 'Crear pedido';
        var authenticated = !!(config.currentUser && config.currentUser.loggedIn);

        function showError(message) {
            loading.hidden = true;
            empty.hidden = true;
            content.hidden = true;
            error.hidden = false;
            errorMessage.textContent = message;
            validated = false;
            submit.textContent = defaultSubmitText;
            submit.disabled = true;
        }

        function clearValidationMessages() {
            validationErrors.hidden = true;
            validationErrorList.replaceChildren();
            status.hidden = true;
            status.textContent = '';
        }

        function invalidateValidation() {
            if (created || ambiguousAttempt) {
                return;
            }
            if (validating) {
                requestSequence += 1;
                if (activeController) {
                    activeController.abort();
                }
                activeController = null;
                validating = false;
                submit.removeAttribute('aria-busy');
                root.removeAttribute('aria-busy');
            }
            validated = false;
            ambiguousAttempt = false;
            submit.textContent = defaultSubmitText;
            clearValidationMessages();
        }

        function showValidationErrors(errors) {
            validationErrorList.replaceChildren();
            errors.forEach(function (problem) {
                validationErrorList.append(element('li', '', problem.message));
            });
            validationErrors.hidden = false;
            status.hidden = false;
            status.textContent = 'La información del carrito cambió. Revisa los problemas indicados.';
            validationErrors.focus();
        }

        function communicationMessage(error) {
            if (error && error.code === 'timeout') {
                return 'La validación tardó demasiado. Inténtalo nuevamente.';
            }
            if (error && error.code === 'invalid_response') {
                return error.message;
            }
            if (error && error.status >= 500) {
                return 'No fue posible validar la compra por un error interno. Inténtalo nuevamente.';
            }
            if (error && [400, 404, 409, 422].indexOf(error.status) !== -1) {
                return error.message || 'No fue posible validar la compra. Revisa los datos e inténtalo nuevamente.';
            }
            return error && typeof error.message === 'string' && error.message.trim() !== ''
                ? error.message
                : 'No fue posible validar la compra. Comprueba tu conexión e inténtalo nuevamente.';
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

        function renderDelivery(summary, preferredMethod) {
            deliveryEligible = summary.totalCents >= minimumCents;
            optionsRoot.replaceChildren(deliveryOption(
                'pickup',
                'Retiro en minimarket',
                preferredMethod !== 'delivery' || !deliveryEligible
            ));
            if (deliveryEligible) {
                optionsRoot.append(deliveryOption(
                    'delivery',
                    'Despacho',
                    preferredMethod === 'delivery'
                ));
                minimumMessage.hidden = true;
            } else {
                minimumMessage.textContent = 'El despacho está disponible para compras desde ' + moneyFromCents(minimumCents) + '.';
                minimumMessage.hidden = false;
            }
            updateDeliveryFields();
        }

        function monetarySignature(items) {
            return items.map(function (item) {
                return [
                    positiveInteger(item && item.id),
                    positiveInteger(item && item.quantity),
                    decimalToCents(item && item.unit_price_snapshot),
                    decimalToCents(item && item.subtotal)
                ].join(':');
            }).sort().join('|');
        }

        function applyValidation(result) {
            var previousTotal = calculated ? calculated.totalCents : null;
            var previousSignature = monetarySignature(visibleItems);
            var previousMethod = selectedMethod();
            var changed = previousTotal !== result.totalCents
                || previousSignature !== monetarySignature(result.items);
            var forcedPickup = previousMethod === 'delivery'
                && result.totalCents < minimumCents;

            calculated = {
                groups: result.groups,
                totalCents: result.totalCents,
                valid: true
            };
            visibleItems = result.items;
            renderGroups(calculated);
            renderDelivery(calculated, forcedPickup ? 'pickup' : previousMethod);
            clearValidationMessages();

            if (changed || forcedPickup) {
                status.hidden = false;
                status.textContent = forcedPickup
                    ? 'El total validado es inferior al mínimo requerido para despacho. El método de entrega cambió automáticamente a retiro.'
                    : 'El carrito cambió mientras preparabas tu compra. Se actualizaron los valores antes de continuar.';
            }

            if (result.valid) {
                validated = true;
                submit.textContent = 'Crear pedido';
                status.hidden = false;
                status.textContent = (status.textContent ? status.textContent + ' ' : '')
                    + 'Compra validada correctamente.';
                if (!authenticated) {
                    status.textContent += ' Debes iniciar sesión para crear el pedido.';
                }
            } else {
                validated = false;
                submit.textContent = defaultSubmitText;
                showValidationErrors(result.errors.length > 0 ? result.errors : [{ message: 'La compra contiene datos no válidos.' }]);
            }
            validateForm(false);
        }

        function renderCheckoutResult(result) {
            var data = result.data;
            checkoutResultDetails.replaceChildren();
            data.orders.forEach(function (order, index) {
                checkoutResultDetails.append(element('p', '', 'Pedido ' + String(index + 1) + ' — Estado: ' + order.status));
            });
            checkoutResultDetails.append(element('p', '', 'Total: ' + moneyFromCents(result.totalCents)));
            if (typeof data.expires_at === 'string' && data.expires_at.trim() !== '') {
                checkoutResultDetails.append(element('p', '', 'Reserva vigente hasta: ' + data.expires_at));
            }
            checkoutResult.hidden = false;
            checkoutResult.focus();
            created = true;
            validated = false;
            ambiguousAttempt = false;
            submit.textContent = 'Continuar al pago';
            submit.disabled = true;
            status.hidden = false;
            status.textContent = 'Pedido creado correctamente.';
        }

        function checkoutErrors(data) {
            var errors = data && Array.isArray(data.errors) ? data.errors : [];
            return errors.length ? errors.map(function (problem) {
                return { message: problem && typeof problem.message === 'string' ? problem.message : 'La compra ya no es válida.' };
            }) : [{ message: 'La compra ya no es válida. Debes validarla nuevamente.' }];
        }

        function createCheckout() {
            var controller;
            var timer;
            var timedOut = false;
            if (!validated || creating || created || ambiguousAttempt) {
                return Promise.resolve(null);
            }
            if (!authenticated) {
                status.hidden = false;
                status.textContent = 'Debes iniciar sesión para crear el pedido.';
                submit.disabled = true;
                return Promise.resolve(null);
            }
            if (calculated.totalCents < minimumCents && selectedMethod() !== 'pickup') {
                invalidateValidation();
                renderDelivery(calculated, 'pickup');
                showValidationErrors([{ message: 'El total validado requiere retiro. Valida nuevamente la compra.' }]);
                return Promise.resolve(null);
            }
            creating = true;
            controller = new AbortController();
            submit.disabled = true;
            submit.textContent = ambiguousAttempt ? 'Reintentando creación…' : 'Creando pedido…';
            submit.setAttribute('aria-busy', 'true');
            root.setAttribute('aria-busy', 'true');
            status.hidden = false;
            status.textContent = 'Creando pedido…';
            timer = window.setTimeout(function () { timedOut = true; controller.abort(); }, REQUEST_TIMEOUT);

            var creationOptions = requestOptions(controller.signal);
            creationOptions.headers = Object.assign({}, creationOptions.headers || {}, {
                'Idempotency-Key': checkoutIdempotencyKey
            });
            return config.api.post('/checkout', {
                fulfillment_method: selectedMethod()
            }, creationOptions).then(function (payload) {
                var result = normalizedCheckout(payload);
                if (!result.valid) {
                    validated = false;
                    ambiguousAttempt = false;
                    showValidationErrors(checkoutErrors(result.data));
                    submit.textContent = defaultSubmitText;
                    return null;
                }
                renderCheckoutResult(result);
                return payload;
            }).catch(function (requestError) {
                ambiguousAttempt = timedOut || !requestError
                    || [400, 401, 403, 409, 422].indexOf(requestError.status) === -1
                    || requestError.code === 'invalid_response';
                showValidationErrors([{
                    message: ambiguousAttempt
                        ? 'No fue posible confirmar el resultado de la creación. La solicitud pudo haberse procesado. Recarga la página y revisa tus pedidos antes de volver a intentarlo.'
                        : communicationMessage(requestError)
                }]);
                if (!ambiguousAttempt) {
                    validated = false;
                }
                submit.textContent = ambiguousAttempt ? 'Resultado pendiente' : defaultSubmitText;
                return null;
            }).finally(function () {
                window.clearTimeout(timer);
                creating = false;
                submit.removeAttribute('aria-busy');
                root.removeAttribute('aria-busy');
                if (!created) {
                    validateForm(false);
                    if (ambiguousAttempt) {
                        submit.textContent = 'Resultado pendiente';
                        submit.disabled = true;
                    }
                }
            });
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
            submit.disabled = submitted || validating || creating || created
                || ambiguousAttempt || (validated && !authenticated) || !valid;
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
            visibleItems = items.slice();
            renderGroups(calculated);
            renderDelivery(calculated, 'pickup');
            empty.hidden = true;
            content.hidden = false;
            if (!calculated.valid) {
                status.hidden = false;
                status.textContent = 'Algunos importes no son válidos. Revisa el carrito antes de continuar.';
            }
            validateForm(false);
        }

        function load() {
            invalidateValidation();
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
                invalidateValidation();
                validateForm(false);
            }
        });
        form.addEventListener('change', function (event) {
            if (event.target.name === 'delivery_method') {
                invalidateValidation();
                updateDeliveryFields();
            } else if (event.target.matches('[data-va-field]')) {
                invalidateValidation();
                validateForm(false);
            }
        });

        function validatePurchase() {
            var firstInvalid;
            var requestId;
            var timer;
            var timedOut = false;

            if (validating) {
                return Promise.resolve(null);
            }
            if (validated) {
                return createCheckout();
            }
            if (!validateForm(true)) {
                firstInvalid = form.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                return Promise.resolve(null);
            }

            clearValidationMessages();
            validating = true;
            requestId = ++requestSequence;
            activeController = new AbortController();
            submit.disabled = true;
            submit.textContent = 'Validando…';
            submit.setAttribute('aria-busy', 'true');
            root.setAttribute('aria-busy', 'true');
            status.hidden = false;
            status.textContent = 'Validando compra…';
            timer = window.setTimeout(function () {
                timedOut = true;
                activeController.abort();
            }, REQUEST_TIMEOUT);

            return config.api.post(
                '/checkout/validate',
                { fulfillment_method: selectedMethod() },
                requestOptions(activeController.signal)
            ).then(function (payload) {
                if (requestId !== requestSequence) {
                    return null;
                }
                applyValidation(normalizedValidation(payload, visibleItems));
                return payload;
            }).catch(function (requestError) {
                if (requestId !== requestSequence) {
                    return null;
                }
                validated = false;
                submit.textContent = defaultSubmitText;
                showValidationErrors([{
                    message: timedOut
                        ? 'La validación tardó demasiado. Inténtalo nuevamente.'
                        : communicationMessage(requestError)
                }]);
                return null;
            }).finally(function () {
                window.clearTimeout(timer);
                if (requestId === requestSequence) {
                    validating = false;
                    activeController = null;
                    submit.removeAttribute('aria-busy');
                    root.removeAttribute('aria-busy');
                    submit.textContent = validated ? 'Crear pedido' : defaultSubmitText;
                    validateForm(false);
                }
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            validatePurchase();
        });
        retry.addEventListener('click', load);

        root.vaCheckout = {
            load: load,
            getSummary: function () { return calculated; },
            validate: function () { return validateForm(true); },
            validatePurchase: validatePurchase
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
        normalizedValidation: normalizedValidation,
        normalizedCheckout: normalizedCheckout,
        initialize: initialize
    };
    window.VeciAhorra = config;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}(window, document));
