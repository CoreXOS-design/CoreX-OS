@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                Audit Trail &mdash; {{ $document->name }}
            </h2>
            <div class="text-sm text-white/60">
                @if($template->isComplete())
                    Completed {{ $template->completed_at?->format('d M Y, H:i') }}
                @else
                    Status: {{ ucfirst(str_replace('_', ' ', $template->status)) }}
                @endif
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if($template->signed_pdf_path)
                <a href="{{ route('docuperfect.signatures.download', $document) }}"
                   class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Download Signed PDF
                </a>
            @endif
            <a href="{{ route('docuperfect.rental') }}"
               class="text-sm text-white/70 hover:text-white">Back to Rental</a>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Signing Parties Summary --}}
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Signing Parties</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($progress as $role => $party)
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <div class="font-semibold text-slate-800">{{ $party['name'] }}</div>
                        <div class="text-xs text-slate-500">
                            {{ strtoupper(str_replace('_', ' ', $role)) }}
                            &mdash; {{ $party['email'] }}
                        </div>
                    </div>
                    <div class="text-right">
                        @if($party['is_complete'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold
                                {{ $party['signing_method'] === 'wet_ink' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                @if($party['signing_method'] === 'wet_ink')
                                    Wet Ink — Verified
                                @else
                                    Electronically Signed
                                @endif
                            </span>
                            @if($party['completed_at'])
                                <div class="text-xs text-slate-400 mt-1">{{ $party['completed_at']->format('d M Y, H:i') }}</div>
                            @endif
                        @else
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
                                Pending
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Document Integrity --}}
    @if($template->document_hash)
        <div class="bg-white rounded-2xl border border-slate-200 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <div class="text-sm font-semibold text-emerald-700">Document Integrity Verified</div>
                    <div class="text-xs text-slate-500 font-mono break-all">
                        SHA-256: {{ $template->document_hash }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Audit Timeline --}}
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Audit Trail</h3>
        </div>
        <div class="px-6 py-4">
            <div class="relative">
                {{-- Timeline line --}}
                <div class="absolute left-3 top-0 bottom-0 w-0.5 bg-slate-200"></div>

                <div class="space-y-0">
                    @foreach($logs as $log)
                        @php
                            $description = \App\Services\Docuperfect\SignaturePdfService::auditActionDescription($log);

                            $dotColor = match($log->action) {
                                'completed', 'document_completed' => 'bg-emerald-500',
                                'signed' => 'bg-blue-500',
                                'sent', 'reminder_sent', 'manual_reminder_sent', 'signed_pdf_emailed' => 'bg-indigo-500',
                                'viewed' => 'bg-sky-400',
                                'wet_ink_uploaded' => 'bg-amber-500',
                                'wet_ink_approved' => 'bg-emerald-500',
                                'wet_ink_rejected' => 'bg-red-500',
                                'declined' => 'bg-red-500',
                                'expired' => 'bg-slate-400',
                                'created' => 'bg-slate-400',
                                default => 'bg-slate-300',
                            };
                        @endphp
                        <div class="relative pl-8 pb-4">
                            {{-- Dot --}}
                            <div class="absolute left-1.5 top-1 w-3 h-3 rounded-full {{ $dotColor }} ring-2 ring-white"></div>

                            <div class="flex items-baseline gap-3">
                                <span class="text-xs text-slate-400 whitespace-nowrap font-mono">
                                    {{ $log->created_at->format('d M Y, H:i') }}
                                </span>
                                <span class="text-sm text-slate-700">{{ $description }}</span>
                            </div>

                            @if($log->actor_ip_address)
                                <div class="text-xs text-slate-400 pl-0 mt-0.5" style="margin-left: 115px;">
                                    IP: {{ $log->actor_ip_address }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Amendment History --}}
    @if(isset($amendments) && $amendments->isNotEmpty())
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-amber-50">
            <h3 class="text-sm font-bold text-amber-800 uppercase tracking-wider">Amendment History</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($amendments as $amendment)
                <div class="px-6 py-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-sm font-semibold text-slate-800">
                                {{ ucfirst($amendment->amendment_type ?? 'Addition') }}
                                @if($amendment->amended_by_party_id)
                                    — by {{ $amendment->amendedBy?->signer_name ?? 'Unknown' }}
                                @endif
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                {{ $amendment->created_at?->format('d M Y, H:i') }}
                                @if($amendment->document_version_before && $amendment->document_version_after)
                                    &middot; v{{ $amendment->document_version_before }} &rarr; v{{ $amendment->document_version_after }}
                                @endif
                            </div>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full font-semibold
                            {{ $amendment->status === 'accepted' ? 'bg-emerald-100 text-emerald-700' : ($amendment->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                            {{ ucfirst($amendment->status ?? 'pending') }}
                        </span>
                    </div>
                    @if($amendment->amendment_text)
                    <div class="mt-2 p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm text-slate-700 italic">
                        "{{ $amendment->amendment_text }}"
                    </div>
                    @endif

                    {{-- Acceptance/rejection details --}}
                    @if($amendment->acceptances && $amendment->acceptances->isNotEmpty())
                    <div class="mt-2 space-y-1">
                        @foreach($amendment->acceptances as $acceptance)
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            @if($acceptance->accepted)
                                <span class="text-emerald-600">&#10003;</span>
                                <span>Accepted by {{ $acceptance->signingRequest?->signer_name ?? 'Unknown' }}</span>
                            @elseif($acceptance->rejected)
                                <span class="text-red-600">&#10007;</span>
                                <span>Rejected by {{ $acceptance->signingRequest?->signer_name ?? 'Unknown' }}
                                    @if($acceptance->rejection_reason) — {{ $acceptance->rejection_reason }} @endif
                                </span>
                            @else
                                <span class="text-amber-500">&#9679;</span>
                                <span>Pending from {{ $acceptance->signingRequest?->signer_name ?? 'Unknown' }}</span>
                            @endif
                            @if($acceptance->initialled_at)
                                <span class="text-slate-400">&middot; Initialled {{ $acceptance->initialled_at->format('d M Y, H:i') }}</span>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Consent Log --}}
    @if(isset($consentLogs) && $consentLogs->isNotEmpty())
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Consent Records</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($consentLogs as $consent)
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-slate-800">
                                {{ $consent->signingRequest?->signer_name ?? $consent->contact?->full_name ?? 'Unknown' }}
                            </div>
                            <div class="text-xs text-slate-500">
                                Consented {{ $consent->consent_accepted_at?->format('d M Y, H:i') }}
                                &middot; IP: {{ $consent->ip_address }}
                            </div>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 font-semibold">
                            Consent Recorded
                        </span>
                    </div>
                    @if($consent->device_info)
                    <div class="text-xs text-slate-400 mt-1">
                        @php $device = is_array($consent->device_info) ? $consent->device_info : []; @endphp
                        {{ $device['browser'] ?? '' }} {{ $device['os'] ?? '' }}
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Document Versions --}}
    @if(isset($versions) && $versions->isNotEmpty())
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Document Versions</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($versions as $version)
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold text-slate-800">
                            Version {{ $version->version_number }}
                            <span class="text-xs font-normal text-slate-500 ml-2">{{ strtoupper($version->file_type) }}</span>
                        </div>
                        <div class="text-xs text-slate-500">
                            Uploaded by {{ $version->uploaded_by_name ?? 'Unknown' }}
                            &middot; {{ $version->uploaded_at?->format('d M Y, H:i') }}
                            @if($version->ip_address) &middot; IP: {{ $version->ip_address }} @endif
                        </div>
                    </div>
                    <div>
                        @if($version->agent_approved)
                            <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 font-semibold">
                                Approved {{ $version->agent_approved_at?->format('d M Y') }}
                            </span>
                        @else
                            <span class="text-xs px-2 py-1 rounded-full bg-amber-100 text-amber-700 font-semibold">
                                Pending Review
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ECT Act Notice --}}
    <div class="text-center text-xs text-slate-400 py-4">
        This document was signed electronically in accordance with the
        Electronic Communications and Transactions Act 25 of 2002 (ECT Act), Republic of South Africa.
    </div>

</div>
@endsection
