<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('corex.rental-work-orders.pdf._style')
</head>
<body>
    @include('corex.rental-work-orders.pdf._header', ['docTitle' => 'Final statement', 'docMeta' => 'Work order #' . $workOrder->id . ' · ' . ($workOrder->completed_at?->format('Y-m-d') ?? now()->format('Y-m-d'))])

    @if($emergencyBanner)<div class="banner">{{ $emergencyBanner }}</div>@endif

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $workOrder->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Job</td><td>{{ $workOrder->title }}</td></tr>
            <tr><td class="label">Completed</td><td>{{ $workOrder->completed_at?->format('Y-m-d') ?? '—' }}</td></tr>
            <tr><td class="label">Paid by</td><td>{{ $workOrder->paid_by ? ucfirst(str_replace('_', ' ', $workOrder->paid_by)) : '—' }}</td></tr>
            @if($vatNumber)<tr><td class="label">VAT No</td><td>{{ $vatNumber }}</td></tr>@endif
        </table>
    </div>

    <h2>Work done</h2>
    <div class="box">
        <p class="note">{{ $workOrder->description }}</p>
        @if($workOrder->completion_notes)<p class="note muted">{{ $workOrder->completion_notes }}</p>@endif
    </div>

    @if($lines->isNotEmpty())
        <h2>Items</h2>
        <table class="lines">
            <thead><tr><th style="width:52%;">Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Total</th></tr></thead>
            <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="r">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.') }} {{ $line->unit }}</td>
                    <td class="r">{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</td>
                    <td class="r">{{ $line->line_total !== null ? 'R' . number_format((float) $line->line_total, 2) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Amount</h2>
    <div class="box">
        <table>
            <tr><td class="label"><strong>Final amount</strong></td><td><strong>{{ $workOrder->cost_amount !== null ? 'R' . number_format((float) $workOrder->cost_amount, 2) : '—' }}</strong>{{ $vatRegistered && $workOrder->cost_amount !== null ? ' incl. VAT' : '' }}</td></tr>
        </table>
        @if($emergencyBanner)<p class="muted">Emergency work: the amount was settled after the work was done.</p>@endif
    </div>
</body>
</html>
