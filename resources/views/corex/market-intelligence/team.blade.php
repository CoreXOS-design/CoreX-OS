{{-- MIC Phase G2 — BM Team dashboard. Spec §10.2.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width: 100%;">

    <x-mic-page-header
        title="Team"
        subtitle="Per-agent claim + outreach health. Sorted worst performers first — high stale count, low feedback rate." />

    @include('corex.market-intelligence.partials.tabs')

    @if($rows->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No agents yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Once agents join this agency, their claim and outreach health appears here.</p>
        </div>
    @else
        {{-- Team health table (§3.7) --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active claims</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Feedback %</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiring 24h</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Stale flagged</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pitches 30d</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Presentations 30d</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                // Score colours never use red — low metrics are "needs
                                // attention" (amber), not danger (§1.5 / Strict Rule #3).
                                $feedbackColor = match (true) {
                                    $row['feedback_rate'] === null => 'var(--text-muted)',
                                    $row['feedback_rate'] >= 80    => 'var(--ds-green, #059669)',
                                    default                        => 'var(--ds-amber, #f59e0b)',
                                };
                                $staleColor = $row['stale_flagged'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-muted)';
                                $roleLabel  = ucwords(str_replace('_', ' ', (string) $row['agent']->role));
                            @endphp
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3" style="color: var(--text-primary); font-weight: 500;">
                                    {{ $row['agent']->name }}
                                    <span class="text-xs" style="color: var(--text-muted); margin-left: 6px;">{{ $roleLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-right" style="color: var(--text-primary);">{{ number_format($row['active_claims']) }}</td>
                                <td class="px-4 py-3 text-right" style="color: {{ $feedbackColor }}; font-weight: 600;">
                                    {{ $row['feedback_rate'] === null ? '—' : number_format($row['feedback_rate'], 1) . '%' }}
                                </td>
                                <td class="px-4 py-3 text-right" style="color: {{ $row['expiring_24h'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-muted)' }};">
                                    {{ $row['expiring_24h'] > 0 ? number_format($row['expiring_24h']) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right" style="color: {{ $staleColor }}; font-weight: 600;">
                                    {{ $row['stale_flagged'] > 0 ? number_format($row['stale_flagged']) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ number_format($row['pitches_30d']) }}</td>
                                <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ number_format($row['presentations_30d']) }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($row['stale_flagged'] >= 3)
                                        <button type="button"
                                                onclick="window.showToast('Coaching feature lands soon — for now, take {{ addslashes($row['agent']->name) }} for a one-on-one this week.', 'info')"
                                                class="rounded-md"
                                                style="padding: 4px 10px; font-size: 0.6875rem; font-weight: 500;
                                                       background: var(--surface); color: var(--ds-amber, #f59e0b);
                                                       border: 1px solid var(--ds-amber, #f59e0b); cursor: pointer;">
                                            Coaching nudge
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <p class="text-xs" style="color: var(--text-muted); margin: 14px 0;">
        Stats reflect the last 30 days. Stale flag set by the hourly FlagStaleClaimsJob once a claim sits 48h+ without feedback.
    </p>

</div>
@endsection
