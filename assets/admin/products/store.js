export const STATUS_IDLE = 'idle';
export const STATUS_LOADING = 'loading';
export const STATUS_SUCCESS = 'success';
export const STATUS_EMPTY = 'empty';
export const STATUS_ERROR = 'error';

export const VIEW_LIST = 'list';
export const VIEW_PRODUCT_FORM = 'product-form';

export const FORM_MODE_CREATE = 'create';
export const FORM_MODE_EDIT = 'edit';
export const FORM_MODE_READONLY = 'readonly';

export const FORM_STATUS_IDLE = 'idle';
export const FORM_STATUS_LOADING = 'loading';
export const FORM_STATUS_READY = 'ready';
export const FORM_STATUS_SAVING = 'saving';
export const FORM_STATUS_SUCCESS = 'success';
export const FORM_STATUS_ERROR = 'error';

const FORM_FIELDS = [
    'name',
    'sku',
    'description',
    'wooProductId',
    'categoryId',
    'brandId',
    'unitId',
    'imageId',
];

const ID_FIELDS = [
    'wooProductId',
    'categoryId',
    'brandId',
    'unitId',
    'imageId',
];

const PAYLOAD_FIELDS = {
    name: 'name',
    sku: 'sku',
    description: 'description',
    wooProductId: 'woo_product_id',
    categoryId: 'category_id',
    brandId: 'brand_id',
    unitId: 'unit_id',
    imageId: 'image_id',
};

/**
 * Crea el estado mínimo de la lista de productos.
 */
