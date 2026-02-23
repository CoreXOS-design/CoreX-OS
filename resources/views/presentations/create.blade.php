@extends('layouts.nexus')

@section('nexus-content')

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">New Presentation</h1>
        <p class="text-sm text-gray-500 mt-1">Enter the property details — you'll upload evidence and run analysis on the next screen.</p>
    </div>
    <a href="{{ route('presentations.index') }}"
       class="text-xs text-indigo-600 hover:underline">&larr; Back to Presentations</a>
</div>

<div class="bg-white rounded-xl shadow p-6 max-w-3xl">
    <form method="POST" action="{{ route('presentations.store') }}">
        @csrf

        <div class="space-y-5">

            {{-- Presentation title --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                    Presentation Title <span class="text-red-500">*</span>
                </label>
                <input type="text" name="title" value="{{ old('title') }}" required
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road — Seller Presentation">
                @error('title')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Property address --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                    Property Address <span class="text-red-500">*</span>
                </label>
                <input type="text" name="property_address" value="{{ old('property_address') }}" required
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road">
                @error('property_address')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Suburb + Property Type --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Suburb <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="suburb" value="{{ old('suburb') }}" required
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. Uvongo">
                    @error('suburb')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Property Type <span class="text-red-500">*</span>
                    </label>
                    <select name="property_type" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
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
                            <option value="{{ $val }}" {{ old('property_type') === $val ? 'selected' : '' }}>
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
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Bedrooms <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="bedrooms" value="{{ old('bedrooms') }}" required
                           min="0" max="20"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 3">
                    @error('bedrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Bathrooms
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="bathrooms" value="{{ old('bathrooms') }}"
                           min="0" max="20"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 2">
                    @error('bathrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Asking Price --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                    Asking Price (ZAR) <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 text-sm pointer-events-none">R</span>
                    <input type="number" name="asking_price_inc" value="{{ old('asking_price_inc') }}" required
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded pl-8 pr-3 py-2 text-sm"
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
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Erf Size m&sup2;
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="erf_size_m2" value="{{ old('erf_size_m2') }}"
                           min="0"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 1523">
                    @error('erf_size_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Floor Area m&sup2;
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="floor_area_m2" value="{{ old('floor_area_m2') }}"
                           min="0"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 180">
                    @error('floor_area_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Garages / Parking
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="garages_parking" value="{{ old('garages_parking') }}"
                           min="0" max="10"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 2">
                    @error('garages_parking')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Seller name --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Seller Name
                    <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input type="text" name="seller_name" value="{{ old('seller_name') }}"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. John Smith">
                @error('seller_name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Branch selector — admin only --}}
            @if($isAdmin)
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                    Branch <span class="text-red-500">*</span>
                </label>
                @if($branches->isEmpty())
                    <p class="text-xs text-red-600">No branches configured. Contact an admin.</p>
                @else
                    <select name="branch_id" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                        <option value="">— Select branch —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>
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
            <button type="submit"
                    class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
                Create Presentation &rarr;
            </button>
            <a href="{{ route('presentations.index') }}"
               class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                Cancel
            </a>
        </div>
    </form>
</div>

@endsection
