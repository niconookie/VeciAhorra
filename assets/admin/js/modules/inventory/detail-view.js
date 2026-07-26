import { safeAdministrativeRoute } from './list-navigation.js';

const node = (tag, text, className = '') => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
};

export function renderInventoryDetail(container, detail, actions) {
    if (detail.status === 'loading') {
        const loading = node(
            'div',
            `Cargando detalle de Inventory #${detail.id}…`,
            'veciahorra-inventory-admin__detail-state'
        );
        loading.setAttribute('role', 'status');
        container.replaceChildren(loading);
        return;
    }
    if (detail.status === 'error') {
        renderDetailError(container, detail, actions);
        return;
    }
    if (!detail.item) {
        container.replaceChildren();
        return;
    }

    const item = detail.item;
    const article = node(
        'article',
        undefined,
        'veciahorra-inventory-admin__detail'
    );
    const header = node(
        'header',
        undefined,
        'veciahorra-inventory-admin__detail-header'
    );
    const title = node('h2', `Oferta Inventory #${item.identity.id}`);
    title.tabIndex = -1;
    const headerBadges = node(
        'div',
        undefined,
        'veciahorra-inventory-admin__detail-badges'
    );
    headerBadges.append(
        badge(
            statusLabel(item.offer.status, 'Inventory'),
            statusTone(item.offer.status)
        ),
        badge(
            item.availability.is_publicly_available
                ? 'Disponible públicamente'
                : 'No disponible públicamente',
            item.availability.is_publicly_available
                ? 'success'
                : causeTone(item.availability.primary_cause.code)
        )
    );
    const navigation = node(
        'nav',
        undefined,
        'veciahorra-inventory-admin__detail-actions'
    );
    navigation.setAttribute('aria-label', 'Acciones de la oferta');
    navigation.append(link('Volver al listado', actions.returnUrl));
    if (item.actions.edit) {
        const edit = link(
            `Editar Inventory #${item.identity.id}`,
            actions.editUrl
        );
        edit.addEventListener('click', (event) => {
            event.preventDefault();
            actions.onEdit(item.identity.id);
        });
        navigation.append(edit);
    }
    header.append(title, headerBadges, navigation);

    article.append(
        header,
        section('Resumen de la oferta', definitionList([
            ['Precio', formatPrice(item.offer.price)],
            ['Stock registrado', String(item.offer.stock)],
            ['Estado Inventory', statusLabel(item.offer.status, 'Inventory')],
            ['Creación', text(item.identity.created_at)],
            ['Última actualización', text(item.identity.updated_at)],
            ['Concurrencia', 'Última escritura prevalece (last_write_wins)'],
            ['Versión observada', text(item.concurrency.version)],
            ['Observado por última vez', text(item.concurrency.last_observed_at)],
            [
                'Acciones informadas',
                item.lifecycle.allowed_actions.length
                    ? item.lifecycle.allowed_actions.join(', ')
                    : 'Ninguna',
            ],
        ])),
        entitySection('Product asociado', item.product, item.routes.product, true),
        storeSection(item.store, item.routes.store),
        availabilitySection(item.availability),
        referencesSection(item.references)
    );
    container.replaceChildren(article);
    queueMicrotask(() => title.focus({ preventScroll: true }));
}

function renderDetailError(container, detail, actions) {
    const status = detail.error?.status;
    const message = status === 404
        ? `Inventory #${detail.id} no existe o ya no está disponible.`
        : status === 401
            ? 'La sesión administrativa debe autenticarse nuevamente.'
            : status === 403
                ? 'No tiene permisos para consultar este Inventory.'
                : status === 422
                    ? 'La solicitud del detalle no pudo ser procesada.'
                    : status !== null && status >= 500
                        ? 'El servidor no pudo cargar el detalle de Inventory.'
                        : detail.error?.type === 'network'
                            ? 'No fue posible conectar con el servidor.'
                            : detail.error?.type === 'invalid_json'
                                ? 'El servidor devolvió una respuesta no válida.'
                                : 'No fue posible cargar el detalle.';
    const notice = node(
        'div',
        undefined,
        'notice notice-error inline veciahorra-inventory-admin__detail-error'
    );
    notice.setAttribute('role', 'alert');
    notice.tabIndex = -1;
    notice.append(node('p', message));
    const controls = node('div', undefined, 'veciahorra-inventory-admin__detail-actions');
    controls.append(link('Volver al listado', actions.returnUrl));
    if (status !== 404 && status !== 401 && status !== 403) {
        const retry = node('button', 'Reintentar', 'button button-primary');
        retry.type = 'button';
        retry.addEventListener('click', actions.onRetry);
        controls.append(retry);
    }
    notice.append(controls);
    container.replaceChildren(notice);
    queueMicrotask(() => notice.focus({ preventScroll: true }));
}

