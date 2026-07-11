<x-app-layout>
    {{-- SETTLE_BLADE_FINGERPRINT: 2026-01-26_1602 --}}
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">Settlement &mdash; Deal #{{ $deal->deal_no }}</h2>
                    <div class="text-sm text-white/60">{{ $deal->property_address ?: 'No address' }}</div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('deals-dr2.index') }}"
                       class="inline-flex items-center rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                        &larr; Back
                    </a>
                    <a href="{{ route('deals-dr2.settle.print', $deal) }}" target="_blank"
                       class="inline-flex items-center rounded-xl bg-white/20 px-4 py-2 text-sm font-semibold text-white hover:bg-white/30">
                        Print Settlement
                    </a>
                    <button form="settleForm"
                            class="corex-btn-primary px-5 py-2.5 text-sm">
                        Save Settlement
                    </button>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if (session('status'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        {{-- Deal Summary --}}
        <div>
            <h2 class="ds-section-header">Deal Summary</h2>
            <div class="ds-section-sub mb-4">Commission totals (incl VAT).</div>

            @php $vatAmt = (float)$totalCommissionIncVat - (float)$totalCommissionExVat; $money = fn($v) => number_format((float)($v ?? 0), 2, '.', ','); @endphp

            <div class="ds-status-card">
                <div class="settle-key-totals">
                    <div class="text-center">
                        <div class="ds-label mb-1">Commission (Incl VAT)</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ $money($totalCommissionIncVat) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="ds-label mb-1">VAT ({{ (int)round(((float)$vatRate)*100) }}%)</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ $money($vatAmt) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="ds-label mb-1">Commission (Ex VAT)</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ $money($totalCommissionExVat) }}</div>
                    </div>
                </div>
            </div>
        </div>

