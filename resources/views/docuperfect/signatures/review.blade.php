@extends('layouts.corex')

@section('content')
@php
    $templateType = $document->template?->template_type ?? 'rentals';
    $dashboardRoute = $templateType === 'sales' ? route('docuperfect.sales') : route('docuperfect.rental');
    $dashboardLabel = $templateType === 'sales' ? 'Back to Sales' : 'Back to Dashboard';
    $completedRole = $completedRequest?->party_role;
    $completedRoleLabel = $completedRole ? ucfirst(preg_replace('/_\d+$/', '', $completedRole)) : '';
    // AT-386 — same prefill sign.blade.php uses for the agent's own initial (userInitials there).
    $userInitials = collect(explode(' ', $user->name ?? ''))->map(fn($n) => strtoupper(substr($n, 0, 1)))->join('');
@endphp

@include('docuperfect.signatures.partials.a4-page-styles')
<style>
/* Read-only document container — interactive elements made inert. The DOCUMENT
   render itself (ink sizing, per-recipient blocks, accumulated signatures) is
   governed ENTIRELY by the shared canonical-spine partial (a4-page-styles), so the
   agent-review renders identically to the signing screens — the review must NOT
   re-style the document. We only make it non-interactive. (The previous emerald
   border/background on .web-sig-interactive was a review-only divergence — the
   "green box" — and is removed; the spine's own signed/ink styling stands.) */
.review-doc-container .web-sig-interactive,
.review-doc-container .corex-page-initials,
.review-doc-container [data-marker-type] {
    pointer-events: none;
    cursor: default;
}
/* Clause flag highlight */
.clause-flag-card {
    border-left: 4px solid #f59e0b;
}
</style>

