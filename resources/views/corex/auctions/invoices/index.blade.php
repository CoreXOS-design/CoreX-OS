@extends('layouts.corex')

@section('corex-content')
@php $money = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ' '); @endphp
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Invoices</h1>
        <p class="text-xs" style="color:var(--text-muted);">What each winning bidder owes: the price, the buyer's commission and VAT, the deposit and the balance.</p>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm" data-invoice-stats>
        @foreach([['Still owing', $money($owing)], ['Overdue', $overdueCount], ['Drafts to issue', $draftCount]] as [$l, $v])
        <div class="rounded-md px-4 py-3" style="background:var(--surface);border:1px solid var(--border);"><div class="text-xs" style="color:var(--text-muted);">{{ $l }}</div><div class="font-bold" style="color:var(--text-primary);">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.invoices.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search invoice no., bidder, lot, property…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\AuctionInvoice::STATUSES as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
            <select name="only" onchange="this.form.submit()" class="list-header-filter" title="Money owing">
                <option value="">Any</option>
                <option value="outstanding" @selected($filters['only'] === 'outstanding')>Still owing</option>
                <option value="overdue" @selected($filters['only'] === 'overdue')>Overdue</option>
            </select>
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Created from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['created_at' => 'Sort: Created', 'number' => 'Sort: Number', 'total_amount' => 'Sort: Total', 'status' => 'Sort: Status', 'balance_due_at' => 'Sort: Balance due'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / highest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / lowest first</option>
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.invoices.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($invoices->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'status', 'auctionId', 'dateFrom', 'dateTo', 'only'])->filter()->isNotEmpty())
                No invoices match these filters. <a href="{{ route('corex.auctions.invoices.index') }}" class="underline">Clear them</a>
            @else
                No invoices yet. A draft invoice appears here as soon as a lot is sold.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Invoice', 'Bidder', 'Lot', 'Total', 'Owing', 'Deposit due', 'Balance due', 'Status', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($invoices as $i)
                @php $overdue = $i->isOverdue(); @endphp
                <tr style="border-top:1px solid var(--border);{{ $i->status === 'cancelled' ? 'opacity:.55;' : '' }}" data-invoice="{{ $i->id }}">
                    <td class="px-4 py-2.5 font-semibold">{{ $i->number ?: 'Draft' }}<div class="text-xs font-normal" style="color:var(--text-muted);">{{ $i->created_at->format('d M Y') }}</div></td>
                    <td class="px-4 py-2.5">#{{ $i->registration?->bidder_number }} {{ $i->registration?->displayName() }}</td>
                    <td class="px-4 py-2.5 text-xs">Lot {{ $i->lot?->lot_number }} — {{ $i->lot?->property?->buildDisplayAddress() ?: $i->lot?->displayTitle() }}<div style="color:var(--text-muted);">{{ $i->auction?->reference }}</div></td>
                    <td class="px-4 py-2.5 whitespace-nowrap">{{ $money($i->total_amount) }}</td>
                    <td class="px-4 py-2.5 whitespace-nowrap">{{ in_array($i->status, ['issued', 'part_paid']) ? $money($i->outstanding()) : '—' }}</td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $i->deposit_due_at?->format('d M Y') }}</td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $i->balance_due_at?->format('d M Y') }}</td>
                    <td class="px-4 py-2.5">{{ $i->statusLabel() }}@if($overdue) <span class="text-xs font-semibold" style="color:#ef4444;">· overdue</span>@endif</td>
                    <td class="px-4 py-2.5"><a href="{{ route('corex.auctions.invoices.show', $i->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
