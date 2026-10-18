<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Inventory Report</title>
<style>
    {{--
        §41-follow-up (Job 3) — same "no photos in the PDF, a link instead"
        rule as RentalInspectionReportPdfService's own report (see that
        view's own docblock for the full reasoning). DomPDF-safe: table
        layout throughout, no flex/grid.
    --}}
    @page { margin: 36pt; size: A4 portrait; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9pt; }
    h1 { font-size: 15pt; margin: 0 0 2pt 0; }
    .muted { color: #666; }
    .cover { margin-bottom: 14pt; }
    .link-box { border: 1pt solid #999; padding: 8pt; margin-top: 10pt; font-size: 8pt; }
    .qr-img { width: 60pt; height: 60pt; vertical-align: middle; }
    .link-text { display: inline-block; vertical-align: middle; margin-left: 8pt; }
    .room-heading { font-size: 10pt; font-weight: bold; text-transform: uppercase; border-bottom: 1pt solid #000; padding-bottom: 3pt; margin-top: 14pt; }
    .room-empty-note { font-size: 8.5pt; color: #666; font-style: italic; margin-top: 4pt; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    table.lines th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #666; border-bottom: 0.5pt solid #ccc; padding: 3pt 4pt; }
    table.lines td { font-size: 8.5pt; border-bottom: 0.5pt solid #eee; padding: 3pt 4pt; vertical-align: top; }
    .qty-col { width: 10%; }
    .desc-col { width: 60%; }
    .cond-col { width: 30%; }
    .sig-table { width: 100%; margin-top: 16pt; border-collapse: collapse; }
    .sig-table td { font-size: 8pt; padding: 3pt 4pt; border-top: 0.5pt solid #ccc; }
    {{-- Johan, 2026-09-28 — "the PDF is the signed record that gets
         auto-emailed... it must carry the actual signature images." Kept
         to a sensible size — this is a printed legal record, not a canvas
         viewer. --}}
    .sig-image { max-height: 50px; margin-top: 3pt; display: block; }
    {{-- Conductor brief 2026-09-29 — "print for signature": the SAME
         report, marked so nobody mistakes a not-yet-complete printout for
         the finished, signed record. Mirrors the inspection report's own
         .for-signature-banner/.blank-sig-* rules exactly. --}}
    .for-signature-banner { background: #fdecec; border: 1pt solid #c41e3a; color: #c41e3a; font-weight: bold; font-size: 9pt; padding: 6pt 8pt; margin-bottom: 10pt; text-transform: uppercase; letter-spacing: 0.5pt; }
    .blank-sig-block { margin-top: 4pt; }
    .blank-sig-line { border-bottom: 0.75pt solid #333; height: 18pt; margin-top: 10pt; }
    .blank-sig-caption { font-size: 7pt; color: #666; }
    .cond-red { color: #c41e3a; font-weight: bold; }
    .cond-blue { color: #0ea5e9; font-weight: bold; }
</style>
</head>
<body>
    @if($forSignature)
        <div class="for-signature-banner">For signature — this document is not yet complete</div>
    @endif
    <div class="cover">
        <p class="muted" style="text-transform:uppercase; font-size:7.5pt; letter-spacing:0.5pt;">Inventory report</p>
        <h1>{{ $inventory->property?->buildDisplayAddress() }}</h1>
        <p class="muted">
            Started {{ $inventory->created_at?->format('d M Y') }}
            @if($inventory->completed_at) &middot; Completed {{ $inventory->completed_at->format('d M Y') }} @endif
        </p>

        @if($publicUrl)
            <div class="link-box">
                @if($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR code to the public inventory report" class="qr-img">
                @endif
                <span class="link-text">
                    Photos and the full record for this inventory:<br>
                    <strong>{{ $publicUrl }}</strong>
                </span>
            </div>
        @endif
    </div>

    @forelse($linesByRoom as $roomLabel => $roomLines)
        <div>
            <div class="room-heading">{{ $roomLabel }}</div>
            <table class="lines">
                <thead>
                    <tr>
                        <th class="qty-col">Qty</th>
                        <th class="desc-col">Description</th>
                        <th class="cond-col">Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roomLines as $line)
                        <tr>
                            <td>{{ $line->quantity }}</td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->condition_key ? ucfirst($line->condition_key) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="muted">No items recorded.</p>
    @endforelse

    {{-- Report-fixes, 2026-09-28 (Johan) — a room explicitly checked and
         found to have nothing in it (RentalInventoryRoomMark) still gets a
         section on the signed record — silently omitting it looked
         identical to a room nobody ever checked. --}}
    @foreach($emptyRoomLabels as $roomLabel)
        <div>
            <div class="room-heading">{{ $roomLabel }}</div>
            <p class="room-empty-note">Checked — nothing recorded in this room.</p>
        </div>
    @endforeach

    {{-- Conductor brief 2026-09-29 — rebuilt around
         RentalInventory::signatureSummaryRows() (mirrors
         RentalInspectionReportPdfService's own use of
         RentalInspection::signatureSummaryRows() exactly), so the FULL
         required roster prints — including a party who hasn't dispositioned
         yet — not just rows that already exist in $inventory->signatures. --}}
    <table class="sig-table">
        <tr><td colspan="3" style="font-weight:bold; border-top:none; padding-top:0;">Signatures</td></tr>
        @foreach($signatureRows as $row)
            @php
                $isBlankEligible = ! $row['not_required']
                    && ($row['signature'] === null || $row['signature']->disposition === \App\Models\RentalInventorySignature::DISPOSITION_AWAITING_WET_INK);
            @endphp
            <tr>
                <td>{{ $row['role'] }}{{ $row['name'] ? ' — ' . $row['name'] : '' }}</td>
                <td>
                    @if($row['not_required'])
                        Not required
                    @elseif($forSignature && $isBlankEligible)
                        <div class="blank-sig-block">
                            <div class="blank-sig-line"></div>
                            <div class="blank-sig-caption">Signature</div>
                            <div class="blank-sig-line" style="width:50%;"></div>
                            <div class="blank-sig-caption">Date</div>
                        </div>
                    @elseif($row['signature']?->disposition === \App\Models\RentalInventorySignature::DISPOSITION_REFUSED)
                        <span class="cond-red">Refused</span> —
                        {{ $refusalReasonLabels->get($row['signature']->refusal_reason_preset, ucfirst(str_replace('_', ' ', $row['signature']->refusal_reason_preset))) }}
                        @if($row['signature']->refusal_reason_note) ({{ $row['signature']->refusal_reason_note }}) @endif
                    @elseif($row['signature']?->disposition === \App\Models\RentalInventorySignature::DISPOSITION_WET_INK)
                        Signed on paper — scan on file
                    @elseif($row['signature']?->disposition === \App\Models\RentalInventorySignature::DISPOSITION_AWAITING_WET_INK)
                        <span class="muted">Awaiting paper signature</span>
                    @elseif($row['signature']?->disposition === \App\Models\RentalInventorySignature::DISPOSITION_SIGNED)
                        <span class="cond-blue">Signed</span>
                        @if($row['signature_image_data_uri'])
                            <img class="sig-image" src="{{ $row['signature_image_data_uri'] }}" alt="{{ $row['role'] }} signature">
                        @endif
                    @else
                        <span class="muted">Outstanding</span>
                    @endif
                </td>
                <td>{{ $row['signature']?->disposition_recorded_at?->format('d M Y, H:i') }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
