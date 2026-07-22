export const deleteAction = 'delete_if_unreferenced';

export function canDeleteStore(dto) {
    return dto.allowed_actions.includes(deleteAction);
}

export function matchesStoreName(value, businessName) {
    return typeof value === 'string' && value === businessName;
}
