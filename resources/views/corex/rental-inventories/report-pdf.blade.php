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
</style>
</head>
<body>
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

    @if($inventory->signatures->isNotEmpty())
        <table class="sig-table">
            <tr><td colspan="2" style="font-weight:bold; border-top:none; padding-top:0;">Signatures</td></tr>
            @foreach($inventory->signatures as $signature)
                {{-- Report-fixes, 2026-09-28 (Johan) — the agent's own row
                     never carries a party_contact_id (an agent is a CoreX
                     user, not a Contact), so reading partyContact alone
                     always printed a bare "Agent" with no name. Falls back
                     to recordedByUser — the real person who signed — the
                     same fix applied to the public page and the show page. --}}
                <tr>
                    <td>
                        {{ ucfirst($signature->party_role) }}{{ $signature->partyContact ? ' — ' . $signature->partyContact->full_name : ($signature->party_role === 'agent' && $signature->recordedByUser ? ' — ' . $signature->recordedByUser->name : '') }}
                    </td>
                    <td>
                        {{ $signature->disposition === 'signed' ? 'Signed' : 'Refused to sign' }}
                        {{ $signature->disposition_recorded_at?->format('d M Y, H:i') }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
