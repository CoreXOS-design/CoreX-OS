<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Practitioner Register — {{ $agency->trading_name ?? $agency->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; }
        .page { padding: 36px 44px; }
        h1 { font-size: 18px; color: #0f172a; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 12px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #334155; font-weight: 700; text-transform: uppercase; font-size: 10px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 700; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .footer-note { margin-top: 20px; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="page">
    <h1>Property Practitioner Register</h1>
    <p class="subtitle">{{ $agency->trading_name ?? $agency->name }} &bull; generated {{ now()->format('d F Y, H:i') }}</p>

    <table>
        <thead>
            <tr><th>Name</th><th>Role</th><th>Designation</th><th>FFC Number</th><th>Status</th><th>Expiry</th></tr>
        </thead>
        <tbody>
            @forelse($roster as $agent)
            <tr>
                <td>{{ $agent['name'] }}</td>
                <td>{{ ucwords(str_replace('_', ' ', $agent['role'] ?? '')) }}</td>
                <td>{{ $agent['designation'] ?? '—' }}</td>
                <td>{{ $agent['ffc_number'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $agent['ffc']['status'] }}">{{ $agent['ffc']['label'] }}</span></td>
                <td>{{ $agent['ffc']['expiry_date'] ? \Illuminate\Support\Carbon::parse($agent['ffc']['expiry_date'])->format('d M Y') : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="6">No active practitioners found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer-note">{{ $agency->trading_name ?? $agency->name }} &bull; Property Practitioner Register &bull; {{ now()->format('d M Y H:i') }}</p>
</div>
</body>
</html>
