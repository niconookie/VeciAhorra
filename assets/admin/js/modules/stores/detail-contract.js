const statuses = new Set(['pending', 'active', 'inactive', 'rejected']);
const onboardingStatuses = new Set(['draft', 'complete']);
const lifecycleStates = new Set(['draft', 'in_review', 'rejected', 'approved_inactive', 'active', 'invalid']);
const actions = new Set([
    'save',
    'submit_for_review',
    'return_to_draft',
    'approve',
    'reject',
    'activate',
    'deactivate',
    'delete_if_unreferenced',
]);
const requiredKeys = [
    'id', 'business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone', 'mobile',
    'address', 'commune', 'city', 'region', 'status', 'onboarding_status', 'approved_at',
    'lifecycle_state', 'allowed_actions', 'created_at', 'updated_at',
];
const optionalTextKeys = ['mobile', 'address', 'commune', 'city', 'region'];
const textKeys = ['business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone'];
const mysqlDatePattern = /^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01]) (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/;

const isPlainObject = (value) => (
    value !== null
    && typeof value === 'object'
    && !Array.isArray(value)
    && (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null)
);

const isMysqlDate = (value) => {
    if (typeof value !== 'string' || !mysqlDatePattern.test(value)) return false;
    const [date, time] = value.split(' ');
    const [year, month, day] = date.split('-').map(Number);
    const candidate = new Date(Date.UTC(year, month - 1, day));
    return candidate.getUTCFullYear() === year
        && candidate.getUTCMonth() === month - 1
        && candidate.getUTCDate() === day
        && typeof time === 'string';
};

export function validateDetailPayload(payload, expectedId) {
    if (!isPlainObject(payload) || payload.success !== true || !isPlainObject(payload.data)) {
        throw new TypeError('invalid_detail_payload');
    }
    const item = payload.data;
    if (
        Object.keys(item).length !== requiredKeys.length
        || requiredKeys.some((key) => !Object.prototype.hasOwnProperty.call(item, key))
    ) {
        throw new TypeError('invalid_detail_dto');
    }
    if (!Number.isSafeInteger(item.id) || item.id <= 0 || item.id !== expectedId) {
        throw new TypeError('invalid_detail_id');
    }
    if (textKeys.some((key) => typeof item[key] !== 'string')) {
        throw new TypeError('invalid_detail_text');
    }
    if (item.business_name.trim() === '' || item.owner_name.trim() === '' || item.email.trim() === '') {
        throw new TypeError('invalid_detail_required_text');
    }
    if (optionalTextKeys.some((key) => item[key] !== null && typeof item[key] !== 'string')) {
        throw new TypeError('invalid_detail_optional_text');
    }
    if (!statuses.has(item.status) || !onboardingStatuses.has(item.onboarding_status)) {
        throw new TypeError('invalid_detail_authority');
    }
    if (!lifecycleStates.has(item.lifecycle_state) || !Array.isArray(item.allowed_actions)) {
        throw new TypeError('invalid_detail_lifecycle');
    }
    if (
        item.allowed_actions.some((action) => typeof action !== 'string' || !actions.has(action))
        || new Set(item.allowed_actions).size !== item.allowed_actions.length
        || (item.lifecycle_state === 'invalid' && item.allowed_actions.length !== 0)
    ) {
        throw new TypeError('invalid_detail_actions');
    }
    if (!isMysqlDate(item.created_at) || !isMysqlDate(item.updated_at)) {
        throw new TypeError('invalid_detail_timestamp');
    }
    if (item.approved_at !== null && !isMysqlDate(item.approved_at)) {
        throw new TypeError('invalid_detail_approval');
    }

    return Object.freeze({ ...item, allowed_actions: Object.freeze([...item.allowed_actions]) });
}

export const lifecyclePresentation = Object.freeze({
    draft: Object.freeze({ label: 'Borrador', tone: 'neutral', description: 'Información en preparación, aún no enviada a revisión.' }),
    in_review: Object.freeze({ label: 'En revisión', tone: 'info', description: 'Pendiente de una decisión administrativa.' }),
    rejected: Object.freeze({ label: 'Rechazado', tone: 'critical', description: 'El lifecycle fue rechazado y puede requerir correcciones.' }),
    approved_inactive: Object.freeze({ label: 'Aprobado e inactivo', tone: 'warning', description: 'Aprobado, pero no habilitado operativamente.' }),
    active: Object.freeze({ label: 'Activo', tone: 'positive', description: 'Habilitado operativamente.' }),
    invalid: Object.freeze({ label: 'Estado inconsistente', tone: 'critical', description: 'La combinación persistida no pertenece al contrato reconocido.' }),
});

export const actionLabels = Object.freeze({
    save: 'Editar información',
    submit_for_review: 'Enviar a revisión',
    return_to_draft: 'Volver a borrador',
    approve: 'Aprobar',
    reject: 'Rechazar',
    activate: 'Activar',
    deactivate: 'Inactivar',
    delete_if_unreferenced: 'Eliminar si no posee referencias',
});
