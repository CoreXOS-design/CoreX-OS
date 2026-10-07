<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leases</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Leases</h1>
    @include('corex.rentals.partials._print-list-filters')
    <table>
        <thead>
            <tr><th>Property</th><th>Tenant(s)</th><th>Status</th><th>Start</th><th>End</th><th>Rent</th><th>Agreement</th></tr>
        </thead>
        <tbody>
            @foreach($leases as $lease)
                <tr>
                    <td>{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td>{{ $lease->tenantNames() }}</td>
                    <td>{{ ucfirst($lease->status) }}</td>
                    <td>{{ $lease->start_date?->format('Y-m-d') }}</td>
                    <td>{{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</td>
                    <td>R{{ number_format((float) $lease->rental_amount, 2) }}</td>
                    <td>{{ $lease->signingStatusLabel() ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
