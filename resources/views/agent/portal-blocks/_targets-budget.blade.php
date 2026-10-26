{{-- My Portal > Overview > Targets & Budget. READ ONLY: calculated and supplied, nothing here is typed or saved.
     Spec: .ai/specs/agency-tracker-rehome.md section 4.2. Figures: App\Services\Agent\MyPortalNumbers.
     Laid out to fit one laptop screen with no scrolling (my-portal-restyle.md section 9): stock and budget in one strip,
     what the budget needs beside the deal summary, my commission in one strip underneath. --}}
@php
    $p = $myPortal;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['period'])->format('F Y');
    $rand = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $lensNames = \App\Services\Agent\MyPortalNumbers::LENSES;
    $rowNames = \App\Services\Agent\MyPortalNumbers::ROWS;
    $req = $p['requirement'];
    $hasBudget = $p['budget']['source'] !== 'none';
    $cols = [['lens' => $p['lensA'], 'sum' => $p['summaryA']], ['lens' => $p['lensB'], 'sum' => $p['summaryB']]];
@endphp
<div x-show="sub.overview === 'targets'" x-cloak class="pg-pane pg-wide" data-testid="portal-targets-budget">
    <div class="mp-h">
        <h3>Targets &amp; Budget &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'targets']" />
    </div>

    {{-- Stock (CoreX properties, not the old import) and the monthly budget: personal and business kept apart, the total is worked out --}}
    <div class="mp-grid {{ $hasBudget ? 'mp-g5' : 'mp-g3' }}" style="margin-bottom:8px;" data-testid="budget-block">
        <div class="mp-card"><div class="mp-lbl">Active sales stock</div><div class="mp-val" data-testid="stock-sales">{{ $p['stock']['sales'] }}</div></div>
        <div class="mp-card"><div class="mp-lbl">Active rental stock</div><div class="mp-val" data-testid="stock-rentals">{{ $p['stock']['rentals'] }}</div></div>
        @if(! $hasBudget)
            <div class="mp-card"><div class="mp-lbl">Monthly budget</div><div class="mp-note" style="margin-top:3px;">No budget has been set for {{ $monthLabel }}. Your branch manager or admin sets budgets for the agency.</div></div>
        @else
            <div class="mp-card"><div class="mp-lbl">Personal budget</div><div class="mp-val" data-testid="budget-personal">{{ $rand($p['budget']['personalBudget']) }}</div></div>
            <div class="mp-card"><div class="mp-lbl">Business budget</div><div class="mp-val" data-testid="budget-business">{{ $rand($p['budget']['businessBudget']) }}</div></div>
            <div class="mp-card"><div class="mp-lbl">Total budget</div><div class="mp-val" data-testid="budget-total">{{ $rand($p['budget']['total']) }}</div>
                @if($p['budget']['source'] === 'carried_forward')<div class="mp-note">carried from {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['budget']['setForPeriod'])->format('M Y') }}</div>@endif
            </div>
        @endif
    </div>

    <div class="mp-grid mp-g2" style="margin-bottom:8px;">
        {{-- What the budget needs: today's Worksheet calculation, unchanged, shown read-only (Johan, 20 Oct 2026). --}}
        <div class="mp-card" data-testid="requirement-block">
            <div class="ds-section-header" style="margin:0 0 6px;">What your budget needs</div>
            @if($req['state'] === 'no_budget')
                <p class="text-sm" style="color:var(--text-muted); margin:0;">Once a budget is set for {{ $monthLabel }}, this shows how many sales and listings it takes.</p>
            @elseif($req['state'] === 'incomplete')
                <p class="text-sm" style="color:var(--text-muted); margin:0;" data-testid="requirement-incomplete">This cannot be worked out yet: the budget has no sale price or commission to work from.</p>
            @else
                <div class="mp-grid mp-g2">
                    <div><div class="mp-lbl">Net need</div><div class="text-base font-bold" style="color:var(--text-primary);" data-testid="req-net">{{ $rand($req['netNeed']) }}</div></div>
                    <div><div class="mp-lbl">Sales needed / month</div><div class="text-base font-bold" style="color:var(--text-primary);" data-testid="req-deals">{{ number_format($req['salesNeeded'], 2) }}</div></div>
                    <div><div class="mp-lbl">Total listings needed</div><div class="text-base font-bold" style="color:var(--text-primary);" data-testid="req-listings">{{ number_format($req['listingsNeeded'], 2) }}</div></div>
                    <div><div class="mp-lbl">Gap (needed &minus; stock {{ $p['stock']['sales'] }})</div><div class="text-base font-bold" style="color: {{ $req['gap'] > 0 ? 'var(--ds-amber)' : 'var(--ds-green)' }};" data-testid="req-gap">{{ number_format($req['gap'], 2) }}</div></div>
                </div>
                <div class="mp-note" style="margin-top:6px;" data-testid="req-basis">Basis: average sale price {{ $rand($req['basis']['avgSalePrice']) }}, commission {{ rtrim(rtrim(number_format($req['basis']['commissionPercent'], 2), '0'), '.') }}%, PAYE {{ rtrim(rtrim(number_format($req['basis']['payePercent'], 2), '0'), '.') }}%, your split {{ rtrim(rtrim(number_format($req['basis']['agentSplitPercent'], 2), '0'), '.') }}%, correctly priced {{ rtrim(rtrim(number_format($req['basis']['correctlyPricedPercent'], 2), '0'), '.') }}%.</div>
            @endif
            @if(($p['branchReq']['requirement'] ?? 0) > 0)
                <div class="text-xs" style="margin-top:6px; padding-top:6px; border-top:1px solid var(--border); color:var(--text-secondary);" data-testid="branch-requirement-block">
                    <span class="mp-lbl">Branch needs</span>
                    <strong>{{ $rand($p['branchReq']['requirement']) }}</strong> &middot; {{ $p['branchReq']['agents'] }} agents &middot; <strong>{{ $rand($p['branchReq']['requiredPerAgent']) }}</strong> per agent <span class="mp-note">(excl. VAT)</span>
                </div>
            @endif
        </div>

        {{-- Deal summary: two columns, each looked at through a lens. Display choices only - nothing is saved. --}}
        <div class="mp-card" data-testid="deal-summary">
            <div class="ds-section-header" style="margin:0 0 6px;">Deal summary</div>
            <form method="GET" action="{{ route('agent.portal') }}" class="flex flex-wrap items-center gap-x-3 gap-y-1" style="margin-bottom:6px;">
                <input type="hidden" name="sub" value="targets">
                <input type="hidden" name="period" value="{{ $p['period'] }}">
                @foreach(['a' => 'Left', 'b' => 'Right'] as $field => $label)
                    <label class="text-xs" style="color:var(--text-secondary);">{{ $label }}
                        <select name="{{ $field }}" onchange="this.form.submit()" class="h-7 text-xs rounded-md px-1" style="background:var(--surface); color:var(--text-primary); border:1px solid var(--border);">
                            @foreach($p['lenses'] as $lens)
                                <option value="{{ $lens }}" @selected(($field === 'a' ? $p['lensA'] : $p['lensB']) === $lens)>{{ $lensNames[$lens] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <fieldset class="flex flex-wrap gap-x-3 text-xs" style="color:var(--text-secondary);">
                    @foreach($rowNames as $key => $label)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="rows[]" value="{{ $key }}" onchange="this.form.submit()" @checked(in_array($key, $p['rows'], true))> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <noscript><button type="submit" class="corex-btn-primary text-xs">Show</button></noscript>
            </form>
            <div class="mp-grid mp-g2">
                @foreach($cols as $i => $col)
                    @php $sum = $col['sum']; @endphp
                    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:6px 8px;" data-testid="summary-col-{{ $i }}">
                        <div class="mp-lbl" style="margin-bottom:3px;">{{ $lensNames[$col['lens']] }}</div>
                        @if(array_sum($sum['status']) === 0)
                            <p class="text-xs" style="color:var(--text-muted); margin:0;">No deals in {{ $monthLabel }}.</p>
                        @else
                            @if(in_array('avg_price', $p['rows'], true))
                                <div class="flex justify-between text-xs"><span style="color:var(--text-secondary);">Average sale price</span><strong data-testid="avg-price-{{ $i }}">{{ $sum['avgPrice'] !== null ? $rand($sum['avgPrice']) : '—' }}</strong></div>
                            @endif
                            @if(in_array('avg_commission', $p['rows'], true))
                                <div class="flex justify-between text-xs"><span style="color:var(--text-secondary);">Average commission</span><strong>{{ $sum['avgCommissionPct'] !== null ? number_format($sum['avgCommissionPct'], 2) . '%' : '—' }}</strong></div>
                            @endif
                            @if(in_array('deal_count', $p['rows'], true))
                                <div class="grid grid-cols-4 gap-1 text-center" style="margin-top:4px;">
                                    @foreach(['P' => 'Pending', 'G' => 'Granted', 'R' => 'Registered', 'D' => 'Declined'] as $code => $name)
                                        <div><div class="text-sm font-bold" style="color:var(--text-primary);" data-testid="count-{{ $code }}-{{ $i }}">{{ $sum['status'][$code] }}</div><div class="text-[10px]" style="color:var(--text-muted);">{{ $name }}</div></div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- My own commission: never the agency's or the branch's --}}
    @php $totals = ! empty($p['totals']) ? array_filter($p['totals']) : []; @endphp
    <div class="mp-grid {{ count($totals) === 2 ? 'mp-g5' : (count($totals) === 1 ? 'mp-g4' : 'mp-g3') }}" data-testid="my-commission">
        <div class="mp-card"><div class="mp-lbl">My commission &mdash; gross</div><div class="mp-val" data-testid="money-gross">{{ $rand($p['money']['gross']) }}</div><div class="mp-note">before splits, excl. VAT</div></div>
        <div class="mp-card"><div class="mp-lbl">After splits and deductions</div><div class="mp-val" data-testid="money-net">{{ $rand($p['money']['net']) }}</div><div class="mp-note">excl. VAT</div></div>
        <div class="mp-card"><div class="mp-lbl">Pipeline</div><div class="mp-val" data-testid="money-pipeline">{{ $rand($p['money']['pipeline']) }}</div><div class="mp-note">yours, not yet registered or paid</div></div>
        @if(! empty($p['totals']))
            {{-- Only when the agency has switched this on AND the user's role reaches that far (MyPortalNumbers::commissionTotals) --}}
            <span data-testid="commission-totals" style="display:contents;">
            @foreach(['branch' => 'Your branch, total', 'agency' => 'The agency, total'] as $k => $label)
                @if($p['totals'][$k])
                    <div class="mp-card"><div class="mp-lbl">{{ $label }}</div>
                        <div class="mp-val" data-testid="totals-{{ $k }}-gross">{{ $rand($p['totals'][$k]['gross']) }} <span class="mp-note">gross</span></div>
                        <div class="mp-note" data-testid="totals-{{ $k }}-net">{{ $rand($p['totals'][$k]['net']) }} after splits &middot; totals only</div></div>
                @endif
            @endforeach
            </span>
        @endif
    </div>
</div>
