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
              class="bg-white border rounded-lg p-6 space-y-4">
            @csrf
            @if(isset($property)) @method('PUT') @endif

            {{-- Address --}}
            <div class="border-b pb-4">
                <h3 class="font-semibold text-gray-700 mb-3">Property Address</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Street Address *</label>
                        <input type="text" name="address_line_1" required
                               value="{{ old('address_line_1', $property->address_line_1 ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               placeholder="e.g. 8 The Tydes">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Unit / Flat Number</label>
                        <input type="text" name="address_line_2"
                               value="{{ old('address_line_2', $property->address_line_2 ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               placeholder="e.g. Unit 4, Flat B">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Suburb</label>
                            <input type="text" name="suburb"
                                   value="{{ old('suburb', $property->suburb ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   placeholder="e.g. Shelly Beach">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">City / Town</label>
                            <input type="text" name="city"
                                   value="{{ old('city', $property->city ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm"
                                   placeholder="e.g. Margate">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Postal Code</label>
                            <input type="text" name="postal_code"
                                   value="{{ old('postal_code', $property->postal_code ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Province</label>
                            <input type="text" name="province"
                                   value="{{ old('province', $property->province ?? 'KwaZulu-Natal') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Property Details --}}
            <div class="border-b pb-4">
                <h3 class="font-semibold text-gray-700 mb-3">Property Details</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Property Type</label>
                        <select name="property_type" class="w-full border rounded-lg px-3 py-2 text-sm">
                            <option value="">-- Select --</option>
                            @foreach(\App\Models\Rental\RentalProperty::PROPERTY_TYPES as $key => $label)
                                <option value="{{ $key }}" {{ old('property_type', $property->property_type ?? '') == $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Monthly Rental (R)</label>
                        <input type="number" name="monthly_rental" step="0.01" min="0"
                               value="{{ old('monthly_rental', $property->monthly_rental ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm"
                               placeholder="0.00">
                    </div>
                </div>
            </div>

            {{-- Landlord / Owner --}}
            <div class="border-b pb-4">
                <h3 class="font-semibold text-gray-700 mb-3">Landlord / Owner</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Landlord Name</label>
                        <input type="text" name="landlord_name"
                               value="{{ old('landlord_name', $property->landlord_name ?? '') }}"
                               class="w-full border rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Email</label>
                            <input type="email" name="landlord_email"
                                   value="{{ old('landlord_email', $property->landlord_email ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Phone</label>
                            <input type="text" name="landlord_phone"
                                   value="{{ old('landlord_phone', $property->landlord_phone ?? '') }}"
                                   class="w-full border rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Notes</label>
                <textarea name="notes" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm"
                          placeholder="Any additional notes about this property...">{{ old('notes', $property->notes ?? '') }}</textarea>
            </div>

            {{-- Submit --}}
            <div class="flex justify-end gap-3 pt-4">
                <a href="{{ route('rental.settings.properties.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                    {{ isset($property) ? 'Update Property' : 'Add Property' }}
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
