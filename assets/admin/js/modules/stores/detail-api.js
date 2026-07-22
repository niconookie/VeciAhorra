export class StoreDetailApiError extends Error {
    constructor(status, code = 'store_detail_failed') {
        super(code);
        this.name = 'StoreDetailApiError';
        this.status = status;
        this.code = code;
    }
}

export function createStoreDetailApi(config) {
    const request = async (url, options, fallbackCode) => {
        let response;
        try {
            response = await fetch(url, options);
        } catch (error) {
            if (error?.name === 'AbortError') throw error;
            throw new StoreDetailApiError(0, 'network_error');
        }
        if (!response.ok) {
            let data = null;
            const contentType = response.headers?.get?.('content-type');
            if (typeof contentType === 'string' && contentType.toLowerCase().includes('application/json')) {
                try { data = await response.json(); } catch (error) { data = null; }
            }
            const failure = new StoreDetailApiError(response.status, fallbackCode);
            failure.data = data;
            throw failure;
        }
        const contentType = response.headers?.get?.('content-type');
        if (typeof contentType !== 'string' || !contentType.toLowerCase().includes('application/json')) {
            throw new StoreDetailApiError(response.status, 'invalid_content_type');
        }
        try { return await response.json(); } catch (error) {
            throw new StoreDetailApiError(response.status, 'invalid_json');
        }
    };
    return Object.freeze({
        async get(signal) {
            return request(config.detailUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-WP-Nonce': config.nonce,
                    },
                    signal,
                }, 'store_detail_failed');
        },
        async update(payload, signal) {
            const body = new FormData();
            body.set('action', 'veciahorra_store_update');
            body.set('id', String(config.storeId));
            body.set('_wpnonce', config.updateNonce);
            Object.entries(payload).forEach(([field, value]) => body.set(field, value));
            return request(config.updateUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Veciahorra-Store-Detail': 'commercial-update',
                },
                body, signal,
            }, 'store_update_failed');
        },
        async transition(action, signal) {
            return request(`${config.detailUrl}/transitions`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ action }),
                signal,
            }, 'store_transition_failed');
        },
    });
}
