{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Overview tab.

    Four sections in order: summary band, top-5 buyers, market position,
    latest activity (3-6 events).
--}}
@php
    $o = $overview;
    $tb = $tierBreakdown ?? ['strong'=>0,'mid'=>0,'weak'=>0,'total'=>0];

    $sectionTitle = 'font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 8px;';
@endphp

<div style="padding: 16px;">
    {{-- Summary band — Ellie placeholder text for F.4 --}}
    <div style="padding: 12px 14px; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface));
                border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 20%, transparent);
                border-radius: 6px; margin-bottom: 16px;">
        <div style="font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--brand-icon); margin-bottom: 4px;">
            Summary
        </div>
        <div style="font-size: 0.8125rem; color: var(--text-primary); line-height: 1.5;">
            {{ $o['summary'] }}
        </div>
    </div>

    {{-- Top 5 buyers --}}
    <div style="margin-bottom: 16px;">
        <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 8px;">
            <div style="{{ $sectionTitle }}; margin: 0;">Top matched buyers</div>
            @if($tb['total'] > 5)
            <button type="button"
                    onclick="this.closest('.mi-tabs').querySelector('button[data-tab=buyers]').click()"
                    style="font-size: 0.6875rem; color: var(--brand-icon); background: none; border: none; cursor: pointer;">
                See all {{ $tb['total'] }} →
            </button>
            @endif
        </div>
        @if($o['top_buyers']->isEmpty())
            <div style="padding: 10px; color: var(--text-muted); font-size: 0.8125rem;">No matched buyers yet.</div>
        @else
        <div style="display: flex; flex-direction: column; gap: 6px;">
            @foreach($o['top_buyers'] as $b)
                @include('corex.market-intelligence._slideover-buyer-row', ['b' => $b])
            @endforeach
        </div>
        @endif
    </div>

    {{-- Market position --}}
    <div style="margin-bottom: 16px;">
        <div style="{{ $sectionTitle }}">Market position</div>
        @php $mp = $o['market_position']; @endphp
        @if($mp['suburb_median'] === null)
            <div style="font-size: 0.8125rem; color: var(--text-muted);">Not enough comparable data in the last 180 days.</div>
        @else
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;">
            <div style="background: var(--surface-2); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--border);">
                <div style="font-size: 0.625rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.02em;">Suburb median</div>
                <div style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); margin-top: 2px;">R {{ number_format($mp['suburb_median']) }}</div>
            </div>
            <div style="background: var(--surface-2); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--border);">
                <div style="font-size: 0.625rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.02em;">This vs median</div>
                @php $sign = ($mp['this_vs_median'] ?? 0) >= 0 ? '+' : ''; $color = ($mp['this_vs_median'] ?? 0) >= 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--ds-green, #10b981)'; @endphp
                <div style="font-size: 0.9375rem; font-weight: 600; color: {{ $color }}; margin-top: 2px;">{{ $sign }}{{ $mp['this_vs_median'] !== null ? $mp['this_vs_median'] . '%' : '—' }}</div>
            </div>
            <div style="background: var(--surface-2); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--border);">
                <div style="font-size: 0.625rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.02em;">YoY trend</div>
                @if($mp['yoy_trend'] !== null)
                    @php $ysign = $mp['yoy_trend'] >= 0 ? '+' : ''; @endphp
                    <div style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); margin-top: 2px;">{{ $ysign }}{{ $mp['yoy_trend'] }}%</div>
                @else
                    <div style="font-size: 0.9375rem; color: var(--text-muted); margin-top: 2px;">—</div>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Latest activity --}}
    <div>
        <div style="{{ $sectionTitle }}">Latest activity</div>
        @if($o['latest_activity']->isEmpty())
            <div style="font-size: 0.8125rem; color: var(--text-muted);">No activity recorded.</div>
        @else
        <div style="display: flex; flex-direction: column; gap: 8px;">
            @foreach($o['latest_activity'] as $event)
                @include('corex.market-intelligence._slideover-activity-entry', ['entry' => $event])
            @endforeach
        </div>
        @endif
    </div>
</div>
