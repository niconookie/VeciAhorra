export function readAdminContext(locationHref) {
    const url = new URL(locationHref, window.location.origin);
    const productValues = url.searchParams.getAll('product_id');
    const actionValues = url.searchParams.getAll('action');
    const hasArraySyntax = [...url.searchParams.keys()].some((key) => (
        key.startsWith('product_id[') || key.startsWith('action[')
    ));

    if (hasArraySyntax) {
        return { status: 'invalid', intent: 'list', productId: null };
    }

    if (productValues.length === 0) {
        return { status: 'none', intent: 'list', productId: null };
    }

    if (
        productValues.length !== 1
        || !/^[1-9]\d*$/.test(productValues[0])
        || !Number.isSafeInteger(Number(productValues[0]))
        || actionValues.length > 1
    ) {
        return { status: 'invalid', intent: 'list', productId: null };
    }

    const hasAction = actionValues.length === 1;
    const action = actionValues[0] ?? '';
    const validAction = !hasAction || action === 'create';

    return {
        status: validAction ? 'valid' : 'invalid',
        intent: validAction && action === 'create' ? 'create' : 'list',
        productId: validAction ? Number(productValues[0]) : null,
    };
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
