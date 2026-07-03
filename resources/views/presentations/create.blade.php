{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')

<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">New Presentation</h1>
                <p class="text-sm text-white/60">Enter the property details — you'll upload evidence and run analysis on the next screen.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('presentations.index') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    &larr; Back to Presentations
                </a>
            </div>
        </div>
    </div>

    {{-- Form card --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
    {{-- AT-165 offline draft persistence — no sensitive fields on this capture. --}}
    <form method="POST" action="{{ route('presentations.store') }}"
          data-draft='@json(["form" => "presentation_capture", "recordId" => null, "version" => null])'
          data-draft-fields="title,property_address,suburb,property_type,bedrooms,bathrooms,asking_price_inc,erf_size_m2,floor_area_m2,garages_parking,seller_name,branch_id">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-5">

            {{-- Presentation title --}}
            <div class="md:col-span-2">
                <label class="ds-label block mb-1">
                    Presentation Title <span class="text-red-500">*</span>
                </label>
                <input type="text" name="title" value="{{ old('title') }}" required
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road — Seller Presentation">
                @error('title')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Property address --}}
            <div class="md:col-span-2">
                <label class="ds-label block mb-1">
                    Property Address <span class="text-red-500">*</span>
                </label>
                <input type="text" name="property_address" value="{{ old('property_address') }}" required
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
                       placeholder="e.g. 21 Dee Road">
                @error('property_address')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Suburb --}}
            <div>
                <label class="ds-label block mb-1">
                    Suburb <span class="text-red-500">*</span>
                </label>
                <input type="text" name="suburb" value="{{ old('suburb') }}" required
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
                       placeholder="e.g. Uvongo">
                @error('suburb')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Property Type --}}
            <div>
                <label class="ds-label block mb-1">
                    Property Type <span class="text-red-500">*</span>
                </label>
                <select name="property_type" required class="ds-field w-full rounded-md px-3 py-2 text-sm">
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

            {{-- Bedrooms --}}
            <div>
                <label class="ds-label block mb-1">
                    Bedrooms <span class="text-red-500">*</span>
                </label>
                <input type="number" name="bedrooms" value="{{ old('bedrooms') }}" required
                       min="0" max="20"
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
                       placeholder="e.g. 3">
                @error('bedrooms')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Bathrooms --}}
            <div>
                <label class="ds-label block mb-1">
                    Bathrooms
                    <span class="font-normal normal-case" style="color: var(--text-muted);">(optional)</span>
                </label>
                <input type="number" name="bathrooms" value="{{ old('bathrooms') }}"
                       min="0" max="20"
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
                       placeholder="e.g. 2">
                @error('bathrooms')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Asking Price --}}
            <div class="md:col-span-2">
                <label class="ds-label block mb-1">
                    Asking Price (ZAR) <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-sm pointer-events-none" style="color: var(--text-muted);">R</span>
                    <input type="number" name="asking_price_inc" value="{{ old('asking_price_inc') }}" required
                           min="0" step="1"
                           class="ds-field w-full rounded-md pl-8 pr-3 py-2 text-sm"
                           placeholder="e.g. 2500000">
                </div>
                <p class="mt-1 text-xs" style="color: var(--text-muted);">Whole rands, no decimals. e.g. 2500000 for R 2,500,000</p>
                @error('asking_price_inc')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Erf Size + Floor Area + Garages --}}
            <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="ds-label block mb-1">
                        Erf Size m&sup2;
                        <span class="font-normal normal-case" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="erf_size_m2" value="{{ old('erf_size_m2') }}"
                           min="0"
                           class="ds-field w-full rounded-md px-3 py-2 text-sm"
                           placeholder="e.g. 1523">
                    @error('erf_size_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Floor Area m&sup2;
                        <span class="font-normal normal-case" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="floor_area_m2" value="{{ old('floor_area_m2') }}"
                           min="0"
                           class="ds-field w-full rounded-md px-3 py-2 text-sm"
                           placeholder="e.g. 180">
                    @error('floor_area_m2')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="ds-label block mb-1">
                        Garages / Parking
                        <span class="font-normal normal-case" style="color: var(--text-muted);">(optional)</span>
                    </label>
                    <input type="number" name="garages_parking" value="{{ old('garages_parking') }}"
                           min="0" max="10"
                           class="ds-field w-full rounded-md px-3 py-2 text-sm"
                           placeholder="e.g. 2">
                    @error('garages_parking')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Seller name --}}
            <div>
                <label class="ds-label block mb-1">Seller Name
                    <span class="font-normal normal-case" style="color: var(--text-muted);">(optional)</span>
                </label>
                <input type="text" name="seller_name" value="{{ old('seller_name') }}"
                       class="ds-field w-full rounded-md px-3 py-2 text-sm"
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
                    <select name="branch_id" required class="ds-field w-full rounded-md px-3 py-2 text-sm">
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
            <button type="submit" class="corex-btn-primary" data-tour="pres-submit">
                Create Presentation &rarr;
            </button>
            <a href="{{ route('presentations.index') }}" class="corex-btn-outline">
                Cancel
            </a>
        </div>
    </form>
    </div>

</div>

@endsection