export function createProductsStore(api, catalogApi) {
    let state = {
        currentView: VIEW_LIST,
        status: STATUS_IDLE,
        inputTerm: '',
        query: {
            term: '',
            page: 1,
            perPage: 20,
        },
        products: [],
        meta: null,
        error: null,
        catalogs: createInitialCatalogsState(),
        form: createInitialFormState(),
    };
    let latestRequest = 0;
    let latestFormRequest = 0;
    let listNeedsReload = false;
    let catalogLoadPromise = null;
    const listeners = new Set();

    function getState() {
        return createSnapshot(state);
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('El listener del store debe ser una función.');
        }

        listeners.add(listener);

        return () => listeners.delete(listener);
    }

    function setState(nextState) {
        state = { ...state, ...nextState };
        listeners.forEach((listener) => listener(createSnapshot(state)));
    }

    function setInputTerm(term) {
        setState({
            inputTerm: typeof term === 'string' ? term : '',
        });
    }

    function loadCatalogs({ force = false } = {}) {
        if (catalogLoadPromise !== null) {
            return catalogLoadPromise;
        }

        const loaders = {
            categories: 'loadCategories',
            brands: 'loadBrands',
            units: 'loadUnits',
        };
        const catalogsToLoad = Object.keys(loaders).filter((catalog) => (
            force || state.catalogs[catalog].status === STATUS_IDLE
        ));

        if (catalogsToLoad.length === 0) {
            return Promise.resolve(getState().catalogs);
        }

        const catalogs = { ...state.catalogs };

        catalogsToLoad.forEach((catalog) => {
            catalogs[catalog] = {
                ...catalogs[catalog],
                status: STATUS_LOADING,
                error: null,
            };
        });
        setState({ catalogs });

        const loads = catalogsToLoad.map((catalog) => (
            Promise.resolve()
                .then(() => catalogApi[loaders[catalog]]())
                .then((response) => {
                    setCatalogState(catalog, {
                        data: response.data.map((item) => ({ ...item })),
                        status: STATUS_SUCCESS,
                        error: null,
                    });

                    return response;
                })
                .catch((error) => {
                    setCatalogState(catalog, {
                        data: [],
                        status: STATUS_ERROR,
                        error: normalizeError(error),
                    });

                    throw error;
                })
        ));

        catalogLoadPromise = Promise.allSettled(loads).finally(() => {
            catalogLoadPromise = null;
        });

        return catalogLoadPromise;
    }

    function setCatalogState(catalog, nextCatalog) {
        setState({
            catalogs: {
                ...state.catalogs,
                [catalog]: nextCatalog,
            },
        });
    }

    function search(term = state.inputTerm) {
        if (state.status === STATUS_LOADING) {
            return Promise.resolve();
        }

        const normalizedTerm = typeof term === 'string' ? term.trim() : '';

        return executeQuery(
            {
                ...state.query,
                term: normalizedTerm,
                page: 1,
            },
            normalizedTerm
        );
    }

    function reload() {
        if (state.status === STATUS_LOADING) {
            return Promise.resolve();
        }

        return executeQuery(state.query, state.inputTerm);
    }

    function goToPage(page) {
        if (state.status === STATUS_LOADING || !Number.isInteger(page)) {
            return Promise.resolve();
        }

        const totalPages = state.meta?.totalPages ?? 0;

        if (page < 1 || totalPages === 0 || page > totalPages) {
            return Promise.resolve();
        }

        return executeQuery(
            { ...state.query, page },
            state.inputTerm
        );
    }

    function openCreateForm({ force = false } = {}) {
        if (
            state.form.isSaving
            || (hasUnsavedChanges() && !force)
        ) {
            return false;
        }

        latestFormRequest++;
        const values = createEmptyFormValues();

        setState({
            currentView: VIEW_PRODUCT_FORM,
            form: {
                ...createInitialFormState(),
                mode: FORM_MODE_CREATE,
                status: FORM_STATUS_READY,
                values,
                initialValues: { ...values },
            },
        });

        return true;
    }

    async function openEditForm(id, { force = false } = {}) {
        if (
            state.form.isSaving
            || (hasUnsavedChanges() && !force)
        ) {
            return false;
        }

        const requestId = ++latestFormRequest;

        setState({
            currentView: VIEW_PRODUCT_FORM,
            form: {
                ...createInitialFormState(),
                mode: FORM_MODE_EDIT,
                status: FORM_STATUS_LOADING,
                productId: id,
            },
        });

        try {
            const response = await api.getProduct(id);

            if (requestId !== latestFormRequest) {
                return false;
            }

            setFormFromProduct(response.data, FORM_MODE_EDIT);

            return true;
        } catch (error) {
            if (requestId !== latestFormRequest) {
                return false;
            }

            setState({
                form: {
                    ...state.form,
                    status: FORM_STATUS_ERROR,
                    error: normalizeError(error),
                },
            });

            return false;
        }
    }

    function setFormField(field, value) {
        if (
            !FORM_FIELDS.includes(field)
            || state.currentView !== VIEW_PRODUCT_FORM
            || state.form.isSaving
            || state.form.status === FORM_STATUS_LOADING
            || state.form.mode === FORM_MODE_READONLY
        ) {
            return false;
        }

        const values = {
            ...state.form.values,
            [field]: value === null || value === undefined
                ? ''
                : String(value),
        };
        const fieldErrors = { ...state.form.fieldErrors };
        delete fieldErrors[field];

        setState({
            form: {
                ...state.form,
                status: FORM_STATUS_READY,
                values,
                fieldErrors,
                error: null,
                message: null,
            },
        });

        return true;
    }

    async function saveProduct() {
        if (
            state.currentView !== VIEW_PRODUCT_FORM
            || state.form.isSaving
            || state.form.status === FORM_STATUS_LOADING
            || state.form.mode === FORM_MODE_READONLY
            || ![FORM_MODE_CREATE, FORM_MODE_EDIT].includes(state.form.mode)
        ) {
            return false;
        }

        if (
            state.form.mode === FORM_MODE_EDIT
            && state.form.initialValues === null
        ) {
            setMissingProductDetailError();

            return false;
        }

        const validation = validateFormValues(state.form.values);

        if (Object.keys(validation.errors).length > 0) {
            setState({
                form: {
                    ...state.form,
                    status: FORM_STATUS_READY,
                    fieldErrors: validation.errors,
                    error: null,
                    message: null,
                },
            });

            return false;
        }

        if (state.form.mode === FORM_MODE_CREATE) {
            return createProduct(validation.payload);
        }

        const changes = buildChangedPayload(
            validation.payload,
            state.form.initialValues
        );

        if (Object.keys(changes).length === 0) {
            setState({
                form: {
                    ...state.form,
                    status: FORM_STATUS_READY,
                    fieldErrors: {},
                    error: null,
                    message: 'No hay cambios para guardar.',
                },
            });

            return true;
        }

        return updateProduct(changes, validation.payload);
    }

    async function changeProductStatus(status) {
        if (
            state.currentView !== VIEW_PRODUCT_FORM
            || state.form.isSaving
            || state.form.status === FORM_STATUS_LOADING
            || state.form.mode !== FORM_MODE_EDIT
            || !['active', 'inactive'].includes(status)
            || state.form.productId === null
        ) {
            return false;
        }

        if (state.form.initialValues === null) {
            setMissingProductDetailError();

            return false;
        }

        if (status === state.form.productStatus) {
            return true;
        }

        setFormSaving();

        try {
            const response = await api.updateProductStatus(
                state.form.productId,
                status
            );

            listNeedsReload = true;
            setState({
                form: {
                    ...state.form,
                    status: FORM_STATUS_SUCCESS,
                    productStatus: response.data.status,
                    error: null,
                    message: 'Estado del producto actualizado.',
                },
            });

            return true;
        } catch (error) {
            setFormOperationError(error);

            return false;
        } finally {
            finishFormSaving();
        }
    }

    async function returnToList({ force = false } = {}) {
        if (
            state.form.isSaving
            || (hasUnsavedChanges() && !force)
        ) {
            return false;
        }

        latestFormRequest++;
        const shouldReload = listNeedsReload;
        listNeedsReload = false;

        setState({
            currentView: VIEW_LIST,
            form: createInitialFormState(),
        });

        if (shouldReload) {
            await executeQuery(state.query, state.inputTerm);
        }

        return true;
    }

    async function executeQuery(query, inputTerm) {
        const requestId = ++latestRequest;

        setState({
            status: STATUS_LOADING,
            inputTerm,
            query: { ...query },
            products: [],
            meta: null,
            error: null,
        });

        try {
            const response = await api.getProducts(query);

            if (requestId !== latestRequest) {
                return;
            }

            if (
                response.meta.total > 0
                && response.meta.total_pages > 0
                && response.meta.page > response.meta.total_pages
            ) {
                return executeQuery(
                    { ...query, page: response.meta.total_pages },
                    inputTerm
                );
            }

            const products = response.data.map(normalizeProduct);

            setState({
                status: products.length === 0 ? STATUS_EMPTY : STATUS_SUCCESS,
                products,
                meta: normalizeMeta(response.meta),
                error: null,
            });
        } catch (error) {
            if (requestId !== latestRequest) {
                return;
            }

            setState({
                status: STATUS_ERROR,
                products: [],
                meta: null,
                error: normalizeError(error),
            });
        }
    }

    return {
        getState,
        subscribe,
        setInputTerm,
        loadCatalogs,
        search,
        reload,
        goToPage,
        openCreateForm,
        openEditForm,
        setFormField,
        saveProduct,
        changeProductStatus,
        returnToList,
    };

    async function createProduct(payload) {
        setFormSaving();

        try {
            const created = await api.createProduct(payload);
            const productId = created.data.id;

            listNeedsReload = true;

            try {
                const detail = await api.getProduct(productId);
                setFormFromProduct(
                    detail.data,
                    FORM_MODE_EDIT,
                    'Producto creado correctamente.'
                );

                return true;
            } catch (error) {
                const values = valuesFromPayload(payload);

                setState({
                    form: {
                        ...state.form,
                        mode: FORM_MODE_EDIT,
                        status: FORM_STATUS_ERROR,
                        productId,
                        values,
                        initialValues: { ...values },
                        productStatus: 'draft',
                        error: normalizeError(error),
                        message: 'El producto fue creado, pero no fue posible recargarlo.',
                    },
                });

                return false;
            }
        } catch (error) {
            setFormOperationError(error);

            return false;
        } finally {
            finishFormSaving();
        }
    }

    async function updateProduct(changes, normalizedPayload) {
        const productId = state.form.productId;
        setFormSaving();

        try {
            await api.updateProduct(productId, changes);
            listNeedsReload = true;

            try {
                const detail = await api.getProduct(productId);
                setFormFromProduct(
                    detail.data,
                    FORM_MODE_EDIT,
                    'Producto actualizado correctamente.'
                );

                return true;
            } catch (error) {
                const values = valuesFromPayload(normalizedPayload);

                setState({
                    form: {
                        ...state.form,
                        status: FORM_STATUS_ERROR,
                        values,
                        initialValues: { ...values },
                        error: normalizeError(error),
                        message: 'Los cambios fueron guardados, pero no fue posible recargar el producto.',
                    },
                });

                return false;
            }
        } catch (error) {
            setFormOperationError(error);

            return false;
        } finally {
            finishFormSaving();
        }
    }

    function setFormFromProduct(product, mode, message = null) {
        const values = normalizeProductDetail(product);

        setState({
            currentView: VIEW_PRODUCT_FORM,
            form: {
                ...createInitialFormState(),
                mode,
                status: message === null
                    ? FORM_STATUS_READY
                    : FORM_STATUS_SUCCESS,
                productId: product.id,
                values,
                initialValues: { ...values },
                productStatus: product.status,
                message,
            },
        });
    }

    function setFormSaving() {
        setState({
            form: {
                ...state.form,
                status: FORM_STATUS_SAVING,
                isSaving: true,
                fieldErrors: {},
                error: null,
                message: null,
            },
        });
    }

    function finishFormSaving() {
        if (!state.form.isSaving) {
            return;
        }

        setState({
            form: {
                ...state.form,
                isSaving: false,
            },
        });
    }

    function setFormOperationError(error) {
        const normalizedError = normalizeError(error);
        const fieldErrors = fieldErrorsFromSaveError(normalizedError);
        const isValidationError = Object.keys(fieldErrors).length > 0
            || normalizedError.status === 422;

        setState({
            form: {
                ...state.form,
                status: FORM_STATUS_ERROR,
                fieldErrors,
                error: isValidationError
                    ? normalizedError
                    : {
                        ...normalizedError,
                        message: 'No fue posible guardar el producto. Inténtelo nuevamente.',
                    },
                message: null,
            },
        });
    }

    function setMissingProductDetailError() {
        setState({
            form: {
                ...state.form,
                status: FORM_STATUS_ERROR,
                error: {
                    type: 'invalid_state',
                    status: null,
                    code: 'product_detail_not_loaded',
                    message: 'No se cargó el detalle del producto. Vuelve a intentarlo.',
                    retryable: true,
                },
                message: null,
            },
        });
    }

    function hasUnsavedChanges() {
        return state.currentView === VIEW_PRODUCT_FORM
            && state.form.initialValues !== null
            && !formValuesEqual(
                state.form.values,
                state.form.initialValues
            );
    }
}

