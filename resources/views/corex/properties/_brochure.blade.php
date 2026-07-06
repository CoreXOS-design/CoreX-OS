{{--
    Printable Brochure — A4 portrait property data sheet (Ad Manager, always-first).
    Spec: .ai/specs/ad-manager.md §10c.

    Rendered in TWO hosts from one partial:
      • dompdf  (brochure-pdf.blade.php)  — images are base64 data-URIs, Inter embedded.
      • browser (ad.blade.php / Tools previews) — images are plain URLs.

    Single data contract: $b (App\Services\Properties\PropertyBrochureService::data()).
    dompdf-SAFE CSS ONLY: tables (no flex/grid), background-size:cover LONGHAND for
    crops, absolute positioning inside a position:relative parent, border-radius
    clips backgrounds. Inline styling so it renders identically in both hosts.

    Layout (top→bottom): centred agency logo · full-bleed photo grid (2 hero 40/60
    + price badge on the hero · 5-thumbnail strip) · centred title + location ·
    specs bar (beds/baths/garages/parking, 0-value hidden) · one-line sub-headings
    (Rates & Taxes / Levy / Floor Size) · justified description · agent + QR footer.
    Property features are intentionally NOT listed.
--}}
@php
    $iconUri = fn (string $svg): string => 'data:image/svg+xml;base64,' . base64_encode($svg);
    $ink = '#2b2b2b';
    $svgBed     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M2 10h9V6H4a2 2 0 0 0-2 2v2zm11 0h9V8a2 2 0 0 0-2-2h-7v4zM2 12v6h2v-2h16v2h2v-6H2z"/></svg>';
    $svgBath    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 5a2 2 0 0 1 4 0v1H6.8A1.8 1.8 0 0 0 5 7.8V11H3a1 1 0 0 0-1 1 5 5 0 0 0 3 4.6V19h2v-1.2h10V19h2v-2.4A5 5 0 0 0 22 12a1 1 0 0 0-1-1H7V7.8c0-.1.1-.2.2-.2H10V6H6V5z"/></svg>';
    $svgGarage  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M5 11l1.6-4.2A2 2 0 0 1 8.5 5.5h7a2 2 0 0 1 1.9 1.3L19 11v6h-2.5v-2h-9v2H5v-6zm2.2-.5h9.6l-1-2.6a.6.6 0 0 0-.6-.4H8.8a.6.6 0 0 0-.6.4l-1 2.6zM7 12.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4zm10 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z"/></svg>';
    $svgParking = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm5 4v10h2.3v-3.1h2.1a3.45 3.45 0 0 0 0-6.9H9zm2.3 2h1.9a1.45 1.45 0 0 1 0 2.9h-1.9V9z" fill-rule="evenodd"/></svg>';

    $hero  = $b['heroImages'] ?? [];
    $strip = $b['stripImages'] ?? [];

    // Specs bar — only non-zero specs (vacant land shows no row).
    $beds = (int) $b['beds']; $baths = (float) $b['baths']; $gar = (int) $b['garages']; $park = (int) $b['parking'];
    $specs = [];
    if ($beds  > 0) $specs[] = ['icon' => $svgBed,     'num' => $beds,  'label' => $beds == 1 ? 'Bedroom' : 'Bedrooms'];
    if ($baths > 0) $specs[] = ['icon' => $svgBath,    'num' => rtrim(rtrim(number_format($baths, 1), '0'), '.'), 'label' => $baths == 1 ? 'Bathroom' : 'Bathrooms'];
    if ($gar   > 0) $specs[] = ['icon' => $svgGarage,  'num' => $gar,   'label' => $gar == 1 ? 'Garage' : 'Garages'];
    if ($park  > 0) $specs[] = ['icon' => $svgParking, 'num' => $park,  'label' => 'Parking'];

    // One-line sub-headings — Rates & Taxes / Levy / Floor Size (only when present).
    $subheads = [];
    if (! empty($b['rates'])) $subheads[] = ['label' => 'Rates & Taxes', 'value' => $b['rates']];
    if (! empty($b['levy']))  $subheads[] = ['label' => 'Levy', 'value' => $b['levy']];
    if (! empty($b['size']))  $subheads[] = ['label' => 'Floor Size', 'value' => $b['size']];

    $coverBg = function (?string $url, string $fallback): string {
        $css = "background-color:{$fallback};";
        if (! empty($url)) {
            $css .= "background-image:url('{$url}');background-size:cover;background-position:center center;background-repeat:no-repeat;";
        }
        return $css;
    };
@endphp

