/**
 * MIC Adverts card - "Get full details", the photo gallery and the full-ad window (spec .ai/specs/mic-work-tab.md 13.16 / 13.16b).
 *
 * One delegated listener on the document, so it keeps working after the tick refresh swaps the list.
 *
 * Get full details: the page asks the CoreX Chrome extension (3.16+) through window.postMessage - no extension id - to read ONE advert page in a
 * background tab. The extension posts what it read to CoreX and answers; the card's row is then re-fetched and swapped in place.
 *   no extension / old extension  -> the advert opens in a new tab and a one-line note says where to install it
 *   30 s without an answer        -> "Couldn't read - open advert"
 *   one read at a time per browser; further clicks wait their turn
 */
(function () {
    'use strict';
    if (window.__micAdvertFetch) return;
    window.__micAdvertFetch = true;

    var TIMEOUT_MS = 30000;
    var PING_MS = 700;
    var extReady = false;
    var extVersion = null;
    var waiting = {};        // request id -> callback
    var chain = Promise.resolve();
    var seq = 0;

    window.addEventListener('message', function (ev) {
        if (ev.source !== window || ev.origin !== window.location.origin) return;
        var d = ev.data;
        if (!d || d.source !== 'corex-ext') return;
        if (d.type === 'pong') { extReady = true; extVersion = d.version || null; }
        if (d.type === 'read-advert-result' && waiting[d.id]) { var cb = waiting[d.id]; delete waiting[d.id]; cb(d); }
    });

    function send(msg) { window.postMessage(Object.assign({ source: 'corex-page' }, msg), window.location.origin); }

    function extensionPresent() {
        if (extReady) return Promise.resolve(true);
        return new Promise(function (resolve) {
            send({ type: 'ping' });
            var t0 = Date.now();
            (function wait() {
                if (extReady) return resolve(true);
                if (Date.now() - t0 > PING_MS) return resolve(false);
                setTimeout(wait, 60);
            })();
        });
    }

    // ---- the label next to the button -------------------------------------------------------------------------------------------------------------------------
    function status(wrap, html, colour) {
        var s = wrap.querySelector('[data-advert-status]');
        if (!s) return;
        s.style.display = html ? 'inline' : 'none';
        s.style.color = colour || 'var(--text-secondary)';
        s.innerHTML = html || '';
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function spinner(text) {
        return '<span style="display:inline-block; width:10px; height:10px; border:2px solid var(--border); border-top-color: var(--brand-icon); border-radius:50%; animation: micAdvSpin .8s linear infinite; vertical-align:-1px;"></span> ' + esc(text);
    }
    (function css() {
        var st = document.createElement('style');
        st.textContent = '@keyframes micAdvSpin{to{transform:rotate(360deg)}}';
        document.head.appendChild(st);
    })();

    function portalName(p) { return p === 'pp' ? 'Private Property' : 'P24'; }

    function openAdvertLink(btn) {
        return '<a href="' + esc(btn.getAttribute('data-url')) + '" target="_blank" rel="noopener" style="color: var(--brand-icon);">open advert</a>';
    }

    function failure(wrap, btn, reason) {
        btn.style.display = '';
        if (reason === 'not_set_up' || reason === 'wrong_site') {
            status(wrap, 'Sign the CoreX Chrome extension in to this site first (<a href="' + esc(wrap.getAttribute('data-ext-help')) + '" style="color: var(--brand-icon);">My Portal → Chrome extension</a>) · ' + openAdvertLink(btn), 'var(--ds-amber, #b45309)');
        } else if (reason === 'no_permission') {
            status(wrap, 'You do not have permission to read advert details · ' + openAdvertLink(btn), 'var(--ds-crimson, #dc2626)');
        } else {
            status(wrap, 'Couldn’t read - ' + openAdvertLink(btn), 'var(--ds-crimson, #dc2626)');
        }
    }

    // ---- the read ---------------------------------------------------------------------------------------------------------------------------------------------
    function runJob(wrap, btn) {
        return new Promise(function (resolve) {
            var id = 'r' + (++seq);
            var timer = setTimeout(function () { delete waiting[id]; failure(wrap, btn, 'timeout'); resolve(); }, TIMEOUT_MS);
            status(wrap, spinner('Reading ' + portalName(btn.getAttribute('data-portal')) + '…'));
            waiting[id] = function (res) {
                clearTimeout(timer);
                if (res.ok) { refreshRow(wrap, btn).then(resolve); } else { failure(wrap, btn, res.reason); resolve(); }
            };
            send({ type: 'read-advert', id: id, portal: btn.getAttribute('data-portal'), ref: btn.getAttribute('data-ref'), url: btn.getAttribute('data-url') });
        });
    }

    function startRead(btn) {
        var wrap = btn.closest('[data-advert-fetch-wrap]');
        if (!wrap || wrap.getAttribute('data-busy')) return;
        wrap.setAttribute('data-busy', '1');
        btn.style.display = 'none';
        status(wrap, spinner('Checking the extension…'));
        extensionPresent().then(function (present) {
            if (!present) {
                wrap.removeAttribute('data-busy');
                btn.style.display = '';
                window.open(btn.getAttribute('data-url'), '_blank', 'noopener');
                status(wrap, 'Install the <a href="' + esc(wrap.getAttribute('data-ext-help')) + '" style="color: var(--brand-icon);">CoreX Chrome extension</a> for one-click details', 'var(--text-secondary)');
                return;
            }
            status(wrap, spinner(chainBusy ? 'Waiting for the other read…' : 'Reading ' + portalName(btn.getAttribute('data-portal')) + '…'));
            chainBusy++;
            chain = chain.then(function () { return runJob(wrap, btn); }).then(function () { chainBusy--; wrap.removeAttribute('data-busy'); });
        });
    }
    var chainBusy = 0;

    // swap this advert's row for a fresh one (the list fragment the tick refresh already serves), so wording, facts, photos and the label are the server's
    function refreshRow(wrap, btn) {
        var listingId = wrap.getAttribute('data-card-listing');
        var url = window.location.pathname + window.location.search;
        url += (url.indexOf('?') === -1 ? '?' : '&') + '_fragments=1';
        return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                var tpl = document.createElement('template');
                tpl.innerHTML = d.listings || '';
                var fresh = tpl.content.querySelector('[data-advert-fetch-wrap][data-card-listing="' + listingId + '"]');
                var freshRow = fresh && fresh.closest('[data-qa="row-wrap"]');
                var oldRow = wrap.closest('[data-qa="row-wrap"]');
                // the stats line ("N with full details") moves with the read, like it does on a tick refresh
                var stats = document.getElementById('mic-slot-stats');
                if (stats && typeof d.statsStrip === 'string' && d.statsStrip !== '') stats.innerHTML = d.statsStrip;
                if (freshRow && oldRow) { oldRow.replaceWith(freshRow); return; }
                // the row is no longer in the list as filtered: say so on the old one
                wrap.removeAttribute('data-busy');
                status(wrap, 'Full details read ✓', 'var(--ds-green, #059669)');
            })
            .catch(function () {
                wrap.removeAttribute('data-busy');
                status(wrap, 'Read ✓ - reload the page to see it', 'var(--ds-green, #059669)');
            });
    }

    // ---- photo gallery on the card tile -----------------------------------------------------------------------------------------------------------------------
    function galleryStep(tile, delta) {
        var data = tile.querySelector('[data-gallery-data]');
        if (!data) return;
        var photos; try { photos = JSON.parse(data.textContent); } catch (e) { return; }
        var i = ((parseInt(tile.getAttribute('data-i') || '0', 10) + delta) % photos.length + photos.length) % photos.length;
        tile.setAttribute('data-i', String(i));
        var img = tile.querySelector('img');
        if (img) { img.onerror = null; img.src = photos[i]; }
        var badge = tile.querySelector('[data-qa="photo-count"]');
        if (badge) badge.textContent = (i + 1) + ' of ' + photos.length;
    }

    // ---- the full-ad window -----------------------------------------------------------------------------------------------------------------------------------
    var openModal = null;
    function closeModal() {
        if (!openModal) return;
        document.removeEventListener('keydown', openModal.onKey, true);
        openModal.el.remove();
        if (openModal.opener && openModal.opener.focus) { try { openModal.opener.focus(); } catch (e) { /* gone */ } }
        openModal = null;
    }

    function openFullAd(trigger) {
        var wrap = trigger.closest('[data-qa="row-wrap"]');
        var card = wrap && wrap.querySelector('[data-qa="advert-card"]');
        var fetchWrap = trigger.closest('[data-advert-fetch-wrap]') || (card && card.querySelector('[data-advert-fetch-wrap]'));
        if (!wrap || !card || !fetchWrap) return;
        closeModal();
        var fileId = fetchWrap.getAttribute('data-file');
        var el = document.createElement('div');
        el.setAttribute('data-qa', 'full-ad-window');
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.style.cssText = 'position:fixed; inset:0; z-index:90; background:rgba(15,23,42,.55); display:flex; align-items:center; justify-content:center; padding:2vh 2vw;';
        el.innerHTML =
            '<div style="background: var(--surface, #fff); color: var(--text-primary); border-radius:8px; width:min(1360px, 100%); height:96vh; display:flex; flex-direction:column; box-shadow:0 20px 50px rgba(0,0,0,.35);">' +
              '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 18px; border-bottom:1px solid var(--border);">' +
                '<strong data-fullad-title style="font-size:1.0625rem;">&nbsp;</strong>' +
                '<button type="button" data-fullad-close aria-label="Close" style="background:none; border:1px solid var(--border); border-radius:5px; padding:3px 12px; cursor:pointer; color: var(--text-primary); white-space:nowrap;">Close ✕</button>' +
              '</div>' +
              '<div style="flex:1; min-height:0; display:grid; grid-template-columns: minmax(0,1fr) 32rem;">' +
                '<div data-fullad-body style="overflow:auto; padding:16px 22px;"><div style="color: var(--text-secondary); font-size:.8125rem;">Loading the ad…</div></div>' +
                '<div data-fullad-right class="mi-fullad-right" style="overflow:auto; padding:14px 16px; border-left:1px solid var(--border); background: var(--surface-2, transparent);"><div data-fullad-intel></div><div data-fullad-ours></div></div>' +
              '</div>' +
            '</div>';
        // inside the card's own row, so the clone of the intelligence panel keeps the row's and the page's Alpine scopes (Claim, Comment, Pitch now, Link contact work as on the card)
        wrap.appendChild(el);
        var intel = card.querySelector('.mi-advert-right');
        if (intel) {
            var clone = intel.cloneNode(true);
            clone.style.height = 'auto';
            clone.style.borderLeft = '0';
            clone.style.paddingLeft = '0';
            var fc = clone.querySelector('[data-qa="full-card"]'); if (fc) fc.remove();
            el.querySelector('[data-fullad-intel]').appendChild(clone);
        }
        var q = window.location.search;
        var url = window.location.pathname + q + (q ? '&' : '?') + '_card=' + encodeURIComponent(fileId) + '&_ad=1';
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                el.querySelector('[data-fullad-title]').textContent = d.title || 'The advert';
                el.querySelector('[data-fullad-body]').innerHTML = d.left || '';
                el.querySelector('[data-fullad-ours]').innerHTML = d.right || '';
            })
            .catch(function () { el.querySelector('[data-fullad-body]').innerHTML = '<div style="color: var(--ds-crimson, #dc2626);">Couldn’t load the ad. Close this and try again.</div>'; });

        var onKey = function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeModal(); return; }
            var g = el.querySelector('[data-fullad-gallery]');
            if (g && (e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !/input|textarea|select/i.test((e.target && e.target.tagName) || '')) { fullAdStep(g, e.key === 'ArrowRight' ? 1 : -1); e.preventDefault(); }
        };
        document.addEventListener('keydown', onKey, true);
        el.addEventListener('mousedown', function (e) { if (e.target === el) closeModal(); });
        openModal = { el: el, onKey: onKey, opener: trigger };
        var cb = el.querySelector('[data-fullad-close]'); if (cb) cb.focus();
    }

    function fullAdStep(g, delta, to) {
        var photos; try { photos = JSON.parse(g.getAttribute('data-photos')); } catch (e) { return; }
        var cur = parseInt(g.getAttribute('data-i') || '0', 10);
        var i = to != null ? to : ((cur + delta) % photos.length + photos.length) % photos.length;
        g.setAttribute('data-i', String(i));
        var main = g.querySelector('[data-fullad-main]'); if (main) main.src = photos[i];
        var c = g.querySelector('[data-fullad-count]'); if (c) c.textContent = (i + 1) + ' of ' + photos.length;
        Array.prototype.forEach.call(g.querySelectorAll('[data-fullad-thumb]'), function (t) {
            var on = parseInt(t.getAttribute('data-fullad-thumb'), 10) === i;
            t.style.borderColor = on ? 'var(--brand-icon)' : 'transparent';
            if (on && t.scrollIntoView) { try { t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) { /* old browser */ } }
        });
    }

    // ---- one listener for everything --------------------------------------------------------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        var f = t.closest('[data-advert-fetch]');
        if (f) { e.preventDefault(); e.stopPropagation(); startRead(f); return; }
        var o = t.closest('[data-advert-open]');
        if (o) { e.preventDefault(); e.stopPropagation(); openFullAd(o); return; }
        if (t.closest('[data-fullad-close]')) { closeModal(); return; }
        var fg = t.closest('[data-fullad-gallery]');
        if (fg) {
            var strip = fg.querySelector('[data-fullad-strip]');
            if (t.closest('[data-fullad-strip-prev]') && strip) { strip.scrollBy({ left: -strip.clientWidth * 0.8, behavior: 'smooth' }); return; }
            if (t.closest('[data-fullad-strip-next]') && strip) { strip.scrollBy({ left: strip.clientWidth * 0.8, behavior: 'smooth' }); return; }
            if (t.closest('[data-fullad-prev]')) { fullAdStep(fg, -1); return; }
            if (t.closest('[data-fullad-next]')) { fullAdStep(fg, 1); return; }
            var th = t.closest('[data-fullad-thumb]');
            if (th) { fullAdStep(fg, 0, parseInt(th.getAttribute('data-fullad-thumb'), 10)); return; }
        }
        var tile = t.closest('.mi-advert-photo');
        if (tile) {
            if (t.closest('[data-gallery-prev]')) { e.preventDefault(); e.stopPropagation(); galleryStep(tile, -1); return; }
            if (t.closest('[data-gallery-next]')) { e.preventDefault(); e.stopPropagation(); galleryStep(tile, 1); return; }
        }
    }, true);

    // keyboard arrows on a photo tile that holds a gallery (the tile that has focus, or that the mouse is over)
    var hoverTile = null;
    document.addEventListener('mouseover', function (e) { hoverTile = e.target && e.target.closest ? e.target.closest('.mi-advert-photo') : null; });
    document.addEventListener('keydown', function (e) {
        if (openModal || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
        var a = document.activeElement;
        var tile = (a && a.closest && a.closest('.mi-advert-photo')) || hoverTile;
        if (!tile || !tile.querySelector('[data-gallery-data]')) return;
        if (a && /input|textarea|select/i.test(a.tagName || '')) return;
        galleryStep(tile, e.key === 'ArrowRight' ? 1 : -1);
        e.preventDefault();
    });

    // say hello early so the first click does not have to wait for a ping
    send({ type: 'ping' });

    window.CoreXAdvertFetch = { extensionPresent: extensionPresent, _state: function () { return { extReady: extReady, extVersion: extVersion }; } };
})();
