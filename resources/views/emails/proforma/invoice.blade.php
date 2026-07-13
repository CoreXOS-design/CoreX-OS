@php $money = fn ($v) => 'R ' . number_format((float) ($v ?? 0), 2, '.', ','); @endphp
<p>Good day,</p>

<p>Please find attached proforma invoice <strong>{{ $invoice->number }}</strong> from {{ $agencyName }}.</p>

<table cellpadding="4" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px;">
    <tr><td style="color:#6b7280;">Reference</td><td><strong>{{ $invoice->reference }}</strong></td></tr>
    <tr><td style="color:#6b7280;">Amount due</td><td><strong>{{ $money($invoice->total_incl) }}</strong></td></tr>
    <tr><td style="color:#6b7280;">Due date</td><td>{{ $invoice->due_date?->format('d M Y') }}</td></tr>
</table>

<p style="color:#6b7280; font-size:12px;">This is a proforma invoice — not a tax invoice.</p>

<p>Kind regards,<br>{{ $agencyName }}</p>
