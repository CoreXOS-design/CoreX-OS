@extends('layouts.nexus')

@section('nexus-content')

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">New Presentation</h1>
        <p class="text-sm text-gray-500 mt-1">Enter the property details — you'll run the analysis on the next screen.</p>
    </div>
    <a href="{{ route('presentations.index') }}"
       class="text-xs text-indigo-600 hover:underline">← Back to Presentations</a>
</div>

<div class="bg-white rounded-xl shadow p-6 max-w-2xl">
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
                       placeholder="e.g. 12 Ocean Drive — Market Analysis">
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
                       placeholder="e.g. 12 Ocean Drive, Ballito, 4399">
                @error('property_address')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Suburb + property type (used to pre-fill analysis) --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Suburb <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="suburb" value="{{ old('suburb') }}" required
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. Ballito">
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
                        @foreach(['house' => 'House', 'unit' => 'Unit / Apartment', 'land' => 'Vacant Land', 'other' => 'Other'] as $val => $label)
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

            {{-- Bedrooms + floor area (optional — unlocks recommendation) --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Bedrooms
                        <span class="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" name="bedrooms" value="{{ old('bedrooms') }}"
                           min="0" max="20"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 3">
                    @error('bedrooms')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                        Floor Area (m²)
                        <span class="text-gray-400 font-normal">(optional — unlocks price/m² signal)</span>
                    </label>
                    <input type="number" name="floor_area_m2" value="{{ old('floor_area_m2') }}"
                           min="0"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="e.g. 180">
                    @error('floor_area_m2')
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
                Create &amp; Run Analysis →
            </button>
            <a href="{{ route('presentations.index') }}"
               class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                Cancel
            </a>
        </div>
    </form>
</div>

@endsection
