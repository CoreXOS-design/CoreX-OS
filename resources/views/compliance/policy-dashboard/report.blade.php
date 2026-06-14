<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Policy Compliance Register — {{ $agency->name }}</title>
    <style>
        body { font-family: 'Figtree', Arial, sans-serif; font-size: 12px; color: #334155; margin: 2rem; }
        h1 { font-size: 16px; color: #0f172a; border-bottom: 2px solid #00b4d8; padding-bottom: 6px; }
        h2 { font-size: 13px; color: #0f172a; margin-top: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
        th { background: #f8fafc; text-align: left; padding: 6px 8px; border: 1px solid #e5e7eb; font-size: 11px; font-weight: 600; color: #64748b; }
        td { padding: 6px 8px; border: 1px solid #e5e7eb; font-size: 11px; }
        .valid { color: #0d9488; font-weight: 600; }
        .expired { color: #dc2626; font-weight: 600; }
        .notack { color: #dc2626; }
        .footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>Policy Compliance Register</h1>
    <p><strong>{{ $agency->name }}</strong> — {{ $selectedPolicy->name ?? 'No policy selected' }} — Generated {{ now()->format('d F Y H:i') }}</p>

    @if($activeVersion)
    <p>Active version: <strong>v{{ $activeVersion->version_number }}</strong>
       | Approved: {{ $activeVersion->approved_at?->format('d M Y') ?? 'N/A' }}
       | Next review: {{ $activeVersion->next_review_due?->format('d M Y') ?? 'N/A' }}</p>
    @endif

    <h2>Staff Acknowledgement Register</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Acknowledged On</th>
                <th>Valid Until</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($staffData as $s)
            <tr>
                <td>{{ $s['name'] }}</td>
                <td>{{ $s['email'] }}</td>
                <td>{{ $s['role'] }}</td>
                <td>{{ $s['acknowledged_on'] ?? '-' }}</td>
                <td>{{ $s['valid_until'] ?? '-' }}</td>
                <td class="{{ $s['status'] === 'Valid' ? 'valid' : ($s['status'] === 'Expired' ? 'expired' : 'notack') }}">{{ $s['status'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>Compliance Officer signature: __________________________ Date: ______________</p>
        <p>This register is maintained for compliance audit purposes.</p>
    </div>
</body>
</html>
