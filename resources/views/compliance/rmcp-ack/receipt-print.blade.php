<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RMCP Acknowledgement Receipt - {{ $ack->user->name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; color: #0f172a; background: #fff; padding: 2rem; max-width: 700px; margin: 0 auto; }

        .header { background: #0f172a; color: #fff; padding: 1.5rem 2rem; text-align: center; border-radius: 3px 3px 0 0; }
        .header .label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #00d4aa; letter-spacing: 2px; }
        .header .ref { font-size: 0.7rem; color: #94a3b8; margin-top: 0.5rem; }

        .body { border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 3px 3px; padding: 1.5rem 2rem; }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem; }
        .grid .cell-label { font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
        .grid .cell-value { font-size: 0.8rem; font-weight: 600; color: #0f172a; margin-top: 0.15rem; }
        .grid .cell-value.teal { color: #00d4aa; }

        h4 { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; margin-bottom: 0.5rem; }

        .sections { margin-bottom: 1.5rem; }
        .section-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.7rem; padding: 0.4rem 0.75rem; background: #f8fafc; border-radius: 3px; margin-bottom: 0.25rem; }
        .section-row .time { color: #00d4aa; }

        .signature-box { border: 1px dashed #e5e7eb; border-radius: 3px; padding: 1rem; text-align: center; margin-bottom: 1.5rem; }
        .signature-box .typed { font-family: 'Dancing Script', cursive; font-size: 1.5rem; color: #0f172a; }
        .signature-box img { max-height: 80px; margin: 0 auto; display: block; }

        .footer { border-top: 1px solid #e5e7eb; padding-top: 0.75rem; text-align: center; font-size: 0.65rem; color: #94a3b8; }

        .no-print { text-align: center; margin-bottom: 1.5rem; }
        .no-print button { font-family: 'Plus Jakarta Sans', sans-serif; padding: 0.5rem 1.5rem; font-size: 0.8rem; font-weight: 700; color: #fff; background: #00d4aa; border: none; border-radius: 3px; cursor: pointer; }
        .no-print button:hover { opacity: 0.85; }
        .no-print .hint { font-size: 0.7rem; color: #94a3b8; margin-top: 0.5rem; }

        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Save / Print PDF</button>
        <div class="hint">Use "Save as PDF" in your browser's print dialog to download.</div>
    </div>

    <div class="header">
        <div class="label">RMCP Acknowledgement Receipt</div>
        <div class="ref">Ref: ACK-{{ str_pad($ack->id, 6, '0', STR_PAD_LEFT) }}</div>
    </div>

    <div class="body">
        <div class="grid">
            <div>
                <div class="cell-label">Staff Member</div>
                <div class="cell-value">{{ $ack->user->name }}</div>
            </div>
            <div>
                <div class="cell-label">RMCP Version</div>
                <div class="cell-value">v{{ $ack->version->version_number }}</div>
            </div>
            <div>
                <div class="cell-label">Acknowledged On</div>
                <div class="cell-value">{{ $ack->completed_at?->format('d F Y H:i') ?? '-' }}</div>
            </div>
            <div>
                <div class="cell-label">Valid Until</div>
                <div class="cell-value teal">{{ $ack->valid_until?->format('d F Y') ?? '-' }}</div>
            </div>
            <div>
                <div class="cell-label">IP Address</div>
                <div class="cell-value">{{ $ack->ip_address ?? '-' }}</div>
            </div>
            <div>
                <div class="cell-label">Sections Acknowledged</div>
                <div class="cell-value">{{ $ack->sections_acknowledged_count }} of {{ $ack->sections_total_count }}</div>
            </div>
        </div>

        <div class="sections">
            <h4>Acknowledged Sections</h4>
            @foreach($ack->sectionAcknowledgements->sortBy('section.display_order') as $sa)
            <div class="section-row">
                <span>{{ $sa->section->section_number }}. {{ $sa->section->title }}</span>
                @if($sa->acknowledged)
                <span class="time">{{ $sa->acknowledged_at?->format('H:i') }}</span>
                @else
                <span style="color:#94a3b8;">-</span>
                @endif
            </div>
            @endforeach
        </div>

        <div>
            <h4>Signature</h4>
            <div class="signature-box">
                @if($ack->signature_type === 'typed' && $ack->typed_signature_name)
                    <span class="typed">{{ $ack->typed_signature_name }}</span>
                @elseif($ack->signature_type === 'drawn' && $ack->signature_path && !str_starts_with($ack->signature_path, 'typed:'))
                    <img src="{{ asset('storage/' . $ack->signature_path) }}" alt="Signature">
                @else
                    <span style="font-size:0.7rem; color:#94a3b8; font-style:italic;">Signature not captured</span>
                @endif
            </div>
        </div>

        <div class="footer">
            This receipt serves as proof of RMCP acknowledgement for FICA compliance audit purposes.
        </div>
    </div>

    <script>
        window.onafterprint = function() { /* stay on page after print */ };
    </script>
</body>
</html>
