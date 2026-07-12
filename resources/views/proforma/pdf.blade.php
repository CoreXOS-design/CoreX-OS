@php
    /** @var \App\Models\Proforma\ProformaInvoice $invoice */
    /** @var \App\Models\Agency $agency */
    /** @var \App\Models\Proforma\AgencyProformaSettings $settings */
    $money = fn ($v) => number_format((float) ($v ?? 0), 2, '.', ',');
    $vat    = (bool) $invoice->vat_registered;
    $voided = $invoice->status === 'voided';
    $company = $agency->trading_name ?: ($agency->name ?? 'Home Finders Coastal');
    // Optional Customer VAT No — layout-ready; hidden unless captured on the record.
    $customerVat = $invoice->customer_vat_no ?? null;
    $ink = '#0b2a4a'; $line = '#c9ced6'; $soft = '#6b7280';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 16mm 15mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
    table { border-collapse: collapse; }
    .w100 { width: 100%; }
    .muted { color: {{ $soft }}; }
    .right { text-align: right; }
    .b { font-weight: bold; }
    .ink { color: {{ $ink }}; }
    .title { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: {{ $ink }}; }

    /* Pastel-style bordered panels */
    .panel { border: 1px solid {{ $line }}; }
    .panel-head { background: {{ $ink }}; color: #fff; font-weight: bold; font-size: 9.5px;
                  letter-spacing: .6px; text-transform: uppercase; padding: 5px 9px; }
    .panel-body { padding: 8px 9px; }
    .kv td { padding: 2px 0; font-size: 10.5px; }
    .kv td.k { color: {{ $soft }}; padding-right: 12px; white-space: nowrap; }

    .items th { background: {{ $ink }}; color: #fff; padding: 7px 9px; text-align: left;
                font-size: 9.5px; letter-spacing: .4px; text-transform: uppercase; }
    .items td { padding: 7px 9px; border-bottom: 1px solid #e9ecf1; }
    .items tr:last-child td { border-bottom: 1px solid {{ $line }}; }

    .tot td { padding: 4px 9px; }
    .tot .grand td { border-top: 2px solid {{ $ink }}; font-weight: bold; font-size: 12.5px; color: {{ $ink }}; }

    .void-mark { position: fixed; top: 44%; left: 14%; font-size: 92px; color: rgba(220,38,38,.13);
                 font-weight: bold; letter-spacing: 8px; transform: rotate(-24deg); }
</style>
</head>
<body>
    @if($voided)<div class="void-mark">VOID</div>@endif

    {{-- ── Header: letterhead (left) + title (right) ── --}}
    <table class="w100">
        <tr>
            <td style="vertical-align: top; width: 60%;">
                @if(!empty($logoData))<img src="{{ $logoData }}" alt="" style="max-height: 56px; max-width: 230px;"><br>@endif
                <span class="b ink" style="font-size: 13px;">{{ $company }}</span><br>
                <span class="muted" style="line-height: 1.5;">
                    {{ $agency->address }}<br>
                    @if($agency->phone){{ $agency->phone_label ?: 'Tel' }}: {{ $agency->phone }}@endif
                    @if($agency->email) &nbsp;·&nbsp; {{ $agency->email }}@endif<br>
                    @if($agency->reg_no)Reg No: {{ $agency->reg_no }}@endif
                    @if($vat && $agency->vat_no) &nbsp;·&nbsp; VAT No: {{ $agency->vat_no }}@endif
                    @if($agency->ppra_number)<br>PPRA: {{ $agency->ppra_number }}@endif
                </span>
            </td>
            <td style="vertical-align: top; width: 40%; text-align: right;">
                <div class="title">PRO FORMA{{ $vat ? '' : ' INVOICE' }}</div>
                @if($vat)<div class="ink b" style="font-size: 11px; letter-spacing:1px;">INVOICE</div>@endif
                @if($voided)<div class="b" style="color:#dc2626; margin-top:2px;">VOIDED</div>@endif
            </td>
        </tr>
    </table>

    {{-- ── Meta panel (right-aligned label/value block, bordered) ── --}}
    <table class="w100" style="margin-top: 12px;">
        <tr>
            <td style="width: 55%;"></td>
            <td style="width: 45%; vertical-align: top;">
                <div class="panel">
                    <div class="panel-head">Invoice details</div>
                    <div class="panel-body">
                        <table class="w100 kv">
                            <tr><td class="k">Invoice No</td><td class="right b ink">{{ $invoice->number }}</td></tr>
                            <tr><td class="k">Date</td><td class="right">{{ $invoice->created_at?->format('d M Y') }}</td></tr>
                            <tr><td class="k">Due date</td><td class="right b">{{ $invoice->due_date?->format('d M Y') }}</td></tr>
                            <tr><td class="k">Reference</td><td class="right">{{ $invoice->reference }}</td></tr>
                        </table>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Invoice-to block (bordered) ── --}}
    <table style="margin-top: 12px; width: 55%;">
        <tr><td>
            <div class="panel">
                <div class="panel-head">Invoice to</div>
                <div class="panel-body">
                    <span class="b" style="font-size: 11.5px;">{{ $invoice->issued_to_name }}</span><br>
                    @if($invoice->care_of_name)<span class="muted">c/o {{ $invoice->care_of_name }} (Transferring Attorney)</span><br>@endif
                    @if($customerVat)<span class="muted">Customer VAT No: {{ $customerVat }}</span>@endif
                </div>
            </div>
        </td></tr>
    </table>

    {{-- ── Line items ── --}}
    <table class="w100 items" style="margin-top: 14px;">
        <thead>
            <tr>
                <th>Description</th>
                @if($vat)
                    <th class="right" style="width: 95px;">Excl VAT</th>
                    <th class="right" style="width: 80px;">VAT</th>
                    <th class="right" style="width: 100px;">Incl VAT</th>
                @else
                    <th class="right" style="width: 130px;">Amount (R)</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $l)
                <tr>
                    <td>{{ $l->description }}</td>
                    @if($vat)
                        <td class="right">{{ $money($l->amount_excl) }}</td>
                        <td class="right">{{ $money($l->vat_amount) }}</td>
                        <td class="right">{{ $money($l->amount_incl) }}</td>
                    @else
                        <td class="right">{{ $money($l->amount_incl) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── Totals (right, bordered top) ── --}}
    <table class="w100" style="margin-top: 6px;">
        <tr>
            <td style="width: 58%;"></td>
            <td style="width: 42%;">
                <table class="w100 tot">
                    @if($vat)
                        <tr><td class="muted">Subtotal (excl)</td><td class="right">R {{ $money($invoice->subtotal_excl) }}</td></tr>
                        <tr><td class="muted">VAT @ {{ rtrim(rtrim(number_format((float)$invoice->vat_rate,2),'0'),'.') }}%</td><td class="right">R {{ $money($invoice->vat_amount) }}</td></tr>
                    @endif
                    <tr class="grand"><td>Total {{ $vat ? 'incl VAT' : 'due' }}</td><td class="right">R {{ $money($invoice->total_incl) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Banking box (filled = bordered block; empty = hidden) ── --}}
    @if(!empty($settings->bank_details))
        <table style="margin-top: 16px; width: 60%;">
            <tr><td>
                <div class="panel">
                    <div class="panel-head">Banking details</div>
                    <div class="panel-body muted" style="line-height: 1.6;">{!! nl2br(e($settings->bank_details)) !!}</div>
                </div>
            </td></tr>
        </table>
    @endif

    <p class="muted" style="margin-top: 16px; font-size: 9px;">
        This is a pro forma invoice — not a tax invoice.
        @unless($vat) {{ $company }} is not a VAT vendor; no VAT is charged. @endunless
    </p>
</body>
</html>
