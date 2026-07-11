(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};

    function isPositiveInteger(value) {
        return Number.isInteger(value) && value > 0;
    }

    function normalizeOffer(productId, offer) {
        var inventoryId;
        var minimarketId;
        var price;
        var stock;

        if (!isPositiveInteger(productId) || !offer || typeof offer !== 'object') {
            return null;
        }

        inventoryId = Number(offer.inventory_id);
        minimarketId = Number(offer.minimarket_id);
        price = Number(offer.price);
        stock = Number(offer.stock);

        if (
            !isPositiveInteger(inventoryId)
            || !isPositiveInteger(minimarketId)
            || !Number.isFinite(price)
            || price <= 0
            || !isPositiveInteger(stock)
        ) {
            return null;
        }

        return {
            product_id: productId,
            inventory_id: inventoryId,
            minimarket_id: minimarketId,
            minimarket: typeof offer.minimarket === 'string' ? offer.minimarket.trim() : '',
            unit_price: price,
            available_stock: stock
        };
    }

    function createSelectionStore() {
        var state = {
            productId: null,
            offers: [],
            invalidOffers: [],
            selectedInventoryId: null,
            selection: null,
            error: null
        };
        var listeners = [];

        function snapshot() {
            return {
                productId: state.productId,
                offers: state.offers.slice(),
                invalidOffers: state.invalidOffers.slice(),
                selectedInventoryId: state.selectedInventoryId,
                selection: state.selection ? Object.assign({}, state.selection) : null,
                error: state.error ? Object.assign({}, state.error) : null
            };
        }

        function notify() {
            var current = snapshot();
            listeners.forEach(function (listener) { listener(current); });
        }

        function setProduct(product) {
            var productId = Number(product && product.id);
            var rawOffers = product && Array.isArray(product.offers) ? product.offers : [];
            var validOffers = [];
            var invalidOffers = [];
            var previousId = state.selectedInventoryId;

            rawOffers.forEach(function (offer) {
                var normalized = normalizeOffer(productId, offer);

                if (normalized) {
                    validOffers.push(normalized);
                } else {
                    invalidOffers.push(offer);
                }
            });

            state.productId = isPositiveInteger(productId) ? productId : null;
            state.offers = validOffers;
            state.invalidOffers = invalidOffers;
            state.error = null;
            state.selection = previousId === null
                ? null
                : validOffers.find(function (offer) {
                    return offer.inventory_id === previousId;
                }) || null;
            state.selectedInventoryId = state.selection
                ? state.selection.inventory_id
                : null;

            if (previousId !== null && state.selection === null) {
                state.error = {
                    code: 'offer_unavailable',
                    message: 'La oferta seleccionada ya no está disponible.'
                };
            }

            notify();
            return snapshot();
        }

        function select(inventoryId) {
            var normalizedId = Number(inventoryId);
            var offer = state.offers.find(function (candidate) {
                return candidate.inventory_id === normalizedId;
            }) || null;

            if (!offer) {
                state.selectedInventoryId = null;
                state.selection = null;
                state.error = {
                    code: 'invalid_offer',
                    message: 'No fue posible seleccionar esta oferta.'
                };
                notify();
                return snapshot();
            }

            if (state.selectedInventoryId === offer.inventory_id) {
                return snapshot();
            }

            state.selectedInventoryId = offer.inventory_id;
            state.selection = offer;
            state.error = null;
            notify();

            return snapshot();
        }

        function subscribe(listener) {
            if (typeof listener !== 'function') {
                return function () {};
            }

            listeners.push(listener);

            return function () {
                listeners = listeners.filter(function (item) { return item !== listener; });
            };
        }

        function cartPayload() {
            return state.selection
                ? { inventory_id: state.selection.inventory_id, quantity: 1 }
                : null;
        }

        return {
            getState: snapshot,
            setProduct: setProduct,
            select: select,
            subscribe: subscribe,
            getCartPayload: cartPayload
        };
    }

    function money(value) {
        try {
            return new Intl.NumberFormat(config.locale || 'es-CL', {
                style: 'currency',
                currency: config.currency || 'CLP',
                maximumFractionDigits: 2
            }).format(value);
        } catch (error) {
            return String(value);
        }
    }

    function text(element, value) {
        if (element) {
            element.textContent = value === null || value === undefined ? '' : String(value);
        }
    }

    function createOfferButton(offer, selected) {
        var button = document.createElement('button');
        var store = document.createElement('strong');
        var price = document.createElement('span');
        var stock = document.createElement('span');

        button.type = 'button';
        button.className = 'va-offer-card' + (selected ? ' va-offer-card--selected' : '');
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', selected ? 'true' : 'false');
        button.setAttribute('data-inventory-id', String(offer.inventory_id));
        button.tabIndex = selected ? 0 : -1;
        store.className = 'va-offer-card__store';
        price.className = 'va-offer-card__price';
        stock.className = 'va-offer-card__stock';
        text(store, offer.minimarket || 'Minimarket');
        text(price, money(offer.unit_price));
        text(stock, 'Stock disponible: ' + offer.available_stock);
        button.append(store, price, stock);

        return button;
    }

    function createUnavailableOffer() {
        var card = document.createElement('div');

        card.className = 'va-offer-card va-offer-card--unavailable';
        card.setAttribute('aria-disabled', 'true');
        card.textContent = 'Oferta no disponible';

        return card;
    }

    function mount(root) {
        var productId = Number(root.getAttribute('data-product-id'));
        var store = createSelectionStore();
        var list = root.querySelector('[data-va-offer-list]');
        var loading = root.querySelector('[data-va-product-loading]');
        var error = root.querySelector('[data-va-product-error]');
        var section = root.querySelector('[data-va-offer-section]');
        var empty = root.querySelector('[data-va-offers-empty]');
        var summary = root.querySelector('[data-va-selection-summary]');
        var values = root.querySelector('[data-va-selection-values]');
        var status = root.querySelector('[data-va-selection-status]');
        var addButton = root.querySelector('[data-va-add-to-cart]');
        var addLabel = root.querySelector('[data-va-add-label]');
        var addLoading = root.querySelector('[data-va-add-loading]');
        var cartSuccess = root.querySelector('[data-va-cart-success]');
        var viewCart = root.querySelector('[data-va-view-cart]');
        var cartError = root.querySelector('[data-va-cart-error]');
        var currentProduct = null;
        var isAddingToCart = false;
        var renderedSelectionId = null;

        function clearCartMessages() {
            cartSuccess.hidden = true;
            cartError.hidden = true;
            text(cartSuccess, '');
            text(cartError, '');
        }

        function updateCartControls(state) {
            var selectedId = state.selectedInventoryId;
            var selectedExists = isPositiveInteger(selectedId)
                && state.offers.some(function (offer) {
                    return offer.inventory_id === selectedId;
                });

            addButton.disabled = !selectedExists || isAddingToCart;
            addButton.setAttribute('aria-busy', isAddingToCart ? 'true' : 'false');
            addLabel.hidden = isAddingToCart;
            addLoading.hidden = !isAddingToCart;

            if (renderedSelectionId !== selectedId) {
                clearCartMessages();
                renderedSelectionId = selectedId;
            }
        }

        function render(state) {
            var buttons;

            list.replaceChildren();
            state.offers.forEach(function (offer, index) {
                var selected = offer.inventory_id === state.selectedInventoryId;
                var button = createOfferButton(offer, selected);

                if (state.selectedInventoryId === null && index === 0) {
                    button.tabIndex = 0;
                }

                list.append(button);
            });
            state.invalidOffers.forEach(function () {
                list.append(createUnavailableOffer());
            });
            empty.hidden = state.offers.length !== 0 || state.invalidOffers.length !== 0;
            summary.hidden = false;
            values.hidden = state.selection === null;

            if (state.selection) {
                text(status, 'Oferta seleccionada.');
                text(root.querySelector('[data-va-selected-store]'), state.selection.minimarket || 'Minimarket');
                text(root.querySelector('[data-va-selected-price]'), money(state.selection.unit_price));
                text(root.querySelector('[data-va-selected-stock]'), state.selection.available_stock);
            } else if (state.error) {
                text(status, state.error.message);
            } else {
                text(status, 'Aún no has seleccionado una oferta.');
            }

            error.hidden = state.error === null;
            text(error, state.error ? state.error.message : '');
            buttons = list.querySelectorAll('[role="radio"]');

            if (buttons.length === 0) {
                list.setAttribute('aria-disabled', 'true');
            } else {
                list.removeAttribute('aria-disabled');
            }

            updateCartControls(state);
        }

        function selectButton(button, focus) {
            var inventoryId = Number(button.getAttribute('data-inventory-id'));
            var state = store.select(inventoryId);
            var selected;

            if (focus && state.selectedInventoryId !== null) {
                selected = list.querySelector('[data-inventory-id="' + state.selectedInventoryId + '"]');

                if (selected) {
                    selected.focus();
                }
            }
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest('[role="radio"]');

            if (button && list.contains(button)) {
                selectButton(button, false);
            }
        });

        list.addEventListener('keydown', function (event) {
            var buttons = Array.from(list.querySelectorAll('[role="radio"]'));
            var current = event.target.closest('[role="radio"]');
            var index = buttons.indexOf(current);
            var nextIndex = index;

            if (index < 0) {
                return;
            }

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                nextIndex = (index + 1) % buttons.length;
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                nextIndex = (index - 1 + buttons.length) % buttons.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = buttons.length - 1;
            } else if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                selectButton(current, true);
                return;
            } else {
                return;
            }

            event.preventDefault();
            selectButton(buttons[nextIndex], true);
        });

        store.subscribe(render);

        function cartRequestOptions() {
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

            return { headers: headers };
        }

        function publicCartError(requestError) {
            if (
                requestError
                && typeof requestError.message === 'string'
                && requestError.message.trim() !== ''
            ) {
                return requestError.message;
            }

            return 'No fue posible agregar el producto al carrito. Intenta nuevamente.';
        }

        function addToCart() {
            var state = store.getState();
            var selectedId = state.selectedInventoryId;
            var selectedExists = isPositiveInteger(selectedId)
                && state.offers.some(function (offer) {
                    return offer.inventory_id === selectedId;
                });
            var payload;

            if (isAddingToCart || !selectedExists) {
                return Promise.resolve(null);
            }

            payload = store.getCartPayload();

            if (
                !payload
                || !isPositiveInteger(payload.inventory_id)
                || payload.inventory_id !== selectedId
                || payload.quantity !== 1
                || Object.keys(payload).length !== 2
            ) {
                cartError.hidden = false;
                text(cartError, 'No fue posible seleccionar una oferta válida.');
                return Promise.resolve(null);
            }

            isAddingToCart = true;
            clearCartMessages();
            updateCartControls(state);

            return config.api.post('/cart/items', {
                inventory_id: selectedId,
                quantity: 1
            }, cartRequestOptions())
                .then(function (response) {
                    if (!response || response.success !== true || !response.data) {
                        throw {
                            status: 0,
                            code: 'invalid_cart_response',
                            message: 'Invalid cart response.',
                            data: null
                        };
                    }

                    cartSuccess.hidden = false;
                    text(cartSuccess, 'Producto agregado al carrito.');
                    viewCart.hidden = false;

                    return response;
                })
                .catch(function (requestError) {
                    cartError.hidden = false;
                    text(cartError, publicCartError(requestError));

                    return null;
                })
                .finally(function () {
                    isAddingToCart = false;
                    updateCartControls(store.getState());
                });
        }

        addButton.addEventListener('click', addToCart);

        function reload() {
            loading.hidden = false;
            error.hidden = true;

            return config.api.get('/catalog/products/' + productId)
                .then(function (payload) {
                    currentProduct = payload && payload.data ? payload.data : null;

                    if (!currentProduct || Number(currentProduct.id) !== productId) {
                        throw { status: 0, code: 'invalid_product', message: 'El producto no es válido.', data: null };
                    }

                    text(root.querySelector('[data-va-product-name]'), currentProduct.name);
                    text(root.querySelector('[data-va-product-description]'), currentProduct.short_description || currentProduct.description || '');
                    section.hidden = false;
                    store.setProduct(currentProduct);
                })
                .catch(function (requestError) {
                    error.hidden = false;
                    text(error, requestError && requestError.message ? requestError.message : 'No fue posible cargar el producto.');
                })
                .finally(function () {
                    loading.hidden = true;
                });
        }

        root.vaOfferSelector = {
            reload: reload,
            getState: store.getState,
            select: store.select,
            getCartPayload: store.getCartPayload,
            addToCart: addToCart,
            isAddingToCart: function () { return isAddingToCart; }
        };
        reload();
    }

    function initialize() {
        document.querySelectorAll('[data-va-product-detail]').forEach(function (root) {
            if (!root.vaOfferSelector) {
                mount(root);
            }
        });
    }

    config.offerSelection = {
        normalizeOffer: normalizeOffer,
        createStore: createSelectionStore,
        initialize: initialize
    };
    window.VeciAhorra = config;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}(window, document));
