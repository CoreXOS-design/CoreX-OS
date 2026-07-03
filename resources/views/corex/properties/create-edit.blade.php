@extends('layouts.corex')

@push('head')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
@endpush

@section('corex-content')
<div class="max-w-3xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:var(--brand-default, #0b2a4a);">
        <h2 class="text-xl font-bold text-white">{{ $property ? 'Edit Property' : 'New Property Listing' }}</h2>
        <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
            {{ $property ? "Editing: {$property->title}" : 'Add a new listing to Nexus.' }}
        </div>
    </div>

    @if($property && $property->p24_suburb_mismatch)
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;">
            <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div>
                    <div class="font-semibold">Property24 doesn't recognise this suburb</div>
                    <div class="text-xs mt-0.5">The current suburb "<span class="font-medium">{{ $property->suburb }}</span>" isn't in Property24's list. Pick a Property24-recognised suburb below to enable publishing to P24. You can't save edits until this is fixed.</div>
                </div>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- AT-165 offline draft persistence: declarative opt-in. Allowlist is POPIA-safe
         (public-ish listing data only — no ID/bank/tax fields exist on this form).
         gallery_images[] (file input) and publish buttons are intentionally excluded. --}}
    <form method="POST"
          action="{{ $property ? route('corex.properties.update', $property) : route('corex.properties.store') }}"
          enctype="multipart/form-data"
          class="space-y-5"
          id="property-form"
          novalidate
          data-draft='@json([
              "form"      => "property_capture",
              "recordId"  => $property?->id,
              "version"   => $property?->updated_at?->toIso8601String(),
              "autosaveMs"=> 1500,
              "ttlDays"   => 7,
              "storage"   => "auto",
          ])'
          data-draft-fields="listing_type,title,agent_id,price,property_type,street_number,street_name,address,latitude,longitude,excerpt,description,region,mandate_type,branch_id,status,property_number,complex_name,unit_number,district,rental_amount,deposit_amount,lease_start_date,lease_end_date,suburb,city,province">
        @csrf
        @if($property) @method('PUT') @endif

        {{-- ── Listing Type (only on create or extension import, hidden after first save) ── --}}
        @if(!$property || !$property->exists)
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-bold uppercase tracking-wider mb-4" style="color:var(--brand-default,#0b2a4a);">Listing Type</h3>
            <div class="flex gap-3" x-data="{ type: '{{ old('listing_type', 'sale') }}' }">
                <input type="hidden" name="listing_type" :value="type">
                <button type="button" @click="type = 'sale'"
                        :class="type === 'sale' ? 'ring-2 ring-blue-500 bg-blue-50 border-blue-400' : 'border-slate-300 hover:border-slate-400'"
                        class="flex-1 flex flex-col items-center gap-2 rounded-xl border px-4 py-4 transition-all cursor-pointer">
                    <svg class="w-6 h-6" :class="type === 'sale' ? 'text-blue-600' : 'text-slate-400'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                    <span class="text-sm font-semibold" :class="type === 'sale' ? 'text-blue-700' : 'text-slate-600'">For Sale</span>
                </button>
                <button type="button" @click="type = 'rental'"
                        :class="type === 'rental' ? 'ring-2 ring-blue-500 bg-blue-50 border-blue-400' : 'border-slate-300 hover:border-slate-400'"
                        class="flex-1 flex flex-col items-center gap-2 rounded-xl border px-4 py-4 transition-all cursor-pointer">
                    <svg class="w-6 h-6" :class="type === 'rental' ? 'text-blue-600' : 'text-slate-400'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                    <span class="text-sm font-semibold" :class="type === 'rental' ? 'text-blue-700' : 'text-slate-600'">For Rental</span>
                </button>
            </div>
        </div>
        @endif

        {{-- ── Core Details ──────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Listing Details</h3>

            {{-- Title --}}
            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Title <span class="text-red-500">*</span></label>
                <input type="text" name="title" value="{{ old('title', $property?->title) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                       placeholder="e.g. Stunning 4 Bed House in Uvongo" required>
            </div>

            {{-- Agent --}}
            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Agent <span class="text-red-500">*</span></label>
                <select name="agent_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white" required>
                    <option value="">— Select an Agent —</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}"
                            {{ (int) old('agent_id', $property?->agent_id ?? auth()->id()) === $agent->id ? 'selected' : '' }}>
                            {{ $agent->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Price --}}
            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Price (ZAR) <span class="text-red-500">*</span></label>
                <input type="number" name="price" value="{{ old('price', $property?->price) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                       placeholder="e.g. 2500000" min="0" required>
            </div>

            {{-- Type --}}
            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Type <span class="text-red-500">*</span></label>
                <select name="property_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white" required>
                    @foreach(['house','flat','townhouse','sectional_title','smallholding','farm','commercial','vacant_land','other'] as $type)
                        <option value="{{ $type }}" {{ old('property_type', $property?->property_type ?? 'house') === $type ? 'selected' : '' }}>
                            {{ ucwords(str_replace('_', ' ', $type)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- ── Province / City / Suburb (Property24-backed typeahead) ──
                 Type to filter; can only submit if all three are picked
                 from the P24 list. Shared partial: see
                 _partials/p24-location-picker.blade.php. --}}
            @include('corex._partials.p24-location-picker', [
                'fieldPrefix'         => 'p24',
                'initialProvinceId'   => old('p24_province_id', $property?->p24_province_id ?? 0),
                'initialCityId'       => old('p24_city_id',     $property?->p24_city_id ?? 0),
                'initialSuburbId'     => old('p24_suburb_id',   $property?->p24_suburb_id ?? 0),
                'initialProvinceName' => old('province', $property?->province ?? ''),
                'initialCityName'     => old('city',     $property?->city ?? ''),
                'initialSuburbName'   => old('suburb',   $property?->suburb ?? ''),
                'denormaliseNames'    => true,
            ])

            {{-- ── Address + Map ── --}}
            <div class="space-y-3" x-data="propertyMap({
                    initialLat: {{ (float) old('latitude',  $property?->latitude ?? 0) }},
                    initialLng: {{ (float) old('longitude', $property?->longitude ?? 0) }},
                })">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Address</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1 text-slate-600">Street Number</label>
                        <input type="text" name="street_number" x-model="streetNumber" @input.debounce.700ms="geocode()"
                               value="{{ old('street_number', $property?->street_number) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. 12">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold mb-1 text-slate-600">Street Name</label>
                        <input type="text" name="street_name" x-model="streetName" @input.debounce.700ms="geocode()"
                               value="{{ old('street_name', $property?->street_name) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. Marine Drive">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Full Address (optional, for documents)</label>
                    <input type="text" name="address" value="{{ old('address', $property?->address) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                           placeholder="auto-filled from street + suburb if blank">
                </div>

                <input type="hidden" name="latitude"  :value="lat">
                <input type="hidden" name="longitude" :value="lng">

                <div class="rounded-lg border border-slate-200 overflow-hidden">
                    <div class="flex items-center justify-between px-3 py-2 bg-slate-50 border-b border-slate-200">
                        <div class="text-xs text-slate-600">
                            <span x-show="lat && lng" x-cloak>
                                Pin: <span class="font-mono" x-text="(+lat).toFixed(5) + ', ' + (+lng).toFixed(5)"></span>
                                <span x-show="geocoding" class="text-slate-400">(locating…)</span>
                            </span>
                            <span x-show="!lat || !lng" class="text-slate-400" x-cloak>
                                Enter the street number and name above — the map will drop a pin automatically.
                            </span>
                        </div>
                        <button type="button" @click="clearPin()" x-show="lat && lng" x-cloak
                                class="text-[11px] text-slate-500 hover:text-slate-700 underline">Clear pin</button>
                    </div>
                    <div id="property-map" class="w-full" style="height: 280px; background:#e5e7eb;"></div>
                </div>
            </div>

            {{-- Beds / Baths / Garages / Size / Erf --}}
            <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                @foreach([['beds','Bedrooms'],['baths','Bathrooms'],['garages','Garages'],['size_m2','Floor m²'],['erf_size_m2','Erf m²']] as [$name,$label])
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">{{ $label }}{{ in_array($name,['beds','baths','garages']) ? ' *' : '' }}</label>
                    <input type="number" name="{{ $name }}" value="{{ old($name, $property?->$name ?? ($name === 'beds' || $name === 'baths' || $name === 'garages' ? 0 : '')) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400 text-center"
                           min="0" {{ in_array($name,['beds','baths','garages']) ? 'max=20 required' : 'placeholder=—' }}>
                </div>
                @endforeach
            </div>
        </div>

        {{-- ── Description ──────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Description</h3>

            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Excerpt <span class="text-xs font-normal text-slate-400">(short summary, max 500 chars)</span></label>
                <textarea name="excerpt" rows="2"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                          placeholder="One or two sentences shown in search results...">{{ old('excerpt', $property?->excerpt) }}</textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Full Description</label>
                <textarea name="description" rows="6"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                          placeholder="Full property description shown on the listing page...">{{ old('description', $property?->description) }}</textarea>
            </div>
        </div>

        {{-- ── Gallery Images ────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-4">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Image Gallery</h3>
            <p class="text-xs text-slate-400">Max 5 MB per image. After saving you can manage, reorder and delete images from the property's Gallery tab.</p>

            @php $existingGallery = $property ? ($property->gallery_images_json ?? []) : []; @endphp

            @if(count($existingGallery))
            <div class="flex flex-wrap gap-2">
                @foreach($existingGallery as $img)
                    <img src="{{ $img }}" alt="" class="h-20 w-28 object-cover rounded-lg border border-slate-200 shadow-sm">
                @endforeach
            </div>
            @endif

            <div class="flex flex-wrap gap-2 mb-2 min-h-0" id="preview-gallery_images"></div>

            <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-dashed border-slate-300 cursor-pointer hover:border-blue-400 transition-colors text-sm text-slate-500 w-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                </svg>
                <span id="label-gallery_images">Select images (multiple)</span>
                <input type="file" name="gallery_images[]" multiple accept="image/*"
                       class="hidden" data-preview="preview-gallery_images" data-label="label-gallery_images">
            </label>
        </div>

        @push('scripts')
        <script>
        document.querySelectorAll('input[type="file"][data-preview]').forEach(function(input) {
            input.addEventListener('change', function() {
                var previewEl = document.getElementById(this.dataset.preview);
                var labelEl   = document.getElementById(this.dataset.label);
                previewEl.innerHTML = '';

                var files = Array.from(this.files);
                if (!files.length) return;

                labelEl.textContent = files.length + ' file' + (files.length > 1 ? 's' : '') + ' selected';

                files.forEach(function(file) {
                    if (!file.type.startsWith('image/')) return;
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var wrap = document.createElement('div');
                        wrap.className = 'relative';

                        var img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'h-20 w-28 object-cover rounded-lg border-2 border-blue-300 shadow-sm';
                        img.title = file.name;

                        var badge = document.createElement('span');
                        badge.className = 'absolute bottom-0 left-0 right-0 text-center text-[9px] bg-black/50 text-white rounded-b-lg px-1 py-0.5 truncate';
                        badge.textContent = file.name;

                        wrap.appendChild(img);
                        wrap.appendChild(badge);
                        previewEl.appendChild(wrap);
                    };
                    reader.readAsDataURL(file);
                });
            });
        });
        </script>
        @endpush

        {{-- ── Meta ─────────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Additional Info</h3>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1 text-slate-700">Region</label>
                    <input type="text" name="region" value="{{ old('region', $property?->region) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                           placeholder="KZN South Coast">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1 text-slate-700">Mandate Type</label>
                    <select name="mandate_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                        <option value="">— Select —</option>
                        @foreach(['sole','joint','open'] as $mt)
                            <option value="{{ $mt }}" {{ old('mandate_type', $property?->mandate_type) === $mt ? 'selected' : '' }}>
                                {{ ucfirst($mt) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1 text-slate-700">Branch</label>
                    <select name="branch_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                        <option value="">— Select —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('branch_id', $property?->branch_id) === $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1 text-slate-700">Status</label>
                <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                    @foreach(['draft','active','sold','withdrawn'] as $s)
                        <option value="{{ $s }}" {{ old('status', $property?->status ?? 'draft') === $s ? 'selected' : '' }}>
                            {{ ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- ── Additional Details (collapsible) ──────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left">
                <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Additional Details</h3>
                <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="open" x-cloak class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Property / Erf Number</label>
                        <input type="text" name="property_number" value="{{ old('property_number', $property?->property_number) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. Erf 789">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Complex Name</label>
                        <input type="text" name="complex_name" value="{{ old('complex_name', $property?->complex_name) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. Ocean View">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Unit Number</label>
                        <input type="text" name="unit_number" value="{{ old('unit_number', $property?->unit_number) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. 14">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">District / Municipality</label>
                        <input type="text" name="district" value="{{ old('district', $property?->district) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="e.g. Ray Nkonyeni">
                    </div>
                </div>

                {{-- Rental-only fields --}}
                @if(($property?->listing_type ?? old('listing_type', 'sale')) === 'rental')
                <div class="border-t border-slate-200 pt-4 mt-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Rental Details</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1 text-slate-700">Monthly Rental (R)</label>
                            <input type="number" name="rental_amount" value="{{ old('rental_amount', $property?->rental_amount) }}"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                                   placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1 text-slate-700">Deposit (R)</label>
                            <input type="number" name="deposit_amount" value="{{ old('deposit_amount', $property?->deposit_amount) }}"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                                   placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1 text-slate-700">Lease Start Date</label>
                            <input type="date" name="lease_start_date" value="{{ old('lease_start_date', $property?->lease_start_date?->format('Y-m-d')) }}"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1 text-slate-700">Lease End Date</label>
                            <input type="date" name="lease_end_date" value="{{ old('lease_end_date', $property?->lease_end_date?->format('Y-m-d')) }}"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- ── Publish ───────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-4">
            <div class="flex items-start gap-3">
                <input type="hidden" name="publish" value="0">
                {{-- NOT disabled: a disabled checkbox never submits, so the hidden
                     publish=0 would always win and the control couldn't reflect its
                     real state. Keeping it enabled means a published listing re-submits
                     publish=1 on save. Unpublishing is done via the publish-toggle. --}}
                <input type="checkbox" name="publish" value="1" id="publish_toggle"
                       {{ ($property && $property->isPublished()) ? 'checked' : '' }}
                       class="w-4 h-4 mt-0.5 rounded border-slate-300 cursor-pointer"
                       style="accent-color:var(--brand-icon,#0ea5e9);">
                <div>
                    <label for="publish_toggle" class="text-sm font-semibold cursor-pointer text-slate-800">
                        Publish to website
                    </label>
                    <p class="text-xs text-slate-400 mt-0.5">
                        When checked, this listing is pushed live to themandatecompany.co.za immediately.
                        @if($property && $property->isPublished())
                            <span class="text-green-600 font-medium">Published {{ $property->published_at?->diffForHumans() }}. Edit and save to re-sync.</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Actions ──────────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-default,#0b2a4a);"
                    onmouseover="this.style.background='#0a2340'" onmouseout="this.style.background='var(--brand-default,#0b2a4a)'">
                {{ $property ? 'Update Listing' : 'Create Listing' }}
            </button>
            <a href="{{ route('corex.properties.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 border border-slate-300 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>

    </form>

    @if($property)
    <form method="POST" action="{{ route('corex.properties.destroy', $property) }}" class="flex justify-end"
          onsubmit="return confirm('Delete this listing?')">
        @csrf @method('DELETE')
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                style="color:#991b1b;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
            Delete
        </button>
    </form>
    @endif
</div>

{{-- ── Required Fields Modal ───────────────────────────────────────────── --}}
<div id="required-fields-modal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 px-4"
     role="dialog" aria-modal="true" aria-labelledby="required-fields-title">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center" style="background:#fee2e2;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color:#dc2626;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>
            <div class="flex-1">
                <h3 id="required-fields-title" class="text-base font-bold text-slate-800">Missing Required Fields</h3>
                <p class="text-xs text-slate-500 mt-0.5">Please complete the following before creating the property:</p>
            </div>
        </div>
        <div class="px-6 py-4 max-h-64 overflow-y-auto">
            <ul id="required-fields-list" class="list-disc list-inside space-y-1 text-sm text-slate-700"></ul>
        </div>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2">
            <button type="button" id="required-fields-close"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 border border-slate-300 hover:bg-slate-100 transition-colors">
                Close
            </button>
            <button type="button" id="required-fields-goto"
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-default,#0b2a4a);"
                    onmouseover="this.style.background='#0a2340'" onmouseout="this.style.background='var(--brand-default,#0b2a4a)'">
                Take Me There
            </button>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function() {
    var form    = document.getElementById('property-form');
    var modal   = document.getElementById('required-fields-modal');
    var listEl  = document.getElementById('required-fields-list');
    var closeBtn= document.getElementById('required-fields-close');
    var gotoBtn = document.getElementById('required-fields-goto');
    var firstMissingEl = null;

    if (!form || !modal) return;

    function labelFor(field) {
        // Try to find an associated <label> by walking up to the wrapping div
        var wrap = field.closest('div');
        if (wrap) {
            var lbl = wrap.querySelector('label');
            if (lbl) {
                // Strip the asterisk and trim
                return lbl.textContent.replace(/\*/g, '').trim();
            }
        }
        return field.name || 'Required field';
    }

    function openAncestors(el) {
        // Open any collapsible Alpine sections containing the field
        var node = el.parentElement;
        while (node && node !== document.body) {
            if (node.hasAttribute('x-data') && /open\s*:/.test(node.getAttribute('x-data') || '')) {
                if (window.Alpine) {
                    try {
                        var data = Alpine.$data ? Alpine.$data(node) : null;
                        if (data && 'open' in data) data.open = true;
                    } catch (e) {}
                }
            }
            node = node.parentElement;
        }
    }

    function showModal(missing) {
        listEl.innerHTML = '';
        missing.forEach(function(item) {
            var li = document.createElement('li');
            li.textContent = item.label;
            listEl.appendChild(li);
        });
        firstMissingEl = missing.length ? missing[0].el : null;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    form.addEventListener('submit', function(e) {
        var missing = [];
        var fields = form.querySelectorAll('[required]');
        fields.forEach(function(f) {
            var val = (f.value || '').trim();
            if (!val) {
                missing.push({ el: f, label: labelFor(f) });
            }
        });

        // Province / City / Suburb come from the P24 location picker, whose
        // values land in hidden inputs (no [required] attribute), so the sweep
        // above can't see them. Validate them here so a missing suburb pops the
        // same modal as every other required field, rather than failing
        // server-side and surfacing in the error banner after the round-trip.
        [
            { name: 'province', label: 'Province' },
            { name: 'city',     label: 'City / Town' },
            { name: 'suburb',   label: 'Suburb' },
        ].forEach(function(loc) {
            var hidden = form.querySelector('input[type="hidden"][name="' + loc.name + '"]');
            if (!hidden) return;
            if (!(hidden.value || '').trim()) {
                // Focus the visible typeahead, never the hidden input.
                var visible = form.querySelector('[data-loc-field="' + loc.name + '"]') || hidden;
                missing.push({ el: visible, label: loc.label });
            }
        });

        if (missing.length) {
            e.preventDefault();
            showModal(missing);
        }
    });

    closeBtn.addEventListener('click', hideModal);

    gotoBtn.addEventListener('click', function() {
        hideModal();
        if (firstMissingEl) {
            openAncestors(firstMissingEl);
            setTimeout(function() {
                firstMissingEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { firstMissingEl.focus({ preventScroll: true }); } catch (e) { firstMissingEl.focus(); }
            }, 50);
        }
    });

    modal.addEventListener('click', function(e) {
        if (e.target === modal) hideModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) hideModal();
    });
})();
</script>

{{-- Leaflet (OpenStreetMap) — free, no API key. --}}
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
/**
 * Leaflet/OSM map + Nominatim geocoder for the property address.
 * Pin is set automatically when address fields change (debounced) and is
 * draggable for manual fine-tuning. lat/lng are written to hidden inputs.
 */
function propertyMap(init) {
    return {
        lat: init.initialLat || 0,
        lng: init.initialLng || 0,
        streetNumber: '',
        streetName:   '',
        geocoding: false,
        _map: null, _marker: null,

        init() {
            // Hidden input values come from existing model; populate display fields from form inputs.
            const numEl = document.querySelector('input[name="street_number"]');
            const nameEl = document.querySelector('input[name="street_name"]');
            if (numEl)  this.streetNumber = numEl.value || '';
            if (nameEl) this.streetName   = nameEl.value || '';

            this.$nextTick(() => this._renderMap());

            window.addEventListener('p24-location-changed:p24', () => this.geocode());
        },

        _renderMap() {
            const el = document.getElementById('property-map');
            if (!el || !window.L) return;
            const center = (this.lat && this.lng)
                ? [+this.lat, +this.lng]
                : [-30.5, 30.5]; // South coast KZN default
            this._map = L.map(el).setView(center, (this.lat && this.lng) ? 16 : 8);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this._map);

            if (this.lat && this.lng) {
                this._placeMarker(+this.lat, +this.lng);
            }
        },

        _placeMarker(lat, lng) {
            if (!this._map) return;
            if (this._marker) {
                this._marker.setLatLng([lat, lng]);
            } else {
                this._marker = L.marker([lat, lng], { draggable: true }).addTo(this._map);
                this._marker.on('dragend', (e) => {
                    const p = e.target.getLatLng();
                    this.lat = p.lat.toFixed(7);
                    this.lng = p.lng.toFixed(7);
                });
            }
            this._map.setView([lat, lng], 16);
        },

        async geocode() {
            // Build a freeform query from the cascading selects + street fields.
            const suburb = document.querySelector('input[name="suburb"]')?.value || '';
            const city   = document.querySelector('input[name="city"]')?.value || '';
            const province = document.querySelector('input[name="province"]')?.value || '';
            const parts = [
                [this.streetNumber, this.streetName].filter(Boolean).join(' '),
                suburb, city, province, 'South Africa',
            ].filter(Boolean);
            const q = parts.join(', ').trim();
            if (q.length < 6) return;

            this.geocoding = true;
            try {
                const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=za&q=' + encodeURIComponent(q);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const arr = await r.json();
                if (Array.isArray(arr) && arr.length > 0) {
                    const hit = arr[0];
                    this.lat = (+hit.lat).toFixed(7);
                    this.lng = (+hit.lon).toFixed(7);
                    this._placeMarker(+hit.lat, +hit.lon);
                }
            } catch (e) {
                // Silent — geocoding is best-effort; user can drag the pin manually.
            } finally {
                this.geocoding = false;
            }
        },

        clearPin() {
            this.lat = 0; this.lng = 0;
            if (this._marker && this._map) { this._map.removeLayer(this._marker); this._marker = null; }
        },
    };
}
</script>
@endpush
@endsection
