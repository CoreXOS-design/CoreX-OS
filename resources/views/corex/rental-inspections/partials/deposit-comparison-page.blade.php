@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md §45.7a (Build I-7) — the MOVE-OUT COMPARISON. One screen: every item
    side by side (move-in | move-out) in the agency's own condition words, notes, photos with the time each
    was taken, rows marked as a difference (derived at read time, never stored), a "what happened in
    between" timeline on every marked row, and the agent's own classification of each difference.

    Every row is a PROPOSAL for an agent to review, never a computed money figure — no amount/currency
    appears anywhere on this page, deliberately (money belongs to the finance build, parked I-8).

    No Alpine on this page: filters are a plain GET form, rooms are <details>, the photo viewer is a small
    plain-JS block at the bottom reading data-* attributes (nothing user-supplied is ever put in innerHTML).
--}}

@php
    $badgeFor = fn ($c) => match ($c) {
        'declined' => 'ds-badge-danger',
        'improved' => 'ds-badge-success',
        'unchanged' => 'ds-badge-muted',
        'different', 'na_mismatch', 'only_at_in', 'only_at_out' => 'ds-badge-warning',
        default => 'ds-badge-muted',
    };
    $filters = $filters ?? [];
    $anyFilter = ($filters['q'] ?? '') !== '' || ($filters['room'] ?? '') !== '' || ($filters['difference'] ?? 'all') !== 'all'
        || ($filters['classification'] ?? 'all') !== 'all' || ! empty($filters['only_differences']);
    $clearUrl = route('corex.rental-inspections.deposit-comparison', $inspection);
@endphp

