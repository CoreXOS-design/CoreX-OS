<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rental Inspections</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Rental Inspections</h1>
    @include('corex.rentals.partials._print-list-filters')
    <table>
        <thead>
            <tr><th>Property</th><th>Tenant(s)</th><th>Type</th><th>Status</th><th>Scheduled</th></tr>
        </thead>
        <tbody>
            @foreach($inspections as $inspection)
                <tr>
                    <td>{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td>{{ $inspection->lease?->tenantNames() ?? '—' }}</td>
                    <td>{{ ucfirst(str_replace('_', '-', $inspection->type)) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</td>
                    <td>{{ $inspection->scheduled_for?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
