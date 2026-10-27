@extends('layouts.corex')

{{-- AT-445; AT-495: the notice, who it reached (per person: emailed / NOT sent - no email address / the email failed), download, resend, archive. --}}
@section('corex-content')
@php
    $tone = fn (string $st) => match ($st) { 'sent' => '--ds-green', 'failed' => '--ds-crimson', default => '--ds-amber' };
@endphp
<div class="w-full space-y-5">
<div class="max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold">{{ \App\Models\RentalNoticeTemplate::labelFor($notice->notice_type) }}{{ $notice->trashed() ? ' (archived)' : '' }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if($notice->document)
                <a href="{{ route('corex.rental-notices.download-document', $notice) }}" class="corex-btn-outline text-xs" data-qa="notice-download">Download document</a>
            @endif
            @permission('rental_notices.create')
                @if(! $notice->trashed() && $notice->isResendable())
                    <form method="POST" action="{{ route('corex.rental-notices.resend', $notice) }}" class="inline" data-confirm="Email this notice again to the people it was addressed to?" data-confirm-label="Resend">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs" data-qa="notice-resend">Resend</button>
                    </form>
                @endif
                @if($notice->trashed())
                    <form method="POST" action="{{ route('corex.rental-notices.restore', $notice->id) }}" class="inline">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                    </form>
                @elseif($notice->lease_breach_id === null)
                    <form method="POST" action="{{ route('corex.rental-notices.archive', $notice) }}" class="inline" data-confirm="Archive this notice? It is hidden from this list; nothing is deleted and Restore brings it back." data-confirm-danger data-confirm-label="Archive">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs" style="color: #991b1b;">Archive</button>
                    </form>
                @endif
            @endpermission
        </div>
    </div>
    @include('corex.notices._tabs', ['active' => 'sent'])
    <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex justify-between"><span style="color:var(--text-muted);">Property</span><strong>{{ $notice->lease?->property?->buildDisplayAddress() }}{{ $notice->lease?->property?->trashed() ? ' (archived)' : '' }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Sent</span><strong>{{ $notice->sent_at?->format('d M Y H:i') }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Sent by</span><strong>{{ $notice->sentByUser?->name }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Addressed to</span><strong>
            @if($notice->sent_to_tenant) Tenant @endif
            @if($notice->sent_to_landlord) Landlord @endif
        </strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Delivery</span><strong data-qa="notice-delivery-summary">{{ $notice->deliveryLabel() }}</strong></div>
    </div>

    {{-- AT-495 - who it really reached. A person with no email address is shown as NOT sent, never as sent. --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-deliveries">
        <div class="px-4 py-3 text-sm font-semibold" style="border-bottom: 1px solid var(--border);">Who it was emailed to</div>
        @if($notice->deliveries->isEmpty())
            <p class="px-4 py-4 text-sm" style="color: var(--text-muted);">
                @if($notice->lease_breach_id)
                    This is a breach notice - the people it went to are shown on its breach screen.
                @else
                    Nothing was written down about who this notice was emailed to (it was sent before deliveries were recorded).
                @endif
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted);">
                        <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wide">Person</th>
                        <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wide">Email</th>
                        <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wide">What happened</th>
                        <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wide">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($notice->deliveries as $d)
                        <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);" data-delivery="{{ $d->status }}">
                            <td class="px-4 py-2">{{ $d->recipient_name }} <span class="text-xs" style="color: var(--text-muted);">({{ $d->recipient_role }})</span></td>
                            <td class="px-4 py-2">{{ $d->recipient_email ?: '—' }}</td>
                            <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap" style="background:color-mix(in srgb, var({{ $tone($d->status) }}) 12%, transparent); color:var({{ $tone($d->status) }}); border:1px solid color-mix(in srgb, var({{ $tone($d->status) }}) 25%, transparent);">{{ $d->statusLabel() }}</span>@if($d->error)<div class="text-xs mt-1" style="color: var(--text-muted);">{{ $d->error }}</div>@endif</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $d->attempted_at?->format('d M Y H:i') }}@if($d->attemptedBy)<div class="text-xs" style="color: var(--text-muted);">{{ $d->attemptedBy->name }}</div>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- A soft-deleted lease has no reachable Lease Hub (default route-model binding 404s on it) —
         never build a dead-end link; the lease relation is withTrashed() so this can be non-null but archived. --}}
    @if($notice->lease && !$notice->lease->trashed())
        @feature('rental-leases')
        <a href="{{ route('corex.leases.show', $notice->lease) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">&larr; Back to Lease Hub</a>
        @endfeature
    @elseif($notice->lease)
        <span class="text-xs" style="color: var(--text-muted);">Lease #{{ $notice->lease->id }} (archived) — no Lease Hub to return to.</span>
    @endif
</div>
</div>
@endsection
