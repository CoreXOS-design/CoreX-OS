{{--
    Printable Brochure — A4 property data sheet (Ad Manager, always-first / always-A4).
    Spec: .ai/specs/ad-manager.md §"Printable Brochure".

    Rendered in TWO hosts from one partial:
      • dompdf  (brochure-pdf.blade.php)  — images are base64 data-URIs.
      • browser (ad.blade.php / Tools previews) — images are plain URLs.

    Single data contract: $b (from App\Services\Properties\PropertyBrochureService::data()).
    dompdf-SAFE CSS ONLY: tables (no flex/grid), background-size:cover for crops,
    absolute positioning inside a position:relative parent, border-radius clips
    backgrounds. All styling is inline so it renders identically in both hosts.
--}}
@php
    /** Filled monochrome metric icons as SVG data-URIs (dompdf renders <img> SVG via php-svg-lib). */
    $iconUri = function (string $svg): string {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    };
    $ink = '#0b2a4a';
    $svgBed     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M2 10h9V6H4a2 2 0 0 0-2 2v2zm11 0h9V8a2 2 0 0 0-2-2h-7v4zM2 12v6h2v-2h16v2h2v-6H2z"/></svg>';
    $svgBath    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 5a2 2 0 0 1 4 0v1H6.8A1.8 1.8 0 0 0 5 7.8V11H3a1 1 0 0 0-1 1 5 5 0 0 0 3 4.6V19h2v-1.2h10V19h2v-2.4A5 5 0 0 0 22 12a1 1 0 0 0-1-1H7V7.8c0-.1.1-.2.2-.2H10V6H6V5z"/></svg>';
    $svgGarage  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M5 11l1.6-4.2A2 2 0 0 1 8.5 5.5h7a2 2 0 0 1 1.9 1.3L19 11v6h-2.5v-2h-9v2H5v-6zm2.2-.5h9.6l-1-2.6a.6.6 0 0 0-.6-.4H8.8a.6.6 0 0 0-.6.4l-1 2.6zM7 12.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4zm10 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z"/></svg>';
    $svgParking = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm5 4v10h2.3v-3.1h2.1a3.45 3.45 0 0 0 0-6.9H9zm2.3 2h1.9a1.45 1.45 0 0 1 0 2.9h-1.9V9z" fill-rule="evenodd"/></svg>';
    $svgRuler   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="'.$ink.'"><path d="M3 8.5L8.5 3 21 15.5 15.5 21 3 8.5zm3 0l1.5 1.5 1-1L7 7.5l-1 1zm3 3l1.5 1.5 1-1L10 10.5l-1 1zm3 3l1.5 1.5 1-1L13 13.5l-1 1z"/></svg>';
    $svgPin     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#9aa7b4"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>';

    $hero  = $b['heroImages'] ?? [];
    $strip = $b['stripImages'] ?? [];
    $metricCells = array_values(array_filter([
        ['icon' => $svgBed,     'num' => $b['beds'],    'label' => ($b['beds'] == 1 ? 'Bedroom' : 'Bedrooms')],
        ['icon' => $svgBath,    'num' => rtrim(rtrim(number_format((float) $b['baths'], 1), '0'), '.'), 'label' => ($b['baths'] == 1 ? 'Bathroom' : 'Bathrooms')],
        ['icon' => $svgGarage,  'num' => $b['garages'], 'label' => ($b['garages'] == 1 ? 'Garage' : 'Garages')],
        ['icon' => $svgParking, 'num' => $b['parking'], 'label' => ($b['parking'] == 1 ? 'Parking' : 'Parking')],
        $b['size'] ? ['icon' => $svgRuler, 'num' => $b['size'], 'label' => 'Floor Size'] : null,
    ]));
    $metricW = number_format(100 / max(1, count($metricCells)), 4);

    $features = $b['features']['items'] ?? [];
    $moreFeatures = $b['features']['more'] ?? 0;

    /**
     * dompdf-safe cover-image background. dompdf's `background` SHORTHAND does
     * not reliably parse the `position/size` slash syntax, so every background
     * is emitted as explicit longhand properties.
     */
    $coverBg = function (?string $url, string $fallback): string {
        $css = "background-color:{$fallback};";
        if (! empty($url)) {
            $css .= "background-image:url('{$url}');background-size:cover;background-position:center center;background-repeat:no-repeat;";
        }
        return $css;
    };
