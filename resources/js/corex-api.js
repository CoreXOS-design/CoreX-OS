// CoreX global API client.
// Fires on every page so the Network tab shows /api/v1/me as XHR.
// Stashes the response on window.CoreX for any page-level code to consume.

(function () {
    if (typeof window === 'undefined') return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function apiFetch(path, options = {}) {
        // ORDER IS LOad-BEARING. `...options` must come BEFORE `headers`, and the
        // header merge must be the LAST key in this literal.
        //
        // Previously `headers` was declared first and `...options` spread after it, so
        // any caller passing its own headers — e.g. { 'Content-Type': 'application/json' }
        // on a JSON POST — replaced the whole merged object and silently dropped
        // 'X-CSRF-TOKEN'. Laravel then answered 419 on every such write. Callers treat
        // these as fire-and-forget, so nothing surfaced: the System Updates pop-up could
        // never record a dismissal and reappeared on every page load, and the property
        // geocode POST failed the same way. A merge that a caller can silently defeat is
        // not a default — spread first, then merge headers on top.
        const opts = {
            credentials: 'same-origin',
            ...options,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        };
        const res = await fetch(path, opts);
        const data = res.headers.get('content-type')?.includes('application/json')
            ? await res.json()
            : await res.text();
        if (!res.ok) {
            const err = new Error(`API ${res.status} ${path}`);
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    window.CoreX = window.CoreX || {};
    window.CoreX.api = {
        fetch: apiFetch,
        loggedUser: ()         => apiFetch('/api/v1/logged-user'),
        me:         ()         => apiFetch('/api/v1/logged-user'),
        properties: (params)   => apiFetch('/api/v1/properties' + qs(params)),
        property:   (id)       => apiFetch('/api/v1/properties/' + id),
        contacts:   (params)   => apiFetch('/api/v1/contacts' + qs(params)),
        contact:    (id)       => apiFetch('/api/v1/contacts/' + id),
        deals:      (params)   => apiFetch('/api/v1/deals' + qs(params)),
        deal:       (id)       => apiFetch('/api/v1/deals/' + id),
    };

    function qs(params) {
        if (!params) return '';
        const s = new URLSearchParams(params).toString();
        return s ? '?' + s : '';
    }

    // Boot — fire /me on every authenticated page.
    if (document.querySelector('meta[name="corex-auth"]')?.content === '1') {
        apiFetch('/api/v1/logged-user')
            .then((data) => {
                window.CoreX.loggedUser = data;
                window.CoreX.me = data; // back-compat alias
                window.dispatchEvent(new CustomEvent('corex:logged-user', { detail: data }));
                window.dispatchEvent(new CustomEvent('corex:me', { detail: data })); // back-compat
            })
            .catch((err) => {
                window.dispatchEvent(new CustomEvent('corex:logged-user:error', { detail: err }));
            });
    }
})();
