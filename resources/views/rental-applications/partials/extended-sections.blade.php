{{--
    Fuller application (26 Oct 2026) - rental-applications.md "Fuller application". The facts and blocks a letting agency's own
    paper application asks that the first V8 form did not: tax number, how married, employment length and pay day, previous
    landlord, banking, judgments, vehicle, a co-applicant / spouse, the other people who will live there, pets, and the tenant's own
    monthly income and expenses.

    ONE partial, included by BOTH the applicant's form (rental-applications/public/show) and the agent's capture form
    (corex/rental-applications/show), so the two can never drift. Every scalar field honours the agency's field settings through
    $fieldConfig (hidden / relabelled / reordered / compulsory); the four blocks are single registry entries, so they can be hidden or
    made compulsory the same way. Everything posts in the form's one POST (nested names, which the autosave already nests).

    @param \App\Models\RentalApplication $application
    @param array  $fieldConfig       RentalApplication::displayFieldConfig() / resolvedFieldConfigFor()
    @param array  $requiredFieldKeys effective required keys (the agent form passes none - it never enforces)
    @param string $mode              'public' | 'agent'
--}}
@php
    $mode = $mode ?? 'public';
    $requiredFieldKeys = $requiredFieldKeys ?? [];
    $isPublic = $mode === 'public';
    $tag = $isPublic ? 'section' : 'div';
    $on = fn (string $key): bool => (bool) ($fieldConfig[$key]['shown'] ?? true);
    $label = fn (string $key): string => (string) ($fieldConfig[$key]['label'] ?? \Illuminate\Support\Str::headline($key));
    $hint = fn (string $key): ?string => $fieldConfig[$key]['help_text'] ?? null;
    $ord = fn (string $key) => $fieldConfig[$key]['order'] ?? null;
    $req = fn (string $key): bool => in_array($key, $requiredFieldKeys, true);
    $h2 = $isPublic ? 'font-semibold text-slate-700 mb-3' : 'text-sm font-semibold mb-3';
    $h2Style = $isPublic ? '' : 'color: var(--text-primary);';
    $input = 'w-full rounded-lg border px-3 py-2 text-sm border-slate-300';
    $anyOn = fn (array $keys): bool => collect($keys)->contains(fn ($k) => $on($k));
    $co = (array) old('co_applicant', $application->co_applicant ?? []);
    $coOccupants = old('co_occupants', $application->co_occupants ?? []);
    $pets = old('pets', $application->pets ?? []);
    $afford = (array) old('affordability', $application->affordability ?? []);
    $incomeLines = collect($afford['income'] ?? [])->values()->all();
    $expenseLines = collect($afford['expenses'] ?? [])->values()->all();
    if ($incomeLines === []) {
        $incomeLines = array_map(fn ($l) => ['label' => $l, 'amount' => ''], \App\Models\RentalApplication::AFFORDABILITY_INCOME_LINES);
    }
    if ($expenseLines === []) {
        $expenseLines = array_map(fn ($l) => ['label' => $l, 'amount' => ''], \App\Models\RentalApplication::AFFORDABILITY_EXPENSE_LINES);
    }
    $coRegimeOptions = \App\Models\RentalApplication::MARRIAGE_REGIMES;
@endphp

