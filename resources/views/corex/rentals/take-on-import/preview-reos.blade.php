@extends('layouts.corex')

{{--
    AT-479 — .ai/specs/rental-takeon-import.md "reOS Tenancy Schedule import" R4/R5. The review screen for a reOS batch: every
    lease, what CoreX already knows (property / owner / tenant / the lease itself), and the user's choices — saved the moment
    they are made (ReosTakeOnApiController), so they survive paging and reloads. Nothing is created until Import is pressed.

    List standard (BUILD_STANDARD §1b): search = address, owner, tenant, agent text, reOS lease id; sort = sheet row (default,
    ascending) / address / owner / tenant / status / rent; filters = property match, readiness, lease status, import state;
    25 per page; real empty state. Scoping: the run is checked with RentalTakeOnImportRun::visibleTo before this renders.
    Deliberately no Alpine (the existing preview's rule) — plain JavaScript, event delegation on the table.
--}}

@php
    $sortLink = fn ($col) => route('corex.rentals.take-on-import.preview', array_merge(
        request()->except('page'),
        ['run' => $run->id, 'sort' => $col, 'dir' => (($filters['sort'] ?? 'row') === $col && ($filters['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc']
    ));
    $sortMark = fn ($col) => ($filters['sort'] ?? 'row') === $col ? (($filters['dir'] ?? 'asc') === 'asc' ? ' ▲' : ' ▼') : '';
    $roll = $run->counts_json['rent_roll'] ?? null;
    $anyFilter = $filters !== [] && ! (count($filters) === 2 && isset($filters['sort'], $filters['dir']) && $filters['sort'] === 'row');
@endphp

@section('content')
<div class="p-6 space-y-4" id="reos-review" data-run-id="{{ $run->id }}"
     data-url-bulk="{{ route('api.v1.rentals.take-on-import.bulk', $run) }}"
     data-url-suburbs="{{ route('api.v1.rentals.take-on-import.suburbs') }}"
     data-url-contacts="{{ route('api.v1.rentals.take-on-import.contacts') }}"
     data-url-decisions="{{ url('/api/v1/rentals/take-on-import/rows') }}"
     data-filters="{{ json_encode($filters) }}">

    {{-- Sticky header: counts, the next action, Import. --}}
    {{-- Solid background and a stacking order above every row control (the suburb / contact result lists are z-20): the rows scroll UNDER it. --}}
    {{-- The page scrolls inside <main id="appScroll"> which has its own padding (p-4, lg:p-6): a sticky bar at top:0 stops at the inside of
         that padding and the rows show in the strip above it. A negative top equal to the padding sticks it flush with the scroll area. --}}
    <style>
        #reos-bar { top: -1rem; }
        @media (min-width: 1024px) { #reos-bar { top: -1.5rem; } }
    </style>
    <div id="reos-bar" class="corex-card p-3 space-y-2" style="position: sticky; z-index: 50; background: var(--surface, #fff); box-shadow: 0 2px 6px rgba(0,0,0,.12);" data-test="reos-bar">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-base font-semibold">Review reOS import — {{ $run->source_filename }}</h1>
                <p class="text-xs text-muted">
                    Uploaded {{ $run->created_at->format('Y-m-d H:i') }} by {{ $run->user?->name }}.
                    @if($roll) Rent Roll "{{ $run->rent_roll_filename }}": {{ $roll['matched'] ?? 0 }} wallet reference(s) matched to leases, {{ $roll['unmatched_roll_rows'] ?? 0 }} Rent Roll row(s) matched nothing.@endif
                </p>
            </div>
            <a href="{{ route('corex.rentals.take-on-import.index') }}" class="corex-btn-secondary text-xs">Back to batches</a>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-xs" data-test="reos-counts">
            <span class="ds-badge ds-badge-info">Ticked: <strong data-sum="selected">{{ $summary['selected'] }}</strong></span>
            <span class="ds-badge ds-badge-muted">To create: <strong data-sum="to_create_properties">{{ $summary['to_create_properties'] }}</strong> properties ·
                <strong data-sum="to_create_contacts">{{ $summary['to_create_contacts'] }}</strong> contacts</span>
            <span class="ds-badge ds-badge-muted">To update: <strong data-sum="to_update">{{ $summary['to_update'] }}</strong></span>
            <span class="ds-badge {{ $summary['blocked'] > 0 ? 'ds-badge-danger' : 'ds-badge-muted' }}" id="reos-blocked-chip">Blocked: <strong data-sum="blocked">{{ $summary['blocked'] }}</strong></span>
            <span class="ds-badge {{ ($summary['name_differs'] ?? 0) > 0 ? 'ds-badge-warning' : 'ds-badge-muted' }}" data-test="name-differs-chip">Name differs: <strong data-sum="name_differs">{{ $summary['name_differs'] ?? 0 }}</strong></span>
            <span class="ds-badge ds-badge-muted" data-test="spelling-chip" title="Same name, spelled differently — not counted as a difference">Spelling: <strong data-sum="spelling_differs">{{ $summary['spelling_differs'] ?? 0 }}</strong></span>
            <span class="ds-badge ds-badge-success">Imported: <strong data-sum="imported">{{ $summary['imported'] }}</strong> of {{ $summary['total'] }}</span>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm font-medium" id="reos-next" data-sum="next_action" data-test="reos-next">{{ $summary['next_action'] }}</p>
            @if($canManage)
                <form method="POST" action="{{ route('corex.rentals.take-on-import.reos.import', $run) }}" id="reos-import-form">
                    @csrf
                    @if($continueImport)<input type="hidden" name="continue" value="1">@endif
                    <button type="submit" id="reos-import-btn" class="corex-btn-primary text-sm" data-test="reos-import-btn" @disabled(! $summary['can_import'] && ! $continueImport)>Import ticked leases</button>
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
    <p class="text-xs text-muted">
        Nothing below has been created yet. Tick the leases to bring in; for each property, owner or tenant CoreX does not know, say whether to create it.
        A lease already in CoreX (same reOS lease id) is <strong>updated</strong> — never added twice. Importing never emails or messages anyone.
    </p>

    {{-- Search / filter / sort --}}
    <form method="GET" action="{{ route('corex.rentals.take-on-import.preview', $run) }}" class="corex-card p-3 flex flex-wrap items-end gap-3" data-test="reos-filters">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" for="reos-q">Search</label>
            <input type="text" id="reos-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Address, owner, tenant, agent, reOS id" class="list-header-filter text-sm {{ filled($filters['q'] ?? null) ? 'is-active' : '' }}" style="min-width:16rem;">
        </div>
        @foreach([
            'property' => ['Property', ['matched' => 'In CoreX', 'unmatched' => 'Not in CoreX']],
            'readiness' => ['Readiness', ['blocked' => 'Blocked', 'ready' => 'Ready', 'ticked' => 'Ticked', 'unticked' => 'Not ticked']],
            'lease_status' => ['reOS status', ['Active' => 'Active', 'Expiring' => 'Expiring', 'Draft' => 'Draft']],
            'state' => ['Import state', ['waiting' => 'Waiting', 'imported' => 'Imported', 'failed' => 'Failed', 'existing' => 'Already in CoreX']],
            'names' => ['Contact names', ['differs' => 'Name differs', 'spelling' => 'Spelling differs']],
        ] as $key => [$label, $options])
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" for="reos-f-{{ $key }}">{{ $label }}</label>
                <select id="reos-f-{{ $key }}" name="{{ $key }}" class="list-header-filter text-sm {{ filled($filters[$key] ?? null) ? 'is-active' : '' }}">
                    <option value="">All</option>
                    @foreach($options as $value => $text)
                        <option value="{{ $value }}" @selected(($filters[$key] ?? '') === (string) $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" for="reos-sort">Sort by</label>
            <select id="reos-sort" name="sort" class="list-header-filter text-sm">
                @foreach(['row' => 'Sheet row', 'address' => 'Address', 'owner' => 'Owner', 'tenant' => 'Tenant', 'status' => 'reOS status', 'rent' => 'Rent'] as $value => $text)
                    <option value="{{ $value }}" @selected(($filters['sort'] ?? 'row') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" for="reos-dir">Order</label>
            <select id="reos-dir" name="dir" class="list-header-filter text-sm">
                <option value="asc" @selected(($filters['dir'] ?? 'asc') === 'asc')>Ascending</option>
                <option value="desc" @selected(($filters['dir'] ?? 'asc') === 'desc')>Descending</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Apply</button>
        @if($anyFilter)<a href="{{ route('corex.rentals.take-on-import.preview', $run) }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>@endif
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">{{ number_format($rows->total()) }} {{ \Illuminate\Support\Str::plural('lease', $rows->total()) }}</span>
    </form>

    @if($canManage)
    {{-- Whole-batch actions --}}
    <div class="corex-card p-3 flex flex-wrap items-end gap-3 text-xs" data-test="reos-bulk">
        <button type="button" class="corex-btn-secondary text-xs" data-bulk="tick_ready">Tick all ready{{ $filters ? ' (in this filter)' : '' }}</button>
        <button type="button" class="corex-btn-secondary text-xs" data-bulk="tick_all">Tick all{{ $filters ? ' (in this filter)' : '' }}</button>
        <button type="button" class="corex-btn-outline text-xs" data-bulk="untick_all">Untick all{{ $filters ? ' (in this filter)' : '' }}</button>

        <div class="relative flex items-end gap-2" data-bulk-suburb-wrap>
            <div>
                <label class="block font-medium mb-1" for="reos-bulk-suburb">Suburb for all ticked new properties</label>
                <input type="text" id="reos-bulk-suburb" data-bulk-suburb-input autocomplete="off" placeholder="Type the suburb…" class="prop-input" style="max-width:15rem;">
                <div data-bulk-suburb-results class="hidden absolute z-20 rounded-md p-1" style="background:var(--surface); border:1px solid var(--border); min-width:15rem;"></div>
            </div>
            <button type="button" class="corex-btn-secondary text-xs" data-bulk="apply_suburb" id="reos-bulk-suburb-btn" disabled>Apply suburb</button>
        </div>

        <div class="flex items-end gap-2">
            <div>
                <label class="block font-medium mb-1" for="reos-bulk-agent">Agent for all ticked</label>
                <select id="reos-bulk-agent" class="prop-select" style="max-width:14rem;">
                    <option value="">Choose…</option>
                    @foreach($agents as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach
                </select>
            </div>
            <button type="button" class="corex-btn-secondary text-xs" data-bulk="apply_agent">Apply agent</button>
        </div>
        <span id="reos-bulk-msg" class="text-muted" role="status"></span>
    </div>
    @endif

    <div class="corex-card p-0 overflow-x-auto">
        @if($rows->isEmpty())
            <div class="ds-empty-state px-6 py-12 text-center text-sm text-muted" data-test="reos-empty">
                @if($anyFilter)
                    No leases match these filters. <a href="{{ route('corex.rentals.take-on-import.preview', $run) }}" class="underline">Clear the filters</a>.
                @else
                    This batch has no leases in it.
                @endif
            </div>
        @else
            {{-- Fits the page width without a horizontal scroll: fixed layout, percentage columns, controls 100% of their column; the
                 "to fix / to know" notes sit under the agent. (The wrapper keeps its own scroll only as a safety on a phone.) --}}
            <style>
                #reos-table { table-layout: fixed; width: 100%; }
                #reos-table th, #reos-table td { overflow-wrap: anywhere; vertical-align: top; }
                #reos-table input[type=text], #reos-table input[type=email], #reos-table select { width: 100%; box-sizing: border-box; }
                #reos-table input[type=checkbox] { width: auto; }
            </style>
            <table class="ds-table w-full text-xs" id="reos-table">
                <colgroup>
                    <col style="width:3%"><col style="width:14%"><col style="width:23%"><col style="width:19%"><col style="width:19%"><col style="width:22%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Tick</th>
                        <th><a href="{{ $sortLink('row') }}">Lease{{ $sortMark('row') }}</a> · <a href="{{ $sortLink('status') }}">status{{ $sortMark('status') }}</a> · <a href="{{ $sortLink('rent') }}">rent{{ $sortMark('rent') }}</a></th>
                        <th><a href="{{ $sortLink('address') }}">Property{{ $sortMark('address') }}</a></th>
                        <th><a href="{{ $sortLink('owner') }}">Owner{{ $sortMark('owner') }}</a></th>
                        <th><a href="{{ $sortLink('tenant') }}">Tenant{{ $sortMark('tenant') }}</a></th>
                        <th>Agent · to fix / to know</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @include('corex.rentals.take-on-import._reos-row', ['row' => $row, 'run' => $run, 'agents' => $agents, 'canManage' => $canManage])
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div>{{ $rows->links() }}</div>
</div>

@if($continueImport)
    {{-- The import works through the ticked leases a few at a time; this carries on by itself until none are left. --}}
    <script>setTimeout(function () { var f = document.getElementById('reos-import-form'); if (f) { f.submit(); } }, 600);</script>
@endif

@push('scripts')
<script>
(function () {
    var root = document.getElementById('reos-review');
    if (!root) { return; }
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var filters = {};
    try { filters = JSON.parse(root.getAttribute('data-filters') || '{}'); } catch (e) { filters = {}; }
    var table = document.getElementById('reos-table');
    var bulkSuburbId = null;

    function api(method, url, body) {
        return fetch(url, {
            method: method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }, function () { return { ok: r.ok, data: {} }; }); });
    }

    function setSummary(s) {
        if (!s) { return; }
        document.querySelectorAll('[data-sum]').forEach(function (el) {
            var k = el.getAttribute('data-sum');
            if (s[k] !== undefined) { el.textContent = s[k]; }
        });
        var btn = document.getElementById('reos-import-btn');
        if (btn) { btn.disabled = !s.can_import; }
        var chip = document.getElementById('reos-blocked-chip');
        if (chip) { chip.className = 'ds-badge ' + (s.blocked > 0 ? 'ds-badge-danger' : 'ds-badge-muted'); }
    }

    function say(msg) { var m = document.getElementById('reos-bulk-msg'); if (m) { m.textContent = msg || ''; } }

    function saveRow(tr, payload) {
        var id = tr.getAttribute('data-row-id');
        tr.style.opacity = '.6';
        return api('POST', root.getAttribute('data-url-decisions') + '/' + id + '/decisions', payload).then(function (res) {
            if (!res.ok) {
                tr.style.opacity = '';
                alertRow(tr, (res.data && res.data.message) || 'That could not be saved.');
                return;
            }
            var tmp = document.createElement('tbody');
            tmp.innerHTML = res.data.row_html.trim();
            var fresh = tmp.firstElementChild;
            tr.parentNode.replaceChild(fresh, tr);
            setSummary(res.data.summary);
        }, function () { tr.style.opacity = ''; alertRow(tr, 'The connection was lost — nothing was saved. Try again.'); });
    }

    function alertRow(tr, msg) {
        var cell = tr.querySelector('[data-test="row-notes"]');
        if (!cell) { return; }
        var d = document.createElement('div');
        d.className = 'text-red-600'; d.textContent = msg;
        cell.insertBefore(d, cell.firstChild);
    }

    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

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

    if (table) {
        table.addEventListener('change', function (e) {
            var el = e.target.closest('[data-field]');
            if (!el) { return; }
            var tr = el.closest('tr[data-row-id]');
            var field = el.getAttribute('data-field');
            var p = {};
            if (field === 'selected') { p.selected = el.checked; }
            else if (field === 'property_create') { p.property_create = el.checked; }
            else if (field === 'property_choice') { p.property_choice = el.value === 'new' ? 'new' : parseInt(el.value, 10); }
            else if (field === 'agent_user_id') { p.agent_user_id = el.value === '' ? null : parseInt(el.value, 10); }
            else if (field === 'tenant_wallet_ref') { p.tenant_wallet_ref = el.value; }
            else if (field === 'owner' || field === 'tenant') {
                if (el.value === 'link-auto') { p[field] = { action: 'link', contact_id: parseInt(el.getAttribute('data-auto-contact'), 10) }; }
                else if (el.value === 'link-other') { return; }
                else { p[field] = { action: el.value }; }
            } else { return; }
            saveRow(tr, p);
        });

        table.addEventListener('input', debounce(function (e) {
            var s = e.target.closest('[data-suburb-input]');
            var c = e.target.closest('[data-contact-input]');
            if (s) {
                var box = s.parentNode.querySelector('[data-suburb-results]');
                api('GET', root.getAttribute('data-url-suburbs') + '?q=' + encodeURIComponent(s.value)).then(function (r) {
                    renderResults(box, (r.data && r.data.data) || [], function (i) { return i.label; }, function (i) { saveRow(s.closest('tr[data-row-id]'), { suburb_id: i.id }); });
                });
            } else if (c) {
                var cbox = c.parentNode.querySelector('[data-contact-results]');
                var party = c.closest('[data-contact-wrap]').getAttribute('data-contact-wrap');
                api('GET', root.getAttribute('data-url-contacts') + '?q=' + encodeURIComponent(c.value)).then(function (r) {
                    renderResults(cbox, (r.data && r.data.data) || [], function (i) { return i.label + (i.detail ? ' — ' + i.detail : ''); }, function (i) {
                        var pl = {}; pl[party] = { action: 'link', contact_id: i.id };
                        saveRow(c.closest('tr[data-row-id]'), pl);
                    });
                });
            }
        }, 250));

        // People for a company (spec R6a): add / remove / change role / the one-click "create the company and link the matched person".
        function personCtx(el) {
            var box = el.closest('[data-people-party]');
            return { tr: el.closest('tr[data-row-id]'), party: box ? box.getAttribute('data-people-party') : null, box: box };
        }
        table.addEventListener('change', function (e) {
            var cap = e.target.closest('[data-person-capacity]');
            if (!cap) { return; }
            var c = personCtx(cap);
            saveRow(c.tr, { person: { party: c.party, op: 'set_capacity', key: cap.getAttribute('data-person-capacity'), capacity: cap.value } });
        });
        table.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-person-toggle]'))) { var pn = personCtx(t).box.querySelector('[data-person-panel]'); pn.classList.toggle('hidden'); return; }
            if ((t = e.target.closest('[data-person-remove]'))) { var c1 = personCtx(t); saveRow(c1.tr, { person: { party: c1.party, op: 'remove', key: t.getAttribute('data-person-remove') } }); return; }
            if ((t = e.target.closest('[data-person-convert]'))) { var c2 = personCtx(t); saveRow(c2.tr, { person: { party: c2.party, op: 'convert' } }); return; }
            if ((t = e.target.closest('[data-person-add-new]'))) {
                var c3 = personCtx(t), b = c3.box;
                saveRow(c3.tr, { person: {
                    party: c3.party, op: 'add',
                    first_name: b.querySelector('[data-person-first]').value, last_name: b.querySelector('[data-person-last]').value,
                    phone: b.querySelector('[data-person-phone]').value, email: b.querySelector('[data-person-email]').value,
                    capacity: b.querySelector('[data-person-new-capacity]').value
                } });
            }
        });
        table.addEventListener('input', debounce(function (e) {
            var ps = e.target.closest('[data-person-search]');
            if (!ps) { return; }
            var c = personCtx(ps), box = c.box.querySelector('[data-person-results]');
            api('GET', root.getAttribute('data-url-contacts') + '?q=' + encodeURIComponent(ps.value)).then(function (r) {
                renderResults(box, (r.data && r.data.data) || [], function (i) { return i.label + (i.detail ? ' — ' + i.detail : ''); }, function (i) {
                    saveRow(c.tr, { person: { party: c.party, op: 'add', contact_id: i.id, capacity: c.box.querySelector('[data-person-new-capacity]').value } });
                });
            });
        }, 250));

        table.addEventListener('click', function (e) {
            var clear = e.target.closest('[data-clear-suburb]');
            if (clear) { saveRow(clear.closest('tr[data-row-id]'), { suburb_id: null }); return; }
            var open = e.target.closest('[data-open-contact-search]');
            if (open) {
                var panel = open.parentNode.querySelector('[data-contact-panel]');
                panel.classList.toggle('hidden');
                var inp = panel.querySelector('input'); if (inp) { inp.focus(); }
                return;
            }
            var f = e.target.closest('a[data-focus]');
            if (f) {
                e.preventDefault();
                var target = document.getElementById(f.getAttribute('data-focus'));
                if (target) { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); target.focus(); }
            }
        });
    }

    // Whole-batch actions
    var bulkInput = document.querySelector('[data-bulk-suburb-input]');
    if (bulkInput) {
        var bbox = document.querySelector('[data-bulk-suburb-results]');
        bulkInput.addEventListener('input', debounce(function () {
            bulkSuburbId = null; document.getElementById('reos-bulk-suburb-btn').disabled = true;
            api('GET', root.getAttribute('data-url-suburbs') + '?q=' + encodeURIComponent(bulkInput.value)).then(function (r) {
                renderResults(bbox, (r.data && r.data.data) || [], function (i) { return i.label; }, function (i) {
                    bulkSuburbId = i.id; bulkInput.value = i.label; bbox.classList.add('hidden');
                    document.getElementById('reos-bulk-suburb-btn').disabled = false;
                });
            });
        }, 250));
    }
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-bulk');
            var body = { action: action, filters: filters };
            if (action === 'apply_suburb') { body.suburb_id = bulkSuburbId; }
            if (action === 'apply_agent') {
                var v = document.getElementById('reos-bulk-agent').value;
                if (!v) { say('Choose an agent first.'); return; }
                body.agent_user_id = parseInt(v, 10);
            }
            say('Working…');
            api('POST', root.getAttribute('data-url-bulk'), body).then(function (res) {
                if (!res.ok) { say((res.data && res.data.message) || 'That could not be done.'); return; }
                say(res.data.changed + ' lease(s) changed.');
                setSummary(res.data.summary);
                window.location.reload();
            }, function () { say('The connection was lost — nothing was changed.'); });
        });
    });
})();
</script>
@endpush
@endsection