function entitySection(title, entity, route, product = false) {
    const content = node('div', undefined, 'veciahorra-inventory-admin__entity-detail');
    if (product) content.append(productImage(entity.image, entity.name));
    const entries = [
        ['Estado de referencia', entity.exists ? 'Referencia resuelta' : 'Referencia no resuelta'],
        ['Nombre', text(entity.name, entity.exists ? 'No informado' : 'Product faltante')],
        ['ID', String(entity.id)],
        ...(product ? [
            ['SKU', text(entity.sku)],
            ['Slug', text(entity.slug)],
        ] : []),
        ['Estado', statusLabel(entity.status, product ? 'Product' : 'Store')],
    ];
    content.append(definitionList(entries));
    const safe = safeAdministrativeRoute(
        route,
        product ? 'veciahorra-products' : 'veciahorra-stores'
    );
    if (safe && entity.exists) {
        content.append(link(
            `Abrir ${product ? 'Product' : 'Store'} #${entity.id}`,
            safe
        ));
    }
    return section(title, content);
}

function storeSection(store, route) {
    const base = entitySection('Store asociada', store, route, false);
    const list = base.querySelector('dl');
    [
        ['Onboarding', text(store.onboarding_status)],
        ['Aprobación', text(store.approved_at, 'Sin fecha de aprobación')],
        ['Lifecycle derivado', statusLabel(store.lifecycle_state, 'Lifecycle')],
        ['Ubicación', location(store.location)],
    ].forEach(([term, value]) => list.append(node('dt', term), node('dd', value)));
    if (store.lifecycle_state === 'invalid') {
        base.append(badge(
            'Advertencia: lifecycle Store inconsistente',
            'warning'
        ));
    }
    return base;
}

function productImage(image, name) {
    const wrapper = node('div', undefined, 'veciahorra-inventory-admin__product-image');
    const url = image.status === 'valid' ? safeImageUrl(image.url) : null;
    if (url) {
        const element = document.createElement('img');
        element.src = url;
        element.alt = '';
        element.loading = 'lazy';
        wrapper.append(element, node('span', 'Imagen decorativa del Product'));
        return wrapper;
    }
    const labels = {
        absent: 'Imagen no asignada',
        missing_attachment: 'Attachment de imagen inexistente',
        unavailable: 'Imagen no disponible',
    };
    wrapper.append(node('span', labels[image.status] || `Imagen no reconocida para ${text(name)}`));
    return wrapper;
}

function availabilitySection(availability) {
    const content = node('div');
    content.append(definitionList([
        [
            'Disponibilidad',
            availability.is_publicly_available
                ? 'Disponible públicamente'
                : 'No disponible públicamente',
        ],
        ['Política evaluada', text(availability.evaluated_policy)],
        ['Causa primaria', causeText(availability.primary_cause)],
    ]));
    const primary = availability.primary_cause.code;
    const blocks = uniqueCauses(
        availability.blocking_causes,
        availability.blocking_codes
    ).filter((cause) => cause.code !== primary);
    content.append(causeGroup('Bloqueos adicionales', blocks, false));
    content.append(causeGroup(
        'Advertencias',
        uniqueCauses(availability.warnings, availability.warning_codes),
        true
    ));
    content.append(dimensions(availability.dimensions));
    return section('Disponibilidad pública y diagnóstico', content);
}

