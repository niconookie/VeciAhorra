const STATUS = ['', 'active', 'inactive', 'unknown'];
const AVAILABILITY = ['', 'public', 'not_public', 'diagnostic_error'];
const CAUSES = [
    '',
    'product_reference_invalid',
    'store_reference_invalid',
    'product_missing',
    'store_missing',
    'reference_mismatch',
    'inventory_status_unknown',
    'inventory_inactive',
    'product_status_unknown',
    'product_not_public',
    'store_status_unknown',
    'store_not_active',
    'invalid_public_price',
    'out_of_stock',
    'publicly_available',
];
const SORT = [
    'updated_at',
    'id',
    'product_name',
    'store_name',
    'price',
    'stock',
    'status',
];
const ORDER = ['ASC', 'DESC'];
const LIST_KEYS = [
    'page',
    'search',
    'status',
    'availability',
    'cause',
    'product_id',
    'store_id',
    'minimarket_id',
    'return_store_id',
    'sort',
    'order',
    'paged',
];
const RETURN_MAP = {
    search: 'return_search',
    productId: 'return_product_id',
    minimarketId: 'return_minimarket_id',
    status: 'return_status',
    availability: 'return_availability',
    cause: 'return_cause',
    orderBy: 'return_order_by',
    direction: 'return_direction',
    page: 'return_paged',
    perPage: 'return_per_page',
};

export function readInventoryListUrl(href, baseAdminUrl) {
    const url = new URL(href, window.location.origin);
    const base = validatedBase(baseAdminUrl);
    const keys = [...url.searchParams.keys()];

    if (
        url.origin !== base.origin
        || url.pathname !== base.pathname
        || url.searchParams.get('page') !== 'veciahorra-inventory'
        || keys.some((key) => !LIST_KEYS.includes(key))
        || keys.some((key) => key.includes('['))
        || LIST_KEYS.some((key) => url.searchParams.getAll(key).length > 1)
    ) {
        return { valid: false, message: 'La URL del listado no es valida.' };
    }

    const search = (url.searchParams.get('search') || '').trim();
    const status = enumValue(url, 'status', STATUS, '');
    const availability = enumValue(
        url,
        'availability',
        AVAILABILITY,
        ''
    );
    const cause = enumValue(url, 'cause', CAUSES, '');
    const productId = positiveId(url.searchParams.get('product_id'));
    const durableStoreId = positiveId(url.searchParams.get('store_id'));
    const legacyStoreId = positiveId(url.searchParams.get('minimarket_id'));
    const returnStoreId = positiveId(
        url.searchParams.get('return_store_id')
    );
    const minimarketId = durableStoreId || legacyStoreId;
    const orderBy = enumValue(url, 'sort', SORT, 'updated_at');
    const direction = enumValue(url, 'order', ORDER, 'DESC', true);
    const page = positivePage(url.searchParams.get('paged'), 1);

    if (
        search.length > 100
        || status === null
        || availability === null
        || cause === null
        || productId === null
        || durableStoreId === null
        || legacyStoreId === null
        || returnStoreId === null
        || (durableStoreId !== '' && legacyStoreId !== '')
        || (
            returnStoreId !== ''
            && (legacyStoreId === '' || returnStoreId !== legacyStoreId)
        )
        || (productId !== '' && minimarketId !== '')
        || orderBy === null
        || direction === null
        || page === null
        || incompatible(availability, cause)
    ) {
        return { valid: false, message: 'Los filtros de la URL no son validos.' };
    }

    const query = {
        search,
        productId,
        minimarketId,
        status,
        availability,
        cause,
        page,
        perPage: 20,
        orderBy,
        direction,
    };

    return {
        valid: true,
        query,
        canonicalUrl: buildInventoryListUrl(baseAdminUrl, query),
    };
}

export function buildInventoryListUrl(baseAdminUrl, query) {
    const url = validatedBase(baseAdminUrl);
    const entries = [
        ['search', query.search],
        ['status', query.status],
        ['availability', query.availability],
        ['cause', query.cause],
        ['product_id', query.productId],
        ['store_id', query.minimarketId],
        ['sort', query.orderBy === 'updated_at' ? '' : query.orderBy],
        ['order', query.direction === 'DESC' ? '' : query.direction],
        ['paged', Number(query.page) === 1 ? '' : query.page],
    ];

    entries.forEach(([key, value]) => {
        const normalized = String(value ?? '').trim();
        if (normalized !== '') url.searchParams.set(key, normalized);
    });
    return url.toString();
}

export function buildInventoryActionUrl(baseAdminUrl, action, id, query) {
    if (!['view', 'edit'].includes(action) || positiveId(String(id)) === null) {
        throw new TypeError('La accion de Inventory no es valida.');
    }
    const url = validatedBase(baseAdminUrl);
    url.searchParams.set('action', action);
    url.searchParams.set('inventory_id', String(id));
    Object.entries(RETURN_MAP).forEach(([field, key]) => {
        const value = query[field];
        const isDefault = (field === 'page' && Number(value) === 1)
            || (field === 'perPage' && Number(value) === 20)
            || (field === 'orderBy' && value === 'updated_at')
            || (field === 'direction' && value === 'DESC');
        if (!isDefault && String(value ?? '').trim() !== '') {
            url.searchParams.set(key, String(value).trim());
        }
    });
    return url.toString();
}

