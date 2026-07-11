@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
@php
    $flowId = $flow->id ?? null;
    $hasFlow = !is_null($flowId);
    $safeStep = $currentStep ?? 1;
@endphp

<div x-data="esignWizard()" x-cloak class="flex flex-col w-full h-[calc(100vh-64px)] rounded-md overflow-hidden"
     style="border: 1px solid var(--border);">

    {{-- ===== TOAST NOTIFICATION ===== --}}
    <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-[-8px]"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed top-20 right-6 z-50 px-4 py-3 rounded-md shadow-lg text-sm font-medium text-white"
         :style="toast.type === 'success' ? 'background: var(--ds-green);' : 'background: var(--ds-crimson);'">
        <span x-text="toast.message"></span>
    </div>

    {{-- ===== PROGRESS BAR (sticky header) ===== --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="px-6 py-5 flex-shrink-0">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-xl font-bold text-white leading-tight flex items-center gap-2" data-tour="esign-title">
                <span class="whitespace-nowrap">E-Sign Document —</span>
                <input type="text"
                       x-model="documentName"
                       class="bg-transparent text-white/80 font-normal text-base border-0 border-b border-transparent
                              focus:border-white/40 outline-none transition-colors px-0 py-0"
                       style="min-width:200px; max-width:500px;"
                       :size="Math.max(20, (documentName || '').length + 2)"
                       placeholder="Document name..."
                />
            </h2>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <span class="text-sm text-white/60" x-text="'Step ' + currentStep + ' of 6'"></span>
            </div>
        </div>
        <div class="flex gap-1" data-tour="esign-rail">
            <template x-for="(label, i) in stepLabels" :key="i">
                <div class="flex-1 flex flex-col gap-1"
                     :class="canGoToStep(i+1) ? 'cursor-pointer' : 'cursor-default'"
                     @click="canGoToStep(i+1) && goToStep(i+1)">
                    <div class="h-1.5 rounded-full transition-all duration-300"
                         :style="(i+1) < currentStep ? 'background: var(--ds-green);' : ((i+1) === currentStep ? 'background: #ffffff;' : 'background: rgba(255,255,255,0.2);')"></div>
                    <span class="text-[0.6875rem] leading-tight"
                          :class="(i+1) <= currentStep ? 'text-white/70' : 'text-white/30'"
                          x-text="label"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ===== SERVER FEEDBACK BANNER =====
         prepareSigning() / prepareWetInk() catch \Throwable and
         redirect()->withErrors() (or ->with('error', ...)) back to this
         view. Without rendering the bag a failure looks identical to
         "the wizard just reset" (audit BL-2a). Mirrors
         my-documents.blade.php:48-58. --}}
    @if($errors->any() || session('error'))
        <div class="mx-6 mt-4 rounded-md px-4 py-3 text-sm flex items-start gap-3 flex-shrink-0"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);
                    color: var(--text-primary, #111827);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                 stroke="var(--ds-crimson, #dc2626)" style="width:18px;height:18px;flex-shrink:0;margin-top:1px;">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                @if(session('error'))
                    <div>{{ session('error') }}</div>
                @endif
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ===== TWO-PANEL LAYOUT ===== --}}
    <div class="flex-1 flex min-h-0 overflow-hidden">

        {{-- LEFT PANEL --}}
        <div class="overflow-y-auto flex flex-col"
             :style="'background: var(--surface); width:' + leftPanelPx + 'px; min-width:250px; max-width:50vw;'">
            <div class="flex-1 p-6 pb-24">

            {{-- ======== STEP 1: Template Selection ======== --}}
            <div x-show="currentStep === 1" x-cloak>

                {{-- Draft flows --}}
                <div x-show="drafts.length > 0" class="mb-6">
                    <h4 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Continue where you left off</h4>
                    <div class="space-y-2">
                        <template x-for="(d, di) in drafts" :key="d.id">
                            <div class="p-3 rounded-md"
                                 style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                                <div class="flex items-start justify-between">
                                    <div class="min-w-0">
                                        <div class="font-medium text-sm truncate" style="color: var(--text-primary);" x-text="d.template_name || 'Untitled'"></div>
                                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                                            Step <span x-text="d.current_step"></span> of 5
                                            <template x-if="d.property_address">
                                                <span> &middot; <span x-text="d.property_address"></span></span>
                                            </template>
                                        </div>
                                        <div class="text-xs mt-0.5" style="color: var(--text-muted);" x-text="'Last edited: ' + d.updated_ago"></div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between mt-2 pt-2" style="border-top: 1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                    <button @click="deleteDraft(d.id, di)"
                                            class="text-xs font-semibold transition" style="color: var(--ds-crimson);">Delete Draft</button>
                                    <a :href="'/docuperfect/esign/' + d.id + '/step/' + d.current_step"
                                       class="text-xs font-semibold transition" style="color: var(--brand-icon, #0ea5e9);">Continue &rarr;</a>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Select Template</h3>

                {{-- Category filter buttons --}}
                <div class="flex items-center gap-2 mb-3">
                    <button @click="categoryFilter = 'all'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                            :style="categoryFilter === 'all' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-secondary);'">
                        All
                    </button>
                    <button @click="categoryFilter = 'sales'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                            :style="categoryFilter === 'sales' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-secondary);'">
                        Sales
                    </button>
                    <button @click="categoryFilter = 'rentals'"
                            class="px-3 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                            :style="categoryFilter === 'rentals' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-secondary);'">
                        Rentals
                    </button>
                </div>

                <input type="text" x-model="templateSearch" placeholder="Search templates..."
                       class="w-full rounded-md px-3 py-2 text-sm mb-4"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" />

                {{-- Template groups by type --}}
                <template x-for="group in templateGroups" :key="group.type">
                    <div x-show="group.templates.length > 0" class="mb-4">
                        <button @click="group.open = !group.open"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-md text-sm font-semibold transition"
                                style="background: var(--surface-2); color: var(--text-primary);">
                            <span>
                                <span x-text="group.label" class="capitalize"></span>
                                <span class="font-normal ml-1" style="color: var(--text-muted);" x-text="'(' + group.templates.length + ')'"></span>
                            </span>
                            <svg class="w-4 h-4 transition-transform" style="color: var(--text-muted);" :class="group.open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="group.open" x-collapse class="mt-2 space-y-2">
                            <template x-for="t in group.templates" :key="t.id">
                                <button @click="selectTemplate(t)"
                                        class="w-full text-left p-3 rounded-md transition-all duration-300"
                                        :style="selectedTemplateId === t.id
                                            ? 'background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent); border: 1px solid var(--brand-button, #0ea5e9);'
                                            : 'background: var(--surface); border: 1px solid var(--border);'">
                                    <div class="font-medium text-sm flex items-center flex-wrap gap-1" style="color: var(--text-primary);">
                                        <span x-text="t.name"></span>
                                        <span x-show="t.render_type === 'web'" class="ds-badge ds-badge-info">Web</span>
                                        <span x-show="!t.render_type || t.render_type === 'pdf'" class="ds-badge ds-badge-default">PDF</span>
                                        <span x-show="t.category === 'sales'" class="ds-badge ds-badge-warning">Sales</span>
                                        <span x-show="t.category === 'rentals'" class="ds-badge ds-badge-info">Rentals</span>
                                        <span x-show="t.document_type?.label || t.document_type?.name" class="ds-badge ds-badge-default" x-text="t.document_type?.label || t.document_type?.name"></span>
                                    </div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                        <span x-text="t.page_count + ' page' + (t.page_count !== 1 ? 's' : '')"></span>
                                        &middot; <span x-text="(t.fields_json?.length || 0) + ' fields'"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-show="templateGroups.every(g => g.templates.length === 0) && allWebPacks.length === 0 && allPdfPacks.length === 0" class="text-sm text-center py-8" style="color: var(--text-muted);">
                    No templates match your search.
                </div>

                {{-- Web Packs section --}}
                <template x-if="allWebPacks.length > 0">
                    <div class="mt-6">
                        <div class="px-3 py-2 rounded-md mb-3" style="background: var(--surface-2);">
                            <h4 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand-icon, #0ea5e9);">Web Packs</h4>
                        </div>
                        <div class="space-y-2">
                            <template x-for="p in allWebPacks" :key="'pack-' + p.id">
                                <div>
                                    {{-- P0-1: a pack containing a wet-ink-only document (sale
                                         agreement / OTP) cannot be e-signed — Alienation of Land
                                         Act. Eligibility is computed server-side; the pack is
                                         shown, greyed, WITH the reason (STANDARDS.md "No Silent
                                         Locks" — say why, and name the offending document). --}}
                                    <template x-if="p.esign_eligible">
                                        <button @click="selectPack(p)"
                                                class="w-full text-left p-3 rounded-md transition-all duration-300"
                                                :style="selectedPackId === p.id
                                                    ? 'background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent); border: 1px solid var(--brand-button, #0ea5e9);'
                                                    : 'background: var(--surface); border: 1px solid var(--border);'">
                                            <div class="font-medium text-sm flex items-center" style="color: var(--text-primary);">
                                                <span x-text="p.name"></span>
                                                <span class="ds-badge ds-badge-info ml-2">Web</span>
                                                <span class="ds-badge ds-badge-default ml-1" x-text="p.items.length + ' tpl' + (p.items.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            <div x-show="p.items.length > 0" class="mt-1.5 space-y-0.5">
                                                <template x-for="item in p.items" :key="'pi-' + item.id">
                                                    <div class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                                                        <span class="w-1 h-1 rounded-full flex-shrink-0" style="background: var(--brand-icon, #0ea5e9);"></span>
                                                        <span x-text="item.template?.name || 'Unknown template'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </button>
                                    </template>

                                    <template x-if="!p.esign_eligible">
                                        <div class="w-full text-left p-3 rounded-md cursor-not-allowed"
                                             style="background: var(--surface-2); border: 1px solid var(--border); opacity: 0.65;">
                                            <div class="font-medium text-sm flex items-center" style="color: var(--text-muted);">
                                                <span x-text="p.name"></span>
                                                <span class="ds-badge ds-badge-default ml-2">Web</span>
                                                <span class="ds-badge ds-badge-default ml-1" x-text="p.items.length + ' tpl' + (p.items.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            <div class="text-xs mt-1" style="color: var(--ds-amber);"
                                                 x-text="p.esign_block_reason || 'Contains a wet ink document — not eligible for e-signature'"></div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- PDF Packs section --}}
                <template x-if="allPdfPacks.length > 0">
                    <div class="mt-6">
                        <div class="px-3 py-2 rounded-md mb-3" style="background: var(--surface-2);">
                            <h4 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Document Packs</h4>
                        </div>
                        <div class="space-y-2">
                            <template x-for="p in allPdfPacks" :key="'pdfpack-' + p.id">
                                <div>
                                    <template x-if="p.esign_eligible">
                                        <button @click="selectPdfPack(p)"
                                                class="w-full text-left p-3 rounded-md transition-all duration-300"
                                                :style="selectedPdfPackId === p.id
                                                    ? 'background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent); border: 1px solid var(--brand-button, #0ea5e9);'
                                                    : 'background: var(--surface); border: 1px solid var(--border);'">
                                            <div class="font-medium text-sm flex items-center" style="color: var(--text-primary);">
                                                <span x-text="p.name"></span>
                                                <span class="ds-badge ds-badge-default ml-2">Pack</span>
                                                <span class="ds-badge ds-badge-default ml-1" x-text="p.templates.length + ' tpl' + (p.templates.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            <div x-show="p.templates.length > 0" class="mt-1.5 space-y-0.5">
                                                <template x-for="t in p.templates" :key="'ppt-' + t.id">
                                                    <div class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                                                        <span class="w-1 h-1 rounded-full flex-shrink-0" style="background: var(--text-muted);"></span>
                                                        <span x-text="t.name"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </button>
                                    </template>
                                    <template x-if="!p.esign_eligible">
                                        <div class="w-full text-left p-3 rounded-md opacity-50 cursor-not-allowed"
                                             style="background: var(--surface); border: 1px solid var(--border);">
                                            <div class="font-medium text-sm flex items-center" style="color: var(--text-primary);">
                                                <span x-text="p.name"></span>
                                                <span class="ds-badge ds-badge-default ml-2">Pack</span>
                                                <span class="ds-badge ds-badge-default ml-1" x-text="p.templates.length + ' tpl' + (p.templates.length !== 1 ? 's' : '')"></span>
                                            </div>
                                            {{-- P0-1: name the offending document so the agent knows
                                                 WHICH one to pull out, not merely that the pack is refused. --}}
                                            <div class="text-xs mt-1" style="color: var(--ds-amber);"
                                                 x-text="p.esign_block_reason || 'Contains a wet ink document — not eligible for e-signature'"></div>
                                            <div x-show="p.templates.length > 0" class="mt-1.5 space-y-0.5">
                                                <template x-for="t in p.templates" :key="'ppt-' + t.id">
                                                    <div class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                                                        <span class="w-1 h-1 rounded-full flex-shrink-0" style="background: var(--text-muted);"></span>
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
                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Property Details</h3>

                {{-- Property search --}}
                <div class="relative mb-4" @click.outside="propSearchOpen = false" @keydown.escape.window="propSearchOpen = false">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search property by address, suburb, or ERF</label>
                    <div class="relative">
                        <input type="text"
                               x-model="propSearchQuery"
                               @input.debounce.300ms="searchProperties()"
                               @focus="if (propSearchResults.length) propSearchOpen = true"
                               @keydown.arrow-down.prevent="propSearchIdx = Math.min(propSearchIdx + 1, propSearchResults.length - 1); $nextTick(() => $el.closest('.relative').querySelector('[data-idx=\'' + propSearchIdx + '\']')?.scrollIntoView({block:'nearest'}))"
                               @keydown.arrow-up.prevent="propSearchIdx = Math.max(propSearchIdx - 1, 0)"
                               @keydown.enter.prevent="if (propSearchOpen && propSearchResults[propSearchIdx]) selectProperty(propSearchResults[propSearchIdx])"
                               class="w-full rounded-md px-3 py-2 text-sm pr-8"
                               :style="property._selected ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                               placeholder="Start typing to search...">
                        <div class="absolute right-2 top-1/2 -translate-y-1/2">
                            <svg x-show="propSearching" class="w-4 h-4 animate-spin" style="color: var(--text-muted);" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                            <svg x-show="!propSearching && !property._selected" class="w-4 h-4" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <svg x-show="property._selected" class="w-4 h-4" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                    </div>

                    {{-- Selected property badge --}}
                    <div x-show="property._selected" class="mt-2 flex items-center gap-2 px-3 py-2 rounded-md text-sm"
                         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
                        <svg class="w-4 h-4 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="font-medium truncate" x-text="'Selected: ' + property.address"></span>
                        <button @click="clearPropertySelection()" class="ml-auto transition flex-shrink-0" style="color: var(--ds-green);">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Search results dropdown --}}
                    <div x-show="propSearchOpen && propSearchResults.length > 0" x-transition
                         class="absolute z-30 w-full mt-1 rounded-md max-h-64 overflow-y-auto"
                         style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.2);">
                        <template x-for="(result, ri) in propSearchResults" :key="result.source + '-' + result.id">
                            <button @click="selectProperty(result)"
                                    :data-idx="ri"
                                    class="w-full text-left px-3 py-2.5 transition-colors"
                                    style="border-top: 1px solid var(--border);"
                                    :style="(ri === propSearchIdx ? 'background: var(--surface-2);' : '') + 'border-top: 1px solid var(--border);'">
                                <div class="flex items-start justify-between">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);" x-text="result.display"></div>
                                        <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                            <span x-show="result.status" x-text="result.status" class="px-1.5 py-0.5 rounded" style="background:var(--surface-2); border:1px solid var(--border);"></span>
                                            <span x-show="result.property_type" x-text="result.property_type" class="capitalize"></span>
                                            <span x-show="result.beds" x-text="result.beds + ' bed'"></span>
                                            <span x-show="result.price && result.source === 'properties'" x-text="'R ' + Number(result.price).toLocaleString()"></span>
                                            <span x-show="result.rental_amount" x-text="'R ' + Number(result.rental_amount).toLocaleString() + '/mo'"></span>
                                            <span x-show="result.agent" x-text="'· ' + result.agent"></span>
                                        </div>
                                        <div x-show="result.lessor_name" class="text-xs mt-0.5" style="color: var(--brand-icon, #0ea5e9);" x-text="ownerPartyLabel + ': ' + result.lessor_name"></div>
                                    </div>
                                    <span class="ds-badge ds-badge-default ml-2 flex-shrink-0"
                                          x-text="result.source === 'properties' ? 'Property' : 'Rental'"></span>
                                </div>
                            </button>
                        </template>
                    </div>

                    {{-- No results --}}
                    <div x-show="propSearchOpen && propSearchResults.length === 0 && !propSearching && propSearchQuery.length >= 2"
                         class="absolute z-30 w-full mt-1 rounded-md p-4 text-center text-sm"
                         style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); box-shadow: 0 8px 24px rgba(0,0,0,0.2);">
                        No properties found for "<span x-text="propSearchQuery"></span>"
                    </div>
                </div>

                {{-- Manual entry toggle --}}
                <div x-show="!property._selected" class="mb-3">
                    <p class="text-xs italic" style="color: var(--text-muted);">Can't find property? Enter manually below</p>
                </div>

                {{-- Manual entry fields --}}
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Address</label>
                        <input type="text" x-model="property.address" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 21 Dee Road, Uvongo">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Suburb</label>
                            <input type="text" x-model="property.suburb" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. Uvongo">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Unit / Erf Number</label>
                            <input type="text" x-model="property.erf" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. Erf 789">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Complex Name</label>
                            <input type="text" x-model="property.complex_name" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. Ocean View">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property Type</label>
                            <select x-model="property.property_type" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
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
                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Recipients</h3>

                <div class="space-y-3">
                    <template x-for="(r, ri) in recipients" :key="ri">
                        <div class="p-4 rounded-md transition-colors"
                             :style="r.readonly ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);' : (r._contact_id ? 'background: color-mix(in srgb, var(--ds-green) 6%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);' : 'background: var(--surface); border: 1px solid var(--border);')">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold text-white"
                                          :style="r.readonly ? 'background: var(--brand-button, #0ea5e9);' : (r._contact_id ? 'background: var(--ds-green);' : 'background: var(--surface-2); color: var(--text-secondary);')"
                                          x-text="ri + 1"></span>
                                    <span class="text-sm font-semibold" style="color: var(--text-primary);" x-text="r.readonly ? 'Agent (You)' : 'Recipient ' + (ri+1)"></span>
                                    <span x-show="r._contact_id" class="ds-badge ds-badge-success">Linked</span>
                                </div>
                                <button x-show="!r.readonly" @click="removeRecipient(ri)"
                                        class="w-6 h-6 flex items-center justify-center rounded-full transition" style="color: var(--ds-crimson);">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Role</label>
                                    <template x-if="r.readonly">
                                        <input type="text" value="Agent" disabled class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
                                    </template>
                                    <template x-if="!r.readonly">
                                        <select x-model="r.role" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="">Select role...</option>
                                            {{-- Hidden fallback preserves bound value for roles not in the list --}}
                                            <option x-show="false" :value="r.role" x-text="getRoleLabel(r.role)" selected></option>
                                            @foreach($contactTypes as $ct)
                                                <option value="{{ strtolower($ct->name) }}">{{ $ct->name }}</option>
                                            @endforeach
                                        </select>
                                    </template>
                                </div>

                                {{-- Role mismatch warning --}}
                                <template x-if="!r.readonly && r.role && requiredSigningRoles.length > 0 && !roleMatchesTemplate(r.role)">
                                    <div class="rounded-md px-3 py-2"
                                         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
                                        <p class="text-xs">
                                            <strong x-text="r.name || ('Recipient ' + (ri+1))"></strong>
                                            is set as <strong x-text="getRoleLabel(r.role)"></strong>
                                            but this document requires
                                            <strong x-text="requiredSigningRoles.map(r => getRoleLabel(r)).join(' / ')"></strong>.
                                        </p>
                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                            <template x-for="pr in resolvedPartyRoles" :key="pr.value">
                                                <button type="button"
                                                        @click="fixRecipientRole(ri, pr.value)"
                                                        class="px-2.5 py-1 text-xs font-semibold rounded-md transition"
                                                        style="background: color-mix(in srgb, var(--ds-amber) 25%, transparent); color: var(--text-primary);">
                                                    <span x-text="'Set as ' + pr.label"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                {{-- Contact search (only for non-agent recipients) --}}
                                <template x-if="!r.readonly">
                                    <div class="relative" @click.outside="r._searchOpen = false" @keydown.escape.window="r._searchOpen = false">
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search contact by name, email, or ID</label>
                                        <div class="relative">
                                            <input type="text"
                                                   x-model="r._searchQuery"
                                                   @input.debounce.300ms="searchContacts(ri)"
                                                   @focus="if (r._searchResults?.length) r._searchOpen = true"
                                                   @keydown.arrow-down.prevent="r._searchIdx = Math.min((r._searchIdx || 0) + 1, (r._searchResults || []).length - 1)"
                                                   @keydown.arrow-up.prevent="r._searchIdx = Math.max((r._searchIdx || 0) - 1, 0)"
                                                   @keydown.enter.prevent="if (r._searchOpen && r._searchResults?.[r._searchIdx]) selectContact(ri, r._searchResults[r._searchIdx])"
                                                   class="w-full rounded-md px-3 py-2 text-sm pr-8"
                                                   :style="r._contact_id ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                                                   placeholder="Start typing to search...">
                                            <div class="absolute right-2 top-1/2 -translate-y-1/2">
                                                <svg x-show="r._searching" class="w-4 h-4 animate-spin" style="color: var(--text-muted);" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                                                <svg x-show="!r._searching && r._contact_id" class="w-4 h-4" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <svg x-show="!r._searching && !r._contact_id" class="w-4 h-4" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                            </div>
                                        </div>

                                        {{-- Selected contact badge --}}
                                        <div x-show="r._contact_id" class="mt-1.5 flex items-center gap-2 px-2.5 py-1.5 rounded-md text-xs"
                                             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            <span class="font-medium truncate" x-text="'Selected: ' + r.name"></span>
                                            <button @click="clearContactSelection(ri)" class="ml-auto transition flex-shrink-0" style="color: var(--ds-green);">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>

                                        {{-- Search results --}}
                                        <div x-show="r._searchOpen && (r._searchResults || []).length > 0" x-transition
                                             class="absolute z-30 w-full mt-1 rounded-md max-h-48 overflow-y-auto"
                                             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.2);">
                                            <template x-for="(contact, ci) in (r._searchResults || [])" :key="contact.id">
                                                <button @click="selectContact(ri, contact)"
                                                        class="w-full text-left px-3 py-2 transition-colors"
                                                        :style="(ci === (r._searchIdx || 0) ? 'background: var(--surface-2);' : '') + 'border-top: 1px solid var(--border);'">
                                                    <div class="text-sm font-medium" style="color: var(--text-primary);" x-text="contact.full_name"></div>
                                                    <div class="text-xs flex items-center gap-2" style="color: var(--text-muted);">
                                                        <span x-show="contact.email" x-text="contact.email"></span>
                                                        <span x-show="contact.phone" x-text="contact.phone"></span>
                                                        <span x-show="contact.contact_type" style="color: var(--brand-icon, #0ea5e9);" x-text="contact.contact_type"></span>
                                                    </div>
                                                </button>
                                            </template>
                                        </div>

                                        {{-- No results --}}
                                        <div x-show="r._searchOpen && (r._searchResults || []).length === 0 && !r._searching && (r._searchQuery || '').length >= 2"
                                             class="absolute z-30 w-full mt-1 rounded-md p-3 text-center text-xs"
                                             style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); box-shadow: 0 8px 24px rgba(0,0,0,0.2);">
                                            No contacts found. Enter manually below.
                                        </div>
                                    </div>
                                </template>

                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Full Name</label>
                                    <input type="text" x-model="r.name" :readonly="r.readonly"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           :style="r.readonly ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">ID Number</label>
                                    <input type="text" x-model="r.id_number" :readonly="r.readonly"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           :style="r.readonly ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                                           placeholder="SA ID or Passport">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                                        <input type="email" x-model="r.email" :readonly="r.readonly"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               :style="r.readonly ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Cell Phone</label>
                                        <input type="tel" x-model="r.cell" :readonly="r.readonly"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               :style="r.readonly ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Physical Address</label>
                                    <input type="text" x-model="r.address" :readonly="r.readonly"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           :style="r.readonly ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                                           placeholder="Residential address">
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Add second owner button (only when an owner party exists but no second one yet) --}}
                <button x-show="hasRoleRecipient(ownerPartyRole) && !hasSecondRoleRecipient(ownerPartyRole)"
                        @click="addSecondOwner()"
                        class="w-full mt-3 py-2.5 rounded-md text-sm transition"
                        style="border: 2px dashed color-mix(in srgb, var(--ds-green) 40%, transparent); color: var(--ds-green);">
                    <span x-text="'+ Add Second ' + ownerPartyLabel + ' (Co-owner)'"></span>
                </button>

                <button @click="addRecipient()" class="w-full mt-3 py-2.5 rounded-md text-sm transition"
                        style="border: 2px dashed var(--border); color: var(--text-secondary);">
                    + Add Recipient
                </button>
            </div>

            {{-- ======== STEP 4: Details ======== --}}
            <div x-show="currentStep === 4" x-cloak>
                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Document Details</h3>

                {{-- Auto-fill notice --}}
                <div x-show="property._selected && (details._autoFilled || false)" class="mb-4 px-3 py-2 rounded-md text-xs flex items-center gap-2"
                     style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Some fields were auto-filled from the selected property. You can adjust them below.
                </div>

                {{-- Context indicator --}}
                <div class="mb-4 px-3 py-2 rounded-md text-xs font-medium flex items-center gap-2"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent); color: var(--text-primary);">
                    <span x-text="isSalesContext ? 'Sales Document' : 'Rental Document'"></span>
                </div>

                <div class="space-y-4">
                    {{-- ---- SALES FIELDS ---- --}}
                    <template x-if="isSalesContext">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Asking Price (R)</label>
                                <input type="text" x-model="details.price"
                                       @input="updatePreviewField('price', $event.target.value)"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 2500000">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Commission (%)</label>
                                <input type="text" x-model="details.commission"
                                       @input="updatePreviewField('commission_percent', $event.target.value)"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 7.5">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Mandate Start Date</label>
                                    <input type="date" x-model="details.mandate_start"
                                           @input="updatePreviewField('mandate_start', $event.target.value)"
                                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Mandate Expiry Date</label>
                                    <input type="date" x-model="details.mandate_expiry"
                                           @input="updatePreviewField('mandate_expiry', $event.target.value)"
                                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                        <template x-for="opt in [{m:1,l:'1 Mo'},{m:3,l:'3 Mo'},{m:6,l:'6 Mo'},{m:9,l:'9 Mo'}]" :key="opt.m">
                                            <button type="button" @click="quickFillExpiry(opt.m)"
                                                    class="px-2.5 py-1 rounded-full text-xs font-medium transition"
                                                    :style="details.mandate_expiry === calcExpiryDate(opt.m) ? 'background: var(--brand-button, #0ea5e9); color: #fff; border: 1px solid var(--brand-button, #0ea5e9);' : 'background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);'"
                                                    x-text="opt.l"></button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- ---- RENTAL FIELDS ---- --}}
                    <template x-if="!isSalesContext">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Monthly Rental (R)</label>
                                    <input type="text" x-model="details.monthly_rental"
                                           @input="updatePreviewField('monthly_rental', $event.target.value); updatePreviewField('rental_amount', $event.target.value)"
                                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 12000">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Deposit (R)</label>
                                    <input type="text" x-model="details.deposit"
                                           @input="updatePreviewField('deposit_amount', $event.target.value)"
                                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 12000">
                                </div>
                            </div>

                            {{-- Lease dates with duration selector --}}
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Lease Start Date</label>
                                <input type="date" x-model="details.lease_start"
                                       @change="calculateLeaseEnd()"
                                       @input="updatePreviewField('lease_start', $event.target.value)"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-2" style="color: var(--text-secondary);">Lease Duration</label>
                                <div class="flex flex-wrap gap-2 mb-3">
                                    <template x-for="opt in [{value: 6, label: '6 months'}, {value: 12, label: '12 months'}, {value: 24, label: '24 months'}, {value: 0, label: 'Custom'}]" :key="opt.value">
                                        <button type="button"
                                                @click="details._duration = opt.value; calculateLeaseEnd()"
                                                class="px-3 py-1.5 rounded-md text-xs font-medium transition"
                                                :style="details._duration === opt.value ? 'background: var(--brand-button, #0ea5e9); color: #fff; border: 1px solid var(--brand-button, #0ea5e9);' : 'background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);'"
                                                x-text="opt.label"></button>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Lease End Date</label>
                                <input type="date" x-model="details.lease_end"
                                       :readonly="details._duration !== 0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       :style="details._duration !== 0 ? 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                                <p x-show="details._duration !== 0 && details.lease_end" class="text-xs mt-1" style="color: var(--text-muted);" x-text="'Auto-calculated: ' + details._duration + ' months from start'"></p>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Commission (%)</label>
                                    <input type="text" x-model="details.commission" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 8.5">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Marketing Fee (R)</label>
                                    <input type="text" x-model="details.marketing_fee" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 2500">
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Dynamic manual fields from template --}}
                    <template x-if="manualFields.length > 0">
                        <div class="mt-4 pt-4" style="border-top: 1px solid var(--border);">
                            <p class="text-xs mb-3" style="color: var(--text-muted);">Additional template fields</p>
                            <div class="grid grid-cols-2 gap-4">
                                <template x-for="mf in manualFields" :key="mf.id">
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);" x-text="mf.name"></label>
                                        <input type="text"
                                               x-model="details['named_field_' + mf.id]"
                                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
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
                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Fill & Review</h3>
                <p class="text-xs mb-4" style="color: var(--text-muted);">Fields are shown in document order. Pre-filled values come from property and recipient data. Multi-recipient roles render one input per recipient.</p>

                {{-- All fields in document order — walk-fix B uses
                     expandedWizardFields when present (N inputs per N
                     recipients with per-instance pre-fill + chip label)
                     so the live recipient-loop engine governs the
                     wizard surface, not the legacy concatenation. --}}
                <div class="space-y-3">
                    <template x-for="(f, fi) in (expandedWizardFields && expandedWizardFields.length ? expandedWizardFields : allWizardFields)" :key="f.id">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-medium" style="color: var(--text-secondary);">
                                    <span x-text="fieldLabel(f)"></span>
                                    {{-- Walk-fix B — when a field carries _instance_index, prepend
                                         a per-instance chip ("Seller 2: Steve Jobs") so the agent
                                         immediately sees which recipient this input belongs to. --}}
                                    <template x-if="f._instance_index">
                                        <span class="ds-badge ds-badge-warning ml-1" x-text="f.instance_label"></span>
                                    </template>
                                    {{-- Fix A — render one chip per role in editable_by (preserves array; pre-fix only first element rendered). --}}
                                    <template x-for="(roleToken, ci) in fieldRoleTokens(f)" :key="f.id + '_' + ci + '_' + roleToken">
                                        <span class="ds-badge ml-1"
                                              :class="isCreatorRole(roleToken) ? 'ds-badge-info' : 'ds-badge-warning'"
                                              x-text="getRoleLabel(roleToken)"></span>
                                    </template>
                                </label>
                                <select @change="setFieldParty(f.id, $event.target.value)"
                                        class="text-xs rounded-md px-1.5 py-0.5 ml-2"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
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
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       :style="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                                       :placeholder="fieldLabel(f)">
                            </template>

                            {{-- Date --}}
                            <template x-if="fieldInputType(f) === 'date'">
                                <input type="date"
                                       :value="fieldValues[f.id] || ''"
                                       @input="setFieldValue(f.id, $event.target.value)"
                                       @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       :style="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
                            </template>

                            {{-- Selection dropdown --}}
                            <template x-if="fieldInputType(f) === 'select'">
                                <select :value="fieldValues[f.id] || ''"
                                        @change="setFieldValue(f.id, $event.target.value)"
                                        @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        :style="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'">
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
                                                class="px-3 py-1.5 rounded-md text-xs font-medium transition"
                                                :style="fieldValues[f.id] === opt ? 'background: var(--brand-button, #0ea5e9); color: #fff; border: 1px solid var(--brand-button, #0ea5e9);' : 'background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);'"
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
                                           class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                    <span class="text-sm" style="color: var(--text-primary);">Apply strikethrough</span>
                                </label>
                            </template>

                            {{-- Condition / clause textarea --}}
                            <template x-if="fieldInputType(f) === 'textarea'">
                                <textarea :value="fieldValues[f.id] || ''"
                                          @input="setFieldValue(f.id, $event.target.value)"
                                          @focus="highlightField(f.id)" @blur="clearFieldHighlight()"
                                          rows="3"
                                          class="w-full rounded-md px-3 py-2 text-sm"
                                          :style="(fieldValues[f.id] && fieldValues[f.id] !== '') ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'"
                                          :placeholder="fieldLabel(f)"></textarea>
                            </template>

                            {{-- Field group display (read-only, collapsed group members) --}}
                            <template x-if="fieldInputType(f) === 'field_group_display'">
                                <div class="rounded-md px-3 py-2 text-sm"
                                     :style="(f.value || fieldValues[f.id]) ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent); color: var(--text-primary);'">
                                    <span x-text="f.value || fieldValues[f.id] || 'Pending — will auto-fill from recipient data'"
                                          :style="(f.value || fieldValues[f.id]) ? 'font-weight: 500;' : 'color: var(--text-muted); font-style: italic;'"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Additional Clauses --}}
                <div class="mt-6 mb-4 p-3 rounded-md"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, transparent); border: 1px dashed color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, transparent);">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-sm font-semibold" style="color: var(--text-primary);">Other Conditions / Additional Clauses</span>
                            <p class="text-xs mt-0.5" style="color: var(--text-secondary);">
                                Type conditions manually or insert from the clause library. Separate each clause with a blank line.
                            </p>
                        </div>
                        <button type="button" @click="showClauseLibrary = true"
                                class="corex-btn-primary">
                            + Insert Clause
                        </button>
                    </div>

                    {{-- Unified editable textarea for all clauses (manual + library) --}}
                    <textarea x-model="otherConditionsText"
                              @input="updateClausesPreview()"
                              rows="6"
                              class="mt-3 w-full rounded-md px-3 py-2 text-sm"
                              :style="(otherConditionsText.trim() ? 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid var(--ds-green); color: var(--text-primary);' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);') + 'min-height:120px; resize:vertical;'"
                              placeholder="Type additional conditions here, or use 'Insert Clause' to add from the library. Separate each clause with a blank line for per-clause initials tracking."></textarea>
                </div>
            </div>

            {{-- ======== STEP 6: Signing Setup ======== --}}
            <div x-show="currentStep === 6" x-cloak>
                <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Signing Setup</h3>

                {{-- Delivery Mode Selection --}}
                <template x-if="effectiveDeliveryModes.length > 1">
                    <div class="mb-6 p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                        <h4 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Delivery Mode</h4>
                        <div class="space-y-2">
                            <template x-for="mode in effectiveDeliveryModes" :key="mode">
                                <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer transition-all"
                                       :style="deliveryMode === mode ? 'background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent); border: 1px solid var(--brand-button, #0ea5e9);' : 'background: var(--surface); border: 1px solid var(--border);'">
                                    <input type="radio" name="delivery_mode" :value="mode" x-model="deliveryMode"
                                           class="mt-0.5" style="accent-color: var(--brand-button, #0ea5e9);">
                                    <div>
                                        <div class="text-sm font-semibold" style="color: var(--text-primary);" x-text="deliveryModeLabel(mode)"></div>
                                        <div class="text-xs mt-0.5" style="color: var(--text-muted);" x-text="deliveryModeDescription(mode)"></div>
                                    </div>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>
                <template x-if="effectiveDeliveryModes.length === 1">
                    <div class="mb-4 p-3 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                        <span class="font-semibold" x-text="deliveryModeLabel(effectiveDeliveryModes[0])"></span>
                        <span class="text-xs ml-2" style="color: var(--text-muted);" x-text="'(only available mode for this template)'"></span>
                    </div>
                </template>
                <template x-if="esignBlocked">
                    <div class="mb-4 p-3 rounded-md text-sm"
                         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
                        <strong>Sale agreements must be signed with wet ink</strong> per the Alienation of Land Act. E-signing is not permitted for this document type.
                    </div>
                </template>

                {{-- Only show signing order for e-sign mode --}}
                <div x-show="deliveryMode === 'esign'">

                {{-- Signing order cards --}}
                <h4 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Signing Order</h4>
                <div class="space-y-2 mb-6">
                    <template x-for="(r, ri) in recipients" :key="r.role + '_' + (r.name || ri)">
                        <div class="p-3 rounded-md transition-all" style="background: var(--surface); border: 1px solid var(--border);">
                            <div class="flex items-start justify-between">
                                <span class="text-sm font-bold mr-3 w-6 text-center mt-1" style="color: var(--text-muted);" x-text="ri + 1"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm font-semibold" style="color: var(--text-primary);" x-text="signingRoleLabel(r.role) + ': ' + (r.name || '(unknown)')"></span>
                                    </div>
                                    <div class="mt-2" x-show="r.role !== 'agent'">
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email address</label>
                                        <input type="email"
                                               x-model="r.email"
                                               :disabled="r.skipEmail"
                                               :style="r.skipEmail ? 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);' : 'background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);'"
                                               class="w-full rounded-md px-3 py-1.5 text-sm"
                                               placeholder="Email address">
                                        <p class="text-xs mt-1" style="color: var(--text-muted);">Edit if signer uses a different email address</p>
                                    </div>
                                    <div x-show="r.role === 'agent'" class="text-xs" style="color: var(--text-muted);">
                                        <span x-text="r.email"></span>
                                        <span x-show="r.cell"> | </span>
                                        <span x-show="r.cell" x-text="r.cell"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2 ml-9">
                                <template x-if="r.role === 'agent'">
                                    <span class="ds-badge ds-badge-info">Signs first &mdash; locked</span>
                                </template>
                                <template x-if="r.role !== 'agent'">
                                    <div>
                                        <div class="flex items-center gap-2 mb-2">
                                            <button type="button"
                                                    x-show="ri > 0"
                                                    @click="moveRecipient(ri, 'up')"
                                                    class="text-xs px-2 py-1 rounded-md flex items-center gap-1 transition"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                                                &uarr; Move Up
                                            </button>
                                            <button type="button"
                                                    x-show="ri < recipients.length - 1"
                                                    @click="moveRecipient(ri, 'down')"
                                                    class="text-xs px-2 py-1 rounded-md flex items-center gap-1 transition"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                                                &darr; Move Down
                                            </button>
                                        </div>
                                        <select x-model="signingActions[ri]"
                                                :disabled="r.skipEmail"
                                                class="text-xs rounded-md px-2 py-1"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="send_after" x-bind:disabled="!r.email || r.skipEmail">Send after previous</option>
                                            <option value="sign_later">Sign later (deferred)</option>
                                        </select>
                                        <div x-show="signingActions[ri] === 'sign_later'" class="mt-2 p-2 rounded-md"
                                             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                                            <div class="flex items-center gap-2 text-xs" style="color: var(--text-primary);">
                                                <span>&#9208;</span>
                                                <span class="font-medium">Deferred &mdash; details not yet known</span>
                                            </div>
                                            <p class="text-xs mt-1" style="color: var(--text-secondary);">This party's signing will be paused until you provide their details. You can resume signing later from the document dashboard.</p>
                                        </div>
                                        <label class="flex items-center gap-2 mt-2 text-sm">
                                            <input type="checkbox"
                                                   x-model="r.skipEmail"
                                                   @change="if (r.skipEmail) signingActions[ri] = 'sign_later'"
                                                   class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                            <span class="text-xs" style="color: var(--text-secondary);">Exclude from email &mdash; will sign in person or via primary recipient</span>
                                        </label>
                                        <label class="flex items-center gap-2 mt-2 text-sm">
                                            <input type="checkbox"
                                                   x-model="r.fica_required"
                                                   class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                            <span class="text-xs" style="color: var(--text-secondary);">FICA verification required before signing</span>
                                        </label>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Field summary --}}
                <h4 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Document Summary</h4>
                <div class="p-4 rounded-md space-y-2 text-sm"
                     style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <div class="flex items-center gap-2">
                        <span style="color: var(--ds-green);">&#10003;</span>
                        <span x-text="fieldSummary.creatorFilled + ' fields completed by you'"></span>
                    </div>
                    <template x-for="sg in fieldSummary.signerGroups" :key="sg.role">
                        <div class="flex items-center gap-2" style="color: var(--text-secondary);">
                            <span>&#9203;</span>
                            <span x-text="sg.count + ' fields for ' + sg.label + ' to complete'"></span>
                        </div>
                    </template>
                    <template x-for="zg in fieldSummary.signatureZones" :key="zg.role">
                        <div class="flex items-center gap-2" style="color: var(--text-secondary);">
                            <span>&#9997;</span>
                            <span x-text="zg.label + ': ' + zg.initials + ' initials + ' + zg.signatures + ' signature'"></span>
                        </div>
                    </template>
                </div>

                </div>{{-- end deliveryMode === 'esign' wrapper --}}

                {{-- Wet ink mode info --}}
                <div x-show="deliveryMode === 'wet_ink'" class="p-4 rounded-md space-y-3"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Wet Ink Signing</h4>
                    <p class="text-sm" style="color: var(--text-secondary);">The document will be generated as a PDF. Each signing party will receive a secure link to:</p>
                    <ol class="text-sm list-decimal ml-5 space-y-1" style="color: var(--text-secondary);">
                        <li>Download the document for printing</li>
                        <li>Sign in ink on the printed copy</li>
                        <li>Scan or photograph the signed pages</li>
                        <li>Upload the signed document through the portal</li>
                    </ol>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">You will review and approve each uploaded document before it proceeds to the next party.</p>
                </div>

                {{-- Download only mode info --}}
                <div x-show="deliveryMode === 'download'" class="p-4 rounded-md space-y-3"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Download Only</h4>
                    <p class="text-sm" style="color: var(--text-secondary);">The document will be generated as a PDF for you to download. No signing pipeline will be created.</p>
                </div>
            </div>

            </div>{{-- end flex-1 p-6 --}}
        </div>

        {{-- RESIZE HANDLE --}}
        <div class="w-1 cursor-col-resize flex-shrink-0 transition-colors"
             style="background: var(--border);"
             @mousedown.prevent="startResize($event)"></div>

        {{-- RIGHT PANEL: Document Preview --}}
        <div class="flex-1 overflow-y-auto p-6 min-w-0" style="background: var(--bg);">

            {{-- Web template preview (wrapped in CoreX document CSS).
                 Shared visual contract — Step 4 / Step 5 / signing view
                 all render through the same _document-body partial. The
                 viewer context flips between 'wizard_preview' (Step 4)
                 and 'wizard_fill' (Step 5) based on currentStep so the
                 outer container class scopes Step-5-specific behaviour
                 (e.g. fill-mode field highlighting) without forking the
                 layout itself. --}}
            <div x-show="previewRenderType === 'web' && previewHtml" class="overflow-y-auto" style="max-height: calc(100vh - 200px);">
                <link href="/css/corex-document.css" rel="stylesheet">
                <div style="zoom: 0.7;">
                    <div class="web-template-preview"
                         :class="{ 'wizard-fill-context': currentStep === 5, 'wizard-preview-context': currentStep !== 5 }"
                         data-viewer-context-host="1">
                        @include('docuperfect.shared._document-body', [
                            'viewerContext'    => 'wizard_preview',
                            'alpineXHtml'      => 'previewHtml',
                        ])
                    </div>
                </div>
            </div>
            <style>
                .web-template-preview .corex-page {
                    min-height: auto !important;
                }
                .field-highlighted {
                    background: color-mix(in srgb, var(--ds-amber) 30%, transparent) !important;
                    outline: 2px solid var(--ds-amber);
                    border-radius: 6px;
                }
            </style>

            {{-- PDF page-image preview --}}
            <div x-show="previewRenderType === 'pdf' && previewPages.length > 0">
                <template x-for="(pageUrl, pi) in previewPages" :key="pi">
                    <div style="margin-bottom:24px;">
                        <div class="text-xs mb-1" style="color: var(--text-muted);" x-text="'Page ' + (pi+1)"></div>
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
                                         + (highlightedFieldId === f.id ? ' box-shadow:0 0 0 2px var(--brand-button, #0ea5e9);' : '')">
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
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="font-semibold mb-2 text-base" style="color: var(--brand-icon, #0ea5e9);">
                        <span x-text="selectedPackName"></span>
                    </div>

                    {{-- Simple pack (no slots) — just list the templates --}}
                    <template x-if="!packHasSlots">
                        <div>
                            <p class="text-xs mb-3" style="color: var(--text-muted);">This pack contains the following documents in order:</p>
                            <template x-for="(item, i) in (packPreview?.items || [])" :key="i">
                                <div class="flex items-center gap-2 py-2" style="border-bottom: 1px solid var(--border);">
                                    <span class="text-xs font-bold w-5 text-center" style="color: var(--text-muted);" x-text="i+1"></span>
                                    <span class="text-sm" style="color: var(--text-primary);" x-text="item.template?.name || 'Unknown'"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Pack with slots — show slot selection UI --}}
                    <template x-if="packHasSlots">
                        <div>
                            <p class="text-xs mb-3" style="color: var(--text-muted);">Configure which documents to include:</p>
                            <div class="space-y-3">
                                <template x-for="slot in packSlots" :key="slot.key">
                                    <div class="rounded-md p-3" style="border: 1px solid var(--border);">
                                        {{-- Required slot --}}
                                        <template x-if="slot.type === 'required'">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: var(--ds-green);"></span>
                                                <span class="text-sm" style="color: var(--text-primary);" x-text="slot.templates[0].name"></span>
                                                <span class="text-xs" style="color: var(--text-muted);">Required</span>
                                            </div>
                                        </template>

                                        {{-- Selectable slot — radio buttons --}}
                                        <template x-if="slot.type === 'selectable'">
                                            <div>
                                                <span class="text-xs font-semibold uppercase" style="color: var(--text-secondary);"
                                                      x-text="slot.label || 'Select one'"></span>
                                                <div class="mt-2 space-y-1">
                                                    <template x-for="tmpl in slot.templates" :key="tmpl.id">
                                                        <label class="flex items-center gap-2 text-sm cursor-pointer p-2 rounded-md transition" style="color: var(--text-primary);">
                                                            <input type="radio"
                                                                   :name="'slot-' + slot.group"
                                                                   :value="tmpl.id"
                                                                   x-model.number="slotSelections[slot.group]"
                                                                   class="w-3.5 h-3.5" style="accent-color: var(--brand-button, #0ea5e9);">
                                                            <span x-text="tmpl.name"></span>
                                                        </label>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Optional slot — checkbox --}}
                                        <template x-if="slot.type === 'optional'">
                                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                                                <input type="checkbox"
                                                       :value="slot.templates[0].id"
                                                       x-model.number="optionalSelections"
                                                       class="w-3.5 h-3.5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                                <span x-text="slot.templates[0].name"></span>
                                                <span class="text-xs" style="color: var(--text-muted);">Optional</span>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            {{-- Resolved template count --}}
                            <div class="mt-3 text-xs" style="color: var(--text-muted);">
                                <span x-text="resolvedPackTemplateIds.length"></span> document(s) will be included
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Empty state: no template selected --}}
            <div x-show="!isPackFlow && previewRenderType === 'pdf' && previewPages.length === 0 && !previewHtml" class="flex items-center justify-center h-full">
                <div class="text-center" style="color: var(--text-muted);">
                    <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p>Select a template to preview</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== STICKY BOTTOM BAR ===== --}}
    <div class="flex-shrink-0 px-6 py-3 flex items-center justify-between"
         style="background: var(--surface); border-top: 1px solid var(--border);">
        <div>
            <button x-show="currentStep > 1" @click="goBack()"
                    class="corex-btn-outline">
                &larr; Back
            </button>
        </div>
        <div>
            <button x-show="flowId" @click="saveDraft()" :disabled="saving"
                    class="corex-btn-outline disabled:opacity-40">
                <span x-show="!saving">Save Draft</span>
                <span x-show="saving" class="flex items-center gap-1">
                    <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/></svg>
                    Saving...
                </span>
            </button>
        </div>
        <div>
            <button @click="goNext()" :disabled="loading || !canGoNext()"
                    class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
             @click.self="showClauseLibrary = false">
            <div class="rounded-md w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col"
                 style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);">
                <div class="p-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">Clause Library</h3>
                    <button @click="showClauseLibrary = false" class="text-lg transition" style="color: var(--text-muted);">&times;</button>
                </div>

                <div class="p-4" style="border-bottom: 1px solid var(--border);">
                    <input type="text" x-model="clauseSearch" placeholder="Search clauses..."
                           class="w-full text-sm rounded-md px-3 py-2"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-2">
                    <template x-for="clause in filteredClauses" :key="clause.id">
                        <div class="p-3 rounded-md cursor-pointer transition-colors"
                             style="background: var(--surface); border: 1px solid var(--border);"
                             @click="insertClause(clause)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold" style="color: var(--text-primary);" x-text="clause.name"></span>
                                <span class="text-xs" style="color: var(--text-muted);" x-text="clause.is_global ? 'Global' : 'Personal'"></span>
                            </div>
                            <p class="text-xs mt-1 line-clamp-3" style="color: var(--text-muted);" x-text="clause.text"></p>
                        </div>
                    </template>
                    <template x-if="filteredClauses.length === 0">
                        <p class="text-xs text-center py-8" style="color: var(--text-muted);">No clauses found</p>
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
    // Walk-fix B — per-recipient expanded fields for Step 5. When the
    // session has multi-recipient roles, this array contains N copies
    // of each role-bound field with unique ids ({field_id}__r{n}),
    // instance_index metadata, and a per-instance value resolved from
    // THAT specific recipient's contact. Single-recipient roles +
    // creator/agent fields pass through with no suffix.
    const serverExpandedWizardFields = @json($expandedWizardFields ?? []);
    const serverAllWizardFields = @json($allWizardFields ?? []);
    const serverPageImages = @json($pageImages ?? []);
    const serverRecipients = @json($recipients ?? []);
    const serverStepData = @json($stepData ?? []);
    const serverManualFields = @json($manualFields ?? []);
    const serverContactTypes = @json($contactTypes ?? []);
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
    function buildTemplateGroups(templates, search, categoryFilter) {
        const types = {};
        const typeLabels = {
            'rental': 'Rental',
            'sales': 'Sales',
            'compliance': 'Compliance',
            'cds': 'Web Templates',
        };

        templates.forEach(t => {
            // Apply category filter (client-side, no server round-trip)
            if (categoryFilter && categoryFilter !== 'all') {
                if (t.category && t.category !== categoryFilter) return;
            }

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

    // Detect sales vs rental context from template name (fallback only)
    function detectSalesContextFromName(templateName) {
        if (!templateName) return false;
        const n = templateName.toLowerCase();
        // Exclude rental authority patterns before checking sales patterns
        if (n.includes('letting') || n.includes('let ') || n.includes('rental') || n.includes('lease')) return false;
        return n.includes('sell') || n.includes('sale') || n.includes('authority to sell')
            || n.includes('otp') || n.includes('purchase') || n.includes('mandate to sell');
    }

    // Detect context from template category (explicit admin-set value)
    function detectContextFromCategory(category) {
        if (category === 'sales') return 'sales';
        if (category === 'rentals') return 'rental';
        return null;
    }

    // Detect context from signing_parties: only explicit concrete roles determine context.
    // Generic roles (owner_party, acquiring_party) are ambiguous and return null,
    // forcing detection to fall through to property source (Layer 2) or template name (Layer 3).
    // Returns: 'sales' | 'rental' | null (null = no explicit signal)
    function detectContextFromSigningParties(signingParties) {
        if (!Array.isArray(signingParties) || signingParties.length === 0) return null;
        const roles = signingParties.map(r => r.toLowerCase());
        const hasSalesRoles = roles.some(r => ['seller', 'buyer'].includes(r));
        const hasRentalRoles = roles.some(r => ['landlord', 'tenant', 'lessor', 'lessee'].includes(r));
        if (hasSalesRoles && !hasRentalRoles) return 'sales';
        if (hasRentalRoles && !hasSalesRoles) return 'rental';
        return null; // generic roles like owner_party or mixed — need property source / name fallback
    }

    // Detect context from property source table
    function detectContextFromPropertySource(propertySource) {
        if (propertySource === 'properties') return 'sales';
        if (propertySource === 'rental_properties') return 'rental';
        return null;
    }

    // Layered context detection: signing_parties > category > property source > template name
    function detectSalesContext(templateName, signingParties, propertySource, templateCategory) {
        // Layer 1: explicit roles in signing_parties
        const fromParties = detectContextFromSigningParties(signingParties);
        if (fromParties === 'sales') return true;
        if (fromParties === 'rental') return false;

        // Layer 2: template category (admin-set Sales/Rentals on template)
        const fromCategory = detectContextFromCategory(templateCategory);
        if (fromCategory === 'sales') return true;
        if (fromCategory === 'rental') return false;

        // Layer 3: property source table
        const fromProp = detectContextFromPropertySource(propertySource);
        if (fromProp === 'sales') return true;
        if (fromProp === 'rental') return false;

        // Layer 4: template name pattern matching (last resort fallback)
        return detectSalesContextFromName(templateName);
    }

    // Resolve a generic role (owner_party, acquiring_party) to concrete role based on context
    function resolvePartyRole(role, isSales) {
        if (role === 'owner_party') return isSales ? 'seller' : 'landlord';
        if (role === 'acquiring_party') return isSales ? 'buyer' : 'tenant';
        return role;
    }

    function getRoleLabel(role) {
        if (!role) return 'Signer';
        // Check contact_types table first
        const ct = (serverContactTypes || []).find(c => c.name.toLowerCase() === role);
        if (ct) return ct.name;
        // Fallback for system roles and aliases
        const labels = {
            'agent': 'Agent', 'creator': 'Agent',
            'owner_party': 'Owner/Seller', 'acquiring_party': 'Buyer/Tenant',
        };
        return labels[role] || role.charAt(0).toUpperCase() + role.slice(1);
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
        categoryFilter: 'all',
        selectedTemplateId: serverTemplate?.id || null,
        templateName: serverTemplate?.name || '',
        documentName: serverStepData?.document_name || '',
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

        // Template signing parties (from DB config)
        templateSigningParties: serverTemplate?.signing_parties || [],
        // Template category (admin-set: 'sales' or 'rentals')
        templateCategory: serverTemplate?.category || null,

        // Document context detection — layered: signing_parties > category > property source > name
        get isSalesContext() {
            const name = this.templateName || serverTemplate?.name || '';
            const sigParties = this.templateSigningParties;
            const propSource = this.property?._property_source || serverStepData?.property?._property_source || null;
            const category = this.templateCategory;
            return detectSalesContext(name, sigParties, propSource, category);
        },
        get ownerPartyLabel() {
            return this.isSalesContext ? 'Seller' : 'Landlord';
        },
        get acquiringPartyLabel() {
            return this.isSalesContext ? 'Buyer' : 'Tenant';
        },
        get ownerPartyRole() {
            return this.isSalesContext ? 'seller' : 'landlord';
        },
        get acquiringPartyRole() {
            return this.isSalesContext ? 'buyer' : 'tenant';
        },

        // Dynamic role options built from template signing_parties
        // Resolves generic roles (owner_party, acquiring_party) to concrete roles based on context
        get resolvedPartyRoles() {
            const parties = this.templateSigningParties;
            if (!Array.isArray(parties) || parties.length === 0) {
                // Fallback: standard binary based on context
                return this.isSalesContext
                    ? [{ value: 'seller', label: 'Seller' }, { value: 'buyer', label: 'Buyer' }]
                    : [{ value: 'landlord', label: 'Landlord' }, { value: 'tenant', label: 'Tenant' }];
            }
            const isSales = this.isSalesContext;
            const roles = [];
            const seen = new Set();
            parties.forEach(role => {
                if (role === 'agent' || role === 'creator') return; // agent is always row 1
                const resolved = resolvePartyRole(role, isSales);
                if (seen.has(resolved)) return;
                seen.add(resolved);
                roles.push({ value: resolved, label: getRoleLabel(resolved) });
            });
            // If signing_parties only had agent + owner_party but template allows acquiring_party
            // (e.g. mandatory disclosure can have buyer/tenant added), ensure both owner + acquiring are available
            if (roles.length === 1 && parties.includes('owner_party')) {
                const acqRole = isSales ? 'buyer' : 'tenant';
                if (!seen.has(acqRole)) {
                    roles.push({ value: acqRole, label: getRoleLabel(acqRole) });
                }
            }
            return roles;
        },
        get partyRolesGroupLabel() {
            return this.isSalesContext ? 'Sales Parties' : 'Rental Parties';
        },

        // Role alias map for matching (SA real estate: lessor=landlord, lessee=tenant)
        _roleAliasMap: {
            'lessor': 'landlord', 'landlord': 'lessor',
            'lessee': 'tenant', 'tenant': 'lessee',
            'seller': 'seller', 'buyer': 'buyer', 'agent': 'agent',
            'owner_party': 'owner_party', 'acquiring_party': 'acquiring_party',
        },

        // Get the list of non-agent signing roles for this template (resolved to concrete roles)
        // MUST stay in sync with resolvedPartyRoles — any role offered as a fix button must pass validation
        get requiredSigningRoles() {
            const parties = this.templateSigningParties;
            if (!Array.isArray(parties) || parties.length === 0) return [];
            const isSales = this.isSalesContext;
            const roles = [];
            const seen = new Set();
            parties.forEach(role => {
                if (role === 'agent' || role === 'creator') return;
                const resolved = resolvePartyRole(role, isSales).toLowerCase();
                if (!seen.has(resolved)) {
                    seen.add(resolved);
                    roles.push(resolved);
                }
            });

            // Sync with resolvedPartyRoles: if template only has owner_party,
            // also accept the acquiring party role (tenant/buyer)
            if (roles.length === 1 && parties.includes('owner_party')) {
                const acqRole = (isSales ? 'buyer' : 'tenant').toLowerCase();
                if (!seen.has(acqRole)) {
                    seen.add(acqRole);
                    roles.push(acqRole);
                }
            }

            // And the reverse: if template only has acquiring_party, also accept owner role
            if (roles.length === 1 && parties.includes('acquiring_party')) {
                const ownRole = (isSales ? 'seller' : 'landlord').toLowerCase();
                if (!seen.has(ownRole)) {
                    seen.add(ownRole);
                    roles.push(ownRole);
                }
            }

            return roles;
        },

        // Check if a recipient role matches any required signing role (with alias support)
        roleMatchesTemplate(recipientRole) {
            if (!recipientRole) return false;
            const role = recipientRole.toLowerCase();
            const required = this.requiredSigningRoles;
            if (required.length === 0) return true; // no signing parties defined — allow any
            if (required.includes(role)) return true;
            const alias = this._roleAliasMap[role];
            if (alias && required.includes(alias)) return true;
            return false;
        },

        // Get mismatched recipients (non-agent recipients whose role doesn't match template)
        get recipientRoleMismatches() {
            const mismatches = [];
            const required = this.requiredSigningRoles;
            if (required.length === 0) return mismatches; // no signing parties — skip validation
            this.recipients.forEach((r, idx) => {
                if (r.readonly) return; // agent — skip
                if (!r.role || !this.roleMatchesTemplate(r.role)) {
                    mismatches.push({ index: idx, name: r.name || ('Recipient ' + (idx + 1)), currentRole: r.role });
                }
            });
            return mismatches;
        },

        // Fix a recipient's role to match the template
        fixRecipientRole(recipientIndex, newRole) {
            if (this.recipients[recipientIndex]) {
                this.recipients[recipientIndex].role = newRole;
            }
        },

        // Preview
        previewPages: serverPageImages || [],
        previewFields: serverFields || [],
        previewRenderType: 'pdf',
        previewHtml: '',
        previewFieldValues: {},

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

        // Step 4: Details — supports both rental and sales fields
        details: (() => {
            const prop = serverStepData?.property || {};
            const det = serverStepData?.details || {};
            const d = {
                // Rental fields
                monthly_rental: det.monthly_rental || prop.rental_amount || '',
                deposit: det.deposit || prop.deposit_amount || '',
                lease_start: det.lease_start || '',
                lease_end: det.lease_end || '',
                // Sales fields
                price: det.price || prop.price || '',
                mandate_start: det.mandate_start || new Date().toISOString().slice(0, 10),
                mandate_expiry: det.mandate_expiry || '',
                // Shared fields
                commission: det.commission || prop.commission_percent || '',
                marketing_fee: det.marketing_fee || prop.marketing_fee || '',
                _duration: det._duration ?? 12,
                _autoFilled: false,
            };
            // Restore saved manual field values (named_field_{id} keys)
            (serverManualFields || []).forEach(mf => {
                const key = 'named_field_' + mf.id;
                if (det[key]) d[key] = det[key];
            });
            // Auto-set deposit = rental when deposit is empty (rental context only)
            if (!d.deposit && d.monthly_rental) d.deposit = d.monthly_rental;
            // Default commission based on context
            if (!d.commission) {
                const tplName = serverTemplate?.name || '';
                d.commission = detectSalesContext(tplName) ? '7.5' : '10';
            }
            return d;
        })(),

        // Manual named fields (for dynamic inputs on step 4)
        manualFields: serverManualFields || [],

        // Step 5: Fields
        creatorFields: serverCreatorFields || [],
        signerFields: serverSignerFields || [],
        expandedWizardFields: serverExpandedWizardFields || [],
        allWizardFields: serverAllWizardFields || [],
        fieldValues: {},
        fieldPartyOverrides: {},
        highlightedFieldId: null,

        // Clause library
        showClauseLibrary: false,
        clauseSearch: '',
        allClauses: [],
        selectedClauses: [],
        otherConditionsText: '',

        // Step 6: Signing setup
        signingActions: [],

        // Delivery mode
        deliveryMode: serverStepData?.delivery_mode || 'esign',
        templateDeliveryModes: (serverTemplate?.allowed_delivery_modes || 'esign,wet_ink,download').split(',').map(s => s.trim()).filter(Boolean),
        esignBlocked: (() => {
            const tpl = serverTemplate;
            if (!tpl) return false;
            const t = (tpl.template_type || '').toLowerCase();
            if (t === 'sale_agreement' || t === 'otp') return true;
            const n = (tpl.name || '').toLowerCase();
            return n.includes('agreement of sale') || n.includes('deed of sale') || n.includes('offer to purchase');
        })(),
        get effectiveDeliveryModes() {
            let modes = [...this.templateDeliveryModes];
            if (this.esignBlocked) {
                modes = modes.filter(m => m !== 'esign');
                if (modes.length === 0) modes = ['wet_ink', 'download'];
            }
            return modes;
        },
        deliveryModeLabel(mode) {
            const labels = { 'esign': 'E-Signature', 'wet_ink': 'Wet Ink (Print & Sign)', 'download': 'Download Only' };
            return labels[mode] || mode;
        },
        deliveryModeDescription(mode) {
            const descs = {
                'esign': 'Sign electronically through the secure online portal',
                'wet_ink': 'Download, print, sign in ink, scan and upload through secure portal',
                'download': 'Generate PDF for download only — no signing pipeline'
            };
            return descs[mode] || '';
        },

        // Resize
        leftPanelPx: 420,
        _resizing: false,

        init() {
            // Initialize field values from server data (unified ordered list).
            // Walk-fix B — when expandedWizardFields is populated it
            // carries the per-instance suffix ids ({orig}__r{n}) with
            // per-recipient pre-fill, so prefer it over allWizardFields
            // (which has the concatenated " and "-joined values).
            const allFields = (this.expandedWizardFields && this.expandedWizardFields.length > 0)
                ? this.expandedWizardFields
                : (this.allWizardFields.length > 0
                    ? this.allWizardFields
                    : [...(this.creatorFields || []), ...(this.signerFields || [])]);
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
                if (!r.hasOwnProperty('fica_required')) r.fica_required = saved.fica_required ?? true;
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

            // Restore selected clauses and other conditions text from step data
            const savedClauses = serverStepData?.fill_review?.clauses || [];
            if (savedClauses.length > 0) this.selectedClauses = savedClauses;
            const savedOtherConditions = serverStepData?.fill_review?.other_conditions_text || '';
            if (savedOtherConditions) this.otherConditionsText = savedOtherConditions;

            // Load web template preview on steps 2+ (PDF preview loads via serverPageImages)
            if (serverIsWebTemplate && this.currentStep > 1 && this.flowId && serverTemplateId) {
                this.previewRenderType = 'web';
                this.loadTemplatePreview(serverTemplateId).then(() => {
                    this.$nextTick(() => {
                        // Scroll preview to relevant section for current step
                        this.scrollPreviewToStep(this.currentStep);
                        // Reapply all stored field values (belt-and-suspenders on top of server rendering)
                        this.reapplyPreviewFields();
                        // Reapply clauses to preview
                        if (this.selectedClauses.length > 0) this.updateClausesPreview();
                    });
                });
            }

            // Global mouse events for resize
            document.addEventListener('mousemove', (e) => this._onResize(e));
            document.addEventListener('mouseup', () => this._resizing = false);
        },

        // ---- Template grouping ----
        get templateGroups() {
            return buildTemplateGroups(this.allTemplates || [], this.templateSearch, this.categoryFilter);
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
            // Append clause text to the unified textarea (don't block duplicates — user may want same clause twice)
            const existing = this.otherConditionsText.trim();
            const clauseContent = clause.text || '';
            if (existing) {
                this.otherConditionsText = existing + '\n\n' + clauseContent;
            } else {
                this.otherConditionsText = clauseContent;
            }
            // Track insertion in selectedClauses for reference
            this.selectedClauses.push({...clause});
            this.showClauseLibrary = false;
            this.updateClausesPreview();
        },

        removeClause(idx) {
            this.selectedClauses.splice(idx, 1);
            this.updateClausesPreview();
        },

        updateClausesPreview() {
            if (this.previewRenderType !== 'web') return;
            const doc = document.querySelector('.web-template-preview');
            if (!doc) return;

            // Use the unified textarea content directly
            const clauseText = this.otherConditionsText.trim();

            // Update the other_conditions data-field in the preview
            const otherField = doc.querySelector('[data-field="other_conditions"]');
            if (otherField) {
                otherField.textContent = clauseText || '';
                if (clauseText) {
                    otherField.style.color = '#0d9488';
                    otherField.style.fontWeight = '600';
                    otherField.style.whiteSpace = 'pre-line';
                } else {
                    otherField.style.color = '';
                    otherField.style.fontWeight = '';
                }
            }

            // Fallback: if no data-field element, inject a clause block before the signature section
            if (!otherField) {
                // Remove any previously injected clause block
                const existing = doc.querySelector('.corex-additional-clauses-preview');
                if (existing) existing.remove();

                if (clauseText) {
                    // Build clause HTML (mirrors server-side insertBeforeSignatureSection)
                    const clauses = clauseText.split(/\n\s*\n/).filter(c => c.trim());
                    let html = '<div class="corex-additional-clauses-preview" style="margin-top:16pt;">';
                    html += '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                    clauses.forEach((c, i) => {
                        html += '<div style="margin:6pt 0;"><p><strong>' + (i + 1) + '.</strong> ' + c.trim().replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p></div>';
                    });
                    html += '</div>';

                    // Insert before signature section (same selectors as server-side)
                    const sigSection = doc.querySelector('.corex-signature-section') || doc.querySelector('.sig-section');
                    if (sigSection) {
                        sigSection.insertAdjacentHTML('beforebegin', html);
                    } else {
                        // Append at end of document
                        doc.insertAdjacentHTML('beforeend', html);
                    }
                }
            }

            // Also store in previewFieldValues for reapplication after preview reload
            this.previewFieldValues['other_conditions'] = clauseText;
        },

        // ---- Template selection (Step 1) ----
        selectTemplate(t) {
            this.selectedTemplateId = t.id;
            this.templateName = t.name;
            this.templateCategory = t.category || null;
            this.selectedPackId = null;
            this.selectedPackName = '';
            this.selectedPdfPackId = null;
            this.isPackFlow = false;
            this.packPreview = null;

            // Immediately reset preview state to prevent flash of old content
            this.previewPages = [];
            this.previewHtml = '';
            this.previewFields = [];
            this.previewRenderType = t.render_type || 'pdf';

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
                const resp = await fetch(url, { cache: 'no-store' });
                const data = await resp.json();
                this.previewRenderType = data.render_type || 'pdf';
                if (data.render_type === 'web') {
                    this.previewHtml = data.html || '';
                    this.previewPages = [];
                    this.previewFields = [];
                    // Reapply all stored field values after preview HTML loads
                    this.$nextTick(() => this.reapplyPreviewFields());
                } else {
                    this.previewHtml = '';
                    this.previewPages = data.pages || [];
                    this.previewFields = data.fields || [];
                }
            } catch (e) {
                console.error('Failed to load template preview:', e);
            }
        },

        reapplyPreviewFields() {
            if (this.previewRenderType !== 'web') return;
            const doc = document.querySelector('.web-template-preview');
            if (!doc) return;

            // Reapply previewFieldValues (from updatePreviewField calls)
            Object.entries(this.previewFieldValues).forEach(([fieldName, value]) => {
                if (!value) return;
                const selectors = [
                    '[data-field="' + fieldName + '"]',
                    '[data-field="' + fieldName.replace(/\./g, '_') + '"]',
                ];
                selectors.forEach(sel => {
                    doc.querySelectorAll(sel).forEach(el => {
                        // Only apply if element is currently empty or has placeholder text
                        if (!el.textContent.trim() || el.textContent.trim() === el.getAttribute('data-field')) {
                            el.textContent = value;
                            el.style.color = '#0d9488';
                            el.style.fontWeight = '600';
                        }
                    });
                });
            });

            // Also reapply fieldValues (from allWizardFields / step 5 inputs)
            Object.entries(this.fieldValues).forEach(([fieldId, value]) => {
                if (!value) return;
                const field = (this.allWizardFields || []).find(f => f.id === fieldId || f.id == fieldId);
                if (!field) return;
                const fieldName = field.field_name || field.name || '';
                if (!fieldName) return;
                const selectors = [
                    '[data-field="' + fieldName + '"]',
                    '[data-field="' + fieldName.replace(/\./g, '_') + '"]',
                ];
                selectors.forEach(sel => {
                    doc.querySelectorAll(sel).forEach(el => {
                        if (!el.textContent.trim() || el.textContent.trim() === el.getAttribute('data-field')) {
                            el.textContent = value;
                            el.style.color = '#0d9488';
                            el.style.fontWeight = '600';
                        }
                    });
                });
            });

            // Reapply other conditions (clauses) to the preview
            this.updateClausesPreview();
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
            if (type === 'field_group_display') return 'field_group_display';
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
            // Immediate client-side preview update
            const field = (this.allWizardFields || []).find(f => f.id === fieldId);
            if (field) {
                const fieldName = field.field_name || field.name || '';
                if (fieldName) this.updatePreviewField(fieldName, value);
            }
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

        isCreatorRole(role) {
            return ['creator', 'user', 'agent'].includes(role);
        },

        fieldRoleLabel(f) {
            const role = this.fieldPartyOverrides[f.id] || f.assignedTo || f.assigned_to || 'creator';
            return getRoleLabel(role);
        },

        // Fix A — returns ALL role tokens that may edit this field. When
        // an override exists (agent narrowed the assignment via the SELECT),
        // returns only the overridden role. Otherwise iterates the full
        // editable_by array preserved by ESignWizardController.
        fieldRoleTokens(f) {
            const override = this.fieldPartyOverrides[f.id];
            if (override) return [override];
            if (Array.isArray(f.editableBy) && f.editableBy.length > 0) return f.editableBy;
            const legacy = f.assignedTo || f.assigned_to || 'creator';
            return [legacy];
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

        // ---- Live preview field updates (client-side DOM manipulation) ----
        updatePreviewField(fieldName, value) {
            // Always store for reapplication after preview reload
            this.previewFieldValues[fieldName] = value;
            if (this.previewRenderType !== 'web') return;
            const doc = document.querySelector('.web-template-preview');
            if (!doc) return;
            // Try both the exact name and underscore variant
            const selectors = [
                '[data-field="' + fieldName + '"]',
                '[data-field="' + fieldName.replace(/\./g, '_') + '"]',
            ];
            selectors.forEach(sel => {
                doc.querySelectorAll(sel).forEach(el => {
                    el.textContent = value || '';
                    if (value) {
                        el.style.color = '#0d9488';
                        el.style.fontWeight = '600';
                    } else {
                        el.style.color = '';
                        el.style.fontWeight = '';
                    }
                });
            });
        },

        updatePreviewFields(fieldMap) {
            Object.entries(fieldMap).forEach(([name, value]) => {
                this.updatePreviewField(name, value);
            });
        },

        focusPreviewField(fieldName) {
            if (this.previewRenderType !== 'web') return;
            const doc = document.querySelector('.web-template-preview');
            if (!doc) return;
            const field = doc.querySelector('[data-field="' + fieldName + '"]')
                       || doc.querySelector('[data-field="' + fieldName.replace(/\./g, '_') + '"]');
            if (field) {
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field.style.outline = '2px solid #0d9488';
                setTimeout(() => { field.style.outline = ''; }, 2000);
            }
        },

        scrollPreviewToStep(step) {
            if (this.previewRenderType !== 'web') return;
            const doc = document.querySelector('.web-template-preview');
            if (!doc) return;
            const find = (...sels) => {
                for (const sel of sels) {
                    const el = doc.querySelector(sel);
                    if (el) return el;
                }
                return null;
            };
            let target = null;
            if (step === 2) {
                target = find('[data-field="property_address"]', '[data-field="property_full_address"]', '[data-field="property_erf_number"]');
            } else if (step === 3) {
                target = find('[data-field="seller_name"]', '[data-field="lessor_name"]', '[data-field="contact_full_names"]');
            } else if (step === 4) {
                target = find('[data-field="price"]', '[data-field="monthly_rental"]', '[data-field="mandate_start"]', '[data-field="commission_percent"]');
            } else if (step === 5) {
                target = doc.firstElementChild;
            }
            if (target) {
                setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
            }
        },

        quickFillExpiry(months) {
            const start = this.details.mandate_start || new Date().toISOString().slice(0, 10);
            const d = new Date(start);
            d.setMonth(d.getMonth() + months);
            this.details.mandate_expiry = d.toISOString().slice(0, 10);
            this.updatePreviewField('mandate_expiry', this.details.mandate_expiry);
        },

        calcExpiryDate(months) {
            const start = this.details.mandate_start || new Date().toISOString().slice(0, 10);
            const d = new Date(start);
            d.setMonth(d.getMonth() + months);
            return d.toISOString().slice(0, 10);
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
            if (this.currentStep === 3) {
                // Block if any recipient's role doesn't match template signing parties
                if (this.recipientRoleMismatches.length > 0) return false;
                // Block if any non-agent recipient has no role
                const hasEmptyRole = this.recipients.some(r => !r.readonly && !r.role);
                if (hasEmptyRole) return false;
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
            if (this.loading) return;
            if (!this.canGoNext()) return;
            this.loading = true;

            try {
                // Auto-build document name when leaving step 3 (Recipients) if not yet set
                if (this.currentStep === 3 && !this.documentName) {
                    this.buildDocumentName();
                }

                if (this.currentStep === 1) {
                    await this.createFlow();
                } else if (this.currentStep === 6) {
                    await this.prepareSigning();
                } else {
                    await this.saveAndAdvance();
                }
            } catch (e) {
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
                        first_name: r.first_name || '',
                        last_name: r.last_name || '',
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
                        // Rental fields
                        monthly_rental: this.details.monthly_rental,
                        deposit: this.details.deposit,
                        lease_start: this.details.lease_start,
                        lease_end: this.details.lease_end,
                        // Sales fields
                        price: this.details.price,
                        mandate_start: this.details.mandate_start,
                        mandate_expiry: this.details.mandate_expiry,
                        // Shared fields
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
                case 5: return { fieldValues: { ...this.fieldValues }, partyOverrides: { ...this.fieldPartyOverrides }, clauses: this.selectedClauses, other_conditions_text: this.otherConditionsText };
                case 6: return {
                    delivery_mode: this.deliveryMode,
                    parties: this.signingActions.map((action, i) => ({
                        signing_order: i + 1,
                        action,
                        role: this.recipients[i]?.role || '',
                        name: this.recipients[i]?.name || '',
                        email: this.recipients[i]?.email || '',
                        skipEmail: this.recipients[i]?.skipEmail || false,
                        fica_required: this.recipients[i]?.fica_required || false,
                    })),
                };
                default: return {};
            }
        },

        buildDocumentName() {
            const base = this.isPackFlow ? this.selectedPackName : this.templateName;
            const firstRecipient = (this.recipients || []).find(r => r.role !== 'agent' && r.name);
            const today = new Date().toISOString().slice(0, 10);
            let name = base || 'Untitled';
            if (firstRecipient) name += ' — ' + firstRecipient.name;
            name += ' — ' + today;
            this.documentName = name;
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
                body: JSON.stringify({ data: this.getStepData(), document_name: this.documentName }),
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
            if (!this.flowId) {
                this.showToast('Error: No flow ID found. Please reload and try again.', 'error');
                return;
            }
            this.loading = true;
            try {
                // First save step 6 data via AJAX (lightweight, always works)
                const saveUrl = '/docuperfect/esign/' + this.flowId + '/step/6';
                const stepData = this.getStepData();
                const saveResp = await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ data: stepData }),
                });
                if (!saveResp.ok) {
                    throw new Error('Failed to save signing setup (step 6): HTTP ' + saveResp.status);
                }

                // Branch by delivery mode BEFORE form submission so each mode
                // hits its own dedicated endpoint on the server.
                let prepareUrl;
                switch (this.deliveryMode) {
                    case 'download':
                        prepareUrl = '/docuperfect/esign/' + this.flowId + '/prepare-download';
                        break;
                    case 'wet_ink':
                        prepareUrl = '/docuperfect/esign/' + this.flowId + '/prepare-wet-ink';
                        break;
                    default: // 'esign'
                        prepareUrl = '/docuperfect/esign/' + this.flowId + '/prepare-signing';
                        break;
                }

                if (this.deliveryMode === 'download' || this.deliveryMode === 'wet_ink') {
                    // download / wet-ink endpoints still redirect — native submit.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = prepareUrl;
                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = '_token';
                    tokenInput.value = csrfToken;
                    form.appendChild(tokenInput);
                    document.body.appendChild(form);
                    form.submit();
                    return; // browser navigates away
                }

                // e-sign: fetch JSON so a server-side failure is surfaced in
                // the UI instead of a blind navigation (audit BL-2b).
                const prepResp = await fetch(prepareUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                });
                let prep = null;
                try { prep = await prepResp.json(); } catch (_) { prep = null; }

                if (prepResp.ok && prep && prep.ok && prep.redirect) {
                    window.location.href = prep.redirect; // → signatures.setup
                    return;
                }

                const failMsg = (prep && prep.error)
                    ? prep.error
                    : ('Failed to prepare signing (HTTP ' + prepResp.status + ')');
                this.showToast(failMsg, 'error');
                this.loading = false;
            } catch (e) {
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
            if (result.price && !this.details.price) {
                this.details.price = String(result.price);
            }
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

            // Live preview: push property fields to DOM
            // Covers both standard web template names and CDS-generated names
            const fullAddr = [result.address, result.suburb].filter(Boolean).join(', ');
            this.updatePreviewFields({
                'property_address': fullAddr,
                'property_full_address': fullAddr,
                'street_address': fullAddr,
                'property_street': result.address || '',
                'property_township': result.suburb || '',
                'property_suburb': result.suburb || '',
                'erf_no': result.erf_no || '',
                'property_erf_number': result.erf_no || '',
                'complex_name': result.complex_name || '',
                'property_complex_name': result.complex_name || '',
                'property_type': result.property_type || '',
            });

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
            r.first_name = contact.first_name || '';
            r.last_name = contact.last_name || '';
            r.email = contact.email || '';
            r.cell = contact.phone || '';
            r.id_number = contact.id_number || '';
            r.address = contact.address || '';
            r._contact_id = contact.id;
            r._searchOpen = false;
            r._searchQuery = contact.full_name;

            // Set role from esign_role (maps type to signing role) or contact_type name as fallback
            if (contact.esign_role) {
                r.role = contact.esign_role.toLowerCase();
            } else if (contact.contact_type) {
                r.role = contact.contact_type.toLowerCase();
            }

            // Store bank details for WebTemplateDataService
            r.bank_name = contact.bank_name || '';
            r.bank_account_name = contact.bank_account_name || '';
            r.bank_account_number = contact.bank_account_number || '';
            r.bank_branch_name = contact.bank_branch_name || '';

            // Live preview: push contact fields to DOM based on role
            // Covers both standard names (seller_name) and CDS generic names (contact_full_names)
            const role = r.role;
            const prefix = (role === 'seller') ? 'seller'
                         : (role === 'buyer') ? 'buyer'
                         : (role === 'landlord' || role === 'lessor') ? 'lessor'
                         : (role === 'tenant' || role === 'lessee') ? 'lessee'
                         : role;
            const contactName = contact.full_name || ((contact.first_name || '') + ' ' + (contact.last_name || '')).trim();
            this.updatePreviewFields({
                [prefix + '_name']: contactName,
                [prefix + '_first_name']: contact.first_name || '',
                [prefix + '_last_name']: contact.last_name || '',
                [prefix + '_id_number']: contact.id_number || '',
                [prefix + '_email']: contact.email || '',
                [prefix + '_cell']: contact.phone || '',
                [prefix + '_phone']: contact.phone || '',
                [prefix + '_address']: contact.address || '',
                // CDS generic names
                'contact_full_names': contactName,
                'contact_email': contact.email || '',
                'contact_phone': contact.phone || '',
                'contact_address': contact.address || '',
            });

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
            // Insert after the first owner party (landlord or seller)
            const role = this.ownerPartyRole;
            const idx = this.recipients.findIndex(r => r.role === role && !r.readonly);
            const newOwner = {
                order: 0, role: role, name: '', id_number: '', email: '', cell: '', address: '', readonly: false,
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
            // Default new recipient to acquiring party role (tenant or buyer)
            const defaultRole = this.acquiringPartyRole;
            this.recipients.push({
                order: this.recipients.length + 1, role: defaultRole, name: '', id_number: '', email: '', cell: '', address: '', readonly: false,
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
