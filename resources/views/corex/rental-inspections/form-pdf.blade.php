<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Inspection Form</title>
<style>
    /* Every position below is in pt (PDF points, 1/72in) — the SAME unit
       and origin RentalInspectionFormPdfService::buildManifest() records.
       No px, no % — an absolute-pt box here is an absolute-pt box there,
       with zero unit conversion in either direction. */
    @page { margin: 0; size: {{ $layout['paper']['width_pt'] }}pt {{ $layout['paper']['height_pt'] }}pt; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #111; }
    .page { position: relative; width: {{ $pw = $layout['paper']['width_pt'] }}pt; height: {{ $ph = $layout['paper']['height_pt'] }}pt; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .abs { position: absolute; }
    .fiducial-square { background: #000; }
    .fiducial-circle { background: #000; border-radius: 50%; }
    .id-bit-on { background: #000; }
    .id-bit-off { border: 0.75pt solid #000; }
    .id-text { font-size: 6.5pt; color: #333; }
    .room-heading { font-size: 9pt; font-weight: bold; text-transform: uppercase; border-bottom: 0.75pt solid #000; }
    .cond-label { font-size: 6pt; text-align: center; }
    .item-label { font-size: 8pt; }
    .box-outline { border: 1pt solid #000; }
    /* An item already recorded on screen: a LIGHT grey fill. The scan reader counts a pixel as ink only below mid-grey,
       so this never reads as a mark - it only shows the agent what is already on the web inspection. */
    .box-pre { background: #d2d2d2; }
    .row-no { font-size: 6pt; color: #666; }
    .field-label { font-size: 6.5pt; color: #444; }
    .field-line { border-bottom: 0.5pt solid #555; font-size: 8.5pt; height: 12pt; }
    .qr-caption { font-size: 6.5pt; color: #333; }
    .legend { font-size: 6.5pt; color: #444; }
    .notes-outline { border: 0.5pt solid #999; }
    .header-line { font-size: 8.5pt; }
    .header-title { font-size: 13pt; font-weight: bold; }
    .sig-block { border: 0.5pt solid #666; }
    .sig-label { font-size: 7.5pt; text-align: center; }
</style>
</head>
<body>

{{-- AT-463 / §63: everything is positioned inside the safe area - $mx is the left/right content edge (the marks sit further out,
     at the agency's safe margin), $ky the vertical shrink every pitch took so no row is dropped. --}}
@php $mx = $layout['margins']['x']; $ky = $layout['margins']['v_scale']; @endphp
@for ($p = 1; $p <= $layout['page_count']; $p++)
    <div class="page">
        {{-- Registration marks — every page, same fixed inset, so a scan of
             ANY page can be de-skewed/scaled independently. Bottom-right is
             deliberately a circle, not a square, so a 180°-rotated scan can
             be resolved from the fiducials alone. --}}
        @foreach($layout['fiducials'] as $f)
            @if($f['page'] === $p)
                <div class="abs {{ $f['shape'] === 'filled_circle' ? 'fiducial-circle' : 'fiducial-square' }}"
                     style="left:{{ $f['x'] }}pt; top:{{ $f['y'] }}pt; width:{{ $f['size'] }}pt; height:{{ $f['size'] }}pt;"></div>
            @endif
        @endforeach

        {{-- Page identifier — printed human-readable AND an OMR bit-grid at
             a FIXED position on every page (inspection id / page / form
             version). Column marking only — the reader decodes this the
             same way it reads every condition tick-box, no OCR, no barcode
             library. --}}
        @foreach($layout['page_identifiers'] as $pid)
            @if($pid['page'] === $p)
                <div class="abs id-text" style="left:{{ $pid['text_x'] }}pt; top:{{ $pid['text_y'] }}pt;">{{ $pid['text'] }}</div>
                @foreach($pid['bits'] as $bit)
                    <div class="abs {{ $bit['value'] ? 'id-bit-on' : 'id-bit-off' }}"
                         style="left:{{ $bit['x'] }}pt; top:{{ $bit['y'] }}pt; width:{{ $bit['width'] }}pt; height:{{ $bit['height'] }}pt;"></div>
                @endforeach
            @endif
        @endforeach

        {{-- QR code — every page, same place (bottom-centre, well away from the corner registration marks): inspection id, page,
             page count and form version. The scan reader identifies and orients the page from this first; the OMR
             grid above is its fallback. --}}
        @foreach($layout['qr_codes'] as $qr)
            @if($qr['page'] === $p)
                <img class="abs" src="{{ $qrImages[$p] }}" alt="" style="left:{{ $qr['x'] }}pt; top:{{ $qr['y'] }}pt; width:{{ $qr['size'] }}pt; height:{{ $qr['size'] }}pt;">
                <div class="abs qr-caption" style="left:{{ $qr['x'] + $qr['size'] + 8 }}pt; top:{{ $qr['y'] + 14 }}pt; width:{{ $pw - $mx - ($qr['x'] + $qr['size'] + 8) }}pt;">
                    Inspection #{{ $inspection->id }} &middot; page {{ $p }} of {{ $layout['page_count'] }}<br>
                    Tick ONE box per row, in ink. Do not write inside the boxes. Keep the whole page flat and in frame when scanning.
                </div>
            @endif
        @endforeach

        @if($p === 1)
            {{-- Header — property, inspection type/date, parties. Plain
                 printed text, never geometry-tracked (nothing here is
                 marked or read back). --}}
            <div class="abs" style="left:{{ $mx }}pt; top:{{ $layout['header']['y'] }}pt; width:{{ $pw - 2 * $mx }}pt;">
                <div class="header-title">Rental Inspection Form — {{ $layout['header']['type'] }}</div>
                <div class="header-line">{{ $layout['header']['address'] }}</div>
                <div class="header-line">Date: {{ $layout['header']['date'] }}</div>
                <div class="header-line">Landlord: {{ trim($layout['header']['landlord']) ?: '—' }}</div>
                <div class="header-line">Tenant(s): {{ count($layout['header']['tenants']) ? implode(', ', $layout['header']['tenants']) : '—' }}</div>
                <div class="header-line">Inspecting agent: {{ $layout['header']['agent'] ?? '—' }}</div>
                <div class="legend">{{ count($prefill['ticks']) ? 'Grey boxes are already recorded on screen (' . count($prefill['ticks']) . ' items). Tick a different box to change one.' : 'Nothing recorded on screen yet.' }}</div>
            </div>
            {{-- Header fields the agent fills on site (never machine-read): shows what is already on the web inspection. --}}
            @php
                $fieldW = ($pw - 2 * $mx - 2 * 12) / 3;
                $fields = [
                    ['Electricity meter', 'electricity', 0, 0], ['Water meter', 'water', 1, 0], ['Furnished', 'furnished', 2, 0],
                    ['Keys (number / description)', 'keys', 0, 1], ['Remotes (number / description)', 'remotes', 1, 1],
                ];
            @endphp
            @foreach($fields as [$flabel, $fkey, $col, $frow])
                <div class="abs field-label" style="left:{{ $mx + $col * ($fieldW + 12) }}pt; top:{{ round($layout['header']['y'] + (96 + $frow * 26) * $ky, 2) }}pt; width:{{ $fieldW }}pt;">{{ $flabel }}</div>
                <div class="abs field-line" style="left:{{ $mx + $col * ($fieldW + 12) }}pt; top:{{ round($layout['header']['y'] + (105 + $frow * 26) * $ky, 2) }}pt; width:{{ $fieldW }}pt;">{{ $prefill['header'][$fkey] ?? '' }}</div>
            @endforeach
        @endif

        {{-- Room headings (repeated as "(cont.)" whenever a room spans a page break). --}}
        @foreach($layout['room_headings'] as $rh)
            @if($rh['page'] === $p)
                <div class="abs room-heading" style="left:{{ $mx }}pt; top:{{ $rh['y'] }}pt; width:{{ $pw - 2 * $mx }}pt;">{{ $rh['label'] }}</div>
            @endif
        @endforeach

        {{-- Condition column headers — printed ONCE per room table, not once per row. --}}
        @foreach($layout['condition_header_rows'] as $ch)
            @if($ch['page'] === $p)
                @foreach($ch['labels'] as $lbl)
                    <div class="abs cond-label" style="left:{{ $lbl['x'] }}pt; top:{{ $ch['y'] }}pt; width:10pt;">{{ $lbl['label'] }}</div>
                @endforeach
            @endif
        @endforeach

        {{-- One row per item: label, tick-box outlines (filled at the
             property by wet ink, never by us), a narrow ruled notes column
             for the human — never machine-read. --}}
        @foreach($layout['item_rows'] as $row)
            @if($row['page'] === $p)
                <div class="abs item-label" style="left:{{ $row['label_x'] }}pt; top:{{ $row['y'] + 5 }}pt; width:122pt;"><span class="row-no">{{ $row['row_no'] }}</span>&nbsp; {{ $row['label'] }}</div>
                <div class="abs notes-outline" style="left:{{ $row['notes_x'] }}pt; top:{{ $row['y'] }}pt; width:{{ $row['notes_width'] }}pt; height:{{ $row['notes_height'] ?? 18 }}pt;"></div>
            @endif
        @endforeach

        {{-- The tick-boxes themselves — this array is byte-for-byte the
             same one written into the manifest's own "boxes" block. --}}
        @foreach($layout['boxes'] as $box)
            @if($box['page'] === $p)
                <div class="abs box-outline {{ ($prefill['ticks'][$box['rental_inspection_item_id']] ?? null) === $box['condition_key'] ? 'box-pre' : '' }}" style="left:{{ $box['x'] }}pt; top:{{ $box['y'] }}pt; width:{{ $box['width'] }}pt; height:{{ $box['height'] }}pt;"></div>
            @endif
        @endforeach

        {{-- Signature block — never OMR-read; geometry only, so a wet-ink
             upload can later be cropped from a known region (§16's already-
             built RentalInspectionSignature::storeWetInkUpload()). --}}
        @foreach($layout['signature_blocks'] as $sig)
            @if($sig['page'] === $p)
                <div class="abs sig-block" style="left:{{ $sig['x'] }}pt; top:{{ $sig['y'] }}pt; width:{{ $sig['width'] }}pt; height:{{ $sig['height'] }}pt;"></div>
                <div class="abs sig-label" style="left:{{ $sig['x'] }}pt; top:{{ $sig['y'] + $sig['height'] + 2 }}pt; width:{{ $sig['width'] }}pt;">{{ ucfirst($sig['party_role']) }} — {{ $sig['label'] }}</div>
            @endif
        @endforeach
    </div>
@endfor

</body>
</html>
