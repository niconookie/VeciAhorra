export function createProductDetailStore(api, productId) {
    let state = {
        status: 'idle',
        product: null,
        error: null,
        busy: false,
    };
    let sequence = 0;
    const listeners = new Set();
    const emit = () => listeners.forEach((listener) => listener(snapshot()));
    const set = (changes) => { state = {...state, ...changes}; emit(); };
    const snapshot = () => ({
        ...state,
        product: state.product ? structuredClone(state.product) : null,
        error: state.error ? {...state.error} : null,
    });

    async function load() {
        const request = ++sequence;
        set({status:'loading', error:null});
        try {
            const product = await api.load(productId);
            if (request !== sequence) return false;
            set({status:'ready', product, error:null, busy:false});
            return true;
        } catch (error) {
            if (request !== sequence) return false;
            set({
                status: error.status === 404 ? 'not-found' : 'error',
                product:null,
                error: normalize(error),
            });
            return false;
        }
    }

    async function changeStatus(status) {
        if (state.busy || !state.product
            || !state.product.lifecycle.allowed_statuses.includes(status)) {
            return false;
        }
        set({busy:true, error:null});
        const request = ++sequence;
        try {
            await api.changeStatus(
                productId,
                status,
                state.product.lifecycle.expected_updated_at
            );
            if (request !== sequence) return false;
            return await load();
        } catch (error) {
            if (request === sequence) {
                set({error:normalize(error)});
            }
            return false;
        } finally {
            if (request === sequence) set({busy:false});
        }
    }

    return {
        subscribe(listener) { listeners.add(listener); return () => listeners.delete(listener); },
        getState: snapshot,
        load,
        changeStatus,
    };
}

function normalize(error) {
    return {
        status: Number.isInteger(error?.status) ? error.status : null,
        code: typeof error?.code === 'string' ? error.code : 'unknown_error',
        message: typeof error?.message === 'string'
            ? error.message
            : 'No fue posible cargar el Product.',
    };
}
