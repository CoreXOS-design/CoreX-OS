{{--
    .ai/specs/rental-work-orders.md §17.5 — BUILD 1: the crew's "Parts & labour" panel.

    (a) "Price this job" — the office pressed "Ask crew to price this job": a banner with the office's note, and every line the
        crew adds while it is open belongs to that request.
    (b) "Extras during the job" — available on any open card: a note and up to 3 photos per line.

    AT-459: a PART line has the crew's actual cost (pre-filled from the list) and an optional receipt; a LABOUR line is HOURS only —
    the crew never types, sees or is told a labour rate or amount (the office prices it).
    The crew types the ACTUAL COST of each part and nothing else about money: there is no selling price, markup, margin, job
    total, owner or approval figure anywhere in this partial or in the $block it reads (CrewPricingBlock — guarded by
    CrewPayloadNeverCarriesSellingTest). Lines the crew adds go on THEIR LIST (AT-465: stored server-side as drafts, so a refresh keeps them) — in no total anywhere and
    never seen by the office — until the ONE sticky "Send to office", which carries the list, the photos and receipts on it, and
    "what did you find / what is needed" (required on the first send) as a single submission.

    Receives $block = CrewPricingBlock::for($card, $ctx) ([] for a closed card) and inherits $actions from _job-body:
      line_add, line_update (url with the literal "__LINE__"), line_archive (same), lines_send.
    §17.43 (19 Oct 2026) - ONE row is always open: "Add new line" saves it by fetch (no reload, the scroll stays put) and opens the next
    blank row; a saved draft saves its own changes as they are made; Remove archives with an Undo. Every row is also a plain form, so
    with JavaScript off a post still saves and redirects as before.
--}}
@if(!empty($block))
@php
    $canWrite = !empty($actions['line_add']);
    $pricesOn = (bool) ($block['prices_on'] ?? false);
    $chipStyle = fn ($state) => match ($state) {
        'accepted' => 'background:#eafaf0; color:#1f8a4c;',
        'rejected', 'declined' => 'background:#fdecea; color:#c0392b;',
        'sent' => 'background:#e6f7fb; color:#076e84;',
        default => 'background:#eef3f8; color:#26435f;',
    };
