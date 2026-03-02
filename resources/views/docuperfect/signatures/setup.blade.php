@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    @php
        $isSalesTemplate = ($templateType ?? 'rentals') === 'sales';
        $backRoute = $isSalesTemplate ? route('docuperfect.sales') : route('docuperfect.rental');
        $backLabel = $isSalesTemplate ? 'Back to Sales' : 'Back to Rental';
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

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Signature Setup &mdash; {{ $document->name }}</h2>
            <div class="text-sm text-white/60">
                @if($step === 1) Step 1: Configure Signing Parties
                @else Step 2: Place Signature Markers
                @endif
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if($step === 2)
                <a href="{{ route('docuperfect.signatures.setup', [$document, 'step' => 1]) }}"
                   class="corex-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">
                    Edit Parties
                </a>
            @endif
            <a href="{{ $backRoute }}"
               class="text-sm text-white/70 hover:text-white">{{ $backLabel }}</a>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
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
                <div class="mb-6 p-4 rounded-xl border border-emerald-200 bg-emerald-50/50">
                    <div class="flex items-center gap-2 mb-1">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-sm font-semibold text-emerald-700 uppercase tracking-wider">Agent (Pre-signed — Wet Ink Upload)</span>
                    </div>
                    <p class="text-xs text-emerald-600 ml-7">Agent pre-signed document was uploaded. No electronic signature required.</p>
                    <input type="hidden" name="agent_name" value="{{ $user->name }}">
                    <input type="hidden" name="agent_email" value="{{ $user->email }}">
                </div>
            @else
                <div class="mb-6 p-4 rounded-xl border border-blue-200 bg-blue-50/50">
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
            <div class="mb-6 p-4 rounded-xl border border-green-200" :class="partyOneNotRequired ? 'bg-gray-50 opacity-60' : 'bg-green-50/50'">
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
            <div class="mb-6 p-4 rounded-xl border border-orange-200" :class="partyTwoNotRequired ? 'bg-gray-50 opacity-60' : 'bg-orange-50/50'">
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
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium
                    @if($party['role'] === 'agent') bg-blue-100 text-blue-700
                    @elseif(in_array($party['role'], ['tenant', 'buyer'])) bg-green-100 text-green-700
                    @elseif(in_array($party['role'], ['landlord', 'seller'])) bg-orange-100 text-orange-700
                    @else bg-purple-100 text-purple-700
                    @endif">
                    {{ ucfirst(str_replace('_', ' ', $party['role'])) }}: {{ $party['name'] }}
                </span>
            @endforeach
        </div>

        <div class="flex gap-4" style="height:calc(100vh - 240px); min-height:400px;">
            {{-- LEFT: Document pages --}}
            <div class="flex-1 ds-status-card p-4 overflow-hidden flex flex-col">
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

                {{-- Page display --}}
                <div class="flex-1 overflow-auto flex justify-center items-start" style="background:#e2e8f0;">
                    <div class="relative inline-block" style="max-width:800px; width:100%;"
                         x-ref="pageContainer"
                         @dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                         @drop.prevent="handleDrop($event)">

                        <img :src="pageImages[currentPage - 1]"
                             class="w-full block select-none pointer-events-none"
                             draggable="false"
                             @load="pageLoaded = true"
                             x-ref="pageImage">

                        {{-- Render document field values (read-only overlay) --}}
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

                        {{-- Render markers for current page --}}
                        <template x-for="(marker, idx) in markersForCurrentPage()" :key="marker._id">
                            <div class="absolute flex items-center justify-center text-xs font-medium select-none"
                                 :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:${marker.height}%;opacity:0.7;z-index:10;`"
                                 :class="markerClasses(marker)"
                                 @mousedown.prevent="startDrag($event, marker)"
                                 style="cursor:grab;">

                                {{-- Label --}}
                                <span class="truncate px-1 pointer-events-none" x-text="markerLabel(marker)"></span>

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

            {{-- RIGHT: Toolbar --}}
            <div class="w-72 flex-shrink-0 ds-status-card flex flex-col">
                {{-- Scrollable content area --}}
                <div class="flex-1 overflow-y-auto p-4 min-h-0">
                    <h4 class="text-sm font-semibold text-slate-800 mb-3">Place Markers</h4>
                    <p class="text-xs text-slate-500 mb-4">Select a marker type and party, then click on the document to place it.</p>

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
                            @foreach($parties as $party)
                            <label class="flex items-center gap-2 cursor-pointer text-sm rounded-lg px-3 py-1.5 transition-colors"
                                   :class="selectedParty === '{{ $party['role'] }}' ? 'bg-slate-100 font-medium' : 'hover:bg-slate-50'">
                                <input type="radio" name="assign_party" value="{{ $party['role'] }}"
                                       x-model="selectedParty"
                                       class="text-cyan-600 focus:ring-cyan-500">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0
                                    @if($party['role'] === 'agent') bg-blue-500
                                    @elseif($party['role'] === 'tenant') bg-green-500
                                    @elseif($party['role'] === 'landlord') bg-orange-500
                                    @else bg-purple-500
                                    @endif"></span>
                                {{ ucfirst(str_replace('_', ' ', $party['role'])) }}
                            </label>
                            @endforeach
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
                    <button @click="showSummary = true"
                            :disabled="markers.length === 0 || !saved"
                            class="w-full rounded-lg px-4 py-2.5 text-sm font-medium transition-colors"
                            :class="markers.length === 0 || !saved ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-emerald-600 text-white hover:bg-emerald-700'">
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

                <div class="bg-slate-50 rounded-xl p-4 space-y-2">
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
            'height' => (float) $m->height,
            'type' => $m->type,
            'assigned_party' => $m->assigned_party,
            'label' => $m->label,
            'required' => (bool) $m->required,
            'from_template' => $m->from_template_zone_id !== null,
        ];
    })->values();
    @endphp

    <script>
    function markerPlacement() {
        return {
            markers: @json($markersJson),
            pageImages: @json($pageImages),
            documentFields: @json($document->fields_json ?? []),
            currentPage: 1,
            totalPages: {{ $pageCount }},
            selectedType: 'signature',
            selectedParty: '{{ $parties[0]['role'] ?? 'agent' }}',
            saving: false,
            saved: {{ $markers->count() > 0 ? 'true' : 'false' }},
            saveMessage: '',
            saveError: false,
            showSummary: false,
            pageLoaded: false,
            _nextId: 1,
            _dragState: null,

            init() {
                // Global mouse handlers for drag
                document.addEventListener('mousemove', (e) => this.onDrag(e));
                document.addEventListener('mouseup', () => this.endDrag());
            },

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
                const partyLabel = m.assigned_party.replace('_', ' ');
                const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
                return partyLabel.charAt(0).toUpperCase() + partyLabel.slice(1) + ' ' + typeLabel;
            },

            markerClasses(m) {
                const base = 'rounded border-2 ';
                switch (m.assigned_party) {
                    case 'agent': return base + 'border-blue-500 bg-blue-50 text-blue-700';
                    case 'tenant': return base + 'border-green-500 bg-green-50 text-green-700';
                    case 'landlord': return base + 'border-orange-500 bg-orange-50 text-orange-700';
                    default: return base + 'border-purple-500 bg-purple-50 text-purple-700';
                }
            },

            partyDotClass(party) {
                switch (party) {
                    case 'agent': return 'bg-blue-500';
                    case 'tenant': return 'bg-green-500';
                    case 'landlord': return 'bg-orange-500';
                    default: return 'bg-purple-500';
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
