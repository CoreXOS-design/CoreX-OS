{{-- Behaviour of the agreement sheets. Config: window.AGR. mode 'form' = recipient, 'rr' = RR countersign, 'preview' = read-only. --}}
<script>
(function () {
    var C = window.AGR, mode = C.mode;
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var toastEl = $('#toast'), toastTimer;
    function toast(msg, ms) { if (!toastEl) return; toastEl.textContent = msg; toastEl.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.hidden = true; }, ms || 3500); }
    function post(url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; }); });
    }

    // ── helpers over fields ────────────────────────────────────────────────
    function els(key) { return $$('[data-field="' + key + '"]'); }
    function isPad(el) { return el.classList && el.classList.contains('sigpad'); }
    function val(key) {
        var list = els(key); if (!list.length) return '';
        var e = list[0];
        if (isPad(e)) { return $('input[type=hidden]', e).value || ''; }
        if (e.type === 'radio') { var c = list.filter(function (x) { return x.checked; })[0]; return c ? c.value : ''; }
        return (e.value || '').trim();
    }
    function setVal(key, v) {
        els(key).forEach(function (e) {
            if (isPad(e)) { $('input[type=hidden]', e).value = v || ''; e._redraw && e._redraw(); }
            else if (e.type === 'radio') { e.checked = (e.value === v); }
            else { e.value = v; }
        });
    }
    function label(key) { var e = els(key)[0]; return (C.labels && C.labels[key]) || (e && e.getAttribute('aria-label')) || key; }

    // ── live pricing (mirrors AgreementPricing::derive; the server recomputes) ─────
    function money(n) { var w = Math.abs(n % 1) < 1e-9; var s = (w ? n.toFixed(0) : n.toFixed(2)); var p = s.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' '); return p.join('.'); }
    var lastPlan = '';
    function calc() {
        if (mode !== 'form') { return 0; } // RR/preview screens print the server's figures; there are no entries here to recalculate from
        var R = C.rates, agents = parseInt(val('agents') || '0', 10) || 0, branches = parseInt(val('branches') || '0', 10) || 0, vari = parseFloat(C.variation || '0') || 0;
        // section 3 completes itself: 1–10 agents → CoreX Team, more → CoreX Agency (unless the sender fixed the plan); extra branches = branches − 1 (Agency only)
        var plan = C.forcedPlan || (agents < 1 ? '' : (agents <= R.team_max_seats ? 'team' : 'agency'));
        var extra = plan === 'agency' ? Math.max(branches - 1, 0) : 0;
        lastPlan = plan; setVal('plan', plan);
        if (els('branches_start').length) { setVal('branches_start', val('branches')); }
        $$('[data-mirror="branches"]').forEach(function (m) { m.value = val('branches'); });
        var L = { team_seats: [0, R.team_seat], agency_base: [0, R.agency_base], agency_t1: [0, R.agency_t1], agency_t2: [0, R.agency_t2], agency_t3: [0, R.agency_t3], branches: [0, R.branch] };
        if (plan === 'team') { L.team_seats[0] = agents; }
        else if (plan === 'agency') {
            L.agency_base[0] = 1; L.agency_t1[0] = Math.min(agents, R.agency_t1_max);
            L.agency_t2[0] = Math.min(Math.max(agents - R.agency_t1_max, 0), R.agency_t2_max - R.agency_t1_max);
            L.agency_t3[0] = Math.max(agents - R.agency_t2_max, 0); L.branches[0] = extra;
        }
        var sub = 0; Object.keys(L).forEach(function (k) { sub += L[k][0] * L[k][1]; });
        vari = Math.max(0, Math.min(vari, sub));
        var applies = plan === 'team' ? ['team_seats'] : (plan === 'agency' ? ['agency_base', 'agency_t1', 'agency_t2', 'agency_t3', 'branches'] : []);
        $$('[data-calc]').forEach(function (s) {
            var d = s.getAttribute('data-calc').split(':'), kind = d[0], key = d[1], t = '';
            if (kind === 'q') { t = applies.indexOf(key) > -1 ? String(L[key][0]) : ''; }
            else if (kind === 'amt') { if (key === 'total') { t = plan ? money(sub - vari) : ''; } else { t = applies.indexOf(key) > -1 ? money(L[key][0] * L[key][1]) : ''; } }
            else if (kind === 'note') { t = (plan === 'agency' && agents > R.quote_above_agents) ? ('For more than ' + R.quote_above_agents + ' agents a quoted rate is recorded under “Agreed variations” — we will confirm it with you.') : ''; }
            if (s.tagName !== 'INPUT') { s.textContent = t; }
        });
        $$('[data-mirror="total"]').forEach(function (m) { m.value = plan ? 'R ' + money(sub - vari) : ''; });
        return sub - vari;
    }

    // ── mirrors: nothing typed twice (spec §11.4) ──────────────────────────
    // Mandate address / contact number FOLLOW Part A until the recipient types their own value; clearing the box makes it follow again (spec §11.20).
    var FOLLOW = C.follow || {}, own = {};
    Object.keys(FOLLOW).forEach(function (t) { var e = els(t)[0]; if (e) { own[t] = e.getAttribute('data-own') === '1'; } });
    function paintFollow() {
        Object.keys(FOLLOW).forEach(function (t) {
            var e = els(t)[0]; if (!e || own[t] || document.activeElement === e) return;
            var s = val(FOLLOW[t]); if (val(t) !== s) { setVal(t, s); }
        });
    }
    var MIRROR = { m_holder: 'da_holder', m_address: 'address', m_bank: 'da_bank', m_branch_no: 'da_branch_code', m_account: 'da_account', m_account_type: 'da_type', m_contact: 'billing_cell', m_amount: '@total', m_place: 'sig_place', branches_start: 'branches' };
    var touched = {};
    function mirrorSource(src) { if (src !== '@total') { return val(src); } var t = calc(); return lastPlan ? String(Math.round(t * 100) / 100) : ''; }
    function applyMirrors(initial) {
        Object.keys(MIRROR).forEach(function (t) {
            if (!els(t).length || FOLLOW[t]) return; // address / contact number follow Part A by the rule below
            var cur = val(t), s = mirrorSource(MIRROR[t]);
            if (initial && cur !== '' && cur !== s) { touched[t] = true; }
            if (!touched[t] && s !== '' && cur !== s) { setVal(t, s); if (t !== 'branches_start') { pending[t] = s; } }
        });
        // The server's date (Africa/Johannesburg) — never the browser clock/timezone, so the printed date always agrees with the signing time.
        var today = C.today || (function () { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); })();
        ['sig_date', 'm_date'].forEach(function (k) { if (els(k).length && !val(k)) { setVal(k, today); pending[k] = today; } });
        if (els('m_day').length && !val('m_day')) { setVal('m_day', '1'); pending.m_day = '1'; }
    }

    // ── autosave (recipient) ───────────────────────────────────────────────
    var pending = {}, saveTimer = null, saving = false, rev = C.rev || 0, statusEl = $('#savestate');
    function setState(t) { if (statusEl) statusEl.textContent = t; }
    var dead = false; // the link or the session is gone: stop retrying, tell the person once, keep what was typed on screen
    function deadMessage(status) {
        return status === 419 ? 'This page has been open too long and its session has ended. Reload the page to carry on — what you entered earlier is saved.'
            : 'This link is no longer valid — it may have been replaced by a newer email from us. Open the latest email we sent you, then carry on there.';
    }
    function goDead(status) { dead = true; setState('Not saved — reload the page'); toast(deadMessage(status), 15000); }
    function restore(batch) { Object.keys(batch).forEach(function (k) { if (!(k in pending)) pending[k] = batch[k]; }); }
    function queue() { if (mode !== 'form' || dead) return; clearTimeout(saveTimer); setState('Saving…'); saveTimer = setTimeout(flush, 700); }
    function flush() {
        if (mode !== 'form' || dead) return Promise.resolve();
        if (saving) { saveTimer = setTimeout(flush, 400); return Promise.resolve(); }
        var keys = Object.keys(pending); if (!keys.length) { setState('All changes saved'); return Promise.resolve(); }
        var batch = pending; pending = {}; saving = true;
        return post(C.urls.save, { rev: rev, values: batch }).then(function (r) {
            saving = false;
            if (r.status === 200) { rev = r.body.rev; setState('Saved ' + (r.body.saved_at || '')); if (Object.keys(pending).length) queue(); }
            else if (r.status === 409) {
                // Another window saved first. Take its values only for boxes this window did not touch; what was in flight (and anything typed since)
                // is kept and saved again on top of the new revision — a conflict never throws typed entries away.
                rev = r.body.rev; var v = r.body.values || {};
                Object.keys(v).forEach(function (k) { if (!(k in batch) && !(k in pending)) setVal(k, v[k]); });
                restore(batch); setState('Updated from another window — your latest entries were kept'); toast((r.body.message || 'This agreement was updated in another window.') + ' Your latest entries were kept.', 6000); recalcAll(); queue();
            }
            else if (r.status === 419 || r.status === 404) { restore(batch); goDead(r.status); }
            else { restore(batch); setState('Not saved — ' + (r.body.message || 'check your connection')); if (r.body.message) toast(r.body.message, 6000); }
            outstanding();
        }).catch(function () { saving = false; restore(batch); setState('Not saved — offline? Retrying…'); saveTimer = setTimeout(flush, 4000); });
    }
    function recalcAll() { calc(); applyMirrors(false); paintMirrors(); paintFollow(); outstanding(); }

    function onChange(e) {
        var t = e.target, key = t.getAttribute && t.getAttribute('data-field');
        if (!key) { return; }
        if (e.isTrusted && MIRROR[key] !== undefined) { touched[key] = true; }
        t.classList && t.classList.remove('err');
        if (mode === 'form' && FOLLOW[key]) {
            // typing = the recipient's own value; leaving the box empty = follow Part A again (the empty value clears any earlier override)
            if (e.type === 'input') { own[key] = val(key) !== ''; }
            if (e.type === 'change' && val(key) === '') { own[key] = false; }
            pending[key] = val(key);
        } else if (mode === 'form') { pending[key] = val(key); }
        if (key === 'sig_name' && !C.initials && $('#ini-input') && !$('#ini-input').dataset.edited) { $('#ini-input').value = initialsOf(val('sig_name')); }
        recalcAll(); queue();
    }
    document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('input[type=text],input[type=email],input[type=tel],textarea')) onChange(e); });
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('input,select,textarea')) onChange(e); });

    // ── signature pads ─────────────────────────────────────────────────────
    function initPad(pad) {
        var cv = $('canvas', pad), hid = $('input[type=hidden]', pad), ctx, drawing = false, key = pad.getAttribute('data-sig');
        function size() {
            var r = cv.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
            cv.width = Math.max(1, r.width * dpr); cv.height = Math.max(1, r.height * dpr);
            ctx = cv.getContext('2d'); ctx.scale(dpr, dpr); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0b2a4a'; redraw();
        }
        function redraw() {
            if (!ctx) return; var r = cv.getBoundingClientRect(); ctx.clearRect(0, 0, r.width, r.height);
            if (hid.value) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = hid.value; }
        }
        pad._redraw = redraw;
        function pos(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
        cv.addEventListener('pointerdown', function (e) { drawing = true; cv.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); });
        cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); });
        function end() { if (!drawing) return; drawing = false; commit(); }
        cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
        function commit() { hid.value = cv.toDataURL('image/png'); pad.classList.remove('err'); if (mode === 'form') { pending[key] = hid.value; queue(); } outstanding(); }
        $$('[data-sig-clear]', pad).forEach(function (b) { b.addEventListener('click', function () { hid.value = ''; redraw(); if (mode === 'form') { pending[key] = ''; queue(); } outstanding(); }); });
        $$('[data-sig-type]', pad).forEach(function (b) { b.addEventListener('click', function () {
            var nm = val('sig_name') || val('rr_name') || (C.signerName || ''); if (!nm) { toast('Type your name first, then choose “Type it instead”.'); return; }
            var r = cv.getBoundingClientRect(); ctx.clearRect(0, 0, r.width, r.height); ctx.fillStyle = '#0b2a4a'; ctx.font = 'italic 38px "Segoe Script","Brush Script MT","Snell Roundhand",cursive'; ctx.textBaseline = 'middle'; ctx.fillText(nm, 12, r.height / 2); commit();
        }); });
        $$('[data-sig-copy]', pad).forEach(function (b) { b.addEventListener('click', function () { var s = val(b.getAttribute('data-sig-copy')); if (!s) { toast('Sign Part A first, then copy it here.'); return; } hid.value = s; redraw(); if (mode === 'form') { pending[key] = s; queue(); } outstanding(); }); });
        size(); window.addEventListener('resize', function () { var v = hid.value; size(); hid.value = v; redraw(); });
    }

    // ── initials ───────────────────────────────────────────────────────────
    var done = {}; (C.done || []).forEach(function (p) { done[p] = true; });
    var myInitials = C.initials || '';
    function initialsOf(name) { return (name || '').split(/\s+/).filter(Boolean).slice(0, 3).map(function (w) { return w.charAt(0).toUpperCase(); }).join(''); }
    function paintInitials() {
        $$('[data-ini="' + (mode === 'rr' ? 'rr' : 'agency') + '"]').forEach(function (s) { s.textContent = myInitials || '   '; });
        $$('.sheet').forEach(function (sh) {
            var n = parseInt(sh.getAttribute('data-page'), 10), chip = $('.ini-chip', sh), btn = $('[data-initial-page]', sh);
            if (done[n]) { chip.textContent = myInitials; chip.classList.add('done'); if (btn) btn.hidden = true; }
            else { chip.textContent = '—'; chip.classList.remove('done'); if (btn) btn.hidden = false; }
        });
        var cnt = Object.keys(done).length; var el = $('#pages-stat'); if (el) el.textContent = cnt + ' of ' + C.total + ' pages initialled';
    }
    function saveInitials() {
        var v = ($('#ini-input').value || '').replace(/[^A-Za-zÀ-ɏ]/g, '').toUpperCase().slice(0, 5);
        $('#ini-input').value = v; if (!v) { toast('Enter your initials (letters only).'); return Promise.resolve(false); }
        if (mode === 'rr') { myInitials = v; paintInitials(); return Promise.resolve(true); }
        return post(C.urls.initials, { initials: v }).then(function (r) {
            if (r.status === 200) { myInitials = r.body.initials; paintInitials(); return true; }
            toast(r.body.message || 'Could not save your initials.', 5000); $('#ini-input').value = myInitials; return false;
        });
    }
    function initialPage(n) {
        var go = function () {
            if (mode === 'rr') { done[n] = true; paintInitials(); outstanding(); return; }
            post(C.urls.page.replace('__P__', n), {}).then(function (r) {
                if (r.status === 200) { done[n] = true; paintInitials(); outstanding(); } else { toast(r.body.message || 'Could not initial this page.', 5000); }
            });
        };
        if (!myInitials) { saveInitials().then(function (ok) { if (ok) go(); }); } else { go(); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-initial-page]'); if (b) { initialPage(parseInt(b.getAttribute('data-initial-page'), 10)); }
    });
    var iniIn = $('#ini-input');
    if (iniIn) { iniIn.addEventListener('input', function () { iniIn.dataset.edited = '1'; }); iniIn.addEventListener('change', saveInitials); }

    // ── outstanding list ───────────────────────────────────────────────────
    function missing() {
        var m = [], seen = {};
        $$('[data-required]').forEach(function (e) {
            var k = e.getAttribute('data-field') || (e.closest('[data-field]') || {}).getAttribute && e.closest('[data-field]').getAttribute('data-field');
            if (!k || seen[k]) return; seen[k] = 1;
            if (k === 'term_months' && val('term') !== 'other') return;
            if (val(k) === '') m.push({ key: k, label: label(k), kind: 'field' });
        });
        if (mode === 'form') {
            var vt = els('term_months')[0]; if (vt && val('term') === 'other' && val('term_months') === '' && !seen.term_months) m.push({ key: 'term_months', label: label('term_months'), kind: 'field' });
        }
        var left = []; for (var i = 1; i <= C.total; i++) { if (!done[i]) left.push(i); }
        if (left.length) m.push({ key: '#page-' + left[0], label: 'Initial every page — ' + left.length + ' left (next: page ' + left[0] + ')', kind: 'page' });
        if (mode === 'form') {
            if (!$('#id_number').value.trim()) m.push({ key: '#id_number', label: 'ID or passport number', kind: 'misc' });
            if (!$('#consent').checked) m.push({ key: '#consent', label: 'Confirm you agree to sign electronically', kind: 'misc' });
        }
        return m;
    }
    function outstanding() {
        var m = missing(), n = m.length, c = $('#todo-count'), list = $('#todo-list');
        if (c) c.textContent = n;
        var sb = $('#submit-btn'); if (sb) sb.disabled = n > 0 && !C.allowEarlySubmit;
        if (list) {
            list.innerHTML = '';
            if (!n) { list.innerHTML = '<div style="color:#166534;font-size:.9rem;">Everything is complete — you can submit.</div>'; }
            m.forEach(function (it) { var a = document.createElement('a'); a.textContent = it.label; a.addEventListener('click', function () { jump(it); $('#todo').hidden = true; }); list.appendChild(a); });
        }
    }
    function jump(it) {
        var t = null;
        if (it.key.charAt(0) === '#') { t = $(it.key) || $('.sheet[data-page="' + it.key.replace('#page-', '') + '"]'); }
        else { t = els(it.key)[0]; }
        if (!t) return; t.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (t.focus && !isPad(t)) { try { t.focus({ preventScroll: true }); } catch (x) {} }
        t.classList && t.classList.add('err');
    }
    var tb = $('#todo-btn'); if (tb) tb.addEventListener('click', function () { var t = $('#todo'); t.hidden = !t.hidden; outstanding(); });
    var tc = $('#todo-close'); if (tc) tc.addEventListener('click', function () { $('#todo').hidden = true; });

    // ── submit / countersign ───────────────────────────────────────────────
    function collect(keys) { var o = {}; keys.forEach(function (k) { if (els(k).length) o[k] = val(k); }); return o; }
    function showErrors(errs) {
        var first = null;
        Object.keys(errs || {}).forEach(function (k) {
            els(k).forEach(function (e) { (isPad(e) || e.classList.contains('fld') ? e : e.closest('.opt') || e).classList.add('err'); if (e.type === 'radio') { var o = e.closest('.opt'); o && o.classList.add('err'); } });
            if (!first && els(k).length) first = els(k)[0];
        });
        if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        var box = $('#submit-errors'); if (box) { box.innerHTML = ''; Object.keys(errs || {}).forEach(function (k) { var d = document.createElement('div'); d.textContent = errs[k]; box.appendChild(d); }); }
    }
    var sbtn = $('#submit-btn');
    if (sbtn) sbtn.addEventListener('click', function () {
        sbtn.disabled = true; setState('Submitting…');
        var box = $('#submit-errors'); if (box) box.innerHTML = '';
        var p;
        if (mode === 'form') {
            var keys = C.recipientKeys; var values = collect(keys);
            p = flush().then(function () { return post(C.urls.submit, { values: values, id_number: $('#id_number').value, consent: $('#consent').checked ? 1 : 0 }); });
        } else {
            var vals = collect(['rr_name', 'rr_capacity', 'rr_place', 'rr_date', 'sigR']);
            p = post(C.urls.countersign, { values: vals, initials: myInitials, pages: Object.keys(done).map(Number) });
        }
        p.then(function (r) {
            if (r.status === 200 && r.body.redirect) { window.location.href = r.body.redirect; return; }
            if (r.body && typeof r.body.rev === 'number') { rev = r.body.rev; } // a refused submit stored what was typed and moved the revision on
            sbtn.disabled = false; setState('');
            if (r.status === 419 || r.status === 404) { goDead(r.status); return; }
            if (r.body.errors) { showErrors(r.body.errors); toast(r.body.message || 'Some details still need attention.', 6000); } else { toast(r.body.message || 'Could not submit. Please try again.', 6000); }
        }).catch(function () { sbtn.disabled = false; toast('Could not reach the server — your entries are saved. Try again.', 6000); });
    });

    ['#id_number', '#consent'].forEach(function (sel) { var el = $(sel); if (el) { el.addEventListener('input', outstanding); el.addEventListener('change', outstanding); } });

    // ── masked values (owner screens): audited reveal ──────────────────────
    document.addEventListener('click', function (e) {
        var m = e.target.closest && e.target.closest('.masked[data-reveal]'); if (!m || !C.urls.reveal) return;
        var old = m.textContent;
        post(C.urls.reveal, { key: m.getAttribute('data-reveal') }).then(function (r) {
            if (r.status !== 200) { toast(r.body.message || 'Could not reveal.'); return; }
            m.textContent = r.body.value; setTimeout(function () { m.textContent = old; }, 30000);
        });
    });

    // "Fills in automatically" tips: the link takes the recipient to the field the value is typed in (agents box, mandate bank fields, …).
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('[data-goto]'); if (!a) return;
        var f = document.getElementById(a.getAttribute('data-goto')); if (!f) return;
        e.preventDefault(); f.scrollIntoView({ block: 'center', behavior: 'smooth' });
        setTimeout(function () { f.focus({ preventScroll: true }); if (f.select && f.type !== 'radio') { try { f.select(); } catch (x) {} } }, 350);
    });

    // Single entry: read-only copies follow the box they are typed in (agreement section 5 ← mandate bank fields, mandate address/contact/place/date ← Part A).
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    function paintMirrors() {
        $$('[data-mirror-of]').forEach(function (m) {
            var v = val(m.getAttribute('data-mirror-of'));
            if (m.getAttribute('data-format') === 'date') { var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v); v = p ? (parseInt(p[3], 10) + ' ' + MONTHS[parseInt(p[2], 10) - 1] + ' ' + p[1]) : ''; }
            else if (m.getAttribute('data-map')) { var map = {}; try { map = JSON.parse(m.getAttribute('data-map')); } catch (x) {} v = map[v] || ''; }
            if (m.value !== v) { m.value = v; }
        });
    }

    // ── boot ───────────────────────────────────────────────────────────────
    $$('.sigpad').forEach(initPad);
    if (mode === 'form') { applyMirrors(true); }
    if (mode === 'form') { calc(); paintMirrors(); paintFollow(); } // the other screens show the server's own figures — they have no entries to recalculate from
    paintInitials(); outstanding();
    if (mode === 'form' && Object.keys(pending).length) { queue(); }
    window.addEventListener('beforeunload', function (e) { if (mode === 'form' && (Object.keys(pending).length || saving)) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
