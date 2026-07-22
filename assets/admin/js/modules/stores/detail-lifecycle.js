export const lifecycleActions = Object.freeze({
    submit_for_review: Object.freeze({ label: 'Enviar a revisión', tone: 'primary', consequence: 'Este minimarket quedará pendiente de revisión administrativa.', success: 'Minimarket enviado a revisión.' }),
    return_to_draft: Object.freeze({ label: 'Volver a borrador', tone: 'secondary', consequence: 'El minimarket volverá a borrador y podrá corregirse antes de enviarse nuevamente.', success: 'Minimarket devuelto a borrador.' }),
    approve: Object.freeze({ label: 'Aprobar', tone: 'primary', consequence: 'El minimarket quedará aprobado, pero todavía inactivo.', success: 'Minimarket aprobado.' }),
    reject: Object.freeze({ label: 'Rechazar', tone: 'critical', consequence: 'El minimarket volverá al estado rechazado y requerirá correcciones antes de una nueva revisión.', success: 'Minimarket rechazado.' }),
    activate: Object.freeze({ label: 'Activar', tone: 'primary', consequence: 'El minimarket quedará habilitado operativamente.', success: 'Minimarket activado.' }),
    deactivate: Object.freeze({ label: 'Inactivar', tone: 'secondary', consequence: 'El minimarket dejará de estar activo, pero conservará su aprobación.', success: 'Minimarket inactivado.' }),
});

export const lifecycleActionNames = Object.freeze(Object.keys(lifecycleActions));

export function visibleLifecycleActions(dto) {
    return Object.freeze(dto.allowed_actions.filter((action) => lifecycleActionNames.includes(action)));
}

export function isLifecycleAction(action) {
    return typeof action === 'string' && lifecycleActionNames.includes(action);
}

export function transitionPayload(action) {
    if (!isLifecycleAction(action)) throw new TypeError('invalid_lifecycle_action');
    return Object.freeze({ action });
}