<form id="settleForm" method="POST" action="{{ route('deals-dr2.settle.save', $deal) }}" class="space-y-6">
@csrf

        {{-- Commission Split --}}
        <div>
            <h2 class="ds-section-header">Commission Split</h2>
            <div class="ds-section-sub mb-4">Adjust Share %, Cut, PAYE, Deductions — values update live.</div>
        </div>

    {{-- SETTLE_MARKPAID_RELOCATED_2026_02_12 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

{{-- LISTING SIDE --}}
<div class="settle-col space-y-6 min-w-0">

    {{-- Listing Pool --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-navy);">
        <div class="ds-label">Listing Pool (Our share)</div>
        <div class="ds-value-xl mt-1" style="color:#0b2a4a">R <span class="js-pool" data-side="listing">{{ $money($listingPool) }}</span></div>
        <div class="text-xs text-gray-500 mt-1">External payable: R {{ $money($listingExternalPayable ?? 0) }}</div>
    </div>

    {{-- Listing Side Card --}}
    <div class="ds-status-card">
        <div class="flex items-center justify-between mb-3">
            <h3 class="ds-section-header" style="margin-bottom:0">Listing Side</h3>
            @if($deal->listing_external)
                <span class="ds-badge ds-badge-warning">External</span>
            @endif
        </div>

        @if($deal->listing_external)
            <div class="text-sm text-gray-600">
                Listing side is marked external — pool is R 0.
            </div>
        @else
            <div class="space-y-3">
                @foreach($listingRows as $r)
                    <div class="settle-row rounded-xl bg-white ring-1 ring-gray-200 p-4 md:p-3 shadow-sm"
                         data-side="listing"
                         data-user="{{ $r['user_id'] }}">

                        <div class="grid grid-cols-1 md:grid-cols-16 gap-3">
                            <div class="md:col-span-4">
                                <div class="font-semibold" style="color:#0b2a4a">{{ $r['name'] }}</div>
                                <div class="text-xs text-gray-400">
                                    Alloc: R <span class="js-allocated" data-raw="{{ (float)$r['allocated'] }}">{{ $money($r['allocated']) }}</span>
                                    &bull; Gross: R <span class="js-gross" data-raw="{{ (float)$r['gross'] }}">{{ $money($r['gross']) }}</span>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Share %</label>
                                <label class="md:hidden text-xs text-gray-500">Share %</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="listing_share[{{ $r['user_id'] }}]"
                                       value="{{ old('listing_share.'.$r['user_id'], $r['share_percent']) }}">
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Cut %</label>
                                <label class="md:hidden text-xs text-gray-500">Cut %</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="listing_agent_cut[{{ $r['user_id'] }}]"
                                       value="{{ old('listing_agent_cut.'.$r['user_id'], $r['agent_cut_percent']) }}">
                            </div>

                            <div class="md:col-span-5">
                                <label class="ds-label block mb-1">PAYE</label>
                                <label class="md:hidden text-xs text-gray-500">PAYE</label>
                                <div class="flex items-center gap-2 flex-nowrap">
                                    @php $pm = old('listing_paye_method.'.$r['user_id'], $r['paye_method']); @endphp
                                    <select class="w-32 shrink-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                            name="listing_paye_method[{{ $r['user_id'] }}]">
                                        <option value="percentage" {{ $pm === 'percentage' ? 'selected' : '' }}>%</option>
                                        <option value="fixed" {{ $pm === 'fixed' ? 'selected' : '' }}>Fixed</option>
                                    </select>

                                    <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                           type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                           name="listing_paye_value[{{ $r['user_id'] }}]"
                                           value="{{ old('listing_paye_value.'.$r['user_id'], $r['paye_value']) }}">
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    Calc: R <span class="js-paye" data-raw="{{ (float)$r['paye'] }}">{{ $money($r['paye']) }}</span>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Deduct</label>
                                <label class="md:hidden text-xs text-gray-500">Deduct</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="listing_deductions[{{ $r['user_id'] }}]"
                                       value="{{ old('listing_deductions.'.$r['user_id'], $r['deductions']) }}">
                            </div>

                            <div class="md:col-span-3 md:text-right">
                                <div class="ds-label block mb-1 text-right">Net</div>
                                <div class="md:hidden text-xs text-gray-500">Net</div>
                                <div class="text-lg font-extrabold" style="color:var(--ds-green)">
                                    R <span class="js-net" data-raw="{{ (float)$r['net'] }}">{{ $money($r['net']) }}</span>
                                </div>
                                <div class="text-xs text-gray-400">
                                    Company: R <span class="js-company" data-raw="{{ (float)$r['company'] }}">{{ $money($r['company']) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-2 grid grid-cols-1 md:grid-cols-16 gap-3">
                            <div class="md:col-span-12">
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="text"
                                       placeholder="Deduction reason (optional)"
                                       name="listing_deductions_description[{{ $r['user_id'] }}]"
                                       value="{{ old('listing_deductions_description.'.$r['user_id'], $r['deductions_description']) }}">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="text-xs text-gray-600 mt-3">Rule: listing shares must total 100.</div>
        @endif
    </div>
</div>

{{-- SELLING SIDE --}}
<div class="settle-col space-y-6 min-w-0">

    {{-- Selling Pool --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-green);">
        <div class="ds-label">Selling Pool (Our share)</div>
        <div class="ds-value-xl mt-1" style="color:#0b2a4a">R <span class="js-pool" data-side="selling">{{ $money($sellingPool) }}</span></div>
        <div class="text-xs text-gray-500 mt-1">External payable: R {{ $money($sellingExternalPayable ?? 0) }}</div>
    </div>

    {{-- Selling Side Card --}}
    <div class="ds-status-card">
        <div class="flex items-center justify-between mb-3">
            <h3 class="ds-section-header" style="margin-bottom:0">Selling Side</h3>
            @if($deal->selling_external)
                <span class="ds-badge ds-badge-warning">External</span>
            @endif
        </div>

        @if($deal->selling_external)
            <div class="text-sm text-gray-600">
                Selling side is marked external — pool is R 0.
            </div>
        @else
            <div class="space-y-3">
                @foreach($sellingRows as $r)
                    <div class="settle-row rounded-xl bg-white ring-1 ring-gray-200 p-4 md:p-3 shadow-sm"
                         data-side="selling"
                         data-user="{{ $r['user_id'] }}">

                        <div class="grid grid-cols-1 md:grid-cols-16 gap-3">
                            <div class="md:col-span-4">
                                <div class="font-semibold" style="color:#0b2a4a">{{ $r['name'] }}</div>
                                <div class="text-xs text-gray-400">
                                    Alloc: R <span class="js-allocated" data-raw="{{ (float)$r['allocated'] }}">{{ $money($r['allocated']) }}</span>
                                    &bull; Gross: R <span class="js-gross" data-raw="{{ (float)$r['gross'] }}">{{ $money($r['gross']) }}</span>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Share %</label>
                                <label class="md:hidden text-xs text-gray-500">Share %</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="selling_share[{{ $r['user_id'] }}]"
                                       value="{{ old('selling_share.'.$r['user_id'], $r['share_percent']) }}">
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Cut %</label>
                                <label class="md:hidden text-xs text-gray-500">Cut %</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="selling_agent_cut[{{ $r['user_id'] }}]"
                                       value="{{ old('selling_agent_cut.'.$r['user_id'], $r['agent_cut_percent']) }}">
                            </div>

                            <div class="md:col-span-5">
                                <label class="ds-label block mb-1">PAYE</label>
                                <label class="md:hidden text-xs text-gray-500">PAYE</label>
                                <div class="flex items-center gap-2 flex-nowrap">
                                    @php $pm = old('selling_paye_method.'.$r['user_id'], $r['paye_method']); @endphp
                                    <select class="w-32 shrink-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                            name="selling_paye_method[{{ $r['user_id'] }}]">
                                        <option value="percentage" {{ $pm === 'percentage' ? 'selected' : '' }}>%</option>
                                        <option value="fixed" {{ $pm === 'fixed' ? 'selected' : '' }}>Fixed</option>
                                    </select>

                                    <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                           type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                           name="selling_paye_value[{{ $r['user_id'] }}]"
                                           value="{{ old('selling_paye_value.'.$r['user_id'], $r['paye_value']) }}">
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    Calc: R <span class="js-paye" data-raw="{{ (float)$r['paye'] }}">{{ $money($r['paye']) }}</span>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="ds-label block mb-1">Deduct</label>
                                <label class="md:hidden text-xs text-gray-500">Deduct</label>
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="number" inputmode="decimal" min="0" placeholder="0.00" step="0.01"
                                       name="selling_deductions[{{ $r['user_id'] }}]"
                                       value="{{ old('selling_deductions.'.$r['user_id'], $r['deductions']) }}">
                            </div>

                            <div class="md:col-span-3 md:text-right">
                                <div class="ds-label block mb-1 text-right">Net</div>
                                <div class="md:hidden text-xs text-gray-500">Net</div>
                                <div class="text-lg font-extrabold" style="color:var(--ds-green)">
                                    R <span class="js-net" data-raw="{{ (float)$r['net'] }}">{{ $money($r['net']) }}</span>
                                </div>
                                <div class="text-xs text-gray-400">
                                    Company: R <span class="js-company" data-raw="{{ (float)$r['company'] }}">{{ $money($r['company']) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-2 grid grid-cols-1 md:grid-cols-16 gap-3">
                            <div class="md:col-span-12">
                                <input class="w-full flex-1 min-w-0 rounded-xl bg-white ring-1 ring-gray-300 px-3 py-2 md:py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                                       type="text"
                                       placeholder="Deduction reason (optional)"
                                       name="selling_deductions_description[{{ $r['user_id'] }}]"
                                       value="{{ old('selling_deductions_description.'.$r['user_id'], $r['deductions_description']) }}">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="text-xs text-gray-600 mt-3">Rule: selling shares must total 100.</div>
        @endif
    </div>
</div>

    </div>

    {{-- SETTLE_BLOCKS_PLACED_UNDER_SPLITS_2026_02_12 --}}
    <div>
        <h2 class="ds-section-header">Payment & Reconciliation</h2>
        <div class="ds-section-sub mb-4">Mark paid and verify checksum.</div>

        <div class="ds-status-card space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <label class="inline-flex items-center gap-2 flex-nowrap text-sm">
                    <input type="checkbox" name="mark_paid" value="1" {{ old('mark_paid') ? 'checked' : '' }}>
                    Mark deal commission status as "Paid"
                </label>

                <div class="text-xs text-gray-400">
                    Note: Invalid totals block saving. Paid deals lock.
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
                <div><span class="ds-label">Total Commission:</span> <span class="ds-value">R {{ $money($deal->total_commission) }}</span></div>
                <div><span class="ds-label">External Payable:</span> <span class="ds-value">R <span id="js-external-total">{{ $money($externalPayableTotal ?? 0) }}</span></span></div>
                <div><span class="ds-label">Company Portion:</span> <span class="ds-value">R <span id="js-company-total">{{ $money($totals['company']) }}</span></span></div>
                <div>
                    <span class="ds-label">Checksum:</span>
                    <span class="{{ $checksumOk ? 'text-green-700' : 'text-red-700' }} font-bold">
                        R <span id="js-checksum">{{ $money($checksumTotal) }}</span>
                        (<span id="js-checksum-status">{{ $checksumOk ? 'OK' : 'NOT OK' }}</span>)
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Agent Summary --}}
    <div>
        <h2 class="ds-section-header">Agent Summary</h2>
        <div class="ds-section-sub mb-4">Updates live as you edit values above.</div>

        <div class="ds-status-card overflow-hidden" style="padding:0">
            <div class="overflow-x-auto">
                <table class="ds-table min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-3">Agent</th>
                            <th class="text-right px-4 py-3">Allocated</th>
                            <th class="text-right px-4 py-3">Gross</th>
                            <th class="text-right px-4 py-3">PAYE</th>
                            <th class="text-right px-4 py-3">Deductions</th>
                            <th class="text-right px-4 py-3">Net</th>
                            <th class="text-right px-4 py-3">Print</th>
                        </tr>
                    </thead>
                    <tbody id="js-agent-summary-body">
                        @foreach(($agentSummary ?? []) as $s)
                            <tr class="agent-summary-row" data-user="{{ (int)$s['user_id'] }}">
                                <td class="px-4 py-3 font-medium" style="color:#0b2a4a">{{ $s['name'] }}</td>
                                <td class="px-4 py-3 text-right">R <span class="js-sum-allocated" data-raw="{{ (float)$s['allocated'] }}">{{ $money($s['allocated']) }}</span></td>
                                <td class="px-4 py-3 text-right">R <span class="js-sum-gross" data-raw="{{ (float)$s['gross'] }}">{{ $money($s['gross']) }}</span></td>
                                <td class="px-4 py-3 text-right">R <span class="js-sum-paye" data-raw="{{ (float)$s['paye'] }}">{{ $money($s['paye']) }}</span></td>
                                <td class="px-4 py-3 text-right">R <span class="js-sum-deductions" data-raw="{{ (float)$s['deductions'] }}">{{ $money($s['deductions']) }}</span></td>
                                <td class="px-4 py-3 text-right font-bold ds-value">R <span class="js-sum-net" data-raw="{{ (float)$s['net'] }}">{{ $money($s['net']) }}</span></td>
                                <td class="px-4 py-3 text-right">
                                    @if((int)$s['user_id'] > 0)
                                        <a class="corex-btn-outline text-xs px-3 py-1.5"
                                           href="{{ route('deals-dr2.settle.print.agent', ['deal' => $deal->id, 'user' => (int)$s['user_id']]) }}" target="_blank">
                                            Payslip
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <tr style="border-top: 2px solid var(--ds-border);">
                            <td class="px-4 py-3 font-extrabold" style="color:#0b2a4a">Totals</td>
                            <td class="px-4 py-3 text-right font-bold">R <span id="js-sum-total-allocated">{{ $money($totals['allocated']) }}</span></td>
                            <td class="px-4 py-3 text-right font-bold">R <span id="js-sum-total-gross">{{ $money($totals['gross']) }}</span></td>
                            <td class="px-4 py-3 text-right font-bold">R <span id="js-sum-total-paye">{{ $money($totals['paye']) }}</span></td>
                            <td class="px-4 py-3 text-right font-bold">R <span id="js-sum-total-deductions">{{ $money($totals['deductions']) }}</span></td>
                            <td class="px-4 py-3 text-right font-extrabold ds-value">R <span id="js-sum-total-net">{{ $money($totals['net']) }}</span></td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

        </form>

        {{-- SETTLE_LIVE_RECALC_JS_2026 --}}
<script>
(() => {
  const form = document.getElementById("settleForm");
  if (!form) return;

  const fmt = (n) => {
      const num = Number(n || 0);
      return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

  const getNum = (el) => {
    if (!el) return 0;
    const v = ("value" in el) ? el.value : (el.dataset && el.dataset.raw ? el.dataset.raw : el.textContent);
    const n = parseFloat(String(v).replace(/[^0-9.-]/g, ""));
    return Number.isFinite(n) ? n : 0;
  };

  const setSpan = (row, sel, val) => {
    const sp = row.querySelector(sel);
    if (!sp) return;
    sp.dataset.raw = String(val);
    sp.textContent = fmt(val);
  };

  const poolForSide = (side) => {
    const sp = document.querySelector('.js-pool[data-side="' + side + '"]');
    return getNum(sp);
  };

  const recalc = () => {
    const totals = { gross:0, paye:0, deductions:0, net:0, company:0 };
    const summary = {}; // userId -> {allocated,gross,paye,deductions,net}

    ["listing","selling"].forEach((side) => {
      const pool = poolForSide(side);

      document.querySelectorAll('.settle-row[data-side="' + side + '"]').forEach((row) => {
        const userId = row.dataset.user;

        const shareEl = row.querySelector('input[name="' + side + '_share[' + userId + ']"]');
        const cutEl   = row.querySelector('input[name="' + side + '_agent_cut[' + userId + ']"]');
        const pmEl    = row.querySelector('select[name="' + side + '_paye_method[' + userId + ']"]');
        const pvEl    = row.querySelector('input[name="' + side + '_paye_value[' + userId + ']"]');
        const dedEl   = row.querySelector('input[name="' + side + '_deductions[' + userId + ']"]');

        const sharePercent = getNum(shareEl);
        const cutPercent = (cutEl && cutEl.value !== "") ? getNum(cutEl) : 50;
        const payeMethod = pmEl ? (pmEl.value || "percentage") : "percentage";
        const payeValue = getNum(pvEl);
        const deductions = getNum(dedEl);

        // EXACT backend formulas (DealController@saveSettlement)
        const allocated = pool * (sharePercent / 100.0);
        const gross = allocated * (cutPercent / 100.0);
        const paye = (payeMethod === "fixed") ? payeValue : (gross * (payeValue / 100.0));
        const net = gross - paye - deductions;
        const company = allocated - gross;

        setSpan(row, ".js-allocated", allocated);
        setSpan(row, ".js-gross", gross);
        setSpan(row, ".js-paye", paye);
        setSpan(row, ".js-net", net);
        setSpan(row, ".js-company", company);

        totals.gross += gross;
        totals.paye += paye;
        totals.deductions += deductions;
        totals.net += net;
        totals.company += company;

        if (!summary[userId]) summary[userId] = {allocated:0,gross:0,paye:0,deductions:0,net:0};
        summary[userId].allocated += allocated;
        summary[userId].gross += gross;
        summary[userId].paye += paye;
        summary[userId].deductions += deductions;
        summary[userId].net += net;
      });
    });

    // Update agent summary rows
    document.querySelectorAll("tr.agent-summary-row").forEach((tr) => {
      const uid = tr.dataset.user;
      const s = summary[uid] || {allocated:0,gross:0,paye:0,deductions:0,net:0};

      const setCell = (sel, val) => {
        const sp = tr.querySelector(sel);
        if (!sp) return;
        sp.dataset.raw = String(val);
        sp.textContent = fmt(val);
      };

      setCell(".js-sum-allocated", s.allocated);
      setCell(".js-sum-gross", s.gross);
      setCell(".js-sum-paye", s.paye);
      setCell(".js-sum-deductions", s.deductions);
      setCell(".js-sum-net", s.net);
    });

    const setId = (id, val) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = fmt(val);
    };

    setId("js-sum-total-gross", totals.gross);
    setId("js-sum-total-paye", totals.paye);
    setId("js-sum-total-deductions", totals.deductions);
    setId("js-sum-total-net", totals.net);

    const companyEl = document.getElementById("js-company-total");
    if (companyEl) companyEl.textContent = fmt(totals.company);

    const externalEl = document.getElementById("js-external-total");
    const external = getNum(externalEl);

    const vatAmt = Number({{ (float)$vatAmt ?? 0 }});
    const vatRate = Number({{ (float)($vatRate ?? 0) }});
    const externalExVat = (vatRate > 0 && external > 0) ? external / (1.0 + vatRate) : external;
    const checksum = totals.net + totals.paye + totals.deductions + totals.company + externalExVat + vatAmt;
    const checksumEl = document.getElementById("js-checksum");
    if (checksumEl) checksumEl.textContent = fmt(checksum);

    const totalIncVat = Number({{ (float)$totalCommissionIncVat ?? 0 }});
    const ok = Math.abs(checksum - totalIncVat) <= 0.01;

    const statusEl = document.getElementById("js-checksum-status");
    if (statusEl) statusEl.textContent = ok ? "OK" : "NOT OK";

    const wrap = statusEl ? statusEl.closest("span") : null;
    if (wrap) {
      wrap.classList.toggle("text-green-700", ok);
      wrap.classList.toggle("text-red-700", !ok);
    }
  };

  form.addEventListener("input", (e) => {
    const t = e.target;
    if (t && (t.matches("input") || t.matches("select"))) recalc();
  });

  form.addEventListener("change", (e) => {
    const t = e.target;
    if (t && (t.matches("input") || t.matches("select"))) recalc();
  });

  recalc();
})();
</script>
    </div>
</x-app-layout>
