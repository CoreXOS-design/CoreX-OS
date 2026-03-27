@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="revShareCalc()">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Revenue Share Calculator</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">See what your network could earn you. Adjust the sliders to explore different scenarios.</div>
    </div>

    {{-- ══════════════════════════════════════
         INPUT SECTION
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:24px;">
        <h3 class="text-sm font-bold mb-5" style="color:var(--text-primary);">Your Scenario</h3>

        <div class="space-y-6">
            {{-- Slider 1: Agents --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium" style="color:var(--text-secondary);">Agents you sponsor</label>
                    <div class="flex items-center gap-2">
                        <input type="number" x-model.number="tier1Agents" min="1" max="20" step="1"
                               class="w-16 text-center rounded-md px-2 py-1 text-sm font-bold"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs" style="color:var(--text-muted);">agents</span>
                    </div>
                </div>
                <input type="range" x-model.number="tier1Agents" min="1" max="20" step="1"
                       class="w-full h-2 rounded-full appearance-none cursor-pointer"
                       style="background:var(--border); accent-color:#0ea5e9;">
                <div class="flex justify-between text-[10px] mt-1" style="color:var(--text-muted);">
                    <span>1</span><span>5</span><span>10</span><span>15</span><span>20</span>
                </div>
            </div>

            {{-- Slider 2: Deals per month --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium" style="color:var(--text-secondary);">Avg deals per agent / month</label>
                    <div class="flex items-center gap-2">
                        <input type="number" x-model.number="dealsPerMonth" min="1" max="10" step="1"
                               class="w-16 text-center rounded-md px-2 py-1 text-sm font-bold"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs" style="color:var(--text-muted);">deals</span>
                    </div>
                </div>
                <input type="range" x-model.number="dealsPerMonth" min="1" max="10" step="1"
                       class="w-full h-2 rounded-full appearance-none cursor-pointer"
                       style="background:var(--border); accent-color:#0ea5e9;">
                <div class="flex justify-between text-[10px] mt-1" style="color:var(--text-muted);">
                    <span>1</span><span>3</span><span>5</span><span>7</span><span>10</span>
                </div>
            </div>

            {{-- Slider 3: Avg commission --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium" style="color:var(--text-secondary);">Avg commission per deal</label>
                    <div class="flex items-center gap-2">
                        <span class="text-xs" style="color:var(--text-muted);">R</span>
                        <input type="number" x-model.number="avgCommission" min="10000" max="200000" step="5000"
                               class="w-24 text-center rounded-md px-2 py-1 text-sm font-bold"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <input type="range" x-model.number="avgCommission" min="10000" max="200000" step="5000"
                       class="w-full h-2 rounded-full appearance-none cursor-pointer"
                       style="background:var(--border); accent-color:#0ea5e9;">
                <div class="flex justify-between text-[10px] mt-1" style="color:var(--text-muted);">
                    <span>R 10k</span><span>R 50k</span><span>R 100k</span><span>R 150k</span><span>R 200k</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         RESULTS SECTION
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Card 1: Monthly Revenue Share --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Monthly Revenue Share</div>
            <div class="text-3xl font-extrabold mb-3" style="color:#14b8a6;">
                R <span x-text="fmt(monthlyTotal)"></span>
            </div>
            <div class="space-y-1.5" style="border-top:1px solid var(--border); padding-top:12px;">
                <div class="flex items-center justify-between text-sm">
                    <span style="color:var(--text-secondary);">Tier 1 (<span x-text="tier1Agents"></span> agents)</span>
                    <span class="font-semibold" style="color:var(--text-primary);">R <span x-text="fmt(tier1Share)"></span></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span style="color:var(--text-secondary);">Tier 2 (<span x-text="tier2Agents"></span> projected)</span>
                    <span class="font-semibold" style="color:var(--text-primary);">R <span x-text="fmt(tier2Share)"></span></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span style="color:var(--text-secondary);">Tier 3 (<span x-text="tier3Agents"></span> projected)</span>
                    <span class="font-semibold" style="color:var(--text-primary);">R <span x-text="fmt(tier3Share)"></span></span>
                </div>
            </div>
        </div>

        {{-- Card 2: Annual Revenue Share --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Annual Revenue Share</div>
            <div class="text-3xl font-extrabold mb-3" style="color:#22c55e;">
                R <span x-text="fmt(annualTotal)"></span>
            </div>
            <div class="text-sm mt-2" style="color:var(--text-secondary);">
                That's <span class="font-bold" style="color:var(--text-primary);">R <span x-text="fmt(monthlyTotal)"></span></span> per month in passive income
            </div>
            <div class="mt-4 text-xs" style="color:var(--text-muted);">
                Revenue share continues even when you're not actively selling.
            </div>
        </div>

        {{-- Card 3: Network Growth --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Network Growth</div>

            <div class="space-y-3">
                {{-- Tier 1 --}}
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0" style="background:rgba(14,165,233,0.15); color:#0ea5e9;">T1</div>
                    <div class="flex-1">
                        <div class="h-5 rounded-full overflow-hidden" style="background:var(--border);">
                            <div class="h-full rounded-full transition-all duration-300" style="background:#0ea5e9;" :style="'width:' + Math.min(100, tier1Agents * 5) + '%'"></div>
                        </div>
                    </div>
                    <div class="text-sm font-bold w-12 text-right" style="color:var(--text-primary);" x-text="tier1Agents"></div>
                </div>

                {{-- Tier 2 --}}
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0" style="background:rgba(20,184,166,0.15); color:#14b8a6;">T2</div>
                    <div class="flex-1">
                        <div class="h-5 rounded-full overflow-hidden" style="background:var(--border);">
                            <div class="h-full rounded-full transition-all duration-300" style="background:#14b8a6;" :style="'width:' + Math.min(100, tier2Agents * 2.5) + '%'"></div>
                        </div>
                    </div>
                    <div class="text-sm font-bold w-12 text-right" style="color:var(--text-primary);" x-text="tier2Agents"></div>
                </div>

                {{-- Tier 3 --}}
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0" style="background:rgba(34,197,94,0.15); color:#22c55e;">T3</div>
                    <div class="flex-1">
                        <div class="h-5 rounded-full overflow-hidden" style="background:var(--border);">
                            <div class="h-full rounded-full transition-all duration-300" style="background:#22c55e;" :style="'width:' + Math.min(100, tier3Agents * 1.25) + '%'"></div>
                        </div>
                    </div>
                    <div class="text-sm font-bold w-12 text-right" style="color:var(--text-primary);" x-text="tier3Agents"></div>
                </div>
            </div>

            <div class="mt-4 text-xs text-center" style="color:var(--text-muted);">
                Total network: <span class="font-bold" style="color:var(--text-primary);" x-text="tier1Agents + tier2Agents + tier3Agents"></span> agents
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         ASSUMPTIONS BOX
         ══════════════════════════════════════ --}}
    <div class="px-4 py-3 rounded-md text-xs" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
        Based on: <span x-text="agentSplit"></span>/<span x-text="agencySplit"></span> agent/agency split, R <span x-text="fmt({{ (float) $settings->annual_cap }})"></span> annual cap, <span x-text="poolPercent"></span>% revenue share pool.
        Assumes all agents are pre-cap. Tier 2 projected at 2 recruits per Tier 1 agent. Tier 3 projected at 2 recruits per Tier 2 agent. Actual results depend on individual agent production.
    </div>

    {{-- ══════════════════════════════════════
         HOW IT WORKS
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:24px;">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">How Revenue Share Works</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @php
                $steps = [
                    ['num' => '1', 'title' => 'Sponsor an agent', 'desc' => 'You recruit an agent into the agency. They become your Tier 1.'],
                    ['num' => '2', 'title' => 'They close deals', 'desc' => 'When your agent closes deals, the agency earns company dollar from their split.'],
                    ['num' => '3', 'title' => 'Pool is funded', 'desc' => (int)$settings->revenue_share_pool_percent . '% of that company dollar goes into the revenue share pool.'],
                    ['num' => '4', 'title' => 'You earn your share', 'desc' => 'You receive ' . number_format((float)$settings->tier_1_percent, 1) . '% of your Tier 1 agents\' company dollar.'],
                    ['num' => '5', 'title' => 'Network grows deeper', 'desc' => 'If your agents sponsor others, you earn on Tier 2, 3, and deeper — up to 7 tiers.'],
                    ['num' => '6', 'title' => 'Passive income', 'desc' => 'Revenue share continues even when you\'re not actively selling. Build once, earn ongoing.'],
                ];
            @endphp

            @foreach($steps as $step)
            <div class="flex gap-3">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">
                    {{ $step['num'] }}
                </div>
                <div>
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $step['title'] }}</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $step['desc'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

</div>

<script>
function revShareCalc() {
    return {
        // Inputs
        tier1Agents: 5,
        dealsPerMonth: 2,
        avgCommission: 60000,

        // Settings from server
        agentSplit: {{ (int) $settings->commission_split_agent }},
        agencySplit: {{ (int) $settings->commission_split_agency }},
        poolPercent: {{ (int) $settings->revenue_share_pool_percent }},
        tier1Pct: {{ (float) $settings->tier_1_percent }},
        tier2Pct: {{ (float) $settings->tier_2_percent }},
        tier3Pct: {{ (float) $settings->tier_3_percent }},

        // Computed
        get tier2Agents() { return this.tier1Agents * 2; },
        get tier3Agents() { return this.tier2Agents * 2; },

        get companyDollarPerAgent() {
            const monthlyGCI = this.dealsPerMonth * this.avgCommission;
            return monthlyGCI * (this.agencySplit / 100);
        },

        get poolPerAgent() {
            return this.companyDollarPerAgent * (this.poolPercent / 100);
        },

        get tier1Share() {
            return this.tier1Agents * this.poolPerAgent * (this.tier1Pct / 100);
        },

        get tier2Share() {
            return this.tier2Agents * this.poolPerAgent * (this.tier2Pct / 100);
        },

        get tier3Share() {
            return this.tier3Agents * this.poolPerAgent * (this.tier3Pct / 100);
        },

        get monthlyTotal() {
            return this.tier1Share + this.tier2Share + this.tier3Share;
        },

        get annualTotal() {
            return this.monthlyTotal * 12;
        },

        fmt(val) {
            return Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
}
</script>
@endsection
