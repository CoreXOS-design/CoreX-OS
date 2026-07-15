{{--
    VIEWING PACK — per-property ONE-PAGER (A4 portrait).
    Agent-consensus layout (training 2026-07-15): NO description; show the SPACES
    (beds/baths/garages/parking) + the agent-selected FEATURES; everything on ONE page.

    Data contract: $b = App\Services\Properties\PropertyBrochureService::data($property, embed:true)
                   $vpNotes (optional) = ['label'=>, 'microcopy'=>, 'confidential'=>bool]

    This is the VIEWING-PACK variant and is deliberately SEPARATE from the Ad-Manager
    brochure (resources/views/corex/properties/_brochure.blade.php) so the two can be
    tuned independently. dompdf-SAFE CSS ONLY: tables (no flex/grid), inline styles,
    background-size:cover longhand, absolute inside position:relative.

    ┌─────────────────────────────────────────────────────────────────────────┐
    │  TUNING — everything an agent might ask to change lives in ONE block.     │
    │  Change a number here; the layout re-flows. No hunting through markup.    │
    └─────────────────────────────────────────────────────────────────────────┘
--}}
@php
    // BUYER (default) vs AGENT variant. Agent (Johan, 2026-07-15): drop the buyer
    // notes + QR footer and spend the reclaimed space on MORE/ALL features — tighter,
    // 4 columns, smaller chips. Pass mode='agent'. An optional $featuresAll (full,
    // un-capped feature list) lets the agent copy show every feature, not the
    // buyer-capped 18. Both modes stay a hard single page.
    $mode    = $mode ?? 'buyer';
    $isAgent = $mode === 'agent';

    // ── TUNING KNOBS (buyer defaults; agent overrides applied below) ─────────
    $T = [
        'heroHeight'   => 232,   // px height of the two hero photos
        'stripHeight'  => 74,    // px height of the thumbnail strip
        'featCols'     => 3,     // feature columns (2 or 3 read best on A4)
        'featMax'      => 18,    // max feature chips shown (rest roll into "+N more")
        'featFontPx'   => 11,    // feature chip font size
        'featGapPx'    => 5,     // vertical gap between feature chips
        'showStrip'    => true,  // show the 5-thumbnail strip
        'showSubheads' => true,  // show Rates & Taxes / Levy / Floor Size line
        'showNotesQr'  => true,  // buyer footer (notes lines + QR); agents don't need it
        'accent'       => '#1a2a6c', // brand navy (price badge, dots)
        'teal'         => '#00b894', // section eyebrow colour
        'ink'          => '#2b2b2b',
        'muted'        => '#6b6b6b',
        'line'         => '#d7dde5',
    ];
    if ($isAgent) {
        $T = array_merge($T, [
            'featCols'    => 4,   // 4 columns — denser feature grid
            'featMax'     => 40,  // effectively "show all" for a normal listing
            'featFontPx'  => 10,  // smaller chips = more fit
            'featGapPx'   => 4,
            'showNotesQr' => false, // no notes/QR — the freed space carries features
        ]);
    }

    $ink = $T['ink'];
    $iconUri = fn (string $svg): string => 'data:image/svg+xml;base64,' . base64_encode($svg);
    $svgBed     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M2 10h9V6H4a2 2 0 0 0-2 2v2zm11 0h9V8a2 2 0 0 0-2-2h-7v4zM2 12v6h2v-2h16v2h2v-6H2z"/></svg>';
    $svgBath    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 5a2 2 0 0 1 4 0v1H6.8A1.8 1.8 0 0 0 5 7.8V11H3a1 1 0 0 0-1 1 5 5 0 0 0 3 4.6V19h2v-1.2h10V19h2v-2.4A5 5 0 0 0 22 12a1 1 0 0 0-1-1H7V7.8c0-.1.1-.2.2-.2H10V6H6V5z"/></svg>';
    $svgGarage  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M5 11l1.6-4.2A2 2 0 0 1 8.5 5.5h7a2 2 0 0 1 1.9 1.3L19 11v6h-2.5v-2h-9v2H5v-6zm2.2-.5h9.6l-1-2.6a.6.6 0 0 0-.6-.4H8.8a.6.6 0 0 0-.6.4l-1 2.6zM7 12.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4zm10 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z"/></svg>';
    $svgParking = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm5 4v10h2.3v-3.1h2.1a3.45 3.45 0 0 0 0-6.9H9zm2.3 2h1.9a1.45 1.45 0 0 1 0 2.9h-1.9V9z" fill-rule="evenodd"/></svg>';

    $hero  = $b['heroImages'] ?? [];
    $strip = $b['stripImages'] ?? [];

    // Spaces bar — only non-zero (vacant land shows no row).
    $beds = (int) $b['beds']; $baths = (float) $b['baths']; $gar = (int) $b['garages']; $park = (int) $b['parking'];
    $specs = [];
    if ($beds  > 0) $specs[] = ['icon'=>$svgBed,     'num'=>$beds, 'label'=>$beds==1?'Bedroom':'Bedrooms'];
    if ($baths > 0) $specs[] = ['icon'=>$svgBath,    'num'=>rtrim(rtrim(number_format($baths,1),'0'),'.'), 'label'=>$baths==1?'Bathroom':'Bathrooms'];
    if ($gar   > 0) $specs[] = ['icon'=>$svgGarage,  'num'=>$gar,  'label'=>$gar==1?'Garage':'Garages'];
    if ($park  > 0) $specs[] = ['icon'=>$svgParking, 'num'=>$park, 'label'=>'Parking'];

    // Sub-headings — Rates & Taxes / Levy / Floor Size (only when present).
    $subheads = [];
    if ($T['showSubheads']) {
        if (!empty($b['rates'])) $subheads[] = ['label'=>'Rates & Taxes','value'=>$b['rates']];
        if (!empty($b['levy']))  $subheads[] = ['label'=>'Levy','value'=>$b['levy']];
        if (!empty($b['size']))  $subheads[] = ['label'=>'Floor Size','value'=>$b['size']];
    }

    // Features — the agent-selected list (replaces the description). Agent mode uses
    // the full un-capped list when supplied ($featuresAll); buyer mode uses the
    // page-safe capped set from the brochure data. Cap to featMax, roll the rest
    // into "+N more" so the page never overflows.
    if ($isAgent && !empty($featuresAll ?? null)) {
        $allFeat  = array_values($featuresAll);
        $moreData = 0;
    } else {
        $allFeat  = array_values($b['features']['items'] ?? []);
        $moreData = (int) ($b['features']['more'] ?? 0);
    }
    $featShown = array_slice($allFeat, 0, $T['featMax']);
    $moreTotal = $moreData + max(0, count($allFeat) - $T['featMax']);
    // Split into N balanced columns (column-major so reading order is top→bottom).
    $cols = max(1, (int) $T['featCols']);
    $perCol = (int) ceil(count($featShown) / $cols);
    $featColumns = $perCol > 0 ? array_chunk($featShown, $perCol) : [];

    $coverBg = function (?string $url, string $fallback): string {
        $css = "background-color:{$fallback};";
        if (!empty($url)) $css .= "background-image:url('{$url}');background-size:cover;background-position:center center;background-repeat:no-repeat;";
        return $css;
    };
