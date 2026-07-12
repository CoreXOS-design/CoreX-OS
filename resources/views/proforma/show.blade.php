@extends('layouts.corex')
@section('title', 'Proforma ' . $invoice->number)

@section('corex-content')
@php $money = fn ($v) => 'R ' . number_format((float) ($v ?? 0), 2, '.', ','); $vat = (bool) $invoice->vat_registered; @endphp
<div style="max-width: 820px; margin: 0 auto; padding: 1rem;">

    @if(session('success'))<div class="corex-alert corex-alert-success" style="margin:1rem 0;">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>@endif

    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
        <div>
            <h1 style="font-size:1.4rem;font-weight:800;color:var(--brand-default,#0b2a4a);">
                Proforma {{ $invoice->number }}
                @if($invoice->isVoided())<span class="corex-badge" style="background:#dc2626;color:#fff;">VOID</span>@endif
            </h1>
            <div style="color:var(--text-muted,#6b7280);font-size:.9rem;">{{ $invoice->reference }}</div>
        </div>
        <div style="display:flex;gap:.5rem;">
            <a href="{{ route('proforma.download', $invoice) }}" target="_blank" class="corex-btn-primary">Download PDF</a>
            <a href="{{ route('deals-dr2.pipeline', $invoice->deal_id) }}" class="corex-btn-secondary">← Deal</a>
        </div>
    </div>

    <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
        <table style="width:100%;font-size:.9rem;">
            <tr><td style="color:#6b7280;width:140px;">Invoice to</td><td><strong>{{ $invoice->issued_to_name }}</strong></td></tr>
            @if($invoice->care_of_name)<tr><td style="color:#6b7280;">c/o (attorney)</td><td>{{ $invoice->care_of_name }}</td></tr>@endif
            <tr><td style="color:#6b7280;">Date</td><td>{{ $invoice->created_at?->format('d M Y') }}</td></tr>
            <tr><td style="color:#6b7280;">Due</td><td>{{ $invoice->due_date?->format('d M Y') }}</td></tr>
            <tr><td style="color:#6b7280;">VAT</td><td>{{ $vat ? 'Registered ('.rtrim(rtrim(number_format((float)$invoice->vat_rate,2),'0'),'.').'%)' : 'Not registered — no VAT' }}</td></tr>
        </table>
    </div>

    <table class="corex-table" style="width:100%;margin-bottom:1rem;">
        <thead><tr>
            <th style="text-align:left;">Description</th>
            @if($vat)<th style="text-align:right;">Excl</th><th style="text-align:right;">VAT</th>@endif
            <th style="text-align:right;">{{ $vat ? 'Incl' : 'Amount' }}</th>
            @permission('proforma.manage')<th></th>@endpermission
        </tr></thead>
        <tbody>
            @foreach($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }} @if($line->is_locked)<span style="color:#9ca3af;font-size:.75rem;">🔒 from deal</span>@endif</td>
                @if($vat)<td style="text-align:right;">{{ $money($line->amount_excl) }}</td><td style="text-align:right;">{{ $money($line->vat_amount) }}</td>@endif
                <td style="text-align:right;">{{ $money($line->amount_incl) }}</td>
                @permission('proforma.manage')
                <td style="text-align:right;">
                    @unless($line->is_locked)
                    <form method="POST" action="{{ route('proforma.lines.remove', [$invoice, $line]) }}" onsubmit="return confirm('Remove this line?')" style="display:inline;">
                        @csrf @method('DELETE')
                        <button class="corex-btn-outline" style="padding:.1rem .5rem;font-size:.75rem;">Remove</button>
                    </form>
                    @endunless
                </td>
                @endpermission
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            @if($vat)<tr><td style="text-align:right;color:#6b7280;" colspan="{{ 3 }}">Subtotal excl</td><td style="text-align:right;">{{ $money($invoice->subtotal_excl) }}</td>@permission('proforma.manage')<td></td>@endpermission</tr>
            <tr><td style="text-align:right;color:#6b7280;" colspan="3">VAT</td><td style="text-align:right;">{{ $money($invoice->vat_amount) }}</td>@permission('proforma.manage')<td></td>@endpermission</tr>@endif
            <tr><td style="text-align:right;font-weight:800;" colspan="{{ $vat ? 3 : 1 }}">Total {{ $vat ? 'incl' : '' }}</td><td style="text-align:right;font-weight:800;">{{ $money($invoice->total_incl) }}</td>@permission('proforma.manage')<td></td>@endpermission</tr>
        </tfoot>
    </table>

    {{-- ── ADMIN-ONLY overrides ── --}}
    @permission('proforma.manage')
    @unless($invoice->isVoided())
    <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
        <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.6rem;">Admin — adjust</h3>
        <form method="POST" action="{{ route('proforma.lines.add', $invoice) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.6rem;">
            @csrf
            <input name="description" placeholder="e.g. Discount on commission" required class="corex-input" style="flex:1 1 260px;">
            <input name="amount_excl" type="number" step="0.01" placeholder="Amount excl (− for discount)" required class="corex-input" style="flex:0 1 200px;">
            <button class="corex-btn-secondary">Add line</button>
        </form>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <form method="POST" action="{{ route('proforma.regenerate', $invoice) }}">@csrf<button class="corex-btn-outline">Regenerate PDF</button></form>
            <form method="POST" action="{{ route('proforma.void', $invoice) }}" onsubmit="return confirm('Void this proforma? The record is kept; the number is never reused.')" style="display:flex;gap:.4rem;">
                @csrf
                <input name="void_reason" placeholder="Void reason" required class="corex-input" style="flex:1 1 200px;">
                <button class="corex-btn-outline" style="color:#dc2626;border-color:#dc2626;">Void</button>
            </form>
        </div>
    </div>
    @endunless
    @endpermission

</div>
@endsection
