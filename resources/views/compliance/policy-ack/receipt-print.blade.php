<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Policy Acknowledgement Receipt - {{ $ack->user->name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Figtree', sans-serif; color: #0f172a; background: #fff; padding: 10mm 15mm; max-width: 700px; margin: 0 auto; }

        .header { background: #0f172a; color: #fff; padding: 0.6rem 1.5rem; text-align: center; border-radius:6px 3px 0 0; }
        .header .label { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; color: #00b4d8; letter-spacing: 2px; }
        .header .ref { font-size: 0.6rem; color: #94a3b8; margin-top: 0.2rem; }

        .body { border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 3px 3px; padding: 0.75rem 1.5rem; }

        .grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.3rem 0.75rem; margin-bottom: 0.6rem; }
        .grid .cell-label { font-size: 0.55rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
        .grid .cell-value { font-size: 0.7rem; font-weight: 600; color: #0f172a; margin-top: 0.05rem; }
        .grid .cell-value.teal { color: #0d9488; }

        h4 { font-size: 0.55rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; margin-bottom: 0.25rem; }

        .sections { margin-bottom: 0.5rem; }
        .section-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.6rem; padding: 0.15rem 0.5rem; background: #f8fafc; border-radius: 2px; margin-bottom: 1px; line-height: 1.3; }
        .section-row .time { color: #0d9488; font-size: 0.55rem; }

        .signature-block { page-break-inside: avoid; }
        .signature-box { border: 1px dashed #e5e7eb; border-radius:6px; padding: 0.4rem; text-align: center; margin-bottom: 0.5rem; }
        .signature-box .typed { font-family: 'Dancing Script', cursive; font-size: 1.2rem; color: #0f172a; }
        .signature-box img { max-height: 50px; margin: 0 auto; display: block; }

        .footer { border-top: 1px solid #e5e7eb; padding-top: 0.3rem; text-align: center; font-size: 0.5rem; color: #94a3b8; }

        @media print {
            body { padding: 0; }
            @page { size: A4; margin: 15mm; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="label">{{ $ack->policy->name }} — Acknowledgement Receipt</div>
        <div class="ref">Ref: ACK-{{ str_pad($ack->id, 6, '0', STR_PAD_LEFT) }}</div>
    </div>

    <div class="body">
        <div class="grid">
            <div>
                <div class="cell-label">Staff Member</div>
                <div class="cell-value">{{ $ack->user->name }}</div>
            </div>
            <div>
                <div class="cell-label">Policy Version</div>
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

        <div class="signature-block">
            <h4>Signature</h4>
            <div class="signature-box">
                @if($ack->signature_type === 'typed' && $ack->typed_signature_name)
                    <span class="typed">{{ $ack->typed_signature_name }}</span>
                @elseif($ack->signature_type === 'drawn' && $ack->signature_path && !str_starts_with($ack->signature_path, 'typed:'))
                    <img src="{{ asset('storage/' . $ack->signature_path) }}" alt="Signature">
                @else
                    <span style="font-size:0.6rem; color:#94a3b8; font-style:italic;">Signature not captured</span>
                @endif
            </div>
        </div>

        @if($ack->declaration_text)
        <div style="margin-top:0.5rem; font-size:0.55rem; color:#64748b; line-height:1.4;">
            <h4>Declaration</h4>
            {{ $ack->declaration_text }}
        </div>
        @endif

        <div class="footer">
            This receipt serves as proof of policy acknowledgement for compliance audit purposes.
        </div>
    </div>
</body>
</html>
