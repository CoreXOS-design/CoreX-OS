/**
 * "Stops here (say why)" (road-to-sold.md 23.25 / 23.11, Johan 3 Nov 2026). ONE control, drawn by ONE function (CorexOffRamp.mount) wherever it appears: inside the pitch popup (an
 * inline fold in the popup's own bar, on every step, from both entry points) and on the Road to Sold card. It lists the agency's reasons for the step as radios, "Other" last; the
 * note is optional, and required (a few words) for Other. Nothing is decided here: the server leaves the belt, closes the claim, marks the property and counts it.
 */
(function () {
    'use strict';
    var cfg = window.CorexPitchConfig || {};
    var NAMES = ['Property', 'Owner', 'Pitch', 'Contact', 'Appointment', 'Presentation', 'Presentation results', 'Draft', 'Active'];

    var css = document.createElement('style');
    css.textContent =
        '.cxo-fold{flex:0 0 100%;box-sizing:border-box;max-height:42vh;overflow:auto;padding:10px 12px;border:1px solid var(--border,#cbd5e1);border-radius:8px;background:var(--surface,#fff);color:var(--text-primary,#0f172a);display:flex;flex-direction:column;gap:8px}' +
        '.cxo-fold[hidden]{display:none}' +
        '.cxo-title{font-size:.8125rem;font-weight:700}' +
        '.cxo-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:4px 16px}' +
        '.cxo-opt{display:flex;align-items:center;gap:8px;font-size:.8125rem;cursor:pointer}' +
        '.cxo-fold label.cxo-lab{font-size:.75rem;font-weight:700;color:var(--text-secondary,#475569)}' +
        '.cxo-fold input[type=text]{width:100%;height:2rem;padding:0 8px;border:1px solid var(--border,#cbd5e1);border-radius:6px;background:var(--surface,#fff);color:var(--text-primary,#0f172a);font-size:.8125rem;box-sizing:border-box}' +
        '.cxo-msg{font-size:.8125rem}.cxo-err{color:#dc2626;font-weight:600}' +
        '.cxo-row{display:flex;gap:8px;justify-content:flex-end}';
    document.head.appendChild(css);

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') n.textContent = attrs[k]; else n.setAttribute(k, attrs[k]); });
        (kids || []).forEach(function (c) { n.appendChild(c); });
        return n;
    }
    function toast(text) {
        var t = el('div', { role: 'status', 'data-qa': 'offramp-toast', 'class': 'cxp-toast', text: text });
        t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:1200;padding:10px 18px;border-radius:8px;background:#0f766e;color:#fff;font-size:.9375rem;font-weight:700;box-shadow:0 6px 24px rgba(0,0,0,.3);transition:opacity .4s';
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 7000);
    }
    try { var said = sessionStorage.getItem('cxOffRamp'); if (said) { sessionStorage.removeItem('cxOffRamp'); if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { toast(said); }); else toast(said); } } catch (e) { /* no storage */ }

    /**
     * Draw the control into $host.  o = { url: where the reasons come from / the off-ramp goes, query: URLSearchParams (who: prospect | listing | tracked), step: 1-9, onDone(message), onCancel() }
     */
    function mount(host, o) {
        host.innerHTML = '';
        host.appendChild(el('div', { 'class': 'cxo-msg', text: 'One moment...' }));
        var q = new URLSearchParams(o.query ? o.query.toString() : ''); q.set('step', String(o.step));
        fetch(o.url + (o.url.indexOf('?') < 0 ? '?' : '&') + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { host.innerHTML = ''; if (!d) { host.appendChild(el('div', { 'class': 'cxo-msg', text: 'The reasons could not be loaded. Close this and try again.' })); return; } render(host, o, d); })
            .catch(function () { host.innerHTML = ''; host.appendChild(el('div', { 'class': 'cxo-msg', text: 'The reasons could not be loaded. Close this and try again.' })); });
    }

    function render(host, o, d) {
        var no = el('button', { type: 'button', 'class': 'cxp-btn', text: 'Cancel' });
        no.addEventListener('click', function () { if (o.onCancel) o.onCancel(); });
        if (!d.record || d.record.stopped || !d.reasons.length) {
            host.appendChild(el('div', { 'class': 'cxo-msg', 'data-qa': 'offramp-none', text: d.message || 'There is nothing to stop here.' }));
            host.appendChild(el('div', { 'class': 'cxo-row' }, [no]));
            return;
        }
        host.appendChild(el('div', { 'class': 'cxo-title', text: 'Stops here - ' + (NAMES[d.step - 1] || '') + '. Why?' }));
        var list = el('div', { 'class': 'cxo-list', role: 'radiogroup', 'aria-label': 'Why does it stop here?', 'data-qa': 'offramp-reasons' });
        var name = 'cxo-reason-' + Math.random().toString(36).slice(2, 7);
        d.reasons.forEach(function (r, i) {
            var inp = el('input', { type: 'radio', name: name, value: r.key, 'data-other': r.other ? '1' : '0', id: name + '-' + i });
            list.appendChild(el('label', { 'class': 'cxo-opt', 'data-qa': 'offramp-reason', 'for': name + '-' + i }, [inp, el('span', { text: r.label })]));
        });
        var noteLab = el('label', { 'class': 'cxo-lab', 'for': name + '-note', text: 'Note (optional)' });
        var note = el('input', { type: 'text', id: name + '-note', maxlength: '255', autocomplete: 'off', 'data-qa': 'offramp-note' });
        var err = el('div', { 'class': 'cxo-msg cxo-err', 'data-qa': 'offramp-error', role: 'alert' });
        var go = el('button', { type: 'button', 'class': 'cxp-btn cxp-primary', 'data-qa': 'offramp-go', text: 'Stop here' });
        function chosen() { return host.querySelector('input[type=radio]:checked'); }
        list.addEventListener('change', function () {
            var c = chosen(); var other = c && c.getAttribute('data-other') === '1';
            noteLab.textContent = other ? 'Say why (a few words - required)' : 'Note (optional)'; err.textContent = '';
            if (other) note.focus();
        });
        go.addEventListener('click', function () {
            err.textContent = '';
            var c = chosen();
            if (!c) { err.textContent = 'Pick why it stops here.'; return; }
            var other = c.getAttribute('data-other') === '1';
            if (other && note.value.trim().length < 3) { err.textContent = 'Say in a few words why it stops here.'; note.focus(); return; }
            go.disabled = true;
            var body = new URLSearchParams(o.query ? o.query.toString() : ''); body.set('step', String(d.step)); body.set('reason', c.value); if (note.value.trim()) body.set('note', note.value.trim());
            var token = document.querySelector('meta[name="csrf-token"]');
            fetch(o.post || o.url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token ? token.content : '' }, body: body.toString() })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
                .then(function (x) {
                    if (!x.ok) { go.disabled = false; err.textContent = (x.j && (x.j.message || (x.j.errors && Object.values(x.j.errors)[0][0]))) || 'It could not be saved. Try again.'; return; }
                    try { sessionStorage.setItem('cxOffRamp', x.j.message); } catch (e) { /* no storage */ }
                    if (o.onDone) o.onDone(x.j.message);
                })
                .catch(function () { go.disabled = false; err.textContent = 'It could not be saved. Try again.'; });
        });
        [list, noteLab, note, err, el('div', { 'class': 'cxo-row' }, [no, go])].forEach(function (n) { host.appendChild(n); });
    }

    window.CorexOffRamp = { mount: mount };

    // ---- the pitch popup: the control is added to the popup's own bar, the reasons unfold INSIDE the popup (never a floating panel) ------------------
    function stepNow(back) {
        var pills = [].slice.call(back.querySelectorAll('[data-qa="pitch-steps"] [data-step]'));
        for (var i = 0; i < pills.length; i++) { if (pills[i].getAttribute('data-state') === 'current') return i + 1; }
        return 1;
    }
    function identity() {
        var st = window.CorexPitch && window.CorexPitch._state ? window.CorexPitch._state() : null;
        var q = new URLSearchParams(st && st.query ? st.query : '');
        if (!q.toString() && st && st.o) {
            var o = st.o, nx = String(o.next || ''), m;
            if (o.prospect) q.set('prospect', o.prospect);
            else if ((m = nx.match(/\/prospects\/(\d+)\//) || nx.match(/rts_prospect=(\d+)/))) q.set('prospect', m[1]);
            if (o.listing) q.set('listing', o.listing);
            if (o.tracked) q.set('tracked', o.tracked);
        }
        return q;
    }
    function attach(back) {
        var bar = back.querySelector('.cxp-bar');
        if (!cfg.offramp || !bar || bar.querySelector('[data-qa="offramp"]')) return;
        var close = bar.querySelector('[data-qa="pitch-close"]');
        var btn = el('button', { type: 'button', 'class': 'cxp-btn', 'data-qa': 'offramp-open', 'aria-expanded': 'false', text: 'Stops here (say why) ▾' });
        var wrap = el('div', { 'data-qa': 'offramp', style: 'display:inline-block' }, [btn]);
        var fold = el('div', { 'class': 'cxo-fold', 'data-qa': 'offramp-panel', hidden: 'hidden' });
        bar.insertBefore(wrap, close || null);
        bar.appendChild(fold);   // the bar wraps: the fold takes its own full-width row inside the popup
        function shut() { fold.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        btn.addEventListener('click', function () {
            if (!fold.hidden) { shut(); return; }
            fold.hidden = false; btn.setAttribute('aria-expanded', 'true');
            mount(fold, { url: cfg.offramp, query: identity(), step: stepNow(back), onCancel: shut, onDone: function () { if (window.CorexPitch) window.CorexPitch.close(false); window.location.reload(); } });
        });
        back.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fold.hidden) { e.stopPropagation(); shut(); } }, true);
    }

    new MutationObserver(function (muts) {
        muts.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && n.getAttribute('data-qa') === 'pitch-popup') attach(n); }); });
    }).observe(document.documentElement, { childList: true, subtree: true });
    var present = document.querySelector('[data-qa="pitch-popup"]'); if (present) attach(present);
})();
