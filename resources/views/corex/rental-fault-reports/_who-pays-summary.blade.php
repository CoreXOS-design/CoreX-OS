{{-- .ai/specs/rentals-faults-work-orders.md section 22.13.3 - read-only: what was found, the cause and the current "Who pays" ruling, so both lease agents see it on the fault as well as on the work order. A record only. --}}
@php
    $wpSvc = app(\App\Services\Rentals\RentalWhoPaysService::class);
    $wpRuling = $wpSvc->rulingForFault($faultReport);
    $wpWo = $faultReport->rental_work_order_id ? \App\Models\RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $faultReport->agency_id)->find($faultReport->rental_work_order_id) : null;
@endphp
@if($wpWo && ($wpWo->found_text || $wpRuling))
<div class="rounded-md p-4 space-y-1" style="background: var(--surface); border: 1px solid var(--border);" data-who-pays-summary>
    <h2 class="text-sm font-semibold">Who pays</h2>
    <p class="text-xs" style="color: var(--text-muted);">A record of the decision. CoreX does not raise a charge.</p>
    @if($wpWo->found_text)
        <div class="text-sm"><strong>What was found:</strong> {{ $wpWo->found_text }}</div>
        <div class="text-sm"><strong>Cause:</strong> {{ $wpSvc->causeText($wpWo) }}</div>
    @endif
    @if($wpRuling)
        <div class="text-sm"><strong>Ruling:</strong> {{ $wpRuling->summary() }} &mdash; {{ $wpRuling->reason }}</div>
    @else
        <div class="text-sm" style="color: var(--text-muted);">No ruling yet.</div>
    @endif
    <a class="text-xs underline" href="{{ route('corex.rental-work-orders.show', $wpWo) }}#who-pays">Open the work order</a>
</div>
@endif
