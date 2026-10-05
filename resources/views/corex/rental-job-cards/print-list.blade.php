<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Job Cards</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Job Cards</h1>
    <table>
        <thead>
            <tr><th>Property</th><th>Title</th><th>Tenant</th><th>Crew</th><th>Status</th><th>Due</th></tr>
        </thead>
        <tbody>
            @foreach($jobCards as $jc)
                <tr>
                    <td>{{ $jc->property?->buildDisplayAddress() ?? '—' }}</td>
                    <td>{{ $jc->title }}</td>
                    <td>{{ $jc->lease?->tenantNames() ?? '—' }}</td>
                    <td>{{ $jc->crew?->name ?? ($jc->assigned_user_id ? 'Previously assigned: ' . ($jc->assignedUser?->name ?? '—') : '—') }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $jc->status)) }}</td>
                    <td>{{ $jc->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
