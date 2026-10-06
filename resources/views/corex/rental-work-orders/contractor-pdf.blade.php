<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('corex.rental-work-orders.pdf._style')
</head>
<body>
    @include('corex.rental-work-orders.pdf._header', ['docTitle' => 'Work order', 'docMeta' => '#' . $workOrder->id . ' · ' . ($workOrder->reported_at?->format('Y-m-d') ?? now()->format('Y-m-d'))])

    <div class="box">
        <table>
            <tr><td class="label">Reference</td><td>Work order #{{ $workOrder->id }}</td></tr>
            <tr><td class="label">Property</td><td>{{ $workOrder->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Access</td><td>Please contact {{ $agencyName }} to arrange access to the property.</td></tr>
            <tr><td class="label">Trade type</td><td>{{ $workOrder->trade_type ? ucfirst($workOrder->trade_type) : '—' }}</td></tr>
        </table>
    </div>

    <h2>Job</h2>
    <div class="box">
        <p><strong>{{ $workOrder->title }}</strong></p>
        <p class="note">{{ $workOrder->description }}</p>
    </div>

    <h2>Approved quote</h2>
    <div class="box">
        @if($quote)
            <table>
                <tr><td class="label">Contractor</td><td>{{ $workOrder->supplier?->name ?? $quote->supplier?->name ?? '—' }}</td></tr>
                <tr><td class="label">Quote amount</td><td><strong>R{{ number_format((float) $quote->amount, 2) }}</strong></td></tr>
                <tr><td class="label">Quote date</td><td>{{ $quote->quote_date?->format('Y-m-d') ?? '—' }}</td></tr>
            </table>
        @else
            <p class="muted">No quote on file.</p>
        @endif
        <p style="margin-top:6px;"><strong>{{ $ownerApprovalLine }}</strong></p>
    </div>

    <h2>Agency contact</h2>
    <div class="box"><p>{{ $agencyName }}</p></div>
</body>
</html>
