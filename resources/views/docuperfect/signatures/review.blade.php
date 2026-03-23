@extends('layouts.corex')

@section('content')
@php
    $templateType = $document->template?->template_type ?? 'rentals';
    $dashboardRoute = $templateType === 'sales' ? route('docuperfect.sales') : route('docuperfect.rental');
    $dashboardLabel = $templateType === 'sales' ? 'Back to Sales' : 'Back to Dashboard';
@endphp
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ $dashboardRoute }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                {{ $dashboardLabel }}
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">Signature Review</h2>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Header info is in the sticky action bar above — no duplicate --}}

    {{-- Flash messages handled by global toast system --}}

    {{-- Candidate Practitioner Banner — shown when supervisor is reviewing --}}
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

    {{-- Document info --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <h3 class="font-semibold text-slate-800 mb-3">{{ $document->name }}</h3>

        @if($completedRequest)
            <div class="rounded-sm bg-amber-50 border border-amber-200 p-4 mb-4">
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
                            ({{ ucfirst($completedRequest->party_role) }})
                            signed on {{ $completedRequest->completed_at?->format('d M Y \a\t H:i') }}
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Signing progress for all parties (dynamic from template) --}}
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
    </div>

    {{-- Document preview --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <h4 class="font-semibold text-slate-800 mb-3">Document Preview</h4>
        <div class="space-y-4">
            @for($pageNum = 0; $pageNum < $pageCount; $pageNum++)
                <div class="relative border border-slate-200 rounded-lg overflow-hidden">
                    <img src="{{ $pageImages[$pageNum] ?? '' }}" alt="Page {{ $pageNum + 1 }}" class="w-full h-auto">

                    @if(empty($hasFlattened))
                        {{-- FALLBACK: Overlay field values + signatures when not flattened --}}
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
                    {{-- When flattened: fields + signatures are already baked into the image --}}

                    <div class="absolute bottom-2 right-2 bg-white/80 text-xs text-slate-500 px-2 py-0.5 rounded">
                        Page {{ $pageNum + 1 }}
                    </div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Marker checklist --}}
    @if($completedRequest)
        @php
            $completedRole = $completedRequest->party_role;
            $roleMarkers = $allMarkers->where('assigned_party', $completedRole);
            $signedCount = $roleMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
            $totalCount = $roleMarkers->where('required', true)->count();
        @endphp
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

    {{-- Action buttons --}}
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ $dashboardRoute }}"
               class="px-4 py-2 text-sm text-slate-600 border border-slate-300 rounded-lg hover:bg-slate-50">
                Cancel
            </a>

            {{-- Return to Candidate button — only for candidate flows when supervisor is reviewing --}}
            @if(!empty($isCandidateFlow) && !empty($candidateName))
                <div x-data="{ showReturnModal: false }">
                    <button @click="showReturnModal = true"
                            class="px-4 py-2 text-sm text-amber-700 border border-amber-300 rounded-lg hover:bg-amber-50">
                        Return to {{ $candidateName }}
                    </button>

                    {{-- Return modal with notes --}}
                    <div x-show="showReturnModal" x-cloak
                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                         @keydown.escape.window="showReturnModal = false">
                        <div class="bg-white rounded-sm shadow-xl p-6 w-full max-w-md mx-4" @click.away="showReturnModal = false">
                            <h3 class="text-lg font-semibold text-slate-800 mb-2">Return to Candidate</h3>
                            <p class="text-sm text-slate-600 mb-4">
                                Provide notes for <strong>{{ $candidateName }}</strong> explaining what needs to be amended.
                            </p>
                            <form method="POST" action="{{ route('docuperfect.signatures.returnToCandidate', $document) }}">
                                @csrf
                                <textarea name="notes" rows="4" required
                                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
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
                </div>
            @endif
        </div>

        {{-- Amendments Section --}}
        @php
            $templateModel = $document->signatureTemplate;
            $hasAmendments = $templateModel && $templateModel->amendments()->exists();
        @endphp
        @if($hasAmendments)
        <div class="rounded-sm border border-amber-200 bg-amber-50 p-5 mb-4" x-data="amendmentManager()">
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

                        {{-- Acceptance status per party --}}
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

                        {{-- Agent actions --}}
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
                init() {
                    this.loadAmendments();
                },
                async loadAmendments() {
                    try {
                        const res = await fetch('{{ route("docuperfect.signatures.amendments", $document) }}');
                        const data = await res.json();
                        this.amendments = data.amendments || [];
                    } catch (e) {
                        console.error('Failed to load amendments', e);
                    }
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
                        if (data.ok) {
                            this.loadAmendments(); // Refresh
                        }
                    } catch (e) {
                        alert('Failed to process amendment action.');
                    }
                },
            };
        }
        </script>
        @endif

        @php
            $nextPartyLabel = $nextParty ? ucfirst(preg_replace('/_\d+$/', '', $nextParty)) : null;
            $nextPartyName = $nextParty && isset($progress[$nextParty]) ? $progress[$nextParty]['name'] : $nextPartyLabel;
        @endphp
        <form method="POST" action="{{ route('docuperfect.signatures.approveAndAdvance', $document) }}">
            @csrf
            <button type="submit"
                    class="px-6 py-2.5 text-sm font-medium rounded-lg text-white transition-colors
                           {{ $nextParty
                               ? 'bg-blue-600 hover:bg-blue-700'
                               : 'bg-emerald-600 hover:bg-emerald-700' }}"
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
    </div>

</div>
@endsection
