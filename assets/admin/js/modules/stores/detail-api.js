export class StoreDetailApiError extends Error {
    constructor(status, code = 'store_detail_failed') {
        super(code);
        this.name = 'StoreDetailApiError';
        this.status = status;
        this.code = code;
    }
}

export function createStoreDetailApi(config) {
    return Object.freeze({
        async get(signal) {
            let response;
            try {
                response = await fetch(config.detailUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-WP-Nonce': config.nonce,
                    },
                    signal,
                });
            } catch (error) {
                if (error?.name === 'AbortError') throw error;
                throw new StoreDetailApiError(0, 'network_error');
            }

            if (!response.ok) {
                throw new StoreDetailApiError(response.status, 'store_detail_failed');
            }

            const contentType = response.headers?.get?.('content-type');
            if (typeof contentType !== 'string' || !contentType.toLowerCase().includes('application/json')) {
                throw new StoreDetailApiError(response.status, 'invalid_content_type');
            }

            try {
                return await response.json();
            } catch (error) {
                throw new StoreDetailApiError(response.status, 'invalid_json');
            }
        },
    });
}