<div class="{{ ($isAmendmentApproval ?? false) ? 'max-w-[1600px]' : 'max-w-7xl' }} mx-auto px-4 sm:px-6 lg:px-8 pt-6 space-y-4">

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

    {{-- AT-373 — REAL three-column layout: the document reflows narrower (review-main, flex:1) and the
         Amendments panel occupies its OWN column beside it (review-aside), part of the page flow — NOT a
         floating card over the document. Stacks below the doc under 1280px. --}}
    @if($isAmendmentApproval ?? false)
    <div class="review-columns" style="display:flex; gap:16px; align-items:flex-start;">
    <div class="review-main space-y-4" style="flex:1 1 0%; min-width:0;">
    @endif

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

    {{-- Amendments (legacy DocumentAmendment box) — SUPPRESSED in AT-373 amendment-approval mode, where
         the sticky right-rail panel is the single review/action surface for BOTH change types. --}}
    @php
        $templateModel = $document->signatureTemplate;
        $hasAmendments = $templateModel && $templateModel->amendments()->exists();
    @endphp
    @if($hasAmendments && empty($isAmendmentApproval))
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

                    {{-- AT-373 — the inline per-amendment Accept/Reject is HIDDEN during amendment approval:
                         the ONE approve path is the consolidated "Approve Amendment" action at the bottom
                         (agent initials each change, then a single approve). Kept for the legacy flag flow. --}}
                    @unless(!empty($isAmendmentApproval))
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
                    @endunless
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
                    var container = document.getElementById('reviewDocContent');
                    paginateDocument(container, @json($signingParties ?? []));
                    // §20 — pack agent review is SEGMENT-AWARE: label each
                    // .corex-document-wrapper with its OWN document title so
                    // the body matches its header (Bug A). Single doc => one
                    // title (= document name), behaviour unchanged.
                    (function () {
                        var segTitles = @json($packSegmentTitles ?? []);
                        var wraps = container.querySelectorAll('.corex-document-wrapper');
                        Array.prototype.forEach.call(wraps, function (w, i) {
                            if (w.querySelector('.review-seg-title')) return;
                            var h = document.createElement('div');
                            h.className = 'review-seg-title';
                            h.textContent = segTitles[i] || ('Document ' + (i + 1));
                            h.style.cssText = 'font-weight:700;font-size:13px;color:#0f172a;background:#e2e8f0;border-bottom:2px solid #94a3b8;padding:8px 12px;margin:0 0 6px;';
                            w.insertBefore(h, w.firstChild);
                        });
                    })();
                    // Restore previously signed initials so reviewer sees them
                    restoreStoredInitials(container, @json($storedInitials ?? []));
                    // §20 — restore the seller's stored YES/NO/N/A disclosure
                    // answers (read-only) so the reviewing agent sees them.
                    restoreStoredDisclosure(container, @json($disclosureAnswers ?? []));

                    @if($isAmendmentApproval ?? false)
                    // SYMMETRIC edit model (Johan 2026-08-10) — the shared amend tool (cc6's
                    // _selection-edit-tool) is mounted on this page. Host-page contract:
                    //  • the document above renders the change-marks server-side (forDisplay canonical) —
                    //    the tool's selection→strike works against them;
                    //  • emit corex-doc-ready now that the body has painted so the tool binds;
                    //  • an agent strike dispatches corex-amendment-created — reload so the new mark + its
                    //    per-party initial row render from the server (the edit also joined the amendment
                    //    cycle server-side via addEditToActiveCycle) and the panel rebuilds. A reload here is
                    //    safe: this is the review surface, not a live signing ceremony.
                    document.dispatchEvent(new CustomEvent('corex-doc-ready'));
                    document.addEventListener('corex-amendment-created', function () {
                        setTimeout(function () { window.location.reload(); }, 250);
                    });
                    @endif
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

    {{-- Candidate return loop — running notes THREAD across all send-back / resubmit rounds
         (audit evidence, never latest-only). Johan 2026-08-04. --}}
    @php $returnThread = $document->web_template_data['return_thread'] ?? []; @endphp
    @if(!empty($isCandidateFlow) && !empty($returnThread))
        <div class="rounded-sm border border-amber-200 bg-amber-50 p-5">
            <h4 class="font-semibold text-amber-900 mb-3">Return history — {{ $candidateName ?: 'candidate' }} &harr; authoriser</h4>
            <ol class="space-y-2">
                @foreach($returnThread as $entry)
                    @php $isBack = ($entry['direction'] ?? '') === 'sent_back'; @endphp
                    <li class="text-sm flex flex-col rounded-md border {{ $isBack ? 'border-amber-200 bg-white' : 'border-sky-200 bg-sky-50' }} px-3 py-2">
                        <span class="text-xs font-medium {{ $isBack ? 'text-amber-700' : 'text-sky-700' }}">
                            {{ $isBack ? 'Sent back to junior' : 'Resubmitted by junior' }}
                            @if(!empty($entry['round'])) &middot; round {{ $entry['round'] }} @endif
                            @if(!empty($entry['actor_name'])) &middot; {{ $entry['actor_name'] }} @endif
                            @if(!empty($entry['at'])) &middot; {{ \Illuminate\Support\Carbon::parse($entry['at'])->format('d M Y H:i') }} @endif
                        </span>
                        @if(!empty($entry['note']))
                            <span class="text-slate-700 mt-1">{{ $entry['note'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @include('docuperfect.signatures.partials._change-initial-affordance')

    {{-- ACTION BUTTONS — the legacy final-gate/candidate actions. In AT-373 amendment-approval mode the
         action surface is the right-rail panel column (beside the document), so this whole block is suppressed. --}}
    @unless(!empty($isAmendmentApproval))
    <div class="rounded-sm border border-slate-200 bg-white p-5" x-data="{ showReturnModal: false, showRejectModal: false }">
        <h4 class="font-semibold text-slate-800 mb-4">Review Actions</h4>

        <div class="flex flex-wrap items-center gap-3">
            @php
                $nextPartyLabel = $nextParty ? ucfirst(preg_replace('/_\d+$/', '', $nextParty)) : null;
                $nextPartyName = $nextParty && isset($progress[$nextParty]) ? $progress[$nextParty]['name'] : $nextPartyLabel;
            @endphp

            @if(!empty($isAmendmentApproval))
                {{-- AT-373 — a recipient's amendment returned for approval. ONE deterministic flow: the
                     agent initials EACH change in the document above (click the "Initial this change"
                     slot → the capture modal), then the SINGLE Approve action below advances the chain /
                     kicks off the prior-recipient re-initial cascade. No competing approve controls. --}}
                @php
                    $amendNextName = ($nextPartyDisplayName ?? null) ?: ($nextPartyName ?: $nextPartyLabel);
                    $amendNextLabel = $nextParty
                        ? 'Approve &amp; Send to ' . e($amendNextName)
                        : 'Approve &amp; Finalise';
                @endphp
                <div class="w-full">
                    <div id="approveAmendmentNote" class="text-xs mb-2" style="color: var(--text-muted);">Initial each change above to approve.</div>
                    <div class="flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('docuperfect.signatures.amendment.approve', $document) }}">
                            @csrf
                            <button type="submit" id="approveAmendmentBtn" disabled
                                    class="px-6 py-2.5 text-sm font-semibold rounded-lg text-white transition-colors bg-emerald-600 hover:bg-emerald-700 shadow"
                                    style="opacity:0.5; cursor:not-allowed;"
                                    onclick="return confirm('{{ $nextParty ? 'Approve the amendment and send to ' . $amendNextName . '?' : 'Approve and finalise the document?' }}')">
                                {!! $amendNextLabel !!} &rarr;
                            </button>
                        </form>
                        {{-- AT-373 (Part 3) — "Send back to recipient" lives in the right-rail
                             _agent-amendments-panel (the real amendment action surface). This bottom
                             block is suppressed in amendment mode (@unless above), so no button here. --}}
                    </div>
                </div>
            @elseif(!empty($isCandidateFlow) && in_array($template->status, [\App\Models\Docuperfect\SignatureTemplate::STATUS_AWAITING_SUPERVISOR, \App\Models\Docuperfect\SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL]))
                {{-- Candidate flow: supervisor must SIGN, not just approve --}}
                <a href="{{ route('docuperfect.signatures.authoriseSigning', $document) }}"
                   class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold rounded-lg text-white transition-colors shadow"
                   style="background: #f59e0b;"
                   onclick="return confirm('You will be taken to the signing view to authorise this document with your signature and initials.')">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    </svg>
                    Authorise &amp; Sign Document
                </a>
            @else
                {{-- Normal flow: Approve & Advance --}}
                <form method="POST" action="{{ route('docuperfect.signatures.approveAndAdvance', $document) }}">
                    @csrf
                    <button type="submit"
                            class="px-6 py-2.5 text-sm font-medium rounded-lg text-white transition-colors bg-emerald-600 hover:bg-emerald-700"
                            onclick="return confirm('{{ $nextParty
                                ? 'Approve and send to ' . ($nextPartyName ?: $nextPartyLabel) . '?'
                                : 'Approve and finalise the document?' }}')">
                        @if($nextParty)
                            Approve &amp; Send to {{ $nextPartyName ?: $nextPartyLabel }} &rarr;
                        @else
                            Approve &amp; Finalise
                        @endif
                    </button>
                </form>
            @endif

            @unless(!empty($isAmendmentApproval))
            {{-- Return to Signer with Notes (final-gate actions — not shown during amendment approval) --}}
            <button @click="showReturnModal = true"
                    class="px-5 py-2.5 text-sm font-medium text-amber-700 border border-amber-300 rounded-lg hover:bg-amber-50 transition-colors">
                Return to {{ $completedRequest ? $completedRoleLabel : 'Signer' }} with Notes
            </button>

            {{-- Reject Document --}}
            <button @click="showRejectModal = true"
                    class="px-5 py-2.5 text-sm font-medium text-red-700 border border-red-300 rounded-lg hover:bg-red-50 transition-colors">
                Reject Document
            </button>
            @endunless

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

        {{-- SYMMETRIC edit-upon-edit (Johan 2026-08-10): the "Reject Amendment" modal is RETIRED — there is
             no reject. The agent disagrees by EDITING (strike/reword with the shared amend tool), which is a
             new initialed mark, never a removal. Amendment approval now lives entirely in the right-rail
             panel (Accept & Initial / Edit per item + the single "send on" action). --}}
    </div>
    @endunless

    {{-- AT-373 — close review-main + the Amendments panel's OWN column (a real flex column beside the
         document, not a floating overlay). The panel is position:sticky WITHIN this column. --}}
    @if($isAmendmentApproval ?? false)
        {{-- SYMMETRIC edit model (Johan 2026-08-10) — mount cc6's SHARED amend tool (do NOT fork). It gives
             the reviewing agent the SAME strike/reword tool recipients use: its teleported "✎ Amend this"
             float appears when the agent highlights text in the document above, and its amend modal posts to
             the AGENT edit-selection endpoint (same {ok, change_id, oc_ref} contract as the recipient route),
             which folds the edit into the amendment cycle server-side. Our own _agent-amendments-panel remains
             the list + Accept & Initial + Approve surface, so the tool's duplicate sticky-bar list is hidden
             (CSS below); only its ✎ float + modal are used. Edit REPLACES reject. --}}
        @include('docuperfect.signatures.partials._selection-edit-tool', [
            'editSelectionUrl' => route('docuperfect.signatures.editSelection', $document),
            'viewerKey'        => 'agent',
            'pendingReview'    => false,
            'wrapperClass'     => 'agent-edit-tool-host',
        ])
    </div>{{-- /review-main --}}
    {{-- 260px column matching cc6's recipient panel (022c377a .recipient-amend-col: flex 0 0 260px + align-self:stretch).
         align-self:stretch is LOAD-BEARING: the row is align-items:flex-start (so the document column is not forced to
         the panel's short height), so the panel column would otherwise collapse to its own content height — a sticky
         element inside a box no taller than itself has ZERO scroll travel and rides away with the page (BUG B). Stretch
         makes this column span the full document height, giving position:sticky its range so the panel stays put. --}}
    <aside class="review-aside" style="width:260px; flex:0 0 260px; align-self:stretch;">
        @include('docuperfect.signatures.partials._agent-amendments-panel')
    </aside>
    {{-- AT-386 (2026-08-28) — unify the signature capture. The Amendments panel's "Accept & Initial"
         used to open its own self-contained draw/type-only modal (#agentCiModal / window.AgentCI, plain
         JS, no saved-signature option). This mounts the SAME shared capture modal sign.blade.php uses
         (signature-modal.blade.php, savedSignatureSupport=true) and bridges AgentCI.capture(item)'s
         existing Promise<bool> contract to it via corex-open-change-initial (in) / corex-change-initialed
         and corex-change-initial-cancelled (out) — the same event names _change-initial-affordance.blade.php
         already documents and dispatches for its own (separate) slot-click path. __corexApplyChangeInitial /
         __corexApplyConditionInitial are untouched; only WHICH modal captures the ink changes.

         Quoting note: every JS string literal below is single-quoted, and CSS attribute selectors use
         either an unquoted ident (meta[name=csrf-token], precedented at sign.blade.php:209) or single
         quotes ([data-change-id='...']) — never a literal double-quote character — because this whole
         block is itself the content of a DOUBLE-quoted x-data="..." HTML attribute; one stray " here
         closes the attribute early and silently corrupts Alpine's parse of everything after it (the
         exact bug an earlier attempt at this shipped and had to be reverted for). Double-brace
         interpolation of route() calls is safe as written — Blade auto-escapes {{ }} output, converting
         any literal double-quote into an HTML entity. The json Blade directive is NOT safe used the
         same way — it always emits its own literal double-quote JSON delimiters regardless of its
         hex-escape flags — so this file only ever interpolates plain values through double braces, never
         through that directive, inside this attribute. (Caught live: an earlier pass here used the json
         directive for one value and it broke exactly this way — fixed by switching to double-brace
         interpolation instead, not by trusting the directive's escaping.) --}}
    <div x-data="{
            showSignModal: false, activeMarker: null, captureMode: 'draw', typedName: '{{ addslashes($userInitials ?? '') }}',
            signaturePad: null, applying: false, isAgent: true, changeInitialApplied: false,
            savedSigConfigured: false, savedSigImpersonating: false, savedSigUnlocked: false,
            savedSignatureImg: null, savedInitialImg: null,
            savedPinOpen: false, savedPin: '', savedPinError: '', savedPinLoading: false,
            showApplyAll: false, remainingSignatureCount: 0, applyingAll: false,
            init() {
                this.initSavedSig();
                document.addEventListener('corex-open-change-initial', (e) => {
                    e.preventDefault();
                    const d = e.detail || {};
                    this.activeMarker = {
                        type: 'initial', assigned_party: 'agent', label: 'Initial this change', page_number: '',
                        isChangeInitial: true, changeId: d.changeId, partyKey: d.partyKey,
                        itemId: d.itemId || d.changeId, kind: d.kind || 'body',
                    };
                    this.captureMode = 'draw';
                    this.showSignModal = true;
                    this.$nextTick(() => this.initCanvas());
                });
                // Cancel path (Escape / click outside) — resolve AgentCI.capture()'s promise as false
                // rather than leaving it pending forever.
                this.$watch('showSignModal', (open, wasOpen) => {
                    if (wasOpen && !open && this.activeMarker && this.activeMarker.isChangeInitial && !this.changeInitialApplied) {
                        document.dispatchEvent(new CustomEvent('corex-change-initial-cancelled', {
                            detail: { itemId: this.activeMarker.itemId, kind: this.activeMarker.kind },
                        }));
                    }
                    this.changeInitialApplied = false;
                });
            },
            async initSavedSig() {
                if (!this.isAgent) return;
                try {
                    const res = await fetch('{{ route('signature.status') }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const d = await res.json();
                    this.savedSigConfigured = !!d.configured;
                    this.savedSigImpersonating = !!d.impersonating;
                } catch (e) {}
            },
            // AT-386 quoting fix — the json Blade directive always emits its own literal double-quote
            // JSON delimiters (its hex-escape flags only cover quote characters INSIDE the string
            // content, never json_encode's own wrapping quotes), so it is never safe used inline inside
            // a double-quoted HTML attribute like this x-data. Plain double-brace interpolation is safe
            // here because Blade's own auto-escaping converts any literal double-quote in its output to
            // an HTML entity before the browser ever sees it. document->id is a plain integer, so
            // nothing else needs escaping. (This note deliberately avoids writing Blade's own directive
            // syntax as prose — Blade compiles it regardless of being inside a comment.)
            sigContext() { return 'esign:doc:{{ $document->id }}'; },
            csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
            chooseSavedSignature() {
                if (this.savedSigImpersonating || !this.savedSigConfigured) return;
                if (!this.savedSigUnlocked) { this.savedPinError = ''; this.savedPin = ''; this.savedPinOpen = true; return; }
                this.captureMode = 'saved';
            },
            async submitSavedPin() {
                if (this.savedPinLoading || !this.savedPin) return;
                this.savedPinLoading = true; this.savedPinError = '';
                try {
                    const res = await fetch('{{ route('signature.unlock') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), Accept: 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ pin: this.savedPin, context: this.sigContext() }),
                    });
                    const d = await res.json().catch(() => ({}));
                    if (res.ok && d.ok) {
                        await this.loadSavedAssets();
                        this.savedSigUnlocked = true;
                        this.savedPinOpen = false;
                        this.savedPin = '';
                        this.captureMode = 'saved';
                    } else {
                        this.savedPinError = d.error || 'Incorrect PIN.';
                    }
                } catch (e) {
                    this.savedPinError = 'Network error — please try again.';
                } finally {
                    this.savedPinLoading = false;
                }
            },
            async loadSavedAssets() {
                const q = '?context=' + encodeURIComponent(this.sigContext());
                const [s, i] = await Promise.all([
                    fetch('{{ route('signature.asset', ['type' => 'signature']) }}' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }),
                    fetch('{{ route('signature.asset', ['type' => 'initial']) }}'   + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }),
                ]);
                if (s.ok) { const d = await s.json(); this.savedSignatureImg = d.image || null; }
                if (i.ok) { const d = await i.json(); this.savedInitialImg   = d.image || null; }
            },
            savedImageForActiveMarker() {
                const isInitial = this.activeMarker && this.activeMarker.type === 'initial';
                return isInitial ? this.savedInitialImg : this.savedSignatureImg;
            },
            initCanvas() {
                const canvas = this.$refs.signatureCanvas;
                if (!canvas) return;
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext('2d').scale(ratio, ratio);
                if (this.signaturePad) { this.signaturePad.clear(); this.signaturePad.off(); }
                this.signaturePad = new SignaturePad(canvas, {
                    backgroundColor: 'rgba(255, 255, 255, 0)', penColor: 'rgb(0, 0, 0)', minWidth: 1, maxWidth: 3,
                });
            },
            clearCanvas() { if (this.signaturePad) this.signaturePad.clear(); },
            generateTypedSignature(name, isInitial = false) {
                const canvas = this.$refs.typedCanvas;
                if (!canvas) return null;
                const scale = 4; const cW = isInitial ? 200 : 400; const cH = 100;
                canvas.width = cW * scale; canvas.height = cH * scale;
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.scale(scale, scale);
                if (isInitial) {
                    ctx.font = 'bold 80px Arial, Helvetica, sans-serif'; ctx.fillStyle = '#000000';
                    ctx.textBaseline = 'middle'; ctx.textAlign = 'center'; ctx.imageSmoothingEnabled = true;
                    ctx.fillText(name, cW / 2, cH / 2);
                } else {
                    ctx.font = '48px \'Dancing Script\', cursive'; ctx.fillStyle = '#000000';
                    ctx.textBaseline = 'middle'; ctx.imageSmoothingEnabled = true;
                    ctx.fillText(name, 10, cH / 2);
                }
                return canvas.toDataURL('image/png');
            },
            paintChangeInitialSlot(changeId, partyKey, imageDataUrl) {
                const esc = (window.CSS && CSS.escape) ? CSS.escape : (s => String(s).replace(/[\x22\x27\\]/g, '\\$&'));
                const sel = '.cir-slot[data-change-id=\'' + esc(changeId) + '\'][data-party-key=\'' + esc(partyKey) + '\']';
                document.querySelectorAll(sel).forEach((slot) => {
                    slot.classList.add('cir-filled');
                    const ink = slot.querySelector('.cir-ink');
                    if (ink) {
                        ink.removeAttribute('data-empty');
                        const img = document.createElement('img');
                        img.src = imageDataUrl;
                        img.style.cssText = 'max-height:20px;max-width:64px;object-fit:contain;vertical-align:middle;';
                        img.alt = 'Initial';
                        ink.replaceChildren(img);
                    }
                });
            },
            applyToAllSignatureMarkers() { this.showApplyAll = false; },
            // signature-modal.blade.php's default $title expression calls this directly (not
            // this.markerLabel — Alpine evaluates title in the component's own scope) — sign.blade.php's
            // verbatim body. Only ever called here with { assigned_party: 'agent', type: 'initial' },
            // producing 'Agent Initial'.
            markerLabel(m) {
                const partyLabel = m.assigned_party.replace('_', ' ');
                const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
                return partyLabel.charAt(0).toUpperCase() + partyLabel.slice(1) + ' ' + typeLabel;
            },
            async applySignature() {
                if (!this.activeMarker) return;
                this.applying = true;
                let signatureData = null;
                if (this.captureMode === 'saved') {
                    signatureData = this.savedImageForActiveMarker();
                    if (!signatureData) { this.applying = false; return; }
                } else if (this.captureMode === 'draw') {
                    if (!this.signaturePad || this.signaturePad.isEmpty()) { this.applying = false; return; }
                    signatureData = this.signaturePad.toDataURL('image/png');
                } else {
                    if (!this.typedName.trim()) { this.applying = false; return; }
                    signatureData = this.generateTypedSignature(this.typedName.trim(), this.activeMarker.type === 'initial');
                }
                if (this.activeMarker.isChangeInitial) {
                    const kind = this.activeMarker.kind || 'body';
                    const itemId = this.activeMarker.itemId;
                    const ok = kind === 'condition'
                        ? await window.__corexApplyConditionInitial(itemId, signatureData)
                        : await window.__corexApplyChangeInitial(this.activeMarker.changeId, this.activeMarker.partyKey, signatureData);
                    this.changeInitialApplied = ok;
                    this.showSignModal = false;
                    this.applying = false;
                    if (ok) {
                        if (kind !== 'condition') {
                            this.paintChangeInitialSlot(this.activeMarker.changeId, this.activeMarker.partyKey, signatureData);
                        }
                        document.dispatchEvent(new CustomEvent('corex-change-initialed', { detail: { itemId: itemId, kind: kind } }));
                    }
                    return;
                }
                this.applying = false;
            },
         }">
        @include('docuperfect.signatures.partials.signature-modal', ['savedSignatureSupport' => true])

        {{-- Saved-signature PIN unlock (agent-only) — same markup/behaviour as sign.blade.php's own modal.
             This small block isn't itself an @include-able partial upstream (a pre-existing gap, not
             introduced here); everything it calls (route('signature.unlock'), submitSavedPin()) is the
             SAME real backend sign.blade.php uses. This markup is plain HTML content, not inside the
             x-data attribute, so ordinary double-quoted HTML attributes are fine here. --}}
        <div x-show="savedPinOpen" x-cloak x-transition.opacity
             class="fixed inset-0 z-[70] flex items-center justify-center"
             style="background:rgba(0,0,0,0.6);"
             @keydown.escape.window="savedPinOpen = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden" @click.stop>
                <div class="px-6 py-4" style="background:#0b2a4a;">
                    <h3 class="text-white font-semibold text-base">Unlock your saved signature</h3>
                </div>
                <div class="p-6 space-y-3">
                    <p class="text-sm text-slate-600">Enter your <strong>signing PIN</strong> to place your saved signature on this document.</p>
                    <input type="password" x-model="savedPin" inputmode="numeric" autocomplete="off"
                           placeholder="Signing PIN" @keydown.enter="submitSavedPin()"
                           class="w-full rounded-lg border border-slate-300 text-sm px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p x-show="savedPinError" x-cloak class="text-xs text-red-600" x-text="savedPinError"></p>
                    <div class="flex items-center justify-end gap-3 pt-1">
                        <button @click="savedPinOpen = false" class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                        <button @click="submitSavedPin()" :disabled="savedPinLoading || !savedPin"
                                class="rounded-lg px-5 py-2 text-sm font-semibold text-white"
                                :class="(savedPinLoading || !savedPin) ? 'opacity-50 cursor-not-allowed' : ''"
                                style="background:#0b2a4a;"
                                x-text="savedPinLoading ? 'Unlocking…' : 'Unlock'"></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    </div>{{-- /review-columns --}}
    @endif

