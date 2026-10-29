@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ' ');
    $reg = $invoice->registration;
    $buyerName = $reg?->displayName();
    $lot = $invoice->lot;
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 12px; margin: 18px 0 6px; text-transform: uppercase; color: #6b7280; }
    table { border-collapse: collapse; width: 100%; } td, th { padding: 6px 8px; vertical-align: top; }
    .muted { color: #6b7280; } .right { text-align: right; } .line td { border-top: 1px solid #e5e7eb; }
    .total td { border-top: 2px solid #111827; font-weight: bold; font-size: 12px; }
    .box { border: 1px solid #e5e7eb; padding: 8px 10px; }
    .draft { color: #b45309; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%;">
            @if(!empty($logoData))<img src="{{ $logoData }}" alt="" style="max-height:52px;max-width:210px;"><br>@endif
            <strong style="font-size:13px;">{{ \App\Models\Agency::displayNameFor($agency) }}</strong><br>
            <span class="muted">{!! nl2br(e((string) $agency?->address)) !!}</span>
            @if($agency?->vat_no)<br><span class="muted">VAT no. {{ $agency->vat_no }}</span>@endif
            @if($agency?->email)<br><span class="muted">{{ $agency->email }}@if($agency->phone) · {{ $agency->phone }}@endif</span>@endif
        </td>
        <td class="right">
            <h1>INVOICE</h1>
            @if($invoice->number)<strong>{{ $invoice->number }}</strong>@else<span class="draft">DRAFT</span>@endif<br>
            <span class="muted">{{ ($invoice->issued_at ?? $invoice->created_at)->format('j F Y') }}</span>
            @if($invoice->status === 'cancelled')<br><span class="draft">CANCELLED</span>@endif
        </td>
    </tr>
</table>

<h2>Bill to</h2>
<div class="box">
    <strong>{{ $buyerName }}</strong>
    @if($reg?->bidder_type === 'company' && $reg->company_reg_no)<br>Reg. {{ $reg->company_reg_no }}@endif
    @if($reg?->clientUser?->email)<br>{{ $reg->clientUser->email }}@endif
    @if($reg?->contact?->phone)<br>{{ $reg->contact->phone }}@endif
    <br><span class="muted">Bidder no. {{ $reg?->bidder_number }}</span>
</div>

<h2>The sale</h2>
<table class="line">
    <tr><td class="muted" style="width:30%;">Auction</td><td>{{ $invoice->auction?->title }} ({{ $invoice->auction?->reference }})</td></tr>
    <tr><td class="muted">Lot</td><td>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</td></tr>
    @if($lot?->property)<tr><td class="muted">Property</td><td>{{ $lot->property->buildDisplayAddress() }}</td></tr>@endif
</table>

<h2>Amount</h2>
<table>
    <tr class="line"><td>Purchase price (hammer price)</td><td class="right">{{ $money($invoice->hammer_price) }}</td></tr>
    @if((float) $invoice->commission_amount > 0)
    <tr class="line"><td>Buyer's commission @ {{ $pct($invoice->commission_percent) }}%</td><td class="right">{{ $money($invoice->commission_amount) }}</td></tr>
    <tr class="line"><td>VAT @ {{ $pct($invoice->vat_rate) }}% on the commission</td><td class="right">{{ $money($invoice->vat_amount) }}</td></tr>
    @endif
    <tr class="total"><td>Total payable</td><td class="right">{{ $money($invoice->total_amount) }}</td></tr>
</table>

<h2>How to pay</h2>
<table class="line">
    @if((float) $invoice->deposit_amount > 0)
    <tr><td style="width:30%;">Deposit ({{ $pct($invoice->deposit_percent) }}% of the price)</td><td>{{ $money($invoice->deposit_amount) }}, due by <strong>{{ $invoice->deposit_due_at?->format('j F Y') }}</strong></td></tr>
    @endif
    <tr><td>Balance</td><td>{{ $money($invoice->balance_amount) }}, due by <strong>{{ $invoice->balance_due_at?->format('j F Y') }}</strong></td></tr>
    @if($invoice->payments->isNotEmpty())
    <tr><td>Received so far</td><td>{{ $money($invoice->paidTotal()) }} — still owing {{ $money($invoice->outstanding()) }}</td></tr>
    @endif
</table>
@if(trim((string) $invoice->payment_details) !== '')
<h2>Payment details</h2>
<div class="box">{!! nl2br(e($invoice->payment_details)) !!}<br><span class="muted">Please use {{ $invoice->number ?: 'the invoice number' }} as your payment reference.</span></div>
@endif
@if(trim((string) $invoice->notes) !== '')<h2>Notes</h2><div>{!! nl2br(e($invoice->notes)) !!}</div>@endif
</body>
</html>
