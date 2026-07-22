export function readAdminContext(locationHref) {
    const url = new URL(locationHref, window.location.origin);
    const productValues = url.searchParams.getAll('product_id');
    const storeValues = url.searchParams.getAll('minimarket_id');
    const returnStoreValues = url.searchParams.getAll('return_store_id');
    const actionValues = url.searchParams.getAll('action');
    const hasArraySyntax = [...url.searchParams.keys()].some((key) => (
        key.startsWith('product_id[') || key.startsWith('minimarket_id[')
        || key.startsWith('return_store_id[') || key.startsWith('action[')
    ));

    if (hasArraySyntax) {
        return { status: 'invalid', intent: 'list', productId: null };
    }

    if (productValues.length === 0 && storeValues.length === 0 && returnStoreValues.length === 0) {
        return { status: 'none', intent: 'list', productId: null };
    }

    if (
        productValues.length > 1 || storeValues.length > 1 || returnStoreValues.length > 1
        || (productValues.length === 1 && storeValues.length === 1)
        || actionValues.length > 1
    ) {
        return { status: 'invalid', intent: 'list', productId: null };
    }

    const values = productValues.length === 1 ? productValues : storeValues;
    const kind = productValues.length === 1 ? 'product' : 'store';
    if (values.length !== 1 || !/^[1-9]\d*$/.test(values[0]) || !Number.isSafeInteger(Number(values[0]))) {
        return { status: 'invalid', intent: 'list', productId: null };
    }
    if (returnStoreValues.length > 0 && (kind !== 'store' || returnStoreValues[0] !== values[0])) {
        return { status: 'invalid', intent: 'list', productId: null };
    }
    const hasAction = actionValues.length === 1;
    const action = actionValues[0] ?? '';
    const validAction = !hasAction || action === 'create';

    return {
        status: validAction ? 'valid' : 'invalid',
        intent: validAction && action === 'create' ? 'create' : 'list',
        productId: validAction && kind === 'product' ? Number(values[0]) : null,
        ...(kind === 'store' && validAction ? { kind, storeId: Number(values[0]), returnStoreId: Number(values[0]) } : {}),
    };
}

export function buildStoreContextUrl(baseUrl, storeId, intent = 'list') {
    const url = new URL(baseUrl, window.location.origin);
    if (url.origin !== window.location.origin || !url.pathname.endsWith('/admin.php')
        || url.searchParams.get('page') !== 'veciahorra-inventory'
        || [...url.searchParams.keys()].length !== 1
        || !Number.isSafeInteger(Number(storeId))
        || !/^[1-9]\d*$/.test(String(storeId)) || !['list', 'create'].includes(intent)) {
        throw new TypeError('El contexto administrativo no es valido.');
    }
    url.searchParams.set('minimarket_id', String(storeId));
    url.searchParams.set('return_store_id', String(storeId));
    url.searchParams.delete('product_id');
    if (intent === 'create') url.searchParams.set('action', 'create');
    else url.searchParams.delete('action');
    return url.toString();
}

export function buildContextUrl(baseUrl, productId, intent = 'list') {
    const url = new URL(baseUrl, window.location.origin);

    if (
        url.origin !== window.location.origin
        || !Number.isSafeInteger(Number(productId))
        || !/^[1-9]\d*$/.test(String(productId))
        || !['list', 'create'].includes(intent)
    ) {
        throw new TypeError('El contexto administrativo no es valido.');
    }
    url.searchParams.set('product_id', String(productId));

    if (intent === 'create') {
        url.searchParams.set('action', 'create');
    } else {
        url.searchParams.delete('action');
    }

    return url.toString();
}

export function contextProductErrorMessage(error) {
    return error?.status === 404 || error?.code === 'product_not_found'
        ? 'El producto indicado no existe o ya no esta disponible.'
        : 'No fue posible cargar el producto seleccionado.';
}
