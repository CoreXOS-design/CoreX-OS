/**
 * The pitch popup (road-to-sold.md 23.23). ONE popup over the page the agent started on: Property · Owner · Pitch · Contact, each step the existing
 * CoreX screen in an iframe (embed mode, same origin only). Also the "What is this property?" window the header of those screens opens.
 *
 *   <a href="..." data-pitch-popup data-listing="123">Pitch now</a>       opens at the property card, Next goes to href
 *   <a href="..." data-pitch-popup data-start="owner">Find the owner</a>   opens straight at the href (no advert to show)
 *   <div data-pitch-peek data-listing="123">  the header: unfolds the card under itself, in place
 *
 * The step is always read from the screen being shown (never stored). Done / Close return to the page, which is refreshed in place.
 * The header block ([data-pitch-peek]) is not a popup: it unfolds the card under itself (an expander) and folds again on a second click.
 */
(function () {
    'use strict';
    if (window.CorexPitch) return;
    var cfg = window.CorexPitchConfig || {};
    var STEPS = [['property', 'Property'], ['owner', 'Owner'], ['pitch', 'Pitch'], ['contact', 'Contact'], ['evaluation', 'Appointment']];
    var NEXT_LABEL = { property: 'Next: find the owner', owner: '', pitch: '', contact: '', evaluation: '' };
    var pop = null;   // the open popup, or null

    function sameSite(u) { try { return new URL(u, window.location.href).origin === window.location.origin; } catch (e) { return false; } }
    function withEmbed(u) { var x = new URL(u, window.location.href); x.searchParams.set('embed', '1'); return x.toString(); }
    function cardUrl(p, peek) {
        var x = new URL(cfg.card || '/corex/market-intelligence/work', window.location.origin);
        if (p.listing) x.searchParams.set('_pitch_card', p.listing);
        else if (p.property) x.searchParams.set('_pitch_property', p.property);
        else if (p.tracked) x.searchParams.set('_pitch_tracked', p.tracked);
        x.searchParams.set('embed', '1');
        if (peek) x.searchParams.set('peek', '1');
        return x.toString();
    }
    function stepOf(href) {
        var u; try { u = new URL(href, window.location.href); } catch (e) { return null; }
        if (u.searchParams.has('_pitch_card') || u.searchParams.has('_pitch_property') || u.searchParams.has('_pitch_tracked')) return 'property';
        if (/\/outreach\/pitch-ready$/.test(u.pathname)) return 'pitch';
        if (/\/outreach\/evaluation-booked\//.test(u.pathname) || (/\/command-center\/calendar$/.test(u.pathname) && u.searchParams.has('pitch_book'))) return 'evaluation';
        if (/\/contacts\/\d+\/outreach\//.test(u.pathname)) return 'contact';
        if (/\/outreach\/compose$/.test(u.pathname)) return 'owner';
        return null;
    }

    var css = document.createElement('style');
    css.textContent =
        '.cxp-back{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:16px}' +
        '.cxp-win{display:flex;flex-direction:column;width:100%;max-width:72rem;height:92vh;background:var(--surface,#fff);color:var(--text-primary,#0f172a);border:1px solid var(--border,#cbd5e1);border-radius:8px;overflow:hidden}' +
        '.cxp-bar{display:flex;align-items:center;gap:12px;padding:8px 14px;border-bottom:1px solid var(--border,#cbd5e1);flex-wrap:wrap}' +
        '.cxp-title{font-size:.875rem;font-weight:700;margin-right:auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
        '.cxp-steps{display:flex;gap:6px;align-items:center;flex-wrap:wrap}' +
        '.cxp-step{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border:1px solid var(--border,#cbd5e1);border-radius:999px;font-size:.8125rem;font-weight:600;background:transparent;color:var(--text-secondary,#475569);cursor:default}' +
        '.cxp-step[data-state="current"]{background:var(--brand-button,#0ea5e9);border-color:var(--brand-button,#0ea5e9);color:#fff}' +
        '.cxp-step[data-state="done"]{color:var(--text-primary,#0f172a)}' +
        '.cxp-step[data-click="1"]{cursor:pointer}' +
        '.cxp-step[data-click="1"]:hover{border-color:var(--brand-button,#0ea5e9)}' +
        '.cxp-body{position:relative;flex:1;min-height:0;display:flex}' +
        '.cxp-frame{flex:1;width:100%;border:0;background:var(--bg,#f8fafc)}' +
        '.cxp-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg,#f8fafc);color:var(--text-secondary,#475569);font-size:.9375rem;font-weight:600}' +
        '.cxp-toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:1200;padding:10px 18px;border-radius:8px;background:#0f766e;color:#fff;font-size:.9375rem;font-weight:700;box-shadow:0 6px 24px rgba(0,0,0,.35);transition:opacity .4s}' +
        '.cxp-btn{padding:5px 14px;border-radius:6px;font-size:.8125rem;font-weight:700;cursor:pointer;border:1px solid var(--border,#cbd5e1);background:var(--surface,#fff);color:var(--text-primary,#0f172a)}' +
        '.cxp-btn.cxp-primary{background:var(--brand-button,#0ea5e9);border-color:var(--brand-button,#0ea5e9);color:#fff}';
    document.head.appendChild(css);

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') n.textContent = attrs[k]; else n.setAttribute(k, attrs[k]); });
        (kids || []).forEach(function (c) { n.appendChild(c); });
        return n;
    }

    // ---- the popup ---------------------------------------------------------------------------------------------------------------------------------
    // A step pill is ticked when its STATION is reached (road-to-sold.md 23.25.3): the server says which ('reached', from the record's stage), the popup draws
    // that and nothing else - a screen merely opened ticks nothing. A pill is a control when its screen is known: the ones loaded here, Property when the
    // property has an advert, the current one (it reloads that screen). The step key 'evaluation' is a stored name; the agent reads "Appointment".
    function open(o) {
        if (pop) return;
        // From a Road to Sold row the card comes FIRST (Johan 2 Nov): the row may not know its advert (a link, a card button), so ask the plan which advert the
        // record has and open on that card; only Market Intelligence's own "Pitch now" opens straight at Owner (data-start="owner").
        if (o.origin === 'rts' && !(o.listing || o.property || o.tracked) && cfg.steps) {
            var nx = String(o.next || '');
            var q0 = new URLSearchParams(), m;
            if ((m = nx.match(/\/prospects\/(\d+)\//) || nx.match(/rts_prospect=(\d+)/))) q0.set('prospect', m[1]);
            else if ((m = nx.match(/\/tracked-properties\/(\d+)\//))) q0.set('tracked', m[1]);
            else if ((m = nx.match(/\/prospecting\/(\d+)\//))) q0.set('listing', m[1]);
            if (q0.toString()) {
                fetch(cfg.steps + '?' + q0.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) { var lm = d && d.steps && d.steps.property ? String(d.steps.property).match(/_pitch_card=(\d+)/) : null; open(Object.assign({}, o, { origin: null, listing: lm ? lm[1] : null, prospect: q0.get('prospect') || o.prospect })); })
                    .catch(function () { open(Object.assign({}, o, { origin: null })); });
                return;
            }
        }
        var next = o.next && sameSite(o.next) ? o.next : null;
        var hasCard = !!(o.listing || o.property || o.tracked);
        var startAtCard = hasCard && o.start !== 'owner';
        var first = startAtCard ? cardUrl(o) : (next ? withEmbed(next) : null);
        if (!first) return;

        var steps = el('div', { 'class': 'cxp-steps', 'data-qa': 'pitch-steps' });
        var stepEls = {};
        STEPS.forEach(function (s, i) {
            var b = el('button', { type: 'button', 'class': 'cxp-step', 'data-step': s[0], 'data-state': 'todo' });
            b.appendChild(el('span', { text: String(i + 1), 'aria-hidden': 'true' }));
            b.appendChild(el('span', { text: s[1] }));
            stepEls[s[0]] = b; steps.appendChild(b);
        });
        var title = el('div', { 'class': 'cxp-title', text: 'Pitch' + (o.label && /\s-\s/.test(o.label) ? ' · ' + o.label.replace(/^.*?\s-\s/, '') : '') });
        var nextBtn = el('button', { type: 'button', 'class': 'cxp-btn cxp-primary', 'data-qa': 'pitch-next' });
        var closeBtn = el('button', { type: 'button', 'class': 'cxp-btn', 'aria-label': 'Close and go back', 'data-qa': 'pitch-close', text: 'Close' });
        var frame = el('iframe', { 'class': 'cxp-frame', title: 'Pitch', 'data-qa': 'pitch-frame' });
        var loading = el('div', { 'class': 'cxp-loading', role: 'status', 'data-qa': 'pitch-loading', text: 'Opening…' });
        var body = el('div', { 'class': 'cxp-body' }, [frame, loading]);
        var win = el('div', { 'class': 'cxp-win' }, [el('div', { 'class': 'cxp-bar' }, [title, steps, nextBtn, closeBtn]), body]);
        var back = el('div', { 'class': 'cxp-back', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Pitch', 'data-qa': 'pitch-popup' }, [win]);
        document.body.appendChild(back);
        document.documentElement.style.overflow = 'hidden';

        var me = pop = {
            back: back, frame: frame, opener: document.activeElement, o: o, loads: 0, results: [],
            // the screen we are on is not known until it has loaded, except when we open the card ourselves
            current: startAtCard ? 'property' : null, reached: {}, urls: {}, planSeq: 0,
        };
        if (hasCard) { pop.urls.property = cardUrl(o); }

        function go(url) { loading.style.display = ''; frame.src = url; }
        function paint() {
            var idx = pop.current ? STEPS.map(function (s) { return s[0]; }).indexOf(pop.current) : -1;
            STEPS.forEach(function (s, i) {
                var b = stepEls[s[0]];
                var state = s[0] === pop.current ? 'current' : (pop.reached[s[0]] === true ? 'done' : 'todo');
                b.setAttribute('data-state', state);
                b.setAttribute('aria-current', state === 'current' ? 'step' : 'false');
                b.firstChild.textContent = state === 'done' ? '✓' : String(i + 1);
                var clickable = !!pop.urls[s[0]];
                b.setAttribute('data-click', clickable ? '1' : '0');
                b.style.cursor = clickable ? 'pointer' : 'default';
            });
            var label = pop.current ? NEXT_LABEL[pop.current] : '';
            nextBtn.style.display = label && next ? '' : 'none';
            nextBtn.textContent = (label || '') + ' →';
            void idx;
        }
        STEPS.forEach(function (s) {
            stepEls[s[0]].addEventListener('click', function () { if (pop && pop.urls[s[0]]) go(pop.urls[s[0]]); });
        });
        nextBtn.addEventListener('click', function () { if (next) go(withEmbed(next)); });
        closeBtn.addEventListener('click', function () { close(false); });
        frame.addEventListener('load', function () {
            if (!pop) return;
            var href = ''; try { href = frame.contentWindow.location.href; } catch (e) { loading.style.display = 'none'; return; }
            if (href === 'about:blank') return;
            pop.loads++;
            loading.style.display = 'none';
            var st = stepOf(href);
            if (st) { pop.current = st; pop.urls[st] = href; }
            // any move from inside the screen (a form saved, a link followed) shows the loading line until the next one is up
            try { frame.contentWindow.addEventListener('beforeunload', function () { if (pop) loading.style.display = ''; }); } catch (e) { /* not same origin */ }
            paint();
            // the ticks follow the record: ask again after every screen (a save redirects to the next one)
            if (pop.plan && pop.loads > 1) { pop.plan(); }
        });
        pop.keydown = function (e) { if (e.key === 'Escape') close(false); };
        document.addEventListener('keydown', pop.keydown);
        pop.repaint = paint;
        paint();
        frame.src = first;
        // which screens exist for THIS record: every other pill is a control when it has one, a plain label when it has none
        var prospect = o.prospect || ((String(o.next || '').match(/\/prospects\/(\d+)\//) || String(o.next || '').match(/rts_prospect=(\d+)/) || [])[1]) || '';
        if (cfg.steps && (o.listing || o.tracked || prospect)) {
            var q = new URLSearchParams();
            if (o.listing) q.set('listing', o.listing);
            if (o.tracked) q.set('tracked', o.tracked);
            if (prospect) q.set('prospect', prospect);
            pop.planQuery = q.toString();
            pop.plan = function () { var seq = ++pop.planSeq; return fetch(cfg.steps + '?' + pop.planQuery, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.steps || pop !== me || seq !== pop.planSeq) return;
                    // one title for every step: the property's address, not the label of the button that opened it ("Find the owner - ..." stayed on the Pitch step)
                    if (d.steps.address) { title.textContent = 'Pitch · ' + d.steps.address; }
                    Object.keys(d.steps).forEach(function (k) { if (typeof d.steps[k] === 'string' && k !== 'address' && !pop.urls[k]) pop.urls[k] = d.steps[k]; });
                    pop.reached = d.steps.reached && typeof d.steps.reached === 'object' ? d.steps.reached : {};
                    paint();
                })
                .catch(function () { /* the pills stay as they are: the screens still work */ }); };
            pop.plan();
        }
        closeBtn.focus();
    }

    function toast(text) {
        var old = document.querySelector('[data-qa="pitch-toast"]'); if (old) old.remove();
        var t = el('div', { role: 'status', 'data-qa': 'pitch-toast', 'class': 'cxp-toast', text: text });
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 7000);
    }

    function close(done) {
        if (!pop) return;
        var p = pop; pop = null;
        document.removeEventListener('keydown', p.keydown);
        p.frame.src = 'about:blank';
        p.back.remove();
        document.documentElement.style.overflow = '';
        if (p.opener && p.opener.focus) { try { p.opener.focus(); } catch (e) { /* gone */ } }
        // Done / Close say what happened: "Pitched · 73 Marine Drive", "Contact logged · 73 Marine Drive", or that nothing was sent
        var where = (p.results.length && p.results[p.results.length - 1].address) || (p.o.label || '').replace(/^Pitch\s*-\s*/i, '');
        if (p.results.length) {
            var kinds = {}, booked = null, claimed = null; p.results.forEach(function (r) { kinds[r.kind] = true; if (r.kind === 'booked') booked = r; if (r.kind === 'claimed') claimed = r; });
            if (!booked && !kinds.pitched && !kinds.logged && claimed) { toast('Claimed · ' + (claimed.address || where) + ' · yours for ' + (claimed.detail || 'the hold period')); }
            else if (booked) { toast('Appointment booked · ' + booked.detail + ' · ' + (booked.address || where)); }
            else { toast((kinds.pitched ? 'Pitched' : 'Contact logged') + (where ? ' · ' + where : '') + (kinds.pitched && kinds.logged ? ' (and a call logged)' : '')); }
        } else if (done === true) {
            toast('Done' + (where ? ' · ' + where : '') + ' - nothing was sent or logged.');
        }
        window.dispatchEvent(new CustomEvent('corex-pitch-closed', { detail: { listing: p.o.listing || null, touched: p.results.length > 0 || p.loads > 1 || done === true, results: p.results } }));
    }

    // ---- "What is this property?": the card unfolds under the header, in place; a second click folds it ---------------------------------------------------
    function toggleCard(head) {
        var open = head.nextElementSibling && head.nextElementSibling.hasAttribute('data-pitch-expander');
        var hint = head.querySelector('[data-peek-hint]');
        if (open) {
            head.nextElementSibling.remove();
            head.setAttribute('aria-expanded', 'false');
            if (hint) hint.textContent = 'What is this property? \u25BE';
            return;
        }
        var url = cardUrl({ listing: head.getAttribute('data-listing') }, true);
        var frame = el('iframe', { title: 'The property', src: url, 'data-qa': 'peek-frame', style: 'display:block;width:100%;height:360px;border:0;background:transparent' });
        var box = el('div', { 'data-pitch-expander': '', 'data-qa': 'peek-expander', style: 'margin-top:8px;border:1px solid var(--border,#cbd5e1);border-radius:6px;overflow:hidden;background:var(--bg,#f8fafc)' }, [frame]);
        head.parentNode.insertBefore(box, head.nextSibling);
        head.setAttribute('aria-expanded', 'true');
        if (hint) hint.textContent = 'What is this property? \u25B4';
        frame.addEventListener('load', function () {
            try {
                var d = frame.contentDocument, card = d.querySelector('[data-qa="pitch-card"]');
                var fit = function () { frame.style.height = Math.max(360, Math.ceil((card ? card.getBoundingClientRect().height : 0) + 40)) + 'px'; };
                fit();
                if (window.ResizeObserver && card) new ResizeObserver(fit).observe(card);
            } catch (e) { /* not same origin: keep the default height */ }
        });
    }

    // ---- wiring ------------------------------------------------------------------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        if (e.button || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var t = e.target && e.target.closest ? e.target : null;
        if (!t) return;
        var pk = t.closest('[data-pitch-peek]');
        if (pk) {
            e.preventDefault();
            toggleCard(pk);
            return;
        }
        var a = t.closest('a[data-pitch-popup]');
        if (!a || !sameSite(a.href)) return;
        e.preventDefault(); e.stopPropagation();
        open({
            next: a.href, listing: a.getAttribute('data-listing'), property: a.getAttribute('data-property'), tracked: a.getAttribute('data-tracked'),
            start: a.getAttribute('data-start'), label: a.getAttribute('data-label') || (a.getAttribute('title') || a.textContent || '').trim().slice(0, 80) || 'Pitch',
        });
    }, true);

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.hasAttribute && e.target.hasAttribute('data-pitch-peek')) { e.preventDefault(); e.target.click(); }
    });

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !e.data || !pop || e.source !== pop.frame.contentWindow) return;
        if (e.data.pitch === 'go') {
            var u = pop.urls[e.data.step];
            if (u) { var x = new URL(u, window.location.href); if (e.data.need) x.searchParams.set('need', String(e.data.need)); pop.frame.src = x.toString(); }
            return;
        }
        if (e.data.pitch === 'book') { if (e.data.url && sameSite(e.data.url) && !pop.urls.evaluation) { pop.urls.evaluation = e.data.url; if (pop.repaint) pop.repaint(); } return; }
        if (e.data.pitch === 'result') { var rk = ['logged', 'booked', 'claimed'].indexOf(e.data.kind) >= 0 ? e.data.kind : 'pitched';
            pop.results.push({ kind: rk, address: String(e.data.address || ''), detail: String(e.data.detail || '') });
            if (pop.plan) { pop.plan(); }   // a save just changed the record: the ticks follow
            // claiming is said the moment it happens: the agent is looking at the screen that did it
            if (rk === 'claimed') { toast('Claimed · ' + (e.data.address || 'this property') + ' · yours for ' + (e.data.detail || 'the hold period')); }
            return; }
        if (e.data.rts === 'done') close(true);
    });

    // closing returns to the list the agent came from, refreshed in place (the MIC list); any other page reloads
    window.addEventListener('corex-pitch-closed', function (e) {
        if (!e.detail || !e.detail.touched) return;
        var host = document.getElementById('mic-slot-listings');
        if (!host || !/market-intelligence/.test(window.location.pathname)) { if (!window.__cxpNoReload) window.location.reload(); return; }
        var u = window.location.pathname + window.location.search;
        u += (u.indexOf('?') === -1 ? '?' : '&') + '_fragments=1';
        fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                if (typeof d.listings === 'string' && d.listings !== '') host.innerHTML = d.listings;
                var stats = document.getElementById('mic-slot-stats');
                if (stats && typeof d.statsStrip === 'string' && d.statsStrip !== '') stats.innerHTML = d.statsStrip;
            })
            .catch(function () { window.location.reload(); });
    });

    window.CorexPitch = { open: open, close: close, toggleCard: toggleCard, stepOf: stepOf, _state: function () { return pop ? { current: pop.current, touched: pop.touched } : null; } };
})();
