const MINIMUM_TERM_LENGTH = 2;
const DEBOUNCE_MS = 300;

export function createProductSelector(actions) {
    const element = document.createElement('div');
    element.className = 'veciahorra-inventory-admin__product-selector';
    const label = document.createElement('label');
    label.htmlFor = 'veciahorra-inventory-product-search';
    label.textContent = 'Producto';
    const input = document.createElement('input');
    input.id = 'veciahorra-inventory-product-search';
    input.type = 'search';
    input.className = 'regular-text';
    input.placeholder = 'Buscar producto por nombre';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', 'veciahorra-inventory-product-results');
    const help = document.createElement('p');
    help.className = 'description';
    help.textContent = 'Escriba al menos 2 caracteres y seleccione un resultado.';
    const status = document.createElement('p');
    status.className = 'veciahorra-inventory-admin__product-search-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    const results = document.createElement('div');
    results.id = 'veciahorra-inventory-product-results';
    results.className = 'veciahorra-inventory-admin__product-results';
    results.setAttribute('role', 'listbox');
    results.hidden = true;
    const selected = document.createElement('div');
    selected.className = 'veciahorra-inventory-admin__selected-product';
    selected.hidden = true;
    selected.setAttribute('role', 'status');
    selected.setAttribute('aria-live', 'polite');
    const selectedText = document.createElement('p');
    const change = document.createElement('button');
    change.type = 'button';
    change.className = 'button button-secondary';
    change.textContent = 'Cambiar producto';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button-link-delete';
    remove.textContent = 'Quitar selección';
    selected.append(selectedText, change, remove);
    element.append(label, input, help, status, results, selected);

    let timer = null;
    let requestSequence = 0;
    let currentTerm = '';
    let options = [];
    let activeIndex = -1;
    let visible = false;
    let composing = false;

    input.addEventListener('compositionstart', () => { composing = true; });
    input.addEventListener('compositionend', () => {
        composing = false;
        schedule(input.value);
    });
    input.addEventListener('input', () => {
        if (!composing) schedule(input.value);
    });
    input.addEventListener('keydown', handleKeydown);
    element.addEventListener('focusout', () => {
        queueMicrotask(() => {
            if (!element.contains(document.activeElement)) closeResults();
        });
    });
    change.addEventListener('click', clearSelection);
    remove.addEventListener('click', clearSelection);

    function render(form) {
        const shouldShow = form.mode === 'create' && !form.productLocked;

        if (!shouldShow) {
            if (visible) reset();
            visible = false;
            element.hidden = true;
            return;
        }

        visible = true;
        element.hidden = false;
        const product = form.selectedProduct;
        const hasSelection = product !== null;
        input.hidden = hasSelection;
        help.hidden = hasSelection;
        selected.hidden = !hasSelection;
        input.disabled = form.isSaving;
        change.disabled = form.isSaving;
        remove.disabled = form.isSaving;

        if (hasSelection) {
            closeResults();
            selectedText.textContent = `${product.name} (#${product.id}) — ${statusLabel(product.status)}`;
        }

        const fieldError = form.fieldErrors.productId || '';
        input.setAttribute('aria-invalid', fieldError ? 'true' : 'false');
        if (fieldError) {
            status.textContent = fieldError;
            status.setAttribute('role', 'alert');
        } else if (status.getAttribute('role') === 'alert') {
            status.textContent = '';
            status.setAttribute('role', 'status');
        }
    }

    function focus() {
        const target = selected.hidden ? input : change;
        if (target.isConnected && !target.disabled) target.focus();
    }

    function schedule(value) {
        const term = String(value).trim();
        currentTerm = term;
        invalidatePending();

        if (term.length < MINIMUM_TERM_LENGTH) {
            options = [];
            activeIndex = -1;
            closeResults();
            status.textContent = term === '' ? '' : 'Escriba al menos 2 caracteres.';
            return;
        }

        options = [];
        activeIndex = -1;
        closeResults();
        status.textContent = 'Esperando para buscar…';
        timer = window.setTimeout(() => search(term), DEBOUNCE_MS);
    }

    async function search(term) {
        timer = null;
        const sequence = ++requestSequence;
        status.textContent = 'Buscando productos…';
        input.setAttribute('aria-busy', 'true');

        try {
            if (typeof actions.searchProducts !== 'function') {
                throw new Error('La búsqueda de Products no está disponible.');
            }
            const response = await actions.searchProducts(term);

            if (sequence !== requestSequence || term !== currentTerm || !visible) return;
            options = normalizeProducts(response.data);
            activeIndex = options.length > 0 ? 0 : -1;
            renderResults();
            status.setAttribute('role', 'status');
            status.textContent = options.length === 0
                ? 'No se encontraron productos.'
                : `${options.length} productos encontrados.`;
        } catch (error) {
            if (sequence !== requestSequence || term !== currentTerm || !visible) return;
            options = [];
            activeIndex = -1;
            closeResults();
            status.textContent = error?.message || 'No fue posible buscar productos.';
            status.setAttribute('role', 'alert');
        } finally {
            if (sequence === requestSequence) input.removeAttribute('aria-busy');
        }
    }

    function renderResults() {
        const nodes = options.map((product, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `veciahorra-inventory-product-option-${product.id}`;
            option.className = 'veciahorra-inventory-admin__product-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            option.tabIndex = -1;
            const name = document.createElement('strong');
            name.textContent = product.name;
            const details = document.createElement('span');
            details.textContent = `ID ${product.id} · ${statusLabel(product.status)}`;
            option.append(name, details);
            option.addEventListener('click', () => choose(product));
            option.addEventListener('pointermove', () => activate(index));
            return option;
        });
        results.replaceChildren(...nodes);
        results.hidden = nodes.length === 0;
        input.setAttribute('aria-expanded', nodes.length === 0 ? 'false' : 'true');
        syncActiveDescendant();
    }

    function handleKeydown(event) {
        if (event.key === 'Escape') {
            invalidatePending();
            closeResults();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
        if (results.hidden || options.length === 0) {
            if (event.key === 'Enter') event.preventDefault();
            return;
        }
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
        if (activeIndex >= 0 && options[activeIndex]) {
            input.setAttribute(
                'aria-activedescendant',
                `veciahorra-inventory-product-option-${options[activeIndex].id}`
            );
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(product) {
        invalidatePending();
        closeResults();
        input.value = '';
        currentTerm = '';
        status.textContent = '';
        status.setAttribute('role', 'status');
        actions.onProductSelected?.(product);
    }

    function clearSelection() {
        invalidatePending();
        reset();
        actions.onProductCleared?.();
        queueMicrotask(() => input.focus());
    }

    function invalidatePending() {
        requestSequence++;
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        input.removeAttribute('aria-busy');
    }

    function closeResults() {
        results.replaceChildren();
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function reset() {
        invalidatePending();
        currentTerm = '';
        options = [];
        activeIndex = -1;
        input.value = '';
        status.textContent = '';
        status.setAttribute('role', 'status');
        closeResults();
    }

    return { element, render, focus, reset };
}

function normalizeProduct(product) {
    const id = Number(product?.id);
    const name = typeof product?.name === 'string' ? product.name : '';
    const status = String(product?.status ?? '');
    return Number.isSafeInteger(id) && id > 0 && name.trim() !== ''
        && ['active', 'inactive', 'draft'].includes(status)
        ? { id, name, status }
        : null;
}

function normalizeProducts(products) {
    const unique = new Map();

    products.forEach((product) => {
        const normalized = normalizeProduct(product);

        if (normalized !== null && !unique.has(normalized.id)) {
            unique.set(normalized.id, normalized);
        }
    });

    return [...unique.values()];
}

function statusLabel(status) {
    return { active: 'Activo', inactive: 'Inactivo', draft: 'Borrador' }[status]
        || status;
}

export const PRODUCT_SEARCH_MINIMUM = MINIMUM_TERM_LENGTH;
export const PRODUCT_SEARCH_DEBOUNCE = DEBOUNCE_MS;