@endphp
<div class="cj-card cj-pricing {{ $canWrite && !empty($block['draft_count']) ? 'has-sendbar' : '' }}" id="cj-pricing" data-crew-pricing>
    <style>
        .cj-pricing input[type=number], .cj-pricing textarea { width: 100%; min-height: 48px; padding: 11px 12px; border: 1px solid var(--cj-border); border-radius: 10px; font: inherit; font-size: 16px; background: #fff; }
        .cj-pricing textarea { min-height: 76px; }
        .cj-pricing .cj-line { padding: 11px 0; border-top: 1px solid var(--cj-border); }
        .cj-pricing .cj-line:first-of-type { border-top: 0; }
        .cj-pricing .cj-line .top { display: flex; justify-content: space-between; gap: 10px; font-size: 15px; }
        .cj-pricing .cj-ask { background: #fff8e6; border: 1px solid #f1dfa6; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; font-size: 14px; line-height: 1.45; }
        .cj-pricing .cj-photos { margin-top: 8px; }
        .cj-pricing .cj-btn.small { min-height: 44px; font-size: 14px; padding: 9px; margin-top: 8px; }
        .cj-pricing [hidden] { display: none !important; }
        .cj-pricing .cj-receipt { display: flex; gap: 8px; }
        .cj-pricing .cj-receipt .cj-filebtn { position: relative; flex: 1 1 0; min-height: 52px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--cj-border); }
        .cj-pricing .cj-receipt .cj-filebtn.has-file { background: #eafaf0; color: #1f8a4c; border-color: #1f8a4c; }
        .cj-pricing .cj-sr { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        .cj-pricing .cj-receipt-name { margin-top: 6px; }
        .cj-pricing.has-sendbar { margin-bottom: 120px; }
        .cj-pricing .cj-entry { padding: 12px 0; border-top: 1px solid var(--cj-border); }
        .cj-pricing #cj-rows > .cj-entry:first-child, .cj-pricing #cj-rows > .cj-line:first-child { border-top: 0; }
        .cj-pricing .cj-entry.is-new { border-top: 2px solid var(--cj-border); background: #fafcff; margin: 0 -8px; padding: 12px 8px; border-radius: 10px; }
        .cj-pricing .cj-entry .cj-label { margin: 8px 0 4px; }
        .cj-pricing .cj-nums { display: flex; gap: 10px; }
        .cj-pricing .cj-nums .cj-num { flex: 1 1 0; min-width: 0; }
        .cj-pricing .cj-more > summary { cursor: pointer; list-style: none; min-height: 44px; display: flex; align-items: center; font-weight: 700; font-size: 14px; color: #076e84; }
        .cj-pricing .cj-more > summary::-webkit-details-marker { display: none; }
        .cj-pricing .cj-row-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .cj-pricing .cj-row-foot .cj-btn { width: auto; flex: 1 1 140px; margin-top: 8px; }
        .cj-pricing .cj-row-foot .cj-btn.cj-add-new-line { flex-basis: 100%; }
        .cj-pricing .cj-row-status { flex: 1 1 100%; }
        .cj-pricing .cj-row-total { flex: 1 1 100%; font-size: 15px; }
        .cj-pricing .cj-row-total:empty { display: none; }
        .cj-pricing .cj-row-status.bad { color: #c0392b; font-weight: 700; }
        .cj-pricing .cj-row-error { margin-top: 8px; padding: 10px 12px; border-radius: 10px; background: #fdecea; border: 1px solid #c0392b; color: #c0392b; font-size: 14px; }
        .cj-pricing .cj-removed { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; margin: 8px 0; border-radius: 10px; background: #eef3f8; font-size: 14px; }
        .cj-pricing .cj-removed .cj-btn { width: auto; margin: 0; min-height: 44px; padding: 8px 16px; }
        .cj-sendbar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: #fff; border-top: 1px solid var(--cj-border); box-shadow: 0 -4px 14px rgba(0,0,0,.08); padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); }
        .cj-sendbar .in { max-width: 560px; margin: 0 auto; }
        .cj-sendbar .cj-btn { margin-top: 0; }
        .cj-sendbar .cj-muted { text-align: center; margin-top: 6px; font-size: 12px; }
    </style>
    <h2>Parts &amp; labour</h2>

    @if(!empty($block['request']))
        <div class="cj-ask" data-price-request>
            <strong>The office asked you to price this job.</strong>
            @if(!empty($block['request']['note']))<div style="margin-top:4px;">{{ $block['request']['note'] }}</div>@endif
            <div class="cj-muted" style="margin-top:4px;">Add every part and piece of labour with what it actually cost you to your list, then press &ldquo;Send to office&rdquo;.</div>
        </div>
    @else
        <div class="cj-muted" style="margin-bottom:8px;">Look at the job, then add every part and piece of labour that is needed to your list. Nothing goes to the office until you press &ldquo;Send to office&rdquo;, and nothing counts until the office accepts it.</div>
    @endif

    <div id="cj-rows">
    @foreach($block['lines'] as $l)
        @if($l['editable'] && $canWrite)
            @include('rentals.crew-link._pricing-line-row', ['l' => $l, 'block' => $block, 'pricesOn' => $pricesOn, 'actions' => $actions])
        @else
            <div class="cj-line" data-line-id="{{ $l['id'] }}" data-line-state="{{ $l['state'] }}">
                <div class="top">
                    <div><strong>{{ $l['description'] }}</strong>
                        <div class="cj-muted">{{ ucfirst($l['kind']) }} &middot; {{ $l['quantity'] }}{{ $l['unit'] ? ' ' . $l['unit'] : ($l['kind'] === 'labour' ? ' hours' : '') }}@if($l['is_extra']) &middot; extra @endif</div>
                    </div>
                    @if($pricesOn && $l['cost_total'] !== null)<div style="white-space:nowrap; font-weight:700;">R {{ $l['cost_total'] }}</div>@endif
                </div>
                <div style="margin-top:6px;"><span class="cj-chip" style="{{ $chipStyle($l['state']) }}">{{ $l['state_label'] }}</span></div>
                @if($l['reason'])<div class="cj-muted" style="margin-top:4px;">Reason: {{ $l['reason'] }}</div>@endif
                @if($l['note'])<div class="cj-muted" style="margin-top:4px;">Note: {{ $l['note'] }}</div>@endif
                @if(count($l['photos']))
                    <div class="cj-photos">@foreach($l['photos'] as $p)<a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>@endforeach</div>
                @endif
                @if(!empty($l['receipt_url']))
                    <div class="cj-muted" style="margin-top:4px;" data-receipt-added>Receipt added &middot; <a href="{{ $l['receipt_url'] }}" target="_blank" rel="noopener">view</a></div>
                @endif
            </div>
        @endif
    @endforeach
    @if($canWrite)
        @include('rentals.crew-link._pricing-line-row', ['l' => null, 'block' => $block, 'pricesOn' => $pricesOn, 'actions' => $actions])
    @elseif(empty($block['lines']))
        <div class="cj-muted" data-no-lines>Your list is empty.</div>
    @endif
    </div>

    @if($canWrite)
        {{-- AT-465 - the ONE send. The findings box is part of the page (so it can be filled in before the button is pressed); the
             button lives in the sticky bar and submits that form through its form="" attribute. §17.43: the no-reload handlers swap
             this wrapper's content, so the bar appears the moment the first line is saved and goes when the last is removed. --}}
        <div id="cj-send-wrap">@include('rentals.crew-link._pricing-send', ['block' => $block, 'actions' => $actions])</div>
    @endif

    <script>
        (function () {
            var root = document.currentScript.parentNode;      // #cj-pricing
            var list = root.querySelector('#cj-rows');
            var blankTpl = (root.querySelector('.cj-entry.is-new') || {}).outerHTML || '';   // the pristine blank row, before any wiring
            var JSON_HEADERS = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

            function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }); }
            function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }

            // ── the Send bar: appears with the first saved line, follows the count, goes with the last ──
            function applySend(html, count) {
                var wrap = root.querySelector('#cj-send-wrap');
                if (!wrap) { return; }
                if (!count) { wrap.innerHTML = ''; root.classList.remove('has-sendbar'); return; }
                if (!wrap.querySelector('#cj-send-form')) { wrap.innerHTML = html || ''; }
                wrap.querySelectorAll('[data-send-btn]').forEach(function (n) { n.textContent = 'Send to office (' + count + ')'; });
                wrap.querySelectorAll('[data-draft-phrase]').forEach(function (n) { n.textContent = 'Your list (' + count + (count === 1 ? ' line)' : ' lines)'); });
                root.classList.add('has-sendbar');
            }

            // ── one request: returns {ok, status, data} and never throws (a dropped network is data.message) ──
            function send(url, body) {
                return fetch(url, { method: 'POST', headers: JSON_HEADERS, body: body, credentials: 'same-origin' }).then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (data) {
                        if (r.status === 419) { data = { message: 'This page has expired - reload it and try again.' }; }
                        else if (!data.message && !r.ok) { data = { message: 'That did not save - please try again.' }; }
                        return { ok: r.ok && data.ok !== false, status: r.status, data: data };
                    });
                }, function () { return { ok: false, status: 0, data: { message: 'Not saved - no signal. Your list on this page is kept; try again.' } }; });
            }

            function showError(row, msg) {
                var box = row.querySelector('.cj-row-error');
                if (!msg) { box.hidden = true; box.textContent = ''; return; }
                box.textContent = msg; box.hidden = false;
            }
            function setStatus(row, text, bad) {
                var s = row.querySelector('.cj-row-status');
                if (s) { s.textContent = text; s.classList.toggle('bad', !!bad); }
            }
            function clearFiles(row) { row.querySelectorAll('input[type=file]').forEach(function (i) { i.value = ''; }); row.querySelectorAll('.cj-filebtn').forEach(function (b) { b.classList.remove('has-file'); var t = b.querySelector('span'); if (t && t.dataset.was) { t.textContent = t.dataset.was; } }); }
            function renderMedia(row, line) {
                var m = row.querySelector('.cj-media');
                m.innerHTML = '';
                if (line.photos && line.photos.length) {
                    var ph = el('div', 'cj-photos');
                    line.photos.forEach(function (p) { var a = el('a'); a.href = p.url; a.target = '_blank'; a.rel = 'noopener'; var i = el('img'); i.src = p.url; i.alt = ''; i.loading = 'lazy'; a.appendChild(i); ph.appendChild(a); });
                    m.appendChild(ph);
                }
                if (line.receipt_url) {
                    var r = el('div', 'cj-muted'); r.setAttribute('data-receipt-added', '');
                    r.appendChild(document.createTextNode('Receipt added · '));
                    var a2 = el('a', '', 'view'); a2.href = line.receipt_url; a2.target = '_blank'; a2.rel = 'noopener'; r.appendChild(a2);
                    m.appendChild(r);
                }
            }
            function tokenOnly(row) { var fd = new FormData(); var t = row.querySelector('input[name=_token]'); if (t) { fd.set('_token', t.value); } return fd; }
            function renderTotal(row, line) {
                var t = row.querySelector('.cj-row-total');
                if (t) { t.textContent = line.cost_total ? 'R ' + line.cost_total : ''; }
            }
            function urlFor(row, key, id) { return (row.dataset[key] || '').replace('__LINE__', id); }

            // ── one row: what a picked item fills in, Part vs Labour, the receipt button ──
            function wire(row) {
                var pick = row.querySelector('.cj-cat-pick'), kind = row.querySelector('.cj-kind'), kindWrap = row.querySelector('.cj-kind-wrap'), desc = row.querySelector('.cj-desc');
                var qty = row.querySelector('.cj-qty'), unit = row.querySelector('.cj-unit'), cost = row.querySelector('.cj-cost');
                var hint = row.querySelector('.cj-cost-hint'), qtyLabel = row.querySelector('.cj-qty-label');
                var qtyTouched = !row.classList.contains('is-new'), costTouched = !row.classList.contains('is-new'), itemUnit = '';
                var saving = false, dirty = false, timer = null;
                if (qty) { qty.addEventListener('input', function () { qtyTouched = true; }); }
                if (cost) { cost.addEventListener('input', function () { costTouched = true; if (hint) { hint.hidden = true; } }); }

                function isLabour() { return kind && kind.value === 'labour'; }
                function hoursLabel() { return itemUnit && !/^hours?$/i.test(itemUnit) ? itemUnit.replace(/s$/i, '') + 's' : 'Hours'; }
                function apply() {
                    var labour = isLabour();
                    row.querySelectorAll('.cj-part-only').forEach(function (p) { if (!p.classList.contains('cj-cost-hint')) { p.hidden = labour; } });
                    if (cost) { cost.disabled = labour; }
                    if (unit) { unit.disabled = labour; }
                    if (qtyLabel) { qtyLabel.textContent = labour ? hoursLabel() : qtyLabel.dataset.partLabel; }
                    // hours have no sensible default: an untouched "1" is cleared so the crew types what they worked
                    if (qty && labour && !qtyTouched && qty.value === '1') { qty.value = ''; }
                    if (qty && !labour && !qtyTouched && qty.value === '') { qty.value = '1'; }
                    if (kindWrap) { kindWrap.hidden = !!(pick && pick.value); }
                }
                if (kind) { kind.addEventListener('change', apply); }
                if (pick) {
                    pick.addEventListener('change', function () {
                        var o = pick.options[pick.selectedIndex];
                        if (!o || !o.value) { itemUnit = ''; apply(); return; }
                        desc.value = o.dataset.desc || '';
                        if (o.dataset.kind && kind) { kind.value = o.dataset.kind; }
                        itemUnit = o.dataset.unit || '';
                        if (unit) { unit.value = o.dataset.kind === 'labour' ? '' : (o.dataset.unit || ''); }
                        if (cost && o.dataset.kind !== 'labour' && o.dataset.cost && !costTouched) { cost.value = o.dataset.cost; if (hint) { hint.hidden = false; } }
                        apply();
                    });
                }
                row.querySelectorAll('.cj-filebtn input[type=file]').forEach(function (inp) {
                    inp.addEventListener('change', function () {
                        var btn = inp.closest('.cj-filebtn'), t = btn.querySelector('span');
                        // one receipt per line: the camera or the phone's files, never both
                        row.querySelectorAll('.cj-filebtn').forEach(function (b) { var bt = b.querySelector('span'); if (bt.dataset.was) { bt.textContent = bt.dataset.was; } b.classList.remove('has-file'); if (b !== btn) { b.querySelector('input').value = ''; } });
                        if (!t.dataset.was) { t.dataset.was = t.textContent; }
                        if (inp.files && inp.files.length) { btn.classList.add('has-file'); t.textContent = 'Receipt ready'; }
                    });
                });
                apply();

                // ── the blank row: "Add new line" saves it, then opens the next one ──
                row.dataset.clientKey = row.dataset.clientKey || uuid();
                var addBtn = row.querySelector('.cj-add-new-line');
                function addNew(anyway) {
                    if (saving) { return; }
                    saving = true; addBtn.disabled = true; showError(row, '');
                    var fd = new FormData(row); fd.set('client_key', row.dataset.clientKey);
                    if (anyway === true) { fd.set('add_anyway', '1'); }
                    send(row.dataset.addUrl, fd).then(function (res) {
                        saving = false; addBtn.disabled = false;
                        // the office already has this part: ask, never silently double it
                        if (res.status === 409 && res.data.duplicate) { askAddAnyway(res.data); return; }
                        if (!res.ok) { showError(row, res.data.message); return; }
                        becomeSaved(res.data);
                        applySend(res.data.send_html, res.data.draft_count);
                        openNextRow();
                    });
                }
                function askAddAnyway(data) {
                    var box = row.querySelector('.cj-row-error');
                    var ex = data.existing || {};
                    box.textContent = '';
                    box.appendChild(document.createTextNode(data.message + (ex.description ? ' (' + ex.description + (ex.quantity ? ', ' + ex.quantity + (ex.unit ? ' ' + ex.unit : '') : '') + ')' : '') + ' '));
                    var yes = el('button', 'cj-btn small cj-add-anyway', 'Add anyway'); yes.type = 'button';
                    yes.addEventListener('click', function () { addNew(true); });
                    box.appendChild(yes);
                    box.hidden = false;
                }
                // one submit handler for the row's whole life: a blank row adds, a saved row saves its changes (Enter in a box, or the button with JS on)
                row.addEventListener('submit', function (e) { e.preventDefault(); if (row.classList.contains('is-new')) { addNew(); } else { autosave(); } });
                function becomeSaved(data) {
                    var line = data.line;
                    row.classList.remove('is-new'); row.classList.add('is-saved');
                    row.dataset.lineId = line.id; row.dataset.clientKey = '';
                    row.action = urlFor(row, 'updateUrl', line.id);
                    var foot = row.querySelector('.cj-row-foot');
                    foot.innerHTML = '';
                    var tot = el('strong', 'cj-row-total'); tot.textContent = line.cost_total ? 'R ' + line.cost_total : '';
                    var st = el('span', 'cj-row-status cj-muted', 'Saved — not sent yet'); st.setAttribute('aria-live', 'polite');
                    var rm = el('button', 'cj-btn ghost small cj-remove', 'Remove'); rm.type = 'button';
                    foot.appendChild(tot); foot.appendChild(st); foot.appendChild(rm);
                    var more = row.querySelector('.cj-more'); if (more) { more.open = false; }
                    var extra = row.querySelector('input[name=is_extra]'); if (extra) { extra.closest('label').remove(); }
                    clearFiles(row); renderMedia(row, line); renderTotal(row, line);
                    qtyTouched = true; costTouched = true;
                    wireSaved();
                }
                function openNextRow() {
                    var holder = document.createElement('div'); holder.innerHTML = blankTpl;
                    var next = holder.firstElementChild;
                    list.appendChild(next); wire(next);
                    var f = next.querySelector('.cj-cat-pick') || next.querySelector('.cj-desc');
                    if (f) { f.focus({ preventScroll: true }); }
                }

                // ── a saved draft: saves its own changes as they are made; Remove archives, with an Undo ──
                function wireSaved() {
                    row.addEventListener('change', function () { dirty = true; clearTimeout(timer); timer = setTimeout(autosave, 400); });
                    var rm = row.querySelector('.cj-remove');
                    if (rm) { rm.addEventListener('click', function (e) { e.preventDefault(); remove(); }); }
                }
                function autosave() {
                    if (saving) { dirty = true; return; }
                    dirty = false; saving = true; showError(row, ''); setStatus(row, 'Saving…');
                    send(row.action, new FormData(row)).then(function (res) {
                        saving = false;
                        if (!res.ok) { showError(row, res.data.message); setStatus(row, 'Not saved', true); return; }
                        setStatus(row, 'Saved — not sent yet'); clearFiles(row); renderMedia(row, res.data.line); renderTotal(row, res.data.line);
                        applySend(res.data.send_html, res.data.draft_count);
                        if (dirty) { autosave(); }
                    });
                }
                function remove() {
                    if (saving) { return; }
                    saving = true; showError(row, '');
                    var was = desc.value;
                    send(urlFor(row, 'archiveUrl', row.dataset.lineId), tokenOnly(row)).then(function (res) {
                        saving = false;
                        if (!res.ok) { showError(row, res.data.message); setStatus(row, 'Not removed', true); return; }
                        applySend(res.data.send_html, res.data.draft_count);
                        var strip = el('div', 'cj-removed');
                        strip.appendChild(el('span', '', 'Removed: ' + (was || 'that line')));
                        var undo = el('button', 'cj-btn ghost', 'Undo'); undo.type = 'button';
                        strip.appendChild(undo);
                        row.hidden = true; row.after(strip);
                        undo.addEventListener('click', function () {
                            undo.disabled = true;
                            send(urlFor(row, 'restoreUrl', row.dataset.lineId), tokenOnly(row)).then(function (r2) {
                                if (!r2.ok) { undo.disabled = false; strip.firstChild.textContent = r2.data.message; return; }
                                strip.remove(); row.hidden = false; setStatus(row, 'Put back — not sent yet');
                                applySend(r2.data.send_html, r2.data.draft_count);
                            });
                        });
                    });
                }
                if (row.classList.contains('is-saved')) { wireSaved(); }
            }
            root.querySelectorAll('.cj-line-form').forEach(wire);

            // The Send form (re-inserted by applySend) and any other plain form: one tap only.
            root.addEventListener('submit', function (e) {
                var f = e.target;
                if (!f || f.classList.contains('cj-line-form')) { return; }
                var btns = Array.prototype.slice.call(f.querySelectorAll('button[type=submit]'));
                if (f.id) { btns = btns.concat(Array.prototype.slice.call(document.querySelectorAll('button[type=submit][form="' + f.id + '"]'))); }
                btns.forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
            });
        })();
    </script>
</div>
@endif
