<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Inspection Report</title>
<style>
    {{--
        Johan, 2026-09-23, approved — NO PHOTOS ("printing the photos will
        be a shitshow... 100 pages"). Two columns always (previous/current),
        never one per chain link — a chain of four+ would make this
        unreadable on A4. DomPDF-safe: table layout throughout, no flex/
        grid (this renderer does not support them reliably).
    --}}
    @page { margin: 36pt; size: A4 portrait; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9pt; }
    h1 { font-size: 15pt; margin: 0 0 2pt 0; }
    .muted { color: #666; }
    .cover { margin-bottom: 14pt; }
    .qr-box { border: 1pt solid #999; padding: 8pt; margin-top: 10pt; }
    .qr-placeholder { width: 60pt; height: 60pt; border: 1pt dashed #999; display: inline-block; text-align: center; vertical-align: middle; font-size: 6pt; color: #999; }
    .room-heading { font-size: 10pt; font-weight: bold; text-transform: uppercase; border-bottom: 1pt solid #000; padding-bottom: 3pt; margin-top: 14pt; }
    table.compare { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    table.compare th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #666; border-bottom: 0.5pt solid #ccc; padding: 3pt 4pt; }
    table.compare td { font-size: 8.5pt; border-bottom: 0.5pt solid #eee; padding: 3pt 4pt; vertical-align: top; }
    .item-col { width: 26%; }
    .prev-col { width: 32%; }
    .cur-col { width: 32%; }
    .history-col { width: 10%; font-size: 7pt; color: #666; }
    {{-- §36, 2026-09-28 (Johan, property 5294) — "a condition and its note
         must JUMP OUT," on the printed report too, never muted grey. DomPDF
         has no theme/dark-mode and no CSS custom-property support, so
         these are literal hex — the exact same values as
         RentalInspectionSetting::SEVERITY_COLORS (passed in as
         $severityColors below), the one place this app's severity→colour
         mapping is defined, kept in sync by reading from it rather than
         restating the values here. --}}
    .cond-blue  { color: {{ $severityColors['blue'] }}; font-weight: bold; }
    .cond-red   { color: {{ $severityColors['red'] }}; font-weight: bold; }
    .cond-amber { color: {{ $severityColors['amber'] }}; font-weight: bold; }
    .cond-grey  { color: {{ $severityColors['grey'] }}; font-weight: bold; }
    .notes-callout { font-size: 7.5pt; color: #333; padding: 2pt 4pt; border-left: 2pt solid; margin-top: 1pt; }
    .notes-callout-red   { background: #fdecec; border-left-color: {{ $severityColors['red'] }}; }
    .notes-callout-amber { background: #fdf3e0; border-left-color: {{ $severityColors['amber'] }}; }
    .notes-callout-blue  { background: #eaf6fc; border-left-color: {{ $severityColors['blue'] }}; }
    .sig-table { width: 100%; margin-top: 16pt; border-collapse: collapse; }
    .sig-table td { font-size: 8pt; padding: 3pt 4pt; border-top: 0.5pt solid #ccc; }
    {{-- Johan, 2026-09-28 — "the PDF is the signed record that gets
         auto-emailed... it must carry the actual signature images." Kept
         to a sensible size — this is a printed legal record, not a canvas
         viewer. --}}
    .sig-image { max-height: 50px; margin-top: 3pt; display: block; }
    {{-- Conductor brief 2026-09-29 — "print for signature": the SAME
         report, marked so nobody mistakes a not-yet-complete printout for
         the finished, signed record. --}}
    .for-signature-banner { background: #fdecec; border: 1pt solid #c41e3a; color: #c41e3a; font-weight: bold; font-size: 9pt; padding: 6pt 8pt; margin-bottom: 10pt; text-transform: uppercase; letter-spacing: 0.5pt; }
    .blank-sig-block { margin-top: 4pt; }
    .blank-sig-line { border-bottom: 0.75pt solid #333; height: 18pt; margin-top: 10pt; }
    .blank-sig-caption { font-size: 7pt; color: #666; }
</style>
</head>
<body>
    @if($forSignature)
        <div class="for-signature-banner">For signature — this document is not yet complete</div>
    @endif
    <div class="cover">
        <p class="muted" style="text-transform:uppercase; font-size:7.5pt; letter-spacing:0.5pt;">{{ ucfirst($inspection->type) }}-inspection report</p>
        <h1>{{ $inspection->property?->buildDisplayAddress() }}</h1>
        <p class="muted">
            {{ $inspection->scheduled_for?->format('d M Y') ?? $inspection->created_at->format('d M Y') }}
            @if($inspection->completed_at) &middot; Completed {{ $inspection->completed_at->format('d M Y') }} @endif
            @if($inspection->previousInspection) &middot; Compared against the {{ $inspection->previousInspection->type }}-inspection of {{ $inspection->previousInspection->scheduled_for?->format('d M Y') ?? $inspection->previousInspection->created_at->format('d M Y') }} @endif
        </p>

        @if($publicUrl)
            <div class="qr-box">
                @if($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR code to the public inspection report" style="width:60pt; height:60pt; vertical-align:middle;">
                @else
                    <span class="qr-placeholder">QR&nbsp;pending</span>
                @endif
                <span style="display:inline-block; vertical-align:middle; margin-left:8pt; font-size:8pt;">
                    Photos and the full record for this inspection:<br>
                    <strong>{{ $publicUrl }}</strong>
                </span>
            </div>
        @endif
    </div>

    @if($inspection->overall_notes)
        <div class="notes-callout notes-callout-blue" style="margin: 6pt 0 8pt 0;">
            <strong>Overall notes:</strong> {{ $inspection->overall_notes }}
        </div>
    @endif

    @forelse($rows as $roomId => $roomRows)
        {{-- Multi-line block form deliberately, not the inline shorthand —
             see the agency-level show.blade.php's own comment on this
             exact fix. --}}
        @php
            $room = $roomRows->first()->room;
        @endphp
        <div id="room-{{ $roomId }}">
            <div class="room-heading">{{ $room?->label ?? 'General' }}</div>
            @if($publicUrl)
                <p class="muted" style="font-size:7pt; margin: 2pt 0 4pt 0;">This room's photos: {{ $publicUrl }}#room-{{ $roomId }}</p>
            @endif
            <table class="compare">
                <thead>
                    <tr>
                        <th class="item-col">Item</th>
                        <th class="prev-col">Previous{{ $inspection->previousInspection ? ' (' . ucfirst($inspection->previousInspection->type) . ')' : '' }}</th>
                        <th class="cur-col">Current ({{ ucfirst($inspection->type) }})</th>
                        <th class="history-col">Full run</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roomRows as $row)
                        <tr>
                            <td>{{ $row->item->label }}</td>
                            <td>
                                @if($row->previous)
                                    <span class="cond-{{ $row->previous_severity }}">{{ $row->previous_label }}</span>
                                    @if($row->previous->observation->notes)
                                        {{-- §36 — never muted grey: red/amber for an issue severity, the calm blue tone otherwise (a grey-severity condition like N/A still gets the blue callout, same "otherwise" bucket the recording screen uses). --}}
                                        <div class="notes-callout notes-callout-{{ in_array($row->previous_severity, ['red', 'amber'], true) ? $row->previous_severity : 'blue' }}">{{ $row->previous->observation->notes }}</div>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($row->current)
                                    <span class="cond-{{ $row->current_severity }}">{{ $row->current_label }}</span>
                                    @if($row->current->notes)
                                        <div class="notes-callout notes-callout-{{ in_array($row->current_severity, ['red', 'amber'], true) ? $row->current_severity : 'blue' }}">{{ $row->current->notes }}</div>
                                    @endif
                                @else
                                    <span class="muted">Not yet recorded</span>
                                @endif
                            </td>
                            <td class="history-col">{{ $row->history_text ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if($roomNotes->get($roomId))
                <div class="notes-callout notes-callout-blue" style="margin-top: 3pt;"><strong>Room note:</strong> {{ $roomNotes->get($roomId)->note }}</div>
            @endif
        </div>
    @empty
        <p class="muted">No observations recorded.</p>
    @endforelse

    {{-- Report-fixes, 2026-09-28 (Johan, property 5294) — rebuilt around
         RentalInspection::signatureSummaryRows(), the SAME resolver the
         public page uses, so the two documents can never disagree on who
         signed. Previously read `signer_role`/`signed_at`, columns that no
         longer exist since §15/§16's three-party rebuild — every row
         printed blank. --}}
    <table class="sig-table">
        <tr><td colspan="3" style="font-weight:bold; border-top:none; padding-top:0;">Signatures</td></tr>
        @foreach($signatureRows as $row)
            @php
                // Conductor brief 2026-09-29 — "print for signature": a
                // party with no live disposition yet, OR one still awaiting
                // a paper signature (nothing has arrived yet), gets a blank
                // signature block instead of a status line — this printout
                // is exactly what goes to them for that purpose.
                $isBlankEligible = ! $row['not_required']
                    && ($row['signature'] === null || $row['signature']->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK);
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
                    @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_REFUSED)
                        <span class="cond-red">Refused</span> —
                        {{ $refusalReasonLabels->get($row['signature']->refusal_reason_preset, ucfirst(str_replace('_', ' ', $row['signature']->refusal_reason_preset))) }}
                        @if($row['signature']->refusal_reason_note) ({{ $row['signature']->refusal_reason_note }}) @endif
                    @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_WET_INK)
                        Signed on paper — scan on file
                    @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK)
                        <span class="muted">Awaiting paper signature</span>
                    @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_SIGNED)
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
