@extends('layouts.corex')

@php
    $userInitials = collect(explode(' ', $user->name))->map(function($n) { return strtoupper(substr($n, 0, 1)); })->join('');
    $userFullName = $user->name;
@endphp

@section('corex-content')
{{-- Signature Pad library --}}
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<style>
@keyframes pulseHighlight {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
    50% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
}
.pulse-highlight {
    animation: pulseHighlight 1s ease-in-out 3;
    border-color: #ef4444 !important;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4"
     x-data="signDocument()" x-init="init()">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ route('docuperfect.rental') }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">Sign: {{ $document->name }}</h2>
        </x-slot>
        <x-slot name="right">
            <span class="text-xs text-gray-500" x-text="`${signedCount}/${totalAgent} completed`"></span>
            <button @click="handleComplete()"
                    :disabled="completingForm || signedCount < totalAgent || totalAgent === 0"
                    class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors"
                    :class="signedCount >= totalAgent && totalAgent > 0 && !completingForm
                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                        : 'bg-slate-100 text-slate-400 cursor-not-allowed'">
                <span x-show="!completingForm">Complete & Send</span>
                <span x-show="completingForm" x-cloak>Completing...</span>
            </button>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                Sign Document &mdash; {{ $document->name }}
            </h2>
            <div class="text-sm text-white/60">
                Your markers to sign: <span x-text="signedCount"></span> of <span x-text="totalAgent"></span> completed
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('docuperfect.signatures.setup', $document) }}"
               class="text-sm text-white/70 hover:text-white">Back to Setup</a>
            <a href="{{ route('docuperfect.rental') }}"
               class="text-sm text-white/70 hover:text-white">Back to Rental</a>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Progress bar --}}
    <div class="ds-status-card p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-slate-700">
                Agent Signing Progress
            </span>
            <span class="text-sm text-slate-500">
                <span x-text="signedCount"></span> / <span x-text="totalAgent"></span> markers completed
            </span>
        </div>
        <div class="w-full bg-slate-200 rounded-full h-2.5">
            <div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500"
                 :style="'width:' + (totalAgent > 0 ? Math.round((signedCount / totalAgent) * 100) : 0) + '%'"></div>
        </div>
    </div>

    {{-- Main content: Document viewer --}}
    <div class="ds-status-card p-4 overflow-hidden flex flex-col" style="min-height:600px;">

        {{-- Page navigation --}}
        <div class="flex items-center justify-between mb-3 flex-shrink-0">
            <button @click="prevPage()" :disabled="currentPage <= 1"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                    :class="currentPage <= 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                &larr; Previous
            </button>
            <span class="text-sm text-slate-600 font-medium">
                Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
            </span>
            <button @click="nextPage()" :disabled="currentPage >= totalPages"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                    :class="currentPage >= totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                Next &rarr;
            </button>
        </div>

        {{-- Page display with markers --}}
        <div class="flex-1 overflow-auto flex justify-center" style="background:#e2e8f0;">
            <div class="relative inline-block" style="max-width:800px; width:100%;" x-ref="pageContainer">
                <img :src="pageImages[currentPage - 1]"
                     class="w-full block select-none pointer-events-none"
                     draggable="false"
                     x-ref="pageImage">

                {{-- Render document field values --}}
                {{-- Creator fields: show only when NOT flattened (baked in when flattened) --}}
                {{-- Agent-assigned fields: interactive (agent fills during signing) --}}
                {{-- Other signer fields: locked with label --}}
                <template x-for="field in fieldsForCurrentPage()" :key="field.id">
                    <div x-show="!hasFlattened || (field.assignedTo && field.assignedTo !== 'creator')"
                         class="absolute overflow-hidden"
                         :class="(field.assignedTo === 'agent') ? '' : 'pointer-events-none'"
                         :style="`left:${field.position.x}%;top:${field.position.y}%;width:${field.size.width}%;height:${field.size.height}%;z-index:5;`">

                        {{-- Agent-assigned field: INTERACTIVE — agent fills these during signing --}}
                        <template x-if="field.assignedTo === 'agent'">
                            <div class="w-full h-full">
                                {{-- Tick --}}
                                <template x-if="field.type === 'tick'">
                                    <div class="w-full h-full relative" style="background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;">
                                        <template x-for="(opt, optIdx) in (field.options || [])" :key="optIdx">
                                            <div class="absolute top-0 h-full flex items-center justify-center cursor-pointer hover:bg-blue-100/50 transition-colors"
                                                 :style="`left:${optIdx * (100 / (field.options || []).length)}%;width:${100 / (field.options || []).length}%;`"
                                                 @click="selectFieldOption(field, opt)">
                                                <span class="font-bold text-lg"
                                                      :class="field.selectedValue === opt ? 'text-black' : 'text-slate-300'"
                                                      x-text="field.selectedValue === opt ? 'X' : opt"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                {{-- Selection --}}
                                <template x-if="field.type === 'selection'">
                                    <div class="w-full h-full relative" style="background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;">
                                        <template x-for="(opt, optIdx) in (field.options || [])" :key="optIdx">
                                            <div class="absolute top-0 h-full flex items-center justify-center cursor-pointer hover:bg-blue-100/50 transition-colors"
                                                 :style="`left:${optIdx * (100 / (field.options || []).length)}%;width:${100 / (field.options || []).length}%;`"
                                                 @click="selectFieldOption(field, opt)">
                                                <span class="text-xs px-1"
                                                      :class="field.selectedValue === opt ? 'font-bold text-blue-800 underline' : 'text-slate-400'"
                                                      x-text="opt"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                {{-- Strikethrough --}}
                                <template x-if="field.type === 'strikethrough'">
                                    <div class="w-full h-full relative cursor-pointer"
                                         :style="field.active ? 'background:rgba(239,68,68,0.08);border:2px solid rgba(239,68,68,0.4);border-radius:4px;' : 'background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;'"
                                         @click="field.active = !field.active; fieldsDirty = true;">
                                        <template x-if="field.active && (field.strikethroughType || 'horizontal') === 'horizontal'">
                                            <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                        </template>
                                        <template x-if="field.active && field.strikethroughType === 'diagonal'">
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                            </svg>
                                        </template>
                                        <template x-if="!field.active">
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-[10px] text-blue-600 italic">Click to strike</span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                {{-- Text --}}
                                <template x-if="field.type === 'placeholder'">
                                    <div class="w-full h-full" style="background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;">
                                        <input type="text" class="w-full h-full bg-transparent border-0 outline-none px-1 text-sm"
                                               :style="fieldStyle(field)"
                                               :value="field.value || ''"
                                               @input="field.value = $event.target.value; fieldsDirty = true;"
                                               placeholder="Enter text...">
                                    </div>
                                </template>
                                {{-- Date --}}
                                <template x-if="field.type === 'date'">
                                    <div class="w-full h-full" style="background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;">
                                        <input type="date" class="w-full h-full bg-transparent border-0 outline-none px-1 text-sm"
                                               :value="field.value || ''"
                                               @change="field.value = $event.target.value; fieldsDirty = true;">
                                    </div>
                                </template>
                                {{-- Condition --}}
                                <template x-if="field.type === 'condition'">
                                    <div class="w-full h-full" style="background:rgba(59,130,246,0.08);border:2px solid rgba(59,130,246,0.5);border-radius:4px;">
                                        <textarea class="w-full h-full bg-transparent border-0 outline-none px-1 text-xs resize-none"
                                                  :style="fieldStyle(field)"
                                                  @input="field.text = $event.target.value; fieldsDirty = true;"
                                                  x-text="field.text || ''"></textarea>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Other signer's field: locked with label --}}
                        <template x-if="field.assignedTo && field.assignedTo !== 'creator' && field.assignedTo !== 'agent'">
                            <div class="w-full h-full flex items-center justify-center pointer-events-none"
                                 style="background:rgba(148,163,184,0.15);border:1px dashed rgba(148,163,184,0.5);">
                                <span class="text-[10px] text-slate-500 italic text-center leading-tight px-1"
                                      x-text="signerLabel(field.assignedTo) + ' will complete'"></span>
                            </div>
                        </template>

                        {{-- Creator fields (read-only, shown when not flattened) --}}
                        <template x-if="!field.assignedTo || field.assignedTo === 'creator'">
                            <div class="w-full h-full">
                                <template x-if="field.type === 'placeholder' && field.value">
                                    <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)"
                                         x-text="field.value"></div>
                                </template>
                                <template x-if="field.type === 'date' && field.value">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)"
                                         x-text="field.value"></div>
                                </template>
                                <template x-if="field.type === 'selection' && field.selectedValue">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)">
                                        <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs" x-text="field.selectedValue"></span>
                                    </div>
                                </template>
                                <template x-if="field.type === 'tick' && field.selectedValue">
                                    <div class="w-full h-full flex items-center justify-center"
                                         :style="fieldStyle(field)">
                                        <span class="font-bold text-black" style="font-size:1.2em;" x-text="'X'"></span>
                                    </div>
                                </template>
                                <template x-if="field.type === 'condition' && field.text">
                                    <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                         :style="fieldStyle(field)"
                                         x-text="field.text"></div>
                                </template>
                                <template x-if="field.type === 'strikethrough' && field.active">
                                    <div class="w-full h-full relative">
                                        <template x-if="(field.strikethroughType || 'horizontal') === 'horizontal'">
                                            <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                        </template>
                                        <template x-if="field.strikethroughType === 'diagonal'">
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                            </svg>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="field.type === 'signature' || field.type === 'initial'">
                                    <div class="w-full h-full flex flex-col justify-end p-0.5">
                                        <div class="border-b border-black mb-0.5"></div>
                                        <div class="text-[8px] uppercase text-gray-500" x-text="field.type === 'initial' ? 'Initial' : 'Signature'"></div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Render markers for current page --}}
                <template x-for="marker in markersForCurrentPage()" :key="marker.id">
                    <div class="absolute flex items-center justify-center select-none transition-all duration-200"
                         :id="'marker-' + marker.id"
                         :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:${marker.height}%;z-index:10;`"
                         :class="markerDisplayClasses(marker)"
                         @click="handleMarkerClick(marker)">

                        {{-- Unsigned agent marker (clickable) --}}
                        <template x-if="marker.assigned_party === 'agent' && !marker.signed">
                            <div class="flex flex-col items-center justify-center w-full h-full px-1">
                                <span class="text-xs font-bold leading-tight truncate" x-text="markerActionLabel(marker)"></span>
                                <span class="text-[10px] leading-tight opacity-70 truncate" x-text="marker.label || markerTypeLabel(marker)"></span>
                            </div>
                        </template>

                        {{-- Signed agent marker (shows signature/value) --}}
                        <template x-if="marker.assigned_party === 'agent' && marker.signed">
                            <div class="flex flex-col items-center justify-center w-full h-full relative">
                                <template x-if="marker.signature_data && marker.type !== 'date' && marker.type !== 'text'">
                                    <img :src="marker.signature_data"
                                         class="w-full h-full object-contain p-0.5"
                                         alt="Signature">
                                </template>
                                <template x-if="marker.type === 'date'">
                                    <span class="text-xs font-medium" x-text="marker.text_value || marker.date_value || formatDate(new Date())"></span>
                                </template>
                                <template x-if="marker.type === 'text'">
                                    <span class="text-xs font-medium truncate px-1" x-text="marker.text_value || ''"></span>
                                </template>
                                <span class="absolute -bottom-0.5 right-0.5 text-[9px] text-emerald-700 font-semibold" x-text="marker.type === 'text' ? 'Done' : 'Signed'"></span>
                            </div>
                        </template>

                        {{-- Other party's marker (greyed out) --}}
                        <template x-if="marker.assigned_party !== 'agent'">
                            <div class="flex flex-col items-center justify-center w-full h-full px-1 opacity-60">
                                <svg class="w-3.5 h-3.5 mb-0.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <span class="text-[10px] leading-tight capitalize truncate" x-text="marker.assigned_party"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Complete Signing button --}}
    <div class="ds-status-card p-4 flex items-center justify-between">
        <div class="text-sm text-slate-600">
            <template x-if="signedCount < totalAgent">
                <span>Sign all <span x-text="totalAgent - signedCount"></span> remaining marker<span x-show="(totalAgent - signedCount) !== 1">s</span> to continue.</span>
            </template>
            <template x-if="signedCount >= totalAgent && totalAgent > 0">
                <span class="text-emerald-600 font-medium">All markers signed! Ready to send to the tenant.</span>
            </template>
        </div>
        <button @click="handleComplete()"
                :disabled="completingForm"
                class="rounded-lg px-6 py-2.5 text-sm font-medium transition-colors"
                :class="signedCount >= totalAgent && totalAgent > 0 && !completingForm
                    ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'">
            <span x-show="!completingForm">Complete Signing & Send to Tenant &rarr;</span>
            <span x-show="completingForm" x-cloak>Completing...</span>
        </button>
    </div>

    {{-- Floating progress bar for unsigned markers --}}
    <div x-show="signedCount < totalAgent && totalAgent > 0" x-cloak x-transition
         class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-white shadow-lg rounded-full px-6 py-3 flex items-center gap-3 z-40 border border-slate-200">
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-slate-700" x-text="`${signedCount} of ${totalAgent} completed`"></span>
            <div class="w-24 h-2 bg-slate-200 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all duration-500"
                     :style="`width: ${totalAgent > 0 ? (signedCount / totalAgent) * 100 : 0}%`"></div>
            </div>
        </div>
        <button @click="goToNextUnsigned()"
                class="text-sm text-blue-600 font-medium hover:text-blue-800">
            Next &rarr;
        </button>
    </div>

    {{-- Include signature capture modal --}}
    @include('docuperfect.signatures.partials.signature-modal')

    {{-- Text input modal --}}
    <div x-show="showTextModal" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);"
         @keydown.escape.window="showTextModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
            <div class="px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
                <h3 class="text-white font-semibold text-lg">
                    Enter Text: <span x-text="activeMarker ? (activeMarker.label || markerLabel(activeMarker)) : ''"></span>
                    <span class="text-white/50 text-sm" x-text="activeMarker ? '— Page ' + activeMarker.page_number : ''"></span>
                </h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Type your response</label>
                    <input type="text" x-model="textInputValue"
                           @keydown.enter.prevent="applyTextValue()"
                           class="w-full rounded-lg border-slate-300 text-sm px-3 py-2.5 focus:ring-cyan-500 focus:border-cyan-500"
                           placeholder="Type here...">
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button @click="showTextModal = false"
                            class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                        Cancel
                    </button>
                    <button @click="applyTextValue()"
                            class="corex-btn-primary text-sm px-6 py-2.5"
                            :disabled="applying || !textInputValue.trim()"
                            :class="(applying || !textInputValue.trim()) ? 'opacity-50 cursor-not-allowed' : ''">
                        <span x-show="!applying">Apply</span>
                        <span x-show="applying" x-cloak>Applying...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@php
$markersJson = $allMarkers->map(function($m) {
    $sig = $m->signatures->first();
    return [
        'id' => $m->id,
        'page_number' => $m->page_number,
        'x_position' => (float) $m->x_position,
        'y_position' => (float) $m->y_position,
        'width' => (float) $m->width,
        'height' => (float) $m->height,
        'type' => $m->type,
        'assigned_party' => $m->assigned_party,
        'label' => $m->label,
        'required' => (bool) $m->required,
        'signed' => $sig !== null,
        'signature_data' => $sig ? $sig->signature_data : null,
        'signature_type' => $sig ? $sig->signature_type : null,
        'text_value' => $sig ? $sig->text_value : null,
        'date_value' => $sig && $m->type === 'date' ? ($sig->text_value ?? $sig->signed_at) : null,
    ];
})->values();
@endphp

<script>
function signDocument() {
    return {
        // Data from server
        markers: @json($markersJson),
        pageImages: @json($pageImages),
        documentFields: @json($document->fields_json ?? []),
        hasFlattened: {{ !empty($hasFlattened) ? 'true' : 'false' }},
        currentPage: 1,
        totalPages: {{ $pageCount }},
        signedCount: {{ $signedCount }},
        totalAgent: {{ $totalAgent }},

        // Modal state
        showSignModal: false,
        showTextModal: false,
        textInputValue: '',
        activeMarker: null,
        captureMode: 'draw',
        typedName: @json($user->name),
        applying: false,
        signaturePad: null,

        // Complete form state
        completingForm: false,
        fieldsDirty: false,

        // Apply-to-all state
        showApplyAll: false,
        lastSignatureData: null,
        lastSignatureType: null,
        applyingAll: false,
        firstSignatureDone: false,

        init() {
            // Check if agent already has at least one signed marker
            this.firstSignatureDone = this.markers.some(m => m.assigned_party === 'agent' && m.signed);
        },

        // ── Navigation ──
        prevPage() { if (this.currentPage > 1) this.currentPage--; },
        nextPage() { if (this.currentPage < this.totalPages) this.currentPage++; },

        markersForCurrentPage() {
            return this.markers.filter(m => m.page_number === this.currentPage);
        },

        fieldsForCurrentPage() {
            const pageIdx = this.currentPage - 1;
            return (this.documentFields || []).filter(f => f.pageIndex === pageIdx);
        },

        signerLabel(role) {
            const labels = { agent: 'Agent', tenant: 'Tenant', landlord: 'Landlord', buyer: 'Buyer', seller: 'Seller' };
            return labels[role] || (role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Signer');
        },

        selectFieldOption(field, opt) {
            if (field.selectedValue === opt) {
                field.selectedValue = null;
            } else {
                field.selectedValue = opt;
            }
            this.fieldsDirty = true;
        },

        async saveAgentFields() {
            const agentFields = (this.documentFields || []).filter(f => f.assignedTo === 'agent');
            if (agentFields.length === 0) return true;

            try {
                const resp = await fetch(@json(route('docuperfect.signatures.saveAgentFields', $document)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                    body: JSON.stringify({ fields: agentFields }),
                });
                const data = await resp.json();
                if (!data.ok) {
                    alert(data.error || 'Failed to save field values.');
                    return false;
                }
                this.fieldsDirty = false;
                return true;
            } catch (err) {
                alert('Network error saving fields. Please try again.');
                return false;
            }
        },

        fieldStyle(field) {
            const s = field.style || {};
            let css = 'font-size:' + (s.fontSize || 12) + 'px;';
            css += 'font-family:' + (s.fontFamily || 'Helvetica') + ';';
            css += 'color:#000;';
            if (s.bold) css += 'font-weight:bold;';
            if (s.underline) css += 'text-decoration:underline;';
            if (s.solidBackground) css += 'background:white;';
            return css;
        },

        // ── Marker display ──
        markerLabel(m) {
            const partyLabel = m.assigned_party.replace('_', ' ');
            const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
            return partyLabel.charAt(0).toUpperCase() + partyLabel.slice(1) + ' ' + typeLabel;
        },

        markerTypeLabel(m) {
            return m.type.charAt(0).toUpperCase() + m.type.slice(1);
        },

        markerActionLabel(m) {
            if (m.type === 'text') return 'Enter Text';
            if (m.type === 'date') return 'Auto Date';
            if (m.type === 'initial') return 'Initial Here';
            return 'Sign Here';
        },

        markerDisplayClasses(m) {
            const base = 'rounded border-2 ';
            if (m.assigned_party === 'agent') {
                if (m.signed) {
                    return base + 'border-emerald-500 bg-emerald-50/80';
                }
                return base + 'border-blue-500 bg-blue-50/80 cursor-pointer hover:bg-blue-100 hover:shadow-md';
            }
            // Other party — greyed out
            return base + 'border-slate-300 bg-slate-100/70 cursor-default';
        },

        // ── Marker interaction ──
        handleMarkerClick(marker) {
            if (marker.assigned_party !== 'agent') return;
            if (marker.signed) return;

            // For date markers, auto-fill with today's date
            if (marker.type === 'date') {
                this.signDateMarker(marker);
                return;
            }

            // For text markers, show text input modal
            if (marker.type === 'text') {
                this.activeMarker = marker;
                this.textInputValue = '';
                this.showTextModal = true;
                return;
            }

            this.activeMarker = marker;
            this.captureMode = 'draw';
            this.typedName = marker.type === 'initial'
                ? @json($userInitials)
                : @json($userFullName);
            this.showSignModal = true;

            this.$nextTick(() => this.initCanvas());
        },

        // ── Canvas management ──
        initCanvas() {
            const canvas = this.$refs.signatureCanvas;
            if (!canvas) return;

            // Size canvas to display size with device pixel ratio
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            if (this.signaturePad) {
                this.signaturePad.clear();
                this.signaturePad.off();
            }

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'rgb(0, 0, 0)',
                minWidth: 1,
                maxWidth: 3,
            });
        },

        clearCanvas() {
            if (this.signaturePad) {
                this.signaturePad.clear();
            }
        },

        // ── Typed signature → PNG (4× resolution for crisp compositing) ──
        generateTypedSignature(name, isInitial = false) {
            const canvas = this.$refs.typedCanvas;
            if (!canvas) return null;

            const scale = 4;
            const cW = isInitial ? 200 : 400;
            const cH = 100;
            canvas.width = cW * scale;
            canvas.height = cH * scale;
            const ctx = canvas.getContext('2d');

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.scale(scale, scale);

            if (isInitial) {
                // Initials: 80% of field height, bold, centered
                ctx.font = 'bold 80px Arial, Helvetica, sans-serif';
                ctx.fillStyle = '#000000';
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'center';
                ctx.imageSmoothingEnabled = true;
                ctx.fillText(name, cW / 2, cH / 2);
            } else {
                ctx.font = '48px "Dancing Script", cursive';
                ctx.fillStyle = '#000000';
                ctx.textBaseline = 'middle';
                ctx.imageSmoothingEnabled = true;
                ctx.fillText(name, 10, cH / 2);
            }

            return canvas.toDataURL('image/png');
        },

        // ── Date marker auto-sign (plain text, rendered server-side) ──
        async signDateMarker(marker) {
            const dateStr = this.formatDate(new Date());
            const success = await this.submitSignature(marker, null, 'typed', dateStr);
            if (success) {
                marker.date_value = dateStr;
                marker.text_value = dateStr;
            }
        },

        // ── Text marker input (plain text, rendered server-side) ──
        async applyTextValue() {
            if (!this.activeMarker || !this.textInputValue.trim()) return;
            this.applying = true;

            const text = this.textInputValue.trim();
            const success = await this.submitSignature(this.activeMarker, null, 'typed', text);

            if (success) {
                this.activeMarker.text_value = text;
                this.showTextModal = false;
            }

            this.applying = false;
        },

        formatDate(d) {
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return yyyy + '/' + mm + '/' + dd;
        },

        // ── Apply signature ──
        async applySignature() {
            if (!this.activeMarker) return;
            this.applying = true;

            let signatureData = null;
            let signatureType = 'drawn';

            if (this.captureMode === 'draw') {
                if (!this.signaturePad || this.signaturePad.isEmpty()) {
                    this.applying = false;
                    return;
                }
                signatureData = this.signaturePad.toDataURL('image/png');
                signatureType = 'drawn';
            } else {
                if (!this.typedName.trim()) {
                    this.applying = false;
                    return;
                }
                const isInitial = this.activeMarker && this.activeMarker.type === 'initial';
                signatureData = this.generateTypedSignature(this.typedName.trim(), isInitial);
                signatureType = 'typed';
            }

            const success = await this.submitSignature(this.activeMarker, signatureData, signatureType);

            if (success) {
                this.showSignModal = false;

                // Check if this was the first signature AND there are more signature-type markers to sign
                const remainingSigMarkers = this.markers.filter(m =>
                    m.assigned_party === 'agent' &&
                    !m.signed &&
                    m.type === 'signature'
                );

                if (!this.firstSignatureDone && this.activeMarker.type === 'signature' && remainingSigMarkers.length > 0) {
                    this.lastSignatureData = signatureData;
                    this.lastSignatureType = signatureType;
                    this.remainingSignatureCount = remainingSigMarkers.length;
                    this.showApplyAll = true;
                }

                this.firstSignatureDone = true;
            }

            this.applying = false;
        },

        // ── Submit single signature to server ──
        async submitSignature(marker, signatureData, signatureType, textValue = null) {
            try {
                const url = @json(url('/docuperfect/documents')) + '/{{ $document->id }}/sign/' + marker.id;
                const body = { signature_type: signatureType };
                if (signatureData) body.signature_data = signatureData;
                if (textValue) body.text_value = textValue;

                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                    body: JSON.stringify(body),
                });

                const data = await resp.json();

                if (data.ok) {
                    // Update marker in local state
                    marker.signed = true;
                    marker.signature_data = signatureData;
                    marker.signature_type = signatureType;

                    // Update counts
                    this.signedCount = data.signed_count;
                    return true;
                } else {
                    alert(data.error || 'Failed to capture signature.');
                    return false;
                }
            } catch (err) {
                alert('Network error. Please try again.');
                return false;
            }
        },

        // ── Apply to all remaining signature markers ──
        async applyToAllSignatureMarkers() {
            this.applyingAll = true;

            const remainingSignatures = this.markers.filter(m =>
                m.assigned_party === 'agent' &&
                !m.signed &&
                m.type === 'signature'
            );

            for (const marker of remainingSignatures) {
                const success = await this.submitSignature(marker, this.lastSignatureData, this.lastSignatureType);
                if (!success) break;
            }

            this.showApplyAll = false;
            this.lastSignatureData = null;
            this.applyingAll = false;
        },

        get remainingSignatureCount() {
            return this.markers.filter(m =>
                m.assigned_party === 'agent' &&
                !m.signed &&
                m.type === 'signature'
            ).length;
        },

        set remainingSignatureCount(v) {
            // setter needed for x-text binding from modal partial
        },

        // ── Complete signing (with guided navigation if unsigned markers remain) ──
        async handleComplete() {
            // Check if all agent markers are signed
            const unsignedMarkers = this.markers.filter(m => m.assigned_party === 'agent' && !m.signed);

            if (unsignedMarkers.length > 0) {
                const first = unsignedMarkers[0];
                const typeLabel = first.type === 'text' ? 'enter text' : (first.type === 'initial' ? 'initial' : 'sign');
                alert(`Please ${typeLabel} here — ${unsignedMarkers.length} remaining`);
                this.navigateToMarker(first);
                return;
            }

            if (this.completingForm) return;
            this.completingForm = true;

            // Save agent-assigned field values before completing
            const agentFields = (this.documentFields || []).filter(f => f.assignedTo === 'agent');
            if (agentFields.length > 0) {
                const saved = await this.saveAgentFields();
                if (!saved) {
                    this.completingForm = false;
                    return;
                }
            }

            // Submit via form POST for redirect
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = @json(route('docuperfect.signatures.signComplete', $document));
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = @json(csrf_token());
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        },

        // ── Navigate to next unsigned agent marker ──
        goToNextUnsigned() {
            const unsigned = this.markers.filter(m => m.assigned_party === 'agent' && !m.signed);
            if (unsigned.length === 0) return;
            this.navigateToMarker(unsigned[0]);
        },

        navigateToMarker(marker) {
            if (this.currentPage !== marker.page_number) {
                this.currentPage = marker.page_number;
            }
            this.$nextTick(() => {
                const el = document.getElementById('marker-' + marker.id);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('pulse-highlight');
                    setTimeout(() => el.classList.remove('pulse-highlight'), 3000);
                }
            });
        },
    };
}
</script>
@endsection
