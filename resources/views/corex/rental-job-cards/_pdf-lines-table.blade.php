{{--
    2026-10-05 rebuild — one task's (or the General group's) own lines
    table, shared between print.blade.php and quote-pdf.blade.php so both
    documents render the same task → lines layout (Johan's req #6).
    Expects: $lines, $pricesOn, $vat (RentalJobCardVatService::breakdown()).

    2026-10-05 evening (§14.21, Johan) — the VAT TYPE column is gone (it
    showed "—": the figures were being read off model instances other than
    the ones this partial iterates). For a VAT-registered agency a line now
    shows its Excl VAT amount and its VAT amount, read from
    $vat['lineFigures'][line id]; each group's subtotal row carries the
    group's excl and VAT. A non-VAT agency keeps the plain Line total
    column and shows no VAT column anywhere. When the agency captures
    prices INCLUDING VAT the unit price header says so, so the row adds up.
--}}
@php
    $registered = (bool) ($vat['registered'] ?? false);
    $figures = $vat['lineFigures'] ?? [];
    $unitPriceLabel = $registered && ($vat['captureMode'] ?? null) === \App\Models\Agency::VAT_CAPTURE_INCL ? 'Unit price (incl VAT)' : 'Unit price';
    $groupExcl = $registered ? collect($lines)->sum(fn ($l) => $figures[$l->id]['excl'] ?? 0) : 0;
    $groupVat = $registered ? collect($lines)->sum(fn ($l) => $figures[$l->id]['vat'] ?? 0) : 0;
@endphp
{{-- §14.22 — fixed layout + column widths: with the default auto layout one long unbroken word
     (a part number) widened the table past the page's right margin and cut the price columns off. --}}
@if($lines->isEmpty())
    <p class="muted">No lines.</p>
@else
<table class="lines" style="table-layout: fixed;">
    <thead>
    <tr>
        <th>Description</th>
        <th style="width: {{ $pricesOn ? '11%' : '16%' }};">Type</th>
        @if($pricesOn)
            <th style="width: 10%;">Qty</th>
            <th style="width: 15%;">{{ $unitPriceLabel }}</th>
            @if($registered)
                <th style="width: 14%;">Excl VAT</th>
                <th style="width: 12%;">VAT</th>
            @else
                <th style="width: 14%;">Line total</th>
            @endif
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach($lines as $line)
    <tr>
        <td>{{ $line->code ? $line->code . ' — ' . $line->description : $line->description }}</td>
        <td>{{ ucfirst($line->type) }}</td>
        @if($pricesOn)
            <td>{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}{{ $line->unit ? ' ' . $line->unit : '' }}</td>
            <td>{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</td>
            @if($registered)
                <td>{{ isset($figures[$line->id]) ? 'R' . number_format((float) $figures[$line->id]['excl'], 2) : '—' }}</td>
                <td>{{ isset($figures[$line->id]) ? 'R' . number_format((float) $figures[$line->id]['vat'], 2) : '—' }}</td>
            @else
                <td>{{ $line->line_total !== null ? 'R' . number_format((float) $line->line_total, 2) : '—' }}</td>
            @endif
        @endif
    </tr>
    @endforeach
    {{-- 2026-10-05 overnight re-verification — Johan's own task-subtotal
         ask: a group's own lines total, distinct from the single grand
         subtotal/VAT/total already shown once at the bottom of the whole
         document. --}}
    @if($pricesOn)
    <tr class="total-row">
        @if($registered)
            <td colspan="4">Subtotal</td>
            <td>R{{ number_format((float) $groupExcl, 2) }}</td>
            <td>R{{ number_format((float) $groupVat, 2) }}</td>
        @else
            <td colspan="4">Subtotal</td>
            <td>R{{ number_format((float) $lines->sum('line_total'), 2) }}</td>
        @endif
    </tr>
    @endif
    </tbody>
</table>
@endif
