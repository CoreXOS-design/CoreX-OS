@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 py-6" x-data="calculatorsApp()">

    {{-- Navy Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
        <h2 class="text-xl font-bold text-white leading-tight">Calculators</h2>
        <div class="text-sm text-white/60">Commission, bond repayments, transfer duty, costs & overpayment savings</div>
    </div>

    {{-- 2-column grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- CARD 1: Commission Calculator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Commission Calculator</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Sale Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500">R</span>
                        <input type="text" x-model="comm.salePrice" @input="formatInput($event)" placeholder="e.g. 2,500,000"
                               class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Commission Rate <span class="text-xs text-gray-400 font-normal">(excl. VAT)</span></label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" @click="comm.rate = 7.5; comm.customRate = ''"
                                :class="comm.rate == 7.5 && comm.customRate === '' ? 'bg-[#0b2a4a] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors">
                            7.5% — Residential
                        </button>
                        <button type="button" @click="comm.rate = 10; comm.customRate = ''"
                                :class="comm.rate == 10 && comm.customRate === '' ? 'bg-[#0b2a4a] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors">
                            10% — Commercial / Vacant Land
                        </button>
                        <input type="text" x-model="comm.customRate" @input="comm.rate = parseFloat(comm.customRate) || 0" placeholder="Custom %"
                               class="w-24 border border-slate-300 rounded-lg p-1.5 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <button type="button" @click="calcCommission()" class="nexus-btn-primary px-6 py-2 text-sm font-semibold rounded-lg">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="comm.result" x-transition class="mt-4 pt-4 border-t border-slate-200 space-y-2">
                <div class="flex justify-between"><span class="ds-label">Commission exc VAT</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.commission_exc_vat)"></span></div>
                <div class="flex justify-between"><span class="ds-label">VAT (15%)</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.vat)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Commission inc VAT</span><span class="ds-value font-bold" x-text="'R ' + fmt(comm.result?.commission_inc_vat)"></span></div>
                <div class="border-t border-slate-100 pt-2 mt-2 space-y-1">
                    <div class="flex justify-between"><span class="ds-label">Agent split at 50%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_50)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Agent split at 60%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_60)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Agent split at 70%</span><span class="ds-value" x-text="'R ' + fmt(comm.result?.agent_split_70)"></span></div>
                </div>
            </div>
        </div>

        {{-- CARD 2: Bond Repayment Calculator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Bond Repayment</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Loan Amount</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500">R</span>
                        <input type="text" x-model="bond.loanAmount" @input="formatInput($event)" placeholder="e.g. 1,500,000"
                               class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Interest Rate (%)</label>
                    <input type="text" x-model="bond.interestRate" placeholder="e.g. 11.75"
                           class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    <div class="text-xs text-gray-500 mt-1">Current prime: {{ $primeRate }}%</div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Loan Term</label>
                    <select x-model="bond.termYears" class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none">
                        <template x-for="y in [10, 15, 20, 25, 30]" :key="y">
                            <option :value="y" x-text="y + ' years'" :selected="y === 20"></option>
                        </template>
                    </select>
                </div>

                <button type="button" @click="calcBond()" class="nexus-btn-primary px-6 py-2 text-sm font-semibold rounded-lg">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="bond.result" x-transition class="mt-4 pt-4 border-t border-slate-200 space-y-2">
                <div class="flex justify-between items-baseline">
                    <span class="ds-label">Monthly repayment</span>
                    <span class="ds-value-lg" x-text="'R ' + fmt(bond.result?.monthly_repayment)"></span>
                </div>
                <div class="flex justify-between"><span class="ds-label">Total repaid over term</span><span class="ds-value" x-text="'R ' + fmt(bond.result?.total_repaid)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(bond.result?.total_interest)"></span></div>
                <div class="border-t border-slate-100 pt-2 mt-2 space-y-1">
                    <div class="text-xs text-gray-500 mb-1">Comparison at higher rates:</div>
                    <div class="flex justify-between"><span class="ds-label" x-text="'At ' + (parseFloat(bond.interestRate) + 1).toFixed(2) + '%'"></span><span class="ds-value" x-text="'R ' + fmt(bond.result?.monthly_plus_1) + '/mo'"></span></div>
                    <div class="flex justify-between"><span class="ds-label" x-text="'At ' + (parseFloat(bond.interestRate) + 2).toFixed(2) + '%'"></span><span class="ds-value" x-text="'R ' + fmt(bond.result?.monthly_plus_2) + '/mo'"></span></div>
                </div>
            </div>
        </div>

        {{-- CARD 3: Transfer Duty --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Transfer Duty</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Purchase Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500">R</span>
                        <input type="text" x-model="duty.purchasePrice" @input="formatInput($event)" placeholder="e.g. 2,500,000"
                               class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <button type="button" @click="calcTransferDuty()" class="nexus-btn-primary px-6 py-2 text-sm font-semibold rounded-lg">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="duty.result" x-transition class="mt-4 pt-4 border-t border-slate-200 space-y-2">
                <div class="flex justify-between items-baseline">
                    <span class="ds-label">Transfer Duty</span>
                    <span class="ds-value-lg" x-text="'R ' + fmt(duty.result?.transfer_duty)"></span>
                </div>
                <div class="flex justify-between"><span class="ds-label">Effective rate</span><span class="ds-value" x-text="duty.result?.effective_rate?.toFixed(2) + '%'"></span></div>
                <div class="mt-2 text-xs text-gray-600 bg-gray-50 rounded-lg p-2" x-text="duty.result?.bracket"></div>
                <div class="text-xs text-gray-500 mt-2">Transfer duty paid by the BUYER. Properties under R1.1M = R0 duty.</div>
            </div>
        </div>

        {{-- CARD 4: Transfer Cost Estimator --}}
        <div class="ds-status-card flex flex-col" style="min-height: 420px;">
            <h3 class="ds-section-header" style="margin-bottom:0.75rem;">Total Transfer Costs</h3>

            <div class="space-y-3 flex-1">
                <div>
                    <label class="ds-label block mb-1">Purchase Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500">R</span>
                        <input type="text" x-model="costs.purchasePrice" @input="formatInput($event)" placeholder="e.g. 2,500,000"
                               class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <div>
                    <label class="ds-label block mb-1">Buyer needs bond?</label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="costs.needsBond" class="rounded" />
                        <span class="text-sm text-gray-700" x-text="costs.needsBond ? 'Yes' : 'No'"></span>
                    </label>
                </div>

                <div x-show="costs.needsBond" x-transition>
                    <label class="ds-label block mb-1">Bond Amount</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500">R</span>
                        <input type="text" x-model="costs.bondAmount" @input="formatInput($event)" placeholder="Default = purchase price"
                               class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                    </div>
                </div>

                <button type="button" @click="calcTransferCosts()" class="nexus-btn-primary px-6 py-2 text-sm font-semibold rounded-lg">
                    Calculate
                </button>
            </div>

            {{-- Results --}}
            <div x-show="costs.result" x-transition class="mt-4 pt-4 border-t border-slate-200 space-y-2">
                <div class="flex justify-between"><span class="ds-label">Transfer duty</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.transfer_duty)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Conveyancing fees (est.)</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.conveyancing_fees)"></span></div>
                <div class="flex justify-between"><span class="ds-label">Deeds office & petties</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.deeds_office_petties)"></span></div>
                <div class="flex justify-between border-t border-slate-100 pt-2"><span class="ds-label">Subtotal (transfer)</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.subtotal_transfer)"></span></div>
                <div x-show="costs.needsBond" class="flex justify-between"><span class="ds-label">Bond registration</span><span class="ds-value" x-text="'R ' + fmt(costs.result?.bond_registration)"></span></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 mt-2">
                    <span class="ds-label font-bold">GRAND TOTAL</span>
                    <span class="ds-value-lg" x-text="'R ' + fmt(costs.result?.grand_total)"></span>
                </div>
                <div class="text-xs text-gray-500 mt-2">Estimates only — request formal quotation from conveyancer.</div>
            </div>
        </div>

    </div>

    {{-- CARD 5: Bond Overpayment Savings (full width) --}}
    <div class="ds-status-card mt-6">
        <h3 class="ds-section-header" style="margin-bottom:0.75rem;">🚀 Bond Overpayment Savings</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="ds-label block mb-1">Loan Amount</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-gray-500">R</span>
                    <input type="text" x-model="overpay.loanAmount" @input="formatInput($event)" placeholder="e.g. 1,500,000"
                           class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                </div>
            </div>

            <div>
                <label class="ds-label block mb-1">Interest Rate (%)</label>
                <input type="text" x-model="overpay.interestRate" placeholder="e.g. 11.75"
                       class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                <div class="text-xs text-gray-500 mt-1">Current prime: {{ $primeRate }}%</div>
            </div>

            <div>
                <label class="ds-label block mb-1">Loan Term</label>
                <select x-model="overpay.termYears" class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none">
                    <template x-for="y in [10, 15, 20, 25, 30]" :key="y">
                        <option :value="y" x-text="y + ' years'" :selected="y === 20"></option>
                    </template>
                </select>
            </div>

            <div>
                <label class="ds-label block mb-1">Extra Monthly Payment</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-gray-500">R</span>
                    <input type="text" x-model="overpay.extraPayment" @input="formatInput($event)" placeholder="e.g. 500"
                           class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:border-cyan-500 focus:outline-none" />
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <template x-for="amt in [250, 500, 1000, 2000, 5000]" :key="amt">
                        <button type="button" @click="overpay.extraPayment = amt.toLocaleString('en-ZA')"
                                class="px-2.5 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors"
                                x-text="'R ' + amt.toLocaleString('en-ZA')">
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="button" @click="calcOverpayment()" class="nexus-btn-primary px-6 py-2 text-sm font-semibold rounded-lg">
                Calculate Savings
            </button>
        </div>

        {{-- Results --}}
        <div x-show="overpay.result" x-transition class="mt-6">
            {{-- Two-column comparison --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- LEFT: Normal Bond --}}
                <div class="bg-gray-50 rounded-xl p-4 space-y-2">
                    <h4 class="text-sm font-bold text-gray-700 mb-3">Normal Bond</h4>
                    <div class="flex justify-between"><span class="ds-label">Monthly payment</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.monthly_payment)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Term</span><span class="ds-value" x-text="overpay.result?.normal?.term_years + ' years (' + overpay.result?.normal?.term_months + ' months)'"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total repaid</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.total_repaid)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.normal?.total_interest)"></span></div>
                </div>

                {{-- RIGHT: Accelerated --}}
                <div class="bg-white rounded-xl p-4 space-y-2 border-l-4" style="border-left-color: #06b6d4;">
                    <h4 class="text-sm font-bold text-cyan-700 mb-3" x-text="'With Extra R ' + fmt(parseAmount(overpay.extraPayment)) + '/month'"></h4>
                    <div class="flex justify-between"><span class="ds-label">Monthly payment</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.monthly_payment)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Paid off in</span><span class="ds-value" x-text="overpay.result?.accelerated?.term_years + ' years ' + overpay.result?.accelerated?.term_remaining_months + ' months'"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total repaid</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.total_repaid)"></span></div>
                    <div class="flex justify-between"><span class="ds-label">Total interest</span><span class="ds-value" x-text="'R ' + fmt(overpay.result?.accelerated?.total_interest)"></span></div>
                </div>
            </div>

            {{-- Savings Summary --}}
            <div class="mt-4 rounded-xl p-5" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 1px solid #6ee7b7;">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-2xl font-bold text-green-800" x-text="'⏱️ ' + overpay.result?.savings?.years_saved + ' years ' + overpay.result?.savings?.months_saved_remainder + ' months'"></div>
                        <div class="text-sm text-green-700 mt-1">Time saved</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-800" x-text="'💰 R ' + fmt(overpay.result?.savings?.interest_saved)"></div>
                        <div class="text-sm text-green-700 mt-1">Interest saved</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-800" x-text="'📊 ' + overpay.result?.savings?.interest_saved_pct + '%'"></div>
                        <div class="text-sm text-green-700 mt-1">Less interest paid!</div>
                    </div>
                </div>
            </div>

            {{-- Comparison Table Toggle --}}
            <div class="mt-4">
                <button type="button" @click="overpay.showTable = !overpay.showTable"
                        class="text-sm font-semibold text-cyan-700 hover:text-cyan-900 transition-colors">
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
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-1.5 font-medium" x-text="i + 1"></td>
                                    <td class="px-3 py-1.5 text-right" x-text="'R ' + fmt(nb)"></td>
                                    <td class="px-3 py-1.5 text-right" x-text="'R ' + fmt(overpay.result?.yearly_comparison?.accelerated[i])"></td>
                                    <td class="px-3 py-1.5 text-right font-semibold text-green-700" x-text="'R ' + fmt(nb - (overpay.result?.yearly_comparison?.accelerated[i] || 0))"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

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
        duty: {
            purchasePrice: '',
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

        async calcTransferDuty() {
            const res = await this.post('{{ route("calculators.transferDuty") }}', {
                purchase_price: this.parseAmount(this.duty.purchasePrice),
            });
            if (res.ok) this.duty.result = res;
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
