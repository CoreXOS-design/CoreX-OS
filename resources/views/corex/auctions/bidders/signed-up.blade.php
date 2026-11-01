@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Bidders</h1>
        <p class="text-xs" style="color:var(--text-muted);">Everyone who has signed up on your public auction page, including people who have not registered to bid yet. {{ number_format($people->total()) }} {{ $people->total() === 1 ? 'person' : 'people' }} shown.</p>
    </div>

    @include('corex.auctions.bidders._tabs', ['active' => 'signed_up'])

    <div class="rounded-md px-4 py-3 flex-shrink-0" style="background:var(--surface);border:1px solid var(--border);">
        <form method="GET" action="{{ route('corex.auctions.bidders.signed-up') }}" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search name, email, phone…"
                   class="flex-1 min-w-[200px] max-w-sm px-3 py-2 text-sm rounded-md" style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
            <select name="registered" onchange="this.form.submit()" class="list-header-filter" title="Registered to bid">
                <option value="">Registered and not yet</option>
                <option value="yes" @selected($filters['registered'] === 'yes')>Registered for an auction</option>
                <option value="no" @selected($filters['registered'] === 'no')>Not registered yet</option>
            </select>
            <span class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">Signed up from
                <input type="date" name="date_from" value="{{ $filters['dateFrom'] }}" onchange="this.form.submit()" class="list-header-filter"> to
                <input type="date" name="date_to" value="{{ $filters['dateTo'] }}" onchange="this.form.submit()" class="list-header-filter"></span>
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" title="Sort by">
                @foreach(['signed_up_at' => 'Sort: Signed up', 'contact_name' => 'Sort: Name', 'last_login_at' => 'Sort: Last seen', 'registered_count' => 'Sort: Registered for'] as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="dir" onchange="this.form.submit()" class="list-header-filter" title="Direction">
                <option value="desc" @selected($filters['dir'] === 'desc')>Newest / highest first</option><option value="asc" @selected($filters['dir'] === 'asc')>Oldest / lowest first</option>
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            <a href="{{ route('corex.auctions.bidders.signed-up') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        </form>
    </div>

    @if($people->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);" data-signed-up-empty>
            @if(collect($filters)->only(['search', 'registered', 'dateFrom', 'dateTo'])->filter()->isNotEmpty())
                No one matches these filters. <a href="{{ route('corex.auctions.bidders.signed-up') }}" class="underline">Clear them</a>
            @else
                No one has signed up on your auction page yet. People appear here as soon as they create an account on your public auction page.
            @endif
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                @foreach(['Name', 'Email', 'Phone', 'Signed up', 'Last seen', 'Registered for', ''] as $h)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach($people as $p)
                <tr style="border-top:1px solid var(--border);" data-signed-up-row>
                    <td class="px-4 py-2.5 font-semibold">{{ $p->contact_name !== null && $p->contact_name !== '' ? $p->contact_name : '—' }}</td>
                    <td class="px-4 py-2.5">{{ $p->email }}</td>
                    <td class="px-4 py-2.5">{{ $p->contact_phone ?: '—' }}</td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($p->signed_up_at)->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5 text-xs whitespace-nowrap">{{ $p->last_login_at ? $p->last_login_at->format('d M Y H:i') : '—' }}</td>
                    <td class="px-4 py-2.5">{{ (int) $p->registered_count > 0 ? (int) $p->registered_count . ((int) $p->registered_count === 1 ? ' auction' : ' auctions') : 'Not registered yet' }}</td>
                    <td class="px-4 py-2.5">@if($p->contact_id)<a href="{{ route('corex.contacts.show', $p->contact_id) }}" class="corex-btn-outline text-xs">Open contact</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div>{{ $people->links() }}</div>
    @endif
</div>
@endsection
