@php
    $kpis = $snapshotKpis ?? [];
    $micThreshold  = (int) ($kpis['match_threshold'] ?? 75);
    $micScoreMin   = (int) request('score_min', $micThreshold);
    $micScoreMax   = (int) request('score_max', 100);
    $micBandActive = request()->filled('score_min') || request()->filled('score_max');
@endphp
{{-- AT-75 — buyer-match %-band slider. Dual native ranges (presentations
     review.blade.php pattern): crossing handles via Math.min/Math.max, live
     tabular-nums label on drag, navigate on release (full-page GET, consistent
     with every other MIC filter). Filters the worklist to the band + sorts %-desc. --}}
<div class="mi-match-band" data-work-url="{{ route('market-intelligence.work', request()->except(['page','score_min','score_max','sort','action_preset'])) }}"
     style="padding: 6px 12px; background: var(--surface, #ffffff); border-bottom: 1px solid var(--border, rgba(0,0,0,0.07));
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 11px; color: var(--text-muted, #9ca3af);">
    <span style="font-weight:600;">Buyer match %</span>
    <input type="range" id="mic-score-min" min="0" max="100" step="5" value="{{ max(0, min(100, $micScoreMin)) }}" style="flex:1; min-width:120px;">
    <input type="range" id="mic-score-max" min="0" max="100" step="5" value="{{ max(0, min(100, $micScoreMax)) }}" style="flex:1; min-width:120px;">
    <span id="mic-score-label" style="min-width:96px; text-align:right; font-variant-numeric:tabular-nums; font-weight:600; color: var(--text-primary, #111827);"></span>
    @if($micBandActive)
        <a href="{{ route('market-intelligence.work', request()->except(['page','score_min','score_max','sort'])) }}"
           style="color: var(--brand-icon, #0ea5e9); text-decoration:none; font-weight:600;">clear</a>
    @endif
</div>
<script>
(function () {
    var root  = document.querySelector('.mi-match-band');
    if (!root) return;
    var smin  = document.getElementById('mic-score-min'),
        smax  = document.getElementById('mic-score-max'),
        label = document.getElementById('mic-score-label'),
        base  = root.getAttribute('data-work-url');
    function bounds() {
        var lo = Math.min(+smin.value, +smax.value), hi = Math.max(+smin.value, +smax.value);
        if (label) label.textContent = lo + '% – ' + hi + '%';
        return [lo, hi];
    }
    function navigate() {
        var b = bounds(), sep = base.indexOf('?') === -1 ? '?' : '&';
        window.location = base + sep + 'score_min=' + b[0] + '&score_max=' + b[1] + '&sort=match_score';
    }
    if (smin && smax) {
        // Live label on drag; commit (navigate) on release — debounced via 'change'.
        smin.addEventListener('input', bounds);
        smax.addEventListener('input', bounds);
        smin.addEventListener('change', navigate);
        smax.addEventListener('change', navigate);
        bounds();
    }
})();
</script>
