(() => {
    'use strict';
    const root = document.querySelector('[data-va-onboarding]');
    if (!root) return;
    const focusTarget = root.querySelector('[data-va-onboarding-alert], [data-va-onboarding-result]');
    if (focusTarget) focusTarget.focus();
    const form = root.querySelector('[data-va-onboarding-form]');
    if (form) form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        const button = form.querySelector('[data-va-submit]');
        const label = form.querySelector('[data-va-submit-label]');
        if (button) button.setAttribute('aria-disabled', 'true');
        if (label) label.textContent = 'Enviando…';
    });
    const copy = root.querySelector('[data-va-copy-code]');
    const code = root.querySelector('[data-va-onboarding-code]');
    if (copy && code && navigator.clipboard) copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(code.textContent || '');
            copy.textContent = 'Código copiado';
        } catch (_) {
            copy.textContent = 'Selecciona y copia el código';
        }
    });
})();