function createInitialCatalogsState() {
    return {
        categories: createInitialCatalogState(),
        brands: createInitialCatalogState(),
        units: createInitialCatalogState(),
    };
}

function createInitialCatalogState() {
    return {
        data: [],
        status: STATUS_IDLE,
        error: null,
    };
}

function createInitialFormState() {
    return {
        mode: FORM_MODE_CREATE,
        status: FORM_STATUS_IDLE,
        isSaving: false,
        productId: null,
        values: createEmptyFormValues(),
        initialValues: null,
        productStatus: 'draft',
        fieldErrors: {},
        error: null,
        message: null,
    };
}

function createEmptyFormValues() {
    return {
        name: '',
        sku: '',
        description: '',
        wooProductId: '',
        categoryId: '',
        brandId: '',
        unitId: '',
        imageId: '',
    };
}

function normalizeProductDetail(product) {
    return {
        name: textValue(product.name),
        sku: textValue(product.sku),
        description: textValue(product.description),
        wooProductId: textValue(product.woo_product_id),
        categoryId: textValue(product.category_id),
        brandId: textValue(product.brand_id),
        unitId: textValue(product.unit_id),
        imageId: textValue(product.image_id),
    };
}

function validateFormValues(values) {
    const errors = {};
    const name = values.name.trim();
    const sku = values.sku.trim();
    const description = values.description.trim();

    if (name === '') {
        errors.name = 'El nombre del producto es obligatorio.';
    } else if (textLength(name) > 180) {
        errors.name = 'El nombre del producto supera el máximo de 180 caracteres.';
    }

    if (textLength(sku) > 100) {
        errors.sku = 'El SKU supera el máximo de 100 caracteres.';
    }

    const payload = {
        name,
        sku: sku === '' ? null : sku,
        description: description === '' ? null : description,
    };

    ID_FIELDS.forEach((field) => {
        const value = values[field].trim();

        if (value === '') {
            payload[PAYLOAD_FIELDS[field]] = null;
            return;
        }

        if (!/^[1-9]\d*$/.test(value)) {
            errors[field] = 'El valor debe ser un entero positivo.';
            return;
        }

        const number = Number(value);

        if (!Number.isSafeInteger(number)) {
            errors[field] = 'El valor supera el máximo permitido.';
            return;
        }

        payload[PAYLOAD_FIELDS[field]] = number;
    });

    return { errors, payload };
}

