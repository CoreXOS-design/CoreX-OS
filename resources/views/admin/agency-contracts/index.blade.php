{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Agency Contracts list (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $sortLink = fn ($key, $label) => '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc', 'page' => null])) . '" style="color:inherit;">' . e($label) . ($sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a>';
    $badge = ['signed' => 'ds-badge-success', 'declined' => 'ds-badge-danger', 'voided' => 'ds-badge-default', 'expired' => 'ds-badge-warning', 'sent' => 'ds-badge-info', 'viewed' => 'ds-badge-info', 'draft' => 'ds-badge-default'];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Agency Contracts</h1>
                <p class="text-xs" style="color: var(--text-muted);">CoreX's own contracts, sent to agencies for electronic signature. Not visible to any agency.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.agency-contracts.templates') }}" class="corex-btn-outline text-xs">Templates</a>
                <a href="{{ route('admin.agency-contracts.create') }}" class="corex-btn-primary text-xs">+ Send contract</a>
            </div>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);">
        <div><label class="ds-label block mb-1">Search</label><input type="text" name="q" value="{{ request('q') }}" placeholder="Agency, signatory, title…" class="ds-field" style="min-width:14rem;"></div>
        <div><label class="ds-label block mb-1">Status</label>
            <select name="status" class="ds-field"><option value="">All</option>@foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
        <div><label class="ds-label block mb-1">Agency</label>
            <select name="agency_id" class="ds-field"><option value="">All</option>@foreach($agencies as $a)<option value="{{ $a->id }}" @selected((string) request('agency_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach</select></div>
        <div><label class="ds-label block mb-1">Sent from</label><input type="date" name="from" value="{{ request('from') }}" class="ds-field"></div>
        <div><label class="ds-label block mb-1">Sent to</label><input type="date" name="to" value="{{ request('to') }}" class="ds-field"></div>
        <label class="flex items-center gap-2 text-xs pb-2"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Show archived</label>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <button class="corex-btn-primary text-xs" type="submit">Filter</button>
        @if(request()->hasAny(['q','status','agency_id','from','to','archived']))<a href="{{ route('admin.agency-contracts.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wider">
                    <th class="px-4 py-3">{!! $sortLink('agency', 'Agency') !!}</th><th class="px-4 py-3">Contract</th><th class="px-4 py-3">Signatory</th>
                    <th class="px-4 py-3">{!! $sortLink('status', 'Status') !!}</th><th class="px-4 py-3">{!! $sortLink('sent', 'Sent') !!}</th>
                    <th class="px-4 py-3">Last viewed</th><th class="px-4 py-3">{!! $sortLink('signed', 'Signed') !!}</th><th class="px-4 py-3 text-right">Actions</th>
                </tr></thead>
                <tbody>
                @forelse($envelopes as $e)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $e->agency?->name ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $e->title }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $e->signatory_name }}<span class="block text-xs" style="color:var(--text-muted);">{{ $e->signatory_email }}</span></td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $badge[$e->status] ?? 'ds-badge-default' }}">{{ ucfirst($e->status) }}</span></td>
                        <td class="px-4 py-3 tabular-nums" style="color:var(--text-secondary);">{{ $e->sent_at?->format('j M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums" style="color:var(--text-secondary);">{{ $e->first_viewed_at?->format('j M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums" style="color:var(--text-secondary);">{{ $e->signed_at?->format('j M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.agency-contracts.show', $e->id) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                            @if($e->deleted_at)
                                <form method="POST" action="{{ route('admin.agency-contracts.restore', $e->id) }}" class="inline">@csrf<button class="text-xs font-semibold ml-3" style="color:var(--ds-green);">Restore</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center" style="color:var(--text-muted);">No contracts {{ request()->hasAny(['q','status','agency_id','from','to','archived']) ? 'match these filters' : 'sent yet' }}. <a href="{{ route('admin.agency-contracts.create') }}" class="underline">Send the first one</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $envelopes->links() }}
</div>
@endsection