</div>
@endsection

@if($isAmendmentApproval ?? false)
<style>
    /* Real column layout matching cc6's recipient side (022c377a): a 260px column, the panel sticky
       WITHIN it (top:16px, max-height calc(100vh - 32px)), never floating over the document. The panel's
       INNER list scrolls independently (header + footer stay put) so it scrolls apart from the left nav. */
    /* The column stretches to the document's height (cc6 .recipient-amend-col align-self:stretch) — WITHOUT this the
       sticky panel has no travel and scrolls off with the page. */
    .review-aside { align-self: stretch; }
    #agentAmendPanel { position: sticky; top: 16px; width: 100%; max-height: calc(100vh - 32px); overflow: hidden; }
    /* The shared amend tool is mounted only for its ✎ float + amend modal (both teleported to <body>).
       Our _agent-amendments-panel is the list/Accept/Approve surface, so hide the tool's duplicate
       in-flow sticky-bar list. The teleported float + modal are unaffected (they live on <body>). */
    .agent-edit-tool-host .sel-sticky-bar { display: none !important; }
    .agent-edit-tool-host { display: contents; }
    @media (max-width: 1279px) {
        .review-aside { align-self: auto !important; }
        .review-columns { flex-direction: column !important; }
        .review-aside { width: 100% !important; flex-basis: auto !important; }
        #agentAmendPanel { position: static !important; max-height: none !important; }
    }
</style>
@endif
