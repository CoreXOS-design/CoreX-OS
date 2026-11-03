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

    Added 2026-10-07 (Lead Response comparison) — two optional, additive behaviours; every existing caller
    renders exactly as before: `minutes` formats the delta as a duration ("-1 h 05 min", faster = good when
    the metric's direction is lower_is_better), and a comparison shaped ['no_data' => true] renders the plain
    words "no data" for the comparison period — never a 0 or an infinity.

    Added 2026-11-02 (deal-status ticks) — two more optional props, again additive: `previousLabel` (the comparison
    period as "2026-09-01 → 2026-09-30") puts that period's ACTUAL figure next to the delta, so a reader never has
    to back-calculate it ("… ▲ +R270,682.92 (+123.2%) · 2026-09-01 → 2026-09-30: R219,714.61"; it stands in for the
    "vs …" phrase, which would only repeat the same dates), and `lead` (the current figure) goes in front of it.
    With a previousLabel, money is shown to the cent so the delta and the two figures add up exactly; every
    other caller keeps whole Rand.
--}}
@props(['c' => null, 'phrase' => '', 'money' => false, 'minutes' => false, 'previousLabel' => null, 'lead' => null])
@if($c && !empty($c['no_data']))
    <div class="report-delta report-delta-neutral">&mdash; no data {{ $phrase }}</div>
@elseif($c && !($c['value'] == 0 && $c['previous'] == 0))
    @php
        $up = $c['delta'] > 0;
        $down = $c['delta'] < 0;
        $cls = $c['good'] === null ? 'report-delta-neutral' : ($c['good'] ? 'report-delta-good' : 'report-delta-bad');
        $abs = abs($c['delta']);
        $amount = $minutes
            ? app(\App\Services\LeadResponse\LeadResponseService::class)->formatMinutes((int) round($abs))
            : ($money ? 'R ' . number_format($abs, $previousLabel ? 2 : 0) : number_format($abs));
        $previousFigure = $money ? 'R ' . number_format((float) $c['previous'], 2) : number_format((float) $c['previous']);
    @endphp
    <div class="report-delta {{ $cls }}">
        @if($lead !== null){{ $lead }} &middot; @endif
        {{ $up ? '▲' : ($down ? '▼' : '') }}
        {{ $up ? '+' : ($down ? '-' : '') }}{{ $amount }}{{ $c['delta_pct'] !== null ? ' (' . ($c['delta_pct'] > 0 ? '+' : '') . $c['delta_pct'] . '%)' : '' }}
        @if($previousLabel)&middot; {{ $previousLabel }}: {{ $previousFigure }}@else{{ $phrase }}@endif
    </div>
@elseif($c)
    <div class="report-delta report-delta-neutral">&mdash; {{ $phrase }}</div>
@endif
