{{--
    2026-08-19 (Johan, Phase 2 — ROI showpiece) — the ONE place a comparison
    delta renders as text, shared by the Agency Performance report's company
    tiles and buyer-activity tiles (and reusable anywhere else a
    PeriodComparison::compute() shape needs to read as a sentence, not a bare
    coloured number).

    Rules this implements, straight from the brief:
      - "Every delta reads as '+R124k vs previous period'" — never a bare
        number. $phrase is the SAME string used by the hero line
        (comparisonMeta.phrase), so it can never say something different here
        than it says there.
      - "arrow + colour + label, never colour alone" — the arrow reflects the
        actual numeric direction (up/down); the colour reflects $c['good']
        (the metric's OWN declared direction-of-good — never the raw sign).
      - Comparison-zero / both-zero edge cases render exactly as
        PeriodComparison::compute() defines them (§4 of the spec).

    Props: c (the PeriodComparison shape, or null — renders nothing),
    phrase (string, e.g. "vs previous period"), money (bool, Rand-format the
    delta instead of a plain number).
--}}
@props(['c' => null, 'phrase' => '', 'money' => false])
@if($c && !($c['value'] == 0 && $c['previous'] == 0))
    @php
        $up = $c['delta'] > 0;
        $down = $c['delta'] < 0;
        $cls = $c['good'] === null ? 'report-delta-neutral' : ($c['good'] ? 'report-delta-good' : 'report-delta-bad');
        $abs = abs($c['delta']);
        $amount = $money ? 'R ' . number_format($abs) : number_format($abs);
    @endphp
    <div class="report-delta {{ $cls }}">
        {{ $up ? '▲' : ($down ? '▼' : '') }}
        {{ $up ? '+' : ($down ? '-' : '') }}{{ $amount }}{{ $c['delta_pct'] !== null ? ' (' . ($c['delta_pct'] > 0 ? '+' : '') . $c['delta_pct'] . '%)' : '' }}
        {{ $phrase }}
    </div>
@elseif($c)
    <div class="report-delta report-delta-neutral">&mdash; {{ $phrase }}</div>
@endif