function buildChangedPayload(currentPayload, initialValues) {
    const initial = validateFormValues(
        initialValues ?? createEmptyFormValues()
    ).payload;
    const changes = {};

    Object.keys(currentPayload).forEach((field) => {
        if (currentPayload[field] !== initial[field]) {
            changes[field] = currentPayload[field];
        }
    });

    return changes;
}

function valuesFromPayload(payload) {
    const values = createEmptyFormValues();

    Object.entries(PAYLOAD_FIELDS).forEach(([formField, payloadField]) => {
        values[formField] = textValue(payload[payloadField]);
    });

    return values;
}

function formValuesEqual(first, second) {
    if (second === null) {
        return false;
    }

    return FORM_FIELDS.every((field) => (
        comparableValue(field, first[field])
        === comparableValue(field, second[field])
    ));
}

function comparableValue(field, value) {
    const normalized = textValue(value).trim();

    if (!ID_FIELDS.includes(field) || normalized === '') {
        return normalized;
    }

    if (!/^\d+$/.test(normalized)) {
        return normalized;
    }

    return normalized.replace(/^0+(?=\d)/, '');
}

function textValue(value) {
    return value === null || value === undefined
        ? ''
        : String(value);
}

function textLength(value) {
    return Array.from(value).length;
}

function normalizeProduct(product) {
    return {
        id: displayValue(product.id),
        name: displayValue(product.name),
        sku: displayValue(product.sku),
        status: displayValue(product.status),
        updatedAt: displayValue(product.updated_at),
    };
}

