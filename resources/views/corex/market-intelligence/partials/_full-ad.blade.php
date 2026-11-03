{{-- MIC "Show full ad" (mic-work-tab.md 13.16b item 5), LEFT column: the advert as a buyer reads it on P24 - header, five key facts, gallery, the description, the property features in groups, who listed it.
     Nothing of ours is in here (our intelligence is the right column: _full-ad-ours). Input: $r = MicWorkList::forFiles() record. --}}
@php
    $m = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $fa = $r['facts'] ?? [];
    $dt = $r['details'] ?? [];
    $photos = is_array($r['photo_urls'] ?? null) ? array_values(array_filter($r['photo_urls'], 'is_string')) : [];
    $g = \App\Services\Prospecting\MicWork\FullAdFeatures::group(is_array($r['features'] ?? null) ? $r['features'] : [], $r);
    $heading = trim((string) ($r['card_heading'] ?? '')) !== '' ? $r['card_heading'] : trim((!empty($fa['beds']) ? $fa['beds'] . ' Bedroom ' : '') . ($fa['type'] ?? 'Property'));
    $icon = fn (string $d) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true">' . $d . '</svg>';
    $car = '<path d="M5 16l1.5-5a2 2 0 0 1 1.9-1.4h7.2a2 2 0 0 1 1.9 1.4L19 16M4 16h16v3H4zM7 19v1M17 19v1"/>';
    $tiles = array_values(array_filter([
        !empty($fa['beds']) ? [$icon('<path d="M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7M3 15h18M3 18v2M21 18v2M7 9V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2"/>'), $fa['beds'], (int) $fa['beds'] === 1 ? 'Bed' : 'Beds'] : null,
        !empty($fa['baths']) ? [$icon('<path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3zM6 12V6a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2"/>'), $fa['baths'], (int) $fa['baths'] === 1 ? 'Bath' : 'Baths'] : null,
        !empty($fa['garages']) ? [$icon($car), $fa['garages'], (int) $fa['garages'] === 1 ? 'Garage' : 'Garages']
            : (!empty($r['parking']) ? [$icon($car), $r['parking'], 'Parking'] : null),
        !empty($r['erf_size_m2']) ? [$icon('<path d="M4 4h16v16H4zM4 9h5M15 4v5"/>'), number_format((float) $r['erf_size_m2']) . ' m²', 'Erf'] : null,
        !empty($r['floor_size_m2']) ? [$icon('<path d="M3 11l9-7 9 7M5 10v10h14V10"/>'), number_format((float) $r['floor_size_m2']) . ' m²', 'Floor'] : null,
    ]));
    $paras = preg_split('/\n\s*\n/', str_replace("\r", '', trim((string) ($r['description'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lab = 'color: var(--text-secondary);';
    $listed = array_filter([
        !empty($r['agencies'][0]) ? $r['agencies'][0] : null,
        !empty($r['agent_name']) ? $r['agent_name'] : null,
        !empty($r['listed_date']) ? 'listed ' . \Carbon\Carbon::parse($r['listed_date'])->format('j M Y') : null,
    ]);
    $tgt = $dt['target'] ?? null;
@endphp
<div data-qa="full-ad" style="color: var(--text-primary); font-size: .9375rem; max-width: 820px;">
    <div style="display:flex; align-items:baseline; gap:6px 14px; flex-wrap:wrap;" data-qa="full-ad-header">
        <span style="font-size:1.75rem; font-weight:800; line-height:1.2;">{{ !empty($r['price']) ? $m($r['price']) : '—' }}</span>
        <span style="font-size:1.125rem; font-weight:600;">{{ $heading }}</span>
    </div>
    <div style="margin-top:2px; {{ $lab }}">{{ $r['suburb'] ?? '' }}@if(!empty($r['address'])) · {{ $r['address'] }}@endif</div>

    @if($tiles)
        <div data-qa="full-ad-facts" style="display:flex; gap:10px; flex-wrap:wrap; margin:14px 0;">
            @foreach($tiles as [$ic, $fig, $lbl])
                <div data-qa="fact-tile" style="min-width:96px; padding:10px 14px; border:1px solid var(--border); border-radius:8px; display:flex; flex-direction:column; align-items:flex-start; gap:2px;">
                    <span style="{{ $lab }}">{!! $ic !!}</span>
                    <span style="font-size:1.25rem; font-weight:800; line-height:1.2;">{{ $fig }}</span>
                    <span style="font-size:.75rem; {{ $lab }}">{{ $lbl }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if($photos)
        <div data-fullad-gallery data-photos='@json($photos)' data-qa="full-ad-gallery" style="margin: 0 0 16px;">
            <div style="position:relative; background:#0f172a; border-radius:8px; overflow:hidden;">
                <img data-fullad-main src="{{ $photos[0] }}" alt="" referrerpolicy="no-referrer" style="display:block; width:100%; height:min(430px, 46vh); object-fit:contain;">
                @if(count($photos) > 1)
                    <button type="button" data-fullad-prev aria-label="Previous photo" class="mi-gallery-arrow" style="left:10px; opacity:1;">‹</button>
                    <button type="button" data-fullad-next aria-label="Next photo" class="mi-gallery-arrow" style="right:10px; opacity:1;">›</button>
                @endif
                <span data-fullad-count style="position:absolute; top:10px; right:10px; padding:1px 8px; font-size:.75rem; font-weight:700; color:#fff; background: rgba(15,23,42,.82); border-radius:3px;">1 of {{ count($photos) }}</span>
            </div>
            @if(count($photos) > 1)
                <div style="margin-top:8px; display:flex; align-items:center; gap:4px;">
                    <button type="button" data-fullad-strip-prev aria-label="Earlier photos" class="mi-strip-arrow">‹</button>
                    <div data-fullad-strip class="mi-strip" style="display:flex; gap:6px; overflow-x:auto; flex:1; min-width:0;">
                        @foreach($photos as $pi => $pu)
                            <img data-fullad-thumb="{{ $pi }}" src="{{ $pu }}" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:84px; height:63px; object-fit:cover; border-radius:4px; cursor:pointer; flex:none; border:2px solid {{ $pi === 0 ? 'var(--brand-icon)' : 'transparent' }};">
                        @endforeach
                    </div>
                    <button type="button" data-fullad-strip-next aria-label="More photos" class="mi-strip-arrow">›</button>
                </div>
            @endif
        </div>
    @endif

    @if($paras)
        <div data-qa="full-ad-wording" style="max-width: 70ch; line-height:1.65; margin: 0 0 18px;">
            @foreach($paras as $p)<p style="margin: 0 0 .85em;">{!! nl2br(e(trim($p))) !!}</p>@endforeach
        </div>
    @endif

    @if($g['groups'] || $g['room_details'])
        <div style="font-size:1.0625rem; font-weight:800; margin: 4px 0 8px;">Property features</div>
        <div data-qa="full-ad-features" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 4px 36px;">
            @foreach($g['groups'] as $gname => $rows)
                <section data-qa="feature-group" data-group="{{ $gname }}" style="margin-bottom:14px;">
                    <div style="font-weight:800; margin-bottom:4px;">{{ $gname }}</div>
                    @foreach($rows as [$fl, $fv])
                        <div data-qa="feature-row" style="display:flex; justify-content:space-between; gap:16px; padding:4px 0; border-top:1px solid var(--border); font-size:.875rem;">
                            <span style="{{ $lab }}">{{ $fl }}</span><span style="text-align:right; font-weight:600;">{{ $fv }}</span>
                        </div>
                    @endforeach
                    @if($gname === 'Rooms' && $g['room_details'])
                        <details data-qa="room-details" style="margin-top:6px; font-size:.875rem;">
                            <summary style="cursor:pointer; font-weight:700;">Room details</summary>
                            @foreach($g['room_details'] as [$rl, $rv])
                                <div style="padding:4px 0; border-top:1px solid var(--border);"><div style="font-weight:700;">{{ $rl }}</div><div style="{{ $lab }}">{{ $rv }}</div></div>
                            @endforeach
                        </details>
                    @endif
                </section>
            @endforeach
        </div>
        @if(!isset($g['groups']['Rooms']) && $g['room_details'])
            <details data-qa="room-details" style="margin: 0 0 14px; font-size:.875rem;">
                <summary style="cursor:pointer; font-weight:700;">Room details</summary>
                @foreach($g['room_details'] as [$rl, $rv])
                    <div style="padding:4px 0; border-top:1px solid var(--border);"><div style="font-weight:700;">{{ $rl }}</div><div style="{{ $lab }}">{{ $rv }}</div></div>
                @endforeach
            </details>
        @endif
    @endif

    <div data-qa="full-ad-listed-by" style="margin-top:6px; padding-top:12px; border-top:1px solid var(--border);">
        <div style="font-weight:800; margin-bottom:4px;">Listed by</div>
        <div>{{ implode(' · ', $listed) }}@if($tgt && !empty($tgt['url'])) · <a href="{{ $tgt['url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon); font-weight:600;">Open on {{ $tgt['portal'] === 'pp' ? 'PrivateProperty' : 'P24' }}</a>@endif</div>
        @if(!empty($dt['read_on']))<div style="font-size:.8125rem; {{ $lab }}">Read {{ $dt['read_on'] }}@if(!empty($dt['read_by'])) by {{ $dt['read_by'] }}@endif</div>@endif
    </div>
</div>
