{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Buyers tab: tier breakdown + full list of matched buyers.
--}}
@php
    $tb = $buyers['tier_breakdown'] ?? ['strong'=>0,'mid'=>0,'weak'=>0,'total'=>0,'top_score'=>null];
@endphp

<div style="padding: 16px;">
    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; font-size: 0.6875rem; font-weight: 600; border-radius: 999px; background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent); color: var(--ds-green, #10b981); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 30%, transparent);">
            🟢 {{ $tb['strong'] }} strong
        </span>
        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; font-size: 0.6875rem; font-weight: 600; border-radius: 999px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); color: var(--ds-amber, #f59e0b); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);">
            🟡 {{ $tb['mid'] }} mid
        </span>
        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; font-size: 0.6875rem; font-weight: 600; border-radius: 999px; background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
            ⚪ {{ $tb['weak'] }} weak
        </span>
        <span style="margin-left: auto; font-size: 0.75rem; color: var(--text-secondary); align-self: center;">
            <strong style="color: var(--text-primary);">{{ $tb['total'] }}</strong> total
            @if($tb['top_score'] !== null)
                · top score <strong style="color: var(--text-primary);">{{ $tb['top_score'] }}%</strong>
            @endif
        </span>
    </div>

    @if($buyers['all']->isEmpty())
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
            No matched buyers yet.
        </div>
    @else
        <div style="display: flex; flex-direction: column; gap: 6px;">
            @foreach($buyers['all'] as $b)
                @include('corex.market-intelligence._slideover-buyer-row', ['b' => $b])
            @endforeach
        </div>
    @endif
</div>
