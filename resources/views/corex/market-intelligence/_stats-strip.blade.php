{{--
    F.3/F.7/D2 — Stats strip (simplified to the 5 high-signal tiles per
    MIC spec §5.3.2).

    Kept: BUYER MATCHED · PITCH NOW · HIGH · MY CLAIMS · EXPIRING · NEW TODAY
    Dropped (still computed, used internally — just not surfaced as headline):
      ACTIVE        (implicit — what's in the list IS active)
      IN STOCK      (managers toggle via filter rail; row badge in listing list)
      CROSS-LISTED  (row badge / tooltip)
      PITCH NOW     (the non-high variant — managers/agents see the high tier)
      LOG OUTCOMES  (filter rail entry)

    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20.
    Every colour resolves via a documented token from §1 with a fallback
    hex per §5.10.

    Spec: .ai/specs/mic-complete-spec.md §5.3.2, build-f-market-intelligence-redesign-spec.md §8.2.
--}}
@php
    $kpis = $snapshotKpis ?? ['active'=>0,'buyers_matched'=>0,'properties_matched'=>0,'match_threshold'=>75,'buyer_matched'=>0,'in_stock'=>0,'new_today'=>0,'cross_listed'=>0];
    $presets = $actionPresetCounts ?? ['pitch_now_high'=>0,'pitch_now'=>0,'log_outcomes'=>0,'my_claims'=>0,'expiring'=>0];
    $activeActionPreset = $actionPreset ?? null;

    // AT-75 — buyer-match threshold + active %-band (slider/tile state).
    $micThreshold  = (int) ($kpis['match_threshold'] ?? 75);
    $micScoreMin   = (int) request('score_min', $micThreshold);
    $micScoreMax   = (int) request('score_max', 100);
    $micBandActive = request()->filled('score_min') || request()->filled('score_max');

    $urlWithPreset = function (string $key) {
        $params = array_merge(request()->except(['action_preset', 'page']), ['action_preset' => $key]);
        return route('market-intelligence.work', $params);
    };
    $urlClearPreset = route('market-intelligence.work', request()->except(['action_preset', 'page']));

    // AT-75 — the "Buyer matched" tile links the worklist to the threshold band.
    $urlBuyerMatched = route('market-intelligence.work', array_merge(
        request()->except(['page', 'score_min', 'score_max', 'sort', 'action_preset']),
        ['score_min' => $micThreshold, 'score_max' => 100, 'sort' => 'match_score']
    ));

    // Defensive token + fallback pattern per UI_DESIGN_SYSTEM.md §5.10
    $tileBaseStyle = 'background: var(--surface, #ffffff); border: 1px solid var(--border, rgba(0,0,0,0.07)); padding: 6px 8px; border-radius: 6px; min-width: 0;';
    $tileActiveStyle = 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, var(--surface, #ffffff)); border: 1px solid var(--brand-icon, #0ea5e9); padding: 6px 8px; border-radius: 6px; min-width: 0;';
    $labelStyle = 'font-size: 0.6125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; color: var(--text-muted, #9ca3af); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
    $valueStyle = 'font-size: 1rem; font-weight: 600; color: var(--text-primary, #111827); line-height: 1.1;';

    // The simplified 5-tile cockpit. Each tile is clickable; clicking sets the
    // matching action_preset query param, which the controller honours.
    $tiles = [
        ['type'=>'buyer_matched', 'label'=>'Buyer matched', 'accent'=>'var(--ds-green, #10b981)',
         'buyers'=>(int)($kpis['buyers_matched'] ?? 0), 'properties'=>(int)($kpis['properties_matched'] ?? 0),
         'threshold'=>$micThreshold, 'href'=>$urlBuyerMatched,
         'tip'=>'Distinct buyers with a real match at '.$micThreshold.'%+ · and how many canvass listings they match at that strength. Click to filter the worklist to this band.'],
        ['type'=>'preset',   'key'=>'pitch_now_high',   'label'=>'Pitch now · high',            'value'=>$presets['pitch_now_high'], 'accent'=>'var(--ds-green, #10b981)','tip'=>'Listings with 3+ strong-tier buyers and no recent pitch — your highest-conversion opportunities. Click to filter.'],
        ['type'=>'preset',   'key'=>'my_claims',        'label'=>'My claims',                    'value'=>$presets['my_claims'],      'accent'=>'var(--brand-icon, #0ea5e9)','tip'=>'Listings you have claimed and are working. Click to filter.'],
        ['type'=>'preset',   'key'=>'expiring',         'label'=>'Expiring',                     'value'=>$presets['expiring'],       'accent'=>'var(--ds-amber, #f59e0b)','tip'=>'Your claims that auto-release in under 6 hours unless you log feedback. Click to filter.'],
        ['type'=>'snapshot', 'label'=>'New today',      'value'=>$kpis['new_today'],            'accent'=>'var(--text-primary)',     'tip'=>'New listings captured today.'],
    ];
@endphp

<div class="mi-stats-strip"
     style="padding: 8px 12px; background: var(--surface-2, #f0f2f8); border-bottom: 1px solid var(--border, rgba(0,0,0,0.07));
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 6px;">

    @foreach($tiles as $tile)
        @php $tip = $tile['tip'] ?? ''; @endphp
        @if($tile['type'] === 'buyer_matched')
            <a href="{{ $tile['href'] }}"
               style="text-decoration: none; {{ $micBandActive ? $tileActiveStyle : $tileBaseStyle }} display: block; cursor: pointer;"
               title="{{ $tip }}">
                <div style="{{ $labelStyle }}">{{ $tile['label'] }} · {{ $tile['threshold'] }}%+</div>
                <div style="display:flex; align-items:baseline; gap:6px;">
                    <span style="{{ $valueStyle }}; color: {{ $tile['buyers'] > 0 ? $tile['accent'] : 'var(--text-muted)' }};">{{ number_format($tile['buyers']) }}</span>
                    <span style="font-size:0.625rem; font-weight:600; color: var(--text-muted, #9ca3af);">{{ \Illuminate\Support\Str::plural('buyer', $tile['buyers']) }}</span>
                    <span style="font-size:0.625rem; color: var(--text-muted, #9ca3af);">·</span>
                    <span style="font-size:0.8125rem; font-weight:600; color: var(--text-primary, #111827);">{{ number_format($tile['properties']) }}</span>
                    <span style="font-size:0.625rem; font-weight:600; color: var(--text-muted, #9ca3af);">{{ \Illuminate\Support\Str::plural('property', $tile['properties']) }}</span>
                </div>
            </a>
        @elseif($tile['type'] === 'snapshot')
            <div style="{{ $tileBaseStyle }}" title="{{ $tip }}">
                <div style="{{ $labelStyle }}">{{ $tile['label'] }}</div>
                <div style="{{ $valueStyle }}; color: {{ $tile['value'] > 0 ? $tile['accent'] : 'var(--text-muted)' }};">{{ number_format($tile['value']) }}</div>
            </div>
        @else
            @php
                $isActive = $activeActionPreset === $tile['key'];
                $href = $isActive ? $urlClearPreset : $urlWithPreset($tile['key']);
                $tooltip = $isActive ? ($tip . ' Currently active — click to clear.') : $tip;
            @endphp
            <a href="{{ $href }}"
               style="text-decoration: none; {{ $isActive ? $tileActiveStyle : $tileBaseStyle }} display: block; cursor: pointer;"
               title="{{ $tooltip }}">
                <div style="{{ $labelStyle }}; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ $tile['label'] }}</div>
                <div style="{{ $valueStyle }}; color: {{ $tile['value'] > 0 ? $tile['accent'] : 'var(--text-muted)' }};">{{ number_format($tile['value']) }}</div>
            </a>
        @endif
    @endforeach
</div>

{{-- AT-75 — buyer-match %-band slider. Dual native ranges (presentations
     review.blade.php pattern): crossing handles via Math.min/Math.max, live
     tabular-nums label on drag, navigate on release (full-page GET, consistent
     with every other MIC filter). Filters the worklist to the band + sorts %-desc. --}}
<div class="mi-match-band" data-work-url="{{ route('market-intelligence.work', request()->except(['page','score_min','score_max','sort','action_preset'])) }}"
     style="padding: 6px 12px; background: var(--surface-2, #f0f2f8); border-bottom: 1px solid var(--border, rgba(0,0,0,0.07));
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
