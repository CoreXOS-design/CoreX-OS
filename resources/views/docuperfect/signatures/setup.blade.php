{{-- CORRECT FILE v2 --}}
@extends('layouts.corex')

@section('corex-content')
@include('docuperfect.signatures.partials.a4-page-styles')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    @php
        $isSalesTemplate = ($templateType ?? 'rentals') === 'sales';
        $esignFlowId = $esignFlowId ?? session('esign_wizard_flow_id');
        if ($esignFlowId) {
            $backRoute = route('docuperfect.esign.step', ['flow' => $esignFlowId, 'step' => 6]);
            $backLabel = 'Back to E-Sign';
        } else {
            $backRoute = $isSalesTemplate ? route('docuperfect.sales') : route('docuperfect.rental');
            $backLabel = $isSalesTemplate ? 'Back to Sales' : 'Back to Rental';
        }
        $partyOneRole = $isSalesTemplate ? 'buyer' : 'tenant';
        $partyTwoRole = $isSalesTemplate ? 'seller' : 'landlord';
        $partyOneLabel = ucfirst($partyOneRole);
        $partyTwoLabel = $isSalesTemplate ? 'Seller' : 'Landlord / Owner';
        $partyOneOrder = 'Signs Second';
        $partyTwoOrder = 'Signs Third';
    @endphp

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                {{ $backLabel }}
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">{{ $document->name }} &mdash; @if($step === 1) Step 1: Parties @else Step 2: Markers @endif</h2>
        </x-slot>
        <x-slot name="right">
            @if($step === 2)
                <a href="{{ route('docuperfect.signatures.setup', [$document, 'step' => 1]) }}"
                   class="px-3 py-1.5 text-sm bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200">
                    Edit Parties
                </a>
            @endif
        </x-slot>
    </x-sticky-action-bar>

    {{-- Header info is in the sticky action bar above — no duplicate --}}

    {{-- Flash messages handled by global toast system --}}
    @if($errors->any())
        <div class="rounded-sm border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════
         STEP 1: Party Configuration
         ═══════════════════════════════════════════════ --}}
    @if($step === 1)
    <div class="ds-status-card p-6" x-data="{
        addPartyOneWitness: {{ !empty(collect($parties)->firstWhere('role', $partyOneRole . '_witness')) ? 'true' : 'false' }},
        addPartyTwoWitness: {{ !empty(collect($parties)->firstWhere('role', $partyTwoRole . '_witness')) ? 'true' : 'false' }},
        partyOneNotRequired: {{ old($partyOneRole . '_not_required') ? 'true' : (collect($parties)->isNotEmpty() && !collect($parties)->firstWhere('role', $partyOneRole) ? 'true' : 'false') }},
        partyTwoNotRequired: {{ old($partyTwoRole . '_not_required') ? 'true' : (collect($parties)->isNotEmpty() && !collect($parties)->firstWhere('role', $partyTwoRole) ? 'true' : 'false') }},
        submittingParties: false
    }">
        <form action="{{ route('docuperfect.signatures.saveParties', $document) }}" method="POST"
              @submit="if (submittingParties) { $event.preventDefault(); return; } submittingParties = true;">
            @csrf

            <h3 class="text-lg font-semibold text-slate-800 mb-4">Signing Parties</h3>

            {{-- Agent --}}
            @if(!empty($template->flattened_pages_json) && empty($parties))
                {{-- Pre-signed wet ink upload — agent section is display-only --}}
                <div class="mb-6 p-4 rounded-sm border border-emerald-200 bg-emerald-50/50">
                    <div class="flex items-center gap-2 mb-1">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-sm font-semibold text-emerald-700 uppercase tracking-wider">Agent (Pre-signed — Wet Ink Upload)</span>
                    </div>
                    <p class="text-xs text-emerald-600 ml-7">Agent pre-signed document was uploaded. No electronic signature required.</p>
                    <input type="hidden" name="agent_name" value="{{ $user->name }}">
                    <input type="hidden" name="agent_email" value="{{ $user->email }}">
                </div>
            @else
                <div class="mb-6 p-4 rounded-sm border border-blue-200 bg-blue-50/50">
                    <div class="text-sm font-semibold text-blue-700 mb-3 uppercase tracking-wider">Agent (Signs First)</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                            <input type="text" name="agent_name"
                                   value="{{ old('agent_name', collect($parties)->firstWhere('role', 'agent')['name'] ?? $user->name) }}"
                                   class="w-full rounded-lg border-slate-300 bg-slate-100 text-sm px-3 py-2" readonly>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                            <input type="email" name="agent_email"
                                   value="{{ old('agent_email', collect($parties)->firstWhere('role', 'agent')['email'] ?? $user->email) }}"
                                   class="w-full rounded-lg border-slate-300 bg-slate-100 text-sm px-3 py-2" readonly>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Party One (Tenant for rental, Buyer for sales) --}}
            <div class="mb-6 p-4 rounded-sm border border-green-200" :class="partyOneNotRequired ? 'bg-gray-50 opacity-60' : 'bg-green-50/50'">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-semibold text-green-700 uppercase tracking-wider">{{ $partyOneLabel }} ({{ $partyOneOrder }})</div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" name="{{ $partyOneRole }}_not_required" value="1"
                               x-model="partyOneNotRequired"
                               @change="if(partyOneNotRequired) { addPartyOneWitness = false; }"
                               class="rounded border-gray-300 text-gray-500 focus:ring-gray-400">
                        <span class="text-gray-500 text-xs">Not required for this document</span>
                    </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4" :class="{ 'pointer-events-none': partyOneNotRequired }">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Name <span x-show="!partyOneNotRequired" class="text-red-500">*</span></label>
                        <input type="text" name="{{ $partyOneRole }}_name" :required="!partyOneNotRequired" :disabled="partyOneNotRequired"
                               value="{{ old($partyOneRole . '_name', collect($parties)->firstWhere('role', $partyOneRole)['name'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-green-500 focus:border-green-500"
                               :class="{ 'bg-gray-100': partyOneNotRequired }"
                               placeholder="Full name">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Email <span x-show="!partyOneNotRequired" class="text-red-500">*</span></label>
                        <input type="email" name="{{ $partyOneRole }}_email" :required="!partyOneNotRequired" :disabled="partyOneNotRequired"
                               value="{{ old($partyOneRole . '_email', collect($parties)->firstWhere('role', $partyOneRole)['email'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-green-500 focus:border-green-500"
                               :class="{ 'bg-gray-100': partyOneNotRequired }"
                               placeholder="{{ $partyOneRole }}@email.com">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">ID Number <span class="text-slate-400">(optional)</span></label>
                        <input type="text" name="{{ $partyOneRole }}_id_number" :disabled="partyOneNotRequired"
                               value="{{ old($partyOneRole . '_id_number', collect($parties)->firstWhere('role', $partyOneRole)['id_number'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-green-500 focus:border-green-500"
                               :class="{ 'bg-gray-100': partyOneNotRequired }"
                               placeholder="SA ID number" maxlength="20">
                    </div>
                </div>

                <div x-show="!partyOneNotRequired" x-cloak>
                    <label class="flex items-center gap-2 mt-3 cursor-pointer text-sm text-slate-600">
                        <input type="checkbox" name="add_{{ $partyOneRole }}_witness" value="1"
                               x-model="addPartyOneWitness"
                               class="rounded border-slate-300 text-green-600 focus:ring-green-500">
                        Add witness for {{ $partyOneLabel }}
                    </label>

                    <div x-show="addPartyOneWitness" x-cloak x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3 pl-6 border-l-2 border-green-200">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Witness Name</label>
                            <input type="text" name="{{ $partyOneRole }}_witness_name"
                                   value="{{ old($partyOneRole . '_witness_name', collect($parties)->firstWhere('role', $partyOneRole . '_witness')['name'] ?? '') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm px-3 py-2" placeholder="Witness full name"
                                   :required="addPartyOneWitness && !partyOneNotRequired">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Witness Email</label>
                            <input type="email" name="{{ $partyOneRole }}_witness_email"
                                   value="{{ old($partyOneRole . '_witness_email', collect($parties)->firstWhere('role', $partyOneRole . '_witness')['email'] ?? '') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm px-3 py-2" placeholder="witness@email.com"
                                   :required="addPartyOneWitness && !partyOneNotRequired">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Party Two (Landlord for rental, Seller for sales) --}}
            <div class="mb-6 p-4 rounded-sm border border-orange-200" :class="partyTwoNotRequired ? 'bg-gray-50 opacity-60' : 'bg-orange-50/50'">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-semibold text-orange-700 uppercase tracking-wider">{{ $partyTwoLabel }} ({{ $partyTwoOrder }})</div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" name="{{ $partyTwoRole }}_not_required" value="1"
                               x-model="partyTwoNotRequired"
                               @change="if(partyTwoNotRequired) { addPartyTwoWitness = false; }"
                               class="rounded border-gray-300 text-gray-500 focus:ring-gray-400">
                        <span class="text-gray-500 text-xs">Not required for this document</span>
                    </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4" :class="{ 'pointer-events-none': partyTwoNotRequired }">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Name <span x-show="!partyTwoNotRequired" class="text-red-500">*</span></label>
                        <input type="text" name="{{ $partyTwoRole }}_name" :required="!partyTwoNotRequired" :disabled="partyTwoNotRequired"
                               value="{{ old($partyTwoRole . '_name', collect($parties)->firstWhere('role', $partyTwoRole)['name'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-orange-500 focus:border-orange-500"
                               :class="{ 'bg-gray-100': partyTwoNotRequired }"
                               placeholder="Full name">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Email <span x-show="!partyTwoNotRequired" class="text-red-500">*</span></label>
                        <input type="email" name="{{ $partyTwoRole }}_email" :required="!partyTwoNotRequired" :disabled="partyTwoNotRequired"
                               value="{{ old($partyTwoRole . '_email', collect($parties)->firstWhere('role', $partyTwoRole)['email'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-orange-500 focus:border-orange-500"
                               :class="{ 'bg-gray-100': partyTwoNotRequired }"
                               placeholder="{{ $partyTwoRole }}@email.com">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">ID Number <span class="text-slate-400">(optional)</span></label>
                        <input type="text" name="{{ $partyTwoRole }}_id_number" :disabled="partyTwoNotRequired"
                               value="{{ old($partyTwoRole . '_id_number', collect($parties)->firstWhere('role', $partyTwoRole)['id_number'] ?? '') }}"
                               class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-orange-500 focus:border-orange-500"
                               :class="{ 'bg-gray-100': partyTwoNotRequired }"
                               placeholder="SA ID number" maxlength="20">
                    </div>
                </div>

                <div x-show="!partyTwoNotRequired" x-cloak>
                    <label class="flex items-center gap-2 mt-3 cursor-pointer text-sm text-slate-600">
                        <input type="checkbox" name="add_{{ $partyTwoRole }}_witness" value="1"
                               x-model="addPartyTwoWitness"
                               class="rounded border-slate-300 text-orange-600 focus:ring-orange-500">
                        Add witness for {{ $partyTwoLabel }}
                    </label>

                    <div x-show="addPartyTwoWitness" x-cloak x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3 pl-6 border-l-2 border-orange-200">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Witness Name</label>
                            <input type="text" name="{{ $partyTwoRole }}_witness_name"
                                   value="{{ old($partyTwoRole . '_witness_name', collect($parties)->firstWhere('role', $partyTwoRole . '_witness')['name'] ?? '') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm px-3 py-2" placeholder="Witness full name"
                                   :required="addPartyTwoWitness && !partyTwoNotRequired">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Witness Email</label>
                            <input type="email" name="{{ $partyTwoRole }}_witness_email"
                                   value="{{ old($partyTwoRole . '_witness_email', collect($parties)->firstWhere('role', $partyTwoRole . '_witness')['email'] ?? '') }}"
                                   class="w-full rounded-lg border-slate-300 text-sm px-3 py-2" placeholder="witness@email.com"
                                   :required="addPartyTwoWitness && !partyTwoNotRequired">
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5"
                        :disabled="submittingParties"
                        :class="submittingParties ? 'opacity-50 cursor-not-allowed' : ''">
                    <span x-show="!submittingParties">Save Parties & Continue to Marker Placement</span>
                    <span x-show="submittingParties" x-cloak>Saving...</span>
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════
         STEP 2: Marker Placement
         ═══════════════════════════════════════════════ --}}
    @if($step === 2)
    <div x-data="markerPlacement()" x-init="init()" class="space-y-4">

        {{-- Party summary bar --}}
        <div class="ds-status-card p-4 flex flex-wrap items-center gap-4 text-sm">
            @foreach($parties as $party)
                @php $baseRole = $party['role_label'] ?? preg_replace('/_\d+$/', '', $party['role']); @endphp
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium
                    @if($party['role'] === 'agent') bg-blue-100 text-blue-700
                    @elseif(in_array($baseRole, ['tenant', 'buyer'])) bg-green-100 text-green-700
                    @elseif(in_array($baseRole, ['landlord', 'seller'])) bg-orange-100 text-orange-700
                    @else bg-purple-100 text-purple-700
                    @endif">
                    {{ ucfirst(str_replace('_', ' ', $baseRole)) }}: {{ $party['name'] }}
                </span>
            @endforeach
        </div>

        <div class="flex gap-4" style="height:calc(100vh - 240px); min-height:400px;">
            {{-- LEFT: Document pages --}}
            <div class="flex-1 ds-status-card p-4 overflow-hidden flex flex-col">

                @if($isWebTemplate ?? false)
                {{-- Web template: document preview — signature elements are visible in the HTML --}}
                {{-- Zone/marker overlays render after the HTML content for ad-hoc markers --}}
                <div class="flex-1 overflow-y-auto" style="background:#f1f5f9;">
                    <link href="/css/corex-document.css" rel="stylesheet">
                    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
                    <style>
                        #webDocContent .corex-page {
                            min-height: auto !important;
                        }
                        /* Highlight signature areas in setup preview */
                        #webDocContent [data-marker-party][data-marker-type="signature"] {
                            border: 2px dashed #94a3b8 !important;
                            background: rgba(148,163,184,0.08) !important;
                            min-height: 28pt;
                        }
                    </style>
                    <div class="relative" style="max-width:100%; margin:0 auto;"
                         x-ref="pageContainer"
                         x-init="pageLoaded = true; $nextTick(() => paginateDocument(document.getElementById('webDocContent'), @json(collect($parties ?? [])->map(fn($p) => ['role' => $p['role'] ?? 'unknown', 'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? 'unknown'))])->values()->toArray())))"
                         @dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                         @drop.prevent="handleDrop($event)"
                         @mousedown.prevent="startZoneDrawOnPage($event)">

                        <div id="webDocContent">
                            {!! $webTemplateHtml ?? '' !!}
                        </div>
                @else
                {{-- PDF template: page navigation + page images --}}
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

                {{-- Page display --}}
                <div class="flex-1 overflow-auto flex justify-center items-start" style="background:#e2e8f0;">
                    <div class="relative inline-block" style="max-width:800px; width:100%;"
                         x-ref="pageContainer"
                         @dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                         @drop.prevent="handleDrop($event)"
                         @mousedown.prevent="startZoneDrawOnPage($event)">

                        <img :src="pageImages[currentPage - 1]"
                             class="w-full block select-none pointer-events-none"
                             draggable="false"
                             @load="pageLoaded = true"
                             x-ref="pageImage">
                @endif

                        {{-- Render document field values (read-only overlay) — only when NOT flattened (PDF only) --}}
                        @if(!($isWebTemplate ?? false))
                        <template x-if="!hasFlattened">
                        <template x-for="field in fieldsForCurrentPage()" :key="field.id">
                            <div class="absolute pointer-events-none overflow-hidden"
                                 :style="`left:${field.position.x}%;top:${field.position.y}%;width:${field.size.width}%;height:${field.size.height}%;z-index:5;`">
                                {{-- Text/placeholder values --}}
                                <template x-if="field.type === 'placeholder' && field.value">
                                    <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)"
                                         x-text="field.value"></div>
                                </template>
                                {{-- Date values --}}
                                <template x-if="field.type === 'date' && field.value">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)"
                                         x-text="field.value"></div>
                                </template>
                                {{-- Selection values --}}
                                <template x-if="field.type === 'selection' && field.selectedValue">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                         :style="fieldStyle(field)">
                                        <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs" x-text="field.selectedValue"></span>
                                    </div>
                                </template>
                                {{-- Condition/clause text --}}
                                <template x-if="field.type === 'condition' && field.text">
                                    <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                         :style="fieldStyle(field)"
                                         x-text="field.text"></div>
                                </template>
                                {{-- Active strikethrough --}}
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
                                {{-- Signature/initial line --}}
                                <template x-if="field.type === 'signature' || field.type === 'initial'">
                                    <div class="w-full h-full flex flex-col justify-end p-0.5">
                                        <div class="border-b border-black mb-0.5"></div>
                                        <div class="text-[8px] uppercase text-gray-500" x-text="field.type === 'initial' ? 'Initial' : 'Signature'"></div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        </template>
                        @endif

                        {{-- Render dynamic signature zones for current page (all template types) --}}
                        <template x-for="zone in zonesForCurrentPage()" :key="'zone_' + zone.id">
                            <div class="absolute select-none"
                                 :style="`left:${zone.x_position}%;top:${zone.y_position}%;width:${zone.width}%;height:50px;z-index:5;`"
                                 style="border:2px dashed rgba(99,102,241,0.6); background:rgba(99,102,241,0.05); cursor:grab;"
                                 @mousedown.prevent="startZoneDrag($event, zone)">

                                {{-- Zone label --}}
                                <div class="absolute -top-5 left-0 px-2 py-0.5 rounded text-[10px] font-semibold bg-indigo-500 text-white pointer-events-none whitespace-nowrap"
                                     style="line-height:1.3;"
                                     x-text="zone.label || (zone.party_role + ' ' + zone.zone_type)"></div>

                                {{-- Zone info badge --}}
                                <div class="absolute top-1 left-1 px-1.5 py-0.5 rounded text-[9px] font-medium bg-indigo-100 text-indigo-700 pointer-events-none"
                                     x-text="(zone.marker_count || 0) + ' block' + ((zone.marker_count || 0) !== 1 ? 's' : '')"></div>

                                {{-- Delete zone button --}}
                                <button class="absolute -top-2 -right-2 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center text-xs hover:bg-red-600 shadow"
                                        @click.stop="removeZone(zone)"
                                        style="cursor:pointer; z-index:20; line-height:1;">
                                    &times;
                                </button>

                                {{-- Resize zone handle --}}
                                <div class="absolute bottom-0 right-0 w-3 h-3 cursor-se-resize"
                                     style="z-index:20;"
                                     @mousedown.stop.prevent="startZoneResize($event, zone)">
                                    <svg viewBox="0 0 10 10" class="w-full h-full opacity-60"><path d="M9 1L1 9M9 5L5 9" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                                </div>
                            </div>
                        </template>

                        {{-- Zone drawing preview --}}
                        <template x-if="_zoneDrawState">
                            <div class="absolute border-2 border-dashed border-indigo-500 bg-indigo-50/30 pointer-events-none"
                                 :style="`left:${_zoneDrawState.x}%;top:${_zoneDrawState.y}%;width:${_zoneDrawState.w}%;height:${_zoneDrawState.h}%;z-index:15;`">
                            </div>
                        </template>

                        {{-- Render markers for current page (PDF only) --}}
                        <template x-for="(marker, idx) in markersForCurrentPage()" :key="marker._id">
                            <div class="absolute flex items-center justify-center text-xs font-medium select-none"
                                 :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${Math.min(marker.width, 50)}%;height:40px;max-width:200px;opacity:0.7;z-index:10;`"
                                 :class="markerClasses(marker)"
                                 @mousedown.prevent="startDrag($event, marker)"
                                 style="cursor:grab;">

                                {{-- Label --}}
                                <span class="truncate px-1 pointer-events-none" style="font-size:9px;line-height:1.2;" x-text="markerLabel(marker)"></span>
                                <template x-if="marker.auto_placed">
                                    <span class="absolute -top-2 -left-1 px-1 py-0 rounded text-[8px] font-bold bg-indigo-500 text-white pointer-events-none" style="line-height:1.3;">Auto</span>
                                </template>

                                {{-- Delete button --}}
                                <button class="absolute -top-2 -right-2 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center text-xs hover:bg-red-600 shadow"
                                        @click.stop="removeMarker(marker._id)"
                                        style="cursor:pointer; z-index:20; line-height:1;">
                                    &times;
                                </button>

                                {{-- Resize handle --}}
                                <div class="absolute bottom-0 right-0 w-3 h-3 cursor-se-resize"
                                     style="z-index:20;"
                                     @mousedown.stop.prevent="startResize($event, marker)">
                                    <svg viewBox="0 0 10 10" class="w-full h-full opacity-60"><path d="M9 1L1 9M9 5L5 9" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Toolbar — identical for web and PDF templates --}}
            <div class="w-72 flex-shrink-0 ds-status-card flex flex-col">
                {{-- Scrollable content area --}}
                <div class="flex-1 overflow-y-auto p-4 min-h-0">

                    {{-- ═══ ZONE MODE: Draw Signature Zones ═══ --}}
                    <div class="mb-4 pb-4 border-b border-slate-200">
                        <h4 class="text-sm font-semibold text-slate-800 mb-2">Signature Zones</h4>
                        <p class="text-xs text-slate-500 mb-3">Draw a rectangle on the document. System auto-places blocks for each party of that role.</p>

                        <button @click="zoneDrawMode = !zoneDrawMode"
                                :class="zoneDrawMode ? 'bg-indigo-600 text-white ring-2 ring-indigo-300' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                                class="w-full rounded-lg border border-indigo-200 px-3 py-2 text-xs font-medium text-center transition-colors mb-3">
                            <span x-show="!zoneDrawMode">Draw Zone</span>
                            <span x-show="zoneDrawMode" x-cloak>Drawing... (click & drag on document)</span>
                        </button>

                        <template x-if="zoneDrawMode">
                            <div class="space-y-2" x-cloak>
                                <div>
                                    <label class="block text-[10px] font-medium text-slate-500 mb-1">Zone Type</label>
                                    <select x-model="zoneType" class="w-full text-xs border-slate-200 rounded-lg px-2 py-1.5 focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="signature">Signature</option>
                                        <option value="initial">Initial</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-slate-500 mb-1">Party Role</label>
                                    <select x-model="zoneRole" class="w-full text-xs border-slate-200 rounded-lg px-2 py-1.5 focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="agent">Agent</option>
                                        <option value="seller">Seller</option>
                                        <option value="buyer">Buyer</option>
                                        <option value="landlord">Landlord</option>
                                        <option value="tenant">Tenant</option>
                                        <option value="witness">Witness</option>
                                        <option value="supervisor">Supervisor</option>
                                    </select>
                                </div>
                            </div>
                        </template>

                        {{-- Placed zones list --}}
                        <template x-if="zones.length > 0">
                            <div class="mt-3 space-y-1" x-cloak>
                                <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Placed Zones</div>
                                <template x-for="zone in zones" :key="zone.id">
                                    <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-lg bg-indigo-50/50 hover:bg-indigo-50 group">
                                        <div class="flex items-center gap-1.5 min-w-0">
                                            <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                            <span class="truncate" x-text="zone.label || (zone.party_role + ' ' + zone.zone_type)"></span>
                                            <span class="text-slate-400 flex-shrink-0" x-text="'Pg ' + zone.page_number"></span>
                                            <span class="text-indigo-500 flex-shrink-0 text-[9px]" x-text="(zone.marker_count || 0) + ' blocks'"></span>
                                        </div>
                                        <button @click="removeZone(zone)" class="text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 flex-shrink-0 ml-1">&times;</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- ═══ MARKER MODE: Individual Markers ═══ --}}
                    <h4 class="text-sm font-semibold text-slate-800 mb-3">Place Markers</h4>
                    <p class="text-xs text-slate-500 mb-4">For ad-hoc fields: drag a marker type onto the document.</p>

                    {{-- Marker type (drag onto document to place) --}}
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-slate-600 mb-2">Marker Type <span class="text-slate-400 font-normal">(drag onto document)</span></label>
                        <div class="grid grid-cols-2 gap-2">
                            <button @click="selectedType = 'signature'" draggable="true"
                                    @dragstart="$event.dataTransfer.setData('marker-type', 'signature'); $event.dataTransfer.effectAllowed = 'copy'; selectedType = 'signature';"
                                    :class="selectedType === 'signature' ? 'ring-2 ring-cyan-500 bg-cyan-50' : 'bg-slate-50 hover:bg-slate-100'"
                                    class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-center transition-colors cursor-grab active:cursor-grabbing">
                                Signature
                            </button>
                            <button @click="selectedType = 'initial'" draggable="true"
                                    @dragstart="$event.dataTransfer.setData('marker-type', 'initial'); $event.dataTransfer.effectAllowed = 'copy'; selectedType = 'initial';"
                                    :class="selectedType === 'initial' ? 'ring-2 ring-cyan-500 bg-cyan-50' : 'bg-slate-50 hover:bg-slate-100'"
                                    class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-center transition-colors cursor-grab active:cursor-grabbing">
                                Initial
                            </button>
                            <button @click="selectedType = 'date'" draggable="true"
                                    @dragstart="$event.dataTransfer.setData('marker-type', 'date'); $event.dataTransfer.effectAllowed = 'copy'; selectedType = 'date';"
                                    :class="selectedType === 'date' ? 'ring-2 ring-cyan-500 bg-cyan-50' : 'bg-slate-50 hover:bg-slate-100'"
                                    class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-center transition-colors cursor-grab active:cursor-grabbing">
                                Date
                            </button>
                            <button @click="selectedType = 'text'" draggable="true"
                                    @dragstart="$event.dataTransfer.setData('marker-type', 'text'); $event.dataTransfer.effectAllowed = 'copy'; selectedType = 'text';"
                                    :class="selectedType === 'text' ? 'ring-2 ring-cyan-500 bg-cyan-50' : 'bg-slate-50 hover:bg-slate-100'"
                                    class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-center transition-colors cursor-grab active:cursor-grabbing">
                                Text Field
                            </button>
                        </div>
                    </div>

                    {{-- Assign to party --}}
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-slate-600 mb-2">Assign To</label>
                        <div class="space-y-1.5">
                            <template x-for="opt in partyOptions" :key="opt.value">
                                <label class="flex items-center gap-2 cursor-pointer text-sm rounded-lg px-3 py-1.5 transition-colors"
                                       :class="selectedParty === opt.value ? 'bg-slate-100 font-medium' : 'hover:bg-slate-50'">
                                    <input type="radio" name="assign_party" :value="opt.value"
                                           x-model="selectedParty"
                                           class="text-cyan-600 focus:ring-cyan-500">
                                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="opt.dotClass"></span>
                                    <span x-text="opt.label"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    {{-- Drag hint --}}
                    <div class="w-full rounded-lg px-4 py-2.5 text-xs text-center mb-4 bg-slate-100 text-slate-500 border border-dashed border-slate-300">
                        Drag a marker type above onto the document page to place it
                    </div>

                    <hr class="border-slate-200 my-3">

                    {{-- Placed markers list --}}
                    <h4 class="text-sm font-semibold text-slate-800 mb-2">
                        Placed Markers <span class="text-slate-400 font-normal" x-text="'(' + markers.length + ')'"></span>
                    </h4>
                    <div class="space-y-1">
                        <template x-if="markers.length === 0">
                            <div class="text-xs text-slate-400 italic py-4 text-center">No markers placed yet.</div>
                        </template>
                        <template x-for="(marker, idx) in markers" :key="marker._id">
                            <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-lg hover:bg-slate-50 group"
                                 :class="marker.page_number === currentPage ? 'bg-slate-50' : ''">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-2 h-2 rounded-full flex-shrink-0" :class="partyDotClass(marker.assigned_party)"></span>
                                    <span x-show="marker.from_template" class="inline-flex items-center px-1 py-0 rounded text-[9px] font-semibold bg-indigo-100 text-indigo-600 flex-shrink-0" title="From template zone">[T]</span>
                                    <span x-show="marker.auto_placed" class="inline-flex items-center px-1 py-0 rounded text-[9px] font-semibold bg-indigo-100 text-indigo-600 flex-shrink-0" title="Auto-placed from document">Auto</span>
                                    <span class="truncate" x-text="markerLabel(marker)"></span>
                                    <span class="text-slate-400 flex-shrink-0" x-text="'Pg ' + marker.page_number"></span>
                                </div>
                                <button @click="removeMarker(marker._id)" class="text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 flex-shrink-0 ml-1">&times;</button>
                            </div>
                        </template>
                    </div>

                    {{-- Status messages --}}
                    <div x-show="saveMessage" x-cloak x-transition class="mt-3 rounded-lg px-3 py-2 text-xs"
                         :class="saveError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'"
                         x-text="saveMessage"></div>
                </div>

                {{-- Sticky button footer — always visible --}}
                <div class="flex-shrink-0 border-t border-slate-200 p-4 bg-white rounded-b-2xl space-y-2">
                    <button @click="saveMarkers()"
                            :disabled="saving || markers.length === 0"
                            class="w-full rounded-lg px-4 py-2.5 text-sm font-medium transition-colors"
                            :class="saving || markers.length === 0 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-700 text-white hover:bg-slate-800'">
                        <span x-show="!saving">Save Markers</span>
                        <span x-show="saving" x-cloak>Saving...</span>
                    </button>
                    <button @click="isWebTemplate && markers.length === 0 ? (window.location.href = '{{ route('docuperfect.signatures.sign', $document) }}') : showSummary = true"
                            :disabled="!isWebTemplate && (markers.length === 0 || !saved)"
                            class="w-full rounded-lg px-4 py-2.5 text-sm font-medium transition-colors"
                            :class="!isWebTemplate && (markers.length === 0 || !saved) ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-emerald-600 text-white hover:bg-emerald-700'">
                        Preview & Continue
                    </button>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════
             SUMMARY OVERLAY
             ═══════════════════════════════════════════════ --}}
        <div x-show="showSummary" x-cloak x-transition.opacity
             class="fixed inset-0 z-50 flex items-center justify-center"
             style="background:rgba(0,0,0,0.5);"
             @click.self="showSummary = false">
            <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full mx-4 p-6 space-y-4" @click.stop>
                <h3 class="text-lg font-semibold text-slate-800">Signature Setup Summary</h3>

                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-emerald-600">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        Parties configured
                    </div>
                    <div class="flex items-center gap-2 text-emerald-600">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span x-text="markers.length + ' markers placed across ' + usedPages() + ' pages'"></span>
                    </div>
                </div>

                <div class="bg-slate-50 rounded-sm p-4 space-y-2">
                    <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Breakdown by Party</div>
                    <template x-for="summary in partySummary()" :key="summary.party">
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full" :class="partyDotClass(summary.party)"></span>
                                <span class="font-medium capitalize" x-text="summary.party.replace('_', ' ')"></span>
                            </div>
                            <div class="text-slate-500" x-text="summary.signatures + ' signatures, ' + summary.initials + ' initials'"></div>
                        </div>
                    </template>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button @click="showSummary = false" class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800 font-medium">
                        &larr; Edit Markers
                    </button>
                    <a href="{{ route('docuperfect.signatures.sign', $document) }}"
                       class="corex-btn-primary text-sm px-6 py-2.5">
                        Proceed to Sign &rarr;
                    </a>
                </div>
            </div>
        </div>

    </div>

    @php
    $markersJson = $markers->map(function($m) {
        return [
            '_id' => 'db_' . $m->id,
            'id' => $m->id,
            'page_number' => $m->page_number,
            'x_position' => (float) $m->x_position,
            'y_position' => (float) $m->y_position,
            'width' => (float) $m->width,
            'height' => min((float) $m->height, 8.0),
            'type' => $m->type,
            'assigned_party' => $m->assigned_party,
            'label' => $m->label,
            'required' => (bool) $m->required,
            'from_template' => $m->from_template_zone_id !== null,
            'from_zone_id' => $m->from_zone_id,
        ];
    })->values();

    $zonesJson = ($zones ?? collect())->map(function($z) {
        return [
            'id' => $z->id,
            'zone_type' => $z->zone_type,
            'party_role' => $z->party_role,
            'page_number' => $z->page_number,
            'x_position' => (float) $z->x_position,
            'y_position' => (float) $z->y_position,
            'width' => (float) $z->width,
            'height' => (float) $z->height,
            'is_auto_placed' => (bool) $z->is_auto_placed,
            'source' => $z->source,
            'label' => $z->label,
            'marker_count' => $z->expandedMarkers()->count(),
        ];
    })->values();
    @endphp

    <script>
    function markerPlacement() {
        return {
            markers: @json($markersJson),
            zones: @json($zonesJson),
            pageImages: @json($pageImages),
            documentFields: @json($document->fields_json ?? []),
            hasFlattened: {{ !empty($hasFlattened) ? 'true' : 'false' }},
            isWebTemplate: {{ ($isWebTemplate ?? false) ? 'true' : 'false' }},
            currentPage: 1,
            totalPages: {{ $pageCount }},
            selectedType: 'signature',
            selectedParty: '{{ $parties[0]['role'] ?? 'agent' }}',
            saving: false,
            saved: {{ ($markers->count() > 0 || ($isWebTemplate ?? false)) ? 'true' : 'false' }},
            saveMessage: '',
            saveError: false,
            showSummary: false,
            pageLoaded: false,
            _nextId: 1,
            _dragState: null,
            _zoneDrawState: null,
            _zoneDragState: null,
            zoneDrawMode: false,
            zoneType: 'signature',
            zoneRole: 'seller',
            parties: @json($parties),

            get partyOptions() {
                // V2 spec: each person is a separate signer — show individually, not grouped
                return (this.parties || []).map(p => {
                    const baseRole = (p.role_label || p.role || '').replace(/_\d+$/, '');
                    const roleLabel = baseRole.charAt(0).toUpperCase() + baseRole.slice(1).replace(/_/g, ' ');
                    const label = p.name ? roleLabel + ': ' + p.name : roleLabel;
                    return { value: p.role, label, dotClass: this.partyDotClass(p.role) };
                });
            },

            init() {
                // Global mouse handlers for drag (markers + zones)
                document.addEventListener('mousemove', (e) => { this.onDrag(e); this.onZoneDraw(e); this.onZoneDrag(e); });
                document.addEventListener('mouseup', () => { this.endDrag(); this.endZoneDraw(); this.endZoneDrag(); });
            },

            setupWebTemplateObserver() {
                // No-op for web templates. Signature positions are defined by
                // the template HTML — no overlay zones needed.
                // The document preview shows signature areas via CSS highlighting.
            },

            /**
             * No-op — web templates define signature positions in the HTML.
             * Zone creation from DOM is no longer needed.
             */
            _createZonesFromDOM() { /* no-op */ },

            prevPage() { if (this.currentPage > 1) this.currentPage--; },
            nextPage() { if (this.currentPage < this.totalPages) this.currentPage++; },

            markersForCurrentPage() {
                return this.markers.filter(m => m.page_number === this.currentPage);
            },

            fieldsForCurrentPage() {
                // fields_json uses 0-indexed pageIndex, currentPage is 1-indexed
                const pageIdx = this.currentPage - 1;
                return (this.documentFields || []).filter(f => f.pageIndex === pageIdx);
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

            handleDrop(e) {
                const type = e.dataTransfer.getData('marker-type');
                if (!type) return;

                const container = this.$refs.pageContainer;
                const rect = container.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;

                const sizes = {
                    signature: { w: 12, h: 5 },
                    initial:   { w: 6,  h: 4 },
                    text:      { w: 15, h: 3 },
                    date:      { w: 8,  h: 3 },
                };
                const s = sizes[type] || sizes.signature;
                const w = s.w;
                const h = s.h;

                // Clamp to page bounds
                const cx = Math.max(0, Math.min(x - w / 2, 100 - w));
                const cy = Math.max(0, Math.min(y - h / 2, 100 - h));

                this.markers.push({
                    _id: 'new_' + this._nextId++,
                    id: null,
                    page_number: this.currentPage,
                    x_position: Math.round(cx * 100) / 100,
                    y_position: Math.round(cy * 100) / 100,
                    width: w,
                    height: h,
                    type: type,
                    assigned_party: this.selectedParty,
                    label: null,
                    required: true,
                });

                this.saved = false;
            },

            removeMarker(id) {
                this.markers = this.markers.filter(m => m._id !== id);
                this.saved = false;
            },

            markerLabel(m) {
                // Use label if set (auto-placed markers have descriptive labels)
                if (m.label) return m.label;
                // Strip suffix for display: seller_2 → Seller
                const baseRole = (m.assigned_party || '').replace(/_\d+$/, '');
                const roleLabel = baseRole.charAt(0).toUpperCase() + baseRole.slice(1).replace(/_/g, ' ');
                // Find party name from knownParties
                const party = (this.parties || []).find(p => p.role === m.assigned_party);
                const name = party ? party.name : '';
                const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
                return name ? roleLabel + ' — ' + name + ' ' + typeLabel : roleLabel + ' ' + typeLabel;
            },

            markerClasses(m) {
                const base = 'rounded border-2 ';
                const baseRole = (m.assigned_party || '').replace(/_\d+$/, '');
                switch (baseRole) {
                    case 'agent': return base + 'border-blue-500 bg-blue-50 text-blue-700';
                    case 'tenant': case 'buyer': return base + 'border-green-500 bg-green-50 text-green-700';
                    case 'landlord': case 'seller': return base + 'border-orange-500 bg-orange-50 text-orange-700';
                    default: return base + 'border-purple-500 bg-purple-50 text-purple-700';
                }
            },

            partyDotClass(party) {
                // Strip numeric suffix (seller_2 → seller) for colour lookup
                const base = (party || '').replace(/_\d+$/, '');
                switch (base) {
                    case 'agent': return 'bg-blue-500';
                    case 'tenant': case 'buyer': return 'bg-green-500';
                    case 'landlord': case 'seller': return 'bg-orange-500';
                    case 'witness': return 'bg-purple-500';
                    case 'spouse': return 'bg-pink-500';
                    default: return 'bg-gray-500';
                }
            },

            // ── Drag to reposition ──
            startDrag(e, marker) {
                if (e.target.closest('button') || e.target.closest('.cursor-se-resize')) return;

                const container = this.$refs.pageContainer;
                const rect = container.getBoundingClientRect();

                this._dragState = {
                    type: 'move',
                    marker: marker,
                    startMouseX: e.clientX,
                    startMouseY: e.clientY,
                    startX: marker.x_position,
                    startY: marker.y_position,
                    containerW: rect.width,
                    containerH: rect.height,
                };
            },

            startResize(e, marker) {
                const container = this.$refs.pageContainer;
                const rect = container.getBoundingClientRect();

                this._dragState = {
                    type: 'resize',
                    marker: marker,
                    startMouseX: e.clientX,
                    startMouseY: e.clientY,
                    startW: marker.width,
                    startH: marker.height,
                    containerW: rect.width,
                    containerH: rect.height,
                };
            },

            onDrag(e) {
                if (!this._dragState) return;
                const s = this._dragState;
                const dx = ((e.clientX - s.startMouseX) / s.containerW) * 100;
                const dy = ((e.clientY - s.startMouseY) / s.containerH) * 100;

                if (s.type === 'move') {
                    s.marker.x_position = Math.round(Math.max(0, Math.min(s.startX + dx, 100 - s.marker.width)) * 100) / 100;
                    s.marker.y_position = Math.round(Math.max(0, Math.min(s.startY + dy, 100 - s.marker.height)) * 100) / 100;
                } else if (s.type === 'resize') {
                    s.marker.width = Math.round(Math.max(5, Math.min(s.startW + dx, 100 - s.marker.x_position)) * 100) / 100;
                    s.marker.height = Math.round(Math.max(2, Math.min(s.startH + dy, 100 - s.marker.y_position)) * 100) / 100;
                }

                this.saved = false;
            },

            endDrag() {
                this._dragState = null;
            },

            // ── Zone helpers ──
            zonesForCurrentPage() {
                return this.zones.filter(z => z.page_number === this.currentPage);
            },

            // Start drawing a zone bounding box on the page container
            startZoneDrawOnPage(e) {
                if (!this.zoneDrawMode) return;
                const container = this.$refs.pageContainer;
                if (!container) return;
                const rect = container.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;

                this._zoneDrawState = {
                    startX: x, startY: y,
                    x: x, y: y, w: 0, h: 0,
                    containerW: rect.width, containerH: rect.height,
                    containerRect: rect,
                };
            },

            onZoneDraw(e) {
                if (!this._zoneDrawState) return;
                const s = this._zoneDrawState;
                const currentX = ((e.clientX - s.containerRect.left) / s.containerW) * 100;
                const currentY = ((e.clientY - s.containerRect.top) / s.containerH) * 100;

                s.x = Math.min(s.startX, currentX);
                s.y = Math.min(s.startY, currentY);
                s.w = Math.abs(currentX - s.startX);
                s.h = Math.abs(currentY - s.startY);
            },

            async endZoneDraw() {
                if (!this._zoneDrawState) return;
                const s = this._zoneDrawState;
                this._zoneDrawState = null;

                // Minimum size check — ignore tiny accidental clicks
                if (s.w < 3 || s.h < 2) return;

                // Create zone via API
                try {
                    const resp = await fetch(@json(route('docuperfect.signatures.storeZone', $document)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({
                            zone_type: this.zoneType,
                            party_role: this.zoneRole,
                            page_number: this.currentPage,
                            x_position: Math.round(s.x * 100) / 100,
                            y_position: Math.round(s.y * 100) / 100,
                            width: Math.round(s.w * 100) / 100,
                            height: Math.round(s.h * 100) / 100,
                        }),
                    });

                    const data = await resp.json();
                    if (data.ok) {
                        // Add zone to local state
                        data.zone.marker_count = (data.markers || []).length;
                        this.zones.push(data.zone);

                        // Add expanded markers to local state
                        (data.markers || []).forEach(m => {
                            this.markers.push({
                                _id: 'zone_' + this._nextId++,
                                id: m.id,
                                page_number: m.page_number,
                                x_position: m.x_position,
                                y_position: m.y_position,
                                width: m.width,
                                height: m.height,
                                type: m.type,
                                assigned_party: m.assigned_party,
                                label: m.label,
                                required: true,
                                from_zone_id: data.zone.id,
                            });
                        });

                        this.saved = false;
                        this.saveMessage = 'Zone created with ' + (data.markers || []).length + ' blocks.';
                        this.saveError = false;
                        setTimeout(() => { this.saveMessage = ''; }, 3000);
                    }
                } catch (err) {
                    this.saveMessage = 'Failed to create zone.';
                    this.saveError = true;
                    setTimeout(() => { this.saveMessage = ''; }, 3000);
                }
            },

            // Zone drag (reposition)
            startZoneDrag(e, zone) {
                if (e.target.closest('button') || e.target.closest('.cursor-se-resize')) return;
                const container = this.$refs.pageContainer;
                const rect = container.getBoundingClientRect();
                this._zoneDragState = {
                    type: 'move', zone,
                    startMouseX: e.clientX, startMouseY: e.clientY,
                    startX: zone.x_position, startY: zone.y_position,
                    containerW: rect.width, containerH: rect.height,
                };
            },

            startZoneResize(e, zone) {
                const container = this.$refs.pageContainer;
                const rect = container.getBoundingClientRect();
                this._zoneDragState = {
                    type: 'resize', zone,
                    startMouseX: e.clientX, startMouseY: e.clientY,
                    startW: zone.width, startH: zone.height,
                    containerW: rect.width, containerH: rect.height,
                };
            },

            onZoneDrag(e) {
                if (!this._zoneDragState) return;
                const s = this._zoneDragState;
                const dx = ((e.clientX - s.startMouseX) / s.containerW) * 100;
                const dy = ((e.clientY - s.startMouseY) / s.containerH) * 100;

                if (s.type === 'move') {
                    s.zone.x_position = Math.round(Math.max(0, Math.min(s.startX + dx, 100 - s.zone.width)) * 100) / 100;
                    s.zone.y_position = Math.round(Math.max(0, Math.min(s.startY + dy, 100 - s.zone.height)) * 100) / 100;
                } else {
                    s.zone.width = Math.round(Math.max(5, Math.min(s.startW + dx, 100 - s.zone.x_position)) * 100) / 100;
                    s.zone.height = Math.round(Math.max(3, Math.min(s.startH + dy, 100 - s.zone.y_position)) * 100) / 100;
                }
            },

            async endZoneDrag() {
                if (!this._zoneDragState) return;
                const zone = this._zoneDragState.zone;
                this._zoneDragState = null;

                // Persist zone update and get re-expanded markers
                try {
                    const resp = await fetch(@json(url('/docuperfect/documents/' . $document->id . '/signatures/zones')) + '/' + zone.id, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({
                            x_position: zone.x_position,
                            y_position: zone.y_position,
                            width: zone.width,
                            height: zone.height,
                        }),
                    });

                    const data = await resp.json();
                    if (data.ok && data.markers) {
                        // Remove old expanded markers for this zone
                        this.markers = this.markers.filter(m => m.from_zone_id !== zone.id);
                        zone.marker_count = data.markers.length;

                        // Add re-expanded markers
                        data.markers.forEach(m => {
                            this.markers.push({
                                _id: 'zone_' + this._nextId++,
                                id: m.id,
                                page_number: m.page_number,
                                x_position: m.x_position,
                                y_position: m.y_position,
                                width: m.width,
                                height: Math.min(m.height, 8),
                                type: m.type,
                                assigned_party: m.assigned_party,
                                label: m.label,
                                required: true,
                                from_zone_id: zone.id,
                            });
                        });
                        this.saved = false;
                    }
                } catch (err) {
                    // Silently fail — zone position reverts on reload
                }
            },

            async removeZone(zone) {
                try {
                    await fetch(@json(url('/docuperfect/documents/' . $document->id . '/signatures/zones')) + '/' + zone.id, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                    });
                } catch (err) {
                    // Continue with local removal even if API fails
                }

                // Remove from local state
                this.zones = this.zones.filter(z => z.id !== zone.id);
                this.markers = this.markers.filter(m => m.from_zone_id !== zone.id);
                this.saved = false;
            },

            // ── Save ──
            async saveMarkers() {
                this.saving = true;
                this.saveMessage = '';
                this.saveError = false;

                const payload = this.markers.map((m, i) => ({
                    page_number: m.page_number,
                    x_position: m.x_position,
                    y_position: m.y_position,
                    width: m.width,
                    height: m.height,
                    type: m.type,
                    assigned_party: m.assigned_party,
                    label: this.markerLabel(m),
                    required: m.required,
                    sort_order: i,
                }));

                try {
                    const resp = await fetch(@json(route('docuperfect.signatures.saveMarkers', $document)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({ markers: payload }),
                    });

                    const data = await resp.json();

                    if (data.ok) {
                        this.saved = true;
                        this.saveMessage = data.count + ' markers saved successfully.';
                        this.saveError = false;
                    } else {
                        this.saveMessage = data.error || 'Failed to save markers.';
                        this.saveError = true;
                    }
                } catch (err) {
                    this.saveMessage = 'Network error. Please try again.';
                    this.saveError = true;
                }

                this.saving = false;
                setTimeout(() => { this.saveMessage = ''; }, 4000);
            },

            // ── Summary helpers ──
            usedPages() {
                return [...new Set(this.markers.map(m => m.page_number))].length;
            },

            partySummary() {
                const parties = {};
                this.markers.forEach(m => {
                    if (!parties[m.assigned_party]) {
                        parties[m.assigned_party] = { party: m.assigned_party, signatures: 0, initials: 0, dates: 0, texts: 0 };
                    }
                    if (m.type === 'signature') parties[m.assigned_party].signatures++;
                    else if (m.type === 'initial') parties[m.assigned_party].initials++;
                    else if (m.type === 'date') parties[m.assigned_party].dates++;
                    else parties[m.assigned_party].texts++;
                });
                return Object.values(parties);
            },
        };
    }
    </script>
    @endif

</div>
@endsection
