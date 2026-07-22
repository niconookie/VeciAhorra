import { actionLabels, lifecyclePresentation } from './detail-contract.js';
import { editableFields, fieldErrorMessage } from './detail-edit.js';

const placeholder = (value, empty = 'No informado') => (
    typeof value === 'string' && value.trim() !== '' ? value : empty
);

const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
};

const definitionList = (entries) => {
    const list = node('dl', undefined, 'va-store-detail__definitions');
    entries.forEach(([term, value]) => {
        list.append(node('dt', term), node('dd', value));
    });
    return list;
};

const location = (item) => [item.commune, item.city, item.region]
    .filter((value) => typeof value === 'string' && value.trim() !== '')
    .join(', ') || 'No informada';

export function createStoreDetailView(root) {
    const nodes = {
        heading: root.querySelector('h1'),
        identity: root.querySelector('[data-va-store-detail-identity]'),
        badge: root.querySelector('[data-va-store-detail-badge]'),
        messages: root.querySelector('[data-va-store-detail-messages]'),
        main: root.querySelector('[data-va-store-detail-main]'),
        summary: root.querySelector('[data-va-store-detail-summary]'),
        lifecycle: root.querySelector('[data-va-store-detail-lifecycle]'),
        commercial: root.querySelector('[data-va-store-detail-commercial]'),
        actions: root.querySelector('[data-va-store-detail-actions]'),
        sensitive: root.querySelector('[data-va-store-detail-sensitive]'),
    };
    if (Object.values(nodes).some((value) => !value)) throw new Error('missing_detail_nodes');

    const clearData = () => {
        nodes.badge.replaceChildren();
        nodes.summary.replaceChildren();
        nodes.lifecycle.replaceChildren();
        nodes.commercial.replaceChildren();
        nodes.actions.replaceChildren();
        nodes.sensitive.replaceChildren();
    };

    return Object.freeze({
        loading() {
            clearData();
            root.dataset.vaStoreDetailState = 'loading';
            root.setAttribute('aria-busy', 'true');
            nodes.messages.replaceChildren(node('p', 'Cargando minimarket…', 'va-store-detail__loading'));
        },
        error(message) {
            clearData();
            root.dataset.vaStoreDetailState = 'error';
            root.setAttribute('aria-busy', 'false');
            const notice = node('div', undefined, 'notice notice-error inline');
            notice.setAttribute('role', 'alert');
            notice.append(node('p', message));
            nodes.messages.replaceChildren(notice);
            nodes.messages.focus({ preventScroll: true });
        },
        render(item, options = {}) {
            const presentation = lifecyclePresentation[item.lifecycle_state];
            nodes.heading.textContent = item.business_name;
            nodes.identity.textContent = [placeholder(item.rut, ''), placeholder(item.legal_name, '')]
                .filter(Boolean).join(' · ') || `Minimarket ID ${item.id}`;
            const badge = node('span', presentation.label, `va-store-detail__badge va-store-detail__badge--${presentation.tone}`);
            nodes.badge.replaceChildren(badge);
            nodes.summary.replaceChildren(definitionList([
                ['Razón social', placeholder(item.legal_name)],
                ['RUT', placeholder(item.rut)],
                ['Representante', item.owner_name],
                ['Contacto', item.email],
                ['Teléfonos', [item.phone, item.mobile].filter((value) => value?.trim()).join(' · ') || 'No informados'],
                ['Ubicación', location(item)],
                ['Creación', item.created_at],
                ['Última actualización', item.updated_at],
                ['Aprobación', item.approved_at ?? 'Sin fecha de aprobación'],
            ]));
            const lifecycleContent = document.createDocumentFragment();
            lifecycleContent.append(node('p', presentation.description));
            if (item.lifecycle_state === 'invalid') {
                const alert = node('div', undefined, 'notice notice-warning inline va-store-detail__invalid');
                alert.setAttribute('role', 'alert');
                alert.append(node('p', 'La combinación contractual persistida es inconsistente. No se realizó ninguna corrección.'));
                lifecycleContent.append(alert);
            }
            lifecycleContent.append(definitionList([
                ['Lifecycle', presentation.label],
                ['Estado operativo', item.status],
                ['Estado de incorporación', item.onboarding_status],
                ['Aprobación', item.approved_at ?? 'Sin fecha de aprobación'],
            ]));
            nodes.lifecycle.replaceChildren(lifecycleContent);
            const commercialContent = document.createDocumentFragment();
            commercialContent.append(definitionList([
                ['Nombre comercial', item.business_name],
                ['Razón social', placeholder(item.legal_name)],
                ['Representante', item.owner_name],
                ['RUT', placeholder(item.rut)],
                ['Correo', item.email],
                ['Teléfono', placeholder(item.phone)],
                ['Móvil', placeholder(item.mobile)],
                ['Dirección', placeholder(item.address)],
                ['Comuna', placeholder(item.commune)],
                ['Ciudad', placeholder(item.city)],
                ['Región', placeholder(item.region)],
            ]));
            if (item.allowed_actions.includes('save')) {
                const controls = node('div', undefined, 'va-store-detail__commercial-actions');
                const edit = node('button', 'Editar información', 'button button-secondary');
                edit.type = 'button';
                edit.dataset.vaStoreEdit = 'true';
                controls.append(edit);
                commercialContent.append(controls);
            }
            nodes.commercial.replaceChildren(commercialContent);
            const actionItems = item.allowed_actions.map((action) => node('li', actionLabels[action]));
            if (actionItems.length === 0) {
                nodes.actions.replaceChildren(node('p', 'No hay acciones contractuales disponibles.'));
            } else {
                const introduction = node('p', 'Acciones contractuales disponibles (sólo lectura):');
                const list = node('ul');
                list.append(...actionItems);
                nodes.actions.replaceChildren(introduction, list);
            }
            nodes.sensitive.replaceChildren(node('p', 'No hay controles sensibles disponibles en esta etapa.'));
            nodes.messages.replaceChildren();
            if (options.success) {
                const notice = node('div', undefined, 'notice notice-success inline');
                notice.setAttribute('role', 'status');
                notice.append(node('p', options.success));
                nodes.messages.replaceChildren(notice);
                nodes.messages.focus({ preventScroll: true });
            } else if (options.focusEdit) {
                nodes.commercial.querySelector('[data-va-store-edit]')?.focus({ preventScroll: true });
            }
            root.dataset.vaStoreDetailState = 'loaded';
            root.setAttribute('aria-busy', 'false');
        },
        edit(snapshot) {
            const form = node('form', undefined, 'va-store-detail__form');
            form.dataset.vaStoreEditForm = 'true';
            form.noValidate = true;
            const heading = node('h3', 'Editar información comercial', 'va-store-detail__form-heading');
            heading.id = 'va-store-detail-edit-heading';
            form.setAttribute('aria-labelledby', heading.id);
            const globalError = node('div', undefined, 'va-store-detail__form-alert');
            globalError.dataset.vaStoreEditError = 'true';
            globalError.setAttribute('role', 'alert');
            globalError.tabIndex = -1;
            const grid = node('div', undefined, 'va-store-detail__form-grid');
            editableFields.forEach((definition, index) => {
                const group = node('div', undefined, 'va-store-detail__field');
                const id = `va-store-detail-field-${definition.name}`;
                const errorId = `${id}-error`;
                const label = node('label', `${definition.label}${definition.required ? ' *' : ''}`);
                label.htmlFor = id;
                const control = node('input');
                control.id = id; control.name = definition.name; control.type = definition.type;
                control.maxLength = definition.maxLength; control.required = definition.required;
                control.autocomplete = definition.autocomplete; control.value = snapshot[definition.name];
                control.setAttribute('aria-describedby', errorId);
                const error = node('p', '', 'va-store-detail__field-error');
                error.id = errorId; error.dataset.vaStoreFieldError = definition.name;
                group.append(label, control, error); grid.append(group);
                if (index === 0) control.dataset.vaStoreFirstField = 'true';
            });
            const actions = node('div', undefined, 'va-store-detail__form-actions');
            const save = node('button', 'Guardar cambios', 'button button-primary'); save.type = 'submit';
            const cancel = node('button', 'Cancelar', 'button'); cancel.type = 'button'; cancel.dataset.vaStoreCancelEdit = 'true';
            const progress = node('span', '', 'va-store-detail__saving'); progress.dataset.vaStoreSaving = 'true'; progress.setAttribute('role', 'status');
            actions.append(save, cancel, progress);
            form.append(heading, globalError, grid, actions);
            nodes.commercial.replaceChildren(form);
            root.dataset.vaStoreDetailState = 'editing';
            nodes.commercial.querySelector('[data-va-store-first-field]')?.focus({ preventScroll: true });
        },
        editErrors(errors, globalMessage = '') {
            editableFields.forEach(({ name }) => {
                const control = nodes.commercial.querySelector(`[name="${name}"]`);
                const region = nodes.commercial.querySelector(`[data-va-store-field-error="${name}"]`);
                const message = errors[name] ? fieldErrorMessage(name, errors[name]) : '';
                control?.setAttribute('aria-invalid', message ? 'true' : 'false');
                if (region) region.textContent = message || '';
            });
            const alert = nodes.commercial.querySelector('[data-va-store-edit-error]');
            if (alert) alert.textContent = globalMessage;
            const firstInvalid = editableFields.map(({ name }) => nodes.commercial.querySelector(`[name="${name}"][aria-invalid="true"]`)).find(Boolean);
            (firstInvalid || (globalMessage ? alert : null))?.focus({ preventScroll: true });
        },
        saving(active) {
            root.dataset.vaStoreDetailState = active ? 'saving' : 'editing';
            root.setAttribute('aria-busy', active ? 'true' : 'false');
            nodes.commercial.querySelectorAll('input, button').forEach((control) => { control.disabled = active; });
            const progress = nodes.commercial.querySelector('[data-va-store-saving]');
            if (progress) progress.textContent = active ? 'Guardando cambios…' : '';
        },
        persistedRefreshError(message) {
            root.dataset.vaStoreDetailState = 'error';
            root.setAttribute('aria-busy', 'false');
            const alert = nodes.commercial.querySelector('[data-va-store-edit-error]');
            if (alert) {
                alert.textContent = message;
                alert.focus({ preventScroll: true });
            }
            const progress = nodes.commercial.querySelector('[data-va-store-saving]');
            if (progress) progress.textContent = '';
        },
        form() { return nodes.commercial.querySelector('[data-va-store-edit-form]'); },
    });
}

export function detailErrorMessage(error) {
    const messages = {
        0: 'No fue posible conectar para cargar el minimarket. Recarga la página para intentarlo nuevamente.',
        400: 'La solicitud del detalle no es válida.',
        401: 'La sesión expiró o no está autenticada. Recarga la página o vuelve a iniciar sesión.',
        403: 'No tienes permisos o la autorización de la página expiró.',
        404: 'El minimarket ya no existe o no está disponible.',
        409: 'El estado del minimarket cambió. Recarga la página para obtener información vigente.',
        422: 'El estado contractual del minimarket no puede mostrarse.',
    };
    return messages[error?.status] || 'No fue posible cargar el detalle del minimarket.';
}
