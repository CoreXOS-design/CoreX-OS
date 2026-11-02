@extends('layouts.corex')

{{--
    Road to Sold v4 belt points (road-to-sold.md 23.16) - "why did I get these points": the signed-in agent's own credits.
    Needs $rows (paginator of: activity_date, property, event, points, reversed, counts, revoke_reason), $totals (BeltPoints::totals), $filters, $credits.
    Screen-text rule (Johan): every line is data or a control. Newest first; search = property or event; filters = event + date range.
--}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $filtered = collect($filters)->filter()->isNotEmpty();
@endphp

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-bold text-white leading-tight">My points</h1>
        <div class="text-white text-sm" data-qa="points-totals">Today {{ rtrim(rtrim(number_format($totals['day'], 2), '0'), '.') }} &middot; This month {{ rtrim(rtrim(number_format($totals['month'], 2), '0'), '.') }}</div>
    </div>

    <form method="GET" action="{{ route('road-to-sold.points') }}" class="flex flex-wrap items-end gap-3" data-qa="points-filters">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="pq">Search</label>
            <input id="pq" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Property or what happened" class="rounded-md px-3 py-2 text-sm w-56" style="{{ $fieldStyle }}">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="pe">What happened</label>
            <select id="pe" name="event" class="rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                <option value="">All</option>
                @foreach($credits as $slug => $c)<option value="{{ $slug }}" @selected($filters['event'] === $slug)>{{ $c[0] }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="pf">From</label>
            <input id="pf" type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="pt">To</label>
            <input id="pt" type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
        </div>
        <button type="submit" class="inline-flex items-center justify-center h-10 px-5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9); border:1px solid var(--brand-icon, #0ea5e9);">Filter</button>
        @if($filtered)<a href="{{ route('road-to-sold.points') }}" class="inline-flex items-center justify-center h-10 px-4 rounded-md text-sm font-semibold" style="{{ $fieldStyle }}">Clear</a>@endif
    </form>

    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @if($rows->count() === 0)
            <div class="p-8 text-sm text-center" data-qa="points-empty" style="color:var(--text-muted);">{{ $filtered ? 'No points match this filter.' : 'No points yet. They appear here as you work the belt.' }}</div>
        @else
            <table class="w-full text-sm" data-qa="points-log">
                <thead><tr style="border-bottom:1px solid var(--border); color:var(--text-muted);" class="text-left text-xs">
                    <th class="px-4 py-2">Date</th><th class="px-4 py-2">Property</th><th class="px-4 py-2">What happened</th><th class="px-4 py-2 text-right">Points</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $r)
                    <tr style="border-bottom:1px solid var(--border); color:var(--text-primary);" @if($r->reversed) class="opacity-60" @endif>
                        <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->activity_date)->format('j M Y') }}</td>
                        <td class="px-4 py-2">{{ $r->property ?: '-' }}</td>
                        <td class="px-4 py-2">{{ $r->event }}@if($r->reversed) <span class="text-xs" style="color:var(--text-muted);">- taken back{{ $r->revoke_reason ? ': ' . $r->revoke_reason : '' }}</span>@endif</td>
                        <td class="px-4 py-2 text-right font-semibold {{ $r->reversed ? 'line-through' : '' }}">{{ rtrim(rtrim(number_format((float) $r->points, 2), '0'), '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
@endsection
