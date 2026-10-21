{{-- My Portal > Overview > Targets & Budget. READ ONLY: calculated and supplied, nothing here is typed or saved.
     Spec: .ai/specs/agency-tracker-rehome.md section 4.2. Figures: App\Services\Agent\MyPortalNumbers. --}}
@php
    $p = $myPortal;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['period'])->format('F Y');
    $rand = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $lensNames = \App\Services\Agent\MyPortalNumbers::LENSES;
    $rowNames = \App\Services\Agent\MyPortalNumbers::ROWS;
    $req = $p['requirement'];
@endphp
<div x-show="sub.overview === 'targets'" x-cloak class="pg-pane pg-wide" data-testid="portal-targets-budget">
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h3 class="text-sm font-bold" style="color:var(--text-primary);">Targets &amp; Budget &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'targets']" />
    </div>
    <p class="text-xs mb-4" style="color:var(--text-muted);">Worked out for you from your stock, your deals and the budget your agency set. Nothing on this page is typed in.</p>

    {{-- Active stock: CoreX properties, not the old import --}}
    <div class="grid grid-cols-2 gap-3 mb-4" style="max-width:520px;">
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Active sales stock</div>
            <div class="text-lg font-bold" style="color:var(--text-primary);" data-testid="stock-sales">{{ $p['stock']['sales'] }}</div>
            <div class="text-[11px]" style="color:var(--text-muted);">listings on the market now</div>
        </div>
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Active rental stock</div>
            <div class="text-lg font-bold" style="color:var(--text-primary);" data-testid="stock-rentals">{{ $p['stock']['rentals'] }}</div>
            <div class="text-[11px]" style="color:var(--text-muted);">to-let listings on the market now</div>
        </div>
    </div>

    {{-- Monthly budget: personal and business kept apart, the total is worked out --}}
    <div class="ds-status-card mb-4" data-testid="budget-block">
        <h4 class="ds-section-header" style="margin-bottom:0.5rem;">Monthly budget</h4>
        @if($p['budget']['source'] === 'none')
            <p class="text-sm" style="color:var(--text-muted);">No budget has been set for {{ $monthLabel }}. Your branch manager or admin sets budgets for the agency.</p>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><div class="ds-label">Personal budget</div><div class="ds-value" data-testid="budget-personal">{{ $rand($p['budget']['personalBudget']) }}</div></div>
                <div><div class="ds-label">Business budget</div><div class="ds-value" data-testid="budget-business">{{ $rand($p['budget']['businessBudget']) }}</div></div>
                <div><div class="ds-label">Total</div><div class="ds-value" data-testid="budget-total">{{ $rand($p['budget']['total']) }}</div></div>
            </div>
            @if($p['budget']['source'] === 'carried_forward')
                <p class="text-xs mt-2" style="color:var(--text-muted);">Nothing new is set for {{ $monthLabel }} yet, so this is the budget from {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['budget']['setForPeriod'])->format('F Y') }}.</p>
            @endif
        @endif
    </div>

    {{-- What the budget needs: deals, listings, gap against stock --}}
    <div class="ds-status-card mb-4" data-testid="requirement-block">
        <h4 class="ds-section-header" style="margin-bottom:0.5rem;">What your budget needs</h4>
        @if($req['state'] === 'no_budget')
            <p class="text-sm" style="color:var(--text-muted);">Once a budget is set for {{ $monthLabel }}, this shows how many deals and listings it takes.</p>
        @elseif($req['state'] === 'not_enough_deals')
            <p class="text-sm" style="color:var(--text-muted);" data-testid="not-enough-deals">Not enough deals yet to calculate. This is worked out from what your deals actually earn you, and there are none to go on yet.</p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><div class="ds-label">Deals needed</div><div class="ds-value" data-testid="req-deals">{{ $req['dealsNeeded'] }}</div><div class="text-xs" style="color:var(--text-muted);">a month</div></div>
                <div><div class="ds-label">Listings needed</div><div class="ds-value" data-testid="req-listings">{{ $req['listingsNeeded'] }}</div></div>
                <div><div class="ds-label">Active sales stock</div><div class="ds-value">{{ $p['stock']['sales'] }}</div></div>
                <div>
                    <div class="ds-label">{{ $req['gap'] > 0 ? 'Listings short' : 'Listings ahead' }}</div>
                    <div class="ds-value" style="color: {{ $req['gap'] > 0 ? 'var(--ds-amber)' : 'var(--ds-green)' }};" data-testid="req-gap">{{ abs($req['gap']) }}</div>
                </div>
            </div>
            <p class="text-xs mt-2" style="color:var(--text-muted);">Based on {{ $req['source'] === 'own' ? 'what your own deals earn you' : 'what deals earn agents across the agency (you have no deals of your own yet)' }}: about {{ $rand($req['perDeal']) }} a deal after splits, excluding VAT.</p>
        @endif
    </div>

    @if(($p['branchReq']['requirement'] ?? 0) > 0)
    <div class="ds-status-card mb-4" data-testid="branch-requirement-block">
        <h4 class="ds-section-header" style="margin-bottom:0.5rem;">What the branch needs</h4>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><div class="ds-label">Branch requirement</div><div class="ds-value">{{ $rand($p['branchReq']['requirement']) }}</div><div class="text-xs" style="color:var(--text-muted);">excluding VAT</div></div>
            <div><div class="ds-label">Agents in the branch</div><div class="ds-value">{{ $p['branchReq']['agents'] }}</div></div>
            <div><div class="ds-label">Required per agent</div><div class="ds-value">{{ $rand($p['branchReq']['requiredPerAgent']) }}</div><div class="text-xs" style="color:var(--text-muted);">excluding VAT</div></div>
        </div>
    </div>
    @endif

    {{-- Deal summary: two columns, each looked at through a lens. Display choices only - nothing is saved. --}}
    <div class="ds-status-card mb-4" data-testid="deal-summary">
        <h4 class="ds-section-header" style="margin-bottom:0.5rem;">Deal summary &mdash; {{ $monthLabel }}</h4>
        <form method="GET" action="{{ route('agent.portal') }}" class="flex flex-wrap items-end gap-3 mb-3">
            <input type="hidden" name="sub" value="targets">
            <input type="hidden" name="period" value="{{ $p['period'] }}">
            @foreach(['a' => 'Left column', 'b' => 'Right column'] as $field => $label)
                <label class="text-xs" style="color:var(--text-secondary);">{{ $label }}
                    <select name="{{ $field }}" onchange="this.form.submit()" class="block h-8 text-sm rounded-md px-2 mt-1" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                        @foreach($p['lenses'] as $lens)
                            <option value="{{ $lens }}" @selected(($field === 'a' ? $p['lensA'] : $p['lensB']) === $lens)>{{ $lensNames[$lens] }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <fieldset class="flex flex-wrap gap-3 text-xs" style="color:var(--text-secondary);">
                @foreach($rowNames as $key => $label)
                    <label class="inline-flex items-center gap-1"><input type="checkbox" name="rows[]" value="{{ $key }}" onchange="this.form.submit()" @checked(in_array($key, $p['rows'], true))> {{ $label }}</label>
                @endforeach
            </fieldset>
            <noscript><button type="submit" class="corex-btn-primary text-sm">Show</button></noscript>
        </form>
        @php $cols = [['lens' => $p['lensA'], 'sum' => $p['summaryA']], ['lens' => $p['lensB'], 'sum' => $p['summaryB']]]; @endphp
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($cols as $i => $col)
                @php $sum = $col['sum']; @endphp
                <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);" data-testid="summary-col-{{ $i }}">
                    <div class="text-[11px] font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">{{ $lensNames[$col['lens']] }}</div>
                    @if(array_sum($sum['status']) === 0)
                        <p class="text-sm" style="color:var(--text-muted);">No deals in {{ $monthLabel }}.</p>
                    @else
                        @if(in_array('avg_price', $p['rows'], true))
                            <div class="flex justify-between text-sm py-1"><span style="color:var(--text-secondary);">Average sale price</span><strong data-testid="avg-price-{{ $i }}">{{ $sum['avgPrice'] !== null ? $rand($sum['avgPrice']) : '—' }}</strong></div>
                        @endif
                        @if(in_array('avg_commission', $p['rows'], true))
                            <div class="flex justify-between text-sm py-1"><span style="color:var(--text-secondary);">Average commission</span><strong>{{ $sum['avgCommissionPct'] !== null ? number_format($sum['avgCommissionPct'], 2) . '%' : '—' }}</strong></div>
                        @endif
                        @if(in_array('deal_count', $p['rows'], true))
                            <div class="grid grid-cols-4 gap-2 text-center mt-2">
                                @foreach(['P' => 'Pending', 'G' => 'Granted', 'R' => 'Registered', 'D' => 'Declined'] as $code => $name)
                                    <div><div class="text-base font-bold" style="color:var(--text-primary);" data-testid="count-{{ $code }}-{{ $i }}">{{ $sum['status'][$code] }}</div><div class="text-[10px]" style="color:var(--text-muted);">{{ $name }}</div></div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- My own commission: never the agency's or the branch's --}}
    <div class="ds-status-card" data-testid="my-commission">
        <h4 class="ds-section-header" style="margin-bottom:0.5rem;">My commission &mdash; {{ $monthLabel }}</h4>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><div class="ds-label">Gross</div><div class="ds-value" data-testid="money-gross">{{ $rand($p['money']['gross']) }}</div><div class="text-xs" style="color:var(--text-muted);">before splits and deductions, excluding VAT</div></div>
            <div><div class="ds-label">After splits and deductions</div><div class="ds-value" data-testid="money-net">{{ $rand($p['money']['net']) }}</div><div class="text-xs" style="color:var(--text-muted);">excluding VAT</div></div>
            <div><div class="ds-label">Pipeline</div><div class="ds-value" data-testid="money-pipeline">{{ $rand($p['money']['pipeline']) }}</div><div class="text-xs" style="color:var(--text-muted);">yours, on deals not yet registered or paid</div></div>
        </div>
        @if(! empty($p['totals']))
            {{-- Only when the agency has switched this on AND the user's role reaches that far (MyPortalNumbers::commissionTotals) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 pt-3" style="border-top:1px solid var(--border);" data-testid="commission-totals">
                @foreach(['branch' => 'Your branch, total', 'agency' => 'The agency, total'] as $k => $label)
                    @if($p['totals'][$k])
                        <div><div class="ds-label">{{ $label }}</div>
                            <div class="ds-value" data-testid="totals-{{ $k }}-gross">{{ $rand($p['totals'][$k]['gross']) }} <span class="text-xs font-normal" style="color:var(--text-muted);">gross</span></div>
                            <div class="text-sm" style="color:var(--text-secondary);" data-testid="totals-{{ $k }}-net">{{ $rand($p['totals'][$k]['net']) }} after splits and deductions</div>
                            <div class="text-xs" style="color:var(--text-muted);">excluding VAT &middot; totals only</div></div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
