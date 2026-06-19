@extends('layouts.corex')

@section('corex-content')
@php
    $currentUser = auth()->user();
    $listingTypes = ['sale' => 'For Sale', 'rental' => 'For Rental'];
@endphp

<div class="max-w-4xl mx-auto"
     x-data="propertyWizard({
        draftId: {{ $draft?->id ?: 'null' }},
        csrf: '{{ csrf_token() }}',
        routes: {
            draft: '{{ route('corex.properties.wizard.draft') }}',
            photos: '{{ url('/corex/properties/wizard') }}',
            showBase: '{{ url('/corex/properties') }}',
            discardBase: '{{ url('/corex/properties/wizard') }}',
        },
        existingImages: {{ collect($draft?->gallery_images_json ?? [])->values()->toJson() }},
        suburbs: {{ $suburbs->toJson() }},
    })">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 mb-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-xl font-bold text-white tracking-tight">New Property</h2>
                <p class="text-sm mt-1" style="color:rgba(255,255,255,0.65);">Add a listing in 4 quick steps. Save as draft any time.</p>
            </div>
            <a href="{{ route('corex.properties.index') }}"
               class="text-xs font-medium px-3 py-1.5 rounded-md transition-all duration-300"
               style="background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.15);">
                &larr; Back to listings
            </a>
        </div>

        {{-- Progress bar --}}
        <div class="mt-5">
            <div class="flex items-center gap-2">
                @foreach([1 => 'Basics', 2 => 'Photos', 3 => 'Details', 4 => 'Review'] as $n => $label)
                    <template x-if="{{ $n }} > 1">
                        <div class="flex-1 h-0.5 transition-all duration-300"
                             :style="step >= {{ $n }} ? 'background:var(--brand-icon,#0ea5e9);' : 'background:rgba(255,255,255,0.15);'"></div>
                    </template>
                    <button type="button"
                            @click="goToStep({{ $n }})"
                            :disabled="!canJumpTo({{ $n }})"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                            :style="step === {{ $n }}
                                ? 'background:var(--brand-icon,#0ea5e9);color:#fff;'
                                : step > {{ $n }}
                                    ? 'background:rgba(255,255,255,0.18);color:#fff;cursor:pointer;'
                                    : 'background:rgba(255,255,255,0.10);color:rgba(255,255,255,0.85);cursor:not-allowed;'">
                        <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[10px] font-bold"
                              :style="step === {{ $n }}
                                ? 'background:#fff !important;color:#0b2a4a !important;'
                                : step > {{ $n }}
                                    ? 'background:var(--brand-icon,#0ea5e9);color:#fff;'
                                    : 'background:rgba(255,255,255,0.25);color:#fff;'">
                            <template x-if="step > {{ $n }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </template>
                            <template x-if="step <= {{ $n }}">
                                <span>{{ $n }}</span>
                            </template>
                        </span>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Resume draft banner --}}
    @if($draft)
    <div x-show="step === 1 && !resumedDraft"
         class="rounded-md px-4 py-3 mb-5 flex items-center justify-between"
         style="background:color-mix(in srgb,#f59e0b 12%,var(--surface));border:1px solid color-mix(in srgb,#f59e0b 35%,transparent);color:var(--text-primary);">
        <div class="text-sm">
            <strong>You have an unfinished draft:</strong>
            <span class="font-medium">{{ $draft->title ?: 'Untitled draft' }}</span>
            <span class="text-xs" style="color:var(--text-secondary);">&middot; {{ $draft->updated_at->diffForHumans() }}</span>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="resumeDraft()"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md"
                    style="background:var(--brand-icon,#0ea5e9);color:#fff;">Continue draft</button>
            <form method="POST" action="{{ url('/corex/properties/wizard/' . $draft->id) }}" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit"
                        onclick="return confirm('Discard this draft? It will be archived.');"
                        class="text-xs font-medium px-3 py-1.5 rounded-md"
                        style="background:var(--surface);color:var(--text-primary);border:1px solid var(--border);">Start fresh</button>
            </form>
        </div>
    </div>
    @endif

    {{-- STEP 1: BASICS ──────────────────────────────────────────────── --}}
    <section x-show="step === 1" x-cloak class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <header class="px-6 py-4" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Step 1 &middot; The basics</h3>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Just the essentials — everything else can wait.</p>
        </header>
        <div class="p-6 space-y-5">

            {{-- Listing type tiles --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-secondary);">Listing type</label>
                <div class="grid grid-cols-2 gap-3">
                    @foreach($listingTypes as $value => $label)
                    <button type="button" @click="s1.listing_type = '{{ $value }}'"
                            :class="s1.listing_type === '{{ $value }}' ? 'ring-2' : ''"
                            :style="s1.listing_type === '{{ $value }}'
                                ? '--tw-ring-color:var(--brand-icon,#0ea5e9);background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 8%,var(--surface));border-color:var(--brand-icon,#0ea5e9);'
                                : 'background:var(--surface-2);border-color:var(--border);'"
                            class="rounded-md border px-4 py-4 flex items-center gap-3 transition-all duration-200 cursor-pointer">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-md"
                              :style="s1.listing_type === '{{ $value }}' ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);'">
                            @if($value === 'sale')
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10"/></svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                            @endif
                        </span>
                        <div class="text-left">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $label }}</div>
                            <div class="text-[11px]" style="color:var(--text-muted);">{{ $value === 'sale' ? 'Property is on the market' : 'Property is for rent' }}</div>
                        </div>
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Title / Headline --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Headline <span class="text-red-500">*</span></label>
                <input type="text" x-model="s1.title" maxlength="200"
                       placeholder="e.g. Stunning 3 Bed Family Home in Uvongo Beach"
                       class="w-full px-3 py-2.5 text-sm rounded-md outline-none transition-all duration-200"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                <div class="text-[11px] mt-1" style="color:var(--text-muted);" x-text="(s1.title?.length || 0) + ' / 200'"></div>
            </div>

            {{-- Property type --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Property type <span class="text-red-500">*</span></label>
                    <select x-model="s1.property_type"
                            class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                            style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                        <option value="">Choose a type…</option>
                        @foreach($settingItems['types'] as $type)
                        <option value="{{ $type->name }}">{{ ucfirst(str_replace('_', ' ', $type->name)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">
                        <span x-show="s1.listing_type === 'sale'">Asking price (R) <span class="text-red-500">*</span></span>
                        <span x-show="s1.listing_type === 'rental'">Monthly rental (R) <span class="text-red-500">*</span></span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold" style="color:var(--text-muted);">R</span>
                        <input type="number" x-model.number="s1.price" min="0" step="1000" placeholder="1 200 000"
                               class="w-full pl-7 pr-3 py-2.5 text-sm rounded-md outline-none"
                               style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                    </div>
                    <div class="text-[11px] mt-1" style="color:var(--text-muted);" x-show="s1.price > 0" x-text="formatZAR(s1.price)"></div>
                </div>
            </div>

            {{-- Internal Address — same structured field set as the property
                 show-page Internal Address modal, rendered inline here. Bound to
                 s1.* so submitStep1 ships every part. The p24 picker still gates
                 saving: no save unless Property24 recognises the suburb. --}}
            <div class="space-y-5">

                {{-- Complex or Estate --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Complex or Estate</div>
                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Unit Number</label>
                                <input type="text" x-model="s1.unit_number" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Floor Number</label>
                                <input type="text" x-model="s1.floor_number" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Unit, Section or Block</label>
                            <input type="text" x-model="s1.unit_section_block" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Complex or Estate</label>
                            <input type="text" x-model="s1.complex_name" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Street --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Street</div>
                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Number</label>
                            <input type="text" x-model="s1.street_number" placeholder="e.g. 1046-2" autocomplete="off" class="w-40 rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Name</label>
                            <input type="text" x-model="s1.street_name" placeholder="e.g. Clarendon Road" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Province / City / Suburb — Property24-backed typeahead.
                     User must pick a suburb P24 recognises; can't save free-text.
                     See _partials/p24-location-picker.blade.php. --}}
                <div x-ref="p24Picker">
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Province / City / Suburb</div>
                    <div class="p-4 rounded-b-md" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        @include('corex._partials.p24-location-picker', [
                            'fieldPrefix'        => 'p24',
                            'initialProvinceId'  => 0,
                            'initialCityId'      => 0,
                            'initialSuburbId'    => 0,
                            'initialProvinceName'=> '',
                            'initialCityName'    => '',
                            'initialSuburbName'  => '',
                            'denormaliseNames'   => false,
                        ])
                        <div class="text-[11px] mt-2" style="color:var(--text-muted);">
                            Start typing a province, city, or suburb — pick from the list. You can't save unless Property24 recognises the suburb.
                        </div>
                    </div>
                </div>

                {{-- More Info --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">More Info</div>
                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Property / Erf Number</label>
                                <input type="text" x-model="s1.property_number" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Stand Number</label>
                                <input type="text" x-model="s1.stand_number" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Zone Type</label>
                                <select x-model="s1.zone_type" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">-- None --</option>
                                    @foreach(['Residential','Commercial','Industrial','Agricultural','Mixed Use'] as $zt)
                                    <option value="{{ $zt }}">{{ $zt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">District / Municipality</label>
                                <input type="text" x-model="s1.district" placeholder="e.g. Ray Nkonyeni" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Region</label>
                            <input type="text" x-model="s1.region" placeholder="KZN South Coast" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Internal Note</label>
                            <textarea x-model="s1.address_internal_note" rows="2" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Bedrooms / Bathrooms / Garages as steppers --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-secondary);">Rooms</label>
                <div class="grid grid-cols-3 gap-3">
                    @foreach([['beds', 'Bedrooms', '🛏'], ['baths', 'Bathrooms', '🚿'], ['garages', 'Garages', '🚗']] as [$key, $label, $emoji])
                    <div class="rounded-md px-3 py-3 text-center" style="background:var(--surface-2);border:1px solid var(--border);">
                        <div class="text-[11px] font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">{{ $label }}</div>
                        <div class="flex items-center justify-between gap-2">
                            <button type="button" @click="s1.{{ $key }} = Math.max(0, s1.{{ $key }} - 1)"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-md text-sm font-bold transition-all duration-200"
                                    style="background:var(--surface);border:1px solid var(--border);color:var(--text-primary);">&minus;</button>
                            <span class="text-lg font-bold" style="color:var(--text-primary);" x-text="@if($key === 'baths')(s1.baths || 0) + ((s1.half_baths || 0) > 0 ? 0.5 : 0)@else s1.{{ $key }}@endif"></span>
                            <button type="button" @click="s1.{{ $key }} = Math.min(20, s1.{{ $key }} + 1)"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-md text-sm font-bold transition-all duration-200"
                                    style="background:var(--brand-icon,#0ea5e9);color:#fff;">+</button>
                        </div>
                        @if($key === 'baths')
                        {{-- Half (guest) bathroom — toggled from inside the Bathrooms tile. --}}
                        <button type="button" @click="s1.half_baths = (s1.half_baths || 0) > 0 ? 0 : 1"
                                class="mt-2 inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold transition-all duration-200"
                                :style="(s1.half_baths || 0) > 0
                                    ? 'background:var(--brand-icon,#0ea5e9);color:#fff;border:1px solid var(--brand-icon,#0ea5e9);'
                                    : 'background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);'"
                                title="Add a half (guest) bathroom">
                            <span class="text-sm leading-none">½</span>
                            <span x-text="(s1.half_baths || 0) > 0 ? 'Half bath' : 'Add half'"></span>
                        </button>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Error banner --}}
            <div x-show="error" x-cloak
                 class="rounded-md px-4 py-3 text-sm"
                 style="background:color-mix(in srgb,#ef4444 10%,var(--surface));border:1px solid color-mix(in srgb,#ef4444 35%,transparent);color:var(--text-primary);"
                 x-text="error"></div>

        </div>
        {{-- Step 1 footer --}}
        <footer class="px-6 py-4 flex items-center justify-between" style="background:var(--surface-2);border-top:1px solid var(--border);">
            <div class="text-[11px]" style="color:var(--text-muted);">Your work saves as a draft when you continue.</div>
            <button type="button" @click="submitStep1()"
                    :disabled="!step1Valid() || loading"
                    :style="'background:var(--brand-button,#0ea5e9);' + (!step1Valid() || loading ? 'opacity:0.5;cursor:not-allowed;' : '')"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300">
                <span x-show="!loading">Continue to photos</span>
                <span x-show="loading">Saving…</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </footer>
    </section>

    {{-- STEP 2: PHOTOS ──────────────────────────────────────────────── --}}
    <section x-show="step === 2" x-cloak class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <header class="px-6 py-4" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Step 2 &middot; Photos</h3>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Drag images in or tap to browse. First photo is your cover — drag to reorder.</p>
        </header>
        <div class="p-6 space-y-4">

            {{-- Drop zone --}}
            <label for="wizard-files"
                   @dragover.prevent="dragOver = true"
                   @dragleave.prevent="dragOver = false"
                   @drop.prevent="handleDrop($event)"
                   class="block rounded-md border-2 border-dashed cursor-pointer transition-all duration-200 px-6 py-10 text-center"
                   :style="dragOver ? 'border-color:var(--brand-icon,#0ea5e9);background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 6%,var(--surface));' : 'border-color:var(--border);background:var(--surface-2);'">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto w-10 h-10 mb-3" style="color:var(--brand-icon,#0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 7.5 7.5 12M12 7.5v9"/>
                </svg>
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Drag photos here</div>
                <div class="text-xs mt-1" style="color:var(--text-muted);">or <span style="color:var(--brand-icon,#0ea5e9);" class="underline font-medium">click to browse</span></div>
                <div class="text-[11px] mt-2" style="color:var(--text-muted);">JPG / PNG &middot; up to 5 MB each</div>
                <input id="wizard-files" type="file" class="hidden" multiple accept="image/*" @change="handleFiles($event.target.files)">
            </label>

            {{-- Upload progress --}}
            <div x-show="uploading" x-cloak class="rounded-md px-4 py-3" style="background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 8%,var(--surface));border:1px solid color-mix(in srgb,var(--brand-icon,#0ea5e9) 30%,transparent);">
                <div class="flex items-center justify-between text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">
                    <span>Uploading photos…</span>
                    <span x-text="uploadedCount + ' / ' + uploadTotal"></span>
                </div>
                <div class="mt-2 h-1.5 rounded-full overflow-hidden" style="background:rgba(255,255,255,0.3);">
                    <div class="h-full transition-all duration-300" :style="'background:var(--brand-icon,#0ea5e9);width:' + (uploadTotal > 0 ? (uploadedCount/uploadTotal*100) : 0) + '%;'"></div>
                </div>
            </div>

            {{-- Photo grid --}}
            <div x-show="photos.length" x-cloak class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                <template x-for="(photo, idx) in photos" :key="photo">
                    <div class="relative group rounded-md overflow-hidden aspect-[4/3]" style="background:var(--surface-2);border:1px solid var(--border);"
                         draggable="true"
                         @dragstart="dragIdx = idx"
                         @dragover.prevent
                         @drop="reorderPhoto(idx)">
                        <img :src="photo" alt="" class="w-full h-full object-cover">
                        <template x-if="idx === 0">
                            <span class="absolute top-1.5 left-1.5 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider" style="background:#f59e0b;color:#fff;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.77 5.82 22 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                                Cover
                            </span>
                        </template>
                        <span class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded-md text-[10px] font-bold" style="background:rgba(0,0,0,0.6);color:#fff;" x-text="idx + 1"></span>
                        <div class="absolute inset-0 flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-all duration-200" style="background:rgba(0,0,0,0.35);">
                            <button type="button" @click="makeCover(idx)" x-show="idx !== 0"
                                    class="px-2 py-1 rounded-md text-[10px] font-semibold"
                                    style="background:#fff;color:var(--brand-default,#0b2a4a);">Cover</button>
                            <button type="button" @click="removePhoto(idx)"
                                    class="px-2 py-1 rounded-md text-[10px] font-semibold"
                                    style="background:var(--ds-crimson);color:#fff;">Remove</button>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!photos.length" x-cloak class="text-center py-4 text-xs" style="color:var(--text-muted);">
                No photos uploaded yet. You can skip this step and add photos later.
            </div>

        </div>
        <footer class="px-6 py-4 flex items-center justify-between" style="background:var(--surface-2);border-top:1px solid var(--border);">
            <button type="button" @click="step = 1"
                    class="text-sm font-medium px-4 py-2 rounded-md"
                    style="background:transparent;color:var(--text-secondary);border:1px solid var(--border);">&larr; Back</button>
            <div class="flex items-center gap-2">
                <button type="button" @click="step = 3"
                        class="text-sm font-medium px-4 py-2 rounded-md"
                        style="background:transparent;color:var(--text-secondary);border:1px solid var(--border);">Skip for now</button>
                <button type="button" @click="step = 3"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300"
                        style="background:var(--brand-button,#0ea5e9);">
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </footer>
    </section>

    {{-- STEP 3: DETAILS ─────────────────────────────────────────────── --}}
    <section x-show="step === 3" x-cloak class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <header class="px-6 py-4" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Step 3 &middot; Details</h3>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Fill in what you have. You can always come back later.</p>
        </header>
        <div class="p-6 space-y-5">

            {{-- Description --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Description</label>
                <textarea x-model="s3.description" rows="5"
                          placeholder="Tell buyers what makes this home special. Views, light, layout, outdoor space…"
                          class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                          style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"></textarea>
                <div class="text-[11px] mt-1" style="color:var(--text-muted);" x-text="(s3.description?.length || 0) + ' characters'"></div>
            </div>

            {{-- Mandate (branch is auto-assigned to the creating user's branch) --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Mandate</label>
                <select x-model="s3.mandate_type"
                        class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                        style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                    <option value="">Not set</option>
                    @foreach($settingItems['mandateTypes'] as $m)
                    <option value="{{ $m->name }}">{{ $m->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Agent picker (admin/BM only) --}}
            @php
                $canPickAgent = in_array(\App\Services\PermissionService::getDataScope($currentUser, 'properties'), ['all', 'branch']);
            @endphp
            @if($canPickAgent)
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Listing agent</label>
                <select x-model="s3.agent_id"
                        class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                        style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                    @foreach($agents as $a)
                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Sizes --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Floor size (m²)</label>
                    <input type="number" x-model.number="s3.size_m2" min="0"
                           class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                           style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Erf size (m²)</label>
                    <input type="number" x-model.number="s3.erf_size_m2" min="0"
                           class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                           style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                </div>
            </div>

            {{-- Rental-only fields --}}
            <div x-show="s1.listing_type === 'rental'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Deposit (R)</label>
                    <input type="number" x-model.number="s3.deposit_amount" min="0"
                           class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                           style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Lease start</label>
                    <input type="date" x-model="s3.lease_start_date"
                           class="w-full px-3 py-2.5 text-sm rounded-md outline-none"
                           style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                </div>
            </div>


        </div>
        <footer class="px-6 py-4 flex items-center justify-between" style="background:var(--surface-2);border-top:1px solid var(--border);">
            <button type="button" @click="step = 2"
                    class="text-sm font-medium px-4 py-2 rounded-md"
                    style="background:transparent;color:var(--text-secondary);border:1px solid var(--border);">&larr; Back</button>
            <button type="button" @click="submitStep3()"
                    :disabled="loading"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300"
                    style="background:var(--brand-button,#0ea5e9);">
                <span x-show="!loading">Continue to review</span>
                <span x-show="loading">Saving…</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </footer>
    </section>

    {{-- STEP 4: REVIEW ──────────────────────────────────────────────── --}}
    <section x-show="step === 4" x-cloak class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <header class="px-6 py-4" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Step 4 &middot; Review</h3>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Take a look. Publish when ready — or save as draft and finish later.</p>
        </header>
        <div class="p-6 space-y-5">

            {{-- Readiness checklist --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-secondary);">Ready to publish?</div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <template x-for="item in checklist" :key="item.key">
                        <button type="button" @click="goToStep(item.step)"
                                class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-medium text-left transition-all duration-200"
                                :style="item.ok
                                    ? 'background:color-mix(in srgb,#22c55e 10%,var(--surface));color:var(--text-primary);border:1px solid color-mix(in srgb,#22c55e 35%,transparent);'
                                    : 'background:color-mix(in srgb,#f59e0b 10%,var(--surface));color:var(--text-primary);border:1px solid color-mix(in srgb,#f59e0b 35%,transparent);'">
                            <svg x-show="item.ok" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <svg x-show="!item.ok" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                            <span x-text="item.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Summary preview card --}}
            <div class="rounded-md overflow-hidden" style="background:var(--surface-2);border:1px solid var(--border);">
                <div class="aspect-video relative" style="background:var(--brand-default,#0b2a4a);">
                    <template x-if="photos.length">
                        <img :src="photos[0]" class="w-full h-full object-cover">
                    </template>
                    <template x-if="!photos.length">
                        <div class="absolute inset-0 flex items-center justify-center" style="color:rgba(255,255,255,0.2);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-14 h-14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/></svg>
                        </div>
                    </template>
                    <span class="absolute bottom-3 left-4 text-xl font-bold text-white" style="text-shadow:0 2px 4px rgba(0,0,0,0.5);" x-text="formatZAR(s1.price)"></span>
                </div>
                <div class="p-4 space-y-2">
                    <h4 class="text-base font-semibold" style="color:var(--text-primary);" x-text="s1.title || 'Untitled'"></h4>
                    <div class="text-xs" style="color:var(--text-muted);" x-text="[s1.street_number, s1.street_name, s1.suburb].filter(Boolean).join(' ')"></div>
                    <div class="flex flex-wrap items-center gap-3 text-xs" style="color:var(--text-secondary);">
                        <span x-text="(s1.beds || 0) + ' Bed'"></span>
                        <span style="color:var(--border);">|</span>
                        <span x-text="(s1.baths || 0) + ' Bath' + ((s1.half_baths || 0) > 0 ? ' + ½' : '')"></span>
                        <span style="color:var(--border);">|</span>
                        <span x-text="(s1.garages || 0) + ' Gar'"></span>
                        <template x-if="s3.size_m2"><span x-text="s3.size_m2 + ' m²'"></span></template>
                    </div>
                    <div class="text-xs" style="color:var(--text-muted);" x-text="(s3.description || '').substring(0, 180)"></div>
                </div>
            </div>

            {{-- Publish warning --}}
            <div x-show="!allReady" x-cloak class="rounded-md px-4 py-3 text-xs" style="background:color-mix(in srgb,#f59e0b 10%,var(--surface));border:1px solid color-mix(in srgb,#f59e0b 35%,transparent);color:var(--text-primary);">
                Some items are missing. You can still save as a draft now and finish later, or go back and complete them to publish live.
            </div>
        </div>
        <footer class="px-6 py-4 flex items-center justify-between gap-2 flex-wrap" style="background:var(--surface-2);border-top:1px solid var(--border);">
            <button type="button" @click="step = 3"
                    class="text-sm font-medium px-4 py-2 rounded-md"
                    style="background:transparent;color:var(--text-secondary);border:1px solid var(--border);">&larr; Back</button>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" @click="finalize(false)"
                        :disabled="loading"
                        class="text-sm font-medium px-4 py-2 rounded-md"
                        style="background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);">Save as draft</button>
                <button type="button" @click="finalize(true)"
                        :disabled="!allReady || loading"
                        :style="'background:var(--brand-button,#0ea5e9);' + (!allReady || loading ? 'opacity:0.5;cursor:not-allowed;' : '')"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <span x-show="!loading">Save &amp; publish</span>
                    <span x-show="loading">Publishing…</span>
                </button>
            </div>
        </footer>
    </section>

</div>

<script>
function propertyWizard(config) {
    return {
        step: 1,
        loading: false,
        error: null,
        resumedDraft: false,
        propertyId: config.draftId,
        csrf: config.csrf,
        routes: config.routes,

        // P24 picker state is owned by the included partial component.
        // We mirror only the final IDs into s1 via the p24-location-changed
        // event so submitStep1 can ship them.

        // Photos
        photos: config.existingImages || [],
        dragOver: false,
        dragIdx: null,
        uploading: false,
        uploadedCount: 0,
        uploadTotal: 0,

        // Step data — agent_id defaults to current user; the server enforces who can change it.
        s1: { listing_type: 'sale', title: '', property_type: '', suburb: '', city: '', province: '',
              p24_province_id: 0, p24_city_id: 0, p24_suburb_id: 0,
              street_number: '', street_name: '', price: null, beds: 0, baths: 0, half_baths: 0, garages: 0,
              unit_number: '', floor_number: '', unit_section_block: '', complex_name: '',
              property_number: '', stand_number: '', zone_type: '', district: '', region: '',
              address_internal_note: '' },
        s3: { description: '', mandate_type: '', branch_id: '{{ auth()->user()->effectiveBranchId() ?? '' }}', agent_id: '{{ auth()->id() }}', size_m2: null, erf_size_m2: null, deposit_amount: null, lease_start_date: '', lease_end_date: '', rental_amount: null },

        init() {
            // Sync P24 picker state into s1 so submitStep1 picks it up.
            window.addEventListener('p24-location-changed:p24', (e) => {
                if (!e.detail) return;
                this.s1.p24_province_id = e.detail.provinceId || 0;
                this.s1.p24_city_id     = e.detail.cityId || 0;
                this.s1.p24_suburb_id   = e.detail.suburbId || 0;
                this.s1.province        = e.detail.provinceName || '';
                this.s1.city            = e.detail.cityName || '';
                this.s1.suburb          = e.detail.suburbName || '';
            });
        },

        get checklist() {
            return [
                { key: 'title',   label: 'Headline',      ok: !!this.s1.title, step: 1 },
                { key: 'price',   label: 'Price',         ok: !!this.s1.price, step: 1 },
                { key: 'type',    label: 'Property type', ok: !!this.s1.property_type, step: 1 },
                { key: 'suburb',  label: 'Suburb',        ok: !!this.s1.p24_suburb_id && this.s1.p24_suburb_id != 0, step: 1 },
                { key: 'photos',  label: 'Photos',        ok: this.photos.length >= 1, step: 2 },
                { key: 'desc',    label: 'Description',   ok: (this.s3.description || '').length >= 30, step: 3 },
            ];
        },
        get allReady() { return this.checklist.every(i => i.ok); },

        formatZAR(n) { return 'R ' + Number(n || 0).toLocaleString('en-ZA').replace(/,/g, ' '); },

        step1Valid() {
            return this.s1.listing_type
                && this.s1.title.trim()
                && this.s1.property_type
                && this.s1.p24_province_id && this.s1.p24_province_id != 0
                && this.s1.p24_city_id     && this.s1.p24_city_id     != 0
                && this.s1.p24_suburb_id   && this.s1.p24_suburb_id   != 0
                && (this.s1.price > 0);
        },
        canJumpTo(n) { return this.propertyId !== null && n <= Math.max(this.step, this.highestReachedStep || 1); },

        async submitStep1() {
            this.loading = true; this.error = null;
            try {
                const body = new FormData();
                Object.entries(this.s1).forEach(([k, v]) => body.append(k, v ?? ''));
                body.append('_token', this.csrf);
                const r = await fetch(this.routes.draft, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
                const j = await r.json();
                if (!r.ok) throw new Error(j.message || 'Could not save. Check required fields.');
                this.propertyId = j.property.id;
                this.step = 2;
            } catch (e) { this.error = e.message; }
            finally { this.loading = false; }
        },

        resumeDraft() {
            // Existing draft data is not preloaded into s1/s3 here because users typically want a fresh start;
            // the top banner lets them continue an existing draft's show page directly. For simplicity we flag
            // that the banner has been addressed and let them work on the current in-flight draft.
            this.resumedDraft = true;
        },

        async handleDrop(ev) { this.dragOver = false; await this.handleFiles(ev.dataTransfer.files); },
        async handleFiles(files) {
            if (!this.propertyId || !files || !files.length) return;
            const images = Array.from(files).filter(f => f.type.startsWith('image/') && f.size <= 5 * 1024 * 1024);
            if (!images.length) return;

            this.uploading = true;
            this.uploadTotal = images.length;
            this.uploadedCount = 0;

            // PHP caps a single POST at max_file_uploads (default 20) and
            // silently drops the overflow. Upload in batches so every photo
            // lands no matter how many were selected, and so the counter
            // advances live as each batch completes.
            const BATCH = 10;
            try {
                const url = `${this.routes.photos}/${this.propertyId}/photos`;
                for (let i = 0; i < images.length; i += BATCH) {
                    const chunk = images.slice(i, i + BATCH);
                    const body = new FormData();
                    body.append('_token', this.csrf);
                    chunk.forEach(f => body.append('gallery_images[]', f));
                    const r = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
                    const j = await r.json();
                    if (r.ok && j.urls) {
                        this.photos = this.photos.concat(j.urls);
                        this.uploadedCount += chunk.length;
                    }
                }
            } catch (e) { /* silent */ }
            finally { this.uploading = false; }
        },

        reorderPhoto(targetIdx) {
            if (this.dragIdx === null || this.dragIdx === targetIdx) return;
            const [moved] = this.photos.splice(this.dragIdx, 1);
            this.photos.splice(targetIdx, 0, moved);
            this.persistOrder();
            this.dragIdx = null;
        },
        makeCover(idx) {
            const [m] = this.photos.splice(idx, 1);
            this.photos.unshift(m);
            this.persistOrder();
        },
        async removePhoto(idx) {
            if (!this.propertyId) return;
            const url = `${this.routes.photos}/${this.propertyId}/photos/remove`;
            const body = new FormData(); body.append('_token', this.csrf); body.append('index', idx);
            await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
            this.photos.splice(idx, 1);
        },
        async persistOrder() {
            if (!this.propertyId) return;
            const url = `${this.routes.photos}/${this.propertyId}/photos/reorder`;
            const body = new FormData(); body.append('_token', this.csrf);
            this.photos.forEach((_, i) => body.append('order[]', i));
            await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
        },

        async submitStep3() {
            if (!this.propertyId) return;
            this.loading = true;
            try {
                const url = `${this.routes.photos}/${this.propertyId}/step`;
                const body = new FormData();
                body.append('_token', this.csrf);
                Object.entries(this.s3).forEach(([k, v]) => {
                    if (v !== null && v !== '') body.append(k, v);
                });
                const r = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body });
                if (r.ok) this.step = 4;
            } finally { this.loading = false; }
        },

        goToStep(n) { if (this.canJumpTo(n)) this.step = n; },

        async finalize(publish) {
            if (!this.propertyId) return;
            this.loading = true;
            const url = `${this.routes.photos}/${this.propertyId}/finalize`;
            const body = new FormData();
            body.append('_token', this.csrf);
            body.append('publish', publish ? '1' : '0');
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = url;
            const addInput = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
            addInput('_token', this.csrf);
            addInput('publish', publish ? '1' : '0');
            document.body.appendChild(f);
            f.submit();
        },
    };
}
</script>
@endsection
