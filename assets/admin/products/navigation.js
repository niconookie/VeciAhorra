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
