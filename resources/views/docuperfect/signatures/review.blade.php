@extends('layouts.corex')

@section('content')
@php
    $templateType = $document->template?->template_type ?? 'rentals';
    $dashboardRoute = $templateType === 'sales' ? route('docuperfect.sales') : route('docuperfect.rental');
    $dashboardLabel = $templateType === 'sales' ? 'Back to Sales' : 'Back to Dashboard';
    $completedRole = $completedRequest?->party_role;
    $completedRoleLabel = $completedRole ? ucfirst(preg_replace('/_\d+$/', '', $completedRole)) : '';
@endphp

@include('docuperfect.signatures.partials.a4-page-styles')
<style>
/* Read-only document container — interactive elements made inert */
.review-doc-container .web-sig-interactive,
.review-doc-container .corex-page-initials,
.review-doc-container [data-marker-type] {
    pointer-events: none;
    cursor: default;
}
.review-doc-container .web-sig-interactive {
    border: 2px solid #10b981 !important;
    background: rgba(16,185,129,0.04) !important;
}
/* Clause flag highlight */
.clause-flag-card {
    border-left: 4px solid #f59e0b;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 space-y-4">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ $dashboardRoute }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                {{ $dashboardLabel }}
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">Agent Review — {{ $document->name }}</h2>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Candidate Practitioner Banner --}}
    @if(!empty($isCandidateFlow) && !empty($candidateName))
        <div class="rounded-sm border border-purple-200 bg-purple-50 p-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
                <div>
                    <div class="font-semibold text-purple-800">Candidate Practitioner Document</div>
                    <div class="text-sm text-purple-700 mt-1">
                        This document was prepared by <strong>{{ $candidateName }}</strong>, a candidate practitioner under your supervision.
                        Your authorisation is required per the Property Practitioners Act 22 of 2019.
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Awaiting Approval Banner --}}
    @if($completedRequest)
        <div class="rounded-sm bg-amber-50 border border-amber-200 p-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div>
                    <div class="font-semibold text-amber-800">Awaiting Your Approval</div>
                    <div class="text-sm text-amber-700 mt-1">
                        <strong>{{ $completedRequest->signer_name }}</strong>
                        ({{ $completedRoleLabel }})
                        signed on {{ $completedRequest->completed_at?->format('d M Y \a\t H:i') }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Summary Panel --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <h3 class="font-semibold text-slate-800 mb-4">Signing Summary</h3>

        {{-- Signing progress --}}
        <div class="space-y-2 mb-4">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Signing Progress</div>
            @foreach($progress as $role => $p)
                @php $roleLabel = ucfirst(preg_replace('/_\d+$/', '', $role)); @endphp
                <div class="flex items-center gap-3 text-sm py-1.5">
                    @if($p['is_complete'])
                        <span class="text-emerald-500 text-lg">&#10003;</span>
                        <span class="text-slate-600 w-20">{{ $roleLabel }}</span>
                        <span class="text-emerald-600 font-medium">{{ $p['name'] }}</span>
                        <span class="text-slate-400 text-xs ml-auto">
                            {{ $p['signed_markers'] }}/{{ $p['total_markers'] }} markers
                            @if($p['completed_at'])
                                &mdash; {{ $p['completed_at']->format('d M H:i') }}
                            @endif
                        </span>
                    @elseif(!empty($p['is_deferred']))
                        <span class="text-amber-500 text-lg">&#9208;</span>
                        <span class="text-amber-600 w-20">{{ $roleLabel }}</span>
                        <span class="text-amber-600 font-medium">{{ $p['name'] ?: '(unknown)' }} &mdash; Deferred</span>
                        <span class="text-amber-400 text-xs ml-auto">Details not yet provided</span>
                    @else
                        <span class="text-slate-300 text-lg">&#128274;</span>
                        <span class="text-slate-400 w-20">{{ $roleLabel }}</span>
                        <span class="text-slate-400">{{ $p['name'] }} &mdash; waiting</span>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Ceremony values for the completed party --}}
        @if($completedRequest && !empty($ceremonyValues))
            @php
                $partyPrefix = $completedRole . '_';
                $partyCeremony = collect($ceremonyValues)->filter(fn($v, $k) => str_starts_with($k, $partyPrefix));
                $location = $partyCeremony[$partyPrefix . 'location'] ?? null;
                $day = $partyCeremony[$partyPrefix . 'day'] ?? null;
                $month = $partyCeremony[$partyPrefix . 'month'] ?? null;
                $year = $partyCeremony[$partyPrefix . 'year'] ?? null;
                $time = $partyCeremony[$partyPrefix . 'time'] ?? null;
                $amPm = $partyCeremony[$partyPrefix . 'am_pm'] ?? null;
                $dateStr = collect([$day, $month, $year])->filter()->implode(' ');
                $timeStr = collect([$time, $amPm])->filter()->implode(' ');
            @endphp
            @if($partyCeremony->isNotEmpty())
                <div class="border-t border-slate-100 pt-3 mt-3">
                    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Signing Ceremony</div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        @if($location)
                            <div class="text-slate-500">Location:</div>
                            <div class="text-slate-800 font-medium">{{ $location }}</div>
                        @endif
                        @if($dateStr)
                            <div class="text-slate-500">Date:</div>
                            <div class="text-slate-800 font-medium">{{ $dateStr }}</div>
                        @endif
                        @if($timeStr)
                            <div class="text-slate-500">Time:</div>
                            <div class="text-slate-800 font-medium">{{ $timeStr }}</div>
                        @endif
                    </div>
                </div>
            @endif
        @endif

        {{-- Disclosure answers --}}
        @if(!empty($disclosureAnswers))
            @php
                $totalDisclosure = count($disclosureAnswers);
                $answeredCount = collect($disclosureAnswers)->filter(fn($v) => $v !== null && $v !== '')->count();
            @endphp
            <div class="border-t border-slate-100 pt-3 mt-3">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Disclosure</div>
                <div class="text-sm text-slate-700">
                    <span class="font-medium">{{ $answeredCount }}/{{ $totalDisclosure }}</span> items completed
                </div>
            </div>
        @endif

        {{-- Clause flags --}}
        @php
            $partyFlags = $completedRole && isset($clauseFlags[$completedRole]) ? $clauseFlags[$completedRole] : [];
            $flagCount = is_array($partyFlags) ? count($partyFlags) : 0;
        @endphp
        <div class="border-t border-slate-100 pt-3 mt-3">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Clause Flags</div>
            @if($flagCount > 0)
                <div class="text-sm text-amber-700 font-medium mb-2">{{ $flagCount }} clause(s) flagged by {{ $completedRequest?->signer_name }}</div>
                <div class="space-y-2">
                    @foreach($partyFlags as $flag)
                        <div class="clause-flag-card rounded-sm bg-amber-50 border border-amber-200 p-3">
                            <div class="text-sm font-medium text-slate-800">{{ $flag['clause'] ?? $flag['section'] ?? 'Clause' }}</div>
                            @if(!empty($flag['concern']))
                                <div class="text-sm text-amber-700 mt-1">{{ $flag['concern'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-sm text-slate-500">No clauses flagged</div>
            @endif
        </div>
    </div>

    {{-- Amendments --}}
    @php
        $templateModel = $document->signatureTemplate;
        $hasAmendments = $templateModel && $templateModel->amendments()->exists();
    @endphp
    @if($hasAmendments)
    <div class="rounded-sm border border-amber-200 bg-amber-50 p-5" x-data="amendmentManager()">
        <h4 class="font-semibold text-amber-800 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Amendments (v{{ $templateModel->document_version ?? 1 }})
        </h4>

        <div class="space-y-3" x-show="amendments.length > 0">
            <template x-for="amendment in amendments" :key="amendment.id">
                <div class="bg-white rounded-sm border p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-medium text-gray-800">
                            <span x-text="amendment.section || 'Other Conditions'"></span>
                            <span class="text-xs text-gray-500 ml-2" x-text="'(' + amendment.type + ')'"></span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                              :class="{
                                  'bg-amber-100 text-amber-700': amendment.status === 'pending',
                                  'bg-green-100 text-green-700': amendment.status === 'accepted',
                                  'bg-red-100 text-red-700': amendment.status === 'rejected',
                              }"
                              x-text="amendment.status.charAt(0).toUpperCase() + amendment.status.slice(1)"></span>
                    </div>

                    <div x-show="amendment.original_text" class="text-sm text-red-600 line-through mb-1" x-text="amendment.original_text"></div>
                    <div class="text-sm text-green-700 font-medium bg-green-50 rounded p-2 mb-2" x-text="amendment.new_text"></div>
                    <div class="text-xs text-gray-500">
                        Added by <span x-text="amendment.amended_by"></span>
                        (<span x-text="amendment.amended_by_role"></span>)
                        on <span x-text="amendment.created_at"></span>
                    </div>

                    <div class="mt-2 space-y-1">
                        <template x-for="acc in amendment.acceptances" :key="acc.id">
                            <div class="flex items-center gap-2 text-xs">
                                <span x-show="acc.accepted" class="text-green-500">&#10003;</span>
                                <span x-show="acc.rejected" class="text-red-500">&#10007;</span>
                                <span x-show="!acc.accepted && !acc.rejected" class="text-gray-400">&#8987;</span>
                                <span x-text="acc.signer_name"></span>
                                <span class="text-gray-400" x-text="'(' + acc.party_role + ')'"></span>
                                <span x-show="acc.rejected && acc.rejection_reason" class="text-red-500 italic" x-text="'— ' + acc.rejection_reason"></span>
                            </div>
                        </template>
                    </div>

                    <div x-show="amendment.status === 'pending'" class="mt-3 flex items-center gap-2">
                        <button @click="agentAction(amendment.id, 'accept')"
                                class="px-3 py-1 bg-green-600 text-white text-xs font-medium rounded-lg hover:bg-green-700">
                            Accept
                        </button>
                        <button @click="agentAction(amendment.id, 'reject')"
                                class="px-3 py-1 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700">
                            Reject
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="amendments.length === 0" class="text-sm text-gray-500">Loading amendments...</div>
    </div>

    <script>
    function amendmentManager() {
        return {
            amendments: [],
            init() { this.loadAmendments(); },
            async loadAmendments() {
                try {
                    const res = await fetch('{{ route("docuperfect.signatures.amendments", $document) }}');
                    const data = await res.json();
                    this.amendments = data.amendments || [];
                } catch (e) { console.error('Failed to load amendments', e); }
            },
            async agentAction(amendmentId, action) {
                const reason = action === 'reject' ? prompt('Reason for rejection:') : null;
                if (action === 'reject' && !reason) return;
                try {
                    const res = await fetch(`/docuperfect/documents/{{ $document->id }}/amendments/${amendmentId}/action`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({ action, reason }),
                    });
                    const data = await res.json();
                    if (data.ok) { this.loadAmendments(); }
                } catch (e) { alert('Failed to process amendment action.'); }
            },
        };
    }
    </script>
    @endif

    {{-- FULL DOCUMENT PREVIEW --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <h4 class="font-semibold text-slate-800 mb-3">Document with All Signatures</h4>

        @if(!empty($isWebTemplate) && $webTemplateHtml)
            {{-- Web template: render merged_html inline (read-only) --}}
            <link href="/css/corex-document.css" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
            <div class="review-doc-container border border-slate-200 rounded-lg" style="background:#e2e8f0; padding:16px;">
                <div id="reviewDocContent">
                    {!! $webTemplateHtml !!}
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    paginateDocument(document.getElementById('reviewDocContent'), @json($signingParties ?? []));
                });
            </script>
        @else
            {{-- PDF/image-based template: render page images with overlays --}}
            <div class="space-y-4">
                @for($pageNum = 0; $pageNum < $pageCount; $pageNum++)
                    <div class="relative border border-slate-200 rounded-lg overflow-hidden">
                        <img src="{{ $pageImages[$pageNum] ?? '' }}" alt="Page {{ $pageNum + 1 }}" class="w-full h-auto">

                        @if(empty($hasFlattened))
                            @php
                                $docFields = $document->fields_json ?? [];
                                $pageMarkers = $allMarkers->where('page_number', $pageNum + 1);
                                $pageFields = collect($docFields)->where('pageIndex', $pageNum);
                            @endphp

                            @foreach($pageFields as $field)
                                @php
                                    $type = $field['type'] ?? 'placeholder';
                                    $pos = $field['position'] ?? [];
                                    $size = $field['size'] ?? [];
                                    $style = $field['style'] ?? [];
                                    $x = $pos['x'] ?? 0;
                                    $y = $pos['y'] ?? 0;
                                    $w = $size['width'] ?? 0;
                                    $h = $size['height'] ?? 0;
                                    $fontSize = $style['fontSize'] ?? 12;
                                    $fontFamily = $style['fontFamily'] ?? 'Helvetica';
                                    $bold = !empty($style['bold']) ? 'font-weight:bold;' : '';
                                    $underline = !empty($style['underline']) ? 'text-decoration:underline;' : '';
                                    $solidBg = !empty($style['solidBackground']) ? 'background:white;' : '';
                                    $fieldCss = "font-size:{$fontSize}px;font-family:{$fontFamily};color:#000;{$bold}{$underline}{$solidBg}";
                                @endphp

                                @if($type === 'placeholder' && !empty(trim((string)($field['value'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                             style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                    </div>
                                @elseif($type === 'date' && !empty(trim((string)($field['value'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                             style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                    </div>
                                @elseif($type === 'selection' && !empty($field['selectedValue']))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-center px-0.5 overflow-hidden" style="{{ $fieldCss }}">
                                            <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs">{{ $field['selectedValue'] }}</span>
                                        </div>
                                    </div>
                                @elseif($type === 'condition' && !empty(trim((string)($field['text'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                             style="{{ $fieldCss }}">{{ $field['text'] }}</div>
                                    </div>
                                @elseif($type === 'strikethrough' && !empty($field['active']))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        @if(($field['strikethroughType'] ?? 'horizontal') === 'horizontal')
                                            <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                        @else
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                            </svg>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            @foreach($pageMarkers as $marker)
                                @php $sig = $marker->signatures->first(); @endphp
                                <div class="absolute border-2 rounded"
                                     style="left: {{ $marker->x_position }}%; top: {{ $marker->y_position }}%; width: {{ $marker->width }}%; height: {{ $marker->height }}%; z-index:10; {{ $sig ? 'border-color: #10b981;' : 'border-color: #d1d5db; border-style: dashed;' }}">
                                    @if($sig && $sig->signature_data)
                                        <img src="{{ $sig->signature_data }}" class="w-full h-full object-contain" alt="Signature">
                                    @endif
                                </div>
                            @endforeach
                        @endif

                        <div class="absolute bottom-2 right-2 bg-white/80 text-xs text-slate-500 px-2 py-0.5 rounded">
                            Page {{ $pageNum + 1 }}
                        </div>
                    </div>
                @endfor
            </div>
        @endif
    </div>

    {{-- Marker checklist --}}
    @if($completedRequest)
        @php
            $roleMarkers = $allMarkers->where('assigned_party', $completedRole);
            $signedCount = $roleMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
            $totalCount = $roleMarkers->where('required', true)->count();
        @endphp
        @if($totalCount > 0)
        <div class="rounded-sm border border-emerald-200 bg-emerald-50 p-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="font-semibold text-emerald-800">All signature zones signed</span>
            </div>
            <div class="text-sm text-emerald-700">
                {{ $signedCount }} of {{ $totalCount }} required markers completed by {{ $completedRequest->signer_name }}
            </div>
        </div>
        @endif
    @endif

    {{-- ACTION BUTTONS --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5" x-data="{ showReturnModal: false, showRejectModal: false }">
        <h4 class="font-semibold text-slate-800 mb-4">Review Actions</h4>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Approve & Advance --}}
            @php
                $nextPartyLabel = $nextParty ? ucfirst(preg_replace('/_\d+$/', '', $nextParty)) : null;
                $nextPartyName = $nextParty && isset($progress[$nextParty]) ? $progress[$nextParty]['name'] : $nextPartyLabel;
            @endphp
            <form method="POST" action="{{ route('docuperfect.signatures.approveAndAdvance', $document) }}">
                @csrf
                <button type="submit"
                        class="px-6 py-2.5 text-sm font-medium rounded-lg text-white transition-colors
                               {{ $nextParty ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-emerald-600 hover:bg-emerald-700' }}"
                        onclick="return confirm('{{ $nextParty
                            ? 'Approve and send to ' . ($nextPartyName ?: $nextPartyLabel) . '?'
                            : 'Approve and complete the document?' }}')">
                    @if($nextParty)
                        Approve &amp; Send to {{ $nextPartyName ?: $nextPartyLabel }} &rarr;
                    @else
                        Approve &amp; Complete Document
                    @endif
                </button>
            </form>

            {{-- Return to Signer with Notes --}}
            <button @click="showReturnModal = true"
                    class="px-5 py-2.5 text-sm font-medium text-amber-700 border border-amber-300 rounded-lg hover:bg-amber-50 transition-colors">
                Return to {{ $completedRequest ? $completedRoleLabel : 'Signer' }} with Notes
            </button>

            {{-- Reject Document --}}
            <button @click="showRejectModal = true"
                    class="px-5 py-2.5 text-sm font-medium text-red-700 border border-red-300 rounded-lg hover:bg-red-50 transition-colors">
                Reject Document
            </button>

            <a href="{{ $dashboardRoute }}"
               class="px-4 py-2.5 text-sm text-slate-600 border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors ml-auto">
                Cancel
            </a>
        </div>

        {{-- Return to Signer Modal --}}
        <div x-show="showReturnModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
             @keydown.escape.window="showReturnModal = false">
            <div class="bg-white rounded-sm shadow-xl p-6 w-full max-w-md mx-4" @click.away="showReturnModal = false">
                <h3 class="text-lg font-semibold text-slate-800 mb-2">Return to {{ $completedRequest ? $completedRoleLabel : 'Signer' }}</h3>
                <p class="text-sm text-slate-600 mb-4">
                    Provide notes for <strong>{{ $completedRequest?->signer_name ?? 'the signer' }}</strong> explaining what needs to be corrected.
                    They will receive a new signing link.
                </p>
                @if(!empty($isCandidateFlow) && !empty($candidateName))
                <form method="POST" action="{{ route('docuperfect.signatures.returnToCandidate', $document) }}">
                @else
                <form method="POST" action="{{ route('docuperfect.signatures.reject', $document) }}">
                    <input type="hidden" name="action" value="revise">
                @endif
                    @csrf
                    <textarea name="{{ (!empty($isCandidateFlow) && !empty($candidateName)) ? 'notes' : 'rejection_reason' }}" rows="4" required
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                              placeholder="Describe what needs to be corrected or amended..."></textarea>
                    <div class="flex justify-end gap-3 mt-4">
                        <button type="button" @click="showReturnModal = false"
                                class="px-4 py-2 text-sm text-slate-600 border border-slate-300 rounded-lg hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700">
                            Return with Notes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Reject Document Modal --}}
        <div x-show="showRejectModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
             @keydown.escape.window="showRejectModal = false">
            <div class="bg-white rounded-sm shadow-xl p-6 w-full max-w-md mx-4" @click.away="showRejectModal = false">
                <h3 class="text-lg font-semibold text-red-800 mb-2">Reject Document</h3>
                <p class="text-sm text-slate-600 mb-4">
                    This will cancel the entire signing flow. All signatures will be voided.
                    This action cannot be undone.
                </p>
                <form method="POST" action="{{ route('docuperfect.signatures.reject', $document) }}">
                    @csrf
                    <input type="hidden" name="action" value="archive">
                    <textarea name="rejection_reason" rows="4" required minlength="5"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500"
                              placeholder="Reason for rejecting this document..."></textarea>
                    <div class="flex justify-end gap-3 mt-4">
                        <button type="button" @click="showRejectModal = false"
                                class="px-4 py-2 text-sm text-slate-600 border border-slate-300 rounded-lg hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700"
                                onclick="return confirm('Are you sure? This will void all signatures and cancel the signing flow.')">
                            Reject &amp; Cancel Signing
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
