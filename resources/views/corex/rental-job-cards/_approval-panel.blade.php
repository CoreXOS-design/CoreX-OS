{{--
    .ai/specs/rental-work-orders.md §17.6–§17.8 — BUILD 2's job-card slot: why it was approved, the approval chips, the variation panel and
    the emergency chip (the emergency form itself lives on the work order). Receives $jobCard, $isOpen and everything show.blade.php has in scope.
    Owner-facing amounts only — nothing here shows cost, markup or margin.
--}}
@php
    $jaWorkOrder = $jobCard->workOrder;
@endphp
@if($jaWorkOrder)
    @php
        $jaGate = app(\App\Services\Rentals\RentalApprovalGateService::class);
        $jaProceed = $jaGate->authoriseCard($jobCard, false);
        $jaEmergency = $jaWorkOrder->activeEmergencyApproval();
        $jaLatest = $jaWorkOrder->latestApprovalDecision();
        $jaOpenVariation = $jaWorkOrder->openVariation();
    @endphp
    <div id="jc-approval-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Owner approval</h2>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($jaEmergency)
                <span class="ds-badge ds-badge-warning">Emergency — owner agreed by {{ str_replace('_', ' ', $jaEmergency->approved_via) }} on {{ $jaEmergency->approved_at?->format('j M Y') }}</span>
                @feature('rental-work-orders')<a href="{{ route('corex.rental-work-orders.show', $jaWorkOrder) }}#emergency-panel" class="underline">Work order</a>@else Work order @endfeature
            @elseif($jaWorkOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
                <span class="ds-badge ds-badge-warning">Awaiting owner approval</span>
            @elseif($jaWorkOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_DECLINED)
                <span class="ds-badge ds-badge-danger">Owner declined</span>
            @elseif($jaWorkOrder->approvalBasisLabel())
                <span class="ds-badge ds-badge-success">{{ $jaWorkOrder->approvalBasisLabel() }}</span>
            @elseif(isset($stage) && $stage['quote']['within'] && $jobCard->acceptedLines()->exists())
                {{-- J2 (Johan, 9 Oct 2026): within the owner's limit nothing needs asking - say so instead of "not approved". --}}
                <span class="ds-badge ds-badge-info" data-auto-approval>Approved automatically once the price is confirmed (within the owner's limit)</span>
            @else
                <span class="ds-badge ds-badge-muted">Not approved yet</span>
            @endif
            @if($jaOpenVariation)<span class="ds-badge ds-badge-warning">Variation awaiting owner</span>@endif
        </div>
        @if($jaWorkOrder->approved_amount !== null)
            <p class="text-sm">Approved amount (to the owner): <strong>R{{ number_format((float) $jaWorkOrder->approved_amount, 2) }}</strong></p>
        @endif
        @if($jaLatest)
            <p class="text-xs"><span style="color: var(--text-muted);">{{ $jaWorkOrder->approvalReasonLabel() }}</span> {{ $jaLatest->note }}</p>
        @endif
        @if(!$jaProceed->authorised)
            <p class="text-xs" style="color: var(--ds-crimson);">Work cannot start or be scheduled yet: {{ $jaProceed->note }}
                @permission('rental_work_orders.record_emergency_approval')
                    @feature('rental-work-orders')<a href="{{ route('corex.rental-work-orders.show', $jaWorkOrder) }}#emergency-panel" class="underline">Emergency? Record the owner's agreement on the work order.</a>@else Emergency? Record the owner's agreement on the work order. @endfeature
                @endpermission
            </p>
        @endif
    </div>
    @include('corex.rental-work-orders._variation-panel', ['workOrder' => $jaWorkOrder])
@endif
