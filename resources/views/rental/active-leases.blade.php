{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@push('head')
<style>
    .lease-row-card { background: var(--surface); transition: background 300ms ease, box-shadow 300ms ease; }
    .lease-row-card:hover { background: var(--surface-2); }
</style>
@endpush

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div data-tour="rent-active-leases-intro" class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Active Leases</h1>
                <p class="text-sm text-white/60">
                    <a href="{{ route('rental.dashboard') }}" class="text-white/60 hover:text-white transition-all duration-300">&larr; Rentals</a>
                    &middot; Completed and signed lease agreements.
                </p>
            </div>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('docuperfect.rental.uploadAndSend') }}" data-tour="rent-active-leases-upload" class="corex-btn-primary">Upload &amp; Send Lease</a>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=feature-rentals') }}"
                   title="Active Leases Settings"
                   aria-label="Active Leases Settings"
                   class="inline-flex items-center justify-center rounded-md text-white transition-colors"
                   style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    <div data-tour="rent-active-leases-list" class="space-y-3">
        @forelse($leases as $lease)
            @php
                $daysLeft = $lease->daysUntilExpiry();
                $rental = number_format((float) $lease->rental_amount, 0, '.', ',');
                $badgeVariant = match(true) {
                    $daysLeft <= 0  => 'ds-badge-danger',
                    $daysLeft <= 90 => 'ds-badge-warning',
                    default         => 'ds-badge-success',
                };
                $borderAccent = match(true) {
                    $daysLeft <= 0  => 'var(--ds-crimson)',
                    $daysLeft <= 90 => 'var(--ds-amber)',
                    default         => 'var(--ds-green)',
                };
            @endphp
            <div class="lease-row-card rounded-md p-5"
                 style="border: 1px solid var(--border); border-left: 3px solid {{ $borderAccent }};">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold" style="color: var(--text-primary);">{{ $lease->property_address ?: ($lease->document->name ?? 'Unnamed') }}</div>
                        <div class="text-xs mt-2 space-y-1" style="color: var(--text-secondary);">
                            <p><span class="font-medium" style="color: var(--text-primary);">Tenant:</span> {{ $lease->tenant_name ?? '—' }}</p>
                            <p><span class="font-medium" style="color: var(--text-primary);">Landlord:</span> {{ $lease->landlord_name ?? '—' }}</p>
                            @if($lease->rental_amount)
                            <p><span class="font-medium" style="color: var(--text-primary);">Rental:</span> R {{ $rental }}/mo</p>
                            @endif
                            @if($lease->lease_start_date)
                            <p><span class="font-medium" style="color: var(--text-primary);">Start:</span> {{ $lease->lease_start_date->format('d M Y') }}</p>
                            @endif
                        </div>
                        @if($lease->lease_end_date)
                        <div class="mt-3">
                            <span class="ds-badge {{ $badgeVariant }}"
                                  @if($daysLeft > 0 && $daysLeft <= 90) title="{{ $daysLeft }} days until lease expiry" @endif>
                                @if($daysLeft <= 0)
                                    Expired
                                @elseif($daysLeft <= 90)
                                    {{ $daysLeft }}d left
                                @else
                                    Active
                                @endif
                            </span>
                            <span class="text-[0.6875rem] ml-2" style="color: var(--text-muted);">
                                @if($daysLeft <= 0)
                                    {{ $lease->lease_end_date->format('d M Y') }}
                                @else
                                    Expires {{ $lease->lease_end_date->format('d M Y') }}
                                @endif
                            </span>
                        </div>
                        @endif
                        @if($lease->signatureTemplate && $lease->signatureTemplate->completed_at)
                            <div class="text-[0.6875rem] mt-1.5" style="color: var(--ds-green);">
                                Signed {{ $lease->signatureTemplate->completed_at->format('d M Y') }}
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2 ml-4 shrink-0">
                        @if($lease->document)
                            <a href="{{ route('docuperfect.signatures.audit', $lease->document) }}"
                               class="corex-btn-outline text-xs px-3 py-1.5 text-center">
                                Audit
                            </a>
                        @endif
                        @if($lease->signatureTemplate && $lease->signatureTemplate->signed_pdf_path)
                            <a href="{{ route('docuperfect.signatures.download', $lease->document) }}"
                               class="corex-btn-outline text-xs px-3 py-1.5 text-center">
                                Download PDF
                            </a>
                        @endif
                        <a href="{{ route('docuperfect.leases.history', $lease) }}"
                           class="corex-btn-outline text-xs px-3 py-1.5 text-center">
                            History
                        </a>
                        <form method="POST" action="{{ route('docuperfect.leases.renew', $lease) }}" class="inline">
                            @csrf
                            <button type="submit" class="corex-btn-primary w-full text-xs px-3 py-1.5 text-center"
                                    onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">
                                Renew Lease
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active leases yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Completed and approved lease agreements will appear here.</p>
                <a href="{{ route('docuperfect.rental.uploadAndSend') }}" class="corex-btn-primary">Upload &amp; Send Lease</a>
            </div>
        @endforelse
    </div>

</div>
@endsection