{{-- ── Tax, employment length, previous landlord ─────────────────────────────────────────────── --}}
@if($anyOn(['income_tax_no', 'marriage_regime', 'years_employed', 'pay_date', 'previous_landlord_name', 'previous_landlord_tel']))
<{{ $tag }} @if($isPublic) data-progress-section="Tax, employment length and previous landlord" @endif data-qa="ext-personal">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">More about you</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @if($on('income_tax_no'))
        <x-rental-application-field name="income_tax_no" :label="$label('income_tax_no')" :hint="$hint('income_tax_no')" :order="$ord('income_tax_no')" :value="$application->income_tax_no" :required="$req('income_tax_no')" />
        @endif
        @if($on('marriage_regime'))
        <div style="@if($ord('marriage_regime') !== null)order: {{ (int) $ord('marriage_regime') }}@endif">
            <label class="block text-xs text-slate-500 mb-1">{{ $label('marriage_regime') }}</label>
            @php $regimeValue = old('marriage_regime', $application->marriage_regime); @endphp
            <select name="marriage_regime" class="{{ $input }}">
                <option value="">— Select —</option>
                @foreach($coRegimeOptions as $opt)
                    <option value="{{ $opt }}" @selected($regimeValue === $opt)>{{ $opt }}</option>
                @endforeach
                @if($regimeValue && ! in_array($regimeValue, $coRegimeOptions, true))
                    <option value="{{ $regimeValue }}" selected>{{ $regimeValue }} (previously recorded)</option>
                @endif
            </select>
            <p class="text-[11px] text-slate-400 mt-1">{{ $hint('marriage_regime') ?? 'Only if you are married.' }}</p>
            @error('marriage_regime') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        @endif
        @if($on('years_employed'))
        <x-rental-application-field name="years_employed" :label="$label('years_employed')" :hint="$hint('years_employed')" :order="$ord('years_employed')" :value="$application->years_employed" :required="$req('years_employed')" />
        @endif
        @if($on('pay_date'))
        <x-rental-application-field name="pay_date" :label="$label('pay_date')" :hint="$hint('pay_date') ?? 'For example: the 25th, or the last working day.'" :order="$ord('pay_date')" :value="$application->pay_date" :required="$req('pay_date')" />
        @endif
        @if($on('previous_landlord_name'))
        <x-rental-application-field name="previous_landlord_name" :label="$label('previous_landlord_name')" :hint="$hint('previous_landlord_name')" :order="$ord('previous_landlord_name')" :value="$application->previous_landlord_name" :required="$req('previous_landlord_name')" />
        @endif
        @if($on('previous_landlord_tel'))
        <x-rental-application-field name="previous_landlord_tel" :label="$label('previous_landlord_tel')" :hint="$hint('previous_landlord_tel')" :order="$ord('previous_landlord_tel')" :value="$application->previous_landlord_tel" :required="$req('previous_landlord_tel')" />
        @endif
    </div>
</{{ $tag }}>
@endif

{{-- ── Banking, judgments, vehicle ───────────────────────────────────────────────────────────── --}}
@if($anyOn(['bank_name', 'bank_account_no', 'judgments', 'judgments_details', 'car_make', 'car_colour', 'car_year', 'car_reg']))
<{{ $tag }} @if($isPublic) data-progress-section="Banking and vehicle" @endif data-qa="ext-banking" x-data="{ judgments: {{ Js::from(old('judgments', $application->judgments)) }} }">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">Banking and vehicle</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @if($on('bank_name'))
        <x-rental-application-field name="bank_name" :label="$label('bank_name')" :hint="$hint('bank_name')" :order="$ord('bank_name')" :value="$application->bank_name" :required="$req('bank_name')" />
        @endif
        @if($on('bank_account_no'))
        <x-rental-application-field name="bank_account_no" :label="$label('bank_account_no')" :hint="$hint('bank_account_no')" :order="$ord('bank_account_no')" :value="$application->bank_account_no" :required="$req('bank_account_no')" />
        @endif
        @if($on('judgments'))
        <div style="@if($ord('judgments') !== null)order: {{ (int) $ord('judgments') }}@endif">
            <label class="block text-xs text-slate-500 mb-1">{{ $label('judgments') }}@if($req('judgments')) *@endif</label>
            <select name="judgments" x-model="judgments" class="{{ $input }}" @if($req('judgments')) required aria-required="true" @endif>
                <option value="">— Select —</option>
                <option value="no">No</option>
                <option value="yes">Yes</option>
            </select>
            @error('judgments') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        @endif
        @if($on('judgments_details'))
        <div x-show="judgments === 'yes'" x-cloak style="@if($ord('judgments_details') !== null)order: {{ (int) $ord('judgments_details') }}@endif">
            <label class="block text-xs text-slate-500 mb-1">{{ $label('judgments_details') }}</label>
            <input type="text" name="judgments_details" value="{{ old('judgments_details', $application->judgments_details) }}" class="{{ $input }}">
            @error('judgments_details') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        @endif
        @foreach(['car_make', 'car_colour', 'car_year', 'car_reg'] as $carKey)
            @if($on($carKey))
            <x-rental-application-field :name="$carKey" :label="$label($carKey)" :hint="$hint($carKey)" :order="$ord($carKey)" :value="$application->{$carKey}" :required="$req($carKey)" />
            @endif
        @endforeach
    </div>
</{{ $tag }}>
@endif

