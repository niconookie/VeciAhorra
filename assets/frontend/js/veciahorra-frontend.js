(function (window) {
    'use strict';

    var config = window.VeciAhorra || {};
    var baseUrl = String(config.restUrl || '').replace(/\/+$/, '');

    function normalizedError(status, payload, fallback) {
        var moduleError = payload && payload.error;

        return {
            status: status,
            code: String((moduleError && moduleError.code) || (payload && payload.code) || ''),
            message: String((moduleError && moduleError.message) || (payload && payload.message) || fallback),
            data: payload || null
        };
    }

    function requestUrl(path) {
        var rawPath = String(path || '');
        var root;
        var url;

        if (
            baseUrl === ''
            || /[\\\u0000-\u001f]/.test(rawPath)
            || /^[a-z][a-z\d+.-]*:/i.test(rawPath)
            || /^\/\//.test(rawPath)
        ) {
            throw normalizedError(0, {
                code: 'invalid_path',
                message: 'La ruta REST no es válida.'
            }, 'La ruta REST no es válida.');
        }

        root = new URL(baseUrl + '/', window.location.origin);
        url = new URL(rawPath.replace(/^\/+/, ''), root);

        if (
            url.origin !== root.origin
            || url.pathname.indexOf(root.pathname) !== 0
        ) {
            throw normalizedError(0, {
                code: 'invalid_path',
                message: 'La ruta REST no es válida.'
            }, 'La ruta REST no es válida.');
        }

        return url.toString();
    }

    function request(method, path, data, options) {
        var settings = options || {};
        var headers = new Headers(settings.headers || {});
        var requestOptions = {
            method: method,
            headers: headers,
            credentials: 'same-origin',
            signal: settings.signal
        };

        headers.set('Accept', 'application/json');

        if (config.nonce) {
            headers.set('X-WP-Nonce', config.nonce);
        }

        if (data !== undefined && data !== null) {
            headers.set('Content-Type', 'application/json');
            requestOptions.body = JSON.stringify(data);
        }

        try {
            path = requestUrl(path);
        } catch (error) {
            return Promise.reject(error);
        }

        return window.fetch(path, requestOptions)
            .then(function (response) {
                return response.text().then(function (body) {
                    var payload = null;

                    if (body !== '') {
                        try {
                            payload = JSON.parse(body);
                        } catch (error) {
                            throw normalizedError(response.status, null, 'El servidor devolvió una respuesta no válida.');
                        }
                    }

                    if (!response.ok || (payload && payload.success === false)) {
                        throw normalizedError(response.status, payload, 'No fue posible completar la solicitud.');
                    }

                    return payload;
                });
            })
            .catch(function (error) {
                if (error && typeof error.status === 'number') {
                    throw error;
                }

                throw {
                    status: 0,
                    code: error && error.name === 'AbortError' ? 'request_aborted' : 'network_error',
                    message: error && error.name === 'AbortError' ? 'La solicitud fue cancelada.' : 'No fue posible conectar con el servidor.',
                    data: null
                };
            });
    }

    config.api = {
        request: request,
        get: function (path, options) { return request('GET', path, null, options); },
        post: function (path, data, options) { return request('POST', path, data, options); },
        patch: function (path, data, options) { return request('PATCH', path, data, options); },
        delete: function (path, options) { return request('DELETE', path, null, options); }
    };

    window.VeciAhorra = config;
}(window));
