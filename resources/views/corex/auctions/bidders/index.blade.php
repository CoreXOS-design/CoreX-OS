@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Bidders</h1>
        <p class="text-xs" style="color:var(--text-muted);">Buyers who registered to bid. Approve each one before they can bid. {{ $pendingCount }} {{ $pendingCount === 1 ? 'is' : 'are' }} waiting for approval.</p>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="flex flex-wrap gap-2 text-sm">
        @foreach($views as $key => $label)
            <a href="{{ route('corex.auctions.bidders.index', array_merge(request()->except('view', 'page'), ['view' => $key])) }}"
               class="px-3 py-1.5 rounded-md" style="border:1px solid var(--border);{{ $filters['view'] === $key ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);' }}" data-bidder-view="{{ $key }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.bidders.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="view" value="{{ $filters['view'] }}">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search name, company, email, bidder no., ID no…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\AuctionRegistration::STATUSES as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select name="bidder_type" onchange="this.form.submit()" class="list-header-filter" title="Bidder type">
                <option value="">Individuals &amp; companies</option>
                @foreach(\App\Models\AuctionRegistration::TYPES as $k => $l)<option value="{{ $k }}" @selected($filters['type'] === $k)>{{ $l }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Registered from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['created_at' => 'Sort: Registered', 'bidder_number' => 'Sort: Bidder no.', 'status' => 'Sort: Status', 'auction' => 'Sort: Auction'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest first</option>
            </select>
            <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-muted);"><input type="checkbox" name="archived" value="1" onchange="this.form.submit()" @checked($filters['archived'])> Withdrawn / archived</label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.bidders.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($registrations->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'auctionId', 'status', 'type', 'dateFrom', 'dateTo'])->filter()->isNotEmpty() || $filters['archived'] || $filters['view'] !== 'all')
                No bidders match these filters. <a href="{{ route('corex.auctions.bidders.index') }}" class="underline">Clear them</a>
            @else
                No one has registered to bid yet. Registrations appear here as soon as a buyer registers on your public auction page.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['No.', 'Bidder', 'Type', 'Auction', 'Registered', 'Bids', 'Status', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($registrations as $r)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5 font-semibold">{{ $r->bidder_number ?? '—' }}</td>
                    <td class="px-4 py-2.5">{{ $r->displayName() }}<div class="text-xs" style="color:var(--text-muted);">{{ $r->clientUser?->email }}</div></td>
                    <td class="px-4 py-2.5">{{ \App\Models\AuctionRegistration::TYPES[$r->bidder_type] ?? $r->bidder_type }}</td>
                    <td class="px-4 py-2.5">{{ $r->auction?->reference }}<div class="text-xs" style="color:var(--text-muted);">{{ $r->auction?->title }}</div></td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $r->created_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $r->bids_count }}</td>
                    <td class="px-4 py-2.5">{{ $r->trashed() ? 'Withdrawn' : $r->statusLabel() }}</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.bidders.show', $r->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $registrations->links() }}</div>
    @endif
</div>
@endsection
