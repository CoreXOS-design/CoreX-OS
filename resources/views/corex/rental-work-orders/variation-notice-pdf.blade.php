<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('corex.rental-work-orders.pdf._style')
</head>
<body>
    @include('corex.rental-work-orders.pdf._header', ['docTitle' => 'Variation notice', 'docMeta' => 'Work order #' . $workOrder->id . ' · ' . now()->format('Y-m-d') . ($variation->revision > 1 ? ' · Revision ' . $variation->revision : '')])

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $workOrder->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Job</td><td>{{ $workOrder->title }}</td></tr>
            @if($vatNumber)<tr><td class="label">VAT No</td><td>{{ $vatNumber }}</td></tr>@endif
        </table>
    </div>

    <h2>What you approved</h2>
    <div class="box">
        <table>
            @if($original)
                <tr><td class="label">Original quote</td><td>R{{ number_format($original['amount'], 2) }}@if($original['revision'] > 1) (Rev {{ $original['revision'] }})@endif · {{ $original['date'] }}</td></tr>
            @endif
            <tr><td class="label">Approved amount</td><td><strong>R{{ number_format((float) $variation->baseline_amount, 2) }}</strong>{{ $vatRegistered ? ' incl. VAT' : '' }}</td></tr>
        </table>
    </div>

    <h2>The extra work</h2>
    @if($lines->isNotEmpty())
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
                @if($line->crew_note)
                    <tr><td colspan="4" class="muted note">Note from the crew: {{ $line->crew_note }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endif
    @if($lines->isEmpty() && $variation->origin === 'external_quote')
        <p>The contractor has sent a revised quote, which is R{{ number_format((float) $variation->extra_amount, 2) }} more than the quote you approved.</p>
    @endif
    @if((float) $variation->price_change_amount > 0 && $variation->origin !== 'external_quote')
        <p class="muted">Also: the price of work already approved changed by R{{ number_format((float) $variation->price_change_amount, 2) }}.</p>
    @endif

    @if(count($photos))
        <div class="photos" style="margin-top:8px;">
            @foreach($photos as $photo)<img src="{{ $photo }}" alt="">@endforeach
        </div>
    @endif

    <h2>New total</h2>
    <div class="box">
        <table>
            <tr><td class="label">Extra work</td><td>R{{ number_format((float) $variation->extra_amount, 2) }}</td></tr>
            <tr><td class="label"><strong>New total</strong></td><td><strong>R{{ number_format((float) $variation->new_total, 2) }}</strong>{{ $vatRegistered ? ' incl. VAT' : '' }}</td></tr>
        </table>
    </div>

    <h2>How to answer</h2>
    <p>Please approve or decline the extra work in your portal, or reply to the email this came with and the office will record your answer. The rest of the job carries on as approved; the extra work waits for your answer.</p>

    @if(trim((string) $variation->term_text) !== '')<div class="term">{{ $variation->term_text }}</div>@endif
</body>
</html>
