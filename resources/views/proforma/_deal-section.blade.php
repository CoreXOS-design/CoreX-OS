{{--
    Proforma Invoices section for the DR2 deal view.
    @include('proforma._deal-section', ['deal' => $deal])
    View-model computed inline (mirrors dr2/_deal-documents). Generation is granted-onward
    only; the button hides when the deal is not eligible, and the endpoint re-checks.
--}}
@php
    $proformas = \App\Models\Proforma\ProformaInvoice::query()
        ->where('deal_id', $deal->id)
        ->latest('id')->get();
    $eligible = app(\App\Services\Proforma\ProformaFinancialResolver::class)->isEligible($deal);
    $money = fn ($v) => 'R ' . number_format((float) ($v ?? 0), 2, '.', ',');
@endphp

<div class="corex-card" style="padding:1rem;margin-top:1rem;" data-tour="deal-proforma">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:.6rem;">
        <h3 style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);">Proforma Invoices</h3>
        @permission('proforma.generate')
            @if($eligible)
                <form method="POST" action="{{ route('deals-dr2.proforma.generate', $deal) }}">@csrf
                    <button type="submit" class="corex-btn-primary" style="font-size:.8rem;padding:.4rem .9rem;">Generate Proforma Invoice</button>
                </form>
            @else
                <span style="font-size:.78rem;color:#9ca3af;">Available once the deal is Granted</span>
            @endif
        @endpermission
    </div>

    @if($proformas->isEmpty())
        <p style="font-size:.85rem;color:var(--text-muted,#9ca3af);">No proforma invoices yet.</p>
    @else
        <div style="display:flex;flex-direction:column;gap:.4rem;">
            @foreach($proformas as $p)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.5rem .65rem;border:1px solid var(--border,rgba(0,0,0,.08));border-radius:8px;">
                <div>
                    <a href="{{ route('proforma.show', $p) }}" style="font-size:.85rem;font-weight:600;color:var(--brand-default,#0b2a4a);">{{ $p->number }}</a>
                    @if($p->status === 'voided')<span class="corex-badge" style="background:#dc2626;color:#fff;font-size:.7rem;">VOID</span>@endif
                    <div style="font-size:.72rem;color:#9ca3af;">{{ $p->issued_to_name }} · {{ $p->created_at?->format('d M Y') }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:.85rem;font-weight:700;">{{ $money($p->total_incl) }}</div>
                    <a href="{{ route('proforma.download', $p) }}" target="_blank" style="font-size:.72rem;">PDF</a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
