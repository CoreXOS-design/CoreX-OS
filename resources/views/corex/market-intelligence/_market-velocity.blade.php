{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.6 — Market velocity (days-on-market by price band).
    Empty-state with "—" tiles when no deals data is available.
    Spec: §9.4.
--}}
@php
    $v = $velocity ?? ['bands' => [], 'data_available' => false];
@endphp

<div class="mi-card">
    <div class="mi-card-title">Market velocity</div>
    <div class="mi-card-subtitle">avg days on market by price band · last 90 days</div>

    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
        @foreach($v['bands'] as $band)
        @php
            $hasData = $band['days_on_market'] !== null;
            $delta = $band['delta_days'];
            $deltaColor = $delta === null
                ? 'var(--text-muted)'
                : ($delta < 0 ? 'var(--ds-green, #10b981)' : ($delta > 0 ? 'var(--ds-crimson, #dc2626)' : 'var(--text-muted)'));
            $deltaSymbol = $delta === null ? '—' : ($delta < 0 ? '↓' : ($delta > 0 ? '↑' : '–'));
        @endphp
        <div style="background: var(--surface-2); border: 1px solid var(--border); padding: 8px 10px; border-radius: 4px;">
            <div style="font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 4px;">
                {{ $band['band'] }}
            </div>
            <div style="display: flex; align-items: baseline; gap: 4px;">
                <span style="font-size: 1.125rem; font-weight: 700; color: {{ $hasData ? 'var(--text-primary)' : 'var(--text-muted)' }};">
                    {{ $hasData ? $band['days_on_market'] : '—' }}
                </span>
                @if($hasData)
                <span style="font-size: 0.6875rem; color: var(--text-muted);">days</span>
                @endif
            </div>
            <div style="font-size: 0.625rem; color: {{ $deltaColor }}; margin-top: 2px;">
                {{ $deltaSymbol }}
                @if($delta !== null)
                    {{ abs($delta) }}d vs prior 90d
                @else
                    no comparison
                @endif
                @if(!empty($band['sold_count']))
                    · {{ $band['sold_count'] }} sold
                @endif
            </div>
        </div>
        @endforeach
    </div>

    @if(! $v['data_available'])
    <div style="margin-top: 10px; padding: 8px 10px; background: var(--surface-2); border: 1px dashed var(--border); border-radius: 4px; font-size: 0.6875rem; color: var(--text-muted);">
        Velocity needs registered deals in the last 90 days to populate. Once mandates close and register, this panel comes alive automatically.
    </div>
    @endif
</div>
