{{-- MIC "Show full ad" (mic-work-tab.md 13.16b item 4): the advert read with Get full details, shown inside CoreX as our own ad page. Rendered at the top of the Full card (_work-record-card)
     when it is asked for with _ad=1; the price history, the Listed-by entries (Adverts), alerts, deeds and nearby sales that follow are the Full card's own. Input: $r = MicWorkList::forFiles() record.
     Phone numbers are never read from a portal (spec 11.2), so the agent is shown by name. --}}
@php
    $m = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $fa = $r['facts'] ?? [];
    $photos = is_array($r['photo_urls'] ?? null) ? array_values(array_filter($r['photo_urls'], 'is_string')) : [];
    $dt = $r['details'] ?? [];
    $feat = [];
    foreach ((is_array($r['features'] ?? null) ? $r['features'] : []) as $fk => $fv) {
        $fk = trim((string) $fk); $fvs = trim(is_scalar($fv) ? (string) $fv : '');
        if ($fk !== '' && $fvs !== '') { $feat[$fk] = $fvs; }
    }
    $bits = array_filter([
        !empty($fa['beds']) ? $fa['beds'] . ' bed' : null, !empty($fa['baths']) ? $fa['baths'] . ' bath' : null, !empty($fa['garages']) ? $fa['garages'] . ' garage' : null,
        !empty($r['parking']) ? $r['parking'] . ' parking' : null, !empty($r['erf_size_m2']) ? 'Erf ' . number_format((float) $r['erf_size_m2']) . ' m²' : null,
        !empty($r['floor_size_m2']) ? 'Floor ' . number_format((float) $r['floor_size_m2']) . ' m²' : null,
    ]);
@endphp
<div data-qa="full-ad" style="margin: 0 0 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border); color: var(--text-primary); font-size: .875rem;">
    <div style="display:flex; align-items:baseline; gap:10px; flex-wrap:wrap;">
        <span style="font-size:1.5rem; font-weight:800;">{{ !empty($r['price']) ? $m($r['price']) : '—' }}</span>
        <span style="font-size:1.0625rem; font-weight:600;">{{ trim((string) ($r['card_heading'] ?? '')) !== '' ? $r['card_heading'] : trim(($fa['beds'] ?? '') . ' ' . ($fa['type'] ?? 'Property')) }}</span>
        <span style="color: var(--text-secondary);">{{ $r['suburb'] ?? '' }}@if(!empty($r['address'])) · {{ $r['address'] }}@endif</span>
    </div>
    @if($photos)
        <div data-fullad-gallery data-photos='@json($photos)' style="margin: 10px 0;">
            <div style="position:relative; background: var(--surface-2); border-radius:6px; overflow:hidden; max-width:760px;">
                <img data-fullad-main src="{{ $photos[0] }}" alt="" referrerpolicy="no-referrer" style="display:block; width:100%; max-height:430px; object-fit:contain; background:#0f172a;">
                @if(count($photos) > 1)
                    <button type="button" data-fullad-prev aria-label="Previous photo" class="mi-gallery-arrow" style="left:8px; opacity:1;">‹</button>
                    <button type="button" data-fullad-next aria-label="Next photo" class="mi-gallery-arrow" style="right:8px; opacity:1;">›</button>
                @endif
                <span data-fullad-count style="position:absolute; top:8px; right:8px; padding:1px 7px; font-size:.6875rem; font-weight:700; color:#fff; background: rgba(15,23,42,.82); border-radius:3px;">1 of {{ count($photos) }}</span>
            </div>
            @if(count($photos) > 1)
                <div style="display:flex; gap:4px; margin-top:6px; overflow-x:auto; max-width:760px;">
                    @foreach($photos as $pi => $pu)
                        <img data-fullad-thumb="{{ $pi }}" src="{{ $pu }}" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:64px; height:48px; object-fit:cover; border-radius:3px; cursor:pointer; flex:none; border:2px solid transparent;">
                    @endforeach
                </div>
            @endif
        </div>
    @endif
    @if($bits)<div data-qa="full-ad-facts" style="margin: 6px 0; font-weight:600;">{{ implode(' · ', $bits) }}</div>@endif
    @if(trim((string) ($r['description'] ?? '')) !== '')
        <div data-qa="full-ad-wording" style="margin: 8px 0; max-width: 760px; line-height:1.5; white-space: pre-line;">{{ trim($r['description']) }}</div>
    @endif
    @if($feat)
        <div style="font-weight:700; margin: 8px 0 3px;">Features</div>
        <div data-qa="full-ad-features" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 2px 14px; max-width:760px;">
            @foreach($feat as $fk => $fv)<div><span style="color: var(--text-secondary);">{{ $fk }}</span> {{ in_array(mb_strtolower($fv), ['yes', 'true', '1'], true) ? '✓' : $fv }}</div>@endforeach
        </div>
    @endif
    <div style="font-weight:700; margin: 8px 0 3px;">The advert</div>
    <div data-qa="full-ad-meta" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 2px 14px; max-width:760px;">
        @if(!empty($r['listed_date']))<div><span style="color: var(--text-secondary);">Listed</span> {{ \Carbon\Carbon::parse($r['listed_date'])->format('j M Y') }}</div>@endif
        @if(!empty($r['agent_name']))<div><span style="color: var(--text-secondary);">Agent</span> {{ $r['agent_name'] }}</div>@endif
        @if(!empty($r['agencies'][0]))<div><span style="color: var(--text-secondary);">Agency</span> {{ $r['agencies'][0] }}</div>@endif
        @if(!empty($r['rates_amount']))<div><span style="color: var(--text-secondary);">Rates</span> {{ $m($r['rates_amount']) }} pm</div>@endif
        @if(!empty($r['levies_amount']))<div><span style="color: var(--text-secondary);">Levies</span> {{ $m($r['levies_amount']) }} pm</div>@endif
        @if(!empty($dt['read_on']))<div><span style="color: var(--text-secondary);">Read</span> {{ $dt['read_on'] }}@if(!empty($dt['read_by'])) by {{ $dt['read_by'] }}@endif</div>@endif
    </div>
    @if(!empty($dt['stale']))<div style="margin-top:6px; color: var(--ds-amber, #b45309);" data-qa="full-ad-stale">The advert has changed since it was read - use Refresh details on the card.</div>@endif
    <div style="margin-top:6px; color: var(--text-muted); font-size:.75rem;">Phone numbers are not read from the portals; open the advert on the portal for the agent's number.@if(!empty($dt['target']['url'])) <a href="{{ $dt['target']['url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon);">Open on {{ strtoupper($dt['target']['portal']) }}</a>@endif</div>
</div>
