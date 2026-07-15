{{-- Shared financial-year field set — used by BOTH the "Add Financial Year" and the
     "Edit" forms on the commercial-evaluation show page. Pass $fin (a
     CommercialEvaluationFinancial) to pre-fill for edit, or leave null for a blank add.
     Money is stored in cents; inputs display Rand. Property-type conditionals mirror
     exactly what the evaluation can capture, so the same field set round-trips on edit
     and the server-side total recomputation never sees a hidden field as 0. --}}
@php
    $fin = $fin ?? null;
    $rand = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format($v / 100, 2, '.', ''), '0'), '.');
@endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
    <div>
        <label class="ds-label text-xs block mb-1">Financial Year <span class="text-red-500">*</span></label>
        <input type="text" name="financial_year" required placeholder="e.g. 2025"
               value="{{ old('financial_year', $fin->financial_year ?? '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Period (months)</label>
        <input type="number" name="period_months" min="1" max="24"
               value="{{ old('period_months', $fin->period_months ?? 12) }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
</div>

{{-- Income Section --}}
<h4 class="text-xs font-semibold uppercase tracking-wider mb-2 mt-4" style="color: var(--text-secondary);">Income (ZAR per annum)</h4>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
    <div>
        <label class="ds-label text-xs block mb-1">Gross Revenue</label>
        <input type="number" step="0.01" name="gross_revenue" placeholder="0"
               value="{{ old('gross_revenue', $fin ? $rand($fin->gross_revenue) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @if($isCommercialOrIndustrial)
    <div>
        <label class="ds-label text-xs block mb-1">Rental Income</label>
        <input type="number" step="0.01" name="rental_income" placeholder="0"
               value="{{ old('rental_income', $fin ? $rand($fin->rental_income) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
    @if($evaluation->property_type === 'hospitality')
    <div>
        <label class="ds-label text-xs block mb-1">Room Revenue</label>
        <input type="number" step="0.01" name="room_revenue" placeholder="0"
               value="{{ old('room_revenue', $fin ? $rand($fin->room_revenue) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Food & Beverage Revenue</label>
        <input type="number" step="0.01" name="food_beverage_revenue" placeholder="0"
               value="{{ old('food_beverage_revenue', $fin ? $rand($fin->food_beverage_revenue) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
    <div>
        <label class="ds-label text-xs block mb-1">Other Income</label>
        <input type="number" step="0.01" name="other_income" placeholder="0"
               value="{{ old('other_income', $fin ? $rand($fin->other_income) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @if($isCommercialOrIndustrial)
    <div>
        <label class="ds-label text-xs block mb-1">Vacancy Rate (%)</label>
        <input type="number" step="0.01" name="vacancy_rate" placeholder="0" min="0" max="100"
               value="{{ old('vacancy_rate', $fin && $fin->vacancy_rate !== null ? $fin->vacancy_rate : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
</div>

{{-- Expenses Section --}}
<h4 class="text-xs font-semibold uppercase tracking-wider mb-2 mt-4" style="color: var(--text-secondary);">Operating Expenses (ZAR per annum)</h4>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
    <div>
        <label class="ds-label text-xs block mb-1">Rates & Taxes</label>
        <input type="number" step="0.01" name="rates_taxes" placeholder="0"
               value="{{ old('rates_taxes', $fin ? $rand($fin->rates_taxes) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Insurance</label>
        <input type="number" step="0.01" name="insurance" placeholder="0"
               value="{{ old('insurance', $fin ? $rand($fin->insurance) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Utilities</label>
        <input type="number" step="0.01" name="utilities" placeholder="0"
               value="{{ old('utilities', $fin ? $rand($fin->utilities) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Security</label>
        <input type="number" step="0.01" name="security" placeholder="0"
               value="{{ old('security', $fin ? $rand($fin->security) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Maintenance & Repairs</label>
        <input type="number" step="0.01" name="maintenance" placeholder="0"
               value="{{ old('maintenance', $fin ? $rand($fin->maintenance) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    <div>
        <label class="ds-label text-xs block mb-1">Management Fees</label>
        <input type="number" step="0.01" name="management_fees" placeholder="0"
               value="{{ old('management_fees', $fin ? $rand($fin->management_fees) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @if(in_array($evaluation->property_type, ['hospitality', 'agricultural']))
    <div>
        <label class="ds-label text-xs block mb-1">Salaries & Wages</label>
        <input type="number" step="0.01" name="salaries_wages" placeholder="0"
               value="{{ old('salaries_wages', $fin ? $rand($fin->salaries_wages) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
    <div>
        <label class="ds-label text-xs block mb-1">Marketing</label>
        <input type="number" step="0.01" name="marketing" placeholder="0"
               value="{{ old('marketing', $fin ? $rand($fin->marketing) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @if($evaluation->property_type === 'hospitality')
    <div>
        <label class="ds-label text-xs block mb-1">Food & Beverage Costs</label>
        <input type="number" step="0.01" name="food_beverage_cost" placeholder="0"
               value="{{ old('food_beverage_cost', $fin ? $rand($fin->food_beverage_cost) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
    @if($evaluation->property_type === 'agricultural')
    <div>
        <label class="ds-label text-xs block mb-1">Farm Operating Costs</label>
        <input type="number" step="0.01" name="farm_operating_costs" placeholder="0"
               value="{{ old('farm_operating_costs', $fin ? $rand($fin->farm_operating_costs) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
    @endif
    <div>
        <label class="ds-label text-xs block mb-1">Other Expenses</label>
        <input type="number" step="0.01" name="other_expenses" placeholder="0"
               value="{{ old('other_expenses', $fin ? $rand($fin->other_expenses) : '') }}"
               class="w-full ds-field rounded-md px-3 py-1.5 text-sm">
    </div>
</div>
