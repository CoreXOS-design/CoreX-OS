{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ route('commercial-evaluations.show', $evaluation) }}" class="inline-flex items-center gap-1 text-sm transition-colors" style="color: var(--text-secondary);">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold truncate" style="color: var(--text-primary);">Edit: {{ $evaluation->property_name }}</h2>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Branded header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 mb-6" style="background: var(--brand-default, #0b2a4a);">
        <h2 class="text-xl font-bold text-white leading-tight">Edit Evaluation</h2>
        <p class="text-sm text-white/60 mt-0.5">{{ $evaluation->property_name }}</p>
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        {{-- AT-165 offline draft persistence — no sensitive fields (property valuations only). --}}
        <form method="POST" action="{{ route('commercial-evaluations.update', $evaluation) }}"
              data-draft='@json(["form" => "commercial_eval", "recordId" => $evaluation->id, "version" => $evaluation->updated_at?->toIso8601String()])'
              data-draft-fields="property_type,property_name,address,suburb,town,province,erf_number,zoning,total_land_size_m2,total_land_size_ha,total_building_size_m2,year_built,condition,asking_price,municipal_evaluation,seller_name,notes,branch_id">
            @csrf
            @method('PATCH')

            <div class="px-5 py-4 space-y-5">

                {{-- Property Type --}}
                <div>
                    <label class="ds-label block mb-1">Property Type <span class="text-red-500">*</span></label>
                    <select name="property_type" required
                            class="w-full ds-field rounded-md px-3 py-2 text-sm">
                        <option value="commercial" {{ old('property_type', $evaluation->property_type) === 'commercial' ? 'selected' : '' }}>Commercial Retail/Office</option>
                        <option value="industrial" {{ old('property_type', $evaluation->property_type) === 'industrial' ? 'selected' : '' }}>Industrial/Warehouse</option>
                        <option value="hospitality" {{ old('property_type', $evaluation->property_type) === 'hospitality' ? 'selected' : '' }}>B&B / Guest House / Lodge</option>
                        <option value="agricultural" {{ old('property_type', $evaluation->property_type) === 'agricultural' ? 'selected' : '' }}>Farm / Smallholding</option>
                    </select>
                </div>

                {{-- Property Name --}}
                <div>
                    <label class="ds-label block mb-1">Property Name <span class="text-red-500">*</span></label>
                    <input type="text" name="property_name" value="{{ old('property_name', $evaluation->property_name) }}" required
                           class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    @error('property_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Address --}}
                <div>
                    <label class="ds-label block mb-1">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full ds-field rounded-md px-3 py-2 text-sm">{{ old('address', $evaluation->address) }}</textarea>
                </div>

                {{-- Location row --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Suburb</label>
                        <input type="text" name="suburb" value="{{ old('suburb', $evaluation->suburb) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Town</label>
                        <input type="text" name="town" value="{{ old('town', $evaluation->town) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Province</label>
                        <input type="text" name="province" value="{{ old('province', $evaluation->province) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                {{-- Erf & Zoning --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Erf Number</label>
                        <input type="text" name="erf_number" value="{{ old('erf_number', $evaluation->erf_number) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Zoning</label>
                        <input type="text" name="zoning" value="{{ old('zoning', $evaluation->zoning) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                {{-- Size fields --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Total Land Size (m&sup2;)</label>
                        <input type="number" step="0.01" name="total_land_size_m2" value="{{ old('total_land_size_m2', $evaluation->total_land_size_m2) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Total Land Size (ha)</label>
                        <input type="number" step="0.0001" name="total_land_size_ha" value="{{ old('total_land_size_ha', $evaluation->total_land_size_ha) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Total Building Size (m&sup2;)</label>
                        <input type="number" step="0.01" name="total_building_size_m2" value="{{ old('total_building_size_m2', $evaluation->total_building_size_m2) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                {{-- Year & Condition --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Year Built</label>
                        <input type="number" name="year_built" value="{{ old('year_built', $evaluation->year_built) }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm"
                               min="1800" max="2100">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Condition</label>
                        <select name="condition"
                                class="w-full ds-field rounded-md px-3 py-2 text-sm">
                            <option value="">— Select —</option>
                            @foreach(['excellent', 'good', 'fair', 'poor'] as $cond)
                                <option value="{{ $cond }}" {{ old('condition', $evaluation->condition) === $cond ? 'selected' : '' }}>{{ ucfirst($cond) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Financial --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Asking Price (ZAR)</label>
                        <input type="number" step="0.01" name="asking_price" value="{{ old('asking_price', $evaluation->asking_price ? $evaluation->asking_price / 100 : '') }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Municipal Evaluation (ZAR)</label>
                        <input type="number" step="0.01" name="municipal_evaluation" value="{{ old('municipal_evaluation', $evaluation->municipal_evaluation ? $evaluation->municipal_evaluation / 100 : '') }}"
                               class="w-full ds-field rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                {{-- Seller & Notes --}}
                <div>
                    <label class="ds-label block mb-1">Seller Name</label>
                    <input type="text" name="seller_name" value="{{ old('seller_name', $evaluation->seller_name) }}"
                           class="w-full ds-field rounded-md px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Notes</label>
                    <textarea name="notes" rows="3"
                              class="w-full ds-field rounded-md px-3 py-2 text-sm">{{ old('notes', $evaluation->notes) }}</textarea>
                </div>

                @if($isAdmin)
                <div>
                    <label class="ds-label block mb-1">Branch</label>
                    <select name="branch_id"
                            class="w-full ds-field rounded-md px-3 py-2 text-sm">
                        <option value="">— Auto-assign —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id', $evaluation->branch_id) == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
            </div>

            <div class="px-5 py-4 flex items-center gap-3" style="border-top: 1px solid var(--border);">
                <button type="submit" class="corex-btn-primary">
                    Save Changes &rarr;
                </button>
                <a href="{{ route('commercial-evaluations.show', $evaluation) }}" class="px-4 py-2 text-sm transition-colors" style="color: var(--text-secondary);">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
