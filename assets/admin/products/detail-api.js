export class ProductDetailApiError extends Error {
    constructor(status, code, message) {
        super(message);
        this.status = status;
        this.code = code;
    }
}

export function createProductDetailApi({ restUrl, nonce }) {
    const base = restUrl.replace(/\/+$/, '');

    async function request(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-WP-Nonce', nonce);
        const response = await fetch(`${base}${path}`, {
            ...options,
            headers,
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || payload.success !== true) {
            throw new ProductDetailApiError(
                response.status,
                payload?.error?.code || `http_${response.status}`,
                payload?.error?.message || 'No fue posible completar la solicitud.'
            );
        }
        return payload.data;
    }

    return {
        load: (id) => request(`/products/${id}/admin-detail`),
        changeStatus: (id, status, version) => request(
            `/products/${id}/status`,
            {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    status,
                    expected_updated_at: version,
                }),
            }
        ),
    };
}
