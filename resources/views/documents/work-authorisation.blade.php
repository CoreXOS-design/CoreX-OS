{{-- AT-229 DR2 W3 — work-authorisation (HFC "COC request" form), dompdf-friendly:
     table layout, inline CSS, no web fonts / flexbox / grid. Every value auto-filled
     from the deal ($fields), all editable by the agent before send. --}}
@php $f = $fields; $ink = '#0b2a4a'; $line = '#c9ced6'; $soft = '#6b7280'; @endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: "DejaVu Sans", sans-serif; box-sizing: border-box; }
    body { color: #1f2937; font-size: 11px; line-height: 1.5; margin: 0; }
    @page { margin: 15mm 15mm; }
    .w100 { width: 100%; border-collapse: collapse; }
    .muted { color: {{ $soft }}; }
    .b { font-weight: bold; }
    .ink { color: {{ $ink }}; }
    .title { font-size: 19px; font-weight: bold; letter-spacing: .5px; color: {{ $ink }}; }
    .svc { font-size: 12px; font-weight: bold; color: {{ $ink }}; margin-top: 2px; }
    .panel { border: 1px solid {{ $line }}; margin-bottom: 10px; }
    .panel-head { background: {{ $ink }}; color: #fff; font-weight: bold; font-size: 9px;
                  letter-spacing: .6px; text-transform: uppercase; padding: 4px 9px; }
    .panel-body { padding: 7px 9px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 2px 0; vertical-align: top; }
    table.kv td.k { color: {{ $soft }}; width: 26%; white-space: nowrap; padding-right: 10px; }
    table.kv td.v { color: #111; }
    .notes { border-left: 3px solid {{ $ink }}; background: #f8fafc; padding: 8px 11px; }
    .foot { margin-top: 20px; font-size: 9px; color: {{ $soft }}; border-top: 1px solid #e2e8f0; padding-top: 7px; }
    .hr { height: 8px; }
</style>
</head>
<body>

    {{-- ── HFC letterhead (company-header) ── --}}
    <table class="w100">
        <tr>
            <td style="vertical-align: top; width: 62%;">
                @if($logoData)<img src="{{ $logoData }}" alt="" style="max-height: 52px; max-width: 220px;"><br>@endif
                <span class="b ink" style="font-size: 13px;">{{ $company }}</span><br>
                <span class="muted">
                    @if($agency && $agency->address){{ $agency->address }}<br>@endif
                    @if($agency && $agency->phone){{ $agency->phone_label ?: 'Tel' }}: {{ $agency->phone }}@endif
                    @if($agency && $agency->email) &nbsp;·&nbsp; {{ $agency->email }}@endif
                    @if($agency && $agency->ppra_number)<br>PPRA: {{ $agency->ppra_number }}@endif
                </span>
            </td>
            <td style="vertical-align: top; width: 38%; text-align: right;">
                <div class="title">WORK ORDER</div>
                <div class="svc">{{ $serviceLabel }}</div>
                <div class="muted" style="margin-top:6px;">Date: <span class="b" style="color:#111;">{{ $f['date'] ?? now()->format('d F Y') }}</span></div>
                <div class="muted">Ref: <span class="b" style="color:#111;">{{ $deal->reference }}</span></div>
            </td>
        </tr>
    </table>
    <div class="hr"></div>

    {{-- Addressed-to supplier (present once a supplier is picked at send) --}}
    @if($providerName || $providerCompany || $providerEmail)
    <div class="panel">
        <div class="panel-head">To — Service Provider</div>
        <div class="panel-body"><table class="kv">
            <tr><td class="k">Provider</td><td class="v">{{ $providerName ?: '—' }}</td></tr>
            @if($providerCompany)<tr><td class="k">Company</td><td class="v">{{ $providerCompany }}</td></tr>@endif
            @if($providerEmail)<tr><td class="k">Email</td><td class="v">{{ $providerEmail }}</td></tr>@endif
        </table></div>
    </div>
    @endif

    {{-- Property --}}
    <div class="panel">
        <div class="panel-head">Property</div>
        <div class="panel-body"><table class="kv">
            <tr><td class="k">Address</td><td class="v b">{{ $f['property_address'] ?: '—' }}</td></tr>
        </table></div>
    </div>

    {{-- Parties --}}
    <div class="panel">
        <div class="panel-head">Parties</div>
        <div class="panel-body"><table class="kv">
            <tr><td class="k">Seller</td><td class="v">{{ $f['seller_name'] ?: '—' }}</td></tr>
            <tr><td class="k">Seller email</td><td class="v">{{ $f['seller_email'] ?: '—' }}</td></tr>
            <tr><td class="k">Seller tel</td><td class="v">{{ $f['seller_tel'] ?: '—' }}</td></tr>
            <tr><td class="k">Purchaser</td><td class="v">{{ $f['purchaser_name'] ?: '—' }}</td></tr>
            <tr><td class="k">Purchaser tel</td><td class="v">{{ $f['purchaser_tel'] ?: '—' }}</td></tr>
            <tr><td class="k">Attorneys</td><td class="v">{{ $f['attorneys'] ?: '—' }}</td></tr>
        </table></div>
    </div>

    {{-- Representative (agent) + keys --}}
    <div class="panel">
        <div class="panel-head">Representative &amp; Keys</div>
        <div class="panel-body"><table class="kv">
            <tr><td class="k">Representative</td><td class="v">{{ $f['rep_name'] ?: '—' }}</td></tr>
            <tr><td class="k">Rep email</td><td class="v">{{ $f['rep_email'] ?: '—' }}</td></tr>
            <tr><td class="k">Rep tel</td><td class="v">{{ $f['rep_tel'] ?: '—' }}</td></tr>
            <tr><td class="k">Keys held by</td><td class="v">{{ $f['keys_name'] ?: '—' }}</td></tr>
            <tr><td class="k">Keys tel</td><td class="v">{{ $f['keys_tel'] ?: '—' }}</td></tr>
        </table></div>
    </div>

    {{-- Invoice payer --}}
    <div class="panel">
        <div class="panel-head">Paying for the Invoice</div>
        <div class="panel-body">{!! nl2br(e($f['payer'] ?: '—')) !!}</div>
    </div>

    {{-- Notes --}}
    <div class="panel-head" style="border:1px solid {{ $line }}; border-bottom:none;">Notes</div>
    <div class="notes">{!! nl2br(e($f['notes'] ?? '')) !!}</div>

    <div class="foot">
        Generated by {{ $company }} via CoreX for deal {{ $deal->reference }}. This work order authorises
        the above service provider to attend the property and issue the certificate; return the original
        certificate and your invoice to the agency per the notes above.
    </div>

</body>
</html>