export function readInventoryEditUrl(href, baseAdminUrl) {
    const url = new URL(href, window.location.origin);
    const base = validatedBase(baseAdminUrl);
    const allowed = [
        'page',
        'action',
        'inventory_id',
        ...Object.values(RETURN_MAP),
    ];
    const keys = [...url.searchParams.keys()];
    const id = positiveId(url.searchParams.get('inventory_id'));

    if (
        url.origin !== base.origin
        || url.pathname !== base.pathname
        || url.searchParams.get('page') !== 'veciahorra-inventory'
        || url.searchParams.get('action') !== 'edit'
        || id === null
        || id === ''
        || keys.some((key) => !allowed.includes(key) || key.includes('['))
        || allowed.some((key) => url.searchParams.getAll(key).length > 1)
    ) {
        return { valid: false, message: 'La URL de edición no es válida.' };
    }

    const query = {
        search: (url.searchParams.get('return_search') || '').trim(),
        productId: positiveId(url.searchParams.get('return_product_id')),
        minimarketId: positiveId(
            url.searchParams.get('return_minimarket_id')
        ),
        status: returnEnum(url, 'return_status', STATUS, ''),
        availability: returnEnum(
            url,
            'return_availability',
            AVAILABILITY,
            ''
        ),
        cause: returnEnum(url, 'return_cause', CAUSES, ''),
        page: positivePage(url.searchParams.get('return_paged'), 1),
        perPage: returnPerPage(url.searchParams.get('return_per_page')),
        orderBy: returnEnum(
            url,
            'return_order_by',
            SORT,
            'updated_at'
        ),
        direction: returnEnum(
            url,
            'return_direction',
            ORDER,
            'DESC',
            true
        ),
    };

    if (
        query.search.length > 100
        || Object.values(query).includes(null)
        || incompatible(query.availability, query.cause)
        || (query.productId !== '' && query.minimarketId !== '')
    ) {
        return { valid: false, message: 'El retorno de edición no es válido.' };
    }

    return {
        valid: true,
        id: Number(id),
        query,
        canonicalUrl: buildInventoryActionUrl(
            baseAdminUrl,
            'edit',
            Number(id),
            query
        ),
    };
}

export function safeAdministrativeRoute(value, expectedPage) {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        const url = new URL(value, window.location.origin);
        const allowed = expectedPage === 'veciahorra-products'
            ? ['page', 'action', 'product_id']
            : ['page', 'action', 'id'];
        const idKey = expectedPage === 'veciahorra-products'
            ? 'product_id'
            : 'id';
        if (
            url.origin !== window.location.origin
            || !url.pathname.endsWith('/admin.php')
            || url.searchParams.get('page') !== expectedPage
            || url.searchParams.get('action') !== 'view'
            || positiveId(url.searchParams.get(idKey)) === null
            || positiveId(url.searchParams.get(idKey)) === ''
            || [...url.searchParams.keys()].some(
                (key) => !allowed.includes(key)
            )
            || allowed.some(
                (key) => url.searchParams.getAll(key).length > 1
            )
        ) return null;
        return url.toString();
    } catch {
        return null;
    }
}

function validatedBase(baseAdminUrl) {
    const url = new URL(baseAdminUrl, window.location.origin);
    if (
        url.origin !== window.location.origin
        || !url.pathname.endsWith('/admin.php')
        || url.searchParams.get('page') !== 'veciahorra-inventory'
        || [...url.searchParams.keys()].some((key) => key !== 'page')
    ) throw new TypeError('La URL base de Inventory no es valida.');
    return url;
}

function positiveId(value) {
    if (value === null || value === '') return '';
    return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? value
        : null;
}

function positivePage(value, fallback) {
    if (value === null || value === '') return fallback;
    return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : null;
}

function enumValue(url, key, allowed, fallback, uppercase = false) {
    const raw = url.searchParams.get(key);
    if (raw === null || raw === '') return fallback;
    const value = uppercase ? raw.toUpperCase() : raw.toLowerCase();
    return allowed.includes(value) ? value : null;
}

function returnEnum(url, key, allowed, fallback, uppercase = false) {
    return enumValue(url, key, allowed, fallback, uppercase);
}

function returnPerPage(value) {
    if (value === null || value === '') return 20;
    return ['20', '50', '100'].includes(value) ? Number(value) : null;
}

function incompatible(availability, cause) {
    if (!availability || !cause) return false;
    const diagnostics = [
        'product_reference_invalid',
        'store_reference_invalid',
        'product_missing',
        'store_missing',
        'reference_mismatch',
        'inventory_status_unknown',
        'product_status_unknown',
        'store_status_unknown',
    ];
    const expected = cause === 'publicly_available'
        ? 'public'
        : diagnostics.includes(cause)
            ? 'diagnostic_error'
            : 'not_public';
    return availability !== expected;
}
