@extends('layouts.corex')

{{--
    AT-481 — .ai/specs/rental-takeon-import.md "reOS review screen rebuild". The review screen of a reOS batch, in the order a person
    works it: the numbers and the Import button (sticky), the "for all" defaults, the leases that NEED YOU one at a time as a card of
    plain questions, then the READY leases as one line each (and the imported / failed ones). Every answer is saved the moment it is
    given (ReosTakeOnApiController), so it survives a reload; nothing is created until Import is pressed.

    List standard (BUILD_STANDARD §1b): search = address, owner, tenant, agent text, reOS lease id; the one "Show" switch = all / ready /
    needs you / imported / failed; sheet order; 50 per page; real empty states. Scoping: the run is checked with
    RentalTakeOnImportRun::visibleTo before this renders. Plain JavaScript, event delegation (the page's own rule: no Alpine here).
--}}

@php
    $q = $filters['q'] ?? '';
    $showUrl = fn (string $s) => route('corex.rentals.take-on-import.preview', array_filter(['run' => $run->id, 'q' => $q, 'show' => $s === 'all' ? null : $s]));
    $anyRows = collect($sections)->sum('total') + count($queue) > 0;
@endphp

@section('content')
<div class="p-6 space-y-4" id="reos-review" data-run-id="{{ $run->id }}"
     data-url-defaults="{{ route('api.v1.rentals.take-on-import.defaults', $run) }}"
     data-url-suburbs="{{ route('api.v1.rentals.take-on-import.suburbs') }}"
     data-url-contacts="{{ route('api.v1.rentals.take-on-import.contacts') }}"
     data-url-decisions="{{ url('/api/v1/rentals/take-on-import/rows') }}"
     data-queue="{{ json_encode($queue) }}">

    {{-- Sticky header: the live numbers and the one primary action. It sticks flush to the top of the scroll area: <main id="appScroll">
         has its own padding (1rem, 1.5rem from lg), so the bar's top is minus that padding. --}}
    <style>
        #reos-bar { top: -1rem; }
        @media (min-width: 1024px) { #reos-bar { top: -1.5rem; } }
    </style>
    <div id="reos-bar" class="corex-card p-3" style="position: sticky; z-index: 50; background: var(--surface, #fff); box-shadow: 0 2px 6px rgba(0,0,0,.12);" data-test="reos-bar">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary corex-btn-xs">← Back to batches</a>
                <span class="text-xs text-muted">{{ $run->source_filename }}</span>
                <span class="text-sm font-semibold" id="reos-line" data-test="reos-counts">{{ $summary['line'] }}</span>
            </div>
            @if($canManage)
                <form method="POST" action="{{ route('corex.rentals.take-on-import.reos.import', $run) }}" id="reos-import-form">
                    @csrf
                    @if($continueImport)<input type="hidden" name="continue" value="1">@endif
                    <button type="submit" id="reos-import-btn" class="corex-btn-primary" data-test="reos-import-btn" @disabled(! $summary['can_import'] && ! $continueImport)>{{ $summary['import_label'] }}</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="ds-alert-success" data-test="reos-status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="ds-alert-danger">{{ $errors->first() }}</div>
    @endif
    <div class="ds-alert-danger hidden" id="reos-msg" role="alert"></div>

    @if($canManage)
    {{-- The "for all" choices, once: leases inherit them and only show their own control when they have their own value or none. --}}
    <div class="corex-card p-3 flex flex-wrap items-end gap-4" data-test="reos-defaults">
        <div>
            <label class="block font-medium mb-1" for="reos-default-agent">Agent for all</label>
            <select id="reos-default-agent" class="prop-select">
                <option value="">Choose…</option>
                @foreach($agents as $a)<option value="{{ $a['id'] }}" @selected((int) $defaults['agent_user_id'] === $a['id'])>{{ $a['name'] }}</option>@endforeach
            </select>
        </div>
        <div class="relative" data-default-suburb-wrap>
            <label class="block font-medium mb-1" for="reos-default-suburb">Suburb for all new properties</label>
            @if($defaults['suburb_id'])
                <div data-test="default-suburb-chosen"><strong>{{ $defaults['suburb_label'] }}</strong> <button type="button" class="underline text-muted" data-default-suburb-clear>change</button></div>
            @else
                <input type="text" id="reos-default-suburb" data-default-suburb-input autocomplete="off" placeholder="Type the suburb…" class="prop-input">
                <div data-default-suburb-results class="hidden absolute z-20 corex-card p-1 w-full"></div>
            @endif
        </div>
    </div>
    @endif

    {{-- Search and the one Show switch --}}
    <div class="flex flex-wrap items-center gap-3" data-test="reos-filters">
        <form method="GET" action="{{ route('corex.rentals.take-on-import.preview', $run) }}" id="reos-search-form">
            @if($show !== 'all')<input type="hidden" name="show" value="{{ $show }}">@endif
            <input type="search" name="q" id="reos-q" value="{{ $q }}" placeholder="Search address, owner, tenant, agent, reOS id" aria-label="Search" class="prop-input" style="min-width:18rem;">
        </form>
        <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Show">
            <span class="text-muted mr-1">Show:</span>
            @foreach(\App\Services\Rentals\TakeOnImport\Reos\ReosRowQuery::SHOWS as $key => $label)
                <a href="{{ $showUrl($key) }}" class="{{ $show === $key ? 'corex-btn-primary' : 'corex-btn-outline' }} corex-btn-xs" data-show="{{ $key }}" @if($show === $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @if($canManage && in_array($show, ['all', 'needs'], true))
        <section data-section="needs" @if($queue === []) hidden @endif>
            <h2 class="ds-section-header mb-2">Needs you</h2>
            <div id="reos-card-wrap" data-test="reos-queue">
                @if($firstRow)
                    @include('corex.rentals.take-on-import._reos-card', ['row' => $firstRow, 'run' => $run, 'agents' => $agents, 'defaults' => $defaults, 'canManage' => true])
                @endif
            </div>
        </section>
    @endif

    @if(! $anyRows)
        <div class="ds-empty-state px-6 py-12 text-center text-sm text-muted" data-test="reos-empty">
            @if($q !== '' || $show !== 'all')
                No leases match. <a href="{{ route('corex.rentals.take-on-import.preview', $run) }}" class="underline">Show everything</a>.
            @else
                This batch has no leases in it.
            @endif
        </div>
    @endif

    @foreach($sections as $state => $section)
        <section data-section="{{ $state }}" @if($section['total'] === 0) hidden @endif>
            <h2 class="ds-section-header mb-2">{{ $section['title'] }} <span class="ds-badge ds-badge-muted" data-section-count="{{ $state }}">{{ $section['total'] }}</span></h2>
            <div class="space-y-2" id="reos-list-{{ $state }}">
                @foreach($section['rows'] as $row)
                    @include('corex.rentals.take-on-import._reos-line', ['row' => $row, 'run' => $run, 'agents' => $agents, 'defaults' => $defaults, 'canManage' => $canManage])
                @endforeach
            </div>
            @if($section['total'] > $section['rows']->count() && ! $section['pager'])
                <a href="{{ $showUrl($state) }}" class="underline">Show all {{ $section['total'] }}</a>
            @endif
            @if($section['pager'])<div class="mt-2">{{ $section['pager']->links() }}</div>@endif
        </section>
    @endforeach
</div>

@if($continueImport)
    {{-- The import works through the ready leases a few at a time; this carries on by itself until none are left. --}}
    <script>setTimeout(function () { var f = document.getElementById('reos-import-form'); if (f) { f.submit(); } }, 600);</script>
@endif

@push('scripts')
<script>
(function () {
    var root = document.getElementById('reos-review');
    if (!root) { return; }
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var queue = [];
    try { queue = JSON.parse(root.getAttribute('data-queue') || '[]'); } catch (e) { queue = []; }
    var pos = 0;
    var cardWrap = document.getElementById('reos-card-wrap');
    var urlDecisions = root.getAttribute('data-url-decisions');

    function api(method, url, body) {
        return fetch(url, {
            method: method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }, function () { return { ok: r.ok, data: {} }; }); });
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function say(msg) {
        var m = document.getElementById('reos-msg');
        if (!m) { return; }
        m.textContent = msg || '';
        m.classList.toggle('hidden', !msg);
    }
    function toHtml(html) { var t = document.createElement('div'); t.innerHTML = html.trim(); return t.firstElementChild; }

    // ── header numbers ─────────────────────────────────────────────────────────────
    function setSummary(s) {
        if (!s) { return; }
        var line = document.getElementById('reos-line'); if (line) { line.textContent = s.line; }
        var btn = document.getElementById('reos-import-btn');
        if (btn) { btn.textContent = s.import_label; btn.disabled = !s.can_import; }
        ['ready', 'failed', 'imported'].forEach(function (k) {
            var c = document.querySelector('[data-section-count="' + k + '"]'); if (c) { c.textContent = s[k]; }
            var sec = document.querySelector('[data-section="' + k + '"]'); if (sec) { sec.hidden = s[k] === 0; }
        });
    }

    // ── the queue card ─────────────────────────────────────────────────────────────
    function labelCard() {
        if (!cardWrap) { return; }
        var p = cardWrap.querySelector('[data-queue-pos]'), t = cardWrap.querySelector('[data-queue-total]');
        if (p) { p.textContent = String(pos + 1); }
        if (t) { t.textContent = String(queue.length); }
        var back = cardWrap.querySelector('[data-queue-step="-1"]'); if (back) { back.disabled = pos <= 0; }
        var skip = cardWrap.querySelector('[data-queue-step="1"]'); if (skip) { skip.disabled = queue.length <= 1; }
    }
    function paintCard(html) {
        if (!cardWrap) { return; }
        cardWrap.innerHTML = html || '';
        var sec = document.querySelector('[data-section="needs"]');
        if (sec) { sec.hidden = !html; }
        labelCard();
    }
    function showCard(id) {
        return api('GET', urlDecisions + '/' + id + '/card').then(function (res) {
            if (!res.ok) { say((res.data && res.data.message) || 'That lease could not be opened.'); return; }
            paintCard(res.data.card_html);
            if (cardWrap) { cardWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        });
    }
    function showCurrent() {
        if (!queue.length) { paintCard(''); return; }
        pos = Math.max(0, Math.min(pos, queue.length - 1));
        showCard(queue[pos]);
    }
    function dequeue(id) { var i = queue.indexOf(id); if (i !== -1) { queue.splice(i, 1); if (i < pos) { pos--; } } }
    function enqueue(id) {
        if (queue.indexOf(id) !== -1) { return; }
        queue.push(id); queue.sort(function (a, b) { return a - b; });
    }

    // ── the lines ──────────────────────────────────────────────────────────────────
    function placeLine(id, state, html) {
        var old = document.getElementById('row-' + id);
        var wasOpen = old && old.tagName === 'DETAILS' && old.open;
        if (old) { old.parentNode.removeChild(old); }
        if (state === 'needs') { return; }               // asked in the queue, not listed
        var list = document.getElementById('reos-list-' + state);
        if (!list) { return; }
        var line = toHtml(html);
        if (wasOpen && line.tagName === 'DETAILS') { line.open = true; }
        var n = parseInt(line.getAttribute('data-row-number'), 10), before = null;
        Array.prototype.some.call(list.children, function (c) { if (parseInt(c.getAttribute('data-row-number'), 10) > n) { before = c; return true; } return false; });
        list.insertBefore(line, before);
    }

    // ── saving an answer ───────────────────────────────────────────────────────────
    function save(holder, payload) {
        var id = parseInt(holder.getAttribute('data-row-id'), 10);
        var cardShown = cardWrap && cardWrap.querySelector('#reos-card[data-row-id="' + id + '"]');
        holder.style.opacity = '.6';
        say('');
        return api('POST', urlDecisions + '/' + id + '/decisions', payload).then(function (res) {
            holder.style.opacity = '';
            if (!res.ok) { say((res.data && res.data.message) || 'That could not be saved.'); return; }
            var d = res.data;
            setSummary(d.summary);
            placeLine(id, d.state, d.line_html);
            if (d.state === 'needs') {
                enqueue(id);
                if (cardShown) { paintCard(d.card_html); }
            } else {
                dequeue(id);
                if (d.state === 'failed' && cardShown) { paintCard(d.card_html); }
                else if (cardShown) { showCurrent(); }
            }
        }, function () { holder.style.opacity = ''; say('The connection was lost — nothing was saved. Try again.'); });
    }
    function holderOf(el) { return el.closest('[data-row-id]'); }

    function renderResults(box, items, labelOf, onPick) {
        box.innerHTML = '';
        if (!items.length) { box.classList.add('hidden'); return; }
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'block w-full text-left px-2 py-1 hover:underline';
            b.textContent = labelOf(it);
            b.addEventListener('click', function () { onPick(it); });
            box.appendChild(b);
        });
        box.classList.remove('hidden');
    }

    // ── clicks: answers, queue steps, fix / close, small toggles ──────────────────
    root.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-payload]'))) {
            var h = holderOf(t);
            if (h) { try { save(h, JSON.parse(t.getAttribute('data-payload'))); } catch (err) { say('That could not be sent.'); } }
            return;
        }
        if ((t = e.target.closest('[data-queue-step]'))) {
            var step = parseInt(t.getAttribute('data-queue-step'), 10);
            pos = queue.length ? (pos + step + queue.length) % queue.length : 0;
            showCurrent();
            return;
        }
        if ((t = e.target.closest('[data-fix]'))) { showCard(parseInt(t.getAttribute('data-fix'), 10)); return; }
        if (e.target.closest('[data-card-close]')) { showCurrent(); return; }
        if ((t = e.target.closest('[data-open-contact-search]'))) {
            var panel = t.parentNode.querySelector('[data-contact-panel]');
            panel.classList.toggle('hidden');
            var inp = panel.querySelector('input'); if (inp) { inp.focus(); }
            return;
        }
        if ((t = e.target.closest('[data-clear-suburb]'))) { var hh = holderOf(t); if (hh) { save(hh, { suburb_id: null }); } return; }

        // company people (spec R6a): add / remove / convert
        function personCtx(el) { var box = el.closest('[data-people-party]'); return { h: holderOf(el), party: box ? box.getAttribute('data-people-party') : null, box: box }; }
        if ((t = e.target.closest('[data-person-toggle]'))) { personCtx(t).box.querySelector('[data-person-panel]').classList.toggle('hidden'); return; }
        if ((t = e.target.closest('[data-person-remove]'))) { var c1 = personCtx(t); save(c1.h, { person: { party: c1.party, op: 'remove', key: t.getAttribute('data-person-remove') } }); return; }
        if ((t = e.target.closest('[data-person-convert]'))) { var c2 = personCtx(t); save(c2.h, { person: { party: c2.party, op: 'convert' } }); return; }
        if ((t = e.target.closest('[data-person-add-new]'))) {
            var c3 = personCtx(t), b = c3.box;
            save(c3.h, { person: {
                party: c3.party, op: 'add',
                first_name: b.querySelector('[data-person-first]').value, last_name: b.querySelector('[data-person-last]').value,
                phone: b.querySelector('[data-person-phone]').value, email: b.querySelector('[data-person-email]').value,
                capacity: b.querySelector('[data-person-new-capacity]').value
            } });
        }
    });

    // ── selects and fields that save when changed ──────────────────────────────────
    root.addEventListener('change', function (e) {
        var cap = e.target.closest('[data-person-capacity]');
        if (cap) {
            var box = cap.closest('[data-people-party]');
            save(holderOf(cap), { person: { party: box.getAttribute('data-people-party'), op: 'set_capacity', key: cap.getAttribute('data-person-capacity'), capacity: cap.value } });
            return;
        }
        var el = e.target.closest('[data-field]');
        if (!el) { return; }
        var h = holderOf(el), field = el.getAttribute('data-field'), p = {};
        if (!h) { return; }
        if (field === 'property_choice') { p.property_choice = el.value === 'new' ? 'new' : parseInt(el.value, 10); }
        else if (field === 'agent_user_id') { p.agent_user_id = el.value === '' ? null : parseInt(el.value, 10); }
        else if (field === 'tenant_wallet_ref') { p.tenant_wallet_ref = el.value; }
        else if (field === 'owner' || field === 'tenant') {
            if (el.value === 'link-auto') { p[field] = { action: 'link', contact_id: parseInt(el.getAttribute('data-auto-contact'), 10) }; }
            else if (el.value === 'link-other') { return; }
            else { p[field] = { action: el.value }; }
        } else { return; }
        save(h, p);
    });

    // ── type-ahead: suburb, contact, person ────────────────────────────────────────
    root.addEventListener('input', debounce(function (e) {
        var s = e.target.closest('[data-suburb-input]');
        var c = e.target.closest('[data-contact-input]');
        var ps = e.target.closest('[data-person-search]');
        if (s) {
            var box = s.parentNode.querySelector('[data-suburb-results]');
            api('GET', root.getAttribute('data-url-suburbs') + '?q=' + encodeURIComponent(s.value)).then(function (r) {
                renderResults(box, (r.data && r.data.data) || [], function (i) { return i.label; }, function (i) { save(holderOf(s), { suburb_id: i.id }); });
            });
        } else if (c) {
            var cbox = c.parentNode.querySelector('[data-contact-results]');
            var party = c.closest('[data-contact-wrap]').getAttribute('data-contact-wrap');
            api('GET', root.getAttribute('data-url-contacts') + '?q=' + encodeURIComponent(c.value)).then(function (r) {
                renderResults(cbox, (r.data && r.data.data) || [], function (i) { return i.label + (i.detail ? ' — ' + i.detail : ''); }, function (i) {
                    var pl = {}; pl[party] = { action: 'link', contact_id: i.id };
                    save(holderOf(c), pl);
                });
            });
        } else if (ps) {
            var pc = ps.closest('[data-people-party]'), pbox = pc.querySelector('[data-person-results]');
            api('GET', root.getAttribute('data-url-contacts') + '?q=' + encodeURIComponent(ps.value)).then(function (r) {
                renderResults(pbox, (r.data && r.data.data) || [], function (i) { return i.label + (i.detail ? ' — ' + i.detail : ''); }, function (i) {
                    save(holderOf(ps), { person: { party: pc.getAttribute('data-people-party'), op: 'add', contact_id: i.id, capacity: pc.querySelector('[data-person-new-capacity]').value } });
                });
            });
        }
    }, 250));

    // ── "for all" defaults: saved on the batch, then the page is drawn again (many leases change at once) ──
    function saveDefaults(body) {
        say('');
        return api('POST', root.getAttribute('data-url-defaults'), body).then(function (res) {
            if (!res.ok) { say((res.data && res.data.message) || 'That could not be saved.'); return; }
            window.location.reload();
        }, function () { say('The connection was lost — nothing was saved. Try again.'); });
    }
    var dAgent = document.getElementById('reos-default-agent');
    if (dAgent) { dAgent.addEventListener('change', function () { saveDefaults({ agent_user_id: dAgent.value === '' ? null : parseInt(dAgent.value, 10) }); }); }
    var dSub = document.querySelector('[data-default-suburb-input]');
    if (dSub) {
        var dBox = document.querySelector('[data-default-suburb-results]');
        dSub.addEventListener('input', debounce(function () {
            api('GET', root.getAttribute('data-url-suburbs') + '?q=' + encodeURIComponent(dSub.value)).then(function (r) {
                renderResults(dBox, (r.data && r.data.data) || [], function (i) { return i.label; }, function (i) { saveDefaults({ suburb_id: i.id }); });
            });
        }, 250));
    }
    var dClear = document.querySelector('[data-default-suburb-clear]');
    if (dClear) { dClear.addEventListener('click', function () { saveDefaults({ suburb_id: null }); }); }

    // ── search: runs as you type ───────────────────────────────────────────────────
    var q = document.getElementById('reos-q');
    if (q) {
        var last = q.value;
        q.addEventListener('input', debounce(function () { if (q.value !== last) { last = q.value; document.getElementById('reos-search-form').submit(); } }, 500));
    }

    labelCard();
})();
</script>
@endpush
@endsection
