@extends('layouts.corex')

{{--
    .ai/specs/rental-notices.md §6 - the bulk payment-breach screen. The chosen leases in a spreadsheet-like list with an "Outstanding amount" to fill in;
    only rows WITH an amount become a breach notice. Each is sent from the agent's own mailbox and the landlord is told.
--}}

@section('content')
<div class="p-6 space-y-4 max-w-6xl">
    <h1 class="text-lg font-semibold">Breach notice - payment</h1>
    <p class="text-sm" style="color: var(--text-muted);">Fill in the amount owing on each lease. <strong>Only leases with an amount get a breach notice</strong>; leave the rest blank. The tenant has {{ $remedyDays }} business days to put it right, and the landlord is told.</p>

    @if($dropped > 0)
        <p class="text-xs" style="color: #b45309;" data-qa="bulk-dropped">{{ $dropped }} ticked {{ $dropped === 1 ? 'lease was' : 'leases were' }} left out (not a running lease, or not one you can see).</p>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);" data-qa="bulk-errors">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($templates->isEmpty())
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-qa="no-templates">
            Your agency has not added a payment breach notice template yet. Breach notices are your own documents - upload yours (a Word document with placeholders) under
            <a href="{{ route('corex.rental-notice-templates.create', ['notice_type' => 'breach_payment']) }}" style="color: var(--brand-icon, #0ea5e9);">Settings &rarr; Rental Notice Templates</a>, then come back to this screen.
        </div>
    @else
    <form method="POST" action="{{ route('corex.breaches.bulk.store') }}" class="space-y-4" data-qa="bulk-form">
        @csrf
        <div>
            <label class="text-xs font-medium">Template</label>
            <select name="rental_notice_template_id" required class="prop-input mt-1" style="max-width: 28rem;">
                @foreach($templates as $t)
                    <option value="{{ $t->id }}" @selected((int) old('rental_notice_template_id') === $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted);">
                        <th class="text-left px-4 py-2 font-medium">Tenant</th>
                        <th class="text-left px-4 py-2 font-medium">Property</th>
                        <th class="text-right px-4 py-2 font-medium">Monthly rent</th>
                        <th class="text-left px-4 py-2 font-medium">Last paid</th>
                        <th class="text-right px-4 py-2 font-medium">Outstanding amount (R)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leases as $lease)
                        <tr style="border-top: 1px solid var(--border);" data-bulk-row="{{ $lease->id }}">
                            <td class="px-4 py-2">{{ $lease->tenantNames() }}</td>
                            <td class="px-4 py-2">{{ $lease->property?->buildDisplayAddress() }}</td>
                            <td class="px-4 py-2 text-right">{{ \App\Services\Rentals\RentalNoticeService::money((float) $lease->rental_amount) }}</td>
                            <td class="px-4 py-2" style="color: var(--text-muted);" title="CoreX does not hold rent payments yet">—</td>
                            <td class="px-4 py-2 text-right"><input type="text" inputmode="decimal" name="amount[{{ $lease->id }}]" value="{{ old('amount.' . $lease->id) }}" placeholder="0.00" class="rounded-md px-3 py-1 text-sm text-right w-36" style="border: 1px solid var(--border);" aria-label="Outstanding amount for {{ $lease->tenantNames() }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <details class="text-xs" style="color: var(--text-muted);">
            <summary class="cursor-pointer">Extra figures your template needs (one per line as name=value) and the placeholders filled for you</summary>
            <textarea name="figures_raw" rows="3" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('figures_raw') }}</textarea>
            <ul class="list-disc list-inside mt-1">@foreach($placeholders as $k => $label)<li><code>&#123;&#123;{{ $k }}&#125;&#125;</code> - {{ $label }}</li>@endforeach</ul>
        </details>

        <div class="flex gap-2">
            <button type="submit" class="corex-btn-primary text-xs" onclick="return confirm('Send a breach notice for every lease with an amount?')">Send breach notices</button>
@feature('rental-leases')
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">Cancel</a>
@endfeature
        </div>
    </form>
    @endif
</div>
@endsection
