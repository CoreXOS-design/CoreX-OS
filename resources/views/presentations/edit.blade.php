@extends('layouts.corex')

@section('corex-content')

{{-- Page header (AT-336 — flat neutral bar, full-bleed above the centred form) --}}
<div class="rounded-md px-6 py-4 mb-6 corex-page-banner">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h2 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Presentation Details</h2>
            <div class="text-xs" style="color: var(--text-muted);">Update property details for: {{ $presentation->title }}</div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('presentations.show', $presentation) }}"
               class="corex-btn-outline text-xs">
                &larr; Back to Presentation
            </a>
        </div>
    </div>
</div>

<div class="max-w-3xl mx-auto">

    {{-- Form card --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
    <form method="POST" action="{{ route('presentations.update', $presentation) }}">
        @csrf
        @method('PATCH')

        <div class="space-y-5">

            {{-- Presentation title --}}
            <div>
                <label class="ds-label block mb-1">
                    Presentation Title <span class="text-red-500">*</span>
                </label>
                <input type="text" name="title" value="{{ old('title', $presentation->title) }}" required
                       class="pres-input w-full px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road — Seller Presentation">
                @error('title')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Property address --}}
            <div>
                <label class="ds-label block mb-1">
                    Property Address <span class="text-red-500">*</span>
                </label>
                <input type="text" name="property_address" value="{{ old('property_address', $presentation->property_address) }}" required
                       class="pres-input w-full px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road">
                @error('property_address')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Suburb + Property Type --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="ds-label block mb-1">
                        Suburb <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="suburb" value="{{ old('suburb', $presentation->suburb) }}" required
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. Uvongo">
                    @error('suburb')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Property Type <span class="text-red-500">*</span>
                    </label>
                    <select name="property_type" required class="pres-select w-full px-3 py-2 text-sm">
                        <option value="">— Select type —</option>
                        @foreach([
                            'house'       => 'House',
                            'townhouse'   => 'Townhouse',
                            'apartment'   => 'Apartment / Flat',
                            'duplex'      => 'Duplex',
                            'vacant_land' => 'Vacant Land',
                            'farm'        => 'Farm',
                            'other'       => 'Other',
                        ] as $val => $label)
                            <option value="{{ $val }}" {{ old('property_type', $presentation->property_type) === $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('property_type')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Bedrooms + Bathrooms --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="ds-label block mb-1">
                        Bedrooms <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="bedrooms" value="{{ old('bedrooms', $presentation->bedrooms) }}" required
                           min="0" max="20"
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. 3">
                    @error('bedrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Bathrooms
                        <span class="font-normal" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="bathrooms" value="{{ old('bathrooms', $presentation->bathrooms) }}"
                           min="0" max="20"
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. 2">
                    @error('bathrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Asking Price --}}
            <div>
                <label class="ds-label block mb-1">
                    Asking Price (ZAR) <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-sm pointer-events-none" style="color: var(--text-muted);">R</span>
                    <input type="number" name="asking_price_inc" value="{{ old('asking_price_inc', $presentation->asking_price_inc) }}" required
                           min="0" step="1"
                           class="pres-input w-full pl-8 pr-3 py-2 text-sm"
                           placeholder="e.g. 2500000">
                </div>
                <p class="mt-0.5 text-xs" style="color: var(--text-muted);">Whole rands, no decimals. e.g. 2500000 for R 2,500,000</p>
                @error('asking_price_inc')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Erf Size + Floor Area + Garages --}}
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="ds-label block mb-1">
                        Erf Size m&sup2;
                        <span class="font-normal" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="erf_size_m2" value="{{ old('erf_size_m2', $presentation->erf_size_m2) }}"
                           min="0"
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. 1523">
                    @error('erf_size_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Floor Area m&sup2;
                        <span class="font-normal" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="floor_area_m2" value="{{ old('floor_area_m2', $presentation->floor_area_m2) }}"
                           min="0"
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. 180">
                    @error('floor_area_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Garages / Parking
                        <span class="font-normal" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="garages_parking" value="{{ old('garages_parking', $presentation->garages_parking) }}"
                           min="0" max="10"
                           class="pres-input w-full px-3 py-2 text-sm"
                           placeholder="e.g. 2">
                    @error('garages_parking')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Seller name --}}
            <div>
                <label class="ds-label block mb-1">Seller Name
                    <span class="font-normal" style="color: var(--text-muted);">(optional)</span>
                </label>
                <input type="text" name="seller_name" value="{{ old('seller_name', $presentation->seller_name) }}"
                       class="pres-input w-full px-3 py-2 text-sm"
                       placeholder="e.g. John Smith">
                @error('seller_name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Branch selector — admin only --}}
            @if($isAdmin)
            <div>
                <label class="ds-label block mb-1">
                    Branch <span class="text-red-500">*</span>
                </label>
                @if($branches->isEmpty())
                    <p class="text-xs text-red-600">No branches configured. Contact an admin.</p>
                @else
                    <select name="branch_id" required class="pres-select w-full px-3 py-2 text-sm">
                        <option value="">— Select branch —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id', $presentation->branch_id) == $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                @endif
                @error('branch_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @endif

        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">
                Save Changes
            </button>
            <a href="{{ route('presentations.show', $presentation) }}"
               class="px-4 py-2 text-sm transition-all duration-300" style="color: var(--text-secondary);">
                Cancel
            </a>
        </div>
    </form>
    </div>

</div>

@endsection
