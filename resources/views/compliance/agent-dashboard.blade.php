@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Agent Compliance Dashboard</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">FFC, training, and compliance status for all agents.</div>
    </div>

    {{-- ══════════════════════════════════════
         SUMMARY CARDS
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Total Agents</div>
            <div class="text-2xl font-extrabold" style="color:#0ea5e9;">{{ $totalAgents }}</div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Fully Compliant</div>
            <div class="text-2xl font-extrabold" style="color:#22c55e;">{{ $compliantCount }}</div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">At Risk</div>
            <div class="text-2xl font-extrabold" style="color:#f59e0b;">{{ $atRiskCount }}</div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Non-Compliant</div>
            <div class="text-2xl font-extrabold" style="color:#ef4444;">{{ $nonCompliantCount }}</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         AGENT TABLE
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Agent Status</h3>
        </div>

        @if($agentData->isEmpty())
            <div class="p-8 text-center">
                <div class="text-sm" style="color:var(--text-secondary);">No active agents found.</div>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); background:var(--surface-2, rgba(0,0,0,0.05));">
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Agent</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">FFC</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Training</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Overall</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agentData as $agent)
                    @php
                        $dotColors = ['green' => '#22c55e', 'amber' => '#f59e0b', 'red' => '#ef4444'];
                        $badgeBg = ['green' => 'rgba(34,197,94,0.12)', 'amber' => 'rgba(245,158,11,0.12)', 'red' => 'rgba(239,68,68,0.12)'];
                        $badgeLabels = ['green' => 'Compliant', 'amber' => 'At Risk', 'red' => 'Non-Compliant'];
                    @endphp
                    <tr style="border-bottom:1px solid var(--border);" class="hover:bg-white/5 transition-colors">
                        {{-- Agent --}}
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $agent['name'] }}</div>
                                @if($agent['designation'])
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-medium" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">
                                    {{ \Illuminate\Support\Str::limit($agent['designation'], 20) }}
                                </span>
                                @endif
                            </div>
                        </td>

                        {{-- FFC --}}
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColors[$agent['ffc']['status']] }};"></span>
                                <span class="text-xs" style="color:var(--text-secondary);">{{ $agent['ffc']['label'] }}</span>
                            </div>
                        </td>

                        {{-- Training --}}
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColors[$agent['training']['status']] }};"></span>
                                <span class="text-xs" style="color:var(--text-secondary);">{{ $agent['training']['label'] }}</span>
                            </div>
                        </td>

                        {{-- Overall --}}
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold"
                                  style="background:{{ $badgeBg[$agent['overall']] }}; color:{{ $dotColors[$agent['overall']] }};">
                                {{ $badgeLabels[$agent['overall']] }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         EXPIRING SOON
         ══════════════════════════════════════ --}}
    @if(!empty($expiringSoon))
    <div style="background:var(--surface); border:2px solid #f59e0b; border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-3 flex items-center gap-2" style="color:#f59e0b;">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            Requires Attention
        </h3>
        <div class="space-y-2">
            @foreach($expiringSoon as $item)
            <div class="flex items-center justify-between py-2 px-3 rounded-md" style="background:rgba(245,158,11,0.06); border:1px solid rgba(245,158,11,0.15);">
                <div class="text-sm" style="color:var(--text-primary);">
                    <span class="font-semibold">{{ $item['agent_name'] }}</span>
                    <span style="color:var(--text-muted);"> — </span>
                    <span style="color:var(--text-secondary);">{{ $item['item'] }} {{ $item['detail'] }}</span>
                </div>
                <button type="button" disabled title="Coming soon"
                        class="text-xs px-3 py-1 rounded cursor-not-allowed opacity-50"
                        style="background:rgba(245,158,11,0.12); color:#f59e0b; border:1px solid rgba(245,158,11,0.25);">
                    Notify
                </button>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
