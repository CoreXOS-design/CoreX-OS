@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-7xl mx-auto space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Sold stock</h1>
        <p class="text-sm text-white/60">Every sale your agency has completed, frozen as it was advertised. This is what your website's Sold showcase shows.</p>
    </div>

    <div class="mt-3">
        @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\PropertiesTabs::class])
    </div>

    @if (session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:rgba(16,185,129,0.12);color:#10b981;border:1px solid rgba(16,185,129,0.3);">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.sold-stock.index') }}" class="rounded-md p-4 grid gap-3 md:grid-cols-6" style="background:var(--surface);border:1px solid var(--border);">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search address, suburb, title, agent" class="md:col-span-2 rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
        <select name="period" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
            <option value="">Any time</option>
            <option value="30" @selected(($filters['period'] ?? '') === '30')>Last 30 days</option>
            <option value="90" @selected(($filters['period'] ?? '') === '90')>Last 90 days</option>
            <option value="year" @selected(($filters['period'] ?? '') === 'year')>This year</option>
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" title="Sold from" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" title="Sold to" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
        <select name="agent_id" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
            <option value="">All agents</option>
            @foreach ($agents as $a)<option value="{{ $a->id }}" @selected((string) ($filters['agent_id'] ?? '') === (string) $a->id)>{{ $a->name }}</option>@endforeach
        </select>
        <select name="suburb" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
            <option value="">All suburbs</option>
            @foreach ($suburbs as $s)<option value="{{ $s }}" @selected(($filters['suburb'] ?? '') === $s)>{{ $s }}</option>@endforeach
        </select>
        @if ($showBranch)
        <select name="branch_id" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
            <option value="">All branches</option>
            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>@endforeach
        </select>
        @endif
        <div class="flex items-center gap-2 md:col-span-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Filter</button>
            <a href="{{ route('corex.sold-stock.index') }}" class="text-sm" style="color:var(--text-muted);">Clear</a>
            @permission('properties.archive')
                <a href="{{ route('corex.sold-stock.index', $archived ? [] : ['archived' => 1]) }}" class="text-sm ml-auto" style="color:var(--text-muted);">{{ $archived ? 'Back to sold stock' : 'Archived sales' }}</a>
            @endpermission
        </div>
    </form>

    @php
        $head = function (string $col, string $label) use ($sort, $dir, $filters, $archived) {
            $next = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            $arrow = $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
            $url = route('corex.sold-stock.index', array_filter(array_merge($filters, ['sort' => $col, 'dir' => $next, 'archived' => $archived ? 1 : null])));
            return '<a href="' . e($url) . '" style="color:inherit;">' . e($label) . $arrow . '</a>';
        };
    @endphp

    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left" style="color:var(--text-muted);border-bottom:1px solid var(--border);">
                    <th class="px-4 py-3">{!! $head('address', 'Address') !!}</th>
                    <th class="px-4 py-3">{!! $head('suburb', 'Suburb') !!}</th>
                    <th class="px-4 py-3 text-right">{!! $head('sale_price', 'Sold price') !!}</th>
                    <th class="px-4 py-3">{!! $head('sale_date', 'Sale date') !!}</th>
                    <th class="px-4 py-3">Agent</th>
                    <th class="px-4 py-3 text-right">{!! $head('days_on_market', 'Days on market') !!}</th>
                    @if ($showBranch)<th class="px-4 py-3">Branch</th>@endif
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr style="border-bottom:1px solid var(--border);">
                    <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">
                        <a href="{{ route('corex.sold-stock.show', $r->id) }}">{{ $r->address ?: $r->title }}</a>
                        @if ($r->cycle_no > 1)<span class="text-xs ml-1" style="color:var(--text-muted);">(sale {{ $r->cycle_no }})</span>@endif
                    </td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $r->suburb }}</td>
                    <td class="px-4 py-3 text-right">{{ $r->sale_price !== null ? 'R ' . number_format((float) $r->sale_price, 0, '.', ',') : '—' }}</td>
                    <td class="px-4 py-3">{{ optional($r->sale_date)->format('j M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $r->agent->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">{{ $r->days_on_market ?? '—' }}</td>
                    @if ($showBranch)<td class="px-4 py-3">{{ $branches->firstWhere('id', $r->branch_id)->name ?? '—' }}</td>@endif
                    <td class="px-4 py-3 text-right">
                        @if ($archived)
                            <form method="POST" action="{{ route('corex.sold-stock.restore', $r->id) }}">@csrf<button class="text-xs" style="color:var(--brand-button,#0ea5e9);">Restore</button></form>
                        @else
                            <a href="{{ route('corex.sold-stock.show', $r->id) }}" class="text-xs" style="color:var(--brand-button,#0ea5e9);">Open</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $showBranch ? 8 : 7 }}" class="px-4 py-10 text-center" style="color:var(--text-muted);">
                    {{ $archived ? 'No archived sales.' : 'No sales match. A sale appears here the moment a property is marked Sold or its deal registers.' }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
