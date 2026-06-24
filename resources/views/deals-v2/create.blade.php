@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@section('corex-content')
<div x-data="dealWizard()" x-cloak class="w-full space-y-5">

    <style>
        .deal-x-btn { transition: background 150ms ease; }
        .deal-x-btn:hover { background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 15%, transparent); }
        .deal-result-btn:hover { background: var(--surface-2); }
    </style>

    {{-- Page header (Pattern A — branded banner) --}}
    <div data-tour="deals-create-intro" class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">New Deal</h1>
                <p class="text-sm text-white/60">Capture the property, parties and commission, then generate the deal pipeline.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('deals-v2.index') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to Deals
                </a>
            </div>
        </div>
    </div>

    {{-- Step indicators --}}
    <div data-tour="deals-create-rail" class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-1 flex-wrap">
            <template x-for="(label, i) in ['Property', 'Contacts', 'Details', 'Pipeline', 'Confirm']" :key="i">
                <div class="flex items-center gap-1">
                    <button @click="if(i < step) step = i + 1" class="flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-medium transition-colors"
                            :style="step === i + 1 ? 'background:color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);color:var(--brand-button, #0ea5e9);' : (i < step - 1 ? 'color:var(--ds-green, #059669);' : 'color:var(--text-muted);')"
                            :class="i < step ? 'cursor-pointer' : 'cursor-default'">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs border"
                              :style="step === i + 1 ? 'border-color:var(--brand-button, #0ea5e9);color:var(--brand-button, #0ea5e9);' : (i < step - 1 ? 'border-color:var(--ds-green, #059669);color:var(--ds-green, #059669);background:color-mix(in srgb, var(--ds-green, #059669) 12%, transparent);' : 'border-color:var(--border);color:var(--text-muted);')"
                              x-text="i < step - 1 ? '✓' : (i + 1)"></span>
                        <span x-text="label" class="hidden sm:inline"></span>
                    </button>
                    <span x-show="i < 4" class="text-xs" style="color: var(--text-muted);">→</span>
                </div>
            </template>
        </div>
    </div>

    {{-- Toast --}}
    <div x-show="toast" x-transition class="fixed top-20 right-6 z-50 px-4 py-2.5 rounded-md text-sm font-medium shadow-lg"
         style="background: var(--ds-crimson, #c41e3a); color: #fff;" x-text="toastMessage"></div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-crimson, #c41e3a);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/></svg>
            <div class="flex-1">
                @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        </div>
    @endif

    {{-- STEP 1: Property --}}
    <div x-show="step === 1" data-tour="deals-create-step1" class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Select Property</h2>

        <div class="relative mb-4">
            <input type="text" data-tour="deals-create-property-search" x-model="propertySearch" @input.debounce.300ms="searchProperties()" placeholder="Search by address..."
                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            {{-- Results dropdown --}}
            <div x-show="propertyResults.length > 0 && !selectedProperty" class="absolute z-20 w-full mt-1 rounded-md shadow-lg overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <template x-for="p in propertyResults" :key="p.id">
                    <button @click="selectProperty(p)" class="deal-result-btn w-full text-left px-4 py-2 text-sm transition-colors" style="border-bottom: 1px solid var(--border); color: var(--text-primary);">
                        <div x-text="p.address" class="font-medium"></div>
                        <div class="text-xs" style="color: var(--text-muted);">
                            <span x-show="p.price" x-text="'R ' + Number(p.price).toLocaleString()"></span>
                            <span x-show="p.listing_agent_name" x-text="' — ' + p.listing_agent_name"></span>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        {{-- Selected property card --}}
        <div x-show="selectedProperty" class="rounded-md p-4 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-medium" style="color: var(--text-primary);" x-text="selectedProperty?.address"></div>
                    <div class="text-sm mt-1" style="color: var(--text-muted);">
                        <span x-show="selectedProperty?.price" x-text="'Listing Price: R ' + Number(selectedProperty?.price || 0).toLocaleString()"></span>
                        <span x-show="selectedProperty?.listing_agent_name" x-text="' | Agent: ' + selectedProperty?.listing_agent_name"></span>
                    </div>
                </div>
                <button @click="clearProperty()" class="deal-x-btn p-1 rounded-md" style="color: var(--text-muted);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <div class="flex justify-end">
            <button @click="if(selectedProperty) step = 2" :disabled="!selectedProperty" data-tour="deals-create-next" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                Next →
            </button>
        </div>
    </div>

    {{-- STEP 2: Contacts --}}
    <div x-show="step === 2" class="space-y-4">
        {{-- Sellers --}}
        <div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
            <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Sellers</h2>
            <div class="relative mb-3">
                <input type="text" x-model="sellerSearch" @input.debounce.300ms="searchContacts('seller')" placeholder="Search contacts..."
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <div x-show="sellerResults.length > 0" class="absolute z-20 w-full mt-1 rounded-md shadow-lg overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <template x-for="c in sellerResults" :key="c.id">
                        <button @click="addContact(c, 'seller'); sellerResults = []; sellerSearch = '';" class="deal-result-btn w-full text-left px-4 py-2 text-sm transition-colors" style="border-bottom: 1px solid var(--border); color: var(--text-primary);">
                            <span x-text="c.name" class="font-medium"></span>
                            <span class="text-xs ml-2" style="color: var(--text-muted);" x-text="c.email || c.phone || ''"></span>
                        </button>
                    </template>
                </div>
            </div>
            <template x-for="(c, i) in contacts.filter(c => c.role === 'seller' || c.role === 'co_seller')" :key="c.contact_id">
                <div class="flex items-center gap-3 mb-2 px-3 py-2 rounded-md" style="background: var(--surface-2);">
                    <span class="font-medium text-sm" style="color: var(--text-primary);" x-text="c.name"></span>
                    <select x-model="c.role" class="text-xs rounded-md px-2 py-1 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                        <option value="seller">Seller</option>
                        <option value="co_seller">Co-Seller</option>
                    </select>
                    <span class="text-xs ml-auto" style="color: var(--text-muted);" x-text="c.email"></span>
                    <button @click="removeContact(c)" class="deal-x-btn p-1 rounded-md" style="color: var(--text-muted);">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>
        </div>

        {{-- Buyers --}}
        <div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
            <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Buyers</h2>
            <div class="relative mb-3">
                <input type="text" x-model="buyerSearch" @input.debounce.300ms="searchContacts('buyer')" placeholder="Search contacts..."
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <div x-show="buyerResults.length > 0" class="absolute z-20 w-full mt-1 rounded-md shadow-lg overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <template x-for="c in buyerResults" :key="c.id">
                        <button @click="addContact(c, 'buyer'); buyerResults = []; buyerSearch = '';" class="deal-result-btn w-full text-left px-4 py-2 text-sm transition-colors" style="border-bottom: 1px solid var(--border); color: var(--text-primary);">
                            <span x-text="c.name" class="font-medium"></span>
                            <span class="text-xs ml-2" style="color: var(--text-muted);" x-text="c.email || c.phone || ''"></span>
                        </button>
                    </template>
                </div>
            </div>
            <template x-for="(c, i) in contacts.filter(c => c.role === 'buyer' || c.role === 'co_buyer')" :key="c.contact_id">
                <div class="flex items-center gap-3 mb-2 px-3 py-2 rounded-md" style="background: var(--surface-2);">
                    <span class="font-medium text-sm" style="color: var(--text-primary);" x-text="c.name"></span>
                    <select x-model="c.role" class="text-xs rounded-md px-2 py-1 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                        <option value="buyer">Buyer</option>
                        <option value="co_buyer">Co-Buyer</option>
                    </select>
                    <span class="text-xs ml-auto" style="color: var(--text-muted);" x-text="c.email"></span>
                    <button @click="removeContact(c)" class="deal-x-btn p-1 rounded-md" style="color: var(--text-muted);">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>
        </div>

        <div class="flex justify-between">
            <button @click="step = 1" class="corex-btn-outline">← Back</button>
            <button @click="if(hasBuyer && hasSeller) step = 3" :disabled="!hasBuyer || !hasSeller" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                Next →
            </button>
        </div>
    </div>

    {{-- STEP 3: Details --}}
    <div x-show="step === 3" class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Deal Details</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <template x-for="t in ['bond', 'cash', 'sale_of_2nd']" :key="t">
                <label class="flex items-center gap-2 px-3 py-2.5 rounded-md cursor-pointer transition-colors"
                       :style="dealType === t ? 'background:color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);border:1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 40%, transparent);' : 'background:var(--surface-2);border:1px solid var(--border);'">
                    <input type="radio" :value="t" x-model="dealType" class="rounded-full" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span class="text-sm font-medium" :style="dealType === t ? 'color:var(--brand-button, #0ea5e9);' : 'color:var(--text-secondary);'"
                          x-text="t === 'bond' ? 'Bond Sale' : (t === 'cash' ? 'Cash Sale' : 'Sale of 2nd Property')"></span>
                </label>
            </template>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Purchase Price (R)</label>
                <input type="number" x-model="purchasePrice" @input="calcFromPct()" min="1" step="0.01"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Offer Date</label>
                <input type="date" x-model="offerDate"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Commission %</label>
                <input type="number" x-model="commissionPercent" @input="calcFromPct()" min="0" max="100" step="0.01"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Commission (Inc VAT)</label>
                <input type="number" x-model="commissionIncVat" @input="calcPctFromInc()" min="0" step="0.01"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Ex VAT / VAT ({{ $vatRate }}%)</label>
                <div class="rounded-md text-sm px-3 py-2 font-mono" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
                    R <span x-text="Number(commExVatCalc).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span>
                    <span class="text-xs">+ R <span x-text="Number(commVatCalc).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span> VAT</span>
                </div>
            </div>
        </div>

        {{-- Listing / Selling Split --}}
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Listing Side %</label>
                <input type="number" x-model="listingSplitPercent" @input="sellingSplitPercent = 100 - (parseFloat(listingSplitPercent) || 0)" min="0" max="100" step="1"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Selling Side %</label>
                <div class="rounded-md text-sm px-3 py-2 font-mono" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);"
                     x-text="sellingSplitPercent + '%'"></div>
            </div>
        </div>

        {{-- Listing Side --}}
        <div class="rounded-md p-3 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-2">
                <div class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Listing Side</div>
                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" x-model="listingExternal" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> External agency
                </label>
            </div>
            <div x-show="listingExternal" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">External Agency Name</label>
                    <input type="text" x-model="listingExternalAgency" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Our Share %</label>
                    <input type="number" x-model="listingOurSharePercent" min="0" max="100" step="1" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>
            <div x-show="!listingExternal">
                <select x-model="listingAgentId" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none mb-1"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Select listing agent...</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Selling Side --}}
        <div class="rounded-md p-3 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-2">
                <div class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Selling Side</div>
                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" x-model="sellingExternal" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> External agency
                </label>
            </div>
            <div x-show="sellingExternal" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">External Agency Name</label>
                    <input type="text" x-model="sellingExternalAgency" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Our Share %</label>
                    <input type="number" x-model="sellingOurSharePercent" min="0" max="100" step="1" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>
            <div x-show="!sellingExternal">
                <select x-model="sellingAgentId" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none mb-1"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Select selling agent...</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Commission breakdown --}}
        <div class="rounded-md p-3 mb-4 font-mono text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
            <div>Commission ex VAT: <span style="color: var(--text-primary);" x-text="'R ' + Number(commExVat).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span></div>
            <div class="ml-3">├─ Listing pool (<span x-text="listingSplitPercent"></span>%): <span style="color: var(--text-primary);" x-text="'R ' + Number(listingPoolCalc).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span>
                <span x-show="listingExternal" style="color: var(--ds-amber, #f59e0b);"> (External)</span>
            </div>
            <div class="ml-3">└─ Selling pool (<span x-text="sellingSplitPercent"></span>%): <span style="color: var(--text-primary);" x-text="'R ' + Number(sellingPoolCalc).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span>
                <span x-show="sellingExternal" style="color: var(--ds-amber, #f59e0b);"> (External)</span>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
            <textarea x-model="notes" rows="2" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
        </div>

        <div class="flex justify-between">
            <button @click="step = 2" class="corex-btn-outline">← Back</button>
            <button @click="if(purchasePrice > 0 && hasRequiredAgents) { selectTemplate(); step = 4; }" :disabled="!purchasePrice || !hasRequiredAgents" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                Next →
            </button>
        </div>
    </div>

    {{-- STEP 4: Pipeline Review --}}
    <div x-show="step === 4" class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pipeline Steps</h2>
            <div>
                <label class="text-xs mr-2" style="color: var(--text-muted);">Template:</label>
                <select x-model="selectedTemplateId" @change="loadTemplate()" class="text-sm rounded-md px-2 py-1 focus:outline-none"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <template x-for="t in availableTemplates" :key="t.id">
                        <option :value="t.id" x-text="t.name"></option>
                    </template>
                </select>
            </div>
        </div>

        <div class="space-y-1 mb-4">
            <template x-for="(ps, idx) in pipelineSteps" :key="ps.id">
                <div class="flex items-center gap-3 px-3 py-2 rounded-md flex-wrap" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <span class="text-xs font-mono w-5 text-center" style="color: var(--text-muted);" x-text="idx + 1"></span>
                    <span class="font-medium text-sm" style="color: var(--text-primary);" x-text="ps.name"></span>
                    <span x-show="ps.is_locked" style="color: var(--ds-amber, #f59e0b);" title="Locked">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    </span>
                    <span x-show="ps.is_milestone" style="color: var(--brand-icon, #0ea5e9);" title="Milestone">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
                    </span>
                    <span class="text-xs px-1.5 py-0.5 rounded-md" style="background: var(--surface); color: var(--text-muted);" x-text="ps.completion_type.replace('_', ' ')"></span>
                    <div class="flex items-center gap-1 ml-auto">
                        <label class="text-xs" style="color: var(--text-muted);">+</label>
                        <input type="number" :value="stepOverrides[ps.id]?.days_offset ?? ps.days_offset" @input="setStepOverride(ps.id, 'days_offset', $event.target.value)" min="0"
                               class="w-12 rounded-md text-xs px-1 py-0.5 text-center focus:outline-none"
                               :style="(stepOverrides[ps.id]?.due_date) ? 'background:var(--surface);border:1px solid var(--border);color:var(--text-muted);opacity:0.5;' : 'background:var(--surface);border:1px solid var(--border);color:var(--text-primary);'">
                        <label class="text-xs" style="color: var(--text-muted);">d</label>
                        <span class="text-xs mx-1" style="color: var(--text-muted);">or</span>
                        <input type="date" :value="stepOverrides[ps.id]?.due_date ?? ''" @input="setStepOverride(ps.id, 'due_date', $event.target.value)"
                               class="rounded-md text-xs px-1 py-0.5 focus:outline-none"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-between">
            <button @click="step = 3" class="corex-btn-outline">← Back</button>
            <button @click="step = 5" class="corex-btn-primary">
                Next →
            </button>
        </div>
    </div>

    {{-- STEP 5: Confirm --}}
    <div x-show="step === 5" class="space-y-4">
        <div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
            <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Confirm Deal</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Property</div>
                    <div class="font-medium" style="color: var(--text-primary);" x-text="selectedProperty?.address"></div>
                </div>
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Deal Type</div>
                    <div class="font-medium" style="color: var(--text-primary);" x-text="dealType === 'bond' ? 'Bond Sale' : (dealType === 'cash' ? 'Cash Sale' : 'Sale of 2nd Property')"></div>
                </div>
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Purchase Price</div>
                    <div class="font-medium font-mono" style="color: var(--text-primary);" x-text="'R ' + Number(purchasePrice).toLocaleString('en-ZA', {minimumFractionDigits: 2})"></div>
                </div>
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Commission</div>
                    <div class="font-medium font-mono" style="color: var(--text-primary);" x-text="'R ' + Number(commExVat).toLocaleString('en-ZA', {minimumFractionDigits: 2}) + ' + VAT'"></div>
                </div>
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Offer Date</div>
                    <div class="font-medium" style="color: var(--text-primary);" x-text="offerDate"></div>
                </div>
                <div>
                    <div class="text-xs" style="color: var(--text-muted);">Pipeline Steps</div>
                    <div class="font-medium" style="color: var(--text-primary);" x-text="pipelineSteps.length + ' steps'"></div>
                </div>
            </div>

            <div class="mb-4">
                <div class="text-xs mb-1" style="color: var(--text-muted);">Contacts</div>
                <div class="flex flex-wrap gap-2">
                    <template x-for="c in contacts" :key="c.contact_id">
                        <span class="text-xs px-2 py-1 rounded-md" style="background: var(--surface-2); color: var(--text-secondary); white-space: nowrap;" x-text="c.name + ' (' + c.role + ')'"></span>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex justify-between">
            <button @click="step = 4" class="corex-btn-outline">← Back</button>
            <button @click="submitDeal()" :disabled="submitting" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-text="submitting ? 'Creating...' : 'Create Deal'"></span>
            </button>
        </div>
    </div>
