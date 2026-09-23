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
    .notes { font-size: 7.5pt; color: #555; }
    .sig-table { width: 100%; margin-top: 16pt; border-collapse: collapse; }
    .sig-table td { font-size: 8pt; padding: 3pt 4pt; border-top: 0.5pt solid #ccc; }
</style>
</head>
<body>
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
                {{-- QR CODE NOT YET DRAWN — see RentalInspectionReportPdfService's
                     own docblock: no QR library in this codebase, pending
                     Johan naming one to add. The link below is fully live. --}}
                <span class="qr-placeholder">QR&nbsp;pending</span>
                <span style="display:inline-block; vertical-align:middle; margin-left:8pt; font-size:8pt;">
                    Photos and the full record for this inspection:<br>
                    <strong>{{ $publicUrl }}</strong>
                </span>
            </div>
        @endif
    </div>

    @forelse($rows as $roomId => $roomRows)
        {{-- Multi-line @endphp block form deliberately, not the inline
             shorthand — see the agency-level show.blade.php's own comment
             on this exact fix. --}}
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
                                    {{ ucfirst($row->previous->observation->condition) }}
                                    @if($row->previous->observation->notes)
                                        <div class="notes">{{ $row->previous->observation->notes }}</div>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($row->current)
                                    {{ ucfirst($row->current->condition) }}
                                    @if($row->current->notes)
                                        <div class="notes">{{ $row->current->notes }}</div>
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
        </div>
    @empty
        <p class="muted">No observations recorded.</p>
    @endforelse

    @if($inspection->signatures->isNotEmpty())
        <table class="sig-table">
            <tr><td colspan="2" style="font-weight:bold; border-top:none; padding-top:0;">Signatures</td></tr>
            @foreach($inspection->signatures as $signature)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $signature->signer_role)) }}</td>
                    <td>{{ $signature->signed_at?->format('d M Y, H:i') }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
