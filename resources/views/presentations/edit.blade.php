@extends('layouts.nexus')

@section('nexus-content')

<div class="max-w-3xl mx-auto">

    {{-- Navy header bar --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Edit Presentation Details</h2>
                <div class="text-sm text-white/60">Update property details for: {{ $presentation->title }}</div>
            </div>
            <a href="{{ route('presentations.show', $presentation) }}"
               class="nexus-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                &larr; Back to Presentation
            </a>
        </div>
    </div>

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
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
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
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
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
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. Uvongo">
                    @error('suburb')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Property Type <span class="text-red-500">*</span>
                    </label>
                    <select name="property_type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
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
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. 3">
                    @error('bedrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Bathrooms
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="bathrooms" value="{{ old('bathrooms', $presentation->bathrooms) }}"
                           min="0" max="20"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
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
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 text-sm pointer-events-none">R</span>
                    <input type="number" name="asking_price_inc" value="{{ old('asking_price_inc', $presentation->asking_price_inc) }}" required
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded-lg pl-8 pr-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. 2500000">
                </div>
                <p class="mt-0.5 text-xs text-gray-400">Whole rands, no decimals. e.g. 2500000 for R 2,500,000</p>
                @error('asking_price_inc')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Erf Size + Floor Area + Garages --}}
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="ds-label block mb-1">
                        Erf Size m&sup2;
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="erf_size_m2" value="{{ old('erf_size_m2', $presentation->erf_size_m2) }}"
                           min="0"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. 1523">
                    @error('erf_size_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Floor Area m&sup2;
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="floor_area_m2" value="{{ old('floor_area_m2', $presentation->floor_area_m2) }}"
                           min="0"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. 180">
                    @error('floor_area_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Garages / Parking
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="garages_parking" value="{{ old('garages_parking', $presentation->garages_parking) }}"
                           min="0" max="10"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. 2">
                    @error('garages_parking')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Seller name --}}
            <div>
                <label class="ds-label block mb-1">Seller Name
                    <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input type="text" name="seller_name" value="{{ old('seller_name', $presentation->seller_name) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
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
                    <select name="branch_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
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
            <button type="submit" class="nexus-btn-primary">
                Save Changes
            </button>
            <a href="{{ route('presentations.show', $presentation) }}"
               class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                Cancel
            </a>
        </div>
    </form>
    </div>

</div>

@endsection
