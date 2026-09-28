<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales &amp; Rentals — {{ $agency->trading_name ?? $agency->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; }
        .page { padding: 36px 44px; }
        h1 { font-size: 18px; color: #0f172a; margin-bottom: 4px; }
        h2 { font-size: 14px; color: #0f172a; margin: 20px 0 8px; }
        .subtitle { color: #64748b; font-size: 12px; margin-bottom: 12px; }
        .note { color: #475569; font-size: 10.5px; margin-bottom: 16px; background: #f1f5f9; padding: 8px 10px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-bottom: 16px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #334155; font-weight: 700; text-transform: uppercase; font-size: 10px; }
        .footer-note { margin-top: 20px; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="page">
    <h1>Sales &amp; Rentals — Current Financial Year</h1>
    <p class="subtitle">{{ $agency->trading_name ?? $agency->name }} &bull; {{ $rangeLabel }} &bull; generated {{ now()->format('d F Y, H:i') }}</p>

    <p class="note">
        "Advertised" is derived from Property24/PrivateProperty activation and the agency's own website syndication.
        P24/PrivateProperty carry only the most recent activation date, not a full on/off history — see the PPRA Inspection
        Report's item (j) section for the full caveat.
    </p>

    <h2>Sales ({{ $sales->count() }})</h2>
    <table>
        <thead><tr><th>Address</th><th style="width:25%;">Suburb</th><th style="width:20%;">Listed Date</th></tr></thead>
        <tbody>
            @forelse($sales as $p)
            <tr><td>{{ $p->address }}</td><td>{{ $p->suburb ?? '—' }}</td><td>{{ optional($p->listed_date)->format('d M Y') ?? '—' }}</td></tr>
            @empty
            <tr><td colspan="3">No sales were active and advertised in this window.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Rentals ({{ $rentals->count() }})</h2>
    <table>
        <thead><tr><th>Address</th><th style="width:25%;">Suburb</th><th style="width:20%;">Listed Date</th></tr></thead>
        <tbody>
            @forelse($rentals as $p)
            <tr><td>{{ $p->address }}</td><td>{{ $p->suburb ?? '—' }}</td><td>{{ optional($p->listed_date)->format('d M Y') ?? '—' }}</td></tr>
            @empty
            <tr><td colspan="3">No rentals were active and advertised in this window.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer-note">{{ $agency->trading_name ?? $agency->name }} &bull; Sales &amp; Rentals — {{ $rangeLabel }} &bull; {{ now()->format('d M Y H:i') }}</p>
</div>
</body>
</html>
