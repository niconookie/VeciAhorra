import { createStoreDetailApi } from './detail-api.js';
import { validateDetailPayload } from './detail-contract.js';
import { createStoreDetailView, detailErrorMessage } from './detail-view.js';
import { captureEditSnapshot, readEditPayload, validateEditPayload, editableFields } from './detail-edit.js';
import { isLifecycleAction, lifecycleActions, transitionPayload } from './detail-lifecycle.js';

const root = document.querySelector('[data-va-store-detail]');

if (root && root.dataset.vaStoreDetailInitialized !== 'true') {
    initialize(root);
}

function initialize(rootNode) {
    const configNode = document.getElementById('va-store-detail-config');
    let view = null;

    try {
        view = createStoreDetailView(rootNode);
        const config = readConfig(configNode);
        rootNode.dataset.vaStoreDetailInitialized = 'true';

        window.VeciAhorra = window.VeciAhorra || {};
        window.VeciAhorra.stores = window.VeciAhorra.stores || {};
        window.VeciAhorra.stores.detail = Object.assign(
            window.VeciAhorra.stores.detail || {},
            config
        );

        const api = createStoreDetailApi(config);
        const coordinator = createStoreDetailCoordinator({
            rootNode,
            config,
            api,
            view,
        });

        rootNode.dataset.vaStoreId = String(config.storeId);
        window.addEventListener('pagehide', coordinator.destroy, { once: true });
        rootNode.addEventListener('click', coordinator.click);
        rootNode.addEventListener('submit', coordinator.submit);
        coordinator.load();
    } catch (error) {
        if (view) {
            view.error('No fue posible preparar el detalle del minimarket.');
        } else {
            rootNode.dataset.vaStoreDetailState = 'error';
            rootNode.setAttribute('aria-busy', 'false');
        }
    }
}