function causeGroup(title, causes, warning) {
    const group = node('div', undefined, 'veciahorra-inventory-admin__cause-group');
    group.append(node('h4', title));
    if (causes.length === 0) {
        group.append(node('p', warning ? 'Sin advertencias.' : 'Sin bloqueos adicionales.'));
        return group;
    }
    const list = node('ul');
    causes.forEach((cause) => {
        const item = node('li');
        item.append(
            badge(
                `${warning ? 'Advertencia' : 'Bloqueo'}: ${causeLabel(cause.code)}`,
                warning ? 'warning' : causeTone(cause.code)
            ),
            node('span', cause.message ? ` ${cause.message}` : '')
        );
        list.append(item);
    });
    group.append(list);
    return group;
}

function dimensions(value) {
    const wrapper = node('div', undefined, 'veciahorra-inventory-admin__dimensions');
    wrapper.append(node('h3', 'Diagnóstico dimensional'));
    const definitions = [
        ['Inventory', value.inventory, [
            ['Existe', 'exists'], ['Estado observado', 'observed_status'],
            ['Estado conocido', 'status_known'], ['Activo', 'active'],
        ]],
        ['Product', value.product, [
            ['Existe', 'exists'], ['Estado observado', 'observed_status'],
            ['Estado conocido', 'status_known'], ['Público', 'public'],
        ]],
        ['Store', value.store, [
            ['Existe', 'exists'], ['Estado observado', 'observed_status'],
            ['Estado conocido', 'status_known'], ['Activo', 'active'],
            ['Lifecycle', 'lifecycle_state'],
            ['Lifecycle consistente', 'lifecycle_consistent'],
        ]],
        ['Referencias', value.references, [
            ['Product ID válido', 'product_reference_valid'],
            ['Store ID válido', 'store_reference_valid'],
            ['Product resuelto', 'product_resolved'],
            ['Store resuelta', 'store_resolved'],
            ['IDs consistentes', 'matches'],
        ]],
        ['Precio', value.price, [
            ['Valor observado', 'observed_value'],
            ['Publicable', 'valid_for_publication'],
        ]],
        ['Stock', value.stock, [
            ['Valor observado', 'observed_value'],
            ['Mayor que cero para publicación', 'available'],
        ]],
    ];
    definitions.forEach(([title, dimension, fields]) => {
        const card = node('section', undefined, 'veciahorra-inventory-admin__dimension');
        card.append(
            node('h4', title),
            definitionList(fields.map(([label, key]) => [
                label,
                diagnosticValue(dimension?.[key]),
            ]))
        );
        wrapper.append(card);
    });
    return wrapper;
}

function referencesSection(references) {
    const reservations = references.reservations;
    const content = node('div');
    content.append(definitionList([
        ['Estado de inspección', statusLabel(references.inspection_status, 'Inspección')],
        ['Clasificación', statusLabel(references.classification, 'Referencias')],
        ['Referencias en Cart', String(references.cart.total)],
        ['Reservations totales', String(reservations.total)],
        ['Reservations activas', String(reservations.active)],
        ['Reservations liberadas', String(reservations.released)],
        ['Reservations expiradas', String(reservations.expired)],
        ['Reservations consumidas', String(reservations.consumed)],
        ['Reservations desconocidas', String(reservations.unknown)],
        [
            'Unidades en reservas activas',
            reservations.active_quantity === null
                ? 'No determinado o sin reservas activas'
                : String(reservations.active_quantity),
        ],
        ['Referencias en OrderItems', String(references.order_items.total)],
    ]));
    const warnings = [...new Set(references.warning_codes)];
    content.append(causeGroup(
        'Advertencias del inspector',
        warnings.map((code) => ({ code, message: '' })),
        true
    ));
    return section('Referencias operacionales agregadas', content);
}

function section(title, content) {
    const element = node('section', undefined, 'veciahorra-inventory-admin__detail-section');
    element.append(node('h3', title), content);
    return element;
}

