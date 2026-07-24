/**
 * api.js — Global API Client (Layer 1: 统一通信层)
 *
 * All pages share this. Handles CSRF, timeout, JSON parsing.
 *
 * Usage:
 *   await api.get('/campaigns.php?format=json')
 *   await api.post('/campaigns.php?action=create', {name:'...'})
 *   await api.del('/campaigns.php?id=123')
 */
(function () {
    var BASE = '';
    var TIMEOUT_MS = 15000;

    function csrfToken() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    async function request(url, options) {
        options = options || {};
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, TIMEOUT_MS);

        try {
            var res = await fetch(BASE + url, Object.assign({}, options, {
                headers: Object.assign({
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                }, options.headers || {}),
                signal: controller.signal
            }));
            if (!res.ok) {
                var text = await res.text().catch(function () { return ''; });
                throw new Error(text || 'HTTP ' + res.status);
            }
            var ct = res.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return await res.json();
            }
            return await res.text();
        } finally {
            clearTimeout(timer);
        }
    }

    window.api = {
        get: function (url) { return request(url); },
        post: function (url, data) {
            return request(url, {
                method: 'POST',
                headers: data && typeof data === 'object' && !(data instanceof FormData)
                    ? {'Content-Type': 'application/json'} : {},
                body: data instanceof FormData ? data : (data ? JSON.stringify(data) : undefined)
            });
        },
        put: function (url, data) {
            return request(url, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data || {})
            });
        },
        patch: function (url, data) {
            return request(url, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data || {})
            });
        },
        del: function (url) { return request(url, { method: 'DELETE' }); }
    };
})();
