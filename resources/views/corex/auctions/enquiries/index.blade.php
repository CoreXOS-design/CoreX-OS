@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Enquiries</h1>
        <p class="text-xs" style="color:var(--text-muted);">Every enquiry sent from your public auction page. {{ $newCount }} {{ $newCount === 1 ? 'is' : 'are' }} new.</p>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">{{ $errors->first() }}</div>@endif

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.enquiries.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search name, email, phone, message, lot…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\AuctionEnquiry::STATUSES as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select name="auction_id" onchange="this.form.submit()" class="list-header-filter" title="Auction">
                <option value="">All auctions</option>
                @foreach($auctions as $a)<option value="{{ $a->id }}" @selected($filters['auctionId'] === (string) $a->id)>{{ $a->reference }} — {{ $a->title }}</option>@endforeach
            </select>
            <select name="assigned_user_id" onchange="this.form.submit()" class="list-header-filter" title="Assigned to">
                <option value="">Everyone</option>
                <option value="none" @selected($filters['assignedId'] === 'none')>Unassigned</option>
                @foreach($agents as $u)<option value="{{ $u->id }}" @selected($filters['assignedId'] === (string) $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Received from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['created_at' => 'Sort: Received', 'name' => 'Sort: Name', 'status' => 'Sort: Status', 'auction' => 'Sort: Auction'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / Z-A first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / A-Z first</option>
            </select>
            <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-muted);"><input type="checkbox" name="archived" value="1" onchange="this.form.submit()" @checked($filters['archived'])> Archived</label>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.enquiries.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($enquiries->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">
            @if(collect($filters)->only(['search', 'status', 'auctionId', 'assignedId', 'dateFrom', 'dateTo'])->filter()->isNotEmpty() || $filters['archived'])
                No enquiries match these filters. <a href="{{ route('corex.auctions.enquiries.index') }}" class="underline">Clear them</a>
            @else
                No enquiries yet. They appear here as soon as a buyer sends one from your public auction page.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Received', 'From', 'About', 'Message', 'Assigned to', 'Status', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($enquiries as $e)
                <tr style="border-top:1px solid var(--border);vertical-align:top;" data-enquiry="{{ $e->id }}">
                    <td class="px-4 py-2.5 whitespace-nowrap text-xs">{{ $e->created_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">
                        <div class="font-semibold">@if($e->contact_id)<a href="{{ route('corex.contacts.show', $e->contact_id) }}" class="underline">{{ $e->name }}</a>@else{{ $e->name }}@endif</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $e->email }}@if($e->email && $e->phone) · @endif{{ $e->phone }}</div>
                        @if($e->client_user_id)<span class="text-[11px]" style="color:var(--text-muted);">Has an account</span>@endif
                    </td>
                    <td class="px-4 py-2.5 text-xs">
                        @if($e->lot)Lot {{ $e->lot->lot_number }} — {{ $e->lot->displayTitle() }}<br>@endif
                        @if($e->auction)<a href="{{ route('corex.auctions.edit', $e->auction_id) }}" class="underline">{{ $e->auction->reference }}</a>@endif
                    </td>
                    <td class="px-4 py-2.5 text-xs" style="max-width:280px;white-space:pre-line;">{{ \Illuminate\Support\Str::limit((string) $e->message, 220) }}</td>
                    @if($e->trashed())
                    <td class="px-4 py-2.5 text-xs">{{ $e->assignee?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs">Archived</td>
                    <td class="px-4 py-2.5">@permission('auctions.enquiries.manage')<form method="POST" action="{{ route('corex.auctions.enquiries.restore', $e->id) }}">@csrf<button class="corex-btn-outline text-xs">Restore</button></form>@endpermission</td>
                    @else
                    <td class="px-4 py-2.5">
                        @permission('auctions.enquiries.manage')
                        <form method="POST" action="{{ route('corex.auctions.enquiries.update', $e->id) }}">@csrf @method('PUT')
                            <select name="assigned_user_id" onchange="this.form.submit()" class="list-header-filter" title="Assign">
                                <option value="">Unassigned</option>
                                @foreach($agents as $u)<option value="{{ $u->id }}" @selected((int) $e->assigned_user_id === $u->id)>{{ $u->name }}</option>@endforeach
                            </select>
                        </form>
                        @else{{ $e->assignee?->name ?? '—' }}@endpermission
                    </td>
                    <td class="px-4 py-2.5">
                        @permission('auctions.enquiries.manage')
                        <form method="POST" action="{{ route('corex.auctions.enquiries.update', $e->id) }}">@csrf @method('PUT')
                            <select name="status" onchange="this.form.submit()" class="list-header-filter" title="Status">
                                @foreach(\App\Models\AuctionEnquiry::STATUSES as $s)<option value="{{ $s }}" @selected($e->status === $s)>{{ ucfirst($s) }}</option>@endforeach
                            </select>
                        </form>
                        @else{{ $e->statusLabel() }}@endpermission
                    </td>
                    <td class="px-4 py-2.5">@permission('auctions.enquiries.manage')<form method="POST" action="{{ route('corex.auctions.enquiries.archive', $e->id) }}" onsubmit="return confirm('Archive this enquiry?');">@csrf @method('DELETE')<button class="text-xs underline" style="color:var(--text-muted);">Archive</button></form>@endpermission</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $enquiries->links() }}</div>
    @endif
</div>
@endsection