function definitionList(entries) {
    const list = node('dl', undefined, 'veciahorra-inventory-admin__definitions');
    entries.forEach(([term, value]) => {
        list.append(node('dt', term), node('dd', value));
    });
    return list;
}

function uniqueCauses(objects, codes) {
    const byCode = new Map();
    (Array.isArray(objects) ? objects : []).forEach((cause) => {
        if (cause?.code && !byCode.has(cause.code)) byCode.set(cause.code, cause);
    });
    (Array.isArray(codes) ? codes : []).forEach((code) => {
        if (!byCode.has(code)) byCode.set(code, { code, message: '' });
    });
    return [...byCode.values()];
}

function badge(label, tone) {
    return node(
        'span',
        label,
        `veciahorra-inventory-admin__badge veciahorra-inventory-admin__badge--${tone}`
    );
}

function link(label, href) {
    const element = node('a', label, 'button button-secondary');
    element.href = href;
    return element;
}

function causeText(cause) {
    return `${causeLabel(cause.code)}${cause.message ? `: ${cause.message}` : ''}`;
}

function causeLabel(code) {
    const labels = {
        inventory_missing: 'Inventory inexistente',
        product_reference_invalid: 'Referencia Product inválida',
        store_reference_invalid: 'Referencia Store inválida',
        product_missing: 'Product inexistente',
        store_missing: 'Store inexistente',
        reference_mismatch: 'Referencia contradictoria',
        inventory_status_unknown: 'Estado Inventory desconocido',
        inventory_inactive: 'Inventory inactiva',
        product_status_unknown: 'Estado Product desconocido',
        product_not_public: 'Product no público',
        store_status_unknown: 'Estado Store desconocido',
        store_not_active: 'Store no activo',
        invalid_public_price: 'Precio no publicable',
        out_of_stock: 'Sin stock para publicación',
        publicly_available: 'Pública',
        store_lifecycle_inconsistent: 'Lifecycle Store inconsistente',
        references_present: 'Tiene referencias',
        active_reservation_present: 'Tiene reservas activas',
    };
    return labels[code] || `Estado no reconocido (${code})`;
}

function causeTone(code) {
    if (code === 'publicly_available') return 'success';
    if (code === 'inventory_inactive') return 'neutral';
    if ([
        'product_reference_invalid', 'store_reference_invalid',
        'product_missing', 'store_missing', 'reference_mismatch',
        'inventory_status_unknown', 'product_status_unknown',
        'store_status_unknown', 'inventory_missing',
    ].includes(code)) return 'error';
    return 'warning';
}

function statusTone(status) {
    return status === 'active' ? 'success'
        : status === 'inactive' ? 'neutral' : 'error';
}

function statusLabel(value, subject) {
    if (value === null || value === '') return `${subject}: no informado`;
    const labels = {
        active: 'Activo', inactive: 'Inactivo', draft: 'Borrador',
        pending: 'Pendiente', rejected: 'Rechazado', invalid: 'Inconsistente',
        complete: 'Completa', partial: 'Parcial', failed: 'Fallida',
        unreferenced: 'Sin referencias', operationally_referenced: 'Referenciada operacionalmente',
        historically_referenced: 'Con referencias históricas',
        mixed: 'Referencias operacionales e históricas', unknown: 'Desconocida',
    };
    return labels[value] || `${subject}: estado no reconocido (${value})`;
}

function diagnosticValue(value) {
    if (value === true) return 'Sí';
    if (value === false) return 'No';
    if (value === null || value === undefined || value === '') return 'No determinado';
    return String(value);
}

function formatPrice(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number < 0) {
        return `Valor no publicable (${String(value)})`;
    }
    return `$${number.toLocaleString('es-CL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function location(value) {
    return [value.commune, value.city, value.region]
        .filter((item) => typeof item === 'string' && item.trim() !== '')
        .join(', ') || 'No informada';
}

function text(value, fallback = 'No informado') {
    return typeof value === 'string' && value.trim() !== ''
        ? value
        : fallback;
}

function safeImageUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin
            && ['http:', 'https:'].includes(url.protocol)
            ? url.toString()
            : null;
    } catch {
        return null;
    }
}
