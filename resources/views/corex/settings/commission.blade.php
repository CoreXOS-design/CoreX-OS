@extends('layouts.corex')

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="commissionSettings()">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Commission & Revenue Share</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Configure commission splits, caps, fees, and revenue share tiers.</div>
    </div>

    {{-- Session messages --}}
    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.commission.update') }}" class="space-y-5">
        @csrf

        {{-- ══════════════════════════════════════
             SECTION 1: Commission Split
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Commission Split</h3>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agent Split %</label>
                        <input type="number" name="commission_split_agent" x-model="agentSplit" min="0" max="100" step="1"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agency Split %</label>
                        <input type="text" :value="100 - agentSplit" disabled
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Annual Cap (R)</label>
                        <input type="number" name="annual_cap" value="{{ old('annual_cap', $settings->annual_cap) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <div class="text-xs" style="color:var(--text-secondary);">
                    Agent receives <span class="font-bold" style="color:var(--brand-icon);" x-text="agentSplit + '%'"></span>
                    of commission excl. VAT before capping. After cap, agent receives 100% minus transaction fees.
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             SECTION 2: Post-Cap Fees
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Post-Cap Fees</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Transaction Fee (R)</label>
                        <input type="number" name="post_cap_transaction_fee" value="{{ old('post_cap_transaction_fee', $settings->post_cap_transaction_fee) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Post-Cap Fee Cap (R)</label>
                        <input type="number" name="post_cap_fee_cap" value="{{ old('post_cap_fee_cap', $settings->post_cap_fee_cap) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Reduced Fee After Cap (R)</label>
                        <input type="number" name="post_cap_reduced_fee" value="{{ old('post_cap_reduced_fee', $settings->post_cap_reduced_fee) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             SECTION 3: Monthly Fees
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Monthly Fees</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Platform Fee (R/month)</label>
                        <input type="number" name="monthly_platform_fee" value="{{ old('monthly_platform_fee', $settings->monthly_platform_fee) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Risk Management Fee (R/tx)</label>
                        <input type="number" name="risk_management_fee" value="{{ old('risk_management_fee', $settings->risk_management_fee) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Risk Mgmt Annual Cap (R)</label>
                        <input type="number" name="risk_management_cap" value="{{ old('risk_management_cap', $settings->risk_management_cap) }}" min="0" step="0.01"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             SECTION 4: Mentor Program
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Mentor Program</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Extra Split % (mentored transactions)</label>
                        <input type="number" name="mentor_extra_split" value="{{ old('mentor_extra_split', $settings->mentor_extra_split) }}" min="0" max="100" step="1"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Transactions Before Graduation</label>
                        <input type="number" name="mentor_transactions" value="{{ old('mentor_transactions', $settings->mentor_transactions) }}" min="1" max="50" step="1"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <div class="mt-2 text-xs" style="color:var(--text-secondary);">
                    New agents under a mentor pay an extra {{ $settings->mentor_extra_split }}% on their first {{ $settings->mentor_transactions }} transactions. Split between mentor and agency.
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             SECTION 5: Revenue Share
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Revenue Share</h3>
            </div>
            <div class="p-5 space-y-4">
                {{-- Enable toggle --}}
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="revenue_share_enabled" value="0">
                        <input type="checkbox" name="revenue_share_enabled" value="1" x-model="revShareEnabled"
                               class="sr-only peer">
                        <div class="w-11 h-6 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-5 after:w-5 after:transition-all"
                             style="background:var(--border);"
                             :style="revShareEnabled ? 'background:#0ea5e9' : ''">
                            <div class="absolute top-[2px] left-[2px] rounded-full h-5 w-5 transition-all bg-white"
                                 :style="revShareEnabled ? 'transform:translateX(100%)' : ''"></div>
                        </div>
                    </label>
                    <span class="text-sm font-semibold" style="color:var(--text-primary);">Revenue Share Enabled</span>
                </div>

                <div x-show="revShareEnabled" x-cloak class="space-y-4">
                    {{-- Pool percentage --}}
                    <div class="max-w-xs">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pool % (of Company Dollar)</label>
                        <input type="number" name="revenue_share_pool_percent" value="{{ old('revenue_share_pool_percent', $settings->revenue_share_pool_percent) }}" min="0" max="100" step="1"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>

                    {{-- Tier table --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" style="border-collapse:separate; border-spacing:0;">
                            <thead>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <th class="text-left text-xs font-bold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Tier</th>
                                    <th class="text-left text-xs font-bold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Relationship</th>
                                    <th class="text-left text-xs font-bold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Share %</th>
                                    <th class="text-left text-xs font-bold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">FLQA Required</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $tierLabels = [
                                        1 => 'Directly sponsored',
                                        2 => 'Tier 1 recruits',
                                        3 => 'Tier 2 recruits',
                                        4 => 'Tier 3 recruits',
                                        5 => 'Tier 4 recruits',
                                        6 => 'Tier 5 recruits',
                                        7 => 'Tier 6 recruits',
                                    ];
                                @endphp
                                @for($t = 1; $t <= 7; $t++)
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td class="px-3 py-2 font-semibold" style="color:var(--text-primary);">Tier {{ $t }}</td>
                                    <td class="px-3 py-2" style="color:var(--text-secondary);">{{ $tierLabels[$t] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" name="tier_{{ $t }}_percent"
                                               value="{{ old("tier_{$t}_percent", $settings->{"tier_{$t}_percent"}) }}"
                                               min="0" max="100" step="0.01"
                                               class="w-24 rounded-md px-2 py-1 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </td>
                                    <td class="px-3 py-2">
                                        @if($t <= 3)
                                            <span class="text-xs font-medium px-2 py-1 rounded" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">Automatic</span>
                                        @else
                                            <input type="number" name="tier_{{ $t }}_flqa_requirement"
                                                   value="{{ old("tier_{$t}_flqa_requirement", $settings->{"tier_{$t}_flqa_requirement"}) }}"
                                                   min="0" step="1"
                                                   class="w-24 rounded-md px-2 py-1 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        @endif
                                    </td>
                                </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>

                    <div class="text-xs" style="color:var(--text-secondary);">
                        FLQA = First Line Qualifying Agent: a Tier 1 agent with 2+ transactions or R50,000+ GCI in the last 6 months.
                    </div>
                </div>
            </div>
        </div>

        {{-- Save button --}}
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5">
                Save Commission Settings
            </button>
        </div>
    </form>
</div>

<script>
function commissionSettings() {
    return {
        agentSplit: {{ old('commission_split_agent', $settings->commission_split_agent) }},
        revShareEnabled: {{ old('revenue_share_enabled', $settings->revenue_share_enabled) ? 'true' : 'false' }},
    }
}
</script>
@endsection
