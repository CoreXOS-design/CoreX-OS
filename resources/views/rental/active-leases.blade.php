@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Active Leases</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.dashboard') }}" class="text-white/60 hover:text-white">&larr; Rentals</a>
                &middot; Completed and signed lease agreements.
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-3">
        @forelse($leases as $lease)
            @php
                $daysLeft = $lease->daysUntilExpiry();
                $rental = number_format((float) $lease->rental_amount, 0, '.', ' ');
                $borderClass = match(true) {
                    $daysLeft <= 0  => 'border-red-300 bg-red-50',
                    $daysLeft <= 30 => 'border-red-200 bg-red-50',
                    $daysLeft <= 90 => 'border-amber-200 bg-amber-50/50',
                    default         => 'border-green-200 bg-green-50/50',
                };
                $urgencyBadge = match(true) {
                    $daysLeft <= 0  => 'bg-red-100 text-red-800',
                    $daysLeft <= 30 => 'bg-red-100 text-red-700',
                    $daysLeft <= 90 => 'bg-amber-100 text-amber-700',
                    default         => 'bg-green-100 text-green-700',
                };
            @endphp
            <div class="rounded-2xl border {{ $borderClass }} p-5">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="font-semibold text-slate-800">{{ $lease->property_address ?: ($lease->document->name ?? 'Unnamed') }}</div>
                        <div class="text-xs text-slate-600 mt-1.5 space-y-0.5">
                            <p><span class="font-medium">Tenant:</span> {{ $lease->tenant_name ?? '—' }}</p>
                            <p><span class="font-medium">Landlord:</span> {{ $lease->landlord_name ?? '—' }}</p>
                            @if($lease->rental_amount)
                            <p><span class="font-medium">Rental:</span> R {{ $rental }}/mo</p>
                            @endif
                            @if($lease->lease_start_date)
                            <p><span class="font-medium">Start:</span> {{ $lease->lease_start_date->format('d M Y') }}</p>
                            @endif
                        </div>
                        @if($lease->lease_end_date)
                        <div class="mt-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $urgencyBadge }}">
                                @if($daysLeft <= 0)
                                    EXPIRED — {{ $lease->lease_end_date->format('d M Y') }}
                                @else
                                    Expires {{ $lease->lease_end_date->format('d M Y') }} ({{ $daysLeft }} days)
                                @endif
                            </span>
                        </div>
                        @endif
                        @if($lease->signatureTemplate && $lease->signatureTemplate->completed_at)
                            <div class="text-[10px] text-green-600 mt-1">
                                Signed {{ $lease->signatureTemplate->completed_at->format('d M Y') }}
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1.5 ml-4">
                        @if($lease->document)
                            <a href="{{ route('docuperfect.signatures.audit', $lease->document) }}"
                               class="text-xs px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-center">
                                Audit
                            </a>
                        @endif
                        @if($lease->signatureTemplate && $lease->signatureTemplate->signed_pdf_path)
                            <a href="{{ route('docuperfect.signatures.download', $lease->document) }}"
                               class="text-xs px-3 py-1 rounded-lg bg-green-600 text-white hover:bg-green-700 text-center">
                                Download PDF
                            </a>
                        @endif
                        <a href="{{ route('docuperfect.leases.history', $lease) }}"
                           class="text-xs px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-center">
                            History
                        </a>
                        <form method="POST" action="{{ route('docuperfect.leases.renew', $lease) }}" class="inline">
                            @csrf
                            <button type="submit" class="w-full text-xs px-3 py-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-center"
                                    onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">
                                Renew Lease
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="ds-status-card p-8 text-center">
                <div class="text-lg text-slate-400">No active leases yet</div>
                <div class="text-sm text-slate-400 mt-1">Completed and approved lease agreements will appear here.</div>
            </div>
        @endforelse
    </div>

</div>
@endsection
