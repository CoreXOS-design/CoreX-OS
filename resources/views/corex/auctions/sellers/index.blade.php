@extends('layouts.corex')

@section('corex-content')
@php $money = fn ($v) => $v === null ? '—' : 'R ' . number_format((float) $v, 0, '.', ' '); @endphp
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Seller leads</h1>
        <p class="text-xs" style="color:var(--text-muted);">People who filled in "Sell at auction" on your public auction page. {{ $newCount }} {{ $newCount === 1 ? 'is' : 'are' }} new.</p>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.sellers.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search name, email, phone, address, suburb, notes…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\AuctionSellerSubmission::STATUSES as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select name="property_type" onchange="this.form.submit()" class="list-header-filter" title="Property type">
                <option value="">All property types</option>
                @foreach(\App\Models\AuctionSellerSubmission::PROPERTY_TYPES as $k => $l)<option value="{{ $k }}" @selected($filters['type'] === $k)>{{ $l }}</option>@endforeach
            </select>
            <select name="auction_format" onchange="this.form.submit()" class="list-header-filter" title="Auction format">
                <option value="">Any format</option>
                @foreach(\App\Models\AuctionSellerSubmission::FORMATS as $k => $l)<option value="{{ $k }}" @selected($filters['format'] === $k)>{{ $l }}</option>@endforeach
            </select>
            <select name="assigned" onchange="this.form.submit()" class="list-header-filter" title="Assigned to">
                <option value="">Anyone</option>
                <option value="me" @selected($filters['assigned'] === 'me')>Assigned to me</option>
                <option value="none" @selected($filters['assigned'] === 'none')>Unassigned</option>
                @foreach($agents as $u)<option value="{{ $u->id }}" @selected($filters['assigned'] === (string) $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Received from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['created_at' => 'Sort: Received', 'name' => 'Sort: Name', 'suburb' => 'Sort: Suburb', 'price_expectation' => 'Sort: Price hoped for', 'status' => 'Sort: Status'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / highest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / lowest first</option>
            </select>
            <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-muted);"><input type="checkbox" name="archived" value="1" onchange="this.form.submit()" @checked($filters['archived'])> Archived</label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.sellers.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($leads->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);" data-sellers-empty>
            @if(collect($filters)->only(['search', 'status', 'type', 'format', 'assigned', 'dateFrom', 'dateTo'])->filter()->isNotEmpty() || $filters['archived'])
                No seller leads match these filters. <a href="{{ route('corex.auctions.sellers.index') }}" class="underline">Clear them</a>
            @else
                No one has filled in "Sell at auction" yet. Leads appear here as soon as someone completes the questionnaire on your public auction page.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Seller', 'Property', 'Price hoped for', 'Format', 'Received', 'Status', 'Assigned to', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($leads as $l)
                <tr style="border-top:1px solid var(--border);" data-seller-row>
                    <td class="px-4 py-2.5"><div class="font-semibold">{{ $l->name }}</div><div class="text-xs" style="color:var(--text-muted);">{{ $l->email }} · {{ $l->phone }}</div></td>
                    <td class="px-4 py-2.5">{{ \App\Models\AuctionSellerSubmission::PROPERTY_TYPES[$l->property_type] ?? $l->property_type }}<div class="text-xs" style="color:var(--text-muted);">{{ $l->address }}, {{ $l->suburb }}</div></td>
                    <td class="px-4 py-2.5 whitespace-nowrap">{{ $money($l->price_expectation) }}</td>
                    <td class="px-4 py-2.5">{{ \App\Models\AuctionSellerSubmission::FORMATS[$l->auction_format] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $l->created_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $l->trashed() ? 'Archived' : $l->statusLabel() }}</td>
                    <td class="px-4 py-2.5">{{ $l->assignee?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.sellers.show', $l->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $leads->links() }}</div>
    @endif
</div>
@endsection
