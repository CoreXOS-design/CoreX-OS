@extends('layouts.corex')

@section('corex-content')
@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ' ');
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $canManage = auth()->user()->hasPermission('auctions.invoices.manage');
    $reg = $invoice->registration;
    $open = in_array($invoice->status, ['issued', 'part_paid'], true);
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-5xl">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold" style="color:var(--text-primary);">Invoice {{ $invoice->number ?: '(draft)' }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">{{ $invoice->auction?->reference }} · Lot {{ $invoice->lot?->lot_number }} · Status: <strong>{{ $invoice->statusLabel() }}</strong>@if($invoice->isOverdue()) <strong style="color:#ef4444;">· overdue</strong>@endif</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('corex.auctions.invoices.pdf', $invoice->id) }}" target="_blank" class="corex-btn-outline text-xs px-3 py-2" data-invoice-pdf>View PDF</a>
            <a href="{{ route('corex.auctions.invoices.index') }}" class="corex-btn-outline text-xs px-3 py-2">← All invoices</a>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="rounded-md p-5 text-sm space-y-1" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Bidder</h2>
            <div><strong>#{{ $reg?->bidder_number }} {{ $reg?->displayName() }}</strong></div>
            <div style="color:var(--text-muted);">{{ $reg?->clientUser?->email }} @if($reg?->contact?->phone)· {{ $reg->contact->phone }}@endif</div>
            @if($reg)<a href="{{ route('corex.auctions.bidders.show', $reg->id) }}" class="text-xs underline">Open the bidder</a>@endif
            <h2 class="text-sm font-bold pt-3" style="color:var(--text-primary);">The sale</h2>
            <div>Lot {{ $invoice->lot?->lot_number }} — {{ $invoice->lot?->displayTitle() }}</div>
            <div class="text-xs" style="color:var(--text-muted);">{{ $invoice->lot?->property?->buildDisplayAddress() }}</div>
            <a href="{{ route('corex.auctions.lots.edit', $invoice->lot_id) }}" class="text-xs underline">Open the lot</a>
            @if($invoice->deal_id)<span class="text-xs"> · <a href="{{ route('deals-dr2.index') }}" class="underline">Deal Register</a></span>@endif
        </div>

        <div class="rounded-md p-5 text-sm" style="background:var(--surface);border:1px solid var(--border);" data-invoice-sum>
            <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Amount</h2>
            <table class="w-full">
                <tr><td>Purchase price</td><td class="text-right">{{ $money($invoice->hammer_price) }}</td></tr>
                @if((float) $invoice->commission_amount > 0)
                <tr><td>Buyer's commission @ {{ $pct($invoice->commission_percent) }}%</td><td class="text-right">{{ $money($invoice->commission_amount) }}</td></tr>
                <tr><td>VAT @ {{ $pct($invoice->vat_rate) }}%</td><td class="text-right">{{ $money($invoice->vat_amount) }}</td></tr>
                @endif
                <tr style="border-top:2px solid var(--border);font-weight:700;"><td>Total payable</td><td class="text-right">{{ $money($invoice->total_amount) }}</td></tr>
                <tr><td style="color:var(--text-muted);">Deposit ({{ $pct($invoice->deposit_percent) }}%) due {{ $invoice->deposit_due_at?->format('d M Y') }}</td><td class="text-right">{{ $money($invoice->deposit_amount) }}</td></tr>
                <tr><td style="color:var(--text-muted);">Balance due {{ $invoice->balance_due_at?->format('d M Y') }}</td><td class="text-right">{{ $money($invoice->balance_amount) }}</td></tr>
                <tr><td style="color:var(--text-muted);">Received</td><td class="text-right">{{ $money($invoice->paidTotal()) }}</td></tr>
                <tr style="font-weight:700;"><td>Still owing</td><td class="text-right">{{ $money($invoice->outstanding()) }}</td></tr>
            </table>
        </div>
    </section>

    {{-- draft: payment details and notes, then issue --}}
    @if($invoice->status === 'draft' && $canManage)
    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-invoice-draft>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Check the draft</h2>
        <form method="POST" action="{{ route('corex.auctions.invoices.notes', $invoice->id) }}" class="space-y-3">@csrf @method('PUT')
            <div><label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Payment details printed on the invoice</label>
                <textarea name="payment_details" rows="3" class="w-full px-3 py-2 text-sm rounded-md" style="{{ $fieldStyle }}">{{ old('payment_details', $invoice->payment_details) }}</textarea></div>
            <div><label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 text-sm rounded-md" style="{{ $fieldStyle }}">{{ old('notes', $invoice->notes) }}</textarea></div>
            <button class="corex-btn-outline text-xs px-3 py-2">Save draft</button>
        </form>
        <form method="POST" action="{{ route('corex.auctions.invoices.issue', $invoice->id) }}" onsubmit="return confirm('Issue this invoice and email it to the buyer?');">@csrf<button class="corex-btn-primary">Issue and email to the buyer</button></form>
    </section>
    @endif

    @if(in_array($invoice->status, ['issued', 'part_paid', 'paid'], true))
    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-invoice-payments>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Payments</h2>
        @forelse($invoice->payments as $p)
        <div class="flex flex-wrap items-center gap-3 text-sm" style="border-top:1px solid var(--border);padding-top:.4rem;">
            <span class="w-28 text-xs" style="color:var(--text-muted);">{{ $p->received_at->format('d M Y') }}</span>
            <span class="w-20">{{ \App\Models\AuctionInvoicePayment::KINDS[$p->kind] ?? $p->kind }}</span>
            <strong class="w-32">{{ $money($p->amount) }}</strong>
            <span class="flex-1 text-xs" style="color:var(--text-muted);">{{ $p->reference }}@if($p->recorder) · {{ $p->recorder->name }}@endif</span>
            @if($canManage)
            <form method="POST" action="{{ route('corex.auctions.invoices.payments.void', [$invoice->id, $p->id]) }}" class="flex items-center gap-1" onsubmit="return confirm('Void this payment?');">@csrf @method('DELETE')
                <input name="reason" placeholder="Why void it?" required class="px-2 py-1 text-xs rounded-md w-36" style="{{ $fieldStyle }}"><button class="text-xs underline" style="color:#ef4444;">Void</button></form>
            @endif
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">Nothing received yet.</p>
        @endforelse
        @foreach($voided as $v)<div class="text-xs" style="color:var(--text-muted);text-decoration:line-through;">{{ $v->received_at->format('d M Y') }} · {{ $money($v->amount) }} · voided: {{ $v->void_reason }}</div>@endforeach

        @if($canManage && $open)
        <form method="POST" action="{{ route('corex.auctions.invoices.payments.store', $invoice->id) }}" class="flex flex-wrap items-end gap-2 pt-3" style="border-top:1px solid var(--border);" data-record-payment>@csrf
            <select name="kind" class="px-2 py-2 text-sm rounded-md" style="{{ $fieldStyle }}">@foreach(\App\Models\AuctionInvoicePayment::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $invoice->paidTotal() < (float) $invoice->deposit_amount ? 'deposit' : 'balance') === $k)>{{ $l }}</option>@endforeach</select>
            <input name="amount" type="number" step="0.01" min="0.01" required placeholder="Amount (R)" value="{{ old('amount') }}" class="px-2 py-2 text-sm rounded-md w-40" style="{{ $fieldStyle }}">
            <input name="received_at" type="date" required value="{{ old('received_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" class="px-2 py-2 text-sm rounded-md" style="{{ $fieldStyle }}">
            <input name="reference" placeholder="Bank reference" value="{{ old('reference') }}" class="px-2 py-2 text-sm rounded-md w-44" style="{{ $fieldStyle }}">
            <button class="corex-btn-primary">Record payment</button>
        </form>
        @endif
    </section>

    <section class="rounded-md p-5 space-y-2" style="background:var(--surface);border:1px solid var(--border);" data-invoice-guarantee>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bank guarantee</h2>
        <p class="text-sm">@if($invoice->guarantee_received_at)Received {{ $invoice->guarantee_received_at->format('d M Y') }}@if($invoice->guarantee_note) — {{ $invoice->guarantee_note }}@endif @else Not received yet. @endif</p>
        @if($canManage)
        <form method="POST" action="{{ route('corex.auctions.invoices.guarantee', $invoice->id) }}" class="flex flex-wrap items-center gap-2">@csrf
            <input name="note" placeholder="Note (bank, reference)" value="{{ $invoice->guarantee_note }}" class="px-2 py-2 text-sm rounded-md w-64" style="{{ $fieldStyle }}">
            @if($invoice->guarantee_received_at)<button class="corex-btn-outline text-xs px-3 py-2" name="received" value="0">Mark as not received</button>
            @else<button class="corex-btn-outline text-xs px-3 py-2" name="received" value="1">Mark as received</button>@endif
        </form>
        @endif
    </section>
    @endif

    @if($canManage)
    <section class="rounded-md p-5 flex flex-wrap items-center gap-3" style="background:var(--surface);border:1px solid var(--border);" data-invoice-actions>
        @if($invoice->status === 'cancelled')
            <p class="text-sm" style="color:var(--text-muted);">Cancelled{{ $invoice->cancel_reason ? ': ' . $invoice->cancel_reason : '' }}.</p>
            <form method="POST" action="{{ route('corex.auctions.invoices.restore', $invoice->id) }}">@csrf<button class="corex-btn-primary">Restore as a draft</button></form>
        @else
            @if(in_array($invoice->status, ['issued', 'part_paid', 'paid'], true))
            <form method="POST" action="{{ route('corex.auctions.invoices.resend', $invoice->id) }}">@csrf<button class="corex-btn-outline">Email it to the buyer again</button></form>
            @endif
            <form method="POST" action="{{ route('corex.auctions.invoices.cancel', $invoice->id) }}" class="flex items-center gap-2" onsubmit="return confirm('Cancel this invoice?');">@csrf
                <input name="reason" placeholder="Why cancel?" required class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline" style="color:#ef4444;">Cancel invoice</button></form>
        @endif
    </section>
    @endif
</div>
@endsection
