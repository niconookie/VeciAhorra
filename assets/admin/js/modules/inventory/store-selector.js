const MINIMUM_TERM_LENGTH = 2;
const DEBOUNCE_MS = 300;
const STORE_STATUSES = ['pending', 'active', 'inactive', 'rejected'];
const initializedInputs = new WeakMap();
let instanceSequence = 0;

export function createStoreSelector({
    searchStores,
    onStoreSelected,
    elements,
}) {
    assertDependencies(searchStores, elements);

    if (initializedInputs.has(elements.input)) {
        return initializedInputs.get(elements.input);
    }

    const { input, results, status } = elements;
    const document = input.ownerDocument;
    const instanceId = `veciahorra-inventory-store-${++instanceSequence}`;
    const listboxId = `${instanceId}-results`;
    let timer = null;
    let requestSequence = 0;
    let controller = null;
    let currentTerm = '';
    let options = [];
    let activeIndex = -1;
    let composing = false;
    let destroyed = false;

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listboxId);
    input.setAttribute('autocomplete', 'off');
    results.id = listboxId;
    results.setAttribute('role', 'listbox');
    results.hidden = true;
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = 'Escriba al menos 2 caracteres para buscar minimarkets.';

    const onCompositionStart = () => { composing = true; };
    const onCompositionEnd = () => {
        composing = false;
        schedule(input.value);
    };
    const onInput = () => {
        if (!composing) schedule(input.value);
    };
    const onKeydown = (event) => handleKeydown(event);
    const onFocusOut = () => {
        queueMicrotask(() => {
            if (!destroyed && !containsFocus()) closeResults();
        });
    };

    input.addEventListener('compositionstart', onCompositionStart);
    input.addEventListener('compositionend', onCompositionEnd);
    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);
    input.addEventListener('focusout', onFocusOut);

    function containsFocus() {
        return input === document.activeElement || results.contains(document.activeElement);
    }

    function schedule(value) {
        if (destroyed) return;
        const term = String(value ?? '').trim();

        if (term === currentTerm && (timer !== null || controller !== null || options.length > 0)) {
            return;
        }

        invalidatePending();
        currentTerm = term;
        options = [];
        activeIndex = -1;
        closeResults();
        status.setAttribute('role', 'status');

        if (term === '') {
            status.textContent = 'Escriba al menos 2 caracteres para buscar minimarkets.';
            return;
        }

        if (term.length < MINIMUM_TERM_LENGTH) {
            status.textContent = 'La busqueda requiere al menos 2 caracteres.';
            return;
        }

        status.textContent = 'Esperando para buscar minimarkets.';
        timer = window.setTimeout(() => search(term), DEBOUNCE_MS);
    }

    async function search(term) {
        timer = null;
        const sequence = ++requestSequence;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const signal = controller?.signal;
        status.setAttribute('role', 'status');
        status.textContent = 'Buscando minimarkets.';
        input.setAttribute('aria-busy', 'true');
        results.setAttribute('aria-busy', 'true');

        try {
            const response = await searchStores(term, { signal });

            if (!isCurrent(sequence, term)) return;
            options = normalizeStores(response.data);
            activeIndex = options.length > 0 ? 0 : -1;
            renderResults();
            status.setAttribute('role', 'status');
            status.textContent = options.length === 0
                ? 'No se encontraron minimarkets para la consulta.'
                : `${options.length} minimarkets encontrados.`;
        } catch (error) {
            if (isAbortError(error) || !isCurrent(sequence, term)) return;
            options = [];
            activeIndex = -1;
            closeResults();
            status.setAttribute('role', 'alert');
            status.textContent = 'No fue posible buscar minimarkets. Intente nuevamente.';
        } finally {
            if (sequence === requestSequence && !destroyed) {
                controller = null;
                input.removeAttribute('aria-busy');
                results.removeAttribute('aria-busy');
            }
        }
    }

    function isCurrent(sequence, term) {
        return !destroyed && sequence === requestSequence && term === currentTerm;
    }

    function renderResults() {
        const nodes = options.map((store, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `${instanceId}-option-${store.id}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            option.tabIndex = -1;
            const name = document.createElement('strong');
            name.textContent = store.name;
            const details = document.createElement('span');
            details.textContent = `ID ${store.id} - ${statusLabel(store.status)}`;
            option.append(name, details);
            option.addEventListener('click', () => choose(store));
            option.addEventListener('pointermove', () => activate(index));
            return option;
        });

        results.replaceChildren(...nodes);
        results.hidden = nodes.length === 0;
        input.setAttribute('aria-expanded', nodes.length > 0 ? 'true' : 'false');
        syncActiveDescendant();
    }

    function handleKeydown(event) {
        if (event.key === 'Escape') {
            invalidatePending();
            options = [];
            activeIndex = -1;
            closeResults();
            status.setAttribute('role', 'status');
            status.textContent = currentTerm === ''
                ? 'Escriba al menos 2 caracteres para buscar minimarkets.'
                : 'Resultados cerrados.';
            return;
        }

        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
        if (results.hidden || options.length === 0) return;
        event.preventDefault();

        if (event.key === 'Enter') {
            choose(options[Math.max(0, activeIndex)]);
            return;
        }

        const direction = event.key === 'ArrowDown' ? 1 : -1;
        activate((activeIndex + direction + options.length) % options.length);
    }

    function activate(index) {
        activeIndex = index;
        [...results.children].forEach((option, optionIndex) => {
            option.setAttribute('aria-selected', optionIndex === index ? 'true' : 'false');
        });
        syncActiveDescendant();
    }

    function syncActiveDescendant() {
        const active = options[activeIndex];

        if (active) {
            input.setAttribute('aria-activedescendant', `${instanceId}-option-${active.id}`);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(store) {
        if (destroyed) return;
        invalidatePending();
        options = [];
        activeIndex = -1;
        closeResults();
        status.setAttribute('role', 'status');
        status.textContent = `${store.name} seleccionado.`;
        onStoreSelected?.(store);
    }

    function invalidatePending() {
        requestSequence++;

        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }

        if (controller !== null) {
            controller.abort();
            controller = null;
        }

        input.removeAttribute('aria-busy');
        results.removeAttribute('aria-busy');
    }

    function closeResults() {
        results.replaceChildren();
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function reset() {
        if (destroyed) return;
        invalidatePending();
        currentTerm = '';
        options = [];
        activeIndex = -1;
        input.value = '';
        status.setAttribute('role', 'status');
        status.textContent = 'Escriba al menos 2 caracteres para buscar minimarkets.';
        closeResults();
    }

    function destroy() {
        if (destroyed) return;
        destroyed = true;
        invalidatePending();
        closeResults();
        input.removeEventListener('compositionstart', onCompositionStart);
        input.removeEventListener('compositionend', onCompositionEnd);
        input.removeEventListener('input', onInput);
        input.removeEventListener('keydown', onKeydown);
        input.removeEventListener('focusout', onFocusOut);
        initializedInputs.delete(input);
    }

    const api = { reset, destroy, focus: () => input.focus() };
    initializedInputs.set(input, api);
    return api;
}

function assertDependencies(searchStores, elements) {
    if (typeof searchStores !== 'function') {
        throw new TypeError('searchStores debe ser una funcion.');
    }

    if (!elements || !['input', 'results', 'status'].every((name) => (
        elements[name] instanceof Element
    ))) {
        throw new TypeError('El selector Store requiere input, results y status validos.');
    }
}

function normalizeStores(stores) {
    if (!Array.isArray(stores)) return [];
    const unique = new Map();

    stores.forEach((store) => {
        const id = Number(store?.id);
        const name = typeof store?.name === 'string' ? store.name.trim() : '';
        const status = String(store?.status ?? '');

        if (
            Number.isSafeInteger(id)
            && id > 0
            && name !== ''
            && STORE_STATUSES.includes(status)
            && !unique.has(id)
        ) {
            unique.set(id, { id, name, status });
        }
    });

    return [...unique.values()];
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

function statusLabel(status) {
    return {
        pending: 'Pendiente',
        active: 'Activo',
        inactive: 'Inactivo',
        rejected: 'Rechazado',
    }[status] || status;
}

export const STORE_SEARCH_MINIMUM = MINIMUM_TERM_LENGTH;
export const STORE_SEARCH_DEBOUNCE = DEBOUNCE_MS;
