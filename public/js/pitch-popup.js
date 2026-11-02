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
    var STEPS = [['property', 'Property'], ['owner', 'Owner'], ['pitch', 'Pitch'], ['contact', 'Contact']];
    var NEXT_LABEL = { property: 'Next: find the owner', owner: '', pitch: '', contact: '' };
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
        '.cxp-frame{flex:1;width:100%;border:0;background:var(--bg,#f8fafc)}' +
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
    function open(o) {
        if (pop) return;
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
        var title = el('div', { 'class': 'cxp-title', text: o.label || 'Pitch' });
        var nextBtn = el('button', { type: 'button', 'class': 'cxp-btn cxp-primary', 'data-qa': 'pitch-next' });
        var closeBtn = el('button', { type: 'button', 'class': 'cxp-btn', 'aria-label': 'Close and go back', 'data-qa': 'pitch-close', text: 'Close' });
        var frame = el('iframe', { 'class': 'cxp-frame', title: 'Pitch', 'data-qa': 'pitch-frame' });
        var win = el('div', { 'class': 'cxp-win' }, [el('div', { 'class': 'cxp-bar' }, [title, steps, nextBtn, closeBtn]), frame]);
        var back = el('div', { 'class': 'cxp-back', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Pitch', 'data-qa': 'pitch-popup' }, [win]);
        document.body.appendChild(back);
        document.documentElement.style.overflow = 'hidden';

        pop = { back: back, frame: frame, opener: document.activeElement, touched: false, o: o, current: startAtCard ? 'property' : 'owner', reached: startAtCard ? 0 : 1, loads: 0 };

        function paint() {
            var idx = STEPS.map(function (s) { return s[0]; }).indexOf(pop.current);
            STEPS.forEach(function (s, i) {
                var b = stepEls[s[0]];
                var state = i < idx || (!hasCard && i === 0) ? 'done' : (i === idx ? 'current' : 'todo');
                b.setAttribute('data-state', state);
                b.setAttribute('aria-current', state === 'current' ? 'step' : 'false');
                b.firstChild.textContent = state === 'done' ? '✓' : String(i + 1);
                b.setAttribute('data-click', s[0] === 'property' && hasCard && state !== 'current' ? '1' : '0');
            });
            var label = NEXT_LABEL[pop.current];
            nextBtn.style.display = label && next ? '' : 'none';
            nextBtn.textContent = (label || '') + ' →';
        }
        stepEls.property.addEventListener('click', function () {
            if (stepEls.property.getAttribute('data-click') === '1') frame.src = cardUrl(o);
        });
        nextBtn.addEventListener('click', function () { if (next) frame.src = withEmbed(next); });
        closeBtn.addEventListener('click', function () { close(false); });
        frame.addEventListener('load', function () {
            pop.loads++;
            var href = ''; try { href = frame.contentWindow.location.href; } catch (e) { return; }
            if (href === 'about:blank') return;
            var s = stepOf(href);
            if (s) pop.current = s;
            pop.touched = pop.loads > 1;
            paint();
        });
        pop.keydown = function (e) { if (e.key === 'Escape' && !document.querySelector('.cxp-back ~ .cxp-back')) close(false); };
        document.addEventListener('keydown', pop.keydown);
        paint();
        frame.src = first;
        closeBtn.focus();
    }

    function close(done) {
        if (!pop) return;
        var p = pop; pop = null;
        document.removeEventListener('keydown', p.keydown);
        p.frame.src = 'about:blank';
        p.back.remove();
        document.documentElement.style.overflow = '';
        if (p.opener && p.opener.focus) { try { p.opener.focus(); } catch (e) { /* gone */ } }
        window.dispatchEvent(new CustomEvent('corex-pitch-closed', { detail: { listing: p.o.listing || null, touched: p.touched || done === true } }));
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
        if (e.origin !== window.location.origin || !e.data || e.data.rts !== 'done' || !pop || e.source !== pop.frame.contentWindow) return;
        close(true);
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
