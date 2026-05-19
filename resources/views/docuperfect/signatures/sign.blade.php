@extends('layouts.corex')

@php
    $userInitials = collect(explode(' ', $user->name))->map(function($n) { return strtoupper(substr($n, 0, 1)); })->join('');
    $userFullName = $user->name;
@endphp

@section('corex-content')
{{-- Signature Pad library --}}
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
@include('docuperfect.signatures.partials.a4-page-styles')
<style>
@keyframes pulseHighlight {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
    50% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
}
.pulse-highlight {
    animation: pulseHighlight 1s ease-in-out 3;
    border-color: #ef4444 !important;
}
/* Web template interactive signature elements */
.web-sig-interactive {
    cursor: pointer;
    border: 2px dashed #3b82f6 !important;
    background: rgba(59,130,246,0.06) !important;
    min-height: 28pt;
    transition: all 0.2s;
    position: relative;
}
.web-sig-interactive:hover {
    background: rgba(59,130,246,0.12) !important;
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}
.web-sig-interactive.web-sig-signed {
    border: 2px solid #10b981 !important;
    background: rgba(16,185,129,0.06) !important;
    cursor: default;
}
.web-sig-other-party {
    opacity: 0.5;
    pointer-events: none;
    position: relative;
}
.web-sig-prompt {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #3b82f6;
}
.web-sig-interactive:hover .web-sig-prompt { color: #1d4ed8; }
.web-sig-signed-img {
    display: block;
    max-height: 50px;
    margin: 2px auto;
    object-fit: contain;
}
/* Page break markers with initials */
.corex-page-break {
    margin: 16px 0;
}
.corex-page-initials-row {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    padding: 12px 0 4px 0;
}
.corex-page-initials {
    width: 60px;
    height: 30px;
    border: 1px solid #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
}
.corex-page-initials:hover {
    border-color: #3b82f6;
    background: rgba(59,130,246,0.06);
}
@media print {
    .corex-page-initials {
        border: 1px solid #000;
    }
}
/* Ceremony field highlight when incomplete */
@keyframes ceremonyPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(59,130,246,0.6); }
    50% { box-shadow: 0 0 0 6px rgba(59,130,246,0); }
}
.ceremony-pulse {
    animation: ceremonyPulse 1s ease-in-out 3;
}
.ceremony-incomplete {
    border-bottom-color: #ef4444 !important;
    background: rgba(239,68,68,0.06) !important;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 space-y-4"
     x-data="signDocument()" x-init="init()">

    {{-- Sticky action bar --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ route('docuperfect.signatures.setup', $document) }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Setup
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">
                Sign Document — {{ $document->name }}
                <span class="text-gray-400 font-normal">(<span x-text="totalAgent - incompleteCount"></span> / <span x-text="totalAgent"></span>)</span>
            </h2>
        </x-slot>
        <x-slot name="right">
            <a href="{{ route('docuperfect.rental') }}" class="text-sm text-gray-500 hover:text-gray-700 mr-2">Dashboard</a>
            <button @click="handleComplete()"
                    :disabled="completingForm || incompleteCount > 0"
                    class="px-4 py-2 text-sm font-semibold rounded-sm transition-colors"
                    :class="incompleteCount === 0 && !completingForm
                        ? 'bg-blue-600 text-white hover:bg-blue-700'
                        : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                    :title="incompleteCount > 0 ? 'Please complete all fields: ' + incompleteCount + ' remaining' : ''">
                <span x-show="!completingForm">Complete & Send</span>
                <span x-show="completingForm" x-cloak>Completing...</span>
            </button>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Progress bar --}}
    <div class="bg-white border border-gray-200 rounded-md p-4" style="border-left:4px solid var(--brand-button, #0ea5e9);">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-slate-700">Agent Signing Progress</span>
            <span class="text-sm text-slate-500">
                <span x-text="totalAgent - incompleteCount"></span> / <span x-text="totalAgent"></span> items completed
            </span>
        </div>
        <div class="w-full bg-slate-200 rounded h-2">
            <div class="h-2 rounded transition-all duration-500"
                 :style="'width:' + (totalAgent > 0 ? Math.round(((totalAgent - incompleteCount) / totalAgent) * 100) : 0) + '%;background:var(--brand-button, #0ea5e9);'"></div>
        </div>
    </div>

    {{-- Section-by-section acceptance/rejection is for EXTERNAL signers only.
         The agent created the document — they sign, they don't reject their own sections.
         Section navigator and rejection UI live in external/sign.blade.php. --}}

    {{-- Completion overlay — prevents Alpine re-render issues --}}
    <div x-show="completionDone" x-cloak class="bg-white border border-emerald-200 rounded-md p-8 text-center" style="min-height:300px;">
        <div class="flex flex-col items-center justify-center gap-4 py-12">
            <svg class="w-16 h-16 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <h3 class="text-lg font-semibold text-emerald-700">Signing Complete</h3>
            <p class="text-sm text-gray-500">Your signatures have been saved successfully.</p>
            <div class="flex flex-col items-center gap-3 mt-4">
                <a href="{{ route('docuperfect.esign.myDocuments') }}"
                   style="background: #00d4aa; color: #fff; border-radius: 3px; padding: 10px 24px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; transition: opacity 0.2s;"
                   onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                    Back to My Documents
                </a>
                <a href="/dashboard" style="color: var(--text-muted, #64748b); font-size: 13px; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                    Go to Dashboard
                </a>
            </div>
        </div>
    </div>

    {{-- Main content: Document viewer --}}
    <div x-show="!completionDone" class="bg-white border border-gray-200 rounded-md p-4 overflow-hidden flex flex-col" style="min-height:600px;">

        @if(!empty($isWebTemplate))
        {{-- ═══════════════════════════════════════════════════════════════
             WEB TEMPLATE PATH — single scrollable document with A4 pages.
             ONE container, ONE content output. Nothing else.
             ═══════════════════════════════════════════════════════════════ --}}
        <div class="flex-1 overflow-auto" style="background:#e2e8f0; padding:16px 0; min-width:794px;">
            <link href="/css/corex-document.css" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
            <div x-ref="pageContainer" class="relative" style="width:210mm; max-width:100%; margin:0 auto;">
                <div x-ref="webDocContent">{!! $webTemplateHtml !!}</div>

                {{-- Floating signature markers — same as PDF path but for web templates.
                     Markers come from zones drawn in setup (expanded into DB markers).
                     Positioned with absolute % values relative to the paginated container.
                     Container width locked to 210mm (A4) to match setup coordinate system. --}}
                <template x-for="marker in markers" :key="'wm-' + marker.id">
                    <div class="absolute flex items-center justify-center select-none transition-all duration-200"
                         :id="'marker-' + marker.id"
                         :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:40px;max-width:200px;z-index:10;`"
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

        @else
        {{-- ═══════════════════════════════════════════════════════════════
             PDF TEMPLATE PATH — paginated page images with overlay fields
             and floating signature markers.
             ═══════════════════════════════════════════════════════════════ --}}

        {{-- Page navigation --}}
        <div class="flex items-center justify-between mb-3 flex-shrink-0">
            <button @click="prevPage()" :disabled="currentPage <= 1"
                    class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                    :class="currentPage <= 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                Previous
            </button>
            <span class="text-sm text-slate-600 font-medium">
                Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
            </span>
            <button @click="nextPage()" :disabled="currentPage >= totalPages"
                    class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                    :class="currentPage >= totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                Next
            </button>
        </div>

        {{-- Page display with field overlays and markers --}}
        <div class="flex-1 overflow-auto flex justify-center" style="background:#e2e8f0; padding:16px 0;">
            <div class="relative inline-block" style="max-width:800px; width:100%;" x-ref="pageContainer">
                <img :src="pageImages[currentPage - 1]"
                     class="w-full block select-none pointer-events-none"
                     draggable="false"
                     x-ref="pageImage">

                {{-- Document field overlays --}}
                <template x-for="field in fieldsForCurrentPage()" :key="field.id">
                    <div x-show="!hasFlattened || (field.assignedTo && field.assignedTo !== 'creator')"
                         class="absolute overflow-hidden"
                         :class="(field.assignedTo === 'agent') ? '' : 'pointer-events-none'"
                         :style="fieldDisplayStyle(field)">

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

                        {{-- Other signer's field: show value if pre-filled, else "X will complete" --}}
                        <template x-if="field.assignedTo && field.assignedTo !== 'creator' && field.assignedTo !== 'agent'">
                            <div class="w-full h-full pointer-events-none">
                                {{-- Has value: render as read-only text --}}
                                <template x-if="field.value || field.selectedValue || field.text">
                                    <div class="w-full h-full">
                                        <template x-if="(field.type === 'placeholder' || field.type === 'date') && field.value">
                                            <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.value"></div>
                                        </template>
                                        <template x-if="field.type === 'selection' && field.selectedValue">
                                            <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.selectedValue"></div>
                                        </template>
                                        <template x-if="field.type === 'tick' && field.selectedValue">
                                            <div class="w-full h-full flex items-center justify-center"
                                                 :style="fieldStyle(field)">
                                                <span class="font-bold text-black" style="font-size:1.2em;">X</span>
                                            </div>
                                        </template>
                                        <template x-if="field.type === 'condition' && field.text">
                                            <div class="w-full h-full overflow-hidden px-0.5"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.text"></div>
                                        </template>
                                    </div>
                                </template>
                                {{-- No value: placeholder label --}}
                                <template x-if="!field.value && !field.selectedValue && !field.text">
                                    <div class="w-full h-full flex items-center justify-center"
                                         style="background:rgba(148,163,184,0.15);border:1px dashed rgba(148,163,184,0.5);">
                                        <span class="text-[10px] text-slate-500 italic text-center leading-tight px-1"
                                              x-text="signerLabel(field.assignedTo) + ' will complete'"></span>
                                    </div>
                                </template>
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

                {{-- Floating signature markers --}}
                <template x-for="marker in markersForCurrentPage()" :key="marker.id">
                    <div class="absolute flex items-center justify-center select-none transition-all duration-200"
                         :id="'marker-' + marker.id"
                         :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:40px;max-width:200px;z-index:10;`"
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
        @endif
    </div>

    {{-- Complete Signing button --}}
    <div class="bg-white border border-gray-200 rounded-md p-4 flex items-center justify-between">
        <div class="text-sm text-slate-600">
            <template x-if="incompleteCount > 0">
                <span>
                    <span x-text="incompleteCount"></span> field<span x-show="incompleteCount !== 1">s</span> remaining —
                    <button @click="scrollToNextIncomplete()" class="text-blue-600 hover:text-blue-800 underline font-medium">go to next</button>
                </span>
            </template>
            <template x-if="incompleteCount === 0">
                <span class="font-medium" style="color:var(--brand-button, #0ea5e9);">All fields complete. Ready to send.</span>
            </template>
        </div>
        <button @click="handleComplete()"
                :disabled="completingForm || incompleteCount > 0"
                class="rounded-md px-6 py-2.5 text-sm font-semibold transition-colors"
                :class="incompleteCount === 0 && !completingForm
                    ? 'text-white hover:brightness-110'
                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'"
                :style="incompleteCount === 0 && !completingForm ? 'background:var(--brand-button, #0ea5e9);' : ''"
                :title="incompleteCount > 0 ? 'Please complete all fields (' + incompleteCount + ' remaining)' : ''">
            <span x-show="!completingForm">Complete Signing & Send</span>
            <span x-show="completingForm" x-cloak>Completing...</span>
        </button>
    </div>

    {{-- Floating progress bar — shows incomplete count (sigs + ceremony fields) --}}
    <div x-show="incompleteCount > 0" x-cloak x-transition
         class="fixed bottom-4 left-1/2 transform -translate-x-1/2 shadow-lg rounded-md px-6 py-3 flex items-center gap-3 z-40 border border-gray-700"
         style="background:var(--corex-navy, #0b2a4a);">
        <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <span class="text-sm font-medium text-white" x-text="incompleteCount + ' field' + (incompleteCount !== 1 ? 's' : '') + ' remaining'"></span>
        </div>
        <button @click="scrollToNextIncomplete()"
                class="text-sm font-semibold px-3 py-1 rounded transition-colors"
                style="background:var(--brand-button, #0ea5e9);color:white;">
            Go to next
        </button>
    </div>

    {{-- Include signature capture modal --}}
    @include('docuperfect.signatures.partials.signature-modal')

    {{-- Text input modal --}}
    <div x-show="showTextModal" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);"
         @keydown.escape.window="showTextModal = false">
        <div class="bg-white rounded-sm shadow-2xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
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
        documentFields: Array.from(Object.values(@json($document->fields_json ?? []))),
        hasFlattened: {{ !empty($hasFlattened) ? 'true' : 'false' }},
        isWebTemplate: {{ !empty($isWebTemplate) ? 'true' : 'false' }},
        signingParties: @json($signingParties ?? []),
        storedInitials: @json($storedInitials ?? []),
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
        completionDone: false,
        fieldsDirty: false,

        // Apply-to-all state
        showApplyAll: false,
        lastSignatureData: null,
        lastSignatureType: null,
        applyingAll: false,

        // Web template interactive signing state
        webSigElements: [],       // [{el, partyRole, type, index, isMine, signed, sigData}]
        webSignatures: {},         // { 'agent-sig-0': dataUrl, ... }
        // §19 Part A — disclosure state (shared logic pulled in below).
        // Agent view is READ-ONLY for the seller's mandatory disclosure
        // (PPA s70) but must SEE + gate-count it and persist it on submit.
        webDisclosureAnswers: {},
        totalDisclosureRows: 0,
        storedDisclosure: @json($storedDisclosure ?? new \stdClass),
        webSigTotal: 0,
        webSigSigned: 0,
        webCeremonyValues: {},     // { 'agent_location': 'Shelly Beach', 'agent_day': '23', ... }
        webInitialElements: [],    // [{el, partyRole, index, isMine, signed, sigData}]
        _pendingInitialApplyAll: false,
        _initialsApplyAllOffered: false,
        incompleteCount: 0,        // Total unfilled required items (sigs + initials + ceremony fields)

        // Section-by-section signing state
        hasSections: {{ !empty($sections) ? 'true' : 'false' }},
        sections: @json($sections ?? []),
        sectionLabels: @json(collect($sections ?? [])->pluck('label')->toArray()),
        totalSections: {{ count($sections ?? []) }},
        currentSection: 0,
        sectionStates: @json(collect($sections ?? [])->map(function($s, $i) use ($sectionAcceptances) {
            if (isset($sectionAcceptances[$i])) {
                return $sectionAcceptances[$i]['accepted'] ? 'accepted' : ($sectionAcceptances[$i]['rejected'] ? 'rejected' : 'pending');
            }
            return 'pending';
        })->values()->toArray()),
        sectionAccepting: false,
        sectionRejecting: false,
        showRejectModal: false,
        rejectReasonText: '',
        firstSignatureDone: false,

        init() {
            // Check if agent already has at least one signed marker
            this.firstSignatureDone = this.markers.some(m => m.assigned_party === 'agent' && m.signed);

            // For web templates: split into A4 pages, then make elements interactive
            // Uses $nextTick + setTimeout to ensure server-rendered HTML is fully in the DOM
            if (this.isWebTemplate) {
                this.$nextTick(() => {
                    setTimeout(() => {
                        const container = this.$refs.webDocContent;
                        paginateDocument(container, this.signingParties);
                        this._syncTotalPagesFromPagination(container);
                        // Restore previously signed initials (from other parties)
                        restoreStoredInitials(container, this.storedInitials);
                        this._makeWebElementsInteractive();
                        this._processAllDisclosures();
                        // Delay to let DOM settle after ceremony fields are created
                        setTimeout(() => this._updateIncompleteCount(), 300);
                    }, 150);
                });
            } else {
                // PDF template: incomplete count = unsigned agent markers
                this._updateIncompleteCount();
            }
        },

        // §19 Part A — shared disclosure logic (single source; external +
        // agent both pull this in). The agent is never the disclosure
        // party, so _disclosureEditable() returns false here → grid is
        // visible + restored + gate-counted, but READ-ONLY.
        _currentSignerRole() {
            return 'agent';
        },
        @include('docuperfect.signatures.partials.disclosure-logic')

        /**
         * Web template interactive signing: find all [data-marker-party][data-marker-type="signature"]
         * elements in the document HTML and make the agent's elements clickable.
         * No floating overlays — the document elements ARE the signing surface.
         */
        _makeWebElementsInteractive() {
            const container = this.$refs.webDocContent;
            if (!container) return;

            const self = this;
            const _isSales = @json($isSalesTemplate ?? false);
            const partyRoleMap = {
                'owner': _isSales ? 'seller' : 'landlord',
                'owner_party': _isSales ? 'seller' : 'landlord',
                'landlord': 'landlord', 'lessor': 'landlord',
                'seller': 'seller',
                'tenant': 'tenant', 'lessee': 'tenant',
                'buyer': 'buyer',
                'acquiring_party': _isSales ? 'buyer' : 'tenant',
                'agent': 'agent',
            };

            const tryInit = () => {
                const sigElements = container.querySelectorAll('[data-marker-party][data-marker-type="signature"]');
                if (sigElements.length === 0) return false;

                self.webSigElements = [];
                let agentCount = 0;
                const partyCounters = {};

                sigElements.forEach((el) => {
                    const rawParty = (el.dataset.markerParty || '').toLowerCase();
                    const baseRole = partyRoleMap[rawParty] || rawParty;
                    const markerType = el.dataset.markerType || 'signature';
                    const isMine = baseRole === 'agent';
                    if (partyCounters[baseRole] === undefined) partyCounters[baseRole] = 0;
                    const sigKey = baseRole + '-sig-' + partyCounters[baseRole];
                    partyCounters[baseRole]++;

                    const entry = { el, partyRole: baseRole, rawParty: rawParty, type: markerType, index: partyCounters[baseRole] - 1, sigKey, isMine, signed: false, sigData: null };
                    self.webSigElements.push(entry);

                    if (isMine) {
                        agentCount++;
                        // Make clickable
                        el.classList.add('web-sig-interactive');
                        el.setAttribute('data-sig-key', sigKey);
                        el.innerHTML = '<div class="web-sig-prompt"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg> Click to sign</div>';

                        el.addEventListener('click', () => {
                            if (entry.signed) return;
                            self._openWebSigCapture(entry);
                        });
                    } else {
                        // Other party — grey out
                        el.classList.add('web-sig-other-party');
                        const nameEl = el.querySelector('.sig-cell-label');
                        const partyLabel = rawParty.replace(/_/g, ' ');
                        if (!nameEl) {
                            el.innerHTML = '<div style="font-size:9px;color:#94a3b8;text-align:center;padding:4px;">Awaiting ' + partyLabel + '</div>';
                        }
                    }
                });

                self.webSigTotal = agentCount;
                self.webSigSigned = 0;

                // Make "Thus done and signed" ceremony fields editable for the agent's blocks
                self._makeCeremonyFieldsEditable(container);

                // Make page-break initials interactive
                self._makeWebInitialsInteractive(container);

                return true;
            };

            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                if (tryInit() || attempts > 20) {
                    clearInterval(interval);
                    // Recompute counts after all interactive setup completes
                    setTimeout(() => this._updateIncompleteCount(), 100);
                }
            }, 200);
        },

        /**
         * Make page-break initials elements interactive.
         * Agent's initials are clickable; other parties are greyed out.
         * All agent initials must be completed before signing can finish.
         */
        _makeWebInitialsInteractive(container) {
            const self = this;
            const initialElements = container.querySelectorAll('[data-marker-type="initial"]');
            if (initialElements.length === 0) return;

            const _isSales = @json($isSalesTemplate ?? false);
            const partyRoleMap = {
                'owner': _isSales ? 'seller' : 'landlord',
                'owner_party': _isSales ? 'seller' : 'landlord',
                'landlord': 'landlord', 'lessor': 'landlord',
                'seller': 'seller',
                'tenant': 'tenant', 'lessee': 'tenant',
                'buyer': 'buyer',
                'acquiring_party': _isSales ? 'buyer' : 'tenant',
                'agent': 'agent',
            };

            this.webInitialElements = [];
            let agentInitialCount = 0;
            const initPartyCounters = {};

            initialElements.forEach((el) => {
                const rawParty = (el.dataset.markerParty || '').toLowerCase();
                const baseRole = partyRoleMap[rawParty] || rawParty;
                const isMine = baseRole === 'agent';
                if (initPartyCounters[baseRole] === undefined) initPartyCounters[baseRole] = 0;
                const initKey = baseRole + '-init-' + initPartyCounters[baseRole];
                initPartyCounters[baseRole]++;

                const entry = { el, partyRole: baseRole, rawParty, index: initPartyCounters[baseRole] - 1, initKey, isMine, signed: false, sigData: null };
                self.webInitialElements.push(entry);

                if (isMine) {
                    agentInitialCount++;
                    el.style.cursor = 'pointer';
                    el.style.border = '2px dashed #3b82f6';
                    el.style.background = 'rgba(59,130,246,0.06)';
                    el.title = 'Click to initial';

                    el.addEventListener('click', () => {
                        if (entry.signed) return;
                        // Open signature capture in initial mode (smaller pad)
                        self.activeMarker = {
                            id: initKey,
                            type: 'initial',
                            assigned_party: 'agent',
                            _isWebInitial: true,
                            _webInitialEntry: entry,
                        };
                        self.captureMode = 'draw';
                        self.typedName = @json($userInitials ?? '');
                        self.showSignModal = true;
                        self.$nextTick(() => self.initCanvas());
                    });
                } else {
                    // Other party — grey out
                    el.style.opacity = '0.5';
                    el.style.pointerEvents = 'none';
                    el.style.cursor = 'default';
                }
            });

            // Track initial count (totalAgent set by _updateIncompleteCount)
            if (agentInitialCount > 0) {
                this.webSigTotal = (this.webSigTotal || 0) + agentInitialCount;
            }
        },

        /**
         * Make "Thus done and signed" ceremony fields editable for the current signer.
         * Fields: location, day, month, year, time, am_pm
         * Pre-fills date/time with current values. Agent fills location manually.
         */
        _makeCeremonyFieldsEditable(container) {
            const ceremonyTypes = ['location', 'day', 'month', 'year', 'time', 'am_pm'];
            const now = new Date();
            const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            const prefills = {
                day: String(now.getDate()),
                month: months[now.getMonth()],
                year: String(now.getFullYear()).slice(-2),
                time: now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0'),
                am_pm: now.getHours() >= 12 ? 'pm' : 'am',
            };
            const placeholders = {
                location: 'Location',
                day: 'DD',
                month: 'Month',
                year: 'YY',
                time: 'HH:MM',
                am_pm: 'am/pm',
            };

            const _isSales = @json($isSalesTemplate ?? false);
            const partyRoleMap = {
                'owner': _isSales ? 'seller' : 'landlord',
                'owner_party': _isSales ? 'seller' : 'landlord',
                'landlord': 'landlord', 'lessor': 'landlord',
                'seller': 'seller',
                'tenant': 'tenant', 'lessee': 'tenant',
                'buyer': 'buyer',
                'acquiring_party': _isSales ? 'buyer' : 'tenant',
                'agent': 'agent',
            };

            const self = this;
            ceremonyTypes.forEach(fieldType => {
                const selector = '[data-marker-party][data-marker-type="' + fieldType + '"]';
                container.querySelectorAll(selector).forEach(el => {
                    const rawParty = (el.dataset.markerParty || '').toLowerCase();
                    const baseRole = partyRoleMap[rawParty] || rawParty;
                    const isMine = baseRole === 'agent';

                    if (!isMine) {
                        // Other party's ceremony fields — leave as-is (read-only)
                        el.style.opacity = '0.5';
                        return;
                    }

                    // Replace the span with an inline input
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.setAttribute('data-marker-party', el.dataset.markerParty);
                    input.setAttribute('data-marker-type', fieldType);
                    input.setAttribute('data-ceremony-field', 'true');
                    input.value = prefills[fieldType] || '';
                    input.placeholder = placeholders[fieldType] || fieldType;
                    input.className = el.className;
                    input.style.cssText = el.style.cssText +
                        'background:rgba(59,130,246,0.06);' +
                        'border:none;border-bottom:2px solid rgba(59,130,246,0.5);' +
                        'outline:none;font:inherit;color:inherit;' +
                        'padding:1pt 4pt;box-sizing:border-box;' +
                        'min-height:14pt;';

                    // Track changes + auto-fill matching ceremony fields
                    input.addEventListener('input', () => {
                        if (!self.webCeremonyValues) self.webCeremonyValues = {};
                        self.webCeremonyValues[rawParty + '_' + fieldType] = input.value;
                        // Issue 1: Propagate to all matching agent ceremony fields of same type
                        self._propagateCeremonyField(fieldType, input.value, input);
                        self._updateIncompleteCount();
                    });

                    // Store prefilled value
                    if (prefills[fieldType]) {
                        if (!self.webCeremonyValues) self.webCeremonyValues = {};
                        self.webCeremonyValues[rawParty + '_' + fieldType] = prefills[fieldType];
                    }

                    el.replaceWith(input);
                });
            });

            // Handle am/pm: find literal "am / pm" text next to time fields and make it a toggle
            // The signature-block renders "am / pm" as plain text after the time field
            container.querySelectorAll('[data-marker-type="time"]').forEach(timeEl => {
                const parent = timeEl.parentElement;
                if (!parent) return;
                const rawParty = (timeEl.dataset.markerParty || '').toLowerCase();
                const baseRole = partyRoleMap[rawParty] || rawParty;
                if (baseRole !== 'agent') return;

                // Walk sibling text nodes after the time input to find "am / pm"
                let node = timeEl.nextSibling;
                while (node) {
                    if (node.nodeType === Node.TEXT_NODE && /am\s*\/\s*pm/i.test(node.textContent)) {
                        const amPmBtn = document.createElement('button');
                        amPmBtn.type = 'button';
                        amPmBtn.className = 'sig-field sig-field-short';
                        amPmBtn.style.cssText = 'cursor:pointer;background:rgba(59,130,246,0.06);border:none;border-bottom:2px solid rgba(59,130,246,0.5);font:inherit;color:inherit;padding:1pt 4pt;min-width:40pt;text-align:center;min-height:14pt;';
                        amPmBtn.textContent = now.getHours() >= 12 ? 'pm' : 'am';
                        amPmBtn.setAttribute('data-ceremony-field', 'true');
                        amPmBtn.setAttribute('data-marker-party', rawParty);
                        amPmBtn.setAttribute('data-marker-type', 'am_pm');
                        amPmBtn.addEventListener('click', () => {
                            amPmBtn.textContent = amPmBtn.textContent === 'am' ? 'pm' : 'am';
                            if (!self.webCeremonyValues) self.webCeremonyValues = {};
                            self.webCeremonyValues[rawParty + '_am_pm'] = amPmBtn.textContent;
                            self._updateIncompleteCount();
                        });
                        // Replace "am / pm" text with the button
                        const remaining = node.textContent.replace(/am\s*\/\s*pm/i, '').trim();
                        if (remaining) {
                            node.textContent = remaining + ' ';
                            node.after(amPmBtn);
                        } else {
                            node.replaceWith(amPmBtn);
                        }
                        if (!self.webCeremonyValues) self.webCeremonyValues = {};
                        self.webCeremonyValues[rawParty + '_am_pm'] = amPmBtn.textContent;
                        break;
                    }
                    node = node.nextSibling;
                }
            });
        },

        /**
         * Issue 1: Auto-fill all matching ceremony fields of the same type for the agent.
         * When agent fills "Shelly Beach" for location, ALL agent location fields update.
         */
        _propagateCeremonyField(fieldType, value, sourceInput) {
            const container = this.$refs.webDocContent;
            if (!container) return;
            const allMatching = container.querySelectorAll('input[data-ceremony-field="true"][data-marker-type="' + fieldType + '"]');
            allMatching.forEach(inp => {
                if (inp === sourceInput) return;
                inp.value = value;
                // Also update the tracked values
                const party = (inp.dataset.markerParty || '').toLowerCase();
                if (!this.webCeremonyValues) this.webCeremonyValues = {};
                this.webCeremonyValues[party + '_' + fieldType] = value;
            });
        },

        /**
         * Issue 2 & 3: Compute all incomplete required items (unsigned sigs + empty ceremony fields).
         * Returns array of {el, label} for each incomplete item.
         */
        _computeIncompleteItems() {
            const items = [];

            // Helper: get absolute document-top position (not viewport-relative)
            function getDocumentTop(el) {
                let top = 0;
                let current = el;
                while (current) {
                    top += current.offsetTop;
                    current = current.offsetParent;
                }
                return top;
            }

            // Unsigned agent DB markers (works for both PDF and web templates)
            this.markers.forEach(m => {
                if (m.assigned_party === 'agent' && !m.signed) {
                    const el = document.getElementById('marker-' + m.id);
                    items.push({ el, label: m.label || m.type, type: 'marker', party: 'agent' });
                }
            });

            if (this.isWebTemplate) {
                // Also include unsigned web template HTML-based elements
                this.webSigElements.forEach(entry => {
                    if (entry.isMine && !entry.signed) {
                        items.push({ el: entry.el, label: 'Signature', type: 'sig', party: entry.partyRole });
                    }
                });
                // Unsigned agent initials at page breaks
                (this.webInitialElements || []).forEach(entry => {
                    if (entry.isMine && !entry.signed) {
                        items.push({ el: entry.el, label: 'Page Initial', type: 'initial', party: entry.partyRole });
                    }
                });
            }
            // Empty ceremony fields (location is required; date/time are pre-filled)
            const container = this.$refs.webDocContent;
            if (container) {
                container.querySelectorAll('input[data-ceremony-field="true"]').forEach(inp => {
                    if (!inp.value || !inp.value.trim()) {
                        const type = inp.dataset.markerType || 'field';
                        const label = type.charAt(0).toUpperCase() + type.slice(1).replace('_', '/');
                        items.push({ el: inp, label, type: 'ceremony', party: 'agent' });
                    }
                });
                // Also check am_pm buttons that are ceremony fields
                container.querySelectorAll('button[data-ceremony-field="true"]').forEach(btn => {
                    if (!btn.textContent || !btn.textContent.trim()) {
                        items.push({ el: btn, label: 'AM/PM', type: 'ceremony', party: 'agent' });
                    }
                });
            }

            // Sort by absolute document position (top-to-bottom)
            items.sort((a, b) => {
                const aTop = a.el ? getDocumentTop(a.el) : 0;
                const bTop = b.el ? getDocumentTop(b.el) : 0;
                return aTop - bTop;
            });

            return items;
        },

        /**
         * Single source of truth for all counters.
         * total = ALL items (sigs + initials + ceremony + disclosures)
         * incomplete = items still needing user action
         * Pre-filled ceremony fields count as completed (in total, not in incomplete).
         */
        _updateIncompleteCount() {
            let total = 0;
            let incomplete = 0;

            // Always count agent's DB markers (works for both PDF and web templates)
            this.markers.forEach(m => {
                if (m.assigned_party === 'agent') {
                    total++;
                    if (!m.signed) incomplete++;
                }
            });

            if (this.isWebTemplate) {
                // Also count web template HTML-based elements (from inline signature-block includes)
                this.webSigElements.forEach(entry => {
                    if (entry.isMine) {
                        total++;
                        if (!entry.signed) incomplete++;
                    }
                });
                // Initial blocks (mine)
                (this.webInitialElements || []).forEach(entry => {
                    if (entry.isMine) {
                        total++;
                        if (!entry.signed) incomplete++;
                    }
                });
                // Ceremony fields — ALL count in total, only empty ones are incomplete
                const container = this.$refs.webDocContent;
                if (container) {
                    container.querySelectorAll('input[data-ceremony-field="true"]').forEach(inp => {
                        total++;
                        if (!inp.value || !inp.value.trim()) incomplete++;
                    });
                    container.querySelectorAll('button[data-ceremony-field="true"]').forEach(btn => {
                        total++;
                        if (!btn.textContent || !btn.textContent.trim()) incomplete++;
                    });
                }
            }

            // §19 Part A — disclosure rows are gate-counted ONLY for the
            // disclosing owner/seller party. The agent sees the grid
            // read-only and must NOT be blocked on it (PPA s70 — the
            // seller is the sole discloser).
            if (this._signerIsDisclosingParty() && this.totalDisclosureRows > 0) {
                total += this.totalDisclosureRows;
                const answered = Object.keys(this.webDisclosureAnswers)
                    .filter(k => this._isDisclosureAnswerKey(k)).length;
                incomplete += (this.totalDisclosureRows - answered);
            }

            this.totalAgent = total;
            this.incompleteCount = incomplete;
        },

        /**
         * Issue 3: Scroll to the next incomplete field and highlight it.
         */
        scrollToNextIncomplete() {
            const items = this._computeIncompleteItems();
            if (items.length === 0) return;
            const item = items[0];
            if (item.el) {
                item.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                item.el.classList.add('ceremony-pulse', 'pulse-highlight');
                setTimeout(() => {
                    item.el.classList.remove('ceremony-pulse', 'pulse-highlight');
                }, 3000);
                // Focus if it's an input
                if (item.el.tagName === 'INPUT') {
                    setTimeout(() => item.el.focus(), 400);
                }
            }
        },

        /**
         * Open the signature capture modal for a web template element.
         */
        _openWebSigCapture(entry) {
            // Create a synthetic marker object so the existing modal works
            this.activeMarker = {
                id: entry.sigKey,
                type: 'signature',
                assigned_party: 'agent',
                page_number: 1,
                label: 'Agent Signature',
                signed: false,
                _webEntry: entry,
            };
            this.captureMode = 'draw';
            this.typedName = @json($userFullName);
            this.showSignModal = true;
            this.$nextTick(() => this.initCanvas());
        },

        // ── Section signing ──
        get acceptedSections() {
            return this.sectionStates.filter(s => s === 'accepted').length;
        },

        goToSection(idx) {
            if (idx >= 0 && idx < this.totalSections) {
                this.currentSection = idx;
                this.showRejectModal = false;
                this.rejectReasonText = '';
            }
        },

        async acceptCurrentSection() {
            if (this.sectionAccepting) return;
            this.sectionAccepting = true;
            try {
                const resp = await fetch(@json(route('docuperfect.signatures.acceptSection', $document)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ section_index: this.currentSection, section_label: this.sectionLabels[this.currentSection] || '' }),
                });
                const data = await resp.json();
                if (data.ok) {
                    this.sectionStates[this.currentSection] = 'accepted';
                    if (this.currentSection < this.totalSections - 1) {
                        this.currentSection++;
                    }
                }
            } catch (e) { console.error('Section accept error', e); }
            this.sectionAccepting = false;
        },

        rejectCurrentSection() {
            this.showRejectModal = true;
        },

        async confirmRejectSection() {
            if (this.sectionRejecting || !this.rejectReasonText.trim()) return;
            this.sectionRejecting = true;
            try {
                const resp = await fetch(@json(route('docuperfect.signatures.acceptSection', $document)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ section_index: this.currentSection, section_label: this.sectionLabels[this.currentSection] || '', rejected: true, rejection_reason: this.rejectReasonText }),
                });
                const data = await resp.json();
                if (data.ok) {
                    this.sectionStates[this.currentSection] = 'rejected';
                    this.showRejectModal = false;
                    this.rejectReasonText = '';
                }
            } catch (e) { console.error('Section reject error', e); }
            this.sectionRejecting = false;
        },

        // ── Navigation ──
        prevPage() { if (this.currentPage > 1) this.currentPage--; },
        nextPage() { if (this.currentPage < this.totalPages) this.currentPage++; },

        // The server $pageCount is provisional — for a web pack it counts
        // TEMPLATES, not rendered A4 pages. The real total is only known once
        // paginateDocument() has built the .corex-a4-page elements. Re-derive
        // totalPages from the actual paginated DOM (continuous across the
        // whole merged pack — never per-template) on EVERY pagination so the
        // counter and prev/next bounds stay correct after re-pagination too.
        _syncTotalPagesFromPagination(container) {
            const root = container || this.$refs.webDocContent;
            if (!root) return;
            // §19.2 — per-DOCUMENT numbering. A pack is N documents; "Page X
            // of N" reflects the document the signer is currently viewing
            // (the .corex-a4-page nearest the viewport top), N = THAT
            // document's page count (data-doc-total), restarting at 1 per
            // document. Single docs: all pages share one docTotal → 1..N as
            // before. Stays live as the signer scrolls through the pack.
            const recompute = () => {
                const pages = Array.from(root.querySelectorAll('.corex-a4-page'));
                if (pages.length === 0) return;
                let ref = pages[0], best = Infinity;
                pages.forEach(p => {
                    const top = p.getBoundingClientRect().top;
                    const d = top >= -2 ? top : (Math.abs(top) + 1e6);
                    if (d < best) { best = d; ref = p; }
                });
                const docTotal = parseInt(ref.getAttribute('data-doc-total') || '0', 10);
                const pageIdx = parseInt(ref.getAttribute('data-page-index') || '0', 10);
                if (docTotal > 0) {
                    this.totalPages = docTotal;
                    this.currentPage = Math.min(Math.max(pageIdx + 1, 1), docTotal);
                } else {
                    this.totalPages = pages.length;
                    if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
                    if (this.currentPage < 1) this.currentPage = 1;
                }
            };
            recompute();
            if (!this._cxPageScrollBound) {
                this._cxPageScrollBound = true;
                let raf = null;
                const onScroll = () => {
                    if (raf) return;
                    raf = requestAnimationFrame(() => { raf = null; recompute(); });
                };
                let sc = root.parentElement;
                while (sc && sc.scrollHeight <= sc.clientHeight && sc !== document.body) sc = sc.parentElement;
                (sc || window).addEventListener('scroll', onScroll, { passive: true });
                window.addEventListener('scroll', onScroll, { passive: true });
            }
        },

        markersForCurrentPage() {
            return this.markers.filter(m => m.page_number === this.currentPage);
        },

        fieldsForCurrentPage() {
            const pageIdx = this.currentPage - 1;
            return (this.documentFields || []).filter(f => f.pageIndex === pageIdx && f.position && f.size);
        },

        // Detect overlapping fields and offset non-agent fields
        fieldDisplayStyle(field) {
            const isAgent = field.assignedTo === 'agent';
            let x = field.position.x;
            let y = field.position.y;
            const w = field.size.width;
            const h = field.size.height;
            const zIndex = isAgent ? 8 : 5;

            // If this is NOT the agent's field, check if it overlaps with any agent field
            if (!isAgent) {
                const pageFields = this.fieldsForCurrentPage();
                let overlapCount = 0;
                for (const other of pageFields) {
                    if (other.id === field.id) continue;
                    if (other.assignedTo !== 'agent') continue;
                    const ox = other.position.x, oy = other.position.y;
                    // Within 2% = overlapping
                    if (Math.abs(x - ox) < 2 && Math.abs(y - oy) < 2) {
                        overlapCount++;
                    }
                }
                if (overlapCount > 0) {
                    y = y + (h + 0.5) * overlapCount;
                }
            }

            return `left:${x}%;top:${y}%;width:${w}%;height:${h}%;z-index:${zIndex};`;
        },

        signerLabel(role) {
            const labels = { agent: 'Agent', tenant: 'Tenant', landlord: 'Landlord', buyer: 'Buyer', seller: 'Seller', lessor: 'Landlord', lessee: 'Tenant' };
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

            // Web template initials: apply to the initial element directly
            if (this.isWebTemplate && this.activeMarker._isWebInitial) {
                const entry = this.activeMarker._webInitialEntry;
                entry.signed = true;
                entry.sigData = signatureData;

                // Update the DOM element
                entry.el.style.border = '2px solid #10b981';
                entry.el.style.background = 'rgba(16,185,129,0.06)';
                entry.el.style.cursor = 'default';
                entry.el.innerHTML = '<img src="' + signatureData + '" style="max-height:26px;max-width:56px;object-fit:contain;" alt="Initial">';

                // Also set data attribute on DOM element for robust checking
                entry.el.setAttribute('data-signed', 'true');

                this.webSigSigned++;
                this.showSignModal = false;

                // Offer apply-to-all for remaining agent initials
                const remainingInitials = (this.webInitialElements || []).filter(e => e.isMine && !e.signed);
                if (remainingInitials.length > 0 && !this._initialsApplyAllOffered) {
                    this._initialsApplyAllOffered = true;
                    this.lastSignatureData = signatureData;
                    this.lastSignatureType = signatureType;
                    this._pendingInitialApplyAll = true;
                    this.showApplyAll = true;
                }

                this.applying = false;
                this._updateIncompleteCount();
                return;
            }

            // Web template: apply signature to the DOM element directly
            if (this.isWebTemplate && this.activeMarker._webEntry) {
                const entry = this.activeMarker._webEntry;
                entry.signed = true;
                entry.sigData = signatureData;
                this.webSignatures[entry.sigKey] = signatureData;

                // Update the DOM element
                entry.el.classList.add('web-sig-signed');
                entry.el.setAttribute('data-signed', 'true');
                entry.el.innerHTML = '<img src="' + signatureData + '" class="web-sig-signed-img" alt="Signature">';

                this.webSigSigned++;
                this.showSignModal = false;

                // Offer apply-to-all for remaining agent sig elements
                const remaining = this.webSigElements.filter(e => e.isMine && !e.signed);
                if (!this.firstSignatureDone && remaining.length > 0) {
                    this.lastSignatureData = signatureData;
                    this.lastSignatureType = signatureType;
                    this.showApplyAll = true;
                }

                this.firstSignatureDone = true;
                this.applying = false;
                this._updateIncompleteCount();
                return;
            }

            // PDF template: submit to server per marker
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

                    // Update all counts from single computation
                    this._updateIncompleteCount();
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

            if (this.isWebTemplate) {
                const sigData = this.lastSignatureData;
                if (!sigData) {
                    console.error('APPLY_ALL: No signature data available');
                    this.applyingAll = false;
                    this.showApplyAll = false;
                    return;
                }

                // Apply to initials if this was triggered from an initial capture
                if (this._pendingInitialApplyAll && this.webInitialElements) {
                    const remainingInitials = this.webInitialElements.filter(e => e.isMine && !e.signed);
                    for (const entry of remainingInitials) {
                        try {
                            entry.signed = true;
                            entry.sigData = sigData;
                            const el = entry.el;
                            if (el) {
                                el.style.border = '2px solid #10b981';
                                el.style.background = 'rgba(16,185,129,0.06)';
                                el.style.cursor = 'default';
                                el.setAttribute('data-signed', 'true');
                                el.innerHTML = '<img src="' + sigData + '" style="max-height:26px;max-width:56px;object-fit:contain;" alt="Initial">';
                            }
                            this.webSigSigned++;
                        } catch (err) {
                            console.error('APPLY_ALL_INITIALS: Failed', err);
                        }
                    }
                    this._pendingInitialApplyAll = false;
                } else {
                    // Web template: apply to all remaining agent sig elements
                    const remaining = this.webSigElements.filter(e => e.isMine && !e.signed);

                    for (const entry of remaining) {
                        try {
                            entry.signed = true;
                            entry.sigData = sigData;
                            this.webSignatures[entry.sigKey] = sigData;

                            const el = entry.el;
                            if (el && el.classList) {
                                el.classList.add('web-sig-signed');
                                el.setAttribute('data-signed', 'true');
                                el.innerHTML = '<img src="' + sigData + '" class="web-sig-signed-img" alt="Signature">';
                            }
                            this.webSigSigned++;
                        } catch (err) {
                            console.error('APPLY_ALL: Failed to apply signature to', entry.sigKey, err);
                        }
                    }
                }
            } else {
                const remainingSignatures = this.markers.filter(m =>
                    m.assigned_party === 'agent' &&
                    !m.signed &&
                    m.type === 'signature'
                );

                for (const marker of remainingSignatures) {
                    const success = await this.submitSignature(marker, this.lastSignatureData, this.lastSignatureType);
                    if (!success) break;
                }
            }

            this.showApplyAll = false;
            this.lastSignatureData = null;
            this.applyingAll = false;
            this._updateIncompleteCount();
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
            // Web template flow: check all incomplete items (sigs + ceremony fields + markers)
            if (this.isWebTemplate) {
                this._updateIncompleteCount();
                const incomplete = this._computeIncompleteItems();
                if (incomplete.length > 0) {
                    const labels = [...new Set(incomplete.map(i => i.label))];
                    alert(`Please complete all fields: ${labels.join(', ')} (${incomplete.length} remaining)`);
                    this.scrollToNextIncomplete();
                    return;
                }

                // If only DB markers were used (no inline HTML signatures), use the
                // standard signComplete POST — marker signatures are already saved per-capture.
                const hasWebSignatures = Object.keys(this.webSignatures || {}).length > 0;
                const hasAgentMarkers = this.markers.some(m => m.assigned_party === 'agent');
                if (!hasWebSignatures && hasAgentMarkers) {
                    // Marker-only web template: use PDF-style completion
                    if (this.completingForm) return;
                    this.completingForm = true;

                    const agentFields = (this.documentFields || []).filter(f => f.assignedTo === 'agent');
                    if (agentFields.length > 0) {
                        const saved = await this.saveAgentFields();
                        if (!saved) { this.completingForm = false; return; }
                    }

                    this.completionDone = true;
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = @json(route('docuperfect.signatures.signComplete', $document));
                    const csrf = document.createElement('input');
                    csrf.type = 'hidden'; csrf.name = '_token';
                    csrf.value = @json(csrf_token());
                    form.appendChild(csrf);
                    document.body.appendChild(form);
                    form.submit();
                    return;
                }

                if (this.completingForm) return;
                this.completingForm = true;

                // Save agent-assigned field values before completing
                const agentFields = (this.documentFields || []).filter(f => f.assignedTo === 'agent');
                if (agentFields.length > 0) {
                    const saved = await this.saveAgentFields();
                    if (!saved) { this.completingForm = false; return; }
                }

                // Submit all web signatures to server
                const url = @json(route('docuperfect.signatures.webSignComplete', $document));
                // Collect initials data from page break markers
                const initialsData = {};
                (this.webInitialElements || []).forEach(entry => {
                    if (entry.isMine && entry.signed && entry.sigData) {
                        initialsData[entry.initKey] = entry.sigData;
                    }
                });

                const payload = {
                    signatures: this.webSignatures,
                    initials: initialsData,
                    // §19 Part A — persist disclosure answers on the agent's
                    // submit so the seller's selections are never dropped
                    // (the agent does not fill them; this just carries them).
                    disclosure_answers: this.webDisclosureAnswers,
                    party_role: 'agent',
                    ceremony_values: this.webCeremonyValues || {},
                    esign_flow_id: @json($esignFlowId ?? null),
                    // §19.7 — send the EXACT signed-and-paginated DOM (per-doc
                    // .corex-a4-page + per-page initial slots, signatures/initials
                    // already rendered as <img>). The server uses this verbatim
                    // as merged_html (no server re-pagination) so the PDF/split
                    // carry per-page initials exactly as the signer saw them.
                    paginated_html: this.$refs.webDocContent ? this.$refs.webDocContent.innerHTML : null,
                };
                try {
                    const resp = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await resp.json();
                    if (data.ok && data.redirect) {
                        this.completionDone = true;
                        window.location.href = data.redirect;
                    } else if (data.ok) {
                        this.completionDone = true;
                        // Fallback: use the standard signComplete POST
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = @json(route('docuperfect.signatures.signComplete', $document));
                        const csrf = document.createElement('input');
                        csrf.type = 'hidden'; csrf.name = '_token';
                        csrf.value = @json(csrf_token());
                        form.appendChild(csrf);
                        document.body.appendChild(form);
                        form.submit();
                    } else {
                        console.error('COMPLETE_FAIL', data);
                        alert(data.error || 'Failed to complete signing.');
                        this.completingForm = false;
                    }
                } catch (err) {
                    console.error('COMPLETE_ERROR', err);
                    // If completion already succeeded server-side but redirect failed, show success
                    if (this.completionDone) return;
                    alert('Network error: ' + err.message + '. Please try again.');
                    this.completingForm = false;
                }
                return;
            }

            // PDF template flow: check markers
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
            this.completionDone = true;
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
            if (this.isWebTemplate) {
                this.scrollToNextIncomplete();
                return;
            }
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
