@extends('layouts.nexus')

@section('nexus-content')
@php
    $typeLabel = \App\Models\CommercialEvaluation::propertyTypeLabel($evaluation->property_type);
    $typeBadge = \App\Models\CommercialEvaluation::propertyTypeBadgeColor($evaluation->property_type);
    $statusBadge = \App\Models\CommercialEvaluation::statusBadgeColor($evaluation->status);
    $isCommercialOrIndustrial = in_array($evaluation->property_type, ['commercial', 'industrial']);
    $isHospitalityOrAgri = in_array($evaluation->property_type, ['hospitality', 'agricultural']);
    $formatZar = fn($cents) => \App\Models\CommercialEvaluation::formatZar($cents);
@endphp

<div class="max-w-6xl mx-auto">

    {{-- Navy header bar --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div>
                <div class="flex items-center gap-3 mb-1.5">
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $evaluation->property_name }}</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $typeBadge }}">
                        {{ $typeLabel }}
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusBadge }}">
                        {{ ucfirst($evaluation->status) }}
                    </span>
                </div>
                @if($evaluation->address)
                    <p class="text-sm text-white/70 font-medium">{{ $evaluation->address }}</p>
                @endif
                @php
                    $details = array_filter([
                        $evaluation->suburb,
                        $evaluation->town,
                        $evaluation->zoning ? 'Zoning: ' . $evaluation->zoning : null,
                        $evaluation->total_building_size_m2 ? number_format($evaluation->total_building_size_m2) . ' m&sup2; building' : null,
                        $evaluation->asking_price ? $evaluation->asking_price_display : null,
                    ]);
                @endphp
                @if(!empty($details))
                    <p class="text-xs text-white/40 mt-1">{!! implode(' &middot; ', $details) !!}</p>
                @endif
                @if($evaluation->seller_name)
                    <p class="text-xs text-white/40 mt-0.5">Seller: {{ $evaluation->seller_name }}</p>
                @endif
                <p class="text-xs text-white/40 mt-0.5">Created {{ $evaluation->created_at->format('Y-m-d') }}</p>
            </div>
            <a href="{{ route('commercial-evaluations.index') }}"
               class="nexus-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                &larr; All Evaluations
            </a>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-5 px-4 py-3 rounded-xl text-sm font-medium" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
            {{ session('success') }}
        </div>
    @endif
    @if(session('info'))
        <div class="mb-5 px-4 py-3 rounded-xl text-sm font-medium bg-blue-50 border border-blue-200 text-blue-700">
            {{ session('info') }}
        </div>
    @endif

    {{-- ACTION BUTTONS --}}
    <div class="ds-status-card mb-6">
        <div class="flex flex-wrap items-center gap-3 px-5 py-3.5">
            <a href="{{ route('commercial-evaluations.edit', $evaluation) }}" class="nexus-btn-primary text-sm">
                Edit Details
            </a>
            <form method="POST" action="{{ route('commercial-evaluations.evaluate', $evaluation) }}" class="inline">
                @csrf
                <button type="submit" class="nexus-btn-outline text-sm">
                    Run Evaluation
                </button>
            </form>
            <a href="{{ route('commercial-evaluations.pdf', $evaluation) }}" class="nexus-btn-outline text-sm">
                Download PDF
            </a>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         PROPERTY OVERVIEW
    ═══════════════════════════════════════════ --}}
    <div class="ds-status-card mb-6">
        <div class="px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Property Overview</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-xs text-gray-400 block">Property Type</span>
                    <span class="font-medium text-gray-800">{{ $typeLabel }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Suburb / Town</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->suburb ?? '—' }}{{ $evaluation->town ? ', ' . $evaluation->town : '' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Erf Number</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->erf_number ?? '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Zoning</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->zoning ?? '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Land Size</span>
                    <span class="font-medium text-gray-800">
                        @if($evaluation->total_land_size_ha)
                            {{ number_format($evaluation->total_land_size_ha, 2) }} ha
                        @elseif($evaluation->total_land_size_m2)
                            {{ number_format($evaluation->total_land_size_m2) }} m&sup2;
                        @else
                            —
                        @endif
                    </span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Building Size</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->total_building_size_m2 ? number_format($evaluation->total_building_size_m2) . ' m&sup2;' : '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Year Built</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->year_built ?? '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Condition</span>
                    <span class="font-medium text-gray-800">{{ $evaluation->condition ? ucfirst($evaluation->condition) : '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Asking Price</span>
                    <span class="font-medium text-gray-800 font-mono">{{ $evaluation->asking_price_display }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 block">Municipal Evaluation</span>
                    <span class="font-medium text-gray-800 font-mono">{{ $evaluation->municipal_evaluation_display }}</span>
                </div>
            </div>
            @if($evaluation->notes)
                <div class="mt-3 pt-3 border-t border-gray-100">
                    <span class="text-xs text-gray-400 block mb-1">Notes</span>
                    <p class="text-sm text-gray-700">{{ $evaluation->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         FINANCIAL DATA
    ═══════════════════════════════════════════ --}}
    <div class="ds-status-card mb-6" x-data="{ showFinancialForm: false }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">Financial Data</h3>
                <button @click="showFinancialForm = !showFinancialForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Financial Year
                </button>
            </div>

            {{-- Add financial year form --}}
            <div x-show="showFinancialForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.financials.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Financial Year <span class="text-red-500">*</span></label>
                            <input type="text" name="financial_year" required placeholder="e.g. 2025"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Period (months)</label>
                            <input type="number" name="period_months" value="12" min="1" max="24"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>

                    {{-- Income Section --}}
                    <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 mt-4">Income (ZAR per annum)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Gross Revenue</label>
                            <input type="number" step="0.01" name="gross_revenue" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @if($isCommercialOrIndustrial)
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Rental Income</label>
                            <input type="number" step="0.01" name="rental_income" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        @if($evaluation->property_type === 'hospitality')
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Room Revenue</label>
                            <input type="number" step="0.01" name="room_revenue" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Food & Beverage Revenue</label>
                            <input type="number" step="0.01" name="food_beverage_revenue" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Other Income</label>
                            <input type="number" step="0.01" name="other_income" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @if($isCommercialOrIndustrial)
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Vacancy Rate (%)</label>
                            <input type="number" step="0.01" name="vacancy_rate" placeholder="0" min="0" max="100"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                    </div>

                    {{-- Expenses Section --}}
                    <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 mt-4">Operating Expenses (ZAR per annum)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Rates & Taxes</label>
                            <input type="number" step="0.01" name="rates_taxes" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Insurance</label>
                            <input type="number" step="0.01" name="insurance" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Utilities</label>
                            <input type="number" step="0.01" name="utilities" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Security</label>
                            <input type="number" step="0.01" name="security" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Maintenance & Repairs</label>
                            <input type="number" step="0.01" name="maintenance" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Management Fees</label>
                            <input type="number" step="0.01" name="management_fees" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @if(in_array($evaluation->property_type, ['hospitality', 'agricultural']))
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Salaries & Wages</label>
                            <input type="number" step="0.01" name="salaries_wages" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Marketing</label>
                            <input type="number" step="0.01" name="marketing" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @if($evaluation->property_type === 'hospitality')
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Food & Beverage Costs</label>
                            <input type="number" step="0.01" name="food_beverage_cost" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        @if($evaluation->property_type === 'agricultural')
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Farm Operating Costs</label>
                            <input type="number" step="0.01" name="farm_operating_costs" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Other Expenses</label>
                            <input type="number" step="0.01" name="other_expenses" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>

                    <div class="flex items-center gap-2 mt-3">
                        <button type="submit" class="nexus-btn-primary text-xs">Save Financial Year</button>
                        <button type="button" @click="showFinancialForm = false" class="text-xs text-gray-500 hover:text-gray-700">Cancel</button>
                    </div>
                </form>
            </div>

            {{-- Existing financials table --}}
            @if($evaluation->financials->isEmpty())
                <p class="text-sm text-gray-400">No financial data added yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Year</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Gross Revenue</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Total Expenses</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">NOI</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">EBITDA</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($evaluation->financials as $fin)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $fin->financial_year }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($fin->gross_revenue + $fin->rental_income + $fin->room_revenue + $fin->food_beverage_revenue + $fin->other_income) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs text-red-600">{{ $formatZar($fin->total_expenses) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs {{ ($fin->net_operating_income ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $formatZar($fin->net_operating_income) }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($fin->ebitda) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         RENTAL UNITS (commercial + industrial only)
    ═══════════════════════════════════════════ --}}
    @if($isCommercialOrIndustrial)
    <div class="ds-status-card mb-6" x-data="{ showUnitForm: false }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">Rental Units</h3>
                <button @click="showUnitForm = !showUnitForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Unit
                </button>
            </div>

            {{-- Add unit form --}}
            <div x-show="showUnitForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.units.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Unit Name <span class="text-red-500">*</span></label>
                            <input type="text" name="unit_name" required placeholder="e.g. Shop 1"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Tenant Name</label>
                            <input type="text" name="tenant_name" placeholder="Vacant if empty"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Size (m&sup2;)</label>
                            <input type="number" step="0.01" name="size_m2" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Monthly Rental (ZAR)</label>
                            <input type="number" step="0.01" name="monthly_rental" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Lease Start</label>
                            <input type="date" name="lease_start"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Lease End</label>
                            <input type="date" name="lease_end"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Escalation Rate (%)</label>
                            <input type="number" step="0.01" name="escalation_rate" placeholder="e.g. 8"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" name="is_vacant" value="1" class="rounded border-gray-300 text-[#00b4d8] focus:ring-[#00b4d8]">
                                Vacant
                            </label>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="nexus-btn-primary text-xs">Add Unit</button>
                        <button type="button" @click="showUnitForm = false" class="text-xs text-gray-500">Cancel</button>
                    </div>
                </form>
            </div>

            @if($evaluation->units->isEmpty())
                <p class="text-sm text-gray-400">No rental units added yet.</p>
            @else
                @php
                    $totalMonthlyRental = $evaluation->units->sum('monthly_rental');
                    $vacantCount = $evaluation->units->where('is_vacant', true)->count();
                @endphp
                <div class="flex gap-4 mb-3 text-xs">
                    <span class="text-gray-500">Total units: <strong>{{ $evaluation->units->count() }}</strong></span>
                    <span class="text-gray-500">Total monthly rental: <strong class="font-mono">{{ $formatZar($totalMonthlyRental) }}</strong></span>
                    <span class="text-gray-500">Vacant: <strong class="{{ $vacantCount > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $vacantCount }}</strong></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Unit</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Tenant</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Size</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Monthly Rental</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Lease</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($evaluation->units as $unit)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $unit->unit_name }}</td>
                                <td class="px-3 py-2">
                                    @if($unit->is_vacant)
                                        <span class="text-red-500 text-xs font-medium">VACANT</span>
                                    @else
                                        {{ $unit->tenant_name ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right text-xs">{{ $unit->size_m2 ? number_format($unit->size_m2) . ' m&sup2;' : '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($unit->monthly_rental) }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">
                                    @if($unit->lease_start || $unit->lease_end)
                                        {{ $unit->lease_start?->format('Y-m-d') ?? '?' }} — {{ $unit->lease_end?->format('Y-m-d') ?? '?' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <form method="POST" action="{{ route('commercial-evaluations.units.destroy', [$evaluation, $unit]) }}" class="inline" onsubmit="return confirm('Remove this unit?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════
         ASSETS (hospitality + agricultural only)
    ═══════════════════════════════════════════ --}}
    @if($isHospitalityOrAgri)
    <div class="ds-status-card mb-6" x-data="{ showAssetForm: false }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">Assets</h3>
                <button @click="showAssetForm = !showAssetForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Asset
                </button>
            </div>

            {{-- Add asset form --}}
            <div x-show="showAssetForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.assets.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Category <span class="text-red-500">*</span></label>
                            <select name="category" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                                <option value="">— Select —</option>
                                @if($evaluation->property_type === 'hospitality')
                                    <option value="Furniture & Fittings">Furniture & Fittings</option>
                                    <option value="Kitchen Equipment">Kitchen Equipment</option>
                                    <option value="Linen & Bedding">Linen & Bedding</option>
                                    <option value="Crockery & Cutlery">Crockery & Cutlery</option>
                                    <option value="Electronics & Entertainment">Electronics & Entertainment</option>
                                    <option value="Vehicles">Vehicles</option>
                                    <option value="Pool & Garden Equipment">Pool & Garden Equipment</option>
                                    <option value="Goodwill">Goodwill</option>
                                    <option value="Other">Other</option>
                                @else
                                    <option value="Livestock">Livestock</option>
                                    <option value="Crops">Crops</option>
                                    <option value="Farm Equipment">Farm Equipment</option>
                                    <option value="Vehicles">Vehicles</option>
                                    <option value="Water Rights">Water Rights</option>
                                    <option value="Boreholes">Boreholes</option>
                                    <option value="Fencing">Fencing</option>
                                    <option value="Irrigation Equipment">Irrigation Equipment</option>
                                    <option value="Buildings & Structures">Buildings & Structures</option>
                                    <option value="Other">Other</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Description <span class="text-red-500">*</span></label>
                            <input type="text" name="description" required placeholder="e.g. 10 cattle, Nguni breed"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Quantity</label>
                            <input type="number" name="quantity" min="1" placeholder="1"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Estimated Value (ZAR)</label>
                            <input type="number" step="0.01" name="estimated_value" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Notes</label>
                            <input type="text" name="notes" placeholder="Optional"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="nexus-btn-primary text-xs">Add Asset</button>
                        <button type="button" @click="showAssetForm = false" class="text-xs text-gray-500">Cancel</button>
                    </div>
                </form>
            </div>

            @if($evaluation->assets->isEmpty())
                <p class="text-sm text-gray-400">No assets added yet.</p>
            @else
                @php $totalAssetValue = $evaluation->assets->sum('estimated_value'); @endphp
                <div class="mb-3 text-xs text-gray-500">
                    Total asset value: <strong class="font-mono">{{ $formatZar($totalAssetValue) }}</strong>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Category</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Description</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Qty</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Value</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($evaluation->assets as $asset)
                            <tr>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $asset->category }}</td>
                                <td class="px-3 py-2 font-medium">{{ $asset->description }}</td>
                                <td class="px-3 py-2 text-right text-xs">{{ $asset->quantity ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($asset->estimated_value) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <form method="POST" action="{{ route('commercial-evaluations.assets.destroy', [$evaluation, $asset]) }}" class="inline" onsubmit="return confirm('Remove this asset?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════
         CROPS & ORCHARDS (agricultural only)
    ═══════════════════════════════════════════ --}}
    @if($evaluation->property_type === 'agricultural')
    <div class="ds-status-card mb-6" x-data="{
        showCropForm: false,
        selectedCrop: '',
        cropConfigs: @js($cropConfig),
        get currentConfig() { return this.cropConfigs[this.selectedCrop] || {}; },
        get hasQuestions() { return (this.currentConfig.questions || []).length > 0; },
        formatCents(cents) {
            if (!cents) return '';
            return (cents / 100).toFixed(0);
        }
    }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">
                    <svg class="w-4 h-4 inline-block mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                    Crops & Orchards
                </h3>
                <button @click="showCropForm = !showCropForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Crop
                </button>
            </div>

            {{-- Add crop form --}}
            <div x-show="showCropForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.crops.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Crop Type <span class="text-red-500">*</span></label>
                            <select name="crop_type" required x-model="selectedCrop"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                                <option value="">— Select —</option>
                                @foreach($cropConfig as $key => $cfg)
                                    <option value="{{ $key }}">{{ $cfg['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Variety</label>
                            <input type="text" name="variety" placeholder="e.g. Beaumont, Williams"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Hectares <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" name="hectares" required placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Year Planted</label>
                            <input type="number" name="year_planted" min="1900" max="2100" placeholder="e.g. 2014"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Trees per hectare</label>
                            <input type="number" name="trees_per_hectare" min="1"
                                   :placeholder="currentConfig.typical_trees_per_ha ? 'Default: ' + currentConfig.typical_trees_per_ha : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Current yield (t/ha)</label>
                            <input type="number" step="0.01" name="current_yield_tons_per_ha" min="0"
                                   :placeholder="currentConfig.peak_yield_tons_per_ha ? 'Peak: ' + currentConfig.peak_yield_tons_per_ha : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Price per ton (ZAR)</label>
                            <input type="number" step="0.01" name="current_price_per_ton" min="0"
                                   :placeholder="currentConfig.current_price_per_ton ? 'Default: R ' + formatCents(currentConfig.current_price_per_ton) : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Cost per ha (ZAR/yr)</label>
                            <input type="number" step="0.01" name="annual_cost_per_ha" min="0"
                                   :placeholder="currentConfig.cost_per_ha ? 'Default: R ' + formatCents(currentConfig.cost_per_ha) : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div class="md:col-span-3">
                            <label class="text-xs text-gray-500 block mb-1">Notes</label>
                            <input type="text" name="notes" placeholder="Optional"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>

                    {{-- Guidance questions panel --}}
                    <div x-show="hasQuestions" x-transition class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                        <p class="text-xs font-semibold text-green-800 mb-2">
                            Questions to ask the seller about their <span x-text="currentConfig.label || 'crop'"></span>:
                        </p>
                        <ul class="space-y-1">
                            <template x-for="(q, i) in (currentConfig.questions || [])" :key="i">
                                <li class="flex items-start gap-2 text-xs text-green-700">
                                    <input type="checkbox" class="mt-0.5 rounded border-green-300 text-green-600 focus:ring-green-500">
                                    <span x-text="q"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Config defaults info --}}
                    <div x-show="selectedCrop && currentConfig.lifespan_years" x-transition class="mb-3 p-2 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-700">
                        <strong>Config defaults:</strong>
                        Lifespan: <span x-text="currentConfig.lifespan_years"></span> yrs |
                        First crop: yr <span x-text="currentConfig.years_to_first_crop"></span> |
                        Peak at yr <span x-text="currentConfig.years_to_peak"></span> |
                        Peak yield: <span x-text="currentConfig.peak_yield_tons_per_ha || '—'"></span> t/ha |
                        Trees/ha: <span x-text="currentConfig.typical_trees_per_ha || '—'"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="nexus-btn-primary text-xs">Add Crop</button>
                        <button type="button" @click="showCropForm = false; selectedCrop = ''" class="text-xs text-gray-500">Cancel</button>
                    </div>
                </form>
            </div>

            @if($evaluation->crops->isEmpty())
                <p class="text-sm text-gray-400">No crops or orchards added yet.</p>
            @else
                <div class="space-y-3">
                    @foreach($evaluation->crops as $crop)
                        @php
                            $cropLabel = $cropConfig[$crop->crop_type]['label'] ?? ucfirst(str_replace('_', ' ', $crop->crop_type));
                            $lifespanYears = $crop->expected_lifespan_years ?? 0;
                            $ageYears = $crop->age_years ?? 0;
                            $remainingYears = $crop->remaining_productive_years ?? 0;
                            $lifespanPct = $lifespanYears > 0 ? min(100, round(($ageYears / $lifespanYears) * 100)) : 0;
                            $yieldPct = $crop->yield_percentage ?? 0;
                            $currentYield = $crop->current_yield_tons_per_ha;
                        @endphp
                        <div class="p-4 bg-white border border-gray-200 rounded-lg">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <span class="font-semibold text-sm text-gray-800">{{ $cropLabel }}</span>
                                    <span class="text-gray-500 text-sm"> — {{ number_format($crop->hectares, 1) }} ha</span>
                                    @if($crop->variety)
                                        <span class="text-gray-400 text-sm">({{ $crop->variety }})</span>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('commercial-evaluations.crops.destroy', [$evaluation, $crop]) }}" class="inline" onsubmit="return confirm('Remove this crop?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                                </form>
                            </div>

                            {{-- Lifecycle bar --}}
                            @if($lifespanYears > 0)
                            <div class="mb-2">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full {{ $lifespanPct > 80 ? 'bg-red-400' : ($lifespanPct > 50 ? 'bg-amber-400' : 'bg-green-400') }}"
                                             style="width: {{ $lifespanPct }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 whitespace-nowrap font-medium">{{ $remainingYears }} years remaining</span>
                                </div>
                            </div>
                            @endif

                            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-600">
                                @if($crop->age_years !== null)
                                    <span>Age: <strong>{{ $ageYears }} yrs</strong></span>
                                @endif
                                @if($lifespanYears)
                                    <span>Lifespan: <strong>{{ $lifespanYears }} yrs</strong></span>
                                @endif
                                @if($yieldPct)
                                    <span>Yield: <strong>{{ number_format($yieldPct, 0) }}% of peak</strong>
                                        @if($currentYield)
                                            ({{ number_format($currentYield, 1) }} t/ha)
                                        @endif
                                    </span>
                                @endif
                                @if($crop->total_trees)
                                    <span>Trees: <strong>{{ number_format($crop->total_trees) }}</strong></span>
                                @endif
                            </div>

                            @if($crop->annual_revenue || $crop->annual_cost_per_ha)
                            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs mt-1">
                                @if($crop->annual_revenue)
                                    <span class="text-green-700">Est. annual revenue: <strong class="font-mono">{{ $formatZar($crop->annual_revenue) }}</strong></span>
                                @endif
                                @if($crop->annual_cost_per_ha)
                                    <span class="text-red-600">Cost: <strong class="font-mono">{{ $formatZar((int)round($crop->annual_cost_per_ha * $crop->hectares)) }}</strong>/yr</span>
                                @endif
                            </div>
                            @endif

                            @if($crop->notes)
                                <p class="text-xs text-gray-400 mt-1 italic">{{ $crop->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Crop totals --}}
                @php
                    $totalCropHa = $evaluation->crops->sum('hectares');
                    $totalCropRevenue = $evaluation->crops->sum('annual_revenue');
                    $totalCropCost = $evaluation->crops->sum(function($c) { return ($c->annual_cost_per_ha ?? 0) * $c->hectares; });
                @endphp
                <div class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap gap-x-6 text-xs text-gray-500">
                    <span>Total hectares: <strong>{{ number_format($totalCropHa, 1) }} ha</strong></span>
                    <span>Total revenue: <strong class="font-mono text-green-700">{{ $formatZar((int)$totalCropRevenue) }}</strong></span>
                    <span>Total cost: <strong class="font-mono text-red-600">{{ $formatZar((int)$totalCropCost) }}</strong></span>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         LIVESTOCK (agricultural only)
    ═══════════════════════════════════════════ --}}
    <div class="ds-status-card mb-6" x-data="{
        showLivestockForm: false,
        selectedLivestock: '',
        livestockConfigs: @js($livestockConfig),
        get currentConfig() { return this.livestockConfigs[this.selectedLivestock] || {}; },
        get hasQuestions() { return (this.currentConfig.questions || []).length > 0; },
        formatCents(cents) {
            if (!cents) return '';
            return (cents / 100).toFixed(0);
        }
    }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">
                    <svg class="w-4 h-4 inline-block mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                    Livestock
                </h3>
                <button @click="showLivestockForm = !showLivestockForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Livestock
                </button>
            </div>

            {{-- Add livestock form --}}
            <div x-show="showLivestockForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.livestock.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Livestock Type <span class="text-red-500">*</span></label>
                            <select name="livestock_type" required x-model="selectedLivestock"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                                <option value="">— Select —</option>
                                @foreach($livestockConfig as $key => $cfg)
                                    <option value="{{ $key }}">{{ $cfg['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Breed</label>
                            <input type="text" name="breed" placeholder="e.g. Nguni, Holstein"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Head Count <span class="text-red-500">*</span></label>
                            <input type="number" name="head_count" required min="1" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Breeding Stock Count</label>
                            <input type="number" name="breeding_stock_count" min="0" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Value per Head (ZAR)</label>
                            <input type="number" step="0.01" name="value_per_head" min="0"
                                   :placeholder="currentConfig.value_per_head ? 'Default: R ' + formatCents(currentConfig.value_per_head) : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Carrying Capacity (ha/LSU)</label>
                            <input type="number" step="0.01" name="carrying_capacity_ha_per_lsu" min="0"
                                   :placeholder="currentConfig.carrying_capacity_ha ? 'Default: ' + currentConfig.carrying_capacity_ha : ''"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Hectares Used</label>
                            <input type="number" step="0.01" name="hectares_used" min="0" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs text-gray-500 block mb-1">Notes</label>
                            <input type="text" name="notes" placeholder="Optional"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>

                    {{-- Guidance questions panel --}}
                    <div x-show="hasQuestions" x-transition class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                        <p class="text-xs font-semibold text-green-800 mb-2">
                            Questions to ask the seller about their <span x-text="currentConfig.label || 'livestock'"></span>:
                        </p>
                        <ul class="space-y-1">
                            <template x-for="(q, i) in (currentConfig.questions || [])" :key="i">
                                <li class="flex items-start gap-2 text-xs text-green-700">
                                    <input type="checkbox" class="mt-0.5 rounded border-green-300 text-green-600 focus:ring-green-500">
                                    <span x-text="q"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="nexus-btn-primary text-xs">Add Livestock</button>
                        <button type="button" @click="showLivestockForm = false; selectedLivestock = ''" class="text-xs text-gray-500">Cancel</button>
                    </div>
                </form>
            </div>

            @if($evaluation->livestock->isEmpty())
                <p class="text-sm text-gray-400">No livestock added yet.</p>
            @else
                <div class="space-y-3">
                    @foreach($evaluation->livestock as $ls)
                        @php
                            $lsLabel = $livestockConfig[$ls->livestock_type]['label'] ?? ucfirst(str_replace('_', ' ', $ls->livestock_type));
                        @endphp
                        <div class="p-4 bg-white border border-gray-200 rounded-lg">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <span class="font-semibold text-sm text-gray-800">{{ $lsLabel }}</span>
                                    <span class="text-gray-500 text-sm"> — {{ number_format($ls->head_count) }} head</span>
                                    @if($ls->breed)
                                        <span class="text-gray-400 text-sm">({{ $ls->breed }})</span>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('commercial-evaluations.livestock.destroy', [$evaluation, $ls]) }}" class="inline" onsubmit="return confirm('Remove this livestock?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                                </form>
                            </div>

                            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-600">
                                @if($ls->breeding_stock_count)
                                    <span>Breeding stock: <strong>{{ number_format($ls->breeding_stock_count) }}</strong></span>
                                @endif
                                @if($ls->carrying_capacity_ha_per_lsu)
                                    <span>Carrying capacity: <strong>{{ $ls->carrying_capacity_ha_per_lsu }} ha/LSU</strong></span>
                                @endif
                                @if($ls->hectares_used)
                                    <span>Hectares used: <strong>{{ number_format($ls->hectares_used, 1) }} ha</strong></span>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs mt-1">
                                @if($ls->total_value)
                                    <span class="text-gray-700">Total herd value: <strong class="font-mono">{{ $formatZar($ls->total_value) }}</strong></span>
                                @endif
                                @if($ls->annual_revenue)
                                    <span class="text-green-700">Est. annual revenue: <strong class="font-mono">{{ $formatZar($ls->annual_revenue) }}</strong></span>
                                @endif
                                @if($ls->annual_cost)
                                    <span class="text-red-600">Cost: <strong class="font-mono">{{ $formatZar($ls->annual_cost) }}</strong>/yr</span>
                                @endif
                            </div>

                            @if($ls->notes)
                                <p class="text-xs text-gray-400 mt-1 italic">{{ $ls->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Livestock totals --}}
                @php
                    $totalHeadCount = $evaluation->livestock->sum('head_count');
                    $totalHerdValue = $evaluation->livestock->sum('total_value');
                    $totalLivestockRevenue = $evaluation->livestock->sum('annual_revenue');
                    $totalLivestockCost = $evaluation->livestock->sum('annual_cost');
                @endphp
                <div class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap gap-x-6 text-xs text-gray-500">
                    <span>Total head: <strong>{{ number_format($totalHeadCount) }}</strong></span>
                    <span>Total herd value: <strong class="font-mono">{{ $formatZar((int)$totalHerdValue) }}</strong></span>
                    <span>Total revenue: <strong class="font-mono text-green-700">{{ $formatZar((int)$totalLivestockRevenue) }}</strong></span>
                    <span>Total cost: <strong class="font-mono text-red-600">{{ $formatZar((int)$totalLivestockCost) }}</strong></span>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════
         COMPARABLE SALES
    ═══════════════════════════════════════════ --}}
    <div class="ds-status-card mb-6" x-data="{ showCompForm: false }">
        <div class="px-5 py-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700">Comparable Sales</h3>
                <button @click="showCompForm = !showCompForm" class="text-xs text-[#00b4d8] hover:text-[#0096b7] font-medium">
                    + Add Comparable
                </button>
            </div>

            {{-- Add comparable form --}}
            <div x-show="showCompForm" x-transition class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('commercial-evaluations.comparables.store', $evaluation) }}">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Address <span class="text-red-500">*</span></label>
                            <input type="text" name="address" required placeholder="Full address"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Suburb</label>
                            <input type="text" name="suburb" placeholder="Suburb"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Property Type <span class="text-red-500">*</span></label>
                            <input type="text" name="property_type" required placeholder="e.g. Guest House"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Size (m&sup2;)</label>
                            <input type="number" step="0.01" name="size_m2" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @if($evaluation->property_type === 'agricultural')
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Size (ha)</label>
                            <input type="number" step="0.0001" name="size_ha" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        @endif
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Sale Price (ZAR)</label>
                            <input type="number" step="0.01" name="sale_price" placeholder="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Sale Date</label>
                            <input type="date" name="sale_date"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Source</label>
                            <input type="text" name="source" placeholder="e.g. CMA Info, Agent knowledge"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Notes</label>
                            <input type="text" name="notes" placeholder="Optional"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="nexus-btn-primary text-xs">Add Comparable</button>
                        <button type="button" @click="showCompForm = false" class="text-xs text-gray-500">Cancel</button>
                    </div>
                </form>
            </div>

            @if($evaluation->comparables->isEmpty())
                <p class="text-sm text-gray-400">No comparable sales added yet.</p>
            @else
                @php
                    $avgPricePerM2 = $evaluation->comparables->whereNotNull('price_per_m2')->avg('price_per_m2');
                @endphp
                @if($avgPricePerM2)
                    <div class="mb-3 text-xs text-gray-500">
                        Average R/m&sup2;: <strong class="font-mono">{{ $formatZar((int)$avgPricePerM2) }}</strong>
                    </div>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Address</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Type</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Size</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">Sale Price</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500">R/m&sup2;</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Date</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500">Source</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($evaluation->comparables as $comp)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $comp->address }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $comp->property_type }}</td>
                                <td class="px-3 py-2 text-right text-xs">
                                    @if($comp->size_ha)
                                        {{ number_format($comp->size_ha, 2) }} ha
                                    @elseif($comp->size_m2)
                                        {{ number_format($comp->size_m2) }} m&sup2;
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($comp->sale_price) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs">{{ $formatZar($comp->price_per_m2) }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $comp->sale_date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-400">{{ $comp->source ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <form method="POST" action="{{ route('commercial-evaluations.comparables.destroy', [$evaluation, $comp]) }}" class="inline" onsubmit="return confirm('Remove this comparable?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         EVALUATION RESULTS
    ═══════════════════════════════════════════ --}}
    <div class="ds-status-card mb-6">
        <div class="px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Evaluation Results</h3>
            @if($evaluation->evaluated_at)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                    <div class="p-3 bg-gray-50 rounded-lg text-center">
                        <span class="text-xs text-gray-400 block mb-1">Low</span>
                        <span class="text-lg font-bold text-gray-800 font-mono">{{ $formatZar($evaluation->recommended_range_low) }}</span>
                    </div>
                    <div class="p-3 bg-emerald-50 rounded-lg text-center border border-emerald-200">
                        <span class="text-xs text-emerald-600 block mb-1">Mid (Recommended)</span>
                        <span class="text-lg font-bold text-emerald-700 font-mono">{{ $formatZar($evaluation->recommended_range_mid) }}</span>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg text-center">
                        <span class="text-xs text-gray-400 block mb-1">High</span>
                        <span class="text-lg font-bold text-gray-800 font-mono">{{ $formatZar($evaluation->recommended_range_high) }}</span>
                    </div>
                </div>
                <p class="text-xs text-gray-400">Primary method: {{ $evaluation->primary_method ?? '—' }} | Evaluated {{ $evaluation->evaluated_at->format('Y-m-d H:i') }}</p>
            @else
                <div class="text-center py-6">
                    <p class="text-sm text-gray-400 mb-3">No evaluation has been run yet.</p>
                    <p class="text-xs text-gray-300">Add financial data and comparable sales, then click "Run Evaluation" above.</p>
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
