@extends('layouts.corex')

@section('corex-content')
@php
    $flowId = $flow->id ?? null;
    $hasFlow = !is_null($flowId);
    $safeStep = $currentStep ?? 1;
@endphp

<div x-data="esignWizard()" x-cloak class="flex flex-col h-[calc(100vh-64px)]">

    {{-- ===== TOAST NOTIFICATION ===== --}}
    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-[-8px]"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed top-20 right-6 z-50 px-4 py-3 rounded-lg shadow-lg text-sm font-medium"
         :class="toast.type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">
        <span x-text="toast.message"></span>
    </div>

    {{-- ===== PROGRESS BAR (sticky header) ===== --}}
    <div style="background:#0b2a4a;" class="px-6 py-4 flex-shrink-0">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-xl font-bold text-white leading-tight">
                E-Sign Document
                <span x-show="templateName || selectedPackName" class="text-white/60 font-normal text-base" x-text="'— ' + (isPackFlow ? selectedPackName : templateName)"></span>
            </h2>
            <span class="text-sm text-white/60" x-text="'Step ' + currentStep + ' of 6'"></span>
        </div>
        <div class="flex gap-1">
            <template x-for="(label, i) in stepLabels" :key="i">
                <div class="flex-1 flex flex-col gap-1"
                     :class="canGoToStep(i+1) ? 'cursor-pointer' : 'cursor-default'"
                     @click="canGoToStep(i+1) && goToStep(i+1)">
                    <div class="h-1.5 rounded-full transition-all duration-300"
                         :class="{
                             'bg-emerald-400': (i+1) < currentStep,
                             'bg-white': (i+1) === currentStep,
                             'bg-white/20': (i+1) > currentStep
                         }"></div>
                    <span class="text-[10px] leading-tight"
                          :class="(i+1) <= currentStep ? 'text-white/70' : 'text-white/30'"
                          x-text="label"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ===== TWO-PANEL LAYOUT ===== --}}
    <div class="flex-1 flex min-h-0 overflow-hidden">

        {{-- LEFT PANEL --}}
        <div class="overflow-y-auto bg-white dark:bg-gray-900 flex flex-col"
             :style="'width:' + leftPanelPx + 'px; min-width:250px; max-width:50vw;'">
            <div class="flex-1 p-6 pb-24">

            {{-- ======== STEP 1: Template Selection ======== --}}
            <div x-show="currentStep === 1" x-cloak>

                {{-- Draft flows --}}
                <div x-show="drafts.length > 0" class="mb-6">
                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">Continue where you left off</h4>
                    <div class="space-y-2">
                        <template x-for="(d, di) in drafts" :key="d.id">
                            <div class="p-3 rounded-lg border border-amber-200 bg-amber-50">
                                <div class="flex items-start justify-between">
                                    <div class="min-w-0">
                                        <div class="font-medium text-gray-900 text-sm truncate" x-text="d.template_name || 'Untitled'"></div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            Step <span x-text="d.current_step"></span> of 5
                                            <template x-if="d.property_address">
                                                <span> &middot; <span x-text="d.property_address"></span></span>
                                            </template>
                                        </div>
                                        <div class="text-xs text-gray-400 mt-0.5" x-text="'Last edited: ' + d.updated_ago"></div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between mt-2 pt-2 border-t border-amber-200/60">
                                    <button @click="deleteDraft(d.id, di)"
                                            class="text-xs text-red-400 hover:text-red-600 transition">Delete Draft</button>
                                    <a :href="'/docuperfect/esign/' + d.id + '/step/' + d.current_step"
                                       class="text-xs font-medium text-blue-600 hover:text-blue-800 transition">Continue &rarr;</a>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Template</h3>

                <input type="text" x-model="templateSearch" placeholder="Search templates..."
                       class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm mb-4" />

                {{-- Template groups by type --}}
                <template x-for="group in templateGroups" :key="group.type">
                    <div x-show="group.templates.length > 0" class="mb-4">
                        <button @click="group.open = !group.open"
                                class="w-full flex items-center justify-between px-3 py-2 bg-gray-50 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-100 transition">
                            <span>
                                <span x-text="group.label" class="capitalize"></span>
                                <span class="text-gray-400 font-normal ml-1" x-text="'(' + group.templates.length + ')'"></span>
                            </span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="group.open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="group.open" x-collapse class="mt-2 space-y-2">
                            <template x-for="t in group.templates" :key="t.id">
                                <button @click="selectTemplate(t)"
                                        class="w-full text-left p-3 rounded-lg border-2 transition-all duration-150"
                                        :class="selectedTemplateId === t.id
                                            ? 'border-blue-500 bg-blue-50'
                                            : 'border-gray-200 hover:border-gray-300 bg-white'">
                                    <div class="font-medium text-gray-900 text-sm flex items-center">
                                        <span x-text="t.name"></span>
                                        <span x-show="t.render_type === 'web'" class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 ml-2">Web</span>
                                        <span x-show="!t.render_type || t.render_type === 'pdf'" class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-2">PDF</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        <span x-text="t.page_count + ' page' + (t.page_count !== 1 ? 's' : '')"></span>
                                        &middot; <span x-text="(t.fields_json?.length || 0) + ' fields'"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-show="templateGroups.every(g => g.templates.length === 0) && allWebPacks.length === 0 && allPdfPacks.length === 0" class="text-gray-400 text-sm text-center py-8">
                    No templates match your search.
                </div>

                {{-- Web Packs section --}}
                <template x-if="allWebPacks.length > 0">
                    <div class="mt-6">
                        <div class="px-3 py-2 bg-blue-50 rounded-lg mb-3">
                            <h4 class="text-sm font-semibold text-blue-700 uppercase tracking-wide">Web Packs</h4>
                        </div>
                        <div class="space-y-2">
                            <template x-for="p in allWebPacks" :key="'pack-' + p.id">
                                <button @click="selectPack(p)"
                                        class="w-full text-left p-3 rounded-lg border-2 transition-all duration-150"
                                        :class="selectedPackId === p.id
                                            ? 'border-blue-500 bg-blue-50'
                                            : 'border-gray-200 hover:border-gray-300 bg-white'">
                                    <div class="font-medium text-gray-900 text-sm flex items-center">
                                        <span x-text="p.name"></span>
                                        <template x-if="p.items.some(i => i.template?.template_type === 'cds')">
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-100 text-teal-700 font-semibold ml-2">CDS</span>
                                        </template>
                                        <template x-if="!p.items.some(i => i.template?.template_type === 'cds')">
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 ml-2">Web</span>
                                        </template>
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-1" x-text="p.items.length + ' template' + (p.items.length !== 1 ? 's' : '')"></span>
                                    </div>
                                    <div x-show="p.items.length > 0" class="mt-1.5 space-y-0.5">
                                        <template x-for="item in p.items" :key="'pi-' + item.id">
                                            <div class="text-xs text-gray-500 flex items-center gap-1">
                                                <span class="w-1 h-1 rounded-full flex-shrink-0"
                                                      :class="item.template?.template_type === 'cds' ? 'bg-teal-500' : 'bg-blue-400'"></span>
                                                <span x-text="item.template?.name || 'Unknown template'"></span>
                                                <span x-show="item.template?.template_type === 'cds'"
                                                      class="text-[9px] px-1 py-0 rounded bg-teal-50 text-teal-600">CDS</span>
                                            </div>
                                        </template>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- PDF Packs section --}}
                <template x-if="allPdfPacks.length > 0">
                    <div class="mt-6">
                        <div class="px-3 py-2 bg-gray-50 rounded-lg mb-3">
                            <h4 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Document Packs</h4>
                        </div>
                        <div class="space-y-2">
                            <template x-for="p in allPdfPacks" :key="'pdfpack-' + p.id">
                                <div>
                                    <template x-if="p.esign_eligible">
                                        <button @click="selectPdfPack(p)"
                                                class="w-full text-left p-3 rounded-lg border-2 transition-all duration-150"
                                                :class="selectedPdfPackId === p.id
                                                    ? 'border-blue-500 bg-blue-50'
                                                    : 'border-gray-200 hover:border-gray-300 bg-white'">
                                            <div class="font-medium text-gray-900 text-sm flex items-center">
                                                <span x-text="p.name"></span>
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-2">Pack</span>
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-1" x-text="p.templates.length + ' template' + (p.templates.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            <div x-show="p.templates.length > 0" class="mt-1.5 space-y-0.5">
                                                <template x-for="t in p.templates" :key="'ppt-' + t.id">
                                                    <div class="text-xs text-gray-500 flex items-center gap-1">
                                                        <span class="w-1 h-1 rounded-full bg-gray-400 flex-shrink-0"></span>
                                                        <span x-text="t.name"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </button>
                                    </template>
                                    <template x-if="!p.esign_eligible">
                                        <div class="w-full text-left p-3 rounded-lg border-2 border-gray-200 bg-white opacity-50 cursor-not-allowed">
                                            <div class="font-medium text-gray-900 text-sm flex items-center">
                                                <span x-text="p.name"></span>
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-2">Pack</span>
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 ml-1" x-text="p.templates.length + ' template' + (p.templates.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            <div class="text-xs text-amber-600 mt-1">Contains a wet ink document &mdash; not eligible for e-signature</div>
                                            <div x-show="p.templates.length > 0" class="mt-1.5 space-y-0.5">
                                                <template x-for="t in p.templates" :key="'ppt-' + t.id">
                                                    <div class="text-xs text-gray-500 flex items-center gap-1">
                                                        <span class="w-1 h-1 rounded-full bg-gray-400 flex-shrink-0"></span>
                                                        <span x-text="t.name"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ======== STEP 2: Property ======== --}}
            <div x-show="currentStep === 2" x-cloak>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Property Details</h3>

                {{-- Property search --}}
                <div class="relative mb-4" @click.outside="propSearchOpen = false" @keydown.escape.window="propSearchOpen = false">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search property by address, suburb, or ERF</label>
                    <div class="relative">
                        <input type="text"
                               x-model="propSearchQuery"
                               @input.debounce.300ms="searchProperties()"
                               @focus="if (propSearchResults.length) propSearchOpen = true"
                               @keydown.arrow-down.prevent="propSearchIdx = Math.min(propSearchIdx + 1, propSearchResults.length - 1); $nextTick(() => $el.closest('.relative').querySelector('[data-idx=\'' + propSearchIdx + '\']')?.scrollIntoView({block:'nearest'}))"
                               @keydown.arrow-up.prevent="propSearchIdx = Math.max(propSearchIdx - 1, 0)"
                               @keydown.enter.prevent="if (propSearchOpen && propSearchResults[propSearchIdx]) selectProperty(propSearchResults[propSearchIdx])"
                               class="w-full rounded-lg border px-3 py-2 text-sm pr-8"
                               :class="property._selected ? 'border-emerald-400 bg-emerald-50' : 'border-slate-300 bg-white'"
                               placeholder="Start typing to search...">
                        <div class="absolute right-2 top-1/2 -translate-y-1/2">
                            <svg x-show="propSearching" class="w-4 h-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                            <svg x-show="!propSearching && !property._selected" class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <svg x-show="property._selected" class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                    </div>

                    {{-- Selected property badge --}}
                    <div x-show="property._selected" class="mt-2 flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-sm">
                        <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-emerald-800 font-medium truncate" x-text="'Selected: ' + property.address"></span>
                        <button @click="clearPropertySelection()" class="ml-auto text-emerald-600 hover:text-red-500 transition flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Search results dropdown --}}
                    <div x-show="propSearchOpen && propSearchResults.length > 0" x-transition
                         class="absolute z-30 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                        <template x-for="(result, ri) in propSearchResults" :key="result.source + '-' + result.id">
                            <button @click="selectProperty(result)"
                                    :data-idx="ri"
                                    class="w-full text-left px-3 py-2.5 border-b border-gray-100 last:border-0 transition-colors"
                                    :class="ri === propSearchIdx ? 'bg-blue-50' : 'hover:bg-gray-50'">
                                <div class="flex items-start justify-between">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-gray-900 truncate" x-text="result.display"></div>
                                        <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-2">
                                            <span x-show="result.property_type" x-text="result.property_type" class="capitalize"></span>
                                            <span x-show="result.beds" x-text="result.beds + ' bed'"></span>
                                            <span x-show="result.rental_amount" x-text="'R ' + Number(result.rental_amount).toLocaleString() + '/mo'"></span>
                                        </div>
                                        <div x-show="result.lessor_name" class="text-xs text-blue-600 mt-0.5" x-text="'Landlord: ' + result.lessor_name"></div>
                                    </div>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 flex-shrink-0 ml-2"
                                          x-text="result.source === 'properties' ? 'Property' : 'Rental'"></span>
                                </div>
                            </button>
                        </template>
                    </div>

                    {{-- No results --}}
                    <div x-show="propSearchOpen && propSearchResults.length === 0 && !propSearching && propSearchQuery.length >= 2"
                         class="absolute z-30 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg p-4 text-center text-sm text-gray-500">
                        No properties found for "<span x-text="propSearchQuery"></span>"
                    </div>
                </div>

                {{-- Manual entry toggle --}}
                <div x-show="!property._selected" class="mb-3">
                    <p class="text-xs text-gray-400 italic">Can't find property? Enter manually below</p>
                </div>

                {{-- Manual entry fields --}}
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <input type="text" x-model="property.address" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. 21 Dee Road, Uvongo">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Suburb</label>
                            <input type="text" x-model="property.suburb" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. Uvongo">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit / Erf Number</label>
                            <input type="text" x-model="property.erf" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. Erf 789">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Complex Name</label>
                            <input type="text" x-model="property.complex_name" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. Ocean View">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Property Type</label>
                            <select x-model="property.property_type" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                                <option value="">Select...</option>
                                <option value="house">House</option>
                                <option value="unit">Unit</option>
                                <option value="flat">Flat</option>
                                <option value="townhouse">Townhouse</option>
                                <option value="duplex">Duplex</option>
                                <option value="cottage">Cottage</option>
                                <option value="commercial">Commercial</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ======== STEP 3: Recipients ======== --}}
            <div x-show="currentStep === 3" x-cloak>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Recipients</h3>

                <div class="space-y-3">
                    <template x-for="(r, ri) in recipients" :key="ri">
                        <div class="p-4 rounded-xl border transition-colors"
                             :class="r.readonly ? 'border-blue-200 bg-blue-50/50' : (r._contact_id ? 'border-emerald-200 bg-emerald-50/30' : 'border-gray-200 bg-white')">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold"
                                          :class="r.readonly ? 'bg-blue-600 text-white' : (r._contact_id ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-600')"
                                          x-text="ri + 1"></span>
                                    <span class="text-sm font-semibold text-gray-700" x-text="r.readonly ? 'Agent (You)' : 'Recipient ' + (ri+1)"></span>
                                    <span x-show="r._contact_id" class="text-xs text-emerald-600 font-medium">Linked</span>
                                </div>
                                <button x-show="!r.readonly" @click="removeRecipient(ri)"
                                        class="w-6 h-6 flex items-center justify-center rounded-full text-red-400 hover:text-red-600 hover:bg-red-50 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Role</label>
                                    <template x-if="r.readonly">
                                        <input type="text" value="Agent" disabled class="w-full rounded-lg border border-gray-200 bg-gray-100 text-gray-500 px-3 py-2 text-sm">
                                    </template>
                                    <template x-if="!r.readonly">
                                        <select x-model="r.role" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                                            <option value="landlord">Landlord</option>
                                            <option value="tenant">Tenant</option>
                                            <option value="buyer">Buyer</option>
                                            <option value="seller">Seller</option>
                                            <option value="witness">Witness</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </template>
                                </div>

                                {{-- Contact search (only for non-agent recipients) --}}
                                <template x-if="!r.readonly">
                                    <div class="relative" @click.outside="r._searchOpen = false" @keydown.escape.window="r._searchOpen = false">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Search contact by name, email, or ID</label>
                                        <div class="relative">
                                            <input type="text"
                                                   x-model="r._searchQuery"
                                                   @input.debounce.300ms="searchContacts(ri)"
                                                   @focus="if (r._searchResults?.length) r._searchOpen = true"
                                                   @keydown.arrow-down.prevent="r._searchIdx = Math.min((r._searchIdx || 0) + 1, (r._searchResults || []).length - 1)"
                                                   @keydown.arrow-up.prevent="r._searchIdx = Math.max((r._searchIdx || 0) - 1, 0)"
                                                   @keydown.enter.prevent="if (r._searchOpen && r._searchResults?.[r._searchIdx]) selectContact(ri, r._searchResults[r._searchIdx])"
                                                   class="w-full rounded-lg border px-3 py-2 text-sm pr-8"
                                                   :class="r._contact_id ? 'border-emerald-400 bg-emerald-50' : 'border-slate-300 bg-white'"
                                                   placeholder="Start typing to search...">
                                            <div class="absolute right-2 top-1/2 -translate-y-1/2">
                                                <svg x-show="r._searching" class="w-4 h-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                                                <svg x-show="!r._searching && r._contact_id" class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <svg x-show="!r._searching && !r._contact_id" class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                            </div>
                                        </div>

                                        {{-- Selected contact badge --}}
                                        <div x-show="r._contact_id" class="mt-1.5 flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-xs">
                                            <svg class="w-3.5 h-3.5 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            <span class="text-emerald-800 font-medium truncate" x-text="'Selected: ' + r.name"></span>
                                            <button @click="clearContactSelection(ri)" class="ml-auto text-emerald-600 hover:text-red-500 transition flex-shrink-0">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>

                                        {{-- Search results --}}
                                        <div x-show="r._searchOpen && (r._searchResults || []).length > 0" x-transition
                                             class="absolute z-30 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                            <template x-for="(contact, ci) in (r._searchResults || [])" :key="contact.id">
                                                <button @click="selectContact(ri, contact)"
                                                        class="w-full text-left px-3 py-2 border-b border-gray-100 last:border-0 transition-colors"
                                                        :class="ci === (r._searchIdx || 0) ? 'bg-blue-50' : 'hover:bg-gray-50'">
                                                    <div class="text-sm font-medium text-gray-900" x-text="contact.full_name"></div>
                                                    <div class="text-xs text-gray-500 flex items-center gap-2">
                                                        <span x-show="contact.email" x-text="contact.email"></span>
                                                        <span x-show="contact.phone" x-text="contact.phone"></span>
                                                        <span x-show="contact.contact_type" class="text-blue-500" x-text="contact.contact_type"></span>
                                                    </div>
                                                </button>
                                            </template>
                                        </div>

                                        {{-- No results --}}
                                        <div x-show="r._searchOpen && (r._searchResults || []).length === 0 && !r._searching && (r._searchQuery || '').length >= 2"
                                             class="absolute z-30 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-center text-xs text-gray-500">
                                            No contacts found. Enter manually below.
                                        </div>
                                    </div>
                                </template>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Full Name</label>
                                    <input type="text" x-model="r.name" :readonly="r.readonly"
                                           class="w-full rounded-lg border px-3 py-2 text-sm"
                                           :class="r.readonly ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">ID Number</label>
                                    <input type="text" x-model="r.id_number" :readonly="r.readonly"
                                           class="w-full rounded-lg border px-3 py-2 text-sm"
                                           :class="r.readonly ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'"
                                           placeholder="SA ID or Passport">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                                        <input type="email" x-model="r.email" :readonly="r.readonly"
                                               class="w-full rounded-lg border px-3 py-2 text-sm"
                                               :class="r.readonly ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Cell Phone</label>
                                        <input type="tel" x-model="r.cell" :readonly="r.readonly"
                                               class="w-full rounded-lg border px-3 py-2 text-sm"
                                               :class="r.readonly ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Physical Address</label>
                                    <input type="text" x-model="r.address" :readonly="r.readonly"
                                           class="w-full rounded-lg border px-3 py-2 text-sm"
                                           :class="r.readonly ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'"
                                           placeholder="Residential address">
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Add second owner button (only when a landlord exists but no second landlord yet) --}}
                <button x-show="hasRoleRecipient('landlord') && !hasSecondRoleRecipient('landlord')"
                        @click="addSecondOwner()"
                        class="w-full mt-3 py-2.5 border-2 border-dashed border-emerald-300 rounded-xl text-sm text-emerald-600 hover:border-emerald-500 hover:text-emerald-700 transition">
                    + Add Second Owner (Co-owner)
                </button>

                <button @click="addRecipient()" class="w-full mt-3 py-2.5 border-2 border-dashed border-gray-300 rounded-xl text-sm text-gray-500 hover:border-blue-400 hover:text-blue-600 transition">
                    + Add Recipient
                </button>
            </div>

            {{-- ======== STEP 4: Details ======== --}}
            <div x-show="currentStep === 4" x-cloak>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Document Details</h3>

                {{-- Auto-fill notice --}}
                <div x-show="property._selected && (details._autoFilled || false)" class="mb-4 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-xs text-emerald-700 flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Some fields were auto-filled from the selected property. You can adjust them below.
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Monthly Rental (R)</label>
                            <input type="text" x-model="details.monthly_rental" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. 12000">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Deposit (R)</label>
                            <input type="text" x-model="details.deposit" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. 12000">
                        </div>
                    </div>

                    {{-- Lease dates with duration selector --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Lease Start Date</label>
                        <input type="date" x-model="details.lease_start"
                               @change="calculateLeaseEnd()"
                               class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Lease Duration</label>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <template x-for="opt in [{value: 6, label: '6 months'}, {value: 12, label: '12 months'}, {value: 24, label: '24 months'}, {value: 0, label: 'Custom'}]" :key="opt.value">
                                <button type="button"
                                        @click="details._duration = opt.value; calculateLeaseEnd()"
                                        class="px-3 py-1.5 rounded-lg border text-xs font-medium transition"
                                        :class="details._duration === opt.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'"
                                        x-text="opt.label"></button>
                            </template>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Lease End Date</label>
                        <input type="date" x-model="details.lease_end"
                               :readonly="details._duration !== 0"
                               class="w-full rounded-lg border px-3 py-2 text-sm"
                               :class="details._duration !== 0 ? 'border-gray-200 bg-gray-100 text-gray-500' : 'border-slate-300 bg-white text-slate-900'">
                        <p x-show="details._duration !== 0 && details.lease_end" class="text-xs text-gray-400 mt-1" x-text="'Auto-calculated: ' + details._duration + ' months from start'"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Commission (%)</label>
                            <input type="text" x-model="details.commission" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. 8.5">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Marketing Fee (R)</label>
                            <input type="text" x-model="details.marketing_fee" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. 2500">
                        </div>
                    </div>

                    {{-- Dynamic manual fields from template --}}
                    <template x-if="manualFields.length > 0">
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <p class="text-xs text-gray-500 mb-3">Additional template fields</p>
                            <div class="grid grid-cols-2 gap-4">
                                <template x-for="mf in manualFields" :key="mf.id">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1" x-text="mf.name"></label>
                                        <input type="text"
                                               x-model="details['named_field_' + mf.id]"
                                               class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm"
                                               :placeholder="mf.name">
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ======== STEP 5: Fill & Review ======== --}}
            <div x-show="currentStep === 5" x-cloak>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Fill & Review</h3>
                <p class="text-xs text-gray-500 mb-4">Fields are shown in document order. Pre-filled values come from property and recipient data.</p>

                {{-- All fields in document order (no party grouping) --}}
                <div class="space-y-3">
                    <template x-for="(f, fi) in allWizardFields" :key="f.id">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-medium text-gray-600">
                                    <span x-text="fieldLabel(f)"></span>
                                    <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full"
                                          :class="isCreatorField(f) ? 'bg-blue-100 text-blue-600' : 'bg-amber-100 text-amber-600'"
                                          x-text="fieldRoleLabel(f)"></span>
                                </label>
                                <select @change="setFieldParty(f.id, $event.target.value)"
                                        class="text-[10px] rounded border border-gray-200 px-1.5 py-0.5 bg-gray-50 text-gray-500 ml-2">
                                    <template x-for="opt in partyOptions" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label" :selected="getFieldParty(f) === opt.value"></option>
                                    </template>
                                </select>
                            </div>

                            {{-- Text / placeholder --}}
                            <template x-if="fieldInputType(f) === 'text'">
                                <input type="text"
                                       :value="fieldValues[f.id] || ''"
                                       @input="setFieldValue(f.id, $event.target.value)"
                                       @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                       class="w-full rounded-lg border px-3 py-2 text-sm"
                                       :class="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'border-green-400 bg-green-50' : 'border-slate-300 bg-white'"
                                       :placeholder="fieldLabel(f)">
                            </template>

                            {{-- Date --}}
                            <template x-if="fieldInputType(f) === 'date'">
                                <input type="date"
                                       :value="fieldValues[f.id] || ''"
                                       @input="setFieldValue(f.id, $event.target.value)"
                                       @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                       class="w-full rounded-lg border px-3 py-2 text-sm"
                                       :class="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'border-green-400 bg-green-50' : 'border-slate-300 bg-white'">
                            </template>

                            {{-- Selection dropdown --}}
                            <template x-if="fieldInputType(f) === 'select'">
                                <select :value="fieldValues[f.id] || ''"
                                        @change="setFieldValue(f.id, $event.target.value)"
                                        @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                        class="w-full rounded-lg border px-3 py-2 text-sm"
                                        :class="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'border-green-400 bg-green-50' : 'border-slate-300 bg-white'">
                                    <option value="">Select...</option>
                                    <template x-for="opt in (f.options || [])" :key="opt">
                                        <option :value="opt" x-text="opt" :selected="fieldValues[f.id] === opt"></option>
                                    </template>
                                </select>
                            </template>

                            {{-- Tick selector --}}
                            <template x-if="fieldInputType(f) === 'tick'">
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="opt in (f.options || ['Yes', 'No'])" :key="opt">
                                        <button type="button"
                                                @click="setFieldValue(f.id, opt); highlightField(f.id)"
                                                class="px-3 py-1.5 rounded border text-xs font-medium transition"
                                                :class="fieldValues[f.id] === opt ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'"
                                                x-text="opt"></button>
                                    </template>
                                </div>
                            </template>

                            {{-- Strikethrough toggle --}}
                            <template x-if="fieldInputType(f) === 'toggle'">
                                <label class="flex items-center gap-2 cursor-pointer"
                                       @click="highlightField(f.id)">
                                    <input type="checkbox"
                                           :checked="fieldValues[f.id] === 'strikethrough'"
                                           @change="setFieldValue(f.id, $event.target.checked ? 'strikethrough' : '')"
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">Apply strikethrough</span>
                                </label>
                            </template>

                            {{-- Condition / clause textarea --}}
                            <template x-if="fieldInputType(f) === 'textarea'">
                                <textarea :value="fieldValues[f.id] || ''"
                                          @input="setFieldValue(f.id, $event.target.value)"
                                          @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                          rows="3"
                                          class="w-full rounded-lg border px-3 py-2 text-sm"
                                          :class="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'border-green-400 bg-green-50' : 'border-slate-300 bg-white'"
                                          :placeholder="fieldLabel(f)"></textarea>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Additional Clauses --}}
                <div class="mt-6 mb-4 p-3 border border-dashed border-blue-300 rounded-lg bg-blue-50/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-sm font-semibold text-blue-700">Additional Clauses</span>
                            <p class="text-xs text-blue-500 mt-0.5">
                                Add standard clauses from the clause library
                            </p>
                        </div>
                        <button type="button" @click="showClauseLibrary = true"
                                class="text-sm px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            + Insert Clause
                        </button>
                    </div>

                    {{-- Selected clauses list --}}
                    <template x-if="selectedClauses.length > 0">
                        <div class="mt-3 space-y-2">
                            <template x-for="(clause, idx) in selectedClauses" :key="clause.id">
                                <div class="flex items-start gap-2 bg-white p-2 rounded border border-blue-200">
                                    <span class="text-xs font-semibold text-blue-600 mt-0.5"
                                          x-text="(idx + 1) + '.'"></span>
                                    <div class="flex-1">
                                        <span class="text-xs font-semibold text-gray-700"
                                              x-text="clause.name"></span>
                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-2"
                                           x-text="clause.text"></p>
                                    </div>
                                    <button @click="removeClause(idx)"
                                            class="text-gray-400 hover:text-red-500 text-xs">
                                        &times;
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ======== STEP 6: Signing Setup ======== --}}
            <div x-show="currentStep === 6" x-cloak>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Signing Setup</h3>

                {{-- Signing order cards --}}
                <h4 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Signing Order</h4>
                <div class="space-y-2 mb-6">
                    <template x-for="(r, ri) in recipients" :key="r.role + '_' + (r.name || ri)">
                        <div class="p-3 rounded-lg border border-gray-200 bg-white transition-all">
                            <div class="flex items-start justify-between">
                                <span class="text-sm font-bold text-gray-500 mr-3 w-6 text-center mt-1" x-text="ri + 1"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm font-semibold text-gray-800" x-text="signingRoleLabel(r.role) + ': ' + (r.name || '(unknown)')"></span>
                                    </div>
                                    <div class="mt-2" x-show="r.role !== 'agent'">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Email address</label>
                                        <input type="email"
                                               x-model="r.email"
                                               :disabled="r.skipEmail"
                                               :class="r.skipEmail ? 'bg-gray-100 text-gray-400' : 'bg-white text-gray-900'"
                                               class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                               placeholder="Email address">
                                        <p class="text-xs text-gray-400 mt-1">Edit if signer uses a different email address</p>
                                    </div>
                                    <div x-show="r.role === 'agent'" class="text-xs text-gray-500">
                                        <span x-text="r.email"></span>
                                        <span x-show="r.cell"> | </span>
                                        <span x-show="r.cell" x-text="r.cell"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2 ml-9">
                                <template x-if="r.role === 'agent'">
                                    <span class="text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded">Signs first — locked</span>
                                </template>
                                <template x-if="r.role !== 'agent'">
                                    <div>
                                        <div class="flex items-center gap-2 mb-2">
                                            <button type="button"
                                                    x-show="ri > 0"
                                                    @click="moveRecipient(ri, 'up')"
                                                    class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 flex items-center gap-1">
                                                &uarr; Move Up
                                            </button>
                                            <button type="button"
                                                    x-show="ri < recipients.length - 1"
                                                    @click="moveRecipient(ri, 'down')"
                                                    class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 flex items-center gap-1">
                                                &darr; Move Down
                                            </button>
                                        </div>
                                        <select x-model="signingActions[ri]"
                                                :disabled="r.skipEmail"
                                                class="text-xs rounded border border-gray-300 bg-white text-gray-700 px-2 py-1">
                                            <option value="send_after" x-bind:disabled="!r.email || r.skipEmail">Send after previous</option>
                                            <option value="sign_later">Sign later</option>
                                        </select>
                                        <label class="flex items-center gap-2 mt-2 text-sm">
                                            <input type="checkbox"
                                                   x-model="r.skipEmail"
                                                   @change="if (r.skipEmail) signingActions[ri] = 'sign_later'"
                                                   class="rounded">
                                            <span class="text-gray-600 text-xs">Exclude from email — will sign in person or via primary recipient</span>
                                        </label>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Field summary --}}
                <h4 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Document Summary</h4>
                <div class="p-4 rounded-lg border border-gray-200 bg-gray-50 space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-gray-700">
                        <span class="text-green-600">&#10003;</span>
                        <span x-text="fieldSummary.creatorFilled + ' fields completed by you'"></span>
                    </div>
                    <template x-for="sg in fieldSummary.signerGroups" :key="sg.role">
                        <div class="flex items-center gap-2 text-gray-500">
                            <span>&#9203;</span>
                            <span x-text="sg.count + ' fields for ' + sg.label + ' to complete'"></span>
                        </div>
                    </template>
                    <template x-for="zg in fieldSummary.signatureZones" :key="zg.role">
                        <div class="flex items-center gap-2 text-gray-500">
                            <span>&#9997;</span>
                            <span x-text="zg.label + ': ' + zg.initials + ' initials + ' + zg.signatures + ' signature'"></span>
                        </div>
                    </template>
                </div>
            </div>

            </div>{{-- end flex-1 p-6 --}}
        </div>

        {{-- RESIZE HANDLE --}}
        <div class="w-1 bg-gray-200 hover:bg-blue-400 cursor-col-resize flex-shrink-0 transition-colors"
             @mousedown.prevent="startResize($event)"></div>

        {{-- RIGHT PANEL: Document Preview --}}
        <div class="flex-1 overflow-y-auto p-6 bg-gray-100 dark:bg-gray-800 min-w-0">

            {{-- Web template preview --}}
            <div x-show="previewRenderType === 'web' && previewHtml"
                 x-html="previewHtml"
                 class="web-template-preview">
            </div>
            <style>
                .field-highlighted {
                    background: rgba(255,200,0,0.3) !important;
                    outline: 2px solid #f59e0b;
                    border-radius: 2px;
                }
            </style>

            {{-- PDF page-image preview --}}
            <div x-show="previewRenderType === 'pdf' && previewPages.length > 0">
                <template x-for="(pageUrl, pi) in previewPages" :key="pi">
                    <div style="margin-bottom:24px;">
                        <div style="font-size:0.75rem; color:#6b7280; margin-bottom:4px;" x-text="'Page ' + (pi+1)"></div>
                        {{-- Container: matches editor .dp-page-container exactly --}}
                        <div style="position:relative; width:100%; max-width:800px; overflow:visible; padding:0; margin:0;">
                            {{-- Image: matches editor .dp-page-img exactly --}}
                            <img :src="pageUrl" :alt="'Page ' + (pi+1)"
                                 style="width:100%; display:block; user-select:none; padding:0; margin:0;" draggable="false">
                            {{-- Fields: matches editor .dp-field positioning --}}
                            {{-- NOTE: ALL styles must be in :style (not split across style + :style) --}}
                            {{-- because Alpine.js string :style REPLACES static style, not merges --}}
                            <template x-for="f in fieldsOnPage(pi)" :key="f.id">
                                <div :style="'position:absolute; left:' + f.position.x + '%; top:' + f.position.y + '%; width:' + f.size.width + '%; height:' + f.size.height + '%; display:flex; align-items:center; padding:0 4px; overflow:hidden; box-sizing:border-box; transition:box-shadow 0.2s; '
                                         + fieldOverlayStyle(f)
                                         + (highlightedFieldId === f.id ? ' box-shadow:0 0 0 2px rgba(59,130,246,0.8);' : '')">
                                    <span :style="'white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:' + Math.max(8, Math.min(14, (f.size.height * 0.6))) + 'px; color:' + fieldOverlayTextColor(f) + ';'"
                                          x-text="fieldOverlayText(f)">
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Pack summary / slot selection --}}
            <div x-show="isPackFlow && packPreview" class="p-6" x-cloak>
                <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                    <div class="font-semibold text-blue-700 mb-2 text-base">
                        <span x-text="selectedPackName"></span>
                    </div>

                    {{-- Simple pack (no slots) — just list the templates --}}
                    <template x-if="!packHasSlots">
                        <div>
                            <p class="text-xs text-gray-500 mb-3">This pack contains the following documents in order:</p>
                            <template x-for="(item, i) in (packPreview?.items || [])" :key="i">
                                <div class="flex items-center gap-2 py-2 border-b border-slate-100 last:border-0">
                                    <span class="text-xs font-bold text-gray-400 w-5 text-center" x-text="i+1"></span>
                                    <span class="text-sm text-gray-700" x-text="item.template?.name || 'Unknown'"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Pack with slots — show slot selection UI --}}
                    <template x-if="packHasSlots">
                        <div>
                            <p class="text-xs text-gray-500 mb-3">Configure which documents to include:</p>
                            <div class="space-y-3">
                                <template x-for="slot in packSlots" :key="slot.key">
                                    <div class="border rounded-lg p-3">
                                        {{-- Required slot --}}
                                        <template x-if="slot.type === 'required'">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
                                                <span class="text-sm text-gray-700" x-text="slot.templates[0].name"></span>
                                                <span class="text-[10px] text-gray-400">Required</span>
                                            </div>
                                        </template>

                                        {{-- Selectable slot — radio buttons --}}
                                        <template x-if="slot.type === 'selectable'">
                                            <div>
                                                <span class="text-xs font-semibold text-gray-500 uppercase"
                                                      x-text="slot.label || 'Select one'"></span>
                                                <div class="mt-2 space-y-1">
                                                    <template x-for="tmpl in slot.templates" :key="tmpl.id">
                                                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer p-2 rounded hover:bg-gray-50">
                                                            <input type="radio"
                                                                   :name="'slot-' + slot.group"
                                                                   :value="tmpl.id"
                                                                   x-model.number="slotSelections[slot.group]"
                                                                   class="w-3.5 h-3.5 text-teal-600">
                                                            <span x-text="tmpl.name"></span>
                                                        </label>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Optional slot — checkbox --}}
                                        <template x-if="slot.type === 'optional'">
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                                <input type="checkbox"
                                                       :value="slot.templates[0].id"
                                                       x-model.number="optionalSelections"
                                                       class="w-3.5 h-3.5 text-teal-600 rounded">
                                                <span x-text="slot.templates[0].name"></span>
                                                <span class="text-[10px] text-gray-400">Optional</span>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            {{-- Resolved template count --}}
                            <div class="mt-3 text-xs text-gray-500">
                                <span x-text="resolvedPackTemplateIds.length"></span> document(s) will be included
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Empty state: no template selected --}}
            <div x-show="!isPackFlow && previewRenderType === 'pdf' && previewPages.length === 0 && !previewHtml" class="flex items-center justify-center h-full">
                <div class="text-center text-gray-400">
                    <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p>Select a template to preview</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== STICKY BOTTOM BAR ===== --}}
    <div class="flex-shrink-0 px-6 py-3 bg-white dark:bg-gray-900 border-t border-gray-200 flex items-center justify-between">
        <div>
            <button x-show="currentStep > 1" @click="goBack()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                &larr; Back
            </button>
        </div>
        <div>
            <button x-show="flowId" @click="saveDraft()" :disabled="saving"
                    class="px-4 py-2 text-sm font-medium bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-40 transition">
                <span x-show="!saving">Save Draft</span>
                <span x-show="saving" class="flex items-center gap-1">
                    <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                    Saving...
                </span>
            </button>
        </div>
        <div>
            <button @click="goNext()" :disabled="loading || !canGoNext()"
                    class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition">
                <span x-show="!loading" x-text="nextButtonLabel()"></span>
                <span x-show="loading" class="flex items-center gap-1">
                    <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                    Saving...
                </span>
            </button>
        </div>
    </div>

    {{-- Clause Library Modal --}}
    <template x-if="showClauseLibrary">
        <div class="fixed inset-0 z-50 flex items-center justify-center"
             style="background:rgba(0,0,0,0.4);" @click.self="showClauseLibrary = false">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col">
                <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Clause Library</h3>
                    <button @click="showClauseLibrary = false" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
                </div>

                <div class="p-4 border-b border-gray-200">
                    <input type="text" x-model="clauseSearch" placeholder="Search clauses..."
                           class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-2">
                    <template x-for="clause in filteredClauses" :key="clause.id">
                        <div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors"
                             @click="insertClause(clause)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-700" x-text="clause.name"></span>
                                <span class="text-[10px] text-gray-400" x-text="clause.is_global ? 'Global' : 'Personal'"></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 line-clamp-3" x-text="clause.text"></p>
                        </div>
                    </template>
                    <template x-if="filteredClauses.length === 0">
                        <p class="text-xs text-gray-400 text-center py-8">No clauses found</p>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function esignWizard() {
    // Server data
    const serverFlow = @json($flow);
    const serverTemplates = @json($templates);
    const serverWebPacks = @json($webPacks ?? []);
    const serverPdfPacks = @json($pdfPacks ?? []);
    const serverTemplate = @json($template ?? null);
    const serverFields = @json($fields ?? []);
    const serverCreatorFields = @json($creatorFields ?? []);
    const serverSignerFields = @json($signerFields ?? []);
    const serverAllWizardFields = @json($allWizardFields ?? []);
    const serverPageImages = @json($pageImages ?? []);
    const serverRecipients = @json($recipients ?? []);
    const serverStepData = @json($stepData ?? []);
    const serverManualFields = @json($manualFields ?? []);
    const serverCurrentStep = {{ $safeStep }};
    const serverIsWebTemplate = @json($isWebTemplate ?? false);
    const serverTemplateId = @json($templateId ?? null);
    const csrfToken = '{{ csrf_token() }}';
    const currentUser = @json(auth()->user()->only(['id', 'name', 'email']));
    const storeUrl = '{{ route("docuperfect.esign.store") }}';

    // Transform drafts from server (with relative time and property address)
    const serverDrafts = @json($drafts ?? []).map(d => {
        const stepData = d.step_data || {};
        const updatedAt = d.updated_at ? new Date(d.updated_at) : null;
        let ago = '';
        if (updatedAt) {
            const diffMs = Date.now() - updatedAt.getTime();
            const diffMin = Math.floor(diffMs / 60000);
            if (diffMin < 1) ago = 'just now';
            else if (diffMin < 60) ago = diffMin + ' minute' + (diffMin !== 1 ? 's' : '') + ' ago';
            else {
                const diffHr = Math.floor(diffMin / 60);
                if (diffHr < 24) ago = diffHr + ' hour' + (diffHr !== 1 ? 's' : '') + ' ago';
                else {
                    const diffDay = Math.floor(diffHr / 24);
                    ago = diffDay === 1 ? 'yesterday' : diffDay + ' days ago';
                }
            }
        }
        return {
            id: d.id,
            current_step: d.current_step || 1,
            template_name: d.template?.name || 'Untitled',
            property_address: stepData.property?.address || stepData.property?.title || '',
            updated_ago: ago,
        };
    });

    // Build template groups from document types
    function buildTemplateGroups(templates, search) {
        const types = {};
        const typeLabels = {
            'rental': 'Rental',
            'sales': 'Sales',
            'compliance': 'Compliance',
        };

        templates.forEach(t => {
            const type = (t.template_type || t.document_type?.name || 'other').toLowerCase();
            if (!types[type]) {
                types[type] = { type, label: typeLabels[type] || type.charAt(0).toUpperCase() + type.slice(1), templates: [], open: true };
            }
            // Apply search filter
            if (search.trim()) {
                const q = search.toLowerCase();
                if (!t.name.toLowerCase().includes(q)) return;
            }
            types[type].templates.push(t);
        });

        // Return in preferred order
        const order = ['rental', 'sales', 'compliance'];
        const result = [];
        order.forEach(key => { if (types[key]) result.push(types[key]); });
        Object.keys(types).forEach(key => { if (!order.includes(key)) result.push(types[key]); });
        return result;
    }

    // Role aliases for field matching
    const roleAliases = {
        'lessor': 'landlord', 'lessee': 'tenant',
        'landlord': 'landlord', 'tenant': 'tenant',
        'buyer': 'buyer', 'seller': 'seller',
        'agent': 'agent', 'creator': 'agent',
    };

    function getRoleLabel(role) {
        const labels = {
            'agent': 'Agent', 'creator': 'Agent',
            'landlord': 'Landlord', 'lessor': 'Landlord',
            'tenant': 'Tenant', 'lessee': 'Tenant',
            'buyer': 'Buyer', 'seller': 'Seller',
            'witness': 'Witness',
        };
        return labels[role] || (role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Signer');
    }

    return {
        // Core state
        flowId: serverFlow?.id || null,
        currentStep: serverCurrentStep,
        stepLabels: ['Template', 'Property', 'Recipients', 'Details', 'Fill & Review', 'Sign & Send'],
        loading: false,
        saving: false,

        // Toast
        toast: { show: false, message: '', type: 'success' },

        // Drafts
        drafts: serverDrafts,

        // Step 1: Templates
        allTemplates: serverTemplates,
        templateSearch: '',
        selectedTemplateId: serverTemplate?.id || null,
        templateName: serverTemplate?.name || '',
        allWebPacks: serverWebPacks,
        allPdfPacks: serverPdfPacks,
        selectedPackId: null,
        selectedPackName: '',
        selectedPdfPackId: null,
        isPackFlow: false,
        packPreview: null,
        packSlots: [],
        slotSelections: {},
        optionalSelections: [],

        // Preview
        previewPages: serverPageImages || [],
        previewFields: serverFields || [],
        previewRenderType: 'pdf',
        previewHtml: '',

        // Step 2: Property
        property: {
            address: serverStepData?.property?.address || serverStepData?.property?.title || '',
            suburb: serverStepData?.property?.suburb || '',
            erf: serverStepData?.property?.erf || '',
            complex_name: serverStepData?.property?.complex_name || '',
            property_type: serverStepData?.property?.property_type || '',
            _selected: !!(serverStepData?.property?.property_id),
            _property_id: serverStepData?.property?.property_id || null,
            _property_source: serverStepData?.property?._property_source || null,
            _propertyData: null,
        },
        propSearchQuery: '',
        propSearchResults: [],
        propSearchOpen: false,
        propSearching: false,
        propSearchIdx: 0,

        // Step 3: Recipients
        recipients: serverRecipients.length > 0
            ? serverRecipients.map((r, i) => ({ ...r, readonly: i === 0 && r.role === 'agent' }))
            : [{ order: 1, role: 'agent', name: currentUser.name, id_number: '', email: currentUser.email || '', cell: '', address: '', readonly: true }],

        // Step 4: Details — deposit defaults to rental if empty
        details: (() => {
            const prop = serverStepData?.property || {};
            const det = serverStepData?.details || {};
            const d = {
                monthly_rental: det.monthly_rental || prop.rental_amount || '',
                deposit: det.deposit || prop.deposit_amount || '',
                lease_start: det.lease_start || '',
                lease_end: det.lease_end || '',
                commission: det.commission || prop.commission_percent || '10',
                marketing_fee: det.marketing_fee || prop.marketing_fee || '',
                _duration: det._duration ?? 12,
                _autoFilled: false,
            };
            // Restore saved manual field values (named_field_{id} keys)
            (serverManualFields || []).forEach(mf => {
                const key = 'named_field_' + mf.id;
                if (det[key]) d[key] = det[key];
            });
            // Auto-set deposit = rental when deposit is empty
            if (!d.deposit && d.monthly_rental) d.deposit = d.monthly_rental;
            return d;
        })(),

        // Manual named fields (for dynamic inputs on step 4)
        manualFields: serverManualFields || [],

        // Step 5: Fields
        creatorFields: serverCreatorFields || [],
        signerFields: serverSignerFields || [],
        allWizardFields: serverAllWizardFields || [],
        fieldValues: {},
        fieldPartyOverrides: {},
        highlightedFieldId: null,

        // Clause library
        showClauseLibrary: false,
        clauseSearch: '',
        allClauses: [],
        selectedClauses: [],

        // Step 6: Signing setup
        signingActions: [],

        // Resize
        leftPanelPx: 420,
        _resizing: false,

        init() {
            // Initialize field values from server data (unified ordered list)
            const allFields = this.allWizardFields.length > 0
                ? this.allWizardFields
                : [...(this.creatorFields || []), ...(this.signerFields || [])];
            allFields.forEach(f => {
                if (f.value) this.fieldValues[f.id] = f.value;
            });

            // Also restore from fill_review step data
            const frValues = serverStepData?.fill_review?.fieldValues || {};
            Object.keys(frValues).forEach(k => {
                if (frValues[k]) this.fieldValues[k] = frValues[k];
            });

            // Restore party overrides from fill_review step data
            const savedOverrides = serverStepData?.fill_review?.partyOverrides || {};
            Object.keys(savedOverrides).forEach(k => {
                if (savedOverrides[k]) this.fieldPartyOverrides[k] = savedOverrides[k];
            });

            // Sync previewFields with allWizardFields so overlay uses same IDs as fieldValues
            if (this.allWizardFields.length > 0 && this.previewRenderType === 'pdf') {
                this.previewFields = this.allWizardFields;
            }

            // Initialize contact search state on existing recipients
            this.recipients.forEach((r, i) => {
                if (!r.hasOwnProperty('_searchQuery')) r._searchQuery = '';
                if (!r.hasOwnProperty('_searchResults')) r._searchResults = [];
                if (!r.hasOwnProperty('_searchOpen')) r._searchOpen = false;
                if (!r.hasOwnProperty('_searching')) r._searching = false;
                if (!r.hasOwnProperty('_searchIdx')) r._searchIdx = 0;
                if (!r.hasOwnProperty('_contact_id')) r._contact_id = null;
                // Restore skipEmail and overridden email from signing_setup step data
                const saved = serverStepData?.signing_setup?.[i] || {};
                if (!r.hasOwnProperty('skipEmail')) r.skipEmail = saved.skipEmail || false;
                if (saved.email && saved.email !== r.email) r.email = saved.email;
            });

            // Initialize signing actions for each recipient
            this.signingActions = this.recipients.map((r, i) => {
                if (r.role === 'agent') return 'signs_now';
                if (r.skipEmail) return 'sign_later';
                if (!r.email) return 'sign_later';
                return serverStepData?.signing_setup?.[i]?.action || 'send_after';
            });

            // Load clause library
            this.loadClauses();

            // Restore selected clauses from step data
            const savedClauses = serverStepData?.fill_review?.clauses || [];
            if (savedClauses.length > 0) this.selectedClauses = savedClauses;

            // Load web template preview on steps 2+ (PDF preview loads via serverPageImages)
            if (serverIsWebTemplate && this.currentStep > 1 && this.flowId && serverTemplateId) {
                this.previewRenderType = 'web';
                this.loadTemplatePreview(serverTemplateId);
            }

            // Global mouse events for resize
            document.addEventListener('mousemove', (e) => this._onResize(e));
            document.addEventListener('mouseup', () => this._resizing = false);
        },

        // ---- Template grouping ----
        get templateGroups() {
            return buildTemplateGroups(this.allTemplates || [], this.templateSearch);
        },

        get filteredClauses() {
            const search = this.clauseSearch.toLowerCase().trim();
            if (!search) return this.allClauses;
            return this.allClauses.filter(c =>
                c.name.toLowerCase().includes(search) ||
                c.text.toLowerCase().includes(search)
            );
        },

        async loadClauses() {
            try {
                const response = await fetch('{{ route("docuperfect.clauses.json") }}');
                if (response.ok) {
                    this.allClauses = await response.json();
                }
            } catch (e) {
                console.error('Failed to load clauses:', e);
            }
        },

        insertClause(clause) {
            if (this.selectedClauses.find(c => c.id === clause.id)) return;
            this.selectedClauses.push({...clause});
            this.showClauseLibrary = false;
        },

        removeClause(idx) {
            this.selectedClauses.splice(idx, 1);
        },

        // ---- Template selection (Step 1) ----
        selectTemplate(t) {
            this.selectedTemplateId = t.id;
            this.templateName = t.name;
            this.selectedPackId = null;
            this.selectedPackName = '';
            this.selectedPdfPackId = null;
            this.isPackFlow = false;
            this.packPreview = null;
            this.loadTemplatePreview(t.id);
        },

        selectPack(p) {
            this.selectedPackId = p.id;
            this.selectedPackName = p.name;
            this.selectedTemplateId = null;
            this.templateName = '';
            this.selectedPdfPackId = null;
            this.isPackFlow = true;

            // Show pack summary in right pane
            this.previewHtml = '';
            this.previewPages = [];
            this.previewReady = true;
            this.packPreview = p;

            // Build slot structure from pack items
            this._buildPackSlots(p);
        },

        _buildPackSlots(p) {
            const items = p.items || [];
            const slots = [];
            const selectableGroups = {};

            // Group selectable items by slot_group
            items.forEach(item => {
                const slotType = item.slot_type || 'required';
                const tmpl = {
                    id: item.template?.id || item.template_id,
                    name: item.template?.name || 'Unknown',
                };

                if (slotType === 'selectable') {
                    const group = item.slot_group || 1;
                    if (!selectableGroups[group]) {
                        selectableGroups[group] = {
                            key: 'sel-' + group,
                            type: 'selectable',
                            group: group,
                            label: item.slot_label || '',
                            templates: [],
                        };
                    }
                    selectableGroups[group].templates.push(tmpl);
                    if (item.slot_label) selectableGroups[group].label = item.slot_label;
                } else if (slotType === 'optional') {
                    slots.push({
                        key: 'opt-' + tmpl.id,
                        type: 'optional',
                        templates: [tmpl],
                    });
                } else {
                    slots.push({
                        key: 'req-' + tmpl.id,
                        type: 'required',
                        templates: [tmpl],
                    });
                }
            });

            // Insert selectable groups in sort order
            Object.values(selectableGroups).forEach(g => slots.push(g));

            this.packSlots = slots;

            // Reset selections
            this.slotSelections = {};
            this.optionalSelections = [];

            // Pre-select first option in each selectable group
            Object.values(selectableGroups).forEach(g => {
                if (g.templates.length > 0) {
                    this.slotSelections[g.group] = g.templates[0].id;
                }
            });
        },

        get packHasSlots() {
            if (!this.packPreview) return false;
            const items = this.packPreview.items || [];
            return items.some(i => (i.slot_type || 'required') !== 'required');
        },

        get resolvedPackTemplateIds() {
            if (!this.packPreview) return [];

            // If no slots, return all item template IDs
            if (!this.packHasSlots) {
                return (this.packPreview.items || []).map(i => i.template?.id || i.template_id).filter(Boolean);
            }

            const ids = [];
            for (const slot of this.packSlots) {
                if (slot.type === 'required') {
                    ids.push(slot.templates[0].id);
                } else if (slot.type === 'selectable') {
                    const selected = this.slotSelections[slot.group];
                    if (selected) ids.push(selected);
                } else if (slot.type === 'optional') {
                    const tmplId = slot.templates[0].id;
                    if (this.optionalSelections.includes(tmplId)) {
                        ids.push(tmplId);
                    }
                }
            }
            return ids;
        },

        selectPdfPack(p) {
            this.selectedPdfPackId = p.id;
            this.selectedPackName = p.name;
            this.selectedTemplateId = null;
            this.templateName = '';
            this.selectedPackId = null;
            this.isPackFlow = false;
            this.packPreview = null;

            // Preview the first template in the pack
            if (p.templates && p.templates.length > 0) {
                this.loadTemplatePreview(p.templates[0].id);
            }
        },

        async loadTemplatePreview(templateId) {
            try {
                let url = '/docuperfect/esign/api/template/' + templateId + '/pages';
                if (this.flowId) url += '?flow_id=' + this.flowId;
                const resp = await fetch(url);
                const data = await resp.json();
                this.previewRenderType = data.render_type || 'pdf';
                if (data.render_type === 'web') {
                    this.previewHtml = data.html || '';
                    this.previewPages = [];
                    this.previewFields = [];
                } else {
                    this.previewHtml = '';
                    this.previewPages = data.pages || [];
                    this.previewFields = data.fields || [];
                }
            } catch (e) {
                console.error('Failed to load template preview:', e);
            }
        },

        // ---- Field helpers ----
        fieldsOnPage(pageIndex) {
            return (this.previewFields || []).filter(f => f.pageIndex === pageIndex && f.position && f.size);
        },

        fieldLabel(f) {
            return f.named_field_name || f.label || f.id;
        },

        fieldInputType(f) {
            const type = (f.type || f.tag_type || 'placeholder').toLowerCase();
            if (type === 'date') return 'date';
            if (type === 'selection') return 'select';
            if (type === 'tick') return 'tick';
            if (type === 'strikethrough' || type === 'diagonal') return 'toggle';
            if (type === 'condition' || type === 'clause') return 'textarea';
            if (type === 'input') return 'text';
            return 'text';
        },

        setFieldValue(fieldId, value) {
            this.fieldValues = { ...this.fieldValues, [fieldId]: value };
            this.refreshPreviewDebounced();
        },

        getFieldParty(f) {
            return this.fieldPartyOverrides[f.id] || f.assignedTo || f.assigned_to || 'agent';
        },

        setFieldParty(fieldId, party) {
            this.fieldPartyOverrides = { ...this.fieldPartyOverrides, [fieldId]: party };
        },

        get partyOptions() {
            const opts = [{ value: 'agent', label: 'Agent (You)' }];
            const roleCounts = {};
            (this.recipients || []).forEach(r => {
                if (r.role === 'agent') return;
                if (!roleCounts[r.role]) roleCounts[r.role] = 0;
                roleCounts[r.role]++;
            });
            const roleIndex = {};
            (this.recipients || []).forEach((r, ri) => {
                if (r.role === 'agent') return;
                if (!roleIndex[r.role]) roleIndex[r.role] = 0;
                roleIndex[r.role]++;
                const roleLabel = getRoleLabel(r.role);
                // Show "Landlord: Koos Kombuis" when name available, else just "Landlord"
                const label = r.name
                    ? (roleLabel + ': ' + r.name)
                    : roleLabel;
                opts.push({ value: r.role + (roleIndex[r.role] > 1 ? '_' + ri : ''), label: label });
            });
            return opts;
        },

        isCreatorField(f) {
            const role = this.fieldPartyOverrides[f.id] || f.assignedTo || f.assigned_to || 'creator';
            return ['creator', 'user', 'agent'].includes(role);
        },

        fieldRoleLabel(f) {
            const role = this.fieldPartyOverrides[f.id] || f.assignedTo || f.assigned_to || 'creator';
            return getRoleLabel(role);
        },

        highlightField(fieldId) {
            this.highlightedFieldId = fieldId;
            setTimeout(() => {
                if (this.highlightedFieldId === fieldId) this.highlightedFieldId = null;
            }, 2000);

            // Web template: highlight matching .field span in preview
            this.clearFieldHighlight();
            if (this.previewRenderType === 'web') {
                // Find field_name from allWizardFields
                const field = (this.allWizardFields || []).find(f => f.id === fieldId);
                const fieldName = field ? (field.field_name || field.name || '') : '';
                if (!fieldName) return;
                const span = document.querySelector('.web-template-preview [data-field="' + fieldName + '"]');
                if (!span) return;
                span.classList.add('field-highlighted');
                span.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },
        clearFieldHighlight() {
            document.querySelectorAll('.field-highlighted').forEach(el => el.classList.remove('field-highlighted'));
        },

        // ---- Preview overlay styling ----
        fieldOverlayStyle(f) {
            const role = f.assignedTo || f.assigned_to || 'creator';
            const isCreator = ['creator', 'user', 'agent'].includes(role);
            if (isCreator) {
                if (this.fieldValues[f.id]) {
                    return 'border:1px dashed rgba(16,185,129,0.7); background:rgba(16,185,129,0.15);';
                }
                return 'border:1px dashed rgba(245,158,11,0.7); background:rgba(245,158,11,0.15);';
            }
            return 'border:1px dashed rgba(156,163,175,0.6); background:rgba(156,163,175,0.15);';
        },

        fieldOverlayTextColor(f) {
            const role = f.assignedTo || f.assigned_to || 'creator';
            const isCreator = ['creator', 'user', 'agent'].includes(role);
            if (isCreator) {
                return this.fieldValues[f.id] ? '#065f46' : '#92400e';
            }
            return '#6b7280';
        },

        fieldOverlayText(f) {
            const role = f.assignedTo || f.assigned_to || 'creator';
            const isCreator = ['creator', 'user', 'agent'].includes(role);
            if (isCreator) {
                return this.fieldValues[f.id] || f.named_field_name || f.label || f.id;
            }
            return getRoleLabel(role);
        },

        // ---- Signing setup helpers ----
        signingRoleLabel(role) {
            return getRoleLabel(role);
        },

        moveRecipient(index, direction) {
            console.log('moveRecipient called', index, direction, this.recipients.length);
            const swapWith = direction === 'up' ? index - 1 : index + 1;
            if (swapWith < 0) return;
            if (swapWith >= this.recipients.length) return;

            const newRecipients = [...this.recipients];
            [newRecipients[index], newRecipients[swapWith]] =
                [newRecipients[swapWith], newRecipients[index]];
            this.recipients = newRecipients;

            if (this.signingActions && this.signingActions.length === this.recipients.length) {
                const newActions = [...this.signingActions];
                [newActions[index], newActions[swapWith]] =
                    [newActions[swapWith], newActions[index]];
                this.signingActions = newActions;
            }
        },

        nextButtonLabel() {
            if (this.currentStep === 6) return 'Sign Document';
            if (this.currentStep === 5) return 'Next \u2192 Signing Setup';
            return 'Next \u2192';
        },

        get fieldSummary() {
            const allFields = this.previewFields || [];
            let creatorFilled = 0;
            const signerCounts = {};
            const signatureZones = {};

            allFields.forEach(f => {
                const role = f.assignedTo || f.assigned_to || 'creator';
                const isCreator = ['creator', 'user', 'agent'].includes(role);
                const fieldType = (f.type || '').toLowerCase();

                if (fieldType === 'sign' || fieldType === 'initial') {
                    const normalizedRole = roleAliases[role] || role;
                    if (!signatureZones[normalizedRole]) {
                        signatureZones[normalizedRole] = { role: normalizedRole, label: getRoleLabel(role), signatures: 0, initials: 0 };
                    }
                    if (fieldType === 'sign') signatureZones[normalizedRole].signatures++;
                    else signatureZones[normalizedRole].initials++;
                    return;
                }

                if (isCreator) {
                    if (this.fieldValues[f.id]) creatorFilled++;
                } else {
                    const normalizedRole = roleAliases[role] || role;
                    if (!signerCounts[normalizedRole]) {
                        signerCounts[normalizedRole] = { role: normalizedRole, label: getRoleLabel(role), count: 0 };
                    }
                    signerCounts[normalizedRole].count++;
                }
            });

            return {
                creatorFilled,
                signerGroups: Object.values(signerCounts),
                signatureZones: Object.values(signatureZones),
            };
        },

        // ---- Signer field groups ----
        get signerFieldGroups() {
            const groups = {};
            (this.signerFields || []).forEach(f => {
                const role = f.assignedTo || f.assigned_to || 'signer';
                const normalizedRole = roleAliases[role] || role;
                if (!groups[normalizedRole]) {
                    groups[normalizedRole] = { role: normalizedRole, label: getRoleLabel(role), fields: [] };
                }
                groups[normalizedRole].fields.push(f);
            });
            return Object.values(groups);
        },

        // ---- Toast ----
        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            const duration = type === 'success' ? 3000 : 6000;
            setTimeout(() => { this.toast.show = false; }, duration);
        },

        // ---- Navigation ----
        canGoToStep(step) {
            if (!this.flowId && step > 1) return false;
            // Can go back to any completed step
            if (step < this.currentStep) return true;
            if (step === this.currentStep) return true;
            return false;
        },

        canGoNext() {
            if (this.currentStep === 1) {
                if (this.selectedPackId && this.packHasSlots) {
                    // All selectable groups must have a selection
                    for (const slot of this.packSlots.filter(s => s.type === 'selectable')) {
                        if (!this.slotSelections[slot.group]) return false;
                    }
                    // Must have at least one resolved template
                    return this.resolvedPackTemplateIds.length > 0;
                }
                return !!(this.selectedTemplateId || this.selectedPackId || this.selectedPdfPackId);
            }
            return true;
        },

        goBack() {
            if (this.currentStep <= 1) return;
            const prevStep = this.currentStep - 1;
            if (prevStep === 1 && !this.flowId) {
                window.location.href = '{{ route("docuperfect.esign.create") }}';
                return;
            }
            if (this.flowId) {
                window.location.href = '/docuperfect/esign/' + this.flowId + '/step/' + prevStep;
            }
        },

        async goToStep(step) {
            if (step === this.currentStep) return;
            if (this.flowId) {
                window.location.href = '/docuperfect/esign/' + this.flowId + '/step/' + step;
            }
        },

        async goNext() {
            if (this.loading || !this.canGoNext()) return;
            this.loading = true;

            try {
                if (this.currentStep === 1) {
                    await this.createFlow();
                } else if (this.currentStep === 6) {
                    await this.prepareSigning();
                } else {
                    await this.saveAndAdvance();
                }
            } catch (e) {
                console.error('goNext error:', e);
                this.showToast('Error: ' + (e.message || 'Something went wrong'), 'error');
            } finally {
                this.loading = false;
            }
        },

        async createFlow() {
            const resp = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    template_id: this.selectedTemplateId,
                    pack_id: this.selectedPackId,
                    is_pack_flow: this.isPackFlow,
                    pdf_pack_id: this.selectedPdfPackId,
                    resolved_template_ids: this.packHasSlots ? this.resolvedPackTemplateIds : null,
                }),
            });
            if (!resp.ok) {
                const text = await resp.text();
                throw new Error('Failed to create flow: ' + text);
            }
            if (resp.redirected) {
                window.location.href = resp.url;
                return;
            }
            const data = await resp.json();
            if (data.redirect) {
                window.location.href = data.redirect;
            }
        },

        getStepData() {
            switch (this.currentStep) {
                case 2: return {
                    address: this.property.address,
                    title: this.property.address,
                    suburb: this.property.suburb,
                    erf: this.property.erf,
                    complex_name: this.property.complex_name,
                    property_type: this.property.property_type,
                    property_id: this.property._property_id || null,
                    _property_source: this.property._property_source || null,
                    rental_amount: this.details.monthly_rental || null,
                    deposit_amount: this.details.deposit || null,
                    commission_percent: this.details.commission || null,
                    marketing_fee: this.details.marketing_fee || null,
                };
                case 3: return {
                    recipients: this.recipients.map((r, i) => ({
                        order: i + 1,
                        role: r.role,
                        name: r.name,
                        id_number: r.id_number || '',
                        email: r.email,
                        cell: r.cell,
                        address: r.address || '',
                        _contact_id: r._contact_id || null,
                        bank_name: r.bank_name || '',
                        bank_account_name: r.bank_account_name || '',
                        bank_account_number: r.bank_account_number || '',
                        bank_branch_name: r.bank_branch_name || '',
                    })),
                };
                case 4: {
                    const detailsData = {
                        monthly_rental: this.details.monthly_rental,
                        deposit: this.details.deposit,
                        lease_start: this.details.lease_start,
                        lease_end: this.details.lease_end,
                        commission: this.details.commission,
                        marketing_fee: this.details.marketing_fee,
                        _duration: this.details._duration,
                    };
                    // Include manual field values under named_field_{id} keys
                    (this.manualFields || []).forEach(mf => {
                        const key = 'named_field_' + mf.id;
                        if (this.details[key]) detailsData[key] = this.details[key];
                    });
                    return detailsData;
                }
                case 5: return { fieldValues: { ...this.fieldValues }, partyOverrides: { ...this.fieldPartyOverrides }, clauses: this.selectedClauses };
                case 6: return this.signingActions.map((action, i) => ({
                    signing_order: i + 1,
                    action,
                    role: this.recipients[i]?.role || '',
                    name: this.recipients[i]?.name || '',
                    email: this.recipients[i]?.email || '',
                    skipEmail: this.recipients[i]?.skipEmail || false,
                }));
                default: return {};
            }
        },

        async saveAndAdvance() {
            const url = '/docuperfect/esign/' + this.flowId + '/step/' + this.currentStep;
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ data: this.getStepData() }),
            });

            if (!resp.ok) {
                const text = await resp.text();
                throw new Error('Save failed: ' + text);
            }

            const result = await resp.json();
            if (result.redirect) {
                window.location.href = result.redirect;
            } else if (result.next_step) {
                window.location.href = '/docuperfect/esign/' + this.flowId + '/step/' + result.next_step;
            }
        },

        async saveDraft() {
            if (!this.flowId) return;
            this.saving = true;
            try {
                const resp = await fetch('/docuperfect/esign/' + this.flowId + '/draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        step: this.currentStep,
                        data: this.getStepData(),
                    }),
                });
                if (resp.ok) {
                    this.showToast('Draft saved', 'success');
                } else {
                    const text = await resp.text();
                    console.error('Draft save failed:', text);
                    this.showToast('Failed to save draft', 'error');
                }
            } catch (e) {
                console.error('Draft save error:', e);
                this.showToast('Failed to save draft', 'error');
            } finally {
                this.saving = false;
            }
        },

        // ---- Prepare Signing ----
        async prepareSigning() {
            if (!this.flowId) return;
            this.loading = true;
            try {
                // First save step 6 data
                const saveUrl = '/docuperfect/esign/' + this.flowId + '/step/6';
                await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ data: this.getStepData() }),
                });

                // Then prepare signing (creates Document + SignatureTemplate + redirects to signing)
                const resp = await fetch('/docuperfect/esign/' + this.flowId + '/prepare-signing', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                });
                if (!resp.ok) {
                    const text = await resp.text();
                    throw new Error('Failed to prepare signing: ' + text);
                }
                if (resp.redirected) {
                    window.location.href = resp.url;
                    return;
                }
                const data = await resp.json();
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            } catch (e) {
                console.error('prepareSigning error:', e);
                this.showToast('Error: ' + (e.message || 'Something went wrong'), 'error');
                this.loading = false;
            }
        },

        // ---- Drafts ----
        async deleteDraft(flowId, index) {
            try {
                const resp = await fetch('/docuperfect/esign/' + flowId, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                });
                if (resp.ok) {
                    this.drafts.splice(index, 1);
                    this.showToast('Draft deleted', 'success');
                } else {
                    this.showToast('Failed to delete draft', 'error');
                }
            } catch (e) {
                this.showToast('Failed to delete draft', 'error');
            }
        },

        // ---- Property search ----
        async searchProperties() {
            const q = (this.propSearchQuery || '').trim();
            if (q.length < 2) {
                this.propSearchResults = [];
                this.propSearchOpen = false;
                return;
            }
            this.propSearching = true;
            this.propSearchIdx = 0;
            try {
                const resp = await fetch('/docuperfect/esign/api/properties?q=' + encodeURIComponent(q));
                this.propSearchResults = await resp.json();
                this.propSearchOpen = this.propSearchResults.length > 0 || q.length >= 2;
            } catch (e) {
                console.error('Property search error:', e);
                this.propSearchResults = [];
            } finally {
                this.propSearching = false;
            }
        },

        selectProperty(result) {
            this.property.address = result.address || '';
            this.property.suburb = result.suburb || '';
            this.property.erf = result.erf_no || '';
            this.property.complex_name = result.complex_name || '';
            this.property.property_type = result.property_type || '';
            this.property._selected = true;
            this.property._property_id = result.id;
            this.property._property_source = result.source;
            this.property._propertyData = result;
            this.propSearchOpen = false;
            this.propSearchQuery = result.display || result.address;

            // Pre-fill details from property data
            if (result.rental_amount && !this.details.monthly_rental) {
                this.details.monthly_rental = String(result.rental_amount);
            }
            if (result.deposit_amount && !this.details.deposit) {
                this.details.deposit = String(result.deposit_amount);
            }
            if (result.commission_percent && !this.details.commission) {
                this.details.commission = String(result.commission_percent);
            }
            if (result.marketing_fee && !this.details.marketing_fee) {
                this.details.marketing_fee = String(result.marketing_fee);
            }
            if (result.lease_start_date && !this.details.lease_start) {
                this.details.lease_start = result.lease_start_date;
            }
            if (result.lease_end_date && !this.details.lease_end) {
                this.details.lease_end = result.lease_end_date;
            }

            this.showToast('Property selected — fields auto-filled', 'success');
        },

        clearPropertySelection() {
            this.property._selected = false;
            this.property._property_id = null;
            this.property._property_source = null;
            this.property._propertyData = null;
            this.propSearchQuery = '';
        },

        // ---- Contact search ----
        async searchContacts(recipientIndex) {
            const r = this.recipients[recipientIndex];
            const q = (r._searchQuery || '').trim();
            if (q.length < 2) {
                r._searchResults = [];
                r._searchOpen = false;
                return;
            }
            r._searching = true;
            r._searchIdx = 0;
            try {
                let url = '/docuperfect/esign/api/contacts?q=' + encodeURIComponent(q);
                if (r.role && r.role !== 'other') url += '&role=' + encodeURIComponent(r.role);
                const resp = await fetch(url);
                r._searchResults = await resp.json();
                r._searchOpen = r._searchResults.length > 0 || q.length >= 2;
            } catch (e) {
                console.error('Contact search error:', e);
                r._searchResults = [];
            } finally {
                r._searching = false;
            }
        },

        selectContact(recipientIndex, contact) {
            const r = this.recipients[recipientIndex];
            r.name = contact.full_name || (contact.first_name + ' ' + contact.last_name);
            r.email = contact.email || '';
            r.cell = contact.phone || '';
            r.id_number = contact.id_number || '';
            r.address = contact.address || '';
            r._contact_id = contact.id;
            r._searchOpen = false;
            r._searchQuery = contact.full_name;

            // Store bank details for WebTemplateDataService
            r.bank_name = contact.bank_name || '';
            r.bank_account_name = contact.bank_account_name || '';
            r.bank_account_number = contact.bank_account_number || '';
            r.bank_branch_name = contact.bank_branch_name || '';

            this.showToast(contact.full_name + ' selected', 'success');
        },

        clearContactSelection(recipientIndex) {
            const r = this.recipients[recipientIndex];
            r._contact_id = null;
            r._searchQuery = '';
            r.name = '';
            r.email = '';
            r.cell = '';
            r.id_number = '';
            r.address = '';
            r.bank_name = '';
            r.bank_account_name = '';
            r.bank_account_number = '';
            r.bank_branch_name = '';
        },

        // ---- Lease duration calculator ----
        calculateLeaseEnd() {
            const dur = this.details._duration;
            const start = this.details.lease_start;
            if (!start || dur === 0) return;

            const startDate = new Date(start);
            startDate.setMonth(startDate.getMonth() + dur);
            // End date is day before the anniversary
            startDate.setDate(startDate.getDate() - 1);
            this.details.lease_end = startDate.toISOString().split('T')[0];
        },

        // ---- Live preview refresh (debounced) ----
        _previewTimer: null,
        refreshPreviewDebounced() {
            if (this._previewTimer) clearTimeout(this._previewTimer);
            this._previewTimer = setTimeout(async () => {
                if (this.previewRenderType === 'web' && this.flowId && serverTemplateId) {
                    // Autosave field values first so server re-renders with latest data
                    if (this.currentStep === 5) {
                        try {
                            await fetch('/docuperfect/esign/' + this.flowId + '/autosave-fields', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                                body: JSON.stringify({ fieldValues: this.fieldValues }),
                            });
                        } catch (e) {
                            console.error('Autosave failed:', e);
                        }
                    }
                    this.loadTemplatePreview(serverTemplateId);
                }
            }, 600);
        },

        // ---- Recipients ----
        hasRoleRecipient(role) {
            return this.recipients.some(r => r.role === role && !r.readonly);
        },

        hasSecondRoleRecipient(role) {
            return this.recipients.filter(r => r.role === role && !r.readonly).length >= 2;
        },

        addSecondOwner() {
            // Insert after the first landlord
            const idx = this.recipients.findIndex(r => r.role === 'landlord' && !r.readonly);
            const newOwner = {
                order: 0, role: 'landlord', name: '', id_number: '', email: '', cell: '', address: '', readonly: false,
                _contact_id: null, _searchQuery: '', _searchResults: [], _searchOpen: false, _searching: false, _searchIdx: 0,
                _includeEmail: false,
            };
            if (idx >= 0) {
                this.recipients.splice(idx + 1, 0, newOwner);
            } else {
                this.recipients.push(newOwner);
            }
            this.recipients.forEach((r, i) => r.order = i + 1);
        },

        addRecipient() {
            this.recipients.push({
                order: this.recipients.length + 1, role: 'landlord', name: '', id_number: '', email: '', cell: '', address: '', readonly: false,
                _contact_id: null, _searchQuery: '', _searchResults: [], _searchOpen: false, _searching: false, _searchIdx: 0,
            });
        },

        removeRecipient(index) {
            if (this.recipients[index]?.readonly) return;
            this.recipients.splice(index, 1);
            // Re-number orders
            this.recipients.forEach((r, i) => r.order = i + 1);
        },

        // ---- Resize ----
        startResize(e) {
            this._resizing = true;
            this._resizeStartX = e.clientX;
            this._resizeStartW = this.leftPanelPx;
        },

        _onResize(e) {
            if (!this._resizing) return;
            const delta = e.clientX - this._resizeStartX;
            const newW = this._resizeStartW + delta;
            const maxW = window.innerWidth * 0.5;
            this.leftPanelPx = Math.max(250, Math.min(maxW, newW));
        },
    };
}
</script>
@endsection
