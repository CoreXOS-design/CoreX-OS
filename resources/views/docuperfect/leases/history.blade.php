@extends('layouts.nexus')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Lease History</h2>
            <div class="text-sm text-white/60">{{ $currentLease->property_address }}</div>
        </div>
        <a href="{{ route('docuperfect.rental') }}" class="text-sm text-white/80 hover:text-white">&larr; Back to Dashboard</a>
    </div>

    {{-- Current Lease --}}
    @php
        $current = $versions->firstWhere('id', $currentLease->id) ?? $versions->last();
        $currentIdx = $versions->search(fn($v) => $v->id === $current->id);
        $versionNum = $currentIdx !== false ? $currentIdx + 1 : $totalVersions;
    @endphp

    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">Current Lease (v{{ $versionNum }})</h3>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-slate-500">Period:</span>
                    <span class="font-medium text-slate-800">
                        {{ $current->lease_start_date?->format('d M Y') }} &mdash; {{ $current->lease_end_date?->format('d M Y') }}
                    </span>
                </div>
                <div>
                    <span class="text-slate-500">Rental:</span>
                    <span class="font-medium text-slate-800">R {{ number_format((float) $current->rental_amount, 0, '.', ' ') }}/mo</span>
                </div>
                <div>
                    <span class="text-slate-500">Tenant:</span>
                    <span class="font-medium text-slate-800">{{ $current->tenant_name }}</span>
                </div>
                <div>
                    <span class="text-slate-500">Landlord:</span>
                    <span class="font-medium text-slate-800">{{ $current->landlord_name }}</span>
                </div>
                <div>
                    <span class="text-slate-500">Status:</span>
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
                    <span class="text-slate-500">Signed:</span>
                    <span class="font-medium text-slate-800">{{ $current->signatureTemplate->completed_at->format('d M Y') }}</span>
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
                       class="text-xs px-3 py-1.5 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
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
        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Previous Versions</h3>
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
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium text-slate-700">v{{ $vNum }}</span>
                            <span class="text-sm text-slate-500 ml-2">
                                {{ $version->lease_start_date?->format('d M Y') }} to {{ $version->lease_end_date?->format('d M Y') }}
                            </span>
                            <span class="text-sm text-slate-700 ml-2">&mdash; R {{ $rental }}/mo</span>
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
                        <div class="text-xs text-slate-400 mt-1">Signed: {{ $version->signatureTemplate->completed_at->format('d M Y') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Rental History Summary --}}
    @if($totalVersions > 1)
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Rental History</h3>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                @foreach($versions as $idx => $version)
                    @php
                        $rental = number_format((float) $version->rental_amount, 0, '.', ' ');
                    @endphp
                    <span class="font-medium text-slate-700">v{{ $idx + 1 }}: R {{ $rental }}</span>
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
                        <span class="text-slate-400">&rarr;</span>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

</div>
@endsection