</div>

<script>
    function dealWizard() {
        return {
            step: 1,
            toast: false,
            toastMessage: '',
            submitting: false,

            // Step 1: Property
            propertySearch: '',
            propertyResults: [],
            selectedProperty: null,

            // Step 2: Contacts
            contacts: [],
            sellerSearch: '',
            sellerResults: [],
            buyerSearch: '',
            buyerResults: [],

            // Step 3: Details
            dealType: 'bond',
            purchasePrice: '',
            commissionPercent: 7.5,
            commissionIncVat: 0,
            vatRatePct: {{ $vatRate }},
            offerDate: new Date().toISOString().split('T')[0],
            listingAgentId: '',
            sellingAgentId: '',
            listingSplitPercent: 50,
            sellingSplitPercent: 50,
            listingExternal: false,
            listingExternalAgency: '',
            listingOurSharePercent: 100,
            sellingExternal: false,
            sellingExternalAgency: '',
            sellingOurSharePercent: 100,
            notes: '',

            // Step 4: Pipeline
            templates: @json($templatesJson),
            selectedTemplateId: null,
            pipelineSteps: [],
            stepOverrides: {},

            get hasBuyer() { return this.contacts.some(c => c.role === 'buyer' || c.role === 'co_buyer'); },
            get hasSeller() { return this.contacts.some(c => c.role === 'seller' || c.role === 'co_seller'); },
            get hasRequiredAgents() {
                const needListing = !this.listingExternal;
                const needSelling = !this.sellingExternal;
                return (!needListing || this.listingAgentId) && (!needSelling || this.sellingAgentId);
            },
            get commExVatCalc() {
                const vr = 1 + (this.vatRatePct / 100);
                return this.commissionIncVat > 0 ? this.commissionIncVat / vr : 0;
            },
            get commVatCalc() {
                return this.commissionIncVat > 0 ? this.commissionIncVat - this.commExVatCalc : 0;
            },
            get commExVat() { return this.commExVatCalc; },
            get listingPoolCalc() {
                if (this.listingExternal) return 0;
                return this.commExVat * (parseFloat(this.listingSplitPercent) / 100) * (parseFloat(this.listingOurSharePercent) / 100);
            },
            get sellingPoolCalc() {
                if (this.sellingExternal) return 0;
                return this.commExVat * (parseFloat(this.sellingSplitPercent) / 100) * (parseFloat(this.sellingOurSharePercent) / 100);
            },

            get availableTemplates() {
                return this.templates.filter(t => t.deal_type === this.dealType);
            },

            async searchProperties() {
                if (this.propertySearch.length < 2) { this.propertyResults = []; return; }
                const { data } = await axios.get('{{ route("deals-v2.search.properties") }}', { params: { q: this.propertySearch } });
                this.propertyResults = data;
            },

            async selectProperty(p) {
                this.selectedProperty = p;
                this.propertySearch = '';
                this.propertyResults = [];
                if (p.listing_agent_id) this.listingAgentId = String(p.listing_agent_id);
                if (p.price) this.purchasePrice = p.price;
                if (p.commission_percent) {
                    this.commissionPercent = p.commission_percent;
                    this.calcFromPct();
                }

                // Auto-load property contacts as sellers
                try {
                    const { data } = await axios.get(`/deals-v2/search/property-contacts/${p.id}`);
                    const existingIds = this.contacts.map(c => c.contact_id);
                    data.forEach(c => {
                        if (!existingIds.includes(c.id)) {
                            this.contacts.push({
                                contact_id: c.id,
                                name: c.name,
                                email: c.email,
                                phone: c.phone,
                                role: 'seller',
                            });
                        }
                    });
                } catch (e) {
                    // Non-critical — user can still add sellers manually
                }
            },

            clearProperty() {
                this.selectedProperty = null;
                this.propertySearch = '';
                // Remove auto-loaded sellers (keep manually-added contacts)
                this.contacts = this.contacts.filter(c => c.role === 'buyer' || c.role === 'co_buyer');
            },

            async searchContacts(type) {
                const q = type === 'seller' ? this.sellerSearch : this.buyerSearch;
                if (q.length < 2) { if (type === 'seller') this.sellerResults = []; else this.buyerResults = []; return; }
                const { data } = await axios.get('{{ route("deals-v2.search.contacts") }}', { params: { q } });
                if (type === 'seller') this.sellerResults = data; else this.buyerResults = data;
            },

            addContact(c, role) {
                if (this.contacts.find(x => x.contact_id === c.id && x.role === role)) return;
                this.contacts.push({ contact_id: c.id, name: c.name, email: c.email, phone: c.phone, role });
            },

            removeContact(c) {
                this.contacts = this.contacts.filter(x => x !== c);
            },

            calcFromPct() {
                if (this.purchasePrice > 0 && this.commissionPercent > 0) {
                    const exVat = parseFloat(this.purchasePrice) * (parseFloat(this.commissionPercent) / 100);
                    this.commissionIncVat = (exVat * (1 + this.vatRatePct / 100)).toFixed(2);
                }
            },

            calcPctFromInc() {
                if (this.purchasePrice > 0 && this.commissionIncVat > 0) {
                    const exVat = parseFloat(this.commissionIncVat) / (1 + this.vatRatePct / 100);
                    this.commissionPercent = ((exVat / parseFloat(this.purchasePrice)) * 100).toFixed(2);
                }
            },

            selectTemplate() {
                const avail = this.availableTemplates;
                const def = avail.find(t => t.is_default) || avail[0];
                if (def) {
                    this.selectedTemplateId = def.id;
                    this.loadTemplate();
                }
            },

            loadTemplate() {
                const t = this.templates.find(t => t.id == this.selectedTemplateId);
                this.pipelineSteps = t ? [...t.steps] : [];
                this.stepOverrides = {};
            },

            setStepOverride(stepId, field, value) {
                if (!this.stepOverrides[stepId]) this.stepOverrides[stepId] = {};
                this.stepOverrides[stepId][field] = field === 'due_date' ? (value || null) : (parseInt(value) || 0);
            },

            showToast(msg) {
                this.toastMessage = msg;
                this.toast = true;
                setTimeout(() => this.toast = false, 3000);
            },

            async submitDeal() {
                this.submitting = true;

                // Build agents array (side-based)
                const agents = [];
                if (!this.listingExternal && this.listingAgentId) {
                    agents.push({ user_id: this.listingAgentId, side: 'listing', split_percent: 100 });
                }
                if (!this.sellingExternal && this.sellingAgentId) {
                    agents.push({ user_id: this.sellingAgentId, side: 'selling', split_percent: 100 });
                }

                const payload = {
                    property_id: this.selectedProperty.id,
                    deal_type: this.dealType,
                    pipeline_template_id: this.selectedTemplateId,
                    purchase_price: this.purchasePrice,
                    commission_percentage: this.commissionPercent,
                    total_commission_inc_vat: this.commissionIncVat,
                    offer_date: this.offerDate,
                    listing_agent_id: this.listingAgentId || (agents.find(a => a.side === 'listing')?.user_id ?? null),
                    selling_agent_id: this.sellingAgentId || null,
                    listing_split_percent: this.listingSplitPercent,
                    selling_split_percent: this.sellingSplitPercent,
                    listing_external: this.listingExternal ? 1 : 0,
                    listing_our_share_percent: this.listingOurSharePercent,
                    listing_external_agency: this.listingExternalAgency || null,
                    selling_external: this.sellingExternal ? 1 : 0,
                    selling_our_share_percent: this.sellingOurSharePercent,
                    selling_external_agency: this.sellingExternalAgency || null,
                    notes: this.notes || null,
                    contacts: this.contacts.map(c => ({ contact_id: c.contact_id, role: c.role })),
                    agents: agents,
                    step_overrides: this.stepOverrides,
                };

                try {
                    const response = await axios.post('{{ route("deals-v2.store") }}', payload);
                    // Axios follows redirects on 302 — for form POST, use native form
                    window.location.href = '{{ route("deals-v2.index") }}';
                } catch (err) {
                    const errors = err.response?.data?.errors;
                    if (errors) {
                        this.showToast(Object.values(errors).flat().join(', '));
                    } else {
                        this.showToast(err.response?.data?.message || 'Failed to create deal.');
                    }
                    this.submitting = false;
                }
            },
        };
    }
</script>
@endsection
