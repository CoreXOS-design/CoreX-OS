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
    @page { margin: 0; size: A4 portrait; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #111; }
    .page { position: relative; width: {{ $pw = \App\Services\Rentals\RentalInspectionFormPdfService::PAGE_WIDTH }}pt; height: {{ $ph = \App\Services\Rentals\RentalInspectionFormPdfService::PAGE_HEIGHT }}pt; page-break-after: always; }
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
    .notes-outline { border: 0.5pt solid #999; }
    .header-line { font-size: 8.5pt; }
    .header-title { font-size: 13pt; font-weight: bold; }
    .sig-block { border: 0.5pt solid #666; }
    .sig-label { font-size: 7.5pt; text-align: center; }
</style>
</head>
<body>

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

        @if($p === 1)
            {{-- Header — property, inspection type/date, parties. Plain
                 printed text, never geometry-tracked (nothing here is
                 marked or read back). --}}
            <div class="abs" style="left:36pt; top:{{ $layout['header']['y'] }}pt; width:{{ $pw - 72 }}pt;">
                <div class="header-title">Rental Inspection Form — {{ $layout['header']['type'] }}</div>
                <div class="header-line">{{ $layout['header']['address'] }}</div>
                <div class="header-line">Date: {{ $layout['header']['date'] }}</div>
                <div class="header-line">Landlord: {{ trim($layout['header']['landlord']) ?: '—' }}</div>
                <div class="header-line">Tenant(s): {{ count($layout['header']['tenants']) ? implode(', ', $layout['header']['tenants']) : '—' }}</div>
                <div class="header-line">Inspecting agent: {{ $layout['header']['agent'] ?? '—' }}</div>
            </div>
        @endif

        {{-- Room headings (repeated as "(cont.)" whenever a room spans a page break). --}}
        @foreach($layout['room_headings'] as $rh)
            @if($rh['page'] === $p)
                <div class="abs room-heading" style="left:36pt; top:{{ $rh['y'] }}pt; width:{{ $pw - 72 }}pt;">{{ $rh['label'] }}</div>
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
                <div class="abs item-label" style="left:{{ $row['label_x'] }}pt; top:{{ $row['y'] + 5 }}pt; width:120pt;">{{ $row['label'] }}</div>
                <div class="abs notes-outline" style="left:{{ $row['notes_x'] }}pt; top:{{ $row['y'] }}pt; width:{{ $row['notes_width'] }}pt; height:18pt;"></div>
            @endif
        @endforeach

        {{-- The tick-boxes themselves — this array is byte-for-byte the
             same one written into the manifest's own "boxes" block. --}}
        @foreach($layout['boxes'] as $box)
            @if($box['page'] === $p)
                <div class="abs box-outline" style="left:{{ $box['x'] }}pt; top:{{ $box['y'] }}pt; width:{{ $box['width'] }}pt; height:{{ $box['height'] }}pt;"></div>
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
