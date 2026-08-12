(function (window) {
    'use strict';

    var config = window.VeciAhorra || {};
    var baseUrl = String(config.restUrl || '').replace(/\/+$/, '');

    function mountSectorSelector() {
        document.querySelectorAll('[data-va-sector-selector]').forEach(function (root) {
            var select = root.querySelector('[data-va-sector-select]');
            var message = root.querySelector('[data-va-sector-message]');
            if (!select || select.dataset.ready === '1') return;
            select.dataset.ready = '1';
            Promise.all([request('GET','sectors'),request('GET','sector/current')]).then(function(values){
                var zones=(values[0]&&values[0].data)||[],current=(values[1]&&values[1].data)||null;
                zones.forEach(function(zone){var option=document.createElement('option');option.value=String(zone.id);option.textContent=zone.name+' · '+zone.commune;select.appendChild(option);});
                if(current)select.value=String(current.id);
            }).catch(function(){message.textContent='No fue posible cargar los sectores.';});
            select.addEventListener('change',function(){if(!select.value)return;select.disabled=true;request('POST','sector/current/'+encodeURIComponent(select.value)).then(function(response){var zone=response.data;message.textContent='Sector actualizado: '+zone.name+'. Revisaremos tu carrito.';window.location.reload();}).catch(function(error){message.textContent=error.message||'No fue posible cambiar el sector.';select.disabled=false;});});
        });
    }

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
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountSectorSelector); else mountSectorSelector();
}(window));