@section('content')
<style>
    .cmp-card { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .cmp-field { border: 1px solid var(--border); border-radius: 4px; padding: 3px 8px; font-size: 12px; background: var(--surface); color: inherit; }
    .cmp-strip { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .cmp-thumb { position: relative; width: 84px; padding: 0; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2, transparent); cursor: pointer; text-align: left; overflow: hidden; color: inherit; }
    .cmp-thumb img { display: block; width: 84px; height: 64px; object-fit: cover; }
    .cmp-cap { display: block; font-size: 10px; line-height: 1.2; padding: 2px 3px; color: var(--text-muted); }
    .cmp-paired { position: absolute; top: 2px; right: 2px; font-size: 9px; padding: 0 4px; border-radius: 3px; background: rgba(0,0,0,.65); color: #fff; }
    .cmp-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; vertical-align: baseline; }
    .cmp-col-h { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); font-weight: 600; }
    .cmp-overlay { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.82); display: none; align-items: center; justify-content: center; padding: 12px; }
    .cmp-overlay.is-open { display: flex; }
    .cmp-modal { background: var(--surface); color: inherit; border-radius: 8px; width: min(1100px, 100%); max-height: 100%; overflow: auto; padding: 12px; }
    .cmp-panes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 720px) { .cmp-panes { grid-template-columns: 1fr; } .cmp-grid2 { grid-template-columns: 1fr !important; } }
    .cmp-pane img { width: 100%; max-height: 62vh; object-fit: contain; background: #111; border-radius: 4px; }
</style>

<div class="p-6 max-w-6xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="text-xs" style="color: var(--text-muted);">Move-out comparison — {{ $inspection->lease?->tenantNames() ?? 'Unknown tenant' }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if($inventoryComparison)
                <a href="{{ route('corex.rental-inventories.comparison', $inventoryComparison) }}" class="corex-btn-outline text-xs">Inventory comparison</a>
            @endif
            <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">&larr; Out-inspection</a>
        </div>
    </div>

    @unless($inInspection)
        <div class="cmp-card p-4 text-sm" style="color: var(--text-muted);">
            No in-inspection found for this tenancy — nothing to compare against yet. Every item below is shown as "{{ \App\Services\RentalInspectionComparisonService::MARK_LABELS['only_at_out'] }}."
        </div>
    @else
        <p class="text-xs" style="color: var(--text-muted);">
            Compared with the move-in inspection{{ $inInspection->completed_at ? ' completed ' . $inInspection->completed_at->format('d M Y') : ' (not yet completed)' }}.
            Interim checks, fault reports and repairs in between appear on the row they belong to.
        </p>
    @endunless

    @if(!empty($headerFacts))
    <div class="cmp-card p-4 space-y-2">
        <h2 class="text-sm font-semibold">Keys, remotes &amp; meters</h2>
        @foreach($headerFacts as $fact)
            <div class="flex items-center justify-between text-sm" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $fact['label'] }}</span>
                <span style="color: var(--text-muted);">
                    {{ $fact['in'] ?? '—' }} <span style="opacity:.6;">at move-in</span>
                    &rarr;
                    {{ $fact['out'] ?? '—' }} <span style="opacity:.6;">at move-out</span>
                    @if($fact['changed'])
                        <span class="ds-badge ds-badge-warning">Changed</span>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Summary — the same marks the rows carry, counted over the whole comparison (before any filter). --}}
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="ds-badge ds-badge-danger">{{ $counts['worse'] }} worse</span>
        <span class="ds-badge ds-badge-warning">{{ $counts['different'] }} different</span>
        <span class="ds-badge ds-badge-warning">{{ $counts['new'] }} new</span>
        <span class="ds-badge ds-badge-warning">{{ $counts['not_at_out'] }} not at move-out</span>
        @if($counts['na'] > 0)<span class="ds-badge ds-badge-warning">{{ $counts['na'] }} N/A on one side</span>@endif
        <span class="ds-badge ds-badge-success">{{ $counts['better'] }} better</span>
        <span class="ds-badge ds-badge-muted">{{ $counts['same'] }} same</span>
        <span style="color: var(--text-muted);">{{ $counts['marked'] }} marked, {{ $counts['unclassified_marked'] }} not yet classified</span>
    </div>

    {{-- Search / filter / sort — plain GET, so a filtered view is a link you can share and reload. --}}
    <form method="GET" action="{{ $clearUrl }}" class="cmp-card p-3 flex flex-wrap items-end gap-3 text-xs">
        <label class="flex flex-col gap-1">Search rooms, items, notes
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" class="cmp-field" style="min-width: 190px;" placeholder="e.g. kitchen, stain">
        </label>
        <label class="flex flex-col gap-1">Room
            <select name="room" class="cmp-field">
                <option value="">All rooms</option>
                @foreach($roomOptions as $roomId => $roomLabel)
                    <option value="{{ $roomId }}" @selected(($filters['room'] ?? '') === (string) $roomId)>{{ $roomLabel }}</option>
                @endforeach
                @if($hasUnroomed)<option value="none" @selected(($filters['room'] ?? '') === 'none')>General (no room)</option>@endif
            </select>
        </label>
        <label class="flex flex-col gap-1">Difference
            <select name="difference" class="cmp-field">
                @foreach(['all' => 'All', 'worse' => 'Worse than move-in', 'different' => 'Different from move-in', 'same' => 'Same as move-in', 'better' => 'Better', 'new' => 'New item', 'not_at_out' => 'Not at move-out', 'na' => 'N/A on one side only'] as $value => $text)
                    <option value="{{ $value }}" @selected(($filters['difference'] ?? 'all') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">Classification
            <select name="classification" class="cmp-field">
                <option value="all" @selected(($filters['classification'] ?? 'all') === 'all')>All</option>
                <option value="unclassified" @selected(($filters['classification'] ?? '') === 'unclassified')>Not yet classified</option>
                @foreach($dispositionLabels as $value => $text)
                    <option value="{{ $value }}" @selected(($filters['classification'] ?? '') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">Sort
            <select name="sort" class="cmp-field">
                <option value="walking" @selected(($filters['sort'] ?? 'walking') === 'walking')>Walking order</option>
                <option value="severity" @selected(($filters['sort'] ?? '') === 'severity')>Biggest differences first</option>
            </select>
        </label>
        <label class="flex items-center gap-1 pb-1">
            <input type="checkbox" name="only_differences" value="1" @checked(!empty($filters['only_differences']))> Differences only
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Apply</button>
        @if($anyFilter || ($filters['sort'] ?? 'walking') !== 'walking')
            <a href="{{ $clearUrl }}" class="text-xs" style="color: var(--text-muted); text-decoration: underline;">Clear</a>
        @endif
        <span class="ml-auto" style="color: var(--text-muted);">Showing {{ $shown }} of {{ $counts['total'] }} items</span>
    </form>

    @forelse($groups as $group)
        <details class="cmp-card" open>
            <summary class="px-4 py-2 cursor-pointer flex items-center justify-between text-sm font-semibold">
                <span>{{ $group['label'] }} <span class="text-xs font-normal" style="color: var(--text-muted);">· {{ $group['rows']->count() }} item{{ $group['rows']->count() === 1 ? '' : 's' }}</span></span>
                @if($group['marked'] > 0)<span class="ds-badge ds-badge-warning">{{ $group['marked'] }} marked</span>@endif
            </summary>

            <div class="px-4 pb-3 space-y-3">
                @if(!empty($group['in_photos']) || !empty($group['out_photos']))
                    @php $roomScope = 'room-' . ($group['room']?->id ?? 0); @endphp
                    <div class="grid grid-cols-2 gap-3 cmp-grid2" style="border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        @foreach(['in' => ['Room photos at move-in', $group['in_photos']], 'out' => ['Room photos at move-out', $group['out_photos']]] as $side => [$sideTitle, $sidePhotos])
                            <div>
                                <div class="cmp-col-h">{{ $sideTitle }}</div>
                                @include('corex.rental-inspections.partials._comparison-photos', ['scope' => $roomScope, 'side' => $side, 'photos' => $sidePhotos])
                            </div>
                        @endforeach
                    </div>
                @endif

                @foreach($group['rows'] as $row)
                    @php
                        $item = $row['item'];
                        $finding = $row['finding'];
                        $scope = 'item-' . $item?->id;
                    @endphp
                    <div id="item-{{ $item?->id }}" class="text-sm py-2 space-y-2" style="border-bottom: 1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-medium">{{ $item?->label ?? 'Unknown item' }}</span>
                            <span class="ds-badge {{ $badgeFor($row['classification']) }}">{{ $row['mark_label'] }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-xs cmp-grid2">
                            @foreach(['in' => ['Move-in', $row['in_cell']], 'out' => ['Move-out', $row['out_cell']]] as $side => [$sideTitle, $cell])
                                <div>
                                    <div class="cmp-col-h">{{ $sideTitle }}</div>
                                    @if($cell['present'])
                                        <div class="mt-1"><span class="cmp-dot" style="background: {{ $cell['color'] }};"></span><strong>{{ $cell['label'] }}</strong></div>
                                        @if($cell['notes'])<div style="color: var(--text-muted);">{{ $cell['notes'] }}</div>@endif
                                    @else
                                        <div class="mt-1" style="color: var(--text-muted);">Not recorded</div>
                                    @endif
                                    @if(!empty($cell['photos']))
                                        @include('corex.rental-inspections.partials._comparison-photos', ['scope' => $scope, 'side' => $side, 'photos' => $cell['photos']])
                                    @elseif($cell['present'])
                                        <div style="color: var(--text-muted); opacity: .7;">No photos</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if($row['marked'] && $row['context'] && (count($row['context']['observations']) + count($row['context']['fault_reports']) + count($row['context']['work_orders'])) > 0)
                            @php $ctx = $row['context']; @endphp
                            <details class="text-xs rounded px-2 py-1" style="background: var(--surface-2);">
                                <summary class="cursor-pointer font-semibold">What happened in between ({{ count($ctx['observations']) + count($ctx['fault_reports']) + count($ctx['work_orders']) }})</summary>
                                <ul class="mt-1 space-y-1">
                                    @foreach($ctx['observations'] as $o)
                                        <li>{{ $o['when']?->format('d M Y') }} — {{ $o['kind'] }}: <strong>{{ $o['condition'] }}</strong>@if($o['notes']) — {{ $o['notes'] }}@endif @if($o['by']) <span style="color: var(--text-muted);">({{ $o['by'] }})</span>@endif</li>
                                    @endforeach
                                    @foreach($ctx['fault_reports'] as $f)
                                        <li>{{ $f['reported_at']?->format('d M Y') }} — Fault report
                                            <a href="{{ route('corex.rental-fault-reports.show', $f['id']) }}" style="color: var(--brand-icon, #2563eb);">#{{ $f['id'] }}</a>:
                                            {{ $f['title'] }} — {{ $f['status'] }}@if($f['outcome']) ({{ $f['outcome'] }})@endif
                                            @if($f['closed_at'])<span style="color: var(--text-muted);">closed {{ $f['closed_at']->format('d M Y') }}</span>@endif</li>
                                    @endforeach
                                    @foreach($ctx['work_orders'] as $w)
                                        <li>{{ $w['reported_at']?->format('d M Y') }} — Work order
                                            <a href="{{ route('corex.rental-work-orders.show', $w['id']) }}" style="color: var(--brand-icon, #2563eb);">#{{ $w['id'] }}</a>:
                                            {{ $w['title'] }} — {{ $w['status'] }}, {{ $w['done_by'] }}
                                            @if($w['closed_at'])<span style="color: var(--text-muted);">closed {{ $w['closed_at']->format('d M Y') }}</span>@endif</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @if($row['marked'])
                            @if($finding)
                                <div class="text-xs rounded px-2 py-1" style="background: var(--surface-2);">
                                    Classified: <strong>{{ $dispositionLabels[$finding->disposition] ?? $finding->disposition }}</strong>
                                    by {{ $finding->recordedBy?->name ?? 'a former user' }}@if($finding->recorded_at) on {{ $finding->recorded_at->format('d M Y') }}@endif — {{ $finding->note }}
                                </div>
                            @endif
                            @permission('rental_inspections.review_deposit_comparison')
                                <form method="POST" action="{{ route('corex.rental-inspections.deposit-comparison.finding', [$inspection, $item->id]) }}" class="flex flex-wrap items-center gap-2 pt-1">
                                    @csrf
                                    <select name="disposition" class="cmp-field">
                                        @foreach($dispositionLabels as $value => $text)
                                            <option value="{{ $value }}" @selected($finding?->disposition === $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="note" required maxlength="2000" placeholder="Reasoning (required)" class="cmp-field flex-1" style="min-width: 180px;">
                                    <button type="submit" class="corex-btn-outline text-xs">{{ $finding ? 'Update' : 'Record' }}</button>
                                </form>
                            @endpermission
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @empty
        <div class="cmp-card p-4 text-xs" style="color: var(--text-muted);">
            @if($counts['total'] === 0)
                No comparable items yet — nothing has been recorded on both sides.
            @else
                No items match these filters.
                <a href="{{ $clearUrl }}" style="text-decoration: underline;">Clear the filters</a> to see all {{ $counts['total'] }} items.
            @endif
        </div>
    @endforelse

    <p class="text-xs" style="color: var(--text-muted);">
        This is a proposal for review, not a deduction — no amount has been calculated or applied against any deposit.
    </p>
</div>

{{-- The photo viewer: click any photo and it opens next to its counterpart from the other inspection
     (the matched photo if the two were paired on the Inspections tab, otherwise the first on that side). --}}
<div id="cmp-overlay" class="cmp-overlay" role="dialog" aria-modal="true" aria-label="Photo comparison">
    <div class="cmp-modal">
        <div class="flex items-center justify-between mb-2">
            <strong id="cmp-title" class="text-sm"></strong>
            <button type="button" id="cmp-close" class="corex-btn-outline text-xs">Close (Esc)</button>
        </div>
        <div class="cmp-panes">
            <div class="cmp-pane" data-pane="in">
                <div class="cmp-col-h">Move-in</div>
                <img alt="" id="cmp-img-in" style="display:none;">
                <div id="cmp-empty-in" class="text-xs" style="color: var(--text-muted);">No photo on this side.</div>
                <div class="text-xs mt-1" id="cmp-cap-in"></div>
                <div class="text-xs" id="cmp-note-in" style="color: var(--text-muted);"></div>
                <div class="flex gap-2 mt-1"><button type="button" class="corex-btn-outline text-xs" data-step="in:-1">&lsaquo; Prev</button><button type="button" class="corex-btn-outline text-xs" data-step="in:1">Next &rsaquo;</button><span class="text-xs" id="cmp-count-in" style="color: var(--text-muted);"></span></div>
            </div>
            <div class="cmp-pane" data-pane="out">
                <div class="cmp-col-h">Move-out</div>
                <img alt="" id="cmp-img-out" style="display:none;">
                <div id="cmp-empty-out" class="text-xs" style="color: var(--text-muted);">No photo on this side.</div>
                <div class="text-xs mt-1" id="cmp-cap-out"></div>
                <div class="text-xs" id="cmp-note-out" style="color: var(--text-muted);"></div>
                <div class="flex gap-2 mt-1"><button type="button" class="corex-btn-outline text-xs" data-step="out:-1">&lsaquo; Prev</button><button type="button" class="corex-btn-outline text-xs" data-step="out:1">Next &rsaquo;</button><span class="text-xs" id="cmp-count-out" style="color: var(--text-muted);"></span></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('cmp-overlay');
    if (!overlay) return;
    var state = { scope: null, idx: { in: 0, out: 0 }, lists: { in: [], out: [] } };

    function thumbs(scope, side) {
        return Array.prototype.slice.call(document.querySelectorAll('[data-cmp-thumb][data-scope="' + scope + '"][data-side="' + side + '"]'));
    }
    function paint(side) {
        var list = state.lists[side], i = state.idx[side], t = list[i];
        var img = document.getElementById('cmp-img-' + side);
        var empty = document.getElementById('cmp-empty-' + side);
        var count = document.getElementById('cmp-count-' + side);
        if (!t) { img.style.display = 'none'; empty.style.display = ''; document.getElementById('cmp-cap-' + side).textContent = ''; document.getElementById('cmp-note-' + side).textContent = ''; count.textContent = ''; return; }
        img.src = t.getAttribute('data-src');
        img.style.display = ''; empty.style.display = 'none';
        document.getElementById('cmp-cap-' + side).textContent = t.getAttribute('data-caption') || '';
        document.getElementById('cmp-note-' + side).textContent = t.getAttribute('data-note') || '';
        count.textContent = list.length > 1 ? (i + 1) + ' of ' + list.length : '';
    }
    function open(thumb) {
        var scope = thumb.getAttribute('data-scope'), side = thumb.getAttribute('data-side'), other = side === 'in' ? 'out' : 'in';
        state.scope = scope;
        state.lists.in = thumbs(scope, 'in'); state.lists.out = thumbs(scope, 'out');
        state.idx[side] = state.lists[side].indexOf(thumb);
        var group = thumb.getAttribute('data-group'), match = 0;
        if (group) {
            var found = state.lists[other].findIndex(function (t) { return t.getAttribute('data-group') === group; });
            if (found >= 0) match = found;
        }
        state.idx[other] = match;
        document.getElementById('cmp-title').textContent = thumb.getAttribute('data-label') || 'Photos';
        paint('in'); paint('out');
        overlay.classList.add('is-open');
    }
    function close() { overlay.classList.remove('is-open'); }

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-cmp-thumb]') : null;
        if (t) { open(t); return; }
        var s = e.target.closest ? e.target.closest('[data-step]') : null;
        if (s) {
            var parts = s.getAttribute('data-step').split(':'), side = parts[0], n = state.lists[side].length;
            if (n > 0) { state.idx[side] = (state.idx[side] + parseInt(parts[1], 10) + n) % n; paint(side); }
            return;
        }
        if (e.target === overlay || e.target.id === 'cmp-close') close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
@endsection