{{-- ── Co-applicant ──────────────────────────────────────────────────────────────────────────────
     AT-517 - the Co-applicant is asked what the Main applicant is asked, in the Main applicant's order, under the agency's
     own wording / hidden / compulsory settings for each field (RentalApplication::coApplicantFieldDefs reads them from the
     Main applicant's definition - there is no second copy of the rules). The conditional groups behave as they do for the
     Main applicant: landlord details only while renting, employer details only when permanently employed, spouse details only
     for a marital status that implies a spouse. Their signatures are further down the form (public/show). --}}
@if($on('co_applicant'))
@php
    $coDefs = \App\Models\RentalApplication::coApplicantFieldDefs($fieldConfig, $requiredFieldKeys);
    $coMaritalOptions = $maritalStatusOptions ?? \App\Models\RentalApplicationQualifyingSetting::maritalStatusOptionsFor($application->agency_id);
    $coSpouseStatuses = collect($coMaritalOptions)->filter(fn ($o) => $o['implies_spouse'] ?? false)->pluck('label')->map(fn ($l) => mb_strtolower(trim((string) $l)))->values()->all();
    $coInitial = [
        'living' => (string) ($co['current_living_situation'] ?? ''),
        'employment' => (string) ($co['employment_type'] ?? ''),
        'marital' => mb_strtolower(trim((string) ($co['marital_status'] ?? ''))),
        'judgments' => (string) ($co['judgments'] ?? ''),
        'stillLiving' => ! empty($co['current_rental_still_living']),
        'spouseStatuses' => $coSpouseStatuses,
        'any' => ! empty(array_filter($co, fn ($v) => $v !== null && $v !== '')) || ! empty($application->co_applicant_contact_id),
    ];
    $coGroupShow = [
        'renting' => "living === 'renting'",
        'employed' => "employment === 'permanently_employed'",
        'married' => 'spouseStatuses.includes(marital)',
    ];
@endphp
<{{ $tag }} @if($isPublic) data-progress-section="Co-applicant" @endif data-qa="ext-co-applicant"
    x-data="{ ...{{ Js::from($coInitial) }}, refreshAny() { this.any = !![...$el.querySelectorAll('[name^=\'co_applicant[\']')].find(e => (e.type === 'checkbox' ? e.checked : String(e.value || '').trim() !== '')); $dispatch('co-applicant-changed', this.any); } }"
    @input="refreshAny()" @change="refreshAny()" x-init="$nextTick(() => $dispatch('co-applicant-changed', any))">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">{{ $label('co_applicant') }}@if($req('co_applicant')) *@endif</h2>
    <p class="text-[11px] text-slate-400 mb-3">{{ $hint('co_applicant') ?? 'Complete this if your spouse or someone else is applying with you. Leave it blank if you are applying alone. Whoever is added here signs this form too.' }}</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @foreach($coDefs as $coKey => $def)
            @continue(! $def['shown'])
            @php
                $coValue = $co[$coKey] ?? '';
                $coKind = $def['kind'];
                $coName = 'co_applicant[' . $coKey . ']';
                $coShowExpr = $coGroupShow[$def['group']] ?? null;
                if ($coKey === 'judgments_details') { $coShowExpr = "judgments === 'yes'"; }
                $coStar = $isPublic && $def['required'] ? ' *' : '';
            @endphp
            <div @if($coShowExpr) x-show="{{ $coShowExpr }}" x-cloak @endif class="{{ in_array($coKind, ['long'], true) ? 'sm:col-span-2' : '' }}">
                @if($coKind === 'bool')
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 mt-5">
                        <input type="checkbox" name="{{ $coName }}" value="1" x-model="stillLiving" @checked(! empty($coValue)) class="rounded border-slate-300">
                        {{ $def['label'] }}
                    </label>
                @else
                    <label class="block text-xs text-slate-500 mb-1">{{ $def['label'] }}{{ $coStar }}</label>
                    @if($coKind === 'yesno')
                        <select name="{{ $coName }}" x-model="judgments" class="{{ $input }}">
                            <option value="">— Select —</option>
                            <option value="no">No</option>
                            <option value="yes">Yes</option>
                        </select>
                    @elseif($coKind === 'regime')
                        <select name="{{ $coName }}" class="{{ $input }}">
                            <option value="">— Select —</option>
                            @foreach($coRegimeOptions as $opt)
                                <option value="{{ $opt }}" @selected($coValue === $opt)>{{ $opt }}</option>
                            @endforeach
                        </select>
                    @elseif($coKind === 'marital')
                        <select name="{{ $coName }}" x-model="marital" class="{{ $input }}">
                            <option value="">— Select —</option>
                            @foreach($coMaritalOptions as $opt)
                                <option value="{{ mb_strtolower(trim((string) $opt['label'])) }}" @selected(mb_strtolower(trim((string) $coValue)) === mb_strtolower(trim((string) $opt['label'])))>{{ $opt['label'] }}</option>
                            @endforeach
                            @if($coValue !== '' && ! collect($coMaritalOptions)->contains(fn ($o) => mb_strtolower(trim((string) $o['label'])) === mb_strtolower(trim((string) $coValue))))
                                <option value="{{ mb_strtolower(trim((string) $coValue)) }}" selected>{{ $coValue }} (previously recorded)</option>
                            @endif
                        </select>
                    @elseif($coKind === 'living')
                        <select name="{{ $coName }}" x-model="living" class="{{ $input }}">
                            <option value="">— Select —</option>
                            @foreach(\App\Models\RentalApplication::CURRENT_LIVING_SITUATION_LABELS as $val => $text)
                                <option value="{{ $val }}" @selected($coValue === $val)>{{ $text }}</option>
                            @endforeach
                        </select>
                    @elseif($coKind === 'employment')
                        <select name="{{ $coName }}" x-model="employment" class="{{ $input }}">
                            <option value="">— Select —</option>
                            @foreach(\App\Models\RentalApplication::EMPLOYMENT_TYPE_LABELS as $val => $text)
                                <option value="{{ $val }}" @selected($coValue === $val)>{{ $text }}</option>
                            @endforeach
                        </select>
                    @elseif($coKind === 'long')
                        <textarea name="{{ $coName }}" rows="2" class="{{ $input }}">{{ $coValue }}</textarea>
                    @elseif($coKind === 'date')
                        <input type="date" name="{{ $coName }}" value="{{ $coValue }}" @if($coKey === 'current_rental_to') :disabled="stillLiving" @endif class="{{ $input }}">
                    @elseif($coKind === 'int')
                        <input type="number" min="1" max="31" name="{{ $coName }}" value="{{ $coValue }}" class="{{ $input }}">
                    @else
                        <input type="{{ $coKind === 'email' ? 'email' : 'text' }}" name="{{ $coName }}" value="{{ $coValue }}" @if($coKind === 'money') inputmode="decimal" @endif class="{{ $input }}">
                    @endif
                    @if($def['help_text'])<p class="text-[11px] text-slate-400 mt-1">{{ $def['help_text'] }}</p>@endif
                @endif
                @error('co_applicant.' . $coKey) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>
    @error('co_applicant') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</{{ $tag }}>
@endif

{{-- ── Co-occupants and pets ─────────────────────────────────────────────────────────────────── --}}
@if($on('co_occupants'))
<{{ $tag }} @if($isPublic) data-progress-section="Other occupants" @endif data-qa="ext-co-occupants"
    x-data="{ rows: {{ Js::from(count((array) $coOccupants) ? array_values((array) $coOccupants) : [['name' => '', 'relationship' => '', 'age' => '']]) }} }">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">{{ $label('co_occupants') }}@if($req('co_occupants')) *@endif</h2>
    <p class="text-[11px] text-slate-400 mb-3">{{ $hint('co_occupants') ?? 'Everyone who will live in the property besides you and your co-applicant. Add a row for each person.' }}</p>
    <div class="space-y-2">
        <template x-for="(row, i) in rows" :key="i">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-5"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Name</label>
                    <input type="text" :name="'co_occupants[' + i + '][name]'" x-model="row.name" class="{{ $input }}"></div>
                <div class="col-span-4"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Relationship to you</label>
                    <input type="text" :name="'co_occupants[' + i + '][relationship]'" x-model="row.relationship" class="{{ $input }}"></div>
                <div class="col-span-2"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Age</label>
                    <input type="text" inputmode="numeric" :name="'co_occupants[' + i + '][age]'" x-model="row.age" class="{{ $input }}"></div>
                <div class="col-span-1"><button type="button" class="text-xs text-red-600 pb-2" @click="rows.length > 1 ? rows.splice(i, 1) : (rows[0] = {name: '', relationship: '', age: ''})" aria-label="Remove this person">&times;</button></div>
            </div>
        </template>
    </div>
    <button type="button" class="text-xs mt-2 font-medium text-sky-700" @click="rows.push({name: '', relationship: '', age: ''})" data-qa="add-co-occupant">+ Add another person</button>
    @error('co_occupants') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</{{ $tag }}>
@endif

@if($on('pets'))
<{{ $tag }} @if($isPublic) data-progress-section="Pets" @endif data-qa="ext-pets"
    x-data="{ rows: {{ Js::from(count((array) $pets) ? array_values((array) $pets) : [['type' => '', 'breed' => '', 'size' => '']]) }} }">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">{{ $label('pets') }}@if($req('pets')) *@endif</h2>
    <p class="text-[11px] text-slate-400 mb-3">{{ $hint('pets') ?? 'Leave this blank if you have no pets.' }}</p>
    <div class="space-y-2">
        <template x-for="(row, i) in rows" :key="i">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-4"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Type (dog, cat ...)</label>
                    <input type="text" :name="'pets[' + i + '][type]'" x-model="row.type" class="{{ $input }}"></div>
                <div class="col-span-4"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Breed</label>
                    <input type="text" :name="'pets[' + i + '][breed]'" x-model="row.breed" class="{{ $input }}"></div>
                <div class="col-span-3"><label class="block text-xs text-slate-500 mb-1" x-show="i === 0">Size</label>
                    <input type="text" :name="'pets[' + i + '][size]'" x-model="row.size" class="{{ $input }}"></div>
                <div class="col-span-1"><button type="button" class="text-xs text-red-600 pb-2" @click="rows.length > 1 ? rows.splice(i, 1) : (rows[0] = {type: '', breed: '', size: ''})" aria-label="Remove this pet">&times;</button></div>
            </div>
        </template>
    </div>
    <button type="button" class="text-xs mt-2 font-medium text-sky-700" @click="rows.push({type: '', breed: '', size: ''})" data-qa="add-pet">+ Add another pet</button>
    @error('pets') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</{{ $tag }}>
@endif

{{-- ── Monthly income and expenses (the tenant's own schedule) ───────────────────────────────── --}}
@if($on('affordability'))
<{{ $tag }} @if($isPublic) data-progress-section="Monthly income and expenses" @endif data-qa="ext-affordability"
    x-data="{
        income: {{ Js::from($incomeLines) }},
        expenses: {{ Js::from($expenseLines) }},
        sum(rows) { return rows.reduce((t, r) => t + (parseFloat(String(r.amount || '').replace(/[^0-9.\-]/g, '')) || 0), 0); },
        money(v) { return 'R ' + v.toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    }">
    <h2 class="{{ $h2 }}" style="{{ $h2Style }}">{{ $label('affordability') }}@if($req('affordability')) *@endif</h2>
    <p class="text-[11px] text-slate-400 mb-3">{{ $hint('affordability') ?? 'What comes in and what goes out every month. Your agent uses this, together with your bank statements, to check that the rent is affordable.' }}</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold text-slate-600 mb-2">Income per month</p>
            <div class="space-y-2">
                <template x-for="(row, i) in income" :key="i">
                    <div class="grid grid-cols-12 gap-2">
                        <input type="text" :name="'affordability[income][' + i + '][label]'" x-model="row.label" class="col-span-7 {{ $input }}" aria-label="Income line">
                        <input type="text" inputmode="decimal" :name="'affordability[income][' + i + '][amount]'" x-model="row.amount" placeholder="R" class="col-span-5 {{ $input }}" aria-label="Amount per month">
                    </div>
                </template>
            </div>
            <button type="button" class="text-xs mt-2 font-medium text-sky-700" @click="income.push({label: '', amount: ''})">+ Add an income line</button>
        </div>
        <div>
            <p class="text-xs font-semibold text-slate-600 mb-2">Expenses per month</p>
            <div class="space-y-2">
                <template x-for="(row, i) in expenses" :key="i">
                    <div class="grid grid-cols-12 gap-2">
                        <input type="text" :name="'affordability[expenses][' + i + '][label]'" x-model="row.label" class="col-span-7 {{ $input }}" aria-label="Expense line">
                        <input type="text" inputmode="decimal" :name="'affordability[expenses][' + i + '][amount]'" x-model="row.amount" placeholder="R" class="col-span-5 {{ $input }}" aria-label="Amount per month">
                    </div>
                </template>
            </div>
            <button type="button" class="text-xs mt-2 font-medium text-sky-700" @click="expenses.push({label: '', amount: ''})">+ Add an expense line</button>
        </div>
    </div>
    <div class="mt-3 pt-2 text-xs text-slate-600 grid grid-cols-3 gap-2" style="border-top: 1px solid #e2e8f0;" data-qa="afford-totals">
        <p>Income <strong x-text="money(sum(income))"></strong></p>
        <p>Expenses <strong x-text="money(sum(expenses))"></strong></p>
        <p>Left over <strong x-text="money(sum(income) - sum(expenses))"></strong></p>
    </div>
    @error('affordability') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</{{ $tag }}>
@endif
