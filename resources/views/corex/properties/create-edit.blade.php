@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:var(--brand-default, #0b2a4a);">
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
          action="{{ $property ? route('corex.properties.update', $property) : route('corex.properties.store') }}"
          enctype="multipart/form-data"
          class="space-y-5">
        @csrf
        @if($property) @method('PUT') @endif

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

            {{-- City + Suburb --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1 text-slate-700">City</label>
                    <input type="text" name="city" value="{{ old('city', $property?->city) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                           placeholder="e.g. Margate">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1 text-slate-700">Suburb <span class="text-red-500">*</span></label>
                    <input type="text" name="suburb" value="{{ old('suburb', $property?->suburb) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                           placeholder="e.g. Uvongo" required>
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

        {{-- ── Rental & Lease Details (collapsible) ──────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left">
                <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--brand-default,#0b2a4a);">Rental & Lease Details</h3>
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
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Commission (%)</label>
                        <input type="number" name="commission_percent" value="{{ old('commission_percent', $property?->commission_percent) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="0.00" min="0" max="100" step="0.01">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Admin Fee (R)</label>
                        <input type="number" name="admin_fee" value="{{ old('admin_fee', $property?->admin_fee) }}"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                               placeholder="0.00" min="0" step="0.01">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1 text-slate-700">Marketing Fee (R)</label>
                        <input type="number" name="marketing_fee" value="{{ old('marketing_fee', $property?->marketing_fee) }}"
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
        </div>

        {{-- ── Publish ───────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-4">
            <div class="flex items-start gap-3">
                <input type="hidden" name="publish" value="0">
                <input type="checkbox" name="publish" value="1" id="publish_toggle"
                       {{ ($property && $property->isPublished()) ? 'checked' : '' }}
                       {{ ($property && $property->isPublished()) ? 'disabled' : '' }}
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
@endsection
