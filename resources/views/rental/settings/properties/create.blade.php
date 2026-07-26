@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">{{ isset($property) ? 'Edit Property' : 'Add Property' }}</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.settings.properties.index') }}" class="text-white/60 hover:text-white">&larr; Properties</a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="max-w-2xl">
        <form method="POST"
              action="{{ isset($property) ? route('rental.settings.properties.update', $property) : route('rental.settings.properties.store') }}"
              class="border rounded-lg p-6 space-y-4"
              style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
            @csrf
            @if(isset($property)) @method('PUT') @endif

            {{-- Address --}}
            <div class="border-b pb-4" style="border-color:var(--border)">
                <h3 class="font-semibold mb-3" style="color:var(--text-secondary)">Property Address</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Street Address *</label>
                        <input type="text" name="address_line_1" required
                               value="{{ old('address_line_1', $property->address_line_1 ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                               placeholder="e.g. 8 The Tydes">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Unit / Flat Number</label>
                        <input type="text" name="address_line_2"
                               value="{{ old('address_line_2', $property->address_line_2 ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                               placeholder="e.g. Unit 4, Flat B">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Suburb</label>
                            <input type="text" name="suburb"
                                   value="{{ old('suburb', $property->suburb ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                                   placeholder="e.g. Shelly Beach">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">City / Town</label>
                            <input type="text" name="city"
                                   value="{{ old('city', $property->city ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                                   placeholder="e.g. Margate">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Postal Code</label>
                            <input type="text" name="postal_code"
                                   value="{{ old('postal_code', $property->postal_code ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Province</label>
                            <input type="text" name="province"
                                   value="{{ old('province', $property->province ?? 'KwaZulu-Natal') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Property Details --}}
            <div class="border-b pb-4" style="border-color:var(--border)">
                <h3 class="font-semibold mb-3" style="color:var(--text-secondary)">Property Details</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Property Type</label>
                        <select name="property_type" class="w-full border rounded-lg px-3 py-2 text-sm"
                                style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                            <option value="">-- Select --</option>
                            @foreach(\App\Models\Rental\RentalProperty::PROPERTY_TYPES as $key => $label)
                                <option value="{{ $key }}" {{ old('property_type', $property->property_type ?? '') == $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Monthly Rental (R)</label>
                        <input type="number" name="monthly_rental" step="0.01" min="0"
                               value="{{ old('monthly_rental', $property->monthly_rental ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                               placeholder="0.00">
                    </div>
                </div>
            </div>

            {{-- Landlord / Owner --}}
            <div class="border-b pb-4" style="border-color:var(--border)">
                <h3 class="font-semibold mb-3" style="color:var(--text-secondary)">Landlord / Owner</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Landlord Name</label>
                        <input type="text" name="landlord_name"
                               value="{{ old('landlord_name', $property->landlord_name ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Email</label>
                            <input type="email" name="landlord_email"
                                   value="{{ old('landlord_email', $property->landlord_email ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Phone</label>
                            <input type="text" name="landlord_phone"
                                   value="{{ old('landlord_phone', $property->landlord_phone ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-medium mb-1" style="color:var(--text-muted)">Notes</label>
                <textarea name="notes" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm"
                          style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                          placeholder="Any additional notes about this property...">{{ old('notes', $property->notes ?? '') }}</textarea>
            </div>

            {{-- Submit --}}
            <div class="flex justify-end gap-3 pt-4">
                <a href="{{ route('rental.settings.properties.index') }}" class="px-4 py-2 text-sm hover:opacity-80" style="color:var(--text-secondary)">Cancel</a>
                <button type="submit" class="px-6 py-2 rounded-lg text-sm font-medium hover:opacity-90" style="background:var(--brand-button); color:#fff">
                    {{ isset($property) ? 'Update Property' : 'Add Property' }}
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
