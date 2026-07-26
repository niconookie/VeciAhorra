export function createOrdersState(api, initial, onChange) {
    let state = { status: 'loading', query: initial, data: null, error: null };
    let controller = null;
    let sequence = 0;
    let destroyed = false;
    const publish = () => { if (!destroyed) onChange(state); };
    async function load(query = state.query) {
        const current = ++sequence;
        controller?.abort();
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        state = { ...state, status: 'loading', query, error: null }; publish();
        try {
            const data = await api.list(query, { signal: controller?.signal });
            if (destroyed || current !== sequence) return;
            state = { status: data.items.length ? 'success' : 'empty', query, data, error: null }; publish();
        } catch (error) {
            if (destroyed || current !== sequence || error?.name === 'AbortError') return;
            state = { ...state, status: 'error', error }; publish();
        }
    }
    return {
        load,
        destroy() { destroyed = true; sequence += 1; controller?.abort(); },
        getState() { return state; },
    };
}