<div style="width:794px;background:#ffffff;color:#2b2b2b;font-family:'Inter','DejaVu Sans',Arial,sans-serif;padding:0 0 14px;">

    {{-- ── 1. Header — agency logo, centred (sits near the top edge) ── --}}
    <div style="text-align:center;padding:6px 32px 12px;">
        @if(!empty($b['logo']))
            <img src="{{ $b['logo'] }}" alt="" style="height:78px;max-width:510px;">
        @else
            <span style="font-weight:700;font-size:30px;color:#0b2a4a;">{{ $b['agencyName'] }}</span>
        @endif
    </div>

    {{-- ── 2. Photo grid (full-bleed) ── --}}
    <div style="position:relative;">
        {{-- Top row: 2 hero photos (40% / 60%) --}}
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="width:40%;padding-right:3px;vertical-align:top;">
                <div style="height:280px;{{ $coverBg($hero[0] ?? null, '#e5eaf0') }}"></div>
            </td>
            <td style="width:60%;padding-left:3px;vertical-align:top;">
                <div style="height:280px;{{ $coverBg($hero[1] ?? ($hero[0] ?? null), '#dbe2ea') }}"></div>
            </td>
        </tr></table>
        {{-- Price badge — solid navy, no rounding, bottom-right of the right photo --}}
        <div style="position:absolute;right:0;bottom:0;background:#1a2a6c;color:#ffffff;font-weight:700;font-size:30px;line-height:1;padding:12px 18px;">{{ $b['price'] }}</div>

        {{-- Bottom row: up to 5 thumbnails --}}
        @if(count($strip))
        <table style="width:100%;border-collapse:collapse;margin-top:6px;"><tr>
            @for($i = 0; $i < 5; $i++)
                <td style="width:20%;{{ $i > 0 ? 'padding-left:3px;' : '' }}{{ $i < 4 ? 'padding-right:3px;' : '' }}vertical-align:top;">
                    <div style="height:96px;{{ $coverBg($strip[$i] ?? null, '#eef1f5') }}"></div>
                </td>
            @endfor
        </tr></table>
        @endif
    </div>

    {{-- ── 3. Title + location ── --}}
    <div style="text-align:center;padding:18px 32px 0;">
        <div style="font-size:28px;font-weight:700;color:#2b2b2b;line-height:1.2;">{{ $b['title'] }}</div>
        @if($b['location'] !== '')
        {{-- Centred table so the pin + text align cleanly. Pin is a GD PNG
             (not an inline SVG) so it never clips at the text baseline. --}}
        <table style="margin:8px auto 0;border-collapse:collapse;"><tr>
            @if(!empty($b['pin']))
            <td style="vertical-align:middle;padding-right:5px;"><img src="{{ $b['pin'] }}" alt="" width="13" height="17" style="width:13px;height:17px;display:block;"></td>
            @endif
            <td style="vertical-align:middle;font-size:15px;color:#6b6b6b;">{{ $b['location'] }}</td>
        </tr></table>
        @endif
    </div>

    {{-- ── 3b. Price — prominent, centred, above the specs/features ── --}}
    @if(!empty($b['price']))
    <div style="text-align:center;padding:14px 32px 0;">
        <span style="font-size:26px;font-weight:700;color:#1a2a6c;letter-spacing:0.01em;">{{ $b['price'] }}</span>
    </div>
    @endif

    {{-- ── 4. Specs bar (beds / baths / garages / parking) ── --}}
    @if(count($specs))
    <table style="margin:16px auto 0;border-collapse:collapse;"><tr>
        @foreach($specs as $s)
        <td style="text-align:center;padding:0 26px;vertical-align:top;">
            <img src="{{ $iconUri($s['icon']) }}" alt="" style="height:24px;"><br>
            <span style="font-size:17px;font-weight:700;color:#2b2b2b;">{{ $s['num'] }}</span><br>
            <span style="font-size:11px;color:#6b6b6b;text-transform:uppercase;letter-spacing:0.04em;">{{ $s['label'] }}</span>
        </td>
        @endforeach
    </tr></table>
    @endif

    {{-- ── 5. Sub-headings line — Rates & Taxes · Levy · Floor Size ── --}}
    @if(count($subheads))
    <div style="text-align:center;padding:16px 32px 0;">
        @foreach($subheads as $i => $sh)
            @if($i > 0)<span style="color:#d4dae0;margin:0 14px;">|</span>@endif
            <span style="font-size:11px;color:#6b6b6b;text-transform:uppercase;letter-spacing:0.06em;">{{ $sh['label'] }}</span>
            <span style="font-size:14px;font-weight:700;color:#2b2b2b;margin-left:6px;">{{ $sh['value'] }}</span>
        @endforeach
    </div>
    @endif

    {{-- ── 6. Description (justified, shrink-to-fit one page) ──
         The service trims the text to a char budget sized from the space left on
         the page — THAT is what keeps the PDF to one page (dompdf does not clip
         overflow). The max-height + overflow:hidden below is a secondary guard
         for the BROWSER preview host, where overflow clipping does work. --}}
    @if(count($b['description']))
    <div style="padding:18px 32px 0;max-height:{{ (int) ($b['descMaxPx'] ?? 260) }}px;overflow:hidden;">
        @foreach($b['description'] as $para)
            <p style="font-size:12px;font-weight:500;line-height:1.6;color:#2b2b2b;margin:0 0 9px;text-align:justify;">{{ $para }}</p>
        @endforeach
    </div>
    @endif

    {{-- ── 7. Footer — agent (left) + QR (right) ── --}}
    <div style="padding:18px 32px 0;">
        <table style="width:100%;border-collapse:collapse;"><tr>
            @php
                // Co-branded brochure: two agents → compact side-by-side blocks
                // (smaller photos / type) so the footer stays on the single A4 page.
                $hasAgent2 = !empty($b['agent2Name'] ?? '');
                $photoPx   = $hasAgent2 ? 62 : 78;
                $namePx    = $hasAgent2 ? 16 : 20;
                $phonePx   = $hasAgent2 ? 13 : 14;
                $emailPx   = $hasAgent2 ? 12 : 13;
            @endphp
            <td style="vertical-align:middle;">
                @if(!empty($vpNotes ?? null))
                {{-- AT-160 items 7+8 — viewing pack: ONE agent per pack (the preparing
                     agent is on the cover), so the per-property listing-agent card is
                     removed; the freed space carries a compact notes block. --}}
                <div style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:{{ !empty($vpNotes['confidential']) ? '#b91c1c' : '#00b894' }};">{{ $vpNotes['label'] }}</div>
                <div style="font-size:10.5px;color:#6b6b6b;margin:3px 0 8px;max-width:440px;">{{ $vpNotes['microcopy'] }}</div>
                @for($vn = 0; $vn < 3; $vn++)
                    <div style="border-bottom:1px solid #d7dde5;height:22px;max-width:440px;"></div>
                @endfor
                @else
                <table style="border-collapse:collapse;"><tr>
                    <td style="vertical-align:middle;padding-right:14px;">
                        @if(!empty($b['agentPhoto']))
                            <div style="width:{{ $photoPx }}px;height:{{ $photoPx }}px;border-radius:6px;{{ $coverBg($b['agentPhoto'], '#e5eaf0') }}"></div>
                        @else
                            <div style="width:{{ $photoPx }}px;height:{{ $photoPx }}px;border-radius:6px;background-color:#1a2a6c;"></div>
                        @endif
                    </td>
                    <td style="vertical-align:middle;{{ $hasAgent2 ? 'padding-right:22px;' : '' }}">
                        <div style="font-size:{{ $namePx }}px;font-weight:700;color:#2b2b2b;">{{ $b['agentName'] }}</div>
                        @if($b['agentPhone'] !== '')<div style="font-size:{{ $phonePx }}px;color:#2b2b2b;margin-top:3px;">{{ $b['agentPhone'] }}</div>@endif
                        @if($b['agentEmail'] !== '')<div style="font-size:{{ $emailPx }}px;color:#6b6b6b;margin-top:1px;">{{ $b['agentEmail'] }}</div>@endif
                    </td>
                    @if($hasAgent2)
                    <td style="vertical-align:middle;padding-right:14px;">
                        @if(!empty($b['agent2Photo']))
                            <div style="width:{{ $photoPx }}px;height:{{ $photoPx }}px;border-radius:6px;{{ $coverBg($b['agent2Photo'], '#e5eaf0') }}"></div>
                        @else
                            <div style="width:{{ $photoPx }}px;height:{{ $photoPx }}px;border-radius:6px;background-color:#1a2a6c;"></div>
                        @endif
                    </td>
                    <td style="vertical-align:middle;">
                        <div style="font-size:{{ $namePx }}px;font-weight:700;color:#2b2b2b;">{{ $b['agent2Name'] }}</div>
                        @if(($b['agent2Phone'] ?? '') !== '')<div style="font-size:{{ $phonePx }}px;color:#2b2b2b;margin-top:3px;">{{ $b['agent2Phone'] }}</div>@endif
                        @if(($b['agent2Email'] ?? '') !== '')<div style="font-size:{{ $emailPx }}px;color:#6b6b6b;margin-top:1px;">{{ $b['agent2Email'] }}</div>@endif
                    </td>
                    @endif
                </tr></table>
                @endif
            </td>
            <td style="vertical-align:middle;text-align:right;width:110px;">
                @if(!empty($b['qr']))
                    <img src="{{ $b['qr'] }}" alt="" style="width:104px;height:104px;">
                    {{-- AT-160 item 7 — the QR encodes the listing preview URL (not agent
                         contact), so it stays; relabelled "View online" in the pack. --}}
                    @if(!empty($vpNotes ?? null))
                        <div style="font-size:9px;color:#6b6b6b;text-align:center;margin-top:2px;">View online</div>
                    @endif
                @endif
            </td>
        </tr></table>
    </div>

</div>
