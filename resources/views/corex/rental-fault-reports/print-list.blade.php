<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rental Fault Reports</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Rental Fault Reports</h1>
    @include('corex.rentals.partials._print-list-filters')
    <table>
        <thead>
            <tr><th>Property</th><th>Title</th><th>Status</th><th>Outcome</th><th>Reported</th></tr>
        </thead>
        <tbody>
            @foreach($faultReports as $faultReport)
                <tr>
                    <td>{{ $faultReport->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td>{{ $faultReport->title }}</td>
                    <td>{{ $faultReport->statusLabel() }}</td>
                    <td>{{ $faultReport->outcome ? ucfirst(str_replace('_', ' ', $faultReport->outcome)) : '—' }}</td>
                    <td>{{ $faultReport->reported_at?->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
