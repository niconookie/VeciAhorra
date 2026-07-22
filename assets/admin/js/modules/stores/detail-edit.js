export const editableFields = Object.freeze([
    Object.freeze({ name: 'business_name', label: 'Nombre comercial', required: true, maxLength: 150, type: 'text', autocomplete: 'organization' }),
    Object.freeze({ name: 'legal_name', label: 'Razón social', required: false, maxLength: 150, type: 'text', autocomplete: 'organization' }),
    Object.freeze({ name: 'owner_name', label: 'Propietario', required: true, maxLength: 150, type: 'text', autocomplete: 'name' }),
    Object.freeze({ name: 'rut', label: 'RUT', required: false, maxLength: 20, type: 'text', autocomplete: 'off' }),
    Object.freeze({ name: 'email', label: 'Correo', required: true, maxLength: 150, type: 'email', autocomplete: 'email' }),
    Object.freeze({ name: 'phone', label: 'Teléfono', required: false, maxLength: 30, type: 'tel', autocomplete: 'tel' }),
    Object.freeze({ name: 'mobile', label: 'Móvil', required: false, maxLength: 30, type: 'tel', autocomplete: 'tel' }),
    Object.freeze({ name: 'address', label: 'Dirección', required: false, maxLength: 255, type: 'text', autocomplete: 'street-address' }),
    Object.freeze({ name: 'commune', label: 'Comuna', required: false, maxLength: 120, type: 'text', autocomplete: 'address-level3' }),
    Object.freeze({ name: 'city', label: 'Ciudad', required: false, maxLength: 120, type: 'text', autocomplete: 'address-level2' }),
    Object.freeze({ name: 'region', label: 'Región', required: false, maxLength: 120, type: 'text', autocomplete: 'address-level1' }),
]);

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function captureEditSnapshot(dto) {
    return Object.freeze(Object.fromEntries(editableFields.map(({ name }) => [name, dto[name] ?? ''])));
}

export function readEditPayload(form) {
    return Object.fromEntries(editableFields.map(({ name }) => {
        const control = form.elements.namedItem(name);
        return [name, typeof control?.value === 'string' ? control.value : ''];
    }));
}

export function validateEditPayload(payload) {
    const errors = {};
    editableFields.forEach(({ name, required, maxLength }) => {
        const value = payload[name];
        if (typeof value !== 'string') errors[name] = 'invalid';
        else if (required && value.trim() === '') errors[name] = 'required';
        else if ([...value].length > maxLength) errors[name] = 'too_long';
    });
    if (!errors.email && !emailPattern.test(payload.email)) errors.email = 'invalid_email';
    return Object.freeze(errors);
}

export function fieldErrorMessage(field, code) {
    const definition = editableFields.find(({ name }) => name === field);
    if (!definition) return null;
    if (code === 'required') return `${definition.label} es obligatorio.`;
    if (code === 'invalid_email') return 'Ingresa un correo electrónico válido.';
    if (code === 'too_long') return `${definition.label} supera el máximo de ${definition.maxLength} caracteres.`;
    return 'Revisa este campo.';
}