@endphp

<div style="width:794px;background:#ffffff;color:#1f2937;font-family:'DejaVu Sans',Arial,sans-serif;padding:26px 30px 30px;">

    {{-- ── Header / branding ── --}}
    <div style="text-align:center;margin-bottom:14px;">
        @if(!empty($b['logo']))
            <img src="{{ $b['logo'] }}" alt="" style="height:54px;max-width:380px;">
        @else
            <div style="font-weight:800;font-size:26px;color:#0b2a4a;">{{ $b['agencyName'] }}</div>
        @endif
    </div>

    {{-- ── Hero collage ── --}}
    <div style="position:relative;margin-bottom:4px;">
        <table style="width:100%;border-collapse:separate;border-spacing:0;"><tr>
            <td style="width:62%;vertical-align:top;padding-right:4px;">
                <div style="height:304px;border-radius:6px;{{ $coverBg($hero[0] ?? null, '#e5eaf0') }}"></div>
            </td>
            <td style="width:38%;vertical-align:top;">
                <div style="height:150px;border-radius:6px;margin-bottom:4px;{{ $coverBg($hero[1] ?? null, '#e5eaf0') }}"></div>
                <div style="height:150px;border-radius:6px;{{ $coverBg($hero[2] ?? null, '#dbe2ea') }}"></div>
            </td>
        </tr></table>

        {{-- Price badge overlay (bottom-right of the collage) --}}
        <div style="position:absolute;right:0;bottom:14px;background:#11366b;color:#ffffff;font-weight:800;font-size:21px;padding:9px 18px;border-radius:5px 0 0 5px;">
            {{ $b['price'] }}
        </div>
    </div>

    {{-- ── Thumbnail strip ── --}}
    @if(count($strip) > 1)
    <table style="width:100%;border-collapse:separate;border-spacing:4px;margin:0 -4px 6px;"><tr>
        @foreach(array_slice($strip, 0, 6) as $t)
            <td style="width:16.66%;"><div style="height:66px;border-radius:5px;{{ $coverBg($t, '#e5eaf0') }}"></div></td>
        @endforeach
    </tr></table>
    @endif

    {{-- ── Title + location ── --}}
    <div style="text-align:center;margin-top:12px;">
        <div style="font-size:20px;font-weight:800;color:#13243a;line-height:1.25;">{{ $b['title'] }}</div>
        @if($b['location'] !== '')
        <div style="font-size:12px;color:#6b7785;margin-top:5px;">
            <img src="{{ $iconUri($svgPin) }}" alt="" style="height:13px;vertical-align:-2px;"> {{ $b['location'] }}
        </div>
        @endif
    </div>

    {{-- ── Metric row ── --}}
    <table style="width:100%;border-collapse:collapse;margin:14px 0 10px;border-top:1px solid #e7ebf0;border-bottom:1px solid #e7ebf0;"><tr>
        @foreach($metricCells as $m)
        <td style="width:{{ $metricW }}%;text-align:center;padding:11px 4px;border-left:1px solid #f0f2f5;">
            <img src="{{ $iconUri($m['icon']) }}" alt="" style="height:22px;"><br>
            <span style="font-size:15px;font-weight:800;color:#13243a;">{{ $m['num'] }}</span><br>
            <span style="font-size:10px;color:#8a96a3;text-transform:uppercase;letter-spacing:0.04em;">{{ $m['label'] }}</span>
        </td>
        @endforeach
    </tr></table>

    {{-- ── Rates / Levy badges ── --}}
    @if($b['rates'] || $b['levy'])
    <table style="width:100%;border-collapse:separate;border-spacing:0;margin-bottom:12px;"><tr>
        @if($b['rates'])
        <td style="padding-right:8px;width:50%;">
            <div style="background:#f3f6fa;border:1px solid #e7ebf0;border-radius:6px;padding:8px 14px;">
                <span style="font-size:15px;font-weight:800;color:#11366b;">{{ $b['rates'] }}</span>
                <span style="font-size:11px;color:#8a96a3;text-transform:uppercase;letter-spacing:0.06em;float:right;margin-top:3px;">Rates</span>
            </div>
        </td>
        @endif
        @if($b['levy'])
        <td style="padding-left:{{ $b['rates'] ? 8 : 0 }}px;width:50%;">
            <div style="background:#f3f6fa;border:1px solid #e7ebf0;border-radius:6px;padding:8px 14px;">
                <span style="font-size:15px;font-weight:800;color:#11366b;">{{ $b['levy'] }}</span>
                <span style="font-size:11px;color:#8a96a3;text-transform:uppercase;letter-spacing:0.06em;float:right;margin-top:3px;">Levy</span>
            </div>
        </td>
        @endif
    </tr></table>
    @endif

    {{-- ── Feature checklist (4 columns) ── --}}
    @if(count($features))
    @php $rows = (int) ceil(count($features) / 4); @endphp
    <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
        @for($r = 0; $r < $rows; $r++)
        <tr>
            @for($c = 0; $c < 4; $c++)
                @php $idx = $c * $rows + $r; $feat = $features[$idx] ?? null; @endphp
                <td style="width:25%;padding:3px 6px 3px 0;vertical-align:top;font-size:11px;color:#3a4654;">
                    @if($feat)
                        <span style="color:#1e9e5a;font-weight:800;">&#10003;</span>
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $feat }}</span>
                    @endif
                </td>
            @endfor
        </tr>
        @endfor
    </table>
    @if($moreFeatures > 0)
        <div style="font-size:10px;color:#9aa7b4;margin-top:-8px;margin-bottom:12px;">+ {{ $moreFeatures }} more feature{{ $moreFeatures === 1 ? '' : 's' }}</div>
    @endif
    @endif

    {{-- ── Description ── --}}
    @if(count($b['description']))
    <div style="margin-bottom:16px;">
        @foreach($b['description'] as $para)
            <p style="font-size:11.5px;line-height:1.55;color:#46525f;margin:0 0 8px;text-align:justify;">{{ $para }}</p>
        @endforeach
    </div>
    @endif

    {{-- ── Agent card ── --}}
    <table style="width:100%;border-collapse:separate;border-spacing:0;border-top:1px solid #e7ebf0;padding-top:12px;"><tr>
        <td style="vertical-align:middle;padding-top:12px;">
            <table style="border-collapse:separate;border-spacing:0;"><tr>
                <td style="vertical-align:middle;padding-right:12px;">
                    @if(!empty($b['agentPhoto']))
                        <div style="width:58px;height:58px;border-radius:50%;{{ $coverBg($b['agentPhoto'], '#e5eaf0') }}"></div>
                    @else
                        <div style="width:58px;height:58px;border-radius:50%;background:#11366b;"></div>
                    @endif
                </td>
                <td style="vertical-align:middle;">
                    <div style="font-size:15px;font-weight:800;color:#13243a;">{{ $b['agentName'] }}</div>
                    @if($b['agentPhone'] !== '')<div style="font-size:12px;color:#6b7785;margin-top:2px;">{{ $b['agentPhone'] }}</div>@endif
                    @if($b['agentEmail'] !== '')<div style="font-size:12px;color:#6b7785;">{{ $b['agentEmail'] }}</div>@endif
                </td>
            </tr></table>
        </td>
        <td style="vertical-align:middle;text-align:right;width:96px;padding-top:12px;">
            @if(!empty($b['qr']))
                <img src="{{ $b['qr'] }}" alt="" style="width:80px;height:80px;">
            @endif
        </td>
    </tr></table>

</div>
