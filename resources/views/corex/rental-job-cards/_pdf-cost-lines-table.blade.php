{{--
    .ai/specs/rental-work-orders.md §17.4.7 / §17.21.1 — one task's (or the General group's) lines on the
    WORKER-facing printed job card. "Crew works on actual costs, not selling": this partial reads the COST
    columns only (`unit_cost` / `cost_total`) and never `unit_price` / `line_total`, markup or margin.

    Expects: $lines, $costsOn.
    With $costsOn false the cost columns are absent altogether (never a 0.00, never a blank column).
    A line with no cost recorded shows "—". VAT on cost is Build 1's costBreakdown(); until then the printed
    card shows plain cost totals, no VAT column.

    The shared _pdf-lines-table partial stays SELLING-only and is used by the owner quote PDF alone.
--}}
@if($lines->isEmpty())
    <p class="muted">No lines.</p>
@else
<table class="lines" style="table-layout: fixed;">
    <thead>
    <tr>
        <th>Description</th>
        <th style="width: {{ $costsOn ? '11%' : '16%' }};">Type</th>
        @if($costsOn)
            <th style="width: 12%;">Qty</th>
            <th style="width: 16%;">Unit cost</th>
            <th style="width: 16%;">Cost</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach($lines as $line)
    <tr>
        <td>{{ $line->code ? $line->code . ' — ' . $line->description : $line->description }}</td>
        <td>{{ ucfirst($line->type) }}</td>
        @if($costsOn)
            <td>{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}{{ $line->unit ? ' ' . $line->unit : '' }}</td>
            <td>{{ $line->unit_cost !== null ? 'R' . number_format((float) $line->unit_cost, 2) : '—' }}</td>
            <td>{{ $line->cost_total !== null ? 'R' . number_format((float) $line->cost_total, 2) : '—' }}</td>
        @endif
    </tr>
    @endforeach
    @if($costsOn)
    <tr class="total-row">
        <td colspan="4">Cost subtotal</td>
        <td>R{{ number_format((float) $lines->sum('cost_total'), 2) }}</td>
    </tr>
    @endif
    </tbody>
</table>
@endif
