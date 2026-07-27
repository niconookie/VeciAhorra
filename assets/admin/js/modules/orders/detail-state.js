const ERROR_STATES = new Set([
    'unauthorized',
    'forbidden',
    'not_found',
    'invalid_request',
    'server_error',
    'network_error',
    'invalid_response',
]);

const BACKEND_CODES = new Set([
    'rest_not_authenticated',
    'rest_nonce_missing',
    'rest_forbidden',
    'rest_cookie_invalid_nonce',
    'order_not_found',
    'invalid_parameters',
    'orders_admin_detail_read_failed',
]);

export function createOrderDetailState({ orderId, transport } = {}) {
    const stableOrderId = canonicalId(orderId);
    if (stableOrderId === null) {
        throw new TypeError('A valid orderId is required.');
    }
    if (transport === null || typeof transport !== 'object'
        || typeof transport.getOrderDetail !== 'function') {
        throw new TypeError('A compatible transport is required.');
    }

    let snapshot = makeSnapshot('idle', stableOrderId);
    let controller = null;
    let sequence = 0;
    let destroyed = false;
    const listeners = new Set();

    function getSnapshot() {
        return {
            ...snapshot,
            error: snapshot.error === null ? null : { ...snapshot.error },
        };
    }

    function publish(status, detail = null, error = null) {
        if (destroyed) return;
        snapshot = makeSnapshot(status, stableOrderId, detail, error);
        const pending = [...listeners];
        for (const listener of pending) {
            if (destroyed) break;
            try {
                listener(getSnapshot());
            } catch {
                // Listener failures do not alter state or notification delivery.
            }
        }
    }

    async function load() {
        if (destroyed) return getSnapshot();

        const previous = controller;
        const operation = ++sequence;
        const current = new AbortController();
        controller = current;
        if (previous !== null) previous.abort();
        publish('loading');

        try {
            const detail = await transport.getOrderDetail(stableOrderId, {
                signal: current.signal,
            });
            if (! accepts(operation, current)) return getSnapshot();
            if (! object(detail)) {
                publish('invalid_response', null, safeError());
            } else {
                publish('ready', detail);
            }
        } catch (failure) {
            if (! accepts(operation, current)) return getSnapshot();
            if (failure?.kind === 'aborted' || current.signal.aborted) {
                publish('idle');
            } else {
                const error = safeError(failure);
                publish(error.kind, null, error);
            }
        } finally {
            if (! destroyed && operation === sequence && controller === current) {
                controller = null;
            }
        }

        return getSnapshot();
    }

    function accepts(operation, activeController) {
        return ! destroyed
            && operation === sequence
            && controller === activeController
            && ! activeController.signal.aborted;
    }

    function cancel() {
        if (destroyed || controller === null) return getSnapshot();
        const active = controller;
        ++sequence;
        controller = null;
        active.abort();
        publish('idle');
        return getSnapshot();
    }

    function destroy() {
        if (destroyed) return;
        destroyed = true;
        ++sequence;
        const active = controller;
        controller = null;
        if (active !== null) active.abort();
        listeners.clear();
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('A listener function is required.');
        }
        if (destroyed) return () => {};
        listeners.add(listener);
        let subscribed = true;
        return () => {
            if (! subscribed) return;
            subscribed = false;
            listeners.delete(listener);
        };
    }

    return Object.freeze({
        getSnapshot,
        subscribe,
        load,
        cancel,
        destroy,
    });
}

function makeSnapshot(status, orderId, detail = null, error = null) {
    return Object.freeze({
        status,
        orderId,
        detail,
        error,
        isLoading: status === 'loading',
    });
}

function safeError(failure = null) {
    const kind = ERROR_STATES.has(failure?.kind) ? failure.kind : 'invalid_response';
    return Object.freeze({
        kind,
        code: BACKEND_CODES.has(failure?.code) ? failure.code : null,
        status: Number.isInteger(failure?.status) ? failure.status : 0,
    });
}

function canonicalId(value) {
    if (Number.isSafeInteger(value) && value > 0) return value;
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) return null;
    const numeric = Number(value);
    return Number.isSafeInteger(numeric) && String(numeric) === value ? numeric : null;
}

function object(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
