@extends('attorney-portal.layout')
@section('title', 'My deals')
@section('content')
@php
    $sortUrl = function (string $col) use ($sort, $dir) {
        $next = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        return route('attorney.deals', array_merge(request()->except('page'), ['sort' => $col, 'dir' => $next]));
    };
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
@endphp
<h1>My deals</h1>
<p class="sub">The deals {{ $agencyName }} has you on. Open one to see who is involved and to download your documents.</p>

<form method="GET" action="{{ route('attorney.deals') }}" class="filters">
    <div>
        <label for="q">Search</label>
        <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Address, buyer, seller or reference" style="min-width:240px;">
    </div>
    <div>
        <label for="show">Show</label>
        <select id="show" name="show">
            <option value="all" @selected($show === 'all')>All my deals</option>
            <option value="open" @selected($show === 'open')>Still open</option>
            <option value="finished" @selected($show === 'finished')>Finished (registered or cancelled)</option>
        </select>
    </div>
    <div><label for="from">Documents from</label><input id="from" type="date" name="from" value="{{ $from }}"></div>
    <div><label for="to">Documents to</label><input id="to" type="date" name="to" value="{{ $to }}"></div>
    <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
    <div><button class="btn" type="submit">Search</button>
        @if($q !== '' || $show !== 'all' || $from || $to) <a class="btn ghost" href="{{ route('attorney.deals') }}">Clear</a> @endif
    </div>
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
    <div class="card" style="padding:6px 12px;overflow-x:auto;">
        <table>
            <thead><tr>
                <th><a href="{{ $sortUrl('property') }}">Property{{ $arrow('property') }}</a></th>
                <th class="hide-sm"><a href="{{ $sortUrl('parties') }}">Buyer and seller{{ $arrow('parties') }}</a></th>
                <th class="hide-sm"><a href="{{ $sortUrl('agent') }}">Agent{{ $arrow('agent') }}</a></th>
                <th><a href="{{ $sortUrl('stage') }}">Where it is{{ $arrow('stage') }}</a></th>
                <th><a href="{{ $sortUrl('latest') }}">Latest document{{ $arrow('latest') }}</a></th>
            </tr></thead>
            <tbody>
            @foreach($deals as $r)
                <tr>
                    <td>
                        <a href="{{ route('attorney.deal', $r['id']) }}" style="font-weight:600;color:var(--brand);text-decoration:none;">{{ $r['address'] }}</a>
                        @if($r['reference'])<div class="muted" style="font-size:.8rem;">{{ $r['reference'] }}</div>@endif
                    </td>
                    <td class="hide-sm"><div>Buyer: {{ $r['buyer'] }}</div><div class="muted">Seller: {{ $r['seller'] }}</div></td>
                    <td class="hide-sm">{{ $r['agent'] ?: '—' }}</td>
                    <td>
                        <span class="pill {{ $r['stage'] === 'Cancelled' ? 'bad' : ($r['finished'] ? 'done' : '') }}">{{ $r['stage'] }}</span>
                        @if($r['finished'])<div class="muted" style="font-size:.78rem;">Available until {{ $r['visible_until']->format('j M Y') }}</div>@endif
                    </td>
                    <td>
                        @if($r['latest_document']) {{ $r['latest_document']->format('j M Y') }}<div class="muted" style="font-size:.78rem;">{{ $r['document_count'] }} {{ \Illuminate\Support\Str::plural('document', $r['document_count']) }}</div>
                        @else <span class="muted">None yet</span> @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if($deals->hasPages())
        <div class="pager">
            @if($deals->currentPage() > 1)<a href="{{ $deals->previousPageUrl() }}">&larr; Previous</a>@endif
            <span class="cur">Page {{ $deals->currentPage() }} of {{ $deals->lastPage() }}</span>
            @if($deals->hasMorePages())<a href="{{ $deals->nextPageUrl() }}">Next &rarr;</a>@endif
        </div>
    @endif
@endif
@endsection
