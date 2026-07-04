// CoreX Resilient Submit — AT-165 (complement C)
// -----------------------------------------------------------------------------
// The draft layer *recovers* lost work; this *prevents* the loss. On a failed
// POST (offline / 419 CSRF expired / 5xx) it does NOT blank the form — it keeps
// the payload, refreshes the CSRF token, shows a quiet "your work is safe,
// retrying" line, and auto-retries when connectivity returns.
//
// Opt in per form:
//   <form method="POST" action="..." data-resilient-submit> ... </form>
// The live draft (draft-persistence.js) is the backing store, so even a full
// browser crash mid-retry loses nothing.
// -----------------------------------------------------------------------------

(function () {
    'use strict';
    if (typeof window === 'undefined') return;

    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
    function el(tag, attrs, html) { const e = document.createElement(tag); if (attrs) for (const k in attrs) e.setAttribute(k, attrs[k]); if (html != null) e.innerHTML = html; return e; }

    class ResilientForm {
        constructor(form) {
            this.form = form;
            this.pending = false;
            this.notice = el('div', { 'data-resilient-notice': '', 'aria-live': 'polite',
                style: 'display:none;padding:.5rem .9rem;margin-top:.5rem;border-radius:8px;font-size:.82rem;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;' });
            form.appendChild(this.notice);
            form.addEventListener('submit', (e) => this.onSubmit(e));
            window.addEventListener('online', () => { if (this.pending) this.retry(); });
        }

        onSubmit(e) {
            e.preventDefault();
            this.submit();
        }

        async submit() {
            const fd = new FormData(this.form);
            fd.set('_token', csrf()); // always send a fresh token
            this._setBusy(true);
            try {
                const res = await fetch(this.form.action, {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html,application/json' },
                    redirect: 'follow',
                });
                if (res.ok || (res.status >= 200 && res.status < 400)) {
                    this.pending = false;
                    // Full-page-POST semantics: follow the server's redirect.
                    if (res.redirected && res.url) { window.location.assign(res.url); return; }
                    // Validation re-render (422) or JSON — reload to show it authoritatively.
                    window.location.reload(); return;
                }
                if (res.status === 419) { await this._refreshToken(); throw new Error('csrf'); }
                if (res.status === 422) { window.location.reload(); return; } // validation errors — server owns the render
                throw new Error('http ' + res.status);
            } catch (err) {
                this.pending = true;
                this._setBusy(false);
                this._showRetry();
            }
        }

        async _refreshToken() {
            // Pull a fresh CSRF token so the retry isn't rejected again.
            try {
                const html = await (await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })).text();
                const m = html.match(/name="csrf-token"\s+content="([^"]+)"/);
                if (m) document.querySelector('meta[name="csrf-token"]').setAttribute('content', m[1]);
            } catch (e) {}
        }

        retry() { if (this.pending) this.submit(); }

        _showRetry() {
            const offline = !navigator.onLine;
            this.notice.style.display = 'block';
            this.notice.innerHTML = offline
                ? 'Offline — your work is safe on this device. It will submit automatically when the connection returns. '
                : "Couldn't reach the server — your work is safe. ";
            const btn = el('button', { type: 'button', style: 'text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;font-weight:600;' }, 'Retry now');
            btn.addEventListener('click', () => this.submit());
            this.notice.appendChild(btn);
        }

        _setBusy(b) {
            const submit = this.form.querySelector('[type="submit"]');
            if (submit) { submit.disabled = b; submit.style.opacity = b ? '.6' : ''; }
            if (b) this.notice.style.display = 'none';
        }
    }

    function scan() { document.querySelectorAll('form[data-resilient-submit]').forEach((f) => { if (!f.__resilient) { f.__resilient = new ResilientForm(f); } }); }
    window.CoreX = window.CoreX || {};
    window.CoreX.resilientSubmit = { scan };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
})();