@endphp

<div style="width:794px;background:#fff;color:{{ $ink }};font-family:'Inter','DejaVu Sans',Arial,sans-serif;padding:0 0 14px;">

    {{-- ── 1. Agency logo ── --}}
    <div style="text-align:center;padding:6px 32px 12px;">
        @if(!empty($b['logo']))
            <img src="{{ $b['logo'] }}" alt="" style="height:78px;max-width:510px;">
        @else
            <span style="font-weight:700;font-size:30px;color:{{ $T['accent'] }};">{{ $b['agencyName'] }}</span>
        @endif
    </div>

    {{-- ── 2. Photo grid ── --}}
    <div style="position:relative;">
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="width:40%;padding-right:3px;vertical-align:top;">
                <div style="height:{{ $T['heroHeight'] }}px;{{ $coverBg($hero[0] ?? null, '#e5eaf0') }}"></div>
            </td>
            <td style="width:60%;padding-left:3px;vertical-align:top;">
                <div style="height:{{ $T['heroHeight'] }}px;{{ $coverBg($hero[1] ?? ($hero[0] ?? null), '#dbe2ea') }}"></div>
            </td>
        </tr></table>
        <div style="position:absolute;right:0;bottom:0;background:{{ $T['accent'] }};color:#fff;font-weight:700;font-size:30px;line-height:1;padding:12px 18px;">{{ $b['price'] }}</div>

        @if($T['showStrip'] && count($strip))
        <table style="width:100%;border-collapse:collapse;margin-top:6px;"><tr>
            @for($i=0;$i<5;$i++)
                <td style="width:20%;{{ $i>0?'padding-left:3px;':'' }}{{ $i<4?'padding-right:3px;':'' }}vertical-align:top;">
                    <div style="height:{{ $T['stripHeight'] }}px;{{ $coverBg($strip[$i] ?? null, '#eef1f5') }}"></div>
                </td>
            @endfor
        </tr></table>
        @endif
    </div>

    {{-- ── 3. Title + location ── --}}
    <div style="text-align:center;padding:12px 32px 0;">
        <div style="font-size:26px;font-weight:700;color:{{ $ink }};line-height:1.2;">{{ $b['title'] }}</div>
        @if(($b['location'] ?? '') !== '')
        <table style="margin:7px auto 0;border-collapse:collapse;"><tr>
            @if(!empty($b['pin']))<td style="vertical-align:middle;padding-right:5px;"><img src="{{ $b['pin'] }}" width="13" height="17" style="width:13px;height:17px;display:block;"></td>@endif
            <td style="vertical-align:middle;font-size:15px;color:{{ $T['muted'] }};">{{ $b['location'] }}</td>
        </tr></table>
        @endif
    </div>

    {{-- ── 4. Price (prominent) ── --}}
    @if(!empty($b['price']))
    <div style="text-align:center;padding:8px 32px 0;">
        <span style="font-size:26px;font-weight:700;color:{{ $T['accent'] }};letter-spacing:0.01em;">{{ $b['price'] }}</span>
    </div>
    @endif

    {{-- ── 5. SPACES bar ── --}}
    @if(count($specs))
    <table style="margin:10px auto 0;border-collapse:collapse;"><tr>
        @foreach($specs as $s)
        <td style="text-align:center;padding:0 24px;vertical-align:top;">
            <img src="{{ $iconUri($s['icon']) }}" alt="" style="height:24px;"><br>
            <span style="font-size:17px;font-weight:700;color:{{ $ink }};">{{ $s['num'] }}</span><br>
            <span style="font-size:11px;color:{{ $T['muted'] }};text-transform:uppercase;letter-spacing:0.04em;">{{ $s['label'] }}</span>
        </td>
        @endforeach
    </tr></table>
    @endif

    {{-- ── 6. Sub-headings — Rates · Levy · Floor Size ── --}}
    @if(count($subheads))
    <div style="text-align:center;padding:10px 32px 0;">
        @foreach($subheads as $i => $sh)
            @if($i>0)<span style="color:#d4dae0;margin:0 14px;">|</span>@endif
            <span style="font-size:11px;color:{{ $T['muted'] }};text-transform:uppercase;letter-spacing:0.06em;">{{ $sh['label'] }}</span>
            <span style="font-size:14px;font-weight:700;color:{{ $ink }};margin-left:6px;">{{ $sh['value'] }}</span>
        @endforeach
    </div>
    @endif

    {{-- ── 7. FEATURES (replaces the description) — agent-selected list ── --}}
    @if(count($featShown))
    <div style="padding:12px 32px 0;">
        <div style="text-align:center;font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{ $T['teal'] }};margin-bottom:8px;">Features</div>
        <table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>
            @foreach($featColumns as $col)
            <td style="width:{{ number_format(100/max(1,count($featColumns)),4) }}%;vertical-align:top;padding:0 10px;">
                @foreach($col as $f)
                    <table style="border-collapse:collapse;margin-bottom:{{ $T['featGapPx'] }}px;"><tr>
                        <td style="vertical-align:top;padding-right:8px;padding-top:3px;">
                            <div style="width:6px;height:6px;border-radius:3px;background:{{ $T['accent'] }};"></div>
                        </td>
                        <td style="vertical-align:top;font-size:{{ $T['featFontPx'] }}px;line-height:1.4;color:{{ $ink }};">{{ $f }}</td>
                    </tr></table>
                @endforeach
            </td>
            @endforeach
        </tr></table>
        @if($moreTotal > 0)
        <div style="text-align:center;font-size:11px;color:{{ $T['muted'] }};margin-top:4px;">+{{ $moreTotal }} more feature{{ $moreTotal == 1 ? '' : 's' }} — ask your agent</div>
        @endif
    </div>
    @endif

    {{-- ── 8. Footer — buyer notes (viewing pack) + QR. Agent mode drops this
         entirely; the reclaimed space already went to more features above. ── --}}
    @if($T['showNotesQr'])
    <div style="padding:12px 32px 0;">
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="vertical-align:middle;">
                @if(!empty($vpNotes ?? null))
                <div style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:{{ !empty($vpNotes['confidential']) ? '#b91c1c' : $T['teal'] }};">{{ $vpNotes['label'] }}</div>
                <div style="font-size:10.5px;color:{{ $T['muted'] }};margin:3px 0 8px;max-width:440px;">{{ $vpNotes['microcopy'] }}</div>
                @for($vn=0;$vn<3;$vn++)<div style="border-bottom:1px solid {{ $T['line'] }};height:22px;max-width:440px;"></div>@endfor
                @endif
            </td>
            <td style="vertical-align:middle;text-align:right;width:110px;">
                @if(!empty($b['qr']))
                    <img src="{{ $b['qr'] }}" alt="" style="width:104px;height:104px;">
                    <div style="font-size:9px;color:{{ $T['muted'] }};text-align:center;margin-top:2px;">View online</div>
                @endif
            </td>
        </tr></table>
    </div>
    @endif

</div>
