@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@include('docuperfect.partials._dp-page-styles')
<div class="rx-page dp-page w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Lease History</h1>
                <p class="text-xs" style="color: var(--text-muted);">{{ $currentLease->property_address }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.rental') }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Back to Dashboard</a>
            </div>
        </div>
    </div>
    <div class="max-w-4xl space-y-5">

    {{-- Current Lease --}}
    @php
        $current = $versions->firstWhere('id', $currentLease->id) ?? $versions->last();
        $currentIdx = $versions->search(fn($v) => $v->id === $current->id);
        $versionNum = $currentIdx !== false ? $currentIdx + 1 : $totalVersions;
    @endphp

    <div class="space-y-2">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-secondary)">Current Lease (v{{ $versionNum }})</h3>
        <div class="rounded-md p-5" style="border:1px solid var(--border);background:var(--surface-2)">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span style="color:var(--text-muted)">Period:</span>
                    <span class="font-medium" style="color:var(--text-primary)">
                        {{ $current->lease_start_date?->format('d M Y') }} &mdash; {{ $current->lease_end_date?->format('d M Y') }}
                    </span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Rental:</span>
                    <span class="font-medium" style="color:var(--text-primary)">R {{ number_format((float) $current->rental_amount, 0, '.', ' ') }}/mo</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Tenant:</span>
                    <span class="font-medium" style="color:var(--text-primary)">{{ $current->tenant_name }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Landlord:</span>
                    <span class="font-medium" style="color:var(--text-primary)">{{ $current->landlord_name }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Status:</span>
                    @php
                        $statusColor = match($current->status) {
                            'active' => 'bg-emerald-100 text-emerald-700',
                            'expiring_soon' => 'bg-amber-100 text-amber-700',
                            'expired' => 'bg-red-100 text-red-700',
                            'renewed' => 'bg-blue-100 text-blue-700',
                            'terminated' => 'bg-slate-100 text-slate-700',
                            default => 'bg-slate-100 text-slate-600',
                        };
                    @endphp
                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $statusColor }}">
                        {{ ucfirst(str_replace('_', ' ', $current->status)) }}
                    </span>
                </div>
                @if($current->signatureTemplate?->completed_at)
                <div>
                    <span style="color:var(--text-muted)">Signed:</span>
                    <span class="font-medium" style="color:var(--text-primary)">{{ $current->signatureTemplate->completed_at->format('d M Y') }}</span>
                </div>
                @endif
            </div>
            @if($current->document)
            <div class="mt-4 flex gap-2">
                @if($current->signatureTemplate?->signed_pdf_path)
                    <a href="{{ route('docuperfect.signatures.download', $current->document) }}"
                       class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                        View Signed Document
                    </a>
                @endif
                @if($current->signatureTemplate)
                    <a href="{{ route('docuperfect.signatures.audit', $current->document) }}"
                       class="text-xs px-3 py-1.5 rounded-lg hover:bg-[color:var(--surface-2)]" style="border:1px solid var(--border);color:var(--text-secondary)">
                        View Audit Trail
                    </a>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Previous Versions --}}
    @if($totalVersions > 1)
    <div class="space-y-2">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-muted)">Previous Versions</h3>
        <div class="space-y-2">
            @foreach($versions as $idx => $version)
                @if($version->id === $current->id)
                    @continue
                @endif
                @php
                    $vNum = $idx + 1;
                    $rental = number_format((float) $version->rental_amount, 0, '.', ' ');
                    $vStatusColor = match($version->status) {
                        'active' => 'text-emerald-600',
                        'renewed' => 'text-blue-600',
                        'expired' => 'text-red-600',
                        'terminated' => 'text-slate-600',
                        default => 'text-slate-500',
                    };
                @endphp
                <div class="rounded-md p-4" style="border:1px solid var(--border);background:var(--surface)">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium" style="color:var(--text-secondary)">v{{ $vNum }}</span>
                            <span class="text-sm ml-2" style="color:var(--text-muted)">
                                {{ $version->lease_start_date?->format('d M Y') }} to {{ $version->lease_end_date?->format('d M Y') }}
                            </span>
                            <span class="text-sm ml-2" style="color:var(--text-secondary)">&mdash; R {{ $rental }}/mo</span>
                            <span class="text-xs ml-2 {{ $vStatusColor }}">{{ ucfirst(str_replace('_', ' ', $version->status)) }}</span>
                        </div>
                        <div class="flex gap-2">
                            @if($version->document && $version->signatureTemplate?->signed_pdf_path)
                                <a href="{{ route('docuperfect.signatures.download', $version->document) }}"
                                   class="text-xs text-emerald-600 hover:underline">View Signed Document</a>
                            @endif
                        </div>
                    </div>
                    @if($version->signatureTemplate?->completed_at)
                        <div class="text-xs mt-1" style="color:var(--text-faint)">Signed: {{ $version->signatureTemplate->completed_at->format('d M Y') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Rental History Summary --}}
    @if($totalVersions > 1)
    <div class="space-y-2">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-muted)">Rental History</h3>
        <div class="rounded-md p-4" style="border:1px solid var(--border);background:var(--surface)">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                @foreach($versions as $idx => $version)
                    @php
                        $rental = number_format((float) $version->rental_amount, 0, '.', ' ');
                    @endphp
                    <span class="font-medium" style="color:var(--text-secondary)">v{{ $idx + 1 }}: R {{ $rental }}</span>
                    @if($idx > 0)
                        @php
                            $prev = $versions[$idx - 1];
                            $prevAmount = (float) $prev->rental_amount;
                            $curAmount = (float) $version->rental_amount;
                            $change = $prevAmount > 0 ? round((($curAmount - $prevAmount) / $prevAmount) * 100, 1) : 0;
                            $changeColor = $change >= 0 ? 'text-emerald-600' : 'text-red-600';
                        @endphp
                        <span class="text-xs {{ $changeColor }}">
                            ({{ $change >= 0 ? '+' : '' }}{{ $change }}%)
                        </span>
                    @endif
                    @if(!$loop->last)
                        <span style="color:var(--text-faint)">&rarr;</span>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

</div>
</div>
@endsection
