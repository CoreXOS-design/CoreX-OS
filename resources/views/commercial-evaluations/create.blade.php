@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-3xl mx-auto" x-data="{ propertyType: '{{ old('property_type', '') }}' }">

    {{-- Navy header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
        <h2 class="text-xl font-bold text-white">New Commercial Market Evaluation</h2>
        <p class="text-sm text-white/60 mt-0.5">Select a property type and enter the details</p>
    </div>

    @if($errors->any())
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Step 1: Property Type Selection --}}
    <div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
        <div class="px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Step 1: Select Property Type</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                {{-- Commercial --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="property_type_selector" value="commercial" class="sr-only peer" @click="propertyType = 'commercial'" :checked="propertyType === 'commercial'">
                    <div class="border-2 rounded-xl p-4 text-center transition-all peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:border-gray-300"
                         :class="propertyType === 'commercial' ? 'border-blue-500 bg-blue-50' : 'border-gray-200'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mx-auto mb-2 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                        </svg>
                        <span class="text-xs font-semibold text-gray-700">Commercial</span>
                        <span class="block text-[10px] text-gray-400 mt-0.5">Retail / Office</span>
                    </div>
                </label>

                {{-- Industrial --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="property_type_selector" value="industrial" class="sr-only peer" @click="propertyType = 'industrial'" :checked="propertyType === 'industrial'">
                    <div class="border-2 rounded-xl p-4 text-center transition-all peer-checked:border-amber-500 peer-checked:bg-amber-50 hover:border-gray-300"
                         :class="propertyType === 'industrial' ? 'border-amber-500 bg-amber-50' : 'border-gray-200'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mx-auto mb-2 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
                        </svg>
                        <span class="text-xs font-semibold text-gray-700">Industrial</span>
                        <span class="block text-[10px] text-gray-400 mt-0.5">Warehouse / Factory</span>
                    </div>
                </label>

                {{-- Hospitality --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="property_type_selector" value="hospitality" class="sr-only peer" @click="propertyType = 'hospitality'" :checked="propertyType === 'hospitality'">
                    <div class="border-2 rounded-xl p-4 text-center transition-all peer-checked:border-purple-500 peer-checked:bg-purple-50 hover:border-gray-300"
                         :class="propertyType === 'hospitality' ? 'border-purple-500 bg-purple-50' : 'border-gray-200'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mx-auto mb-2 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                        </svg>
                        <span class="text-xs font-semibold text-gray-700">Hospitality</span>
                        <span class="block text-[10px] text-gray-400 mt-0.5">B&B / Guest House</span>
                    </div>
                </label>

                {{-- Agricultural --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="property_type_selector" value="agricultural" class="sr-only peer" @click="propertyType = 'agricultural'" :checked="propertyType === 'agricultural'">
                    <div class="border-2 rounded-xl p-4 text-center transition-all peer-checked:border-green-500 peer-checked:bg-green-50 hover:border-gray-300"
                         :class="propertyType === 'agricultural' ? 'border-green-500 bg-green-50' : 'border-gray-200'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mx-auto mb-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                        <span class="text-xs font-semibold text-gray-700">Agricultural</span>
                        <span class="block text-[10px] text-gray-400 mt-0.5">Farm / Smallholding</span>
                    </div>
                </label>
            </div>
        </div>
    </div>

    {{-- Step 2: Property Details Form (shows after type selected) --}}
    <div x-show="propertyType !== ''" x-transition class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        <form method="POST" action="{{ route('commercial-evaluations.store') }}">
            @csrf
            <input type="hidden" name="property_type" :value="propertyType">

            <div class="px-5 py-4 space-y-5">
                <h3 class="text-sm font-semibold text-gray-700">Step 2: Property Details</h3>

                {{-- Property Name --}}
                <div>
                    <label class="ds-label block mb-1">Property Name <span class="text-red-500">*</span></label>
                    <input type="text" name="property_name" value="{{ old('property_name') }}" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="e.g. Ocean View Guest House">
                    @error('property_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Address --}}
                <div>
                    <label class="ds-label block mb-1">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                              placeholder="Full street address">{{ old('address') }}</textarea>
                    @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Location row --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Suburb</label>
                        <input type="text" name="suburb" value="{{ old('suburb') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. Uvongo">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Town</label>
                        <input type="text" name="town" value="{{ old('town') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. Margate">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Province</label>
                        <input type="text" name="province" value="{{ old('province', 'KwaZulu-Natal') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                    </div>
                </div>

                {{-- Erf & Zoning --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Erf Number</label>
                        <input type="text" name="erf_number" value="{{ old('erf_number') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. Erf 789">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Zoning</label>
                        <input type="text" name="zoning" value="{{ old('zoning') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. Business 1, Industrial, Agricultural">
                    </div>
                </div>

                {{-- Size fields --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Total Land Size (m&sup2;)</label>
                        <input type="number" step="0.01" name="total_land_size_m2" value="{{ old('total_land_size_m2') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="0.00">
                    </div>
                    <div x-show="propertyType === 'agricultural'">
                        <label class="ds-label block mb-1">Total Land Size (ha)</label>
                        <input type="number" step="0.0001" name="total_land_size_ha" value="{{ old('total_land_size_ha') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="0.0000">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Total Building Size (m&sup2;)</label>
                        <input type="number" step="0.01" name="total_building_size_m2" value="{{ old('total_building_size_m2') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="0.00">
                    </div>
                </div>

                {{-- Year & Condition --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Year Built</label>
                        <input type="number" name="year_built" value="{{ old('year_built') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. 2005" min="1800" max="2100">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Condition</label>
                        <select name="condition"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                            <option value="">— Select —</option>
                            <option value="excellent" {{ old('condition') === 'excellent' ? 'selected' : '' }}>Excellent</option>
                            <option value="good" {{ old('condition') === 'good' ? 'selected' : '' }}>Good</option>
                            <option value="fair" {{ old('condition') === 'fair' ? 'selected' : '' }}>Fair</option>
                            <option value="poor" {{ old('condition') === 'poor' ? 'selected' : '' }}>Poor</option>
                        </select>
                    </div>
                </div>

                {{-- Financial --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="ds-label block mb-1">Asking Price (ZAR)</label>
                        <input type="number" step="0.01" name="asking_price" value="{{ old('asking_price') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. 5000000">
                    </div>
                    <div>
                        <label class="ds-label block mb-1">Municipal Evaluation (ZAR)</label>
                        <input type="number" step="0.01" name="municipal_evaluation" value="{{ old('municipal_evaluation') }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                               placeholder="e.g. 3500000">
                    </div>
                </div>

                {{-- Seller & Notes --}}
                <div>
                    <label class="ds-label block mb-1">Seller Name</label>
                    <input type="text" name="seller_name" value="{{ old('seller_name') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                           placeholder="Optional">
                </div>
                <div>
                    <label class="ds-label block mb-1">Notes</label>
                    <textarea name="notes" rows="3"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                              placeholder="Any additional notes about this property...">{{ old('notes') }}</textarea>
                </div>

                @if($isAdmin)
                <div>
                    <label class="ds-label block mb-1">Branch</label>
                    <select name="branch_id"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        <option value="">— Auto-assign —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
            </div>

            <div class="px-5 py-4 border-t border-gray-100 flex items-center gap-3">
                <button type="submit" class="nexus-btn-primary" :disabled="propertyType === ''">
                    Create Evaluation &rarr;
                </button>
                <a href="{{ route('commercial-evaluations.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
