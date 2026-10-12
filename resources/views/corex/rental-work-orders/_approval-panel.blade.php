{{--
    .ai/specs/rental-work-orders.md §17.6–§17.8 — BUILD 2's work-order slot: "Why was this approved?", the approval chips, the
    emergency approval panel and the variation panel. Receives $workOrder, $isOpen, $proceed (a pure read of authoriseToProceed()),
    $ownerContacts and everything show.blade.php has in scope. Owner-facing amounts only (selling); no cost, markup or margin.
--}}
@php
    $apLatest = $workOrder->latestApprovalDecision();
    $apEmergency = $workOrder->activeEmergencyApproval();
    $apOpenVariation = $workOrder->openVariation();
    $apAutoCount = $workOrder->variations()->where('status', \App\Models\RentalWorkOrderVariation::STATUS_AUTO_APPROVED)->count();
@endphp
<div id="approval-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-sm font-semibold">Approval</h2>

    <div class="flex flex-wrap items-center gap-2 text-xs">
        @if($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
            <span class="ds-badge ds-badge-warning">Awaiting owner approval</span>
        @elseif($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_DECLINED)
            <span class="ds-badge ds-badge-danger">Owner declined</span>
        @elseif($apEmergency)
            <span class="ds-badge ds-badge-warning">Emergency — owner agreed</span>
        @elseif($workOrder->approvalBasisLabel())
            <span class="ds-badge ds-badge-success">{{ $workOrder->approvalBasisLabel() }}</span>
        @else
            <span class="ds-badge ds-badge-muted">No approval needed yet</span>
        @endif
        @if($workOrder->isDeclaredEmergency())<span class="ds-badge ds-badge-warning" data-declared-emergency-badge>Emergency declared</span>@endif
        @if($workOrder->quote_first)<span class="ds-badge ds-badge-info" data-quote-first-badge>Owner asked for a quote first</span>@endif
        @if($apOpenVariation)<span class="ds-badge ds-badge-warning">Variation awaiting owner</span>@endif
        @if($apAutoCount > 0)<span class="ds-badge ds-badge-info">{{ $apAutoCount }} extra{{ $apAutoCount > 1 ? 's' : '' }} auto-approved</span>@endif
    </div>

    @if($workOrder->approved_amount !== null)
        <p class="text-sm">Approved amount (to the owner): <strong>R{{ number_format((float) $workOrder->approved_amount, 2) }}</strong></p>
    @endif

    <div class="text-sm">
        <span style="color: var(--text-muted);">{{ $workOrder->approvalReasonLabel() }}</span>
        @if($apLatest)
            {{ $apLatest->note }}
        @elseif($workOrder->approval_basis === \App\Models\RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED)
            Already under way before approvals were recorded.
        @else
            No approval decision recorded yet.
        @endif
    </div>

    <p class="text-xs" style="color: {{ $proceed->authorised ? 'var(--text-muted)' : 'var(--ds-crimson)' }};">
        @if($proceed->authorised)
            Work may proceed.
        @else
            Work cannot start yet: {{ $proceed->note }}
        @endif
    </p>
    {{-- AT-452 round 3 (§20): authority C - record what the owner told you (replaces the old "Record decision on the owner's behalf" form). --}}
    @if($isOpen)
        @permission('rental_work_orders.record_approval')
            @include('corex.rental-owner-decision._record', ['subject' => $workOrder])
        @endpermission
    @endif
</div>

@include('corex.rental-work-orders._variation-panel')
@include('corex.rental-work-orders._emergency-panel')
