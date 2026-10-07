<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Due inspections</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Due inspections</h1>
    <table>
        <thead>
            <tr><th>Type</th><th>Property</th><th>Tenant(s)</th><th>Due</th><th>Status</th><th>Agent</th><th>Note</th></tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $typeLabels[$row['type']] ?? ucfirst($row['type']) }}</td>
                    <td>{{ $row['property_address'] }}</td>
                    <td>{{ $row['tenants'] ?: '—' }}</td>
                    <td>{{ $row['due_on']?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $stateLabels[$row['state']] ?? $row['state'] }}</td>
                    <td>{{ $row['agent_name'] ?? '—' }}</td>
                    <td>{{ $row['source'] === 'due_list' ? $row['reason'] : ($row['note'] ?: '') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
