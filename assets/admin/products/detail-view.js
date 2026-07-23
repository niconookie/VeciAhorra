export function createProductDetailView(nodes, actions) {
    let lastKey = '';
    function render(state) {
        nodes.main.setAttribute(
            'aria-busy',
            state.status === 'loading' || state.busy ? 'true' : 'false'
        );
        const key = JSON.stringify(state);
        if (key === lastKey) return;
        lastKey = key;
        nodes.messages.replaceChildren();
        if (state.error) renderNotice(nodes.messages, state.error, actions);
        if (state.status === 'loading') {
            nodes.main.replaceChildren(text('p', 'Cargando detalle del producto…'));
        } else if (state.status === 'not-found') {
            const box = section('Product no encontrado');
            box.append(
                text('p', 'El Product solicitado no existe.'),
                link('Volver al listado', actions.listUrl)
            );
            nodes.main.replaceChildren(box);
            box.focus();
        } else if (state.status === 'error') {
            const box = section('Detalle no disponible');
            const retry = button('Reintentar', actions.reload);
            box.append(text('p', state.error.message), retry);
            nodes.main.replaceChildren(box);
            retry.focus();
        } else if (state.product) {
            renderProduct(nodes.main, state.product, state.busy, actions);
        }
    }
    return {render};
}

function renderProduct(main, product, busy, actions) {
    const header = document.createElement('header');
    header.className = 'veciahorra-product-detail__hero';
    header.append(image(product), identity(product));
    const actionBar = document.createElement('div');
    actionBar.className = 'veciahorra-product-detail__actions';
    actionBar.append(
        link('Volver al listado', actions.listUrl),
        link('Editar Product', actions.editUrl),
        link('Ver todas las ofertas', actions.inventoryListUrl(product.id)),
        link('Crear oferta', actions.inventoryCreateUrl(product.id))
    );
    product.lifecycle.allowed_statuses.forEach((status) => {
        const control = button(
            status === 'active' ? 'Activar' : 'Desactivar',
            () => actions.changeStatus(status)
        );
        control.disabled = busy;
        actionBar.append(control);
    });
    header.append(actionBar);

    const commercial = section('Información comercial');
    commercial.append(
        field('Slug', product.slug),
        field('Descripción', product.description || 'Sin descripción')
    );
    const taxonomies = section('Taxonomías');
    Object.entries(product.taxonomies).forEach(([key, value]) => {
        taxonomies.append(field(taxonomyName(key), taxonomyValue(value)));
    });
    const offers = section('Ofertas');
    offers.append(offerSummary(product.inventory));
    if (product.inventory.offers.length === 0) {
        offers.append(text('p', 'Este Product no tiene ofertas.'));
    } else {
        const list = document.createElement('div');
        list.className = 'veciahorra-product-detail__offers';
        product.inventory.offers.forEach((offer) => {
            const card = section(offer.store_name || 'Referencia Store inconsistente');
            card.classList.add('veciahorra-product-detail__offer');
            card.append(
                field('Inventory', `#${offer.id}`),
                field('Estado', offer.status),
                field('Precio', String(offer.price)),
                field('Stock', String(offer.stock)),
                field('Disponibilidad', availabilityLabel(offer.availability_reason)),
                link('Ver ofertas en Inventory', actions.inventoryListUrl(product.id))
            );
            list.append(card);
        });
        offers.append(list);
    }
    const references = section('Referencias');
    references.append(
        field('Clasificación', product.references.classification),
        field('Inventory', String(product.references.inventory.total)),
        field('Cart', String(product.references.cart.total)),
        field('Reservations', String(product.references.reservations.total)),
        field('OrderItems', String(product.references.order_items.total))
    );
    const lifecycle = section('Estado y lifecycle');
    lifecycle.append(
        field('Estado durable', product.status),
        field('Versión CAS', product.lifecycle.expected_updated_at)
    );
    const metadata = section('Metadatos técnicos');
    metadata.append(
        field('Creado', product.created_at),
        field('Actualizado', product.updated_at),
        field('ID', String(product.id))
    );
    main.replaceChildren(
        header, commercial, taxonomies, offers,
        references, lifecycle, metadata
    );
    const heading = main.querySelector('h1');
    if (heading) {
        heading.tabIndex = -1;
        heading.focus();
    }
}

function image(product) {
    if (product.image.url) {
        const img = document.createElement('img');
        img.className = 'veciahorra-product-detail__image';
        img.src = product.image.url;
        img.alt = `Imagen de ${product.name}`;
        img.addEventListener('error', () => img.replaceWith(imageFallback(product.name)), {once:true});
        return img;
    }
    return imageFallback(product.name);
}
function imageFallback(name) {
    const fallback = text('div', 'Sin imagen');
    fallback.className = 'veciahorra-product-detail__image veciahorra-product-detail__image--fallback';
    fallback.setAttribute('role','img');
    fallback.setAttribute('aria-label',`Sin imagen para ${name}`);
    return fallback;
}
function identity(product) {
    const box=document.createElement('div');
    const heading=text('h1',product.name);
    const status=text('span',product.status);
    status.className='veciahorra-products-admin__product-status';
    status.dataset.status=product.status;
    box.append(
        heading,
        text('p', product.sku || `Product #${product.id}`),
        status,
        text('p', product.publicly_available ? 'Disponible públicamente' : 'Sin disponibilidad pública'),
        text('p', `Actualizado: ${product.updated_at}`)
    );
    return box;
}
function offerSummary(value) {
    return text('p', `${value.total} ofertas · ${value.active} activas · ${value.inactive} inactivas · ${value.publicly_available} públicas`);
}
function taxonomyName(key) { return {category:'Categoría',brand:'Marca',unit:'Unidad'}[key]; }
function taxonomyValue(value) {
    if (value.status === 'valid') return value.name;
    return {
        unassigned:'Sin asignación',
        orphaned:'Referencia no disponible',
        taxonomy_unregistered:'Taxonomía no registrada',
    }[value.status] || 'No disponible';
}
function availabilityLabel(reason) {
    return {
        publicly_available:'Disponible públicamente',
        product_inactive:'Product inactivo',
        inventory_inactive:'Inventory inactivo',
        inventory_unknown:'Estado Inventory desconocido',
        out_of_stock:'Sin stock',
        invalid_price:'Precio inválido',
        store_missing:'Referencia Store inconsistente',
        store_inactive:'Store inactiva',
    }[reason] || 'Sin disponibilidad pública';
}
function renderNotice(container, error, actions) {
    const notice=document.createElement('div');
    notice.className='notice notice-error inline';
    notice.append(text('p', error.status===409
        ? 'El Product cambió en otra sesión. Recarga antes de intentar nuevamente.'
        : error.message));
    if (error.status===409) notice.append(button('Recargar',actions.reload));
    container.replaceChildren(notice);
}
function section(title) {
    const node=document.createElement('section');
    node.className='veciahorra-product-detail__section';
    node.tabIndex=-1;
    node.append(text('h2',title));
    return node;
}
function field(label,value) {
    const row=document.createElement('div');
    row.className='veciahorra-product-detail__field';
    row.append(text('strong',`${label}: `),text('span',value));
    return row;
}
function text(tag,value) { const node=document.createElement(tag); node.textContent=value; return node; }
function link(label,url) { const node=document.createElement('a'); node.className='button'; node.href=url; node.textContent=label; return node; }
function button(label,handler) { const node=document.createElement('button'); node.type='button'; node.className='button'; node.textContent=label; node.addEventListener('click',handler); return node; }
