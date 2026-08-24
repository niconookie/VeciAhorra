(function (window, document) {
    'use strict';

    var config = window.VeciAhorra || {};
    var money = new Intl.NumberFormat(config.locale || 'es-CL', {
        style: 'currency',
        currency: config.currency || 'CLP',
        maximumFractionDigits: 0
    });
    var safeClass = /^-?[_a-zA-Z]+[_a-zA-Z0-9-]*$/;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function safeUrl(value) {
        var parsed;
        var normalized = String(value || '').trim();

        if (!normalized) { return ''; }

        try {
            parsed = new URL(normalized, window.location.origin);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? normalized : '';
        } catch (ignore) {
            return '';
        }
    }

    function modifierClasses(value) {
        var normalized = typeof value === 'string' ? value.trim() : '';
        var classes = normalized ? normalized.split(/\s+/) : [];

        return classes.length && classes.every(function (className) {
            return safeClass.test(className);
        }) ? classes : [];
    }

    function normalized(value) {
        return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    }

    function secondaryValue(value, productName) {
        var label = value && value.name ? String(value.name).trim() : '';
        var normalizedLabel = normalized(label);
        var normalizedName = normalized(productName);

        return label && normalizedLabel && !(' ' + normalizedName + ' ').includes(' ' + normalizedLabel + ' ') ? label : '';
    }

    function render(product, options) {
        var item = product && typeof product === 'object' ? product : {};
        var settings = options && typeof options === 'object' ? options : {};
        var headingTag = settings.headingTag === 'h3' ? 'h3' : 'h2';
        var article = el('article', 'va-card va-catalog-card');
        var media = el('div', 'va-catalog-card__media');
        var body = el('div', 'va-catalog-card__body');
        var url = safeUrl(settings.url);
        var imageUrl = safeUrl(item.image);
        var name = String(item.name || 'Producto');
        var price = item.min_price;
        var minimarkets = Number(item.available_minimarkets);
        var offers = Number(item.eligible_offers);
        var metadata = [secondaryValue(item.unit, name), secondaryValue(item.brand, name)].filter(Boolean);
        var catalogMode = settings.catalogMode === true;
        var priceLine;
        var image;
        var link;
        var actions;
        var addButton;
        var cardStatus;

        modifierClasses(settings.modifierClass).forEach(function (className) {
            article.classList.add(className);
        });
        if (catalogMode) {
            media.classList.add('va-catalog-card__media--tone-' + (Math.abs(Number(item.id) || 0) % 5));
        }

        if (imageUrl) {
            image = el('img', 'va-catalog-card__image');
            image.src = imageUrl;
            image.alt = name;
            image.loading = 'lazy';
            image.decoding = 'async';
            media.appendChild(image);
        } else {
            media.appendChild(el('span', 'va-catalog-card__image-missing', 'Imagen no disponible'));
        }

        if (catalogMode && metadata.length) {
            body.appendChild(el('p', 'va-catalog-card__metadata', metadata.join(' · ')));
        }
        body.appendChild(el(headingTag, 'va-catalog-card__title', name));
        if (price !== null && price !== undefined && price !== '' && isFinite(Number(price))) {
            priceLine = el('p', 'va-catalog-card__price');
            priceLine.appendChild(el('span', 'va-catalog-card__price-prefix', catalogMode ? 'desde' : 'Desde'));
            priceLine.appendChild(el('strong', 'va-catalog-card__price-value', money.format(Number(price))));
            body.appendChild(priceLine);
        }
        if (Number.isInteger(minimarkets) && minimarkets > 0) {
            body.appendChild(el(
                'p',
                'va-catalog-card__availability',
                'Disponible en ' + minimarkets + (minimarkets === 1 ? ' minimarket' : ' minimarkets')
            ));
        }

        if (!catalogMode) {
            if (url) {
                link = el('a', 'va-button va-button--primary va-catalog-card__action', 'Ver producto');
                link.href = url;
                body.appendChild(link);
            } else {
                body.appendChild(el('span', 'va-catalog-card__unavailable', 'Ficha no disponible'));
            }
            article.appendChild(media);
            article.appendChild(body);
            return article;
        }

        actions = el('div', 'va-catalog-card__actions');
        if (url) {
            link = el('a', 'va-catalog-card__action', 'Ver opciones');
            link.href = url;
        } else {
            link = el('span', 'va-catalog-card__unavailable', 'Ficha no disponible');
        }
        actions.appendChild(link);
        addButton = el('button', 'va-catalog-card__add', '+');
        addButton.type = 'button';
        addButton.setAttribute('aria-label', Number.isInteger(offers) && offers === 1 ? 'Agregar al carrito' : 'Ver opciones disponibles');
        addButton.setAttribute('aria-busy', 'false');
        addButton.addEventListener('click', function () {
            if (Number.isInteger(offers) && offers === 1 && typeof settings.quickAdd === 'function') {
                settings.quickAdd(item, addButton, cardStatus);
            } else if (typeof settings.openOptions === 'function') {
                settings.openOptions(item);
            }
        });
        actions.appendChild(addButton);
        cardStatus = el('p', 'va-catalog-card__status');
        cardStatus.setAttribute('role', 'status');
        cardStatus.setAttribute('aria-live', 'polite');
        body.append(actions, cardStatus);
        article.appendChild(media);
        article.appendChild(body);
        return article;
    }

    window.VeciAhorraProductCard = Object.freeze({ render: render });
}(window, document));
