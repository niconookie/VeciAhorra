(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};
    var REQUEST_TIMEOUT = 12000;

    function money(value) {
        var numeric = Number(value);

        if (!Number.isFinite(numeric)) {
            return '—';
        }

        return new Intl.NumberFormat(config.locale || 'es-CL', {
            style: 'currency',
            currency: config.currency || 'CLP',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(numeric);
    }

    function textNode(tag, className, value) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }
        element.textContent = value;
        return element;
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

    function apiRequest(method, path, payload) {
        var controller = new AbortController();
        var timedOut = false;
        var timer = window.setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, REQUEST_TIMEOUT);
        var options = requestOptions(controller.signal);
        var promise = method === 'get'
            ? config.api.get(path, options)
            : method === 'delete'
                ? config.api.delete(path, options)
                : config.api.patch(path, payload, options);

        return promise.catch(function (error) {
            if (timedOut) {
                throw {
                    status: 0,
                    code: 'timeout',
                    message: 'La solicitud tardó demasiado. Intenta nuevamente.',
                    data: null
                };
            }
            throw error;
        }).finally(function () {
            window.clearTimeout(timer);
        });
    }

    function errorMessage(error) {
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message;
        }

        if (error && error.code === 'timeout') {
            return 'La solicitud tardó demasiado. Intenta nuevamente.';
        }

        return 'No fue posible conectar con el servidor. Revisa tu conexión e intenta nuevamente.';
    }

    function mount(root) {
        var loading = root.querySelector('[data-va-cart-loading]');
        var empty = root.querySelector('[data-va-cart-empty]');
        var error = root.querySelector('[data-va-cart-error]');
        var errorText = root.querySelector('[data-va-cart-error-message]');
        var retry = root.querySelector('[data-va-cart-retry]');
        var content = root.querySelector('[data-va-cart-content]');
        var itemsBody = root.querySelector('[data-va-cart-items]');
        var total = root.querySelector('[data-va-cart-total]');
        var clear = root.querySelector('[data-va-cart-clear]');
        var status = root.querySelector('[data-va-cart-status]');
        var busy = false;

        function announce(message) {
            status.textContent = '';
            window.setTimeout(function () { status.textContent = message; }, 0);
        }

        function setLoading(active) {
            loading.hidden = !active;
            root.setAttribute('aria-busy', active ? 'true' : 'false');
        }

        function setBusy(active) {
            busy = active;
            root.querySelectorAll('button').forEach(function (button) {
                button.disabled = active
                    || button.hasAttribute('data-va-default-disabled');
            });
            root.setAttribute('aria-busy', active ? 'true' : 'false');
        }

        function showError(requestError) {
            setLoading(false);
            content.hidden = true;
            empty.hidden = true;
            clear.hidden = true;
            error.hidden = false;
            errorText.textContent = errorMessage(requestError);
            retry.disabled = false;
        }

        function showMutationError(requestError) {
            setLoading(false);
            error.hidden = false;
            errorText.textContent = errorMessage(requestError);
            retry.disabled = false;
        }

        function quantityControl(item) {
            var control = document.createElement('div');
            var decrease = textNode('button', 'va-cart-quantity__button', '−');
            var value = textNode('span', 'va-cart-quantity__value', String(item.quantity));
            var increase = textNode('button', 'va-cart-quantity__button', '+');
            var label = item.product_name || 'producto';

            control.className = 'va-cart-quantity';
            decrease.type = 'button';
            increase.type = 'button';
            decrease.setAttribute('aria-label', 'Disminuir cantidad de ' + label);
            increase.setAttribute('aria-label', 'Aumentar cantidad de ' + label);
            decrease.disabled = Number(item.quantity) <= 1;
            if (decrease.disabled) {
                decrease.setAttribute('data-va-default-disabled', 'true');
            }
            decrease.addEventListener('click', function () {
                updateQuantity(item, Number(item.quantity) - 1);
            });
            increase.addEventListener('click', function () {
                updateQuantity(item, Number(item.quantity) + 1);
            });
            control.append(decrease, value, increase);
            return control;
        }

        function productCell(item) {
            var wrapper = document.createElement('div');
            var image;
            var name = item.product_name || 'Producto no disponible';

            wrapper.className = 'va-cart-product';
            if (item.product_image_url) {
                image = document.createElement('img');
                image.src = item.product_image_url;
                image.alt = '';
                image.loading = 'lazy';
            } else {
                image = textNode('span', 'va-cart-product__placeholder', '');
                image.setAttribute('aria-hidden', 'true');
            }
            wrapper.append(image, textNode('strong', '', name));
            return wrapper;
        }

        function labeledCell(label, child) {
            var cell = document.createElement('td');

            cell.setAttribute('data-label', label);
            if (typeof child === 'string') {
                cell.textContent = child;
            } else {
                cell.append(child);
            }
            return cell;
        }

        function renderItem(item) {
            var row = document.createElement('tr');
            var remove = textNode('button', 'va-button va-button--danger', 'Eliminar');

            row.setAttribute('data-cart-item-id', String(item.id));
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Eliminar ' + (item.product_name || 'producto') + ' del carrito');
            remove.addEventListener('click', function () { removeItem(item); });
            row.append(
                labeledCell('Producto', productCell(item)),
                labeledCell('Minimarket', item.minimarket_name || 'Minimarket no disponible'),
                labeledCell('Precio unitario', money(item.unit_price_snapshot)),
                labeledCell('Cantidad', quantityControl(item)),
                labeledCell('Subtotal', money(item.subtotal)),
                labeledCell('Acciones', remove)
            );
            return row;
        }

        function render(payload) {
            var items = payload && Array.isArray(payload.data) ? payload.data : null;

            if (items === null || typeof payload.total === 'undefined') {
                showError({ message: 'El servidor devolvió un carrito no válido.' });
                return;
            }

            setLoading(false);
            error.hidden = true;
            itemsBody.replaceChildren();
            if (items.length === 0) {
                empty.hidden = false;
                content.hidden = true;
                clear.hidden = true;
                return;
            }

            items.forEach(function (item) { itemsBody.append(renderItem(item)); });
            total.textContent = money(payload.total);
            empty.hidden = true;
            content.hidden = false;
            clear.hidden = false;
        }

        function load() {
            if (busy) {
                return Promise.resolve(null);
            }
            error.hidden = true;
            empty.hidden = true;
            content.hidden = true;
            clear.hidden = true;
            setLoading(true);
            return apiRequest('get', '/cart').then(render).catch(showError);
        }

        function mutate(method, path, payload, message) {
            if (busy) {
                return Promise.resolve(null);
            }
            setBusy(true);
            return apiRequest(method, path, payload)
                .then(function () { return apiRequest('get', '/cart'); })
                .then(function (response) {
                    render(response);
                    announce(message);
                })
                .catch(function (requestError) {
                    showMutationError(requestError);
                    announce(errorMessage(requestError));
                })
                .finally(function () { setBusy(false); });
        }

        function updateQuantity(item, quantity) {
            if (!Number.isInteger(quantity) || quantity <= 0) {
                return Promise.resolve(null);
            }
            return mutate(
                'patch',
                '/cart/items/' + encodeURIComponent(item.id),
                { quantity: quantity },
                'Cantidad actualizada.'
            );
        }

        function removeItem(item) {
            return mutate(
                'delete',
                '/cart/items/' + encodeURIComponent(item.id),
                null,
                'Producto eliminado del carrito.'
            );
        }

        function clearCart() {
            return mutate('delete', '/cart', null, 'Carrito vaciado.');
        }

        retry.addEventListener('click', load);
        clear.addEventListener('click', clearCart);
        root.vaCart = {
            load: load,
            updateQuantity: updateQuantity,
            removeItem: removeItem,
            clear: clearCart
        };
        load();
    }

    function initialize() {
        document.querySelectorAll('[data-va-cart]').forEach(function (root) {
            if (!root.vaCart) {
                mount(root);
            }
        });
    }

    config.publicCart = { initialize: initialize };
    window.VeciAhorra = config;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}(window, document));
