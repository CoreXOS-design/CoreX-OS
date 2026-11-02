{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold v4 (road-to-sold.md 23.12): the properties behind one number or one bar of the funnel. Needs $rows (paginator), $allowed, $title, $period, $station, $kind, $reason, $scope. --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold funnel list')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $back = route('road-to-sold.belt-funnel', array_filter($params + ['period' => $preset, 'start' => $start, 'end' => $end]));
    $scopeWord = ['me' => 'my properties', 'branch' => 'my branch', 'agency' => 'the agency'][$scope] ?? $scope;
    $sortUrl = fn ($col) => route('road-to-sold.belt-funnel.list', array_filter(request()->query() + ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
@endphp
<div class="w-full space-y-4 rts-page" data-qa="belt-funnel-list">
    <div class="rounded-md px-6 py-4 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $title }}</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $period->label }} ({{ $period->start->format('j M') }} - {{ $period->end->format('j M Y') }}), {{ $scopeWord }} - <strong>{{ $rows->total() }}</strong> {{ $rows->total() === 1 ? 'property' : 'properties' }}</p>
            </div>
            <a href="{{ $back }}" class="corex-btn-outline corex-btn-xs" data-qa="back-to-funnel">Back to the funnel</a>
        </div>
    </div>

    @unless($allowed)
        <div class="rounded-md px-6 py-8 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" data-qa="list-not-allowed">
            You can see the figures for {{ $scopeWord }}, but not the properties behind them - those belong to your colleagues. Your own properties are listed under "Me".
        </div>
    @else
        <form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="list-filters">
            @foreach(request()->except(['q', 'page']) as $k => $v)@if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bl-q">Search</label>
                <input id="bl-q" name="q" value="{{ $q }}" placeholder="Address or agent" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        </form>
        <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-xs ds-table">
                <thead><tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left"><a href="{{ $sortUrl('address') }}" class="underline">Property</a></th>
                    <th class="px-3 py-2 text-left"><a href="{{ $sortUrl('agent') }}" class="underline">Agent</a></th>
                    <th class="px-3 py-2 text-left"><a href="{{ $sortUrl('when') }}" class="underline">When</a></th>
                    @if($kind === 'lost')<th class="px-3 py-2 text-left">Why</th>@endif
                    <th class="px-3 py-2 text-left">Now</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr style="border-top: 1px solid var(--border);" data-qa="list-row">
                        <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $r['address'] }}</td>
                        <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['agent'] }}</td>
                        <td class="px-3 py-2" style="color: var(--text-primary);">{{ \Illuminate\Support\Carbon::parse($r['when'])->format('j M Y') }}</td>
                        @if($kind === 'lost')<td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['reason'] }}</td>@endif
                        <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['now'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-10 text-center text-sm" style="color: var(--text-primary);" data-qa="list-empty">{{ $q !== '' ? 'Nothing matches this search.' : 'No properties for this figure in this period.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endunless
</div>
@endsection
