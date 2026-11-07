@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Auctions</h1>
                <p class="text-xs" style="color:var(--text-muted);">Create auctions, add their lots and publish them to your public auction page.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('corex.auctions.lots.index') }}" class="corex-btn-outline text-xs px-3 py-2">All lots</a>
                @permission('auctions.create')
                <a href="{{ route('corex.auctions.create') }}" class="corex-btn-primary" data-auction-new>+ New Auction</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>
    @endif

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search reference, title, venue…"
                   class="flex-1 min-w-[180px] max-w-xs px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\Auction::STATUSES as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select name="format" onchange="this.form.submit()" class="list-header-filter" title="Format">
                <option value="">All formats</option>
                @foreach(\App\Models\Auction::FORMATS as $k => $label)
                    <option value="{{ $k }}" @selected($filters['format'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
            @if($branches->count() > 1)
            <select name="branch_id" onchange="this.form.submit()" class="list-header-filter" title="Branch">
                <option value="">All branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected($filters['branchId'] === (string) $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
            @endif
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">From
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter">
                To
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter">
            </span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['starts_at' => 'Sort: Auction date', 'reference' => 'Sort: Reference', 'title' => 'Sort: Title', 'status' => 'Sort: Status', 'created_at' => 'Sort: Created'] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['sort'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / Z-A first</option>
                <option value="asc" @selected($filters['dir'] === 'asc')>Oldest / A-Z first</option>
            </select>
            <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-muted);">
                <input type="checkbox" name="archived" value="1" onchange="this.form.submit()" @checked($filters['archived'])> Archived
            </label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($auctions->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if($filters['search'] !== '' || $filters['status'] !== '' || $filters['format'] !== '' || $filters['archived'] || $filters['dateFrom'] !== '' || $filters['dateTo'] !== '')
                No auctions match these filters. <a href="{{ route('corex.auctions.index') }}" class="underline">Clear them</a>
            @else
                No auctions yet. @permission('auctions.create')<a href="{{ route('corex.auctions.create') }}" class="underline">Create your first auction.</a>@endpermission
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
            <table class="min-w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2);">
                        @foreach(['Reference', 'Title', 'Date', 'Format', 'Venue', 'Lots', 'Status', ''] as $h)
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach($auctions as $a)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-2.5">{{ $a->reference }}</td>
                        <td class="px-4 py-2.5">{{ $a->title }}@if($a->is_single_property) <span class="text-[11px]" style="color:var(--text-muted);">(one property)</span>@endif</td>
                        <td class="px-4 py-2.5">{{ $a->starts_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ \App\Models\Auction::FORMATS[$a->format] ?? $a->format }}</td>
                        <td class="px-4 py-2.5">{{ $a->venue_name ?: ($a->format === 'timed' ? 'Online' : '—') }}</td>
                        <td class="px-4 py-2.5">{{ $a->live_lots_count }}</td>
                        <td class="px-4 py-2.5">{{ $a->statusLabel() }}</td>
                        <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.edit', $a->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $auctions->links() }}</div>
    @endif
</div>
@endsection
