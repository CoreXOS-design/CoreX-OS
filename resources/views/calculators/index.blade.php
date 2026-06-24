@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="calculatorsApp()">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="calc-hub-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Calculators</h1>
                <p class="text-sm text-white/60">Commission, bond repayments, transfer costs &amp; overpayment savings.</p>
            </div>
        </div>
    </div>

    {{-- Admin: Fee Scale Management --}}
    @permission('calculators.manage')
    <div class="ds-status-card">
        <div class="ds-section-header" style="margin-bottom:0.5rem;">Fee Scale Management (Admin)</div>
        <p class="text-sm mb-3" style="color: var(--text-secondary);">
            Upload the annual attorney cost sheet to update all calculator fees.
            Current fees effective: <strong>{{ $feeEffectiveDate ?? 'Default' }}</strong>
            | Source: <strong>{{ $feeSourceDocument ?? 'Built-in defaults' }}</strong>
        </p>
        <form action="{{ route('calculators.uploadFeeSheet') }}" method="POST"
              enctype="multipart/form-data" class="flex items-end gap-4 flex-wrap">
            @csrf
            <div>
                <label class="ds-label block mb-1">Cost Sheet PDF</label>
                <input type="file" name="fee_sheet" accept=".pdf" required
                       class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="ds-label block mb-1">Effective Date</label>
                <input type="date" name="effective_date"
                       value="{{ date('Y-01-01') }}"
                       class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary px-4 py-2 text-sm font-semibold rounded-md">
                Upload & Update Fees
            </button>
        </form>
        @if(session('fee_upload_success'))
            <div class="mt-3 rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <div class="flex-1">{{ session('fee_upload_success') }}</div>
            </div>
        @endif
        @if(session('fee_upload_error'))
            <div class="mt-3 rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                <div class="flex-1">{{ session('fee_upload_error') }}</div>
            </div>
        @endif
    </div>
    @endpermission

    {{-- 2-column grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- CARD 1: Commission Calculator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;" data-tour="calc-hub-commission">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Commission Calculator</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Sale Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                        <input type="text" x-model="comm.salePrice" @input="formatInput($event)" placeholder="e.g. 2,500,000"
                               class="calc-input w-full" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Commission Rate <span class="text-xs font-normal" style="color: var(--text-muted);">(excl. VAT)</span></label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" @click="comm.rate = 7.5; comm.customRate = ''"
                                :class="comm.rate == 7.5 && comm.customRate === '' ? 'calc-rate-active' : 'calc-rate'"
                                class="px-4 py-1.5 rounded-md text-sm font-semibold transition-all duration-300">
                            7.5% — Residential
                        </button>
                        <button type="button" @click="comm.rate = 10; comm.customRate = ''"
                                :class="comm.rate == 10 && comm.customRate === '' ? 'calc-rate-active' : 'calc-rate'"
                                class="px-4 py-1.5 rounded-md text-sm font-semibold transition-all duration-300">
                            10% — Commercial / Vacant Land
                        </button>
                        <input type="text" x-model="comm.customRate" @input="comm.rate = parseFloat(comm.customRate) || 0" placeholder="Custom %"
                               class="calc-input w-24" />
                    </div>
                </div>

                <button type="button" @click="calcCommission()" class="corex-btn-primary px-6 py-2 text-sm font-semibold rounded-md">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="comm.result" x-transition class="mt-4 pt-4 space-y-2" style="border-top: 1px solid var(--border);">
                <div class="flex justify-between"><span class="ds-label">Commission exc VAT</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.commission_exc_vat)"></span></div>
                <div class="flex justify-between"><span class="ds-label">VAT (15%)</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.vat)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Commission inc VAT</span><span class="ds-value font-bold" x-text="'R ' + fmt(comm.result?.commission_inc_vat)"></span></div>
                <div class="pt-2 mt-2 space-y-1" style="border-top: 1px solid var(--border);">
                    <div class="flex justify-between"><span class="ds-label">Agent split at 50%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_50)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Agent split at 60%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_60)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Agent split at 70%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_70)"></span></div>
                </div>
            </div>
        </div>

        {{-- CARD 2: Bond Repayment Calculator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;" data-tour="calc-hub-bond">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Bond Repayment</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Loan Amount</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                        <input type="text" x-model="bond.loanAmount" @input="formatInput($event)" placeholder="e.g. 1,500,000"
                               class="calc-input w-full" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Interest Rate (%)</label>
                    <input type="text" x-model="bond.interestRate" placeholder="e.g. 11.75"
                           class="calc-input w-full" />
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Current prime: {{ $primeRate }}%</div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Loan Term</label>
                    <select x-model="bond.termYears" class="calc-input w-full">
                        <template x-for="y in [10, 15, 20, 25, 30]" :key="y">
                            <option :value="y" x-text="y + ' years'" :selected="y === 20"></option>
                        </template>
                    </select>
                </div>

                <button type="button" @click="calcBond()" class="corex-btn-primary px-6 py-2 text-sm font-semibold rounded-md">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="bond.result" x-transition class="mt-4 pt-4 space-y-2" style="border-top: 1px solid var(--border);">
                <div class="flex justify-between items-baseline">
                    <span class="ds-label">Monthly repayment</span>
                    <span class="ds-value-lg" x-text="'R ' + fmt(bond.result?.monthly_repayment)"></span>
                </div>
                <div class="flex justify-between"><span class="ds-label">Total repaid over term</span><span class="ds-value" x-text="'R ' + fmt(bond.result?.total_repaid)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(bond.result?.total_interest)"></span></div>
                <div class="pt-2 mt-2 space-y-1" style="border-top: 1px solid var(--border);">
                    <div class="text-xs mb-1" style="color: var(--text-muted);">Comparison at higher rates:</div>
                    <div class="flex justify-between"><span class="ds-label" x-text="'At ' + (parseFloat(bond.interestRate) + 1).toFixed(2) + '%'"></span><span class="ds-value" x-text="'R ' + fmt(bond.result?.monthly_plus_1) + '/mo'"></span></div>
                    <div class="flex justify-between"><span class="ds-label" x-text="'At ' + (parseFloat(bond.interestRate) + 2).toFixed(2) + '%'"></span><span class="ds-value" x-text="'R ' + fmt(bond.result?.monthly_plus_2) + '/mo'"></span></div>
                </div>
            </div>
        </div>

        {{-- CARD 3: Transfer & Bond Cost Calculator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;" data-tour="calc-hub-transfer">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Transfer & Bond Costs</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Purchase Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                        <input type="text" x-model="costs.purchasePrice" @input="formatInput($event)" placeholder="e.g. 2,500,000"
                               class="calc-input w-full" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Buyer needs bond?</label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="costs.needsBond" class="rounded-md" style="accent-color: var(--brand-button, #0ea5e9);" />
                        <span class="text-sm" style="color: var(--text-secondary);" x-text="costs.needsBond ? 'Yes' : 'No'"></span>
                    </label>
                </div>

                <div x-show="costs.needsBond" x-transition>
                    <label class="ds-label block mb-1">Bond Amount</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                        <input type="text" x-model="costs.bondAmount" @input="formatInput($event)" placeholder="Default = purchase price"
                               class="calc-input w-full" />
                    </div>
                </div>

                <button type="button" @click="calcTransferCosts()" class="corex-btn-primary px-6 py-2 text-sm font-semibold rounded-md">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="costs.result" x-transition class="mt-4 pt-4 space-y-1" style="border-top: 1px solid var(--border);">
                {{-- Transfer section --}}
                <div class="text-xs font-bold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Transfer Costs</div>
                <div class="flex justify-between"><span class="ds-label">Conveyancing fee</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer?.conveyancing_fee)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Posts & petties</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer?.posts_petties)"></span></div>
                <div class="flex justify-between"><span class="ds-label">VAT (15%)</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer?.vat)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Deeds office</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer?.deeds_office)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Transfer duty</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer?.transfer_duty)"></span></div>
                <div class="flex justify-between pt-1 mt-1" style="border-top: 1px solid var(--border);"><span class="ds-label font-semibold">Total Transfer</span><span class="ds-value font-semibold" x-text="'R ' + fmt(costs.result?.transfer?.total)"></span></div>

                {{-- Bond section --}}
                <template x-if="costs.needsBond && costs.result?.bond">
                    <div class="mt-3 space-y-1">
                        <div class="text-xs font-bold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Bond Registration</div>
                        <div class="flex justify-between"><span class="ds-label">Conveyancing fee</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.bond?.conveyancing_fee)"></span></div>
                        <div class="flex justify-between"><span class="ds-label">Posts & petties</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.bond?.posts_petties)"></span></div>
                        <div class="flex justify-between"><span class="ds-label">VAT (15%)</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.bond?.vat)"></span></div>
                        <div class="flex justify-between"><span class="ds-label">Deeds office</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.bond?.deeds_office)"></span></div>
                        <div class="flex justify-between pt-1 mt-1" style="border-top: 1px solid var(--border);"><span class="ds-label font-semibold">Total Bond</span><span class="ds-value font-semibold" x-text="'R ' + fmt(costs.result?.bond?.total)"></span></div>
                    </div>
                </template>

                {{-- Grand total --}}
                <div class="flex justify-between pt-2 mt-3" style="border-top: 1px solid var(--border);">
                    <span class="ds-label font-bold">GRAND TOTAL</span>
                    <span class="ds-value-lg" x-text="'R ' + fmt(costs.result?.grand_total)"></span>
                </div>

                {{-- Additional costs toggle --}}
                <div class="mt-3" x-data="{ showAdditional: false }">
                    <button type="button" @click="showAdditional = !showAdditional"
                            class="text-xs font-semibold transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">
                        <span x-text="showAdditional ? '▼ Hide' : '▶ Show'"></span> additional costs
                    </button>
                    <div x-show="showAdditional" x-transition class="mt-2 rounded-md p-3 space-y-1 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">
                        <div class="flex justify-between"><span>Bank admin & initiation fees</span><span>~R 6,037.50</span></div>
                        <div class="flex justify-between"><span>FICA fees (per person)</span><span>R 1,100 + VAT</span></div>
                        <div class="flex justify-between"><span>Clearance certificate</span><span>R 950.00</span></div>
                        <div class="flex justify-between"><span>Misc (deed searches, electronic fees)</span><span>~R 1,800.00</span></div>
                        <div class="flex justify-between"><span>Lodgment fee (per deed/document)</span><span>R 50.00</span></div>
                        <div class="flex justify-between"><span>Sectional title insurance cert (if applicable)</span><span>~R 950.00</span></div>
                    </div>
                </div>

                <div class="text-xs rounded-md p-2 mt-3" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); color: var(--text-primary); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);">These are ESTIMATES based on guideline tariffs. Actual costs vary by attorney. Always request a formal quotation from your conveyancer. Fees based on Van Dyk &amp; Swart Inc. — Guideline Tariff 2025.</div>
            </div>
        </div>

    </div>

    {{-- CARD 5: Bond Overpayment Savings (full width) --}}
    <div class="ds-status-card" data-tour="calc-hub-overpayment">
        <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Bond Overpayment Savings</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="ds-label block mb-1">Loan Amount</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                    <input type="text" x-model="overpay.loanAmount" @input="formatInput($event)" placeholder="e.g. 1,500,000"
                           class="calc-input w-full" />
                </div>
            </div>

            <div>
                <label class="ds-label block mb-1">Interest Rate (%)</label>
                <input type="text" x-model="overpay.interestRate" placeholder="e.g. 11.75"
                       class="calc-input w-full" />
                <div class="text-xs mt-1" style="color: var(--text-muted);">Current prime: {{ $primeRate }}%</div>
            </div>

            <div>
                <label class="ds-label block mb-1">Loan Term</label>
                <select x-model="overpay.termYears" class="calc-input w-full">
                    <template x-for="y in [10, 15, 20, 25, 30]" :key="y">
                        <option :value="y" x-text="y + ' years'" :selected="y === 20"></option>
                    </template>
                </select>
            </div>

            <div>
                <label class="ds-label block mb-1">Extra Monthly Payment</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold" style="color: var(--text-muted);">R</span>
                    <input type="text" x-model="overpay.extraPayment" @input="formatInput($event)" placeholder="e.g. 500"
                           class="calc-input w-full" />
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <template x-for="amt in [250, 500, 1000, 2000, 5000]" :key="amt">
                        <button type="button" @click="overpay.extraPayment = amt.toLocaleString('en-ZA')"
                                class="calc-rate px-2.5 py-1 rounded-md text-xs font-semibold transition-all duration-300"
                                x-text="'R ' + amt.toLocaleString('en-ZA')">
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="button" @click="calcOverpayment()" class="corex-btn-primary px-6 py-2 text-sm font-semibold rounded-md">
                Calculate Savings
            </button>
        </div>

        {{-- Results --}}
        <div x-show="overpay.result" x-transition class="mt-6">
            {{-- Two-column comparison --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- LEFT: Normal Bond --}}
                <div class="rounded-md p-4 space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <h4 class="text-sm font-bold mb-3" style="color: var(--text-secondary);">Normal Bond</h4>
                    <div class="flex justify-between"><span class="ds-label">Monthly payment</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.monthly_payment)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Term</span><span class="ds-value" x-text="overpay.result?.normal?.term_years + ' years (' + overpay.result?.normal?.term_months + ' months)'"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total repaid</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.total_repaid)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.total_interest)"></span></div>
                </div>

                {{-- RIGHT: Accelerated --}}
                <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--brand-icon, #0ea5e9);">
                    <h4 class="text-sm font-bold mb-3" style="color: var(--brand-icon, #0ea5e9);" x-text="'With Extra R ' + fmt(parseAmount(overpay.extraPayment)) + '/month'"></h4>
                    <div class="flex justify-between"><span class="ds-label">Monthly payment</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.monthly_payment)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Paid off in</span><span class="ds-value" x-text="overpay.result?.accelerated?.term_years + ' years ' + overpay.result?.accelerated?.term_remaining_months + ' months'"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total repaid</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.total_repaid)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.total_interest)"></span></div>
                </div>
            </div>

            {{-- Savings Summary --}}
            <div class="mt-4 rounded-md p-5" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-2xl font-bold" style="color: var(--ds-green, #059669);" x-text="overpay.result?.savings?.years_saved + ' years ' + overpay.result?.savings?.months_saved_remainder + ' months'"></div>
                        <div class="text-sm mt-1" style="color: var(--text-secondary);">Time saved</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold" style="color: var(--ds-green, #059669);" x-text="'R ' + fmt(overpay.result?.savings?.interest_saved)"></div>
                        <div class="text-sm mt-1" style="color: var(--text-secondary);">Interest saved</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold" style="color: var(--ds-green, #059669);" x-text="overpay.result?.savings?.interest_saved_pct + '%'"></div>
                        <div class="text-sm mt-1" style="color: var(--text-secondary);">Less interest paid</div>
                    </div>
                </div>
            </div>

            {{-- Comparison Table Toggle --}}
            <div class="mt-4">
                <button type="button" @click="overpay.showTable = !overpay.showTable"
                        class="text-sm font-semibold transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">
                    <span x-text="overpay.showTable ? '▼ Hide' : '▶ Show'"></span> year-by-year comparison table
                </button>

                <div x-show="overpay.showTable" x-transition class="mt-3 overflow-x-auto">
                    <table class="ds-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left px-3 py-2">Year</th>
                                <th class="text-right px-3 py-2">Normal Balance</th>
                                <th class="text-right px-3 py-2">Accelerated Balance</th>
                                <th class="text-right px-3 py-2">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(nb, i) in overpay.result?.yearly_comparison?.normal || []" :key="i">
                                <tr>
                                    <td class="px-3 py-1.5 font-medium" x-text="i + 1"></td>
                                    <td class="px-3 py-1.5 text-right" x-text="'R ' + fmt(nb)"></td>
                                    <td class="px-3 py-1.5 text-right" x-text="'R ' + fmt(overpay.result?.yearly_comparison?.accelerated[i])"></td>
                                    <td class="px-3 py-1.5 text-right font-semibold" style="color: var(--ds-green, #059669);" x-text="'R ' + fmt(nb - (overpay.result?.yearly_comparison?.accelerated[i] || 0))"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
/* Calculator inputs — theme-aware */
.calc-input {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text-primary);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    transition: all 300ms;
    outline: none;
}
.calc-input:focus {
    border-color: var(--brand-button, #0ea5e9);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button, #0ea5e9) 20%, transparent);
}
/* Rate toggle buttons — theme-aware */
.calc-rate {
    background: var(--surface-2);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}
.calc-rate:hover {
    background: var(--surface);
    color: var(--text-primary);
    border-color: var(--brand-icon, #0ea5e9);
}
.calc-rate-active {
    background: var(--brand-default, #0b2a4a);
    color: #fff;
    border: 1px solid var(--brand-default, #0b2a4a);
}
</style>

<script>
function calculatorsApp() {
    return {
        comm: {
            salePrice: '',
            rate: 7.5,
            customRate: '',
            result: null,
        },
        bond: {
            loanAmount: '',
            interestRate: '{{ $primeRate }}',
            termYears: 20,
            result: null,
        },
        costs: {
            purchasePrice: '',
            needsBond: true,
            bondAmount: '',
            result: null,
        },
        overpay: {
            loanAmount: '',
            interestRate: '{{ $primeRate }}',
            termYears: 20,
            extraPayment: '',
            result: null,
            showTable: false,
        },

        parseAmount(val) {
            if (!val) return 0;
            let s = String(val).replace(/[Rr\s,]/g, '');
            // Handle "2.5m" or "2.5M"
            const mMatch = s.match(/^([\d.]+)[mM]$/);
            if (mMatch) return parseFloat(mMatch[1]) * 1000000;
            return parseFloat(s) || 0;
        },

        formatInput(event) {
            let el = event.target;
            let raw = el.value.replace(/[^0-9]/g, '');
            if (raw === '') { return; }
            el.value = Number(raw).toLocaleString('en-ZA');
            // Update the bound model
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },

        fmt(val) {
            if (val === null || val === undefined) return '0';
            return Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async post(url, body) {
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(body),
            });
            return resp.json();
        },

        async calcCommission() {
            const rate = this.comm.customRate !== '' ? parseFloat(this.comm.customRate) : this.comm.rate;
            const res = await this.post('{{ route("calculators.commission") }}', {
                sale_price: this.parseAmount(this.comm.salePrice),
                commission_rate: rate,
            });
            if (res.ok) this.comm.result = res;
        },

        async calcBond() {
            const res = await this.post('{{ route("calculators.bond") }}', {
                loan_amount: this.parseAmount(this.bond.loanAmount),
                interest_rate: parseFloat(this.bond.interestRate) || 0,
                term_years: parseInt(this.bond.termYears) || 20,
            });
            if (res.ok) this.bond.result = res;
        },

        async calcTransferCosts() {
            const bondAmt = this.costs.bondAmount ? this.parseAmount(this.costs.bondAmount) : this.parseAmount(this.costs.purchasePrice);
            const res = await this.post('{{ route("calculators.transferCosts") }}', {
                purchase_price: this.parseAmount(this.costs.purchasePrice),
                needs_bond: this.costs.needsBond,
                bond_amount: bondAmt,
            });
            if (res.ok) this.costs.result = res;
        },

        async calcOverpayment() {
            const res = await this.post('{{ route("calculators.bondOverpayment") }}', {
                loan_amount: this.parseAmount(this.overpay.loanAmount),
                interest_rate: parseFloat(this.overpay.interestRate) || 0,
                term_years: parseInt(this.overpay.termYears) || 20,
                extra_payment: this.parseAmount(this.overpay.extraPayment),
            });
            if (res.ok) {
                this.overpay.result = res;
                this.overpay.showTable = false;
            }
        },
    };
}
</script>
@endsection