function normalizeMeta(meta) {
    return {
        page: meta.page,
        perPage: meta.per_page,
        total: meta.total,
        totalPages: meta.total_pages,
    };
}

function normalizeError(error) {
    if (error && typeof error === 'object') {
        return {
            type: typeof error.type === 'string' ? error.type : 'unknown',
            status: Number.isInteger(error.status) ? error.status : null,
            code: typeof error.code === 'string' ? error.code : 'unknown_error',
            message: typeof error.message === 'string' && error.message !== ''
                ? error.message
                : 'No se pudo completar la operación.',
            retryable: error.retryable === true,
        };
    }

    return {
        type: 'unknown',
        status: null,
        code: 'unknown_error',
        message: 'No se pudo completar la operación.',
        retryable: false,
    };
}

function fieldErrorsFromSaveError(error) {
    const fieldsByCode = {
        invalid_category_id: 'categoryId',
        invalid_brand_id: 'brandId',
        invalid_unit_id: 'unitId',
        invalid_image_id: 'imageId',
    };
    const field = fieldsByCode[error.code]
        ?? validationFieldFromMessage(error.message);

    return field === null
        ? {}
        : { [field]: error.message };
}

function validationFieldFromMessage(message) {
    if (typeof message !== 'string') {
        return null;
    }

    const normalized = message.toLocaleLowerCase('es');
    const fieldsByText = [
        ['nombre', 'name'],
        ['sku', 'sku'],
        ['descripción', 'description'],
        ['woocommerce', 'wooProductId'],
        ['categoría', 'categoryId'],
        ['marca', 'brandId'],
        ['unidad', 'unitId'],
        ['imagen', 'imageId'],
    ];
    const match = fieldsByText.find(([text]) => normalized.includes(text));

    return match?.[1] ?? null;
}

function displayValue(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}

function createSnapshot(state) {
    return {
        ...state,
        query: { ...state.query },
        products: state.products.map((product) => ({ ...product })),
        meta: state.meta === null ? null : { ...state.meta },
        error: state.error === null ? null : { ...state.error },
        catalogs: Object.fromEntries(
            Object.entries(state.catalogs).map(([catalog, catalogState]) => [
                catalog,
                {
                    ...catalogState,
                    data: catalogState.data.map((item) => ({ ...item })),
                    error: catalogState.error === null
                        ? null
                        : { ...catalogState.error },
                },
            ])
        ),
        form: {
            ...state.form,
            hasUnsavedChanges: state.currentView === VIEW_PRODUCT_FORM
                && state.form.initialValues !== null
                && !formValuesEqual(
                    state.form.values,
                    state.form.initialValues
                ),
            values: { ...state.form.values },
            initialValues: state.form.initialValues === null
                ? null
                : { ...state.form.initialValues },
            fieldErrors: { ...state.form.fieldErrors },
            error: state.form.error === null
                ? null
                : { ...state.form.error },
        },
    };
}
