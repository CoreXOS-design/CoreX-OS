@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-3xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:var(--brand-primary, #0b2a4a);">
        <h2 class="text-xl font-bold text-white">{{ $property ? 'Edit Property' : 'New Property Listing' }}</h2>
        <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
            {{ $property ? "Editing: {$property->title}" : 'Add a new listing to Nexus.' }}
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $property ? route('nexus.properties.update', $property) : route('nexus.properties.store') }}"
          class="space-y-5">
        @csrf
        @if($property)
            @method('PUT')
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">

            {{-- Title --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">
                    Title <span class="text-red-500">*</span>
                </label>
                <input type="text" name="title" value="{{ old('title', $property?->title) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                       placeholder="e.g. 4 Bed House in Uvongo" required>
            </div>

            {{-- Description --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Description</label>
                <textarea name="description" rows="4"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                          placeholder="Property description shown on the website...">{{ old('description', $property?->description) }}</textarea>
            </div>

            {{-- Price --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">
                    Asking Price (ZAR) <span class="text-red-500">*</span>
                </label>
                <input type="number" name="price" value="{{ old('price', $property?->price) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                       placeholder="e.g. 2500000" min="0" required>
                <p class="text-xs text-slate-400 mt-1">Enter as a full number without spaces or commas, e.g. 2500000</p>
            </div>

            {{-- Location --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">
                        Suburb <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="suburb" value="{{ old('suburb', $property?->suburb) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. Uvongo" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Region</label>
                    <input type="text" name="region" value="{{ old('region', $property?->region) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. KZN South Coast">
                </div>
            </div>

            {{-- Property attributes --}}
            <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Beds *</label>
                    <input type="number" name="beds" value="{{ old('beds', $property?->beds ?? 0) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none text-center"
                           min="0" max="20" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Baths *</label>
                    <input type="number" name="baths" value="{{ old('baths', $property?->baths ?? 0) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none text-center"
                           min="0" max="20" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Garages *</label>
                    <input type="number" name="garages" value="{{ old('garages', $property?->garages ?? 0) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none text-center"
                           min="0" max="20" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Size m²</label>
                    <input type="number" name="size_m2" value="{{ old('size_m2', $property?->size_m2) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none text-center"
                           min="0" placeholder="—">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-slate-600">Erf m²</label>
                    <input type="number" name="erf_size_m2" value="{{ old('erf_size_m2', $property?->erf_size_m2) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none text-center"
                           min="0" placeholder="—">
                </div>
            </div>

            {{-- Type + Mandate --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">
                        Property Type <span class="text-red-500">*</span>
                    </label>
                    <select name="property_type"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white" required>
                        @foreach(['house','flat','townhouse','sectional_title','smallholding','farm','commercial','vacant_land','other'] as $type)
                            <option value="{{ $type }}" {{ old('property_type', $property?->property_type) === $type ? 'selected' : '' }}>
                                {{ ucwords(str_replace('_', ' ', $type)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Mandate Type</label>
                    <select name="mandate_type"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                        <option value="">— Select —</option>
                        @foreach(['sole','joint','open'] as $mt)
                            <option value="{{ $mt }}" {{ old('mandate_type', $property?->mandate_type) === $mt ? 'selected' : '' }}>
                                {{ ucfirst($mt) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Branch --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Branch</label>
                <select name="branch_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                    <option value="">— Select branch —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (int) old('branch_id', $property?->branch_id) === $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Status --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Status</label>
                <select name="status"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none bg-white">
                    @foreach(['draft','active','sold','withdrawn'] as $s)
                        <option value="{{ $s }}" {{ old('status', $property?->status ?? 'draft') === $s ? 'selected' : '' }}>
                            {{ ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
            </div>

        </div>

        {{-- Publish toggle --}}
        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-4">
            <div class="flex items-start gap-3">
                <input type="hidden" name="publish" value="0">
                <input type="checkbox" name="publish" value="1" id="publish_toggle"
                       {{ old('publish', !$property || $property->isPublished() ? '1' : '0') === '1' ? 'checked' : '' }}
                       class="w-4 h-4 mt-0.5 rounded border-slate-300 cursor-pointer"
                       style="accent-color:var(--brand-secondary, #00b4d8);">
                <div>
                    <label for="publish_toggle" class="text-sm font-semibold cursor-pointer" style="color:var(--brand-primary, #0b2a4a);">
                        Publish to website
                    </label>
                    <p class="text-xs text-slate-400 mt-0.5">
                        When checked, this listing will be pushed to the public website via the sync queue.
                        @if($property && $property->isPublished())
                            <span class="text-green-600 font-medium">Already published {{ $property->published_at?->diffForHumans() }}.</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-primary, #0b2a4a);"
                    onmouseover="this.style.background='#0a2340'" onmouseout="this.style.background='var(--brand-primary, #0b2a4a)'">
                {{ $property ? 'Update Listing' : 'Create Listing' }}
            </button>
            <a href="{{ route('nexus.properties.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 border border-slate-300 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
            @if($property)
            <form method="POST" action="{{ route('nexus.properties.destroy', $property) }}" class="ml-auto"
                  onsubmit="return confirm('Delete this listing? This cannot be undone.')">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        style="color:#991b1b;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                    Delete
                </button>
            </form>
            @endif
        </div>
    </form>

</div>
@endsection
