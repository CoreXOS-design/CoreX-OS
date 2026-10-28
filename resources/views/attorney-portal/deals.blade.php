@extends('attorney-portal.layout')
@section('title', 'My deals')
{{--
    .ai/specs/attorney-portal.md 7.1 - ONE grid, one line per deal; a deal opens in place to show its documents as single lines.
    Wide page: the grid scrolls inside its own frame (header row pinned) so the page itself does not scroll on a laptop screen.
--}}
@section('content')
@php
    $sortUrl = function (string $col) use ($sort, $dir) {
        $next = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        return route('attorney.deals', array_merge(request()->except('page'), ['sort' => $col, 'dir' => $next]));
    };
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $filtered = $q !== '' || $show !== 'all';
@endphp
<style>
    header.top .in, main { max-width: none; }
    @media (min-width: 900px) {
        body { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; padding: 10px 16px 12px; }
        .ap-frame { flex: 1 1 auto; min-height: 0; }
    }
    main h1 { font-size: 1.15rem; }
    .ap-head { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
    .ap-head .sub { margin: 0; font-size: .85rem; }
    .ap-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
    .ap-bar input[type=search] { padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: .9rem; width: 24rem; max-width: 100%; background: #fff; }
    .ap-bar select { padding: 6px 8px; border: 1px solid var(--line); border-radius: 8px; font-size: .9rem; background: #fff; }
    .ap-bar .btn { padding: 6px 14px; font-size: .88rem; }
    .ap-bar .count { color: var(--muted); font-size: .85rem; margin-left: auto; }
    .ap-frame { overflow: auto; background: #fff; border: 1px solid var(--line); border-radius: 8px; }
    table.ap-grid { table-layout: fixed; min-width: 1120px; border-collapse: separate; border-spacing: 0; }
    .ap-grid th, .ap-grid td { padding: 5px 8px; font-size: .85rem; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border-bottom: 1px solid var(--line); vertical-align: middle; }
    .ap-grid thead th { position: sticky; top: 0; z-index: 2; background: #f3f4f6; font-size: .72rem; border-bottom: 1px solid #d1d5db; }
    .ap-grid tr.deal { cursor: pointer; }
    .ap-grid tr.deal:hover td { background: #f0f9ff; }
    .ap-grid tr.deal.open td { background: #e0f2fe; }
    .ap-grid td.num { font-weight: 600; }
    .ap-grid td.addr { font-weight: 600; color: var(--brand); }
    .ap-chev { background: none; border: 0; padding: 0 4px; cursor: pointer; color: var(--muted); font-size: .8rem; transition: transform .12s; }
    tr.deal.open .ap-chev { transform: rotate(90deg); }
    .ap-grid .pill { font-size: .72rem; padding: 1px 8px; }
    tr.files > td { white-space: normal; background: #f9fafb; padding: 6px 8px 10px 40px; }
    table.ap-files { table-layout: fixed; width: 100%; max-width: 900px; border: 1px solid var(--line); border-radius: 6px; background: #fff; }
    .ap-files td, .ap-files th { padding: 3px 8px; font-size: .82rem; }
    .ap-files td.dl { text-align: right; width: 6.5rem; }
    .ap-files a.dl { color: var(--brand); font-weight: 600; text-decoration: none; }
    .ap-note { font-size: .8rem; color: var(--muted); margin: 0 0 4px; }
    .ap-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px; font-size: .85rem; color: var(--muted); }
    .ap-foot a { color: var(--brand); text-decoration: none; padding: 3px 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
</style>

<div class="ap-head">
    <h1>My deals</h1>
    <p class="sub">The deals {{ $agencyName }} has you on. Click a deal to see its documents.</p>
</div>

<form method="GET" action="{{ route('attorney.deals') }}" class="ap-bar" data-ap-bar>
    <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Find a deal: number, address, suburb, buyer, seller or agent" aria-label="Find a deal" autofocus>
    <select name="show" aria-label="Show" onchange="this.form.submit()">
        <option value="all" @selected($show === 'all')>All my deals</option>
        <option value="open" @selected($show === 'open')>Still open</option>
        <option value="finished" @selected($show === 'finished')>Finished (registered or cancelled)</option>
    </select>
    <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
    <button class="btn" type="submit">Search</button>
    @if($filtered) <a class="btn ghost" href="{{ route('attorney.deals') }}">Clear</a> @endif
    <span class="count">{{ $deals->total() }} {{ \Illuminate\Support\Str::plural('deal', $deals->total()) }}</span>
</form>

@if($deals->isEmpty())
    <div class="card">
        @if(!$hasAny)
            <strong>No deals yet.</strong>
            <p class="muted" style="margin:6px 0 0;">When {{ $agencyName }} adds you to a deal, it appears here.</p>
        @else
            <strong>No deals match your search.</strong>
            <p class="muted" style="margin:6px 0 0;"><a href="{{ route('attorney.deals') }}">Clear the search</a> to see all your deals.</p>
        @endif
    </div>
@else
    <div class="ap-frame" data-ap-frame>
        <table class="ap-grid" data-ap-grid>
            <colgroup>
                <col style="width:34px"><col style="width:80px"><col><col style="width:150px"><col style="width:150px"><col style="width:200px"><col style="width:150px"><col style="width:105px"><col style="width:80px"><col style="width:62px">
            </colgroup>
            <thead><tr>
                <th></th>
                <th><a href="{{ $sortUrl('number') }}">Deal no.{{ $arrow('number') }}</a></th>
                <th><a href="{{ $sortUrl('property') }}">Property{{ $arrow('property') }}</a></th>
                <th><a href="{{ $sortUrl('seller') }}">Seller{{ $arrow('seller') }}</a></th>
                <th><a href="{{ $sortUrl('buyer') }}">Buyer{{ $arrow('buyer') }}</a></th>
                <th><a href="{{ $sortUrl('agent') }}">Agent{{ $arrow('agent') }}</a></th>
                <th><a href="{{ $sortUrl('stage') }}">Where it is{{ $arrow('stage') }}</a></th>
                <th><a href="{{ $sortUrl('latest') }}">Last document{{ $arrow('latest') }}</a></th>
                <th><a href="{{ $sortUrl('docs') }}">Documents{{ $arrow('docs') }}</a></th>
                <th></th>
            </tr></thead>
            <tbody>
            @foreach($deals as $r)
                <tr class="deal" data-deal-id="{{ $r['id'] }}" tabindex="0" aria-expanded="false">
                    <td><button type="button" class="ap-chev" aria-label="Show documents for {{ $r['address'] }}" data-ap-toggle>&#9654;</button></td>
                    <td class="num" title="{{ $r['reference'] }}">{{ $r['number'] }}</td>
                    <td class="addr" title="{{ $r['address'] }}{{ $r['suburb'] ? ', ' . $r['suburb'] : '' }}">{{ $r['address'] }}</td>
                    <td title="{{ $r['seller'] }}">{{ $r['seller'] }}</td>
                    <td title="{{ $r['buyer'] }}">{{ $r['buyer'] }}</td>
                    <td title="{{ $r['agent'] }}{{ $r['agent_phone'] ? ' - ' . $r['agent_phone'] : '' }}">{{ $r['agent'] ?: '—' }}@if($r['agent_phone']) <span class="muted">&middot; {{ $r['agent_phone'] }}</span>@endif</td>
                    <td><span class="pill {{ $r['stage'] === 'Cancelled' ? 'bad' : ($r['finished'] ? 'done' : '') }}">{{ $r['stage'] }}</span></td>
                    <td>{{ $r['latest_document'] ? $r['latest_document']->format('j M Y') : '—' }}</td>
                    <td>{{ $r['document_count'] }}</td>
                    <td><a href="{{ route('attorney.deal', $r['id']) }}" style="color:var(--brand);text-decoration:none;font-weight:600;" title="Open this deal on its own page">Open</a></td>
                </tr>
                <tr class="files" hidden data-files-for="{{ $r['id'] }}">
                    <td colspan="10">
                        @if($r['finished'])<p class="ap-note">This deal is {{ strtolower($r['stage']) }}. You can still use it until {{ $r['visible_until']->format('j M Y') }}.</p>@endif
                        @if(empty($r['files']))
                            <p class="ap-note" style="margin:0;">No documents have been shared on this deal yet. You will get an email when there are some.</p>
                        @else
                            <table class="ap-files">
                                <colgroup><col style="width:190px"><col><col style="width:110px"><col style="width:104px"></colgroup>
                                <tbody>
                                @foreach($r['files'] as $f)
                                    <tr>
                                        <td title="{{ $f['type'] }}">{{ $f['type'] }}</td>
                                        <td title="{{ $f['name'] }}">{{ $f['name'] }}</td>
                                        <td class="muted">{{ $f['filed'] ? $f['filed']->format('j M Y') : '—' }}</td>
                                        <td class="dl"><a class="dl" href="{{ route('attorney.document', [$r['id'], $f['id'], 'download']) }}" data-ap-download>Download</a></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="ap-foot">
        <span>Showing {{ $deals->firstItem() }}&ndash;{{ $deals->lastItem() }} of {{ $deals->total() }}</span>
        @if($deals->hasPages())
            <span style="display:flex;gap:8px;align-items:center;">
                @if($deals->currentPage() > 1)<a href="{{ $deals->previousPageUrl() }}">&larr; Previous</a>@endif
                <span>Page {{ $deals->currentPage() }} of {{ $deals->lastPage() }}</span>
                @if($deals->hasMorePages())<a href="{{ $deals->nextPageUrl() }}">Next &rarr;</a>@endif
            </span>
        @endif
    </div>
    <script>
        (function () {
            var grid = document.querySelector('[data-ap-grid]');
            if (!grid) return;
            function toggle(row) {
                var files = grid.querySelector('tr.files[data-files-for="' + row.getAttribute('data-deal-id') + '"]');
                if (!files) return;
                var open = files.hasAttribute('hidden');
                if (open) { files.removeAttribute('hidden'); } else { files.setAttribute('hidden', ''); }
                row.classList.toggle('open', open);
                row.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            grid.addEventListener('click', function (e) {
                if (e.target.closest('a')) return;
                var row = e.target.closest('tr.deal');
                if (row) toggle(row);
            });
            grid.addEventListener('keydown', function (e) {
                if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr.deal')) { e.preventDefault(); toggle(e.target); }
            });
        })();
    </script>
@endif
@endsection
