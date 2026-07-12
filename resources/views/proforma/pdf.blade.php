@php
    /** @var \App\Models\Proforma\ProformaInvoice $invoice */
    /** @var \App\Models\Agency $agency */
    /** @var \App\Models\Proforma\AgencyProformaSettings $settings */
    $money = fn ($v) => 'R ' . number_format((float) ($v ?? 0), 2, '.', ',');
    $vat   = (bool) $invoice->vat_registered;
    $voided = $invoice->status === 'voided';
    $company = $agency->trading_name ?: ($agency->name ?? 'Home Finders Coastal');
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    .b { font-weight: bold; }
    .title { font-size: 22px; font-weight: bold; letter-spacing: 2px; color: #0b2a4a; }
    .panel td { padding: 3px 8px; vertical-align: top; }
    .party td { padding: 2px 0; }
    .items th { background: #0b2a4a; color: #fff; padding: 7px 8px; text-align: left; font-size: 10px; letter-spacing: .5px; }
    .items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    .totals td { padding: 4px 8px; }
    .grand td { border-top: 2px solid #0b2a4a; font-weight: bold; font-size: 13px; }
    .notes { border: 1px solid #e5e7eb; padding: 8px 10px; background: #f9fafb; font-size: 10px; }
    .void-mark { position: fixed; top: 42%; left: 12%; font-size: 90px; color: rgba(220,38,38,.14);
                 font-weight: bold; letter-spacing: 8px; transform: rotate(-24deg); }
</style>
</head>
<body>
    @if($voided)<div class="void-mark">VOID</div>@endif

    {{-- ── Header: letterhead ── --}}
    <table>
        <tr>
            <td style="width: 62%; vertical-align: top;">
                @if(!empty($logoData))
                    <img src="{{ $logoData }}" alt="" style="max-height: 58px; max-width: 240px;"><br>
                @endif
                <span class="b" style="font-size: 14px; color: #0b2a4a;">{{ $company }}</span><br>
                <span class="muted">
                    {{ $agency->address }}<br>
                    @if($agency->phone){{ $agency->phone_label ?: 'Tel' }}: {{ $agency->phone }} &nbsp;@endif
                    @if($agency->email){{ $agency->email }}@endif<br>
                    @if($agency->reg_no)Reg: {{ $agency->reg_no }} &nbsp;@endif
                    @if($vat && $agency->vat_no)VAT: {{ $agency->vat_no }} &nbsp;@endif
                    @if($agency->ppra_number)PPRA: {{ $agency->ppra_number }}@endif
                </span>
            </td>
            <td style="width: 38%; text-align: right; vertical-align: top;">
                <div class="title">PROFORMA</div>
                <div class="b" style="font-size: 13px;">{{ $invoice->number }}</div>
                @if($voided)<div class="b" style="color:#dc2626;">VOIDED</div>@endif
            </td>
        </tr>
    </table>

    <hr style="border: none; border-top: 2px solid #0b2a4a; margin: 10px 0 12px;">

    {{-- ── Number / date / reference / due panel ── --}}
    <table class="panel" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 50%;">
                <span class="muted">Invoice No</span><br><span class="b">{{ $invoice->number }}</span>
            </td>
            <td style="width: 50%;">
                <span class="muted">Date</span><br><span class="b">{{ $invoice->created_at?->format('d M Y') }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="muted">Reference</span><br><span class="b">{{ $invoice->reference }}</span>
            </td>
            <td>
                <span class="muted">Due</span><br><span class="b">{{ $invoice->due_date?->format('d M Y') }}</span>
            </td>
        </tr>
    </table>

    {{-- ── Made out to: seller c/o transferring attorney ── --}}
    <table class="party" style="margin-bottom: 14px;">
        <tr><td class="muted" style="width: 90px;">Invoice to</td><td class="b">{{ $invoice->issued_to_name }}</td></tr>
        @if($invoice->care_of_name)
            <tr><td class="muted">c/o</td><td>{{ $invoice->care_of_name }} <span class="muted">(Transferring Attorney)</span></td></tr>
        @endif
    </table>

    {{-- ── Line table ── --}}
    <table class="items" style="margin-bottom: 4px;">
        <thead>
            <tr>
                <th>Description</th>
                @if($vat)
                    <th class="right" style="width: 90px;">Excl VAT</th>
                    <th class="right" style="width: 80px;">VAT</th>
                    <th class="right" style="width: 95px;">Incl VAT</th>
                @else
                    <th class="right" style="width: 120px;">Amount</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    @if($vat)
                        <td class="right">{{ $money($line->amount_excl) }}</td>
                        <td class="right">{{ $money($line->vat_amount) }}</td>
                        <td class="right">{{ $money($line->amount_incl) }}</td>
                    @else
                        <td class="right">{{ $money($line->amount_incl) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── Totals ── --}}
    <table style="margin-bottom: 16px;">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%;">
                <table class="totals">
                    @if($vat)
                        <tr><td class="muted">Subtotal (excl)</td><td class="right">{{ $money($invoice->subtotal_excl) }}</td></tr>
                        <tr><td class="muted">VAT @ {{ rtrim(rtrim(number_format((float)$invoice->vat_rate,2),'0'),'.') }}%</td><td class="right">{{ $money($invoice->vat_amount) }}</td></tr>
                    @endif
                    <tr class="grand"><td>Total {{ $vat ? '(incl VAT)' : '' }}</td><td class="right">{{ $money($invoice->total_incl) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Notes = bank details ── --}}
    @if(!empty($settings->bank_details))
        <div class="notes">
            <span class="b">Banking details</span><br>
            {!! nl2br(e($settings->bank_details)) !!}
        </div>
    @endif

    <p class="muted" style="margin-top: 14px; font-size: 9px;">
        This is a proforma invoice — not a tax invoice. {{ $company }}.
        @unless($vat) {{ $company }} is not a VAT vendor; no VAT is charged. @endunless
    </p>
</body>
</html>
