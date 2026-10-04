<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rental Work Orders</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Rental Work Orders</h1>
    @include('corex.rentals.partials._print-list-filters')
    <table>
        <thead>
            <tr><th>Property</th><th>Title</th><th>Status</th><th>Priority</th><th>Supplier</th><th>Paid by</th><th>Reported</th></tr>
        </thead>
        <tbody>
            @foreach($workOrders as $workOrder)
                <tr>
                    <td>{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td>{{ $workOrder->title }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}</td>
                    <td>{{ $workOrder->priority ? ucfirst($workOrder->priority) : '—' }}</td>
                    <td>{{ $workOrder->supplier?->name ?? '—' }}</td>
                    <td>{{ $workOrder->paid_by ? ucfirst(str_replace('_', ' ', $workOrder->paid_by)) : '—' }}</td>
                    <td>{{ $workOrder->reported_at?->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <script>window.onload = () => window.print();</script>
</body>
</html>
