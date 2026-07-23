export function buildInventoryAdminUrl(baseUrl, productId, intent = 'list') {
    if (!Number.isInteger(Number(productId)) || !/^[1-9]\d*$/.test(String(productId))) {
        throw new TypeError('El Product ID contextual no es valido.');
    }

    const url = new URL(baseUrl, window.location.origin);

    if (url.origin !== window.location.origin || !['list', 'create'].includes(intent)) {
        throw new TypeError('La URL administrativa de Inventory no es valida.');
    }
    url.searchParams.set('product_id', String(productId));

    if (intent === 'create') {
        url.searchParams.set('action', 'create');
    } else {
        url.searchParams.delete('action');
    }

    return url.toString();
}

export function buildProductAdminUrl(baseUrl, productId, action = 'view', returnQuery = {}) {
    if (!/^[1-9]\d*$/.test(String(productId))
        || !['view', 'edit'].includes(action)) {
        throw new TypeError('La ruta administrativa de Product no es valida.');
    }
    const url = new URL(baseUrl, window.location.origin);
    if (url.origin !== window.location.origin) {
        throw new TypeError('La URL administrativa de Product no es valida.');
    }
    url.searchParams.set('action', action);
    url.searchParams.set('product_id', String(productId));
    const mapping = {
        term: 'term',
        status: 'status',
        categoryId: 'category_id',
        brandId: 'brand_id',
        page: 'paged',
    };
    Object.entries(mapping).forEach(([source, target]) => {
        const value = returnQuery[source];
        if (value !== '' && value !== null && value !== undefined
            && !(source === 'page' && value === 1)) {
            url.searchParams.set(target, String(value));
        }
    });

    return url.toString();
}