export function createStoreDetailCoordinator({ rootNode, config, api, view }) {
    let readSequence = 0;
    let saveSequence = 0;
    let active = true;
    let mode = 'loading';
    let dto = null;
    let snapshot = null;
    let readController = null;
    let saveController = null;
    let transitionController = null;
    let transitionSequence = 0;
    let pendingAction = null;

    const load = () => {
        if (!active) return;
        transitionSequence++;
        transitionController?.abort();
        pendingAction = null;
        readController?.abort();
        readController = new AbortController();
        const requestId = ++readSequence;
        mode = 'loading';
        view.loading();

        api.get(readController.signal)
            .then((payload) => {
                if (!active || requestId !== readSequence || !rootNode.isConnected) return;
                dto = validateDetailPayload(payload, config.storeId);
                mode = 'reading';
                view.render(dto);
            })
            .catch((error) => {
                if (
                    !active
                    || requestId !== readSequence
                    || error?.name === 'AbortError'
                    || !rootNode.isConnected
                ) return;
                mode = 'error';
                view.error(detailErrorMessage(error));
            });
    };

    const enterEdit = () => {
        if (mode !== 'reading' || !dto?.allowed_actions.includes('save')) return;
        snapshot = captureEditSnapshot(dto);
        mode = 'editing';
        view.edit(snapshot);
    };

    const cancel = () => {
        if (mode !== 'editing' || !dto) return;
        snapshot = null;
        mode = 'reading';
        view.render(dto, { focusEdit: true });
    };

    const submit = (event) => {
        if (!event.target?.matches?.('[data-va-store-edit-form]')) return;
        event.preventDefault();
        if (mode !== 'editing' || !snapshot || !dto?.allowed_actions.includes('save')) return;
        const payload = readEditPayload(event.target);
        const errors = validateEditPayload(payload);
        if (Object.keys(errors).length > 0) {
            view.editErrors(errors);
            return;
        }
        mode = 'saving';
        view.editErrors({});
        view.saving(true);
        saveController?.abort();
        saveController = new AbortController();
        const operationId = ++saveSequence;
        const baseDto = dto;
        let persisted = false;
        api.update(payload, saveController.signal)
            .then((result) => {
                if (!isCurrentSave(operationId, baseDto)) return null;
                if (result?.success !== true || result?.data?.updated !== true) throw new TypeError('invalid_update_response');
                persisted = true;
                readController?.abort();
                readController = new AbortController();
                const refreshId = ++readSequence;
                return api.get(readController.signal).then((response) => ({ response, refreshId }));
            })
            .then((refresh) => {
                if (!refresh || !isCurrentSave(operationId, baseDto) || refresh.refreshId !== readSequence) return;
                dto = validateDetailPayload(refresh.response, config.storeId);
                snapshot = null;
                mode = 'reading';
                view.render(dto, { success: 'Información del minimarket actualizada.' });
            })
            .catch((error) => {
                if (!isCurrentSave(operationId, baseDto) || error?.name === 'AbortError') return;
                if (persisted) {
                    mode = 'error';
                    view.persistedRefreshError('La información fue enviada, pero no fue posible recargar el estado actualizado. Recarga la página antes de continuar.');
                    return;
                }
                mode = 'editing';
                view.saving(false);
                const fields = safeFieldErrors(error);
                view.editErrors(fields, updateErrorMessage(error, Object.keys(fields).length > 0));
            });
    };

    const isCurrentSave = (operationId, baseDto) => active
        && operationId === saveSequence && dto === baseDto && rootNode.isConnected;

    const openLifecycle = (action) => {
        if (mode !== 'reading' || !dto || !isLifecycleAction(action) || !dto.allowed_actions.includes(action)) return;
        pendingAction = action;
        mode = 'confirming';
        view.confirmLifecycle(action);
    };

    const cancelLifecycle = () => {
        if (mode !== 'confirming' || !dto || !pendingAction) return;
        const action = pendingAction;
        pendingAction = null;
        mode = 'reading';
        view.render(dto, { focusLifecycle: action });
    };

    const confirmLifecycle = () => {
        if (mode !== 'confirming' || !dto || !pendingAction || !dto.allowed_actions.includes(pendingAction)) return;
        const action = pendingAction;
        transitionPayload(action);
        const baseDto = dto;
        const operationId = ++transitionSequence;
        let processed = false;
        mode = 'transitioning';
        view.transitioning(true);
        transitionController?.abort();
        transitionController = new AbortController();
        api.transition(action, transitionController.signal)
            .then((result) => {
                if (!isCurrentTransition(operationId, baseDto, action)) return null;
                validateDetailPayload(result, config.storeId);
                processed = true;
                return authoritativeTransitionGet(operationId, baseDto, action);
            })
            .then((refresh) => {
                if (!refresh || !isCurrentTransition(operationId, baseDto, action)) return;
                dto = validateDetailPayload(refresh, config.storeId);
                pendingAction = null;
                mode = 'reading';
                view.render(dto, { success: lifecycleActions[action].success });
            })
            .catch((error) => {
                if (!isCurrentTransition(operationId, baseDto, action) || error?.name === 'AbortError') return;
                if (processed) {
                    mode = 'error'; pendingAction = null;
                    view.error('La acción fue procesada, pero no fue posible recargar el estado actualizado. Recarga la página antes de continuar.');
                    return;
                }
                if (error?.status === 409) {
                    authoritativeTransitionGet(operationId, baseDto, action)
                        .then((refresh) => {
                            if (!isCurrentTransition(operationId, baseDto, action)) return;
                            dto = validateDetailPayload(refresh, config.storeId);
                            pendingAction = null; mode = 'reading';
                            view.render(dto, { warning: 'El estado del minimarket cambió mientras realizabas la acción. Se recargó la información vigente.' });
                        })
                        .catch((refreshError) => {
                            if (!isCurrentTransition(operationId, baseDto, action) || refreshError?.name === 'AbortError') return;
                            pendingAction = null; mode = 'error';
                            view.error('El estado cambió y no fue posible recargar la información vigente. Recarga la página.');
                        });
                    return;
                }
                if (isUncertainTransition(error)) {
                    pendingAction = null;
                    mode = 'error';
                    view.error('No fue posible confirmar el resultado de la acción. Recarga la página antes de volver a intentarlo.');
                    return;
                }
                pendingAction = null;
                if (error?.status === 404) {
                    mode = 'error';
                    view.error(transitionErrorMessage(error));
                } else {
                    mode = 'reading';
                    view.render(dto, { error: transitionErrorMessage(error) });
                }
            });
    };

    const authoritativeTransitionGet = (operationId, baseDto, action) => {
        if (!isCurrentTransition(operationId, baseDto, action)) return Promise.resolve(null);
        readController?.abort();
        readController = new AbortController();
        ++readSequence;
        return api.get(readController.signal);
    };

    const isCurrentTransition = (operationId, baseDto, action) => active
        && operationId === transitionSequence && dto === baseDto
        && pendingAction === action && rootNode.isConnected;

    const click = (event) => {
        if (event.target?.closest?.('[data-va-store-edit]')) enterEdit();
        else if (event.target?.closest?.('[data-va-store-cancel-edit]')) cancel();
        else if (event.target?.closest?.('[data-va-store-confirm-lifecycle]')) confirmLifecycle();
        else if (event.target?.closest?.('[data-va-store-cancel-lifecycle]')) cancelLifecycle();
        else {
            const action = event.target?.closest?.('[data-va-store-lifecycle-action]')?.dataset.vaStoreLifecycleAction;
            if (action) openLifecycle(action);
        }
    };

    const destroy = () => {
        if (!active) return;
        active = false;
        mode = 'abandoned';
        readSequence++;
        saveSequence++;
        transitionSequence++;
        readController?.abort();
        saveController?.abort();
        transitionController?.abort();
        rootNode.removeEventListener?.('click', click);
        rootNode.removeEventListener?.('submit', submit);
    };

    return Object.freeze({ load, destroy, click, submit, enterEdit, cancel, openLifecycle, cancelLifecycle, confirmLifecycle });
}

