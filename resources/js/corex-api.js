// CoreX global API client.
// Fires on every page so the Network tab shows /api/v1/me as XHR.
// Stashes the response on window.CoreX for any page-level code to consume.

(function () {
    if (typeof window === 'undefined') return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function apiFetch(path, options = {}) {
        const opts = {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
            ...options,
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
