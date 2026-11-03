/**
 * "Stops here (say why)" on every step of the pitch popup (road-to-sold.md 23.25 / 23.11, Johan 3 Nov 2026). ONE control, the same on every step and from both entry points
 * (a Road to Sold row, MIC Pitch now): it is added to the popup's own bar when the popup opens, so no step draws its own copy. It asks the server for the reasons of the step
 * the agent is on (the agency's own list, "Other" last), and "Other" needs a few words. Nothing is decided here: the server leaves the belt, closes the claim and counts it.
 */
(function () {
    'use strict';
    var cfg = window.CorexPitchConfig || {};
    if (!cfg.offramp) return;

    var css = document.createElement('style');
    css.textContent =
        '.cxo-wrap{position:relative;display:inline-block}' +
        '.cxo-panel{position:absolute;right:0;top:calc(100% + 6px);z-index:1100;width:21rem;max-width:90vw;padding:12px;border:1px solid var(--border,#cbd5e1);border-radius:8px;background:var(--surface,#fff);color:var(--text-primary,#0f172a);box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;flex-direction:column;gap:8px}' +
        '.cxo-panel label{font-size:.75rem;font-weight:700;color:var(--text-secondary,#475569)}' +
        '.cxo-panel select,.cxo-panel input{width:100%;height:2rem;padding:0 8px;border:1px solid var(--border,#cbd5e1);border-radius:6px;background:var(--surface,#fff);color:var(--text-primary,#0f172a);font-size:.8125rem}' +
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
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 7000);
    }
    try { var said = sessionStorage.getItem('cxOffRamp'); if (said) { sessionStorage.removeItem('cxOffRamp'); document.addEventListener('DOMContentLoaded', function () { toast(said); }); if (document.readyState !== 'loading') toast(said); } } catch (e) { /* no storage */ }

    // the step number the agent is on, read from the popup's own bar (the pill that is current; the first pill when none is yet)
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
        if (!bar || bar.querySelector('[data-qa="offramp"]')) return;
        var close = bar.querySelector('[data-qa="pitch-close"]');
        var btn = el('button', { type: 'button', 'class': 'cxp-btn', 'data-qa': 'offramp-open', 'aria-haspopup': 'true', 'aria-expanded': 'false', text: 'Stops here (say why) ▾' });
        var panel = el('div', { 'class': 'cxo-panel', 'data-qa': 'offramp-panel', hidden: 'hidden' });
        var wrap = el('div', { 'class': 'cxo-wrap', 'data-qa': 'offramp' }, [btn, panel]);
        bar.insertBefore(wrap, close || null);

        function render(d) {
            panel.innerHTML = '';
            if (!d.record || d.record.stopped || !d.reasons.length) {
                panel.appendChild(el('div', { 'class': 'cxo-msg', 'data-qa': 'offramp-none', text: d.message || 'There is nothing to stop here.' }));
                return;
            }
            var sel = el('select', { id: 'cxo-reason', 'data-qa': 'offramp-reason', 'aria-label': 'Why does it stop here?' });
            sel.appendChild(el('option', { value: '', text: 'Why does it stop here?' }));
            d.reasons.forEach(function (r) { var o = el('option', { value: r.key, text: r.label }); o.setAttribute('data-other', r.other ? '1' : '0'); sel.appendChild(o); });
            var note = el('input', { type: 'text', id: 'cxo-note', maxlength: '255', placeholder: 'A few words', autocomplete: 'off', 'data-qa': 'offramp-note' });
            var noteBox = el('div', { hidden: 'hidden' }, [el('label', { 'for': 'cxo-note', text: 'Say why (a few words)' }), note]);
            var err = el('div', { 'class': 'cxo-msg cxo-err', 'data-qa': 'offramp-error', role: 'alert' });
            var go = el('button', { type: 'button', 'class': 'cxp-btn cxp-primary', 'data-qa': 'offramp-go', text: 'Stop here' });
            var no = el('button', { type: 'button', 'class': 'cxp-btn', text: 'Cancel' });
            sel.addEventListener('change', function () { var other = sel.selectedOptions[0] && sel.selectedOptions[0].getAttribute('data-other') === '1'; noteBox.hidden = !other; err.textContent = ''; if (other) note.focus(); });
            no.addEventListener('click', function () { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); });
            go.addEventListener('click', function () {
                err.textContent = '';
                if (!sel.value) { err.textContent = 'Pick why it stops here.'; return; }
                var other = sel.selectedOptions[0].getAttribute('data-other') === '1';
                if (other && note.value.trim().length < 3) { err.textContent = 'Say in a few words why it stops here.'; return; }
                go.disabled = true;
                var body = identity(); body.set('step', String(d.step)); body.set('reason', sel.value); if (note.value.trim()) body.set('note', note.value.trim());
                var token = document.querySelector('meta[name="csrf-token"]');
                fetch(cfg.offramp, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token ? token.content : '' }, body: body.toString() })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
                    .then(function (x) {
                        if (!x.ok) { go.disabled = false; err.textContent = (x.j && (x.j.message || (x.j.errors && Object.values(x.j.errors)[0][0]))) || 'It could not be saved. Try again.'; return; }
                        try { sessionStorage.setItem('cxOffRamp', x.j.message); } catch (e) { /* no storage */ }
                        if (window.CorexPitch) window.CorexPitch.close(false);
                        window.location.reload();
                    })
                    .catch(function () { go.disabled = false; err.textContent = 'It could not be saved. Try again.'; });
            });
            [el('label', { 'for': 'cxo-reason', text: 'Stops here - ' + (d.title || '') }), sel, noteBox, err, el('div', { 'class': 'cxo-row' }, [no, go])].forEach(function (n) { panel.appendChild(n); });
        }

        btn.addEventListener('click', function () {
            if (!panel.hidden) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); return; }
            var q = identity(); q.set('step', String(stepNow(back)));
            panel.hidden = false; btn.setAttribute('aria-expanded', 'true');
            panel.innerHTML = ''; panel.appendChild(el('div', { 'class': 'cxo-msg', text: 'One moment...' }));
            fetch(cfg.offramp + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (!d) { panel.innerHTML = ''; panel.appendChild(el('div', { 'class': 'cxo-msg', text: 'The reasons could not be loaded. Close this and try again.' })); return; }
                    var names = ['Property', 'Owner', 'Pitch', 'Contact', 'Appointment', 'Presentation', 'Presentation results', 'Draft', 'Active'];
                    d.title = names[d.step - 1] || ''; render(d); });
        });
        back.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { e.stopPropagation(); panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } }, true);
    }

    new MutationObserver(function (muts) {
        muts.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && n.getAttribute('data-qa') === 'pitch-popup') attach(n); }); });
    }).observe(document.documentElement, { childList: true, subtree: true });
    var present = document.querySelector('[data-qa="pitch-popup"]'); if (present) attach(present);
})();
