{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign documents list (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-5 py-2.5 text-xs font-semibold uppercase tracking-wider';
    $sortLink = function ($key, $label) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
        $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next])) . '">' . e($label) . $arrow . '</a>';
    };
    $tone = ['awaiting_countersign' => 'orange', 'wetink_received' => 'orange', 'draft' => 'default', 'sent' => 'info', 'in_progress' => 'orange', 'completed' => 'success', 'declined' => 'default', 'voided' => 'default', 'expired' => 'default'];
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', [
        'title' => 'Documents', 'tab' => 'documents',
        'sub' => 'Every CoreX contract that has been sent, who has signed and who has not.',
        'actions' => '<a href="' . route('platform-esign.documents.create') . '" class="corex-btn-primary">Send a contract</a>',
    ])

    <form method="GET" class="rounded-md p-3 flex flex-col flex-wrap lg:flex-row gap-2 lg:items-center" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="text" name="q" value="{{ $q }}" placeholder="Search title, agency, signer name or email…" class="ds-field flex-1">
        <select name="status" class="list-header-filter" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach(\App\Models\PlatformEsign\Document::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
            <option value="archived" @selected($status === 'archived')>Archived</option>
        </select>
        <label class="flex items-center gap-1 text-xs" style="color: var(--text-muted);">Sent from <input type="date" name="from" value="{{ $from }}" class="ds-field"></label>
        <label class="flex items-center gap-1 text-xs" style="color: var(--text-muted);">to <input type="date" name="to" value="{{ $to }}" class="ds-field"></label>
        <button class="corex-btn-primary">Search</button>
        @if($q !== '' || $status !== '' || $from || $to)<a href="{{ route('platform-esign.documents.index') }}" class="corex-btn-outline">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('title', 'Document') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Signers</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('status', 'Status') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('sent', 'Sent') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('completed', 'Signed') !!}</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);"></th>
                </tr></thead>
                <tbody>
                @forelse($docs as $d)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-5 py-4">
                            <a href="{{ route('platform-esign.documents.show', $d->id) }}" class="font-semibold" style="color: var(--text-primary);">{{ $d->title }}</a>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $d->agency?->name ?? 'No agency' }}</div>
                        </td>
                        <td class="px-5 py-4 text-xs" style="color: var(--text-secondary);">
                            @foreach($d->signers as $s)<div>{!! $s->status === 'signed' ? '<span style="color: var(--ds-green);">✓</span>' : '<span style="color: var(--text-muted);">○</span>' !!} {{ $s->name }} <span style="color: var(--text-muted);">({{ $s->role_label }})</span></div>@endforeach
                        </td>
                        <td class="px-5 py-4"><span class="ds-badge ds-badge-{{ $tone[$d->status] ?? 'default' }}">{{ $d->statusLabel() }}</span>@if($d->trashed()) <span class="ds-badge ds-badge-default">Archived</span>@endif</td>
                        <td class="px-5 py-4 whitespace-nowrap" style="color: var(--text-muted);">{{ $d->sent_at?->format('j M Y') ?? '—' }}</td>
                        <td class="px-5 py-4 whitespace-nowrap" style="color: var(--text-muted);">{{ $d->completed_at?->format('j M Y') ?? '—' }}</td>
                        <td class="px-5 py-4 text-right"><a href="{{ route('platform-esign.documents.show', $d->id) }}" class="corex-btn-outline corex-btn-xs">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">No documents match. <a href="{{ route('platform-esign.documents.create') }}" class="underline" style="color: var(--brand-icon);">Send a contract</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($docs->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $docs->links() }}</div>@endif
    </div>
    @include('platform-esign._end')
</div>
@endsection
