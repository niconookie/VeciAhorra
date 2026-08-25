(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};

    function isPositiveInteger(value) {
        return Number.isInteger(value) && value > 0;
    }

    function normalizeOffer(productId, offer) {
        var offerToken;
        var price;

        if (!isPositiveInteger(productId) || !offer || typeof offer !== 'object') {
            return null;
        }

        offerToken = typeof offer.offer_token === 'string' ? offer.offer_token : '';
        price = Number(offer.price);

        if (
            !/^[A-Za-z0-9_-]{40,512}$/.test(offerToken)
            || !Number.isFinite(price)
            || price <= 0
            || offer.availability !== 'available'
        ) {
            return null;
        }

        return {
            product_id: productId,
            offer_token: offerToken,
            unit_price: price,
            availability: 'Disponible'
        };
    }

    function createSelectionStore() {
        var state = {
            productId: null,
            offers: [],
            invalidOffers: [],
            selectedInventoryId: null,
            selection: null,
            error: null,
            selectionLocked: false
        };
        var listeners = [];

        function snapshot() {
            return {
                productId: state.productId,
                offers: state.offers.slice(),
                invalidOffers: state.invalidOffers.slice(),
                selectedInventoryId: state.selectedInventoryId,
                selection: state.selection ? Object.assign({}, state.selection) : null,
                error: state.error ? Object.assign({}, state.error) : null,
                selectionLocked: state.selectionLocked
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
                    normalized.offer_label = 'Opción ' + String.fromCharCode(65 + validOffers.length);
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
                    return offer.offer_token === previousId;
                }) || null;
            state.selectedInventoryId = state.selection
                ? state.selection.offer_token
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

        function select(offerToken) {
            var normalizedId = String(offerToken || '');
            var offer;

            if (state.selectionLocked) {
                return snapshot();
            }

            offer = state.offers.find(function (candidate) {
                return candidate.offer_token === normalizedId;
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

            if (state.selectedInventoryId === offer.offer_token) {
                return snapshot();
            }

            state.selectedInventoryId = offer.offer_token;
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
                ? { offer_token: state.selection.offer_token, quantity: 1 }
                : null;
        }

        function setSelectionLocked(locked) {
            var normalized = locked === true;

            if (state.selectionLocked === normalized) {
                return snapshot();
            }

            state.selectionLocked = normalized;
            notify();
            return snapshot();
        }

        return {
            getState: snapshot,
            setProduct: setProduct,
            select: select,
            subscribe: subscribe,
            getCartPayload: cartPayload,
            setSelectionLocked: setSelectionLocked
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

    function createOfferButton(offer, selected, locked) {
        var button = document.createElement('button');
        var store = document.createElement('strong');
        var price = document.createElement('span');
        var stock = document.createElement('span');
        var choice = document.createElement('span');

        button.type = 'button';
        button.className = 'va-card va-offer-card' + (selected ? ' va-offer-card--selected' : '');
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', selected ? 'true' : 'false');
        button.setAttribute('data-offer-token', offer.offer_token);
        button.disabled = locked;
        button.setAttribute('aria-disabled', locked ? 'true' : 'false');
        button.tabIndex = selected ? 0 : -1;
        store.className = 'va-offer-card__store';
        price.className = 'va-offer-card__price';
        stock.className = 'va-offer-card__stock';
        choice.className = 'va-offer-card__choice';
        text(store, offer.offer_label);
        text(price, money(offer.unit_price));
        text(stock, offer.availability);
        text(choice, selected ? '● Seleccionado' : '○ Seleccionar');
        button.append(store, price, stock, choice);

        return button;
    }

    function createUnavailableOffer() {
        var card = document.createElement('div');

        card.className = 'va-card va-offer-card va-offer-card--unavailable';
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
        var continueShopping = root.querySelector('[data-va-continue-shopping]');
        var cartError = root.querySelector('[data-va-cart-error]');
        var productImage = root.querySelector('[data-va-product-image]');
        var productImageMissing = root.querySelector('[data-va-product-image-missing]');
        var currentProduct = null;
        var isAddingToCart = false;
        var renderedSelectionId = null;
        var reloadSequence = 0;
        var displayMode = 'price';

        function renderProductImage(product) {
            var rawUrl = product && typeof product.image === 'string' ? product.image.trim() : '';
            var parsed;
            var valid = false;

            if (rawUrl !== '') {
                try {
                    parsed = new URL(rawUrl, window.location.href);
                    valid = (parsed.protocol === 'http:' || parsed.protocol === 'https:')
                        && parsed.username === ''
                        && parsed.password === '';
                } catch (ignore) {}
            }

            if (productImage) {
                productImage.hidden = !valid;
                if (valid) {
                    productImage.src = parsed.href;
                } else {
                    productImage.removeAttribute('src');
                }
                productImage.alt = valid && product && product.name ? String(product.name) : '';
            }

            if (productImageMissing) {
                productImageMissing.hidden = valid;
            }
        }

        function clearCartMessages() {
            cartSuccess.hidden = true;
            cartError.hidden = true;
            text(cartSuccess, '');
            text(cartError, '');
        }

        function updateCartControls(state) {
            var selectedId = state.selectedInventoryId;
            var selectedExists = typeof selectedId === 'string' && /^[A-Za-z0-9_-]{40,512}$/.test(selectedId)
                && state.offers.some(function (offer) {
                    return offer.offer_token === selectedId;
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
                var selected = offer.offer_token === state.selectedInventoryId;
                var button = createOfferButton(offer, selected, state.selectionLocked);

                if (displayMode === 'price' && index === 0) {
                    button.classList.add('va-offer-card--best-price');
                    button.append(document.createElement('span'));
                    button.lastChild.className = 'va-offer-card__badge';
                    button.lastChild.textContent = 'Precio más conveniente';
                }

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
                text(root.querySelector('[data-va-selected-store]'), state.selection.offer_label);
                text(root.querySelector('[data-va-selected-price]'), money(state.selection.unit_price));
                text(root.querySelector('[data-va-selected-stock]'), state.selection.availability);
            } else if (state.error) {
                text(status, state.error.message);
            } else {
                text(status, 'Aún no has seleccionado una oferta.');
            }

            error.hidden = state.error === null;
            text(error, state.error ? state.error.message : '');
            buttons = list.querySelectorAll('[role="radio"]');

            if (buttons.length === 0 || state.selectionLocked) {
                list.setAttribute('aria-disabled', 'true');
            } else {
                list.removeAttribute('aria-disabled');
            }

            updateCartControls(state);
        }

        function selectButton(button, focus) {
            var offerToken = button.getAttribute('data-offer-token');
            var state = store.select(offerToken);
            var selected;

            if (focus && state.selectedInventoryId !== null) {
                selected = Array.from(list.querySelectorAll('[data-offer-token]')).find(function(candidate){return candidate.getAttribute('data-offer-token')===state.selectedInventoryId;});

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
        root.querySelectorAll('[data-va-offer-mode]').forEach(function(button){
            button.addEventListener('click',function(){displayMode=button.getAttribute('data-va-offer-mode')==='free'?'free':'price';render(store.getState());});
        });

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
            var selectedExists = typeof selectedId === 'string' && /^[A-Za-z0-9_-]{40,512}$/.test(selectedId)
                && state.offers.some(function (offer) {
                    return offer.offer_token === selectedId;
                });
            var payload;
            var operationSelection;

            if (isAddingToCart || !selectedExists) {
                return Promise.resolve(null);
            }

            payload = store.getCartPayload();
            operationSelection = state.selection ? Object.assign({}, state.selection) : null;

            if (
                !payload
                || typeof payload.offer_token !== 'string'
                || payload.offer_token !== selectedId
                || payload.quantity !== 1
                || Object.keys(payload).length !== 2
            ) {
                cartError.hidden = false;
                text(cartError, 'No fue posible seleccionar una oferta válida.');
                return Promise.resolve(null);
            }

            isAddingToCart = true;
            clearCartMessages();
            store.setSelectionLocked(true);

            return config.api.post('/cart/items', {
                offer_token: selectedId,
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
                    text(
                        cartSuccess,
                        'Producto agregado al carrito desde la opción seleccionada.'
                    );
                    viewCart.hidden = false;
                    continueShopping.hidden = false;

                    return response;
                })
                .catch(function (requestError) {
                    cartError.hidden = false;
                    text(cartError, publicCartError(requestError));

                    return null;
                })
                .finally(function () {
                    isAddingToCart = false;
                    store.setSelectionLocked(false);
                });
        }

        addButton.addEventListener('click', addToCart);

        function reload() {
            var sequence = ++reloadSequence;

            loading.hidden = false;
            error.hidden = true;

            return config.api.get('/catalog/products/' + productId)
                .then(function (payload) {
                    if (sequence !== reloadSequence) {
                        return;
                    }

                    currentProduct = payload && payload.data ? payload.data : null;

                    if (!currentProduct || Number(currentProduct.id) !== productId) {
                        throw { status: 0, code: 'invalid_product', message: 'El producto no es válido.', data: null };
                    }

                    text(root.querySelector('[data-va-product-name]'), currentProduct.name);
                    text(root.querySelector('[data-va-product-description]'), currentProduct.short_description || currentProduct.description || '');
                    renderProductImage(currentProduct);
                    section.hidden = false;
                    store.setProduct(currentProduct);
                })
                .catch(function (requestError) {
                    if (sequence !== reloadSequence) {
                        return;
                    }

                    error.hidden = false;
                    text(error, requestError && requestError.message ? requestError.message : 'No fue posible cargar el producto.');
                })
                .finally(function () {
                    if (sequence === reloadSequence) {
                        loading.hidden = true;
                    }
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
