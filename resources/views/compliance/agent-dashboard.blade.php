{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agent Compliance Dashboard</h1>
                <p class="text-sm text-white/60">FFC, training, and compliance status for all agents.</p>
            </div>
        </div>
    </div>

    {{-- Summary KPIs --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total Agents" :value="number_format($totalAgents)" />
        <x-corex-kpi-card title="Fully Compliant" :value="number_format($compliantCount)" />
        <x-corex-kpi-card title="At Risk" :value="number_format($atRiskCount)" />
        <x-corex-kpi-card title="Non-Compliant" :value="number_format($nonCompliantCount)" />
    </div>

    {{-- Agent table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Agent Status</h3>
        </div>

        @if($agentData->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active agents</h3>
                <p class="text-sm" style="color: var(--text-muted);">Agents will appear here once added to the agency.</p>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">FFC</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Training</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Overall</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agentData as $agent)
                    @php
                        $dotTokens = ['green' => 'var(--ds-green, #059669)', 'amber' => 'var(--ds-amber, #f59e0b)', 'red' => 'var(--ds-crimson, #c41e3a)'];
                        $badgeVariant = ['green' => 'ds-badge-success', 'amber' => 'ds-badge-warning', 'red' => 'ds-badge-danger'];
                        $badgeLabels = ['green' => 'Compliant', 'amber' => 'At Risk', 'red' => 'Non-Compliant'];
                    @endphp
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $agent['name'] }}</div>
                                @if($agent['designation'])
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                                    {{ \Illuminate\Support\Str::limit($agent['designation'], 20) }}
                                </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $dotTokens[$agent['ffc']['status']] }};"></span>
                                <span class="text-xs" style="color: var(--text-secondary);">{{ $agent['ffc']['label'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $dotTokens[$agent['training']['status']] }};"></span>
                                <span class="text-xs" style="color: var(--text-secondary);">{{ $agent['training']['label'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="ds-badge {{ $badgeVariant[$agent['overall']] }}">{{ $badgeLabels[$agent['overall']] }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- Requires attention alert --}}
    @if(!empty($expiringSoon))
    <div class="rounded-md px-4 py-3"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                color: var(--text-primary);">
        <div class="flex items-start gap-3 mb-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-amber, #f59e0b);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <strong class="text-sm font-semibold">Requires Attention</strong>
        </div>
        <div class="space-y-2">
            @foreach($expiringSoon as $item)
            <div class="flex items-center justify-between gap-3 py-2 px-3 rounded-md"
                 style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm" style="color: var(--text-primary);">
                    <span class="font-semibold">{{ $item['agent_name'] }}</span>
                    <span style="color: var(--text-muted);"> — </span>
                    <span style="color: var(--text-secondary);">{{ $item['item'] }} {{ $item['detail'] }}</span>
                </div>
                <button type="button" disabled title="Coming soon" class="corex-btn-outline disabled:opacity-40 disabled:cursor-not-allowed">
                    Notify
                </button>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