function transitionErrorMessage(error) {
    const messages = {
        0: 'No fue posible conectar para procesar la acción lifecycle.',
        400: 'La solicitud de transición no es válida.',
        401: 'La sesión expiró. Revisa tu sesión antes de volver a intentar.',
        403: 'No tienes permisos o la autorización REST expiró.',
        404: 'El minimarket ya no existe o no está disponible.',
        422: 'La acción lifecycle no puede aplicarse al estado actual.',
    };
    return messages[error?.status] || 'No fue posible procesar la acción lifecycle.';
}

function isUncertainTransition(error) {
    return error?.status === 0
        || (Number.isInteger(error?.status) && error.status >= 200 && error.status < 300)
        || error instanceof TypeError;
}

function safeFieldErrors(error) {
    const fields = error?.status === 422 && error?.data?.data?.code === 'validation_error'
        ? error.data.data.fields : null;
    if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return {};
    const allowed = new Set(editableFields.map(({ name }) => name));
    const codes = new Set(['required', 'invalid_email', 'too_long']);
    return Object.fromEntries(Object.entries(fields).filter(([field, code]) => allowed.has(field) && codes.has(code)));
}

function updateErrorMessage(error, hasFields) {
    if (hasFields) return 'Revisa los campos indicados.';
    const messages = {
        0: 'No fue posible conectar para guardar. Tus cambios permanecen en el formulario.',
        400: 'La solicitud de guardado no es válida.',
        401: 'La sesión expiró. Revisa tu sesión antes de volver a intentar.',
        403: 'No tienes permisos o la autorización expiró. Tus cambios no se han descartado.',
        404: 'El minimarket ya no existe. Tus cambios permanecen visibles para que puedas revisarlos.',
        409: 'Los datos cambiaron en el servidor. Cancela y recarga la página antes de decidir cómo continuar.',
        422: 'No fue posible validar la información enviada.',
    };
    return messages[error?.status] || 'No fue posible guardar los cambios. Inténtalo nuevamente sin recargar la página.';
}

function readConfig(configNode) {
    if (!configNode) throw new Error('missing_detail_config');
    const parsed = JSON.parse(configNode.textContent || '{}');
    const config = window.location.protocol === 'file:'
        ? { updateUrl: './admin.php?page=veciahorra-store-edit', updateNonce: 'browser-harness', ...parsed }
        : parsed;
    if (
        config.enabled !== true
        || !Number.isSafeInteger(config.storeId)
        || config.storeId <= 0
        || typeof config.nonce !== 'string'
        || config.nonce.trim() === ''
        || typeof config.updateNonce !== 'string'
        || config.updateNonce.trim() === ''
    ) {
        throw new Error('invalid_detail_config');
    }
    const detailUrl = new URL(config.detailUrl, window.location.href);
    const returnUrl = new URL(config.returnUrl, window.location.href);
    const updateUrl = new URL(config.updateUrl, window.location.href);
    const expectedSuffix = `/stores/${config.storeId}`;
    const allowedProtocols = window.location.protocol === 'file:'
        ? ['file:']
        : ['http:', 'https:'];
    if (
        !allowedProtocols.includes(detailUrl.protocol)
        || detailUrl.origin !== window.location.origin
        || !detailUrl.pathname.endsWith(expectedSuffix)
        || detailUrl.search !== ''
        || detailUrl.hash !== ''
        || !allowedProtocols.includes(returnUrl.protocol)
        || returnUrl.origin !== window.location.origin
        || detailUrl.username !== ''
        || detailUrl.password !== ''
        || returnUrl.username !== ''
        || returnUrl.password !== ''
        || !allowedProtocols.includes(updateUrl.protocol)
        || updateUrl.origin !== window.location.origin
        || !updateUrl.pathname.endsWith('/admin.php')
        || updateUrl.searchParams.get('page') !== 'veciahorra-store-edit'
        || [...updateUrl.searchParams.keys()].some((key) => key !== 'page')
        || updateUrl.hash !== ''
        || updateUrl.username !== ''
        || updateUrl.password !== ''
    ) {
        throw new Error('invalid_detail_urls');
    }
    return Object.freeze({ ...config, detailUrl: detailUrl.toString(), updateUrl: updateUrl.toString(), returnUrl: returnUrl.toString() });
}
