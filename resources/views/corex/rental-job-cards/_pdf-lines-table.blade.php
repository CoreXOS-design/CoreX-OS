{{--
    2026-10-05 rebuild — one task's (or the General group's) own lines
    table, shared between print.blade.php and quote-pdf.blade.php so both
    documents render the same task → lines layout (Johan's req #6).
    Expects: $lines, $pricesOn, $vat (RentalJobCardVatService::breakdown()).
--}}
@if($lines->isEmpty())
    <p class="muted">No lines.</p>
@else
<table class="lines">
    <tr>
        <th>Description</th>
        <th>Type</th>
        @if($pricesOn)
            <th>Qty</th>
            <th>Unit price</th>
            <th>Line total</th>
            @if($vat['registered'])
                <th>VAT type</th>
            @endif
        @endif
    </tr>
    @foreach($lines as $line)
    <tr>
        <td>{{ $line->code ? $line->code . ' — ' . $line->description : $line->description }}</td>
        <td>{{ ucfirst($line->type) }}</td>
        @if($pricesOn)
            <td>{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}{{ $line->unit ? ' ' . $line->unit : '' }}</td>
            <td>{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</td>
            <td>{{ $line->line_total !== null ? 'R' . number_format((float) $line->line_total, 2) : '—' }}</td>
            @if($vat['registered'])
                <td>{{ $line->vat_display_label ?? $line->vat_type_name_snapshot ?? '—' }}</td>
            @endif
        @endif
    </tr>
    @endforeach
</table>
@endif
