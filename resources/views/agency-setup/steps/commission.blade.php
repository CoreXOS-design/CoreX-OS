{{-- Commission & revenue share — inline wizard step.
     Fields mirror corex/settings/commission.blade.php EXACTLY (same input names)
     and post through the wizard form to CommissionSettingsController@update.
     $commission = CommissionSetting::forAgency() (current values). --}}
@php $c = $commission; @endphp
<div class="space-y-5" x-data="{ agentSplit: {{ (int) old('commission_split_agent', $c->commission_split_agent) }}, revShare: {{ old('revenue_share_enabled', $c->revenue_share_enabled) ? 'true' : 'false' }} }">

    {{-- Split --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Commission split</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">How the commission (excl. VAT) divides between the agent and the agency, and the annual cap after which the agent keeps more.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agent split %</label>
                <input type="number" name="commission_split_agent" x-model="agentSplit" min="0" max="100" step="1"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agency split %</label>
                <input type="text" :value="100 - agentSplit" disabled
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Annual cap (R)</label>
                <input type="number" name="annual_cap" value="{{ old('annual_cap', $c->annual_cap) }}" min="0" step="0.01"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
        </div>
    </div>

    {{-- Post-cap fees --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Post-cap fees</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Once an agent hits the annual cap, they keep 100% minus these transaction fees.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach (['post_cap_transaction_fee' => 'Transaction fee (R)', 'post_cap_fee_cap' => 'Post-cap fee cap (R)', 'post_cap_reduced_fee' => 'Reduced fee after cap (R)'] as $f => $lbl)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $lbl }}</label>
                    <input type="number" name="{{ $f }}" value="{{ old($f, $c->$f) }}" min="0" step="0.01"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            @endforeach
        </div>
    </div>

    {{-- Monthly fees --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Monthly &amp; risk fees</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach (['monthly_platform_fee' => 'Platform fee (R/month)', 'risk_management_fee' => 'Risk management fee (R/tx)', 'risk_management_cap' => 'Risk mgmt annual cap (R)'] as $f => $lbl)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $lbl }}</label>
                    <input type="number" name="{{ $f }}" value="{{ old($f, $c->$f) }}" min="0" step="0.01"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            @endforeach
        </div>
    </div>

    {{-- Mentor program --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Mentor program</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">New agents under a mentor pay an extra split on their first few transactions, shared between mentor and agency.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Extra split % (mentored transactions)</label>
                <input type="number" name="mentor_extra_split" value="{{ old('mentor_extra_split', $c->mentor_extra_split) }}" min="0" max="100" step="1"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Transactions before graduation</label>
                <input type="number" name="mentor_transactions" value="{{ old('mentor_transactions', $c->mentor_transactions) }}" min="1" max="50" step="1"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
        </div>
    </div>

    {{-- Revenue share --}}
    <div>
        <div class="flex items-center gap-3 mb-1">
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="revenue_share_enabled" value="0">
                <input type="checkbox" name="revenue_share_enabled" value="1" x-model="revShare" class="sr-only peer">
                <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
            </label>
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Revenue share</h3>
        </div>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Reward agents who recruit and support others with a share of company revenue, down a sliding tier structure.</p>

        <div x-show="revShare" x-cloak class="space-y-4">
            <div class="max-w-xs">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pool % (of company dollar)</label>
                <input type="number" name="revenue_share_pool_percent" value="{{ old('revenue_share_pool_percent', $c->revenue_share_pool_percent) }}" min="0" max="100" step="1"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            @php $tierLabels = [1=>'Directly sponsored',2=>'Tier 1 recruits',3=>'Tier 2 recruits',4=>'Tier 3 recruits',5=>'Tier 4 recruits',6=>'Tier 5 recruits',7=>'Tier 6 recruits']; @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border);">
                            <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Tier</th>
                            <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Relationship</th>
                            <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Share %</th>
                            <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">FLQA required</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($t = 1; $t <= 7; $t++)
                            <tr style="border-bottom:1px solid var(--border);">
                                <td class="px-3 py-2 font-semibold" style="color:var(--text-primary);">Tier {{ $t }}</td>
                                <td class="px-3 py-2" style="color:var(--text-secondary);">{{ $tierLabels[$t] }}</td>
                                <td class="px-3 py-2">
                                    <input type="number" name="tier_{{ $t }}_percent" value="{{ old("tier_{$t}_percent", $c->{"tier_{$t}_percent"}) }}" min="0" max="100" step="0.01"
                                           class="w-24 rounded-md px-2 py-1 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                </td>
                                <td class="px-3 py-2">
                                    @if ($t <= 3)
                                        <span class="text-xs font-medium px-2 py-1 rounded" style="background: color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color: var(--brand-icon,#0ea5e9);">Automatic</span>
                                    @else
                                        <input type="number" name="tier_{{ $t }}_flqa_requirement" value="{{ old("tier_{$t}_flqa_requirement", $c->{"tier_{$t}_flqa_requirement"}) }}" min="0" step="1"
                                               class="w-24 rounded-md px-2 py-1 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    @endif
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
            <p class="text-[11px]" style="color:var(--text-muted);">FLQA = First Line Qualifying Agent: a Tier 1 agent with 2+ transactions or R50,000+ GCI in the last 6 months.</p>
        </div>
    </div>
</div>
