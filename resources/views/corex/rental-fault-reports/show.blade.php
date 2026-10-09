@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3a — the fault report detail screen. --}}

@php
        // Johan, 8 Oct 2026: the screen shows only what fits where the fault IS, not every stage at once.
        //   prepare     reported / under review   -> the owner version + send it
        //   with_owner  sent, owner not yet decided -> record the owner's decision on their behalf
        //   appoint     owner approved             -> appoint the contractor (Create work order)
        //   in_progress a work order exists        -> the work order carries it; the outcome closes the fault
        //   declined    owner said no              -> the outcome closes the fault
        $stage = match ($faultReport->status) {
            \App\Models\RentalFaultReport::STATUS_REPORTED, \App\Models\RentalFaultReport::STATUS_UNDER_REVIEW => 'prepare',
            \App\Models\RentalFaultReport::STATUS_AWAITING_APPROVAL => 'with_owner',
            \App\Models\RentalFaultReport::STATUS_APPROVED, \App\Models\RentalFaultReport::STATUS_OWNER_HANDLING => 'appoint',
            \App\Models\RentalFaultReport::STATUS_DECLINED => 'declined',
            default => 'in_progress',
        };
        $noApprovalLimit = $faultReport->property ? \App\Models\RentalWorkOrderSetting::thresholdFor($faultReport->property) : \App\Models\RentalWorkOrderSetting::spendThresholdFor($faultReport->agency_id);
    $statusBadgeClass = match ($faultReport->status) {
        'resolved' => 'ds-badge-success',
        'reported', 'under_review', 'awaiting_approval' => 'ds-badge-info',
        'declined', 'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    // The owner's decision, read at the TOP of the page: the appoint-contractor step is the first thing the agent sees once the owner has approved.
    $latestDecision = $faultReport->decision();
    $prefillSupplier = ($latestDecision && $latestDecision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_AGENCY) ? $latestDecision->contractorSupplier : null;
    $prefillOwner = ($latestDecision && $latestDecision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_OWN) ? $latestDecision : null;
    // J7: a declined fault whose outcome was filled in automatically - the Outcome block is then a summary, not a task.
    $declinedClosed = $faultReport->status === \App\Models\RentalFaultReport::STATUS_DECLINED && (bool) $faultReport->outcome_set_automatically;
@endphp

@section('content')
{{-- AT-439 Part 3 — full-width (was max-w-3xl), matching the Lease Hub/Work
     Order show precedent: every line of space is data the agent needs or a
     control they act on. Two-column below the header: record + actions
     left, photos/history right. --}}
<div class="p-6 max-w-7xl mx-auto space-y-4">
    {{-- (the success message is the layout's toast - J8: it is not repeated here as a second banner) --}}
    {{-- §17.3.2 / §17.10.6 — a refused "Create work order" or a refused "repaired" outcome says WHY, in plain words. --}}
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $faultReport->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ $faultReport->statusLabel() }}</span>
            @if($faultReport->faultType)
                <span class="ds-badge ds-badge-muted">{{ $faultReport->faultType->name }}</span>
            @endif
            <span class="text-xs">
                @if($faultReport->property && !$faultReport->property->trashed())
                    <a href="{{ route('corex.properties.show', $faultReport->property->id) }}" style="color:var(--brand-icon,#2563eb);">{{ $faultReport->property->buildDisplayAddress() }}</a>
                @elseif($faultReport->property)
                    {{-- A trashed property's own show route 404s under default route-model binding — never a dead link. --}}
                    <span style="color: var(--text-muted);">{{ $faultReport->property->buildDisplayAddress() }} (archived)</span>
                @else
                    <span style="color: var(--text-muted);">Unknown property</span>
                @endif
            </span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-fault-reports.pdf', $faultReport) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">&larr; All fault reports</a>
        </div>
    </div>

    <x-rental-context-bar :property="$faultReport->property" :lease="$faultReport->lease" current="faults" />

    {{-- Johan, 8 Oct 2026 (night): once the owner has approved, appointing the contractor is THE next action - open by default, right here
         above everything else, no toggle and no button that scrolls somewhere else. --}}
    @permission('rental_fault_reports.raise_work_order')
        @if($stage === 'appoint')
            <div class="rounded-md p-4 space-y-2" style="background: color-mix(in srgb, var(--ds-green) 8%, var(--surface)); border: 1px solid var(--ds-green);" data-appoint-contractor id="appoint-contractor">
                <div class="text-sm font-semibold">Owner approved &mdash; appoint the contractor</div>
                @if($faultReport->workOrderBlockReason() === null)
                    @include('corex.rental-fault-reports._raise-work-order-form', ['secondary' => false])
                @else
                    <p class="text-xs" style="color: var(--text-muted);">{{ $faultReport->workOrderBlockReason() }}</p>
                @endif
            </div>
        @endif
    @endpermission

    <div class="grid grid-cols-3 gap-4">
    <div class="col-span-3 lg:col-span-2 space-y-4">

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
            <div class="col-span-2"><span style="color: var(--text-muted);">Description (as reported):</span> {{ $faultReport->description }}</div>
            <div><span style="color: var(--text-muted);">Tenancy:</span> {{ $faultReport->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
            <div><span style="color: var(--text-muted);">Item:</span> {{ $faultReport->inspectionItem?->label ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Reported by:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->reported_by_type)) }}{{ $faultReport->reportedByContact ? ' — ' . $faultReport->reportedByContact->first_name . ' ' . $faultReport->reportedByContact->last_name : ($faultReport->reportedByUser ? ' — ' . $faultReport->reportedByUser->name : '') }}</div>
            <div><span style="color: var(--text-muted);">Channel:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->reported_channel)) }}</div>
            <div><span style="color: var(--text-muted);">Captured by:</span> {{ $faultReport->capturedByUser?->name ?? 'Self-reported' }}</div>
            <div><span style="color: var(--text-muted);">Reported at:</span> {{ $faultReport->reported_at?->format('Y-m-d H:i') }}</div>
            {{-- §15 (AT-447) — "From inspection <type> <date>" back-link. --}}
            @if($faultReport->reportedInspectionObservation?->inspection)
                <div><span style="color: var(--text-muted);">From inspection:</span> @feature('rental-inspections')<a href="{{ route('corex.rental-inspections.show', $faultReport->reportedInspectionObservation->inspection) }}" class="underline">{{ ucfirst(str_replace('_', '-', $faultReport->reportedInspectionObservation->inspection->type)) }}-inspection {{ $faultReport->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $faultReport->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }}</a>@else {{ ucfirst(str_replace('_', '-', $faultReport->reportedInspectionObservation->inspection->type)) }}-inspection {{ $faultReport->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $faultReport->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }} @endfeature</div>
            @endif
            @if($faultReport->workOrder)
                {{-- §17.12 — the fault's chips are derived read-only from the linked work order. --}}
                <div><span style="color: var(--text-muted);">Work order:</span> @feature('rental-work-orders')<a href="{{ route('corex.rental-work-orders.show', $faultReport->rental_work_order_id) }}" class="underline">#{{ $faultReport->rental_work_order_id }}</a>@else #{{ $faultReport->rental_work_order_id }} @endfeature
                    <span class="ds-badge {{ $faultReport->workOrder->status === \App\Models\RentalWorkOrder::STATUS_DISPUTED ? 'ds-badge-danger' : 'ds-badge-muted' }}">{{ $faultReport->workOrder->stageLabel('agent') }}</span>
                    @if($faultReport->workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
                        <span class="ds-badge ds-badge-info">Awaiting owner approval</span>
                    @endif
                    @if($faultReport->workOrder->approval_basis === \App\Models\RentalWorkOrder::BASIS_EMERGENCY)
                        <span class="ds-badge ds-badge-danger">Emergency approved</span>
                    @endif
                    @php $faultRound = $faultReport->workOrder->latestCompletionRound(); @endphp
                    @if($faultRound && $faultRound->isAwaitingTenant())
                        <span class="ds-badge ds-badge-info">Tenant asked to check (optional)</span>
                    @endif
                </div>
            @endif
            @if($faultReport->owner_approval_status !== \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED)
                <div><span style="color: var(--text-muted);">Owner approval:</span> {{ ucfirst($faultReport->owner_approval_status) }}{{ $faultReport->approval_route ? ' — ' . str_replace('_', ' ', ucfirst($faultReport->approval_route)) : '' }}</div>
            @endif
            @if($faultReport->outcome)
                <div><span style="color: var(--text-muted);">Outcome:</span> {{ ucfirst(str_replace('_', ' ', $faultReport->outcome)) }}{{ $faultReport->repaired_at ? ' — ' . $faultReport->repaired_at->format('Y-m-d') : '' }}</div>
                @if($faultReport->outcome_note)
                    <div class="col-span-2"><span style="color: var(--text-muted);">Outcome note:</span> {{ $faultReport->outcome_note }}</div>
                @endif
            @endif
        </div>

        {{-- §3a schema — editable only while status='reported'; approval/outcome
             transitions are a separate action (Stage 2), not this form. --}}
        {{-- A tenant's / owner's own report is the original evidence: never edited (the agent prepares a separate owner version below). --}}
        @permission('rental_fault_reports.create')
        @if($faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED && ! in_array($faultReport->reported_by_type, [\App\Models\RentalFaultReport::REPORTED_BY_TENANT, \App\Models\RentalFaultReport::REPORTED_BY_LANDLORD], true))
        <div x-show="!editing" class="pt-1">
            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        </div>
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-fault-reports.update', $faultReport) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="text-xs font-medium">Title</label>
                <input type="text" name="title" required maxlength="191" value="{{ old('title', $faultReport->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Description</label>
                <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $faultReport->description) }}</textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endif
        @endpermission

        @if($faultReport->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $faultReport->cancelled_at?->format('Y-m-d') }} by {{ $faultReport->cancelledByUser?->name }}: {{ $faultReport->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_fault_reports.cancel')
                {{-- J7 (Johan, 9 Oct 2026): "Cancel report" only while the owner has not decided; once decided the fault is handled through its work order / outcome. --}}
                @if(in_array($faultReport->status, [\App\Models\RentalFaultReport::STATUS_REPORTED, \App\Models\RentalFaultReport::STATUS_UNDER_REVIEW, \App\Models\RentalFaultReport::STATUS_AWAITING_APPROVAL], true))
                    <button type="button" onclick="document.getElementById('cancel-fault-report-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel report</button>
                @endif
            @endpermission
            @permission('rental_fault_reports.create')
                @if($faultReport->isDeletable() && $faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-fault-reports.destroy', $faultReport) }}">
                        @csrf
                        @method('DELETE')
                        <x-confirm-submit title="Archive this fault report" message="Archive this fault report? It leaves the list, and an admin can restore it." confirm-label="Archive" :danger="true" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</x-confirm-submit>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-fault-report-form" method="POST" action="{{ route('corex.rental-fault-reports.cancel', $faultReport) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>

    @php
        // Fault flow: the contractor chosen on the decision (agency route) pre-fills the existing work-order form.
        $latestDecision = $faultReport->decision();
        $prefillSupplier = ($latestDecision && $latestDecision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_AGENCY) ? $latestDecision->contractorSupplier : null;
        $prefillOwner = ($latestDecision && $latestDecision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_OWN) ? $latestDecision : null;
        $prefillTradeCode = null;
        if ($faultReport->faultType?->category) {
            $needle = strtolower($faultReport->faultType->category);
            $prefillTradeCode = optional(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get()->first(
                fn ($t) => str_contains(strtolower($t->label . ' ' . $t->code), $needle)
            ))->code;
        }
        $isOpen = ! in_array($faultReport->status, ['resolved', 'cancelled'], true);
        $notYetSent = $faultReport->sent_to_owner_at === null && $faultReport->owner_approval_status === \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED;
    @endphp

    {{-- Fault flow F2 (Johan 2026-10-08) - what the OWNER will see. The tenant's original above stays exactly as reported;
         this is the agent's reviewed, sanitised copy. Until the agent presses send the owner cannot see the fault at all. --}}
    @if($isOpen)
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="owner-version-card">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Owner version <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; what the owner will see</span></h2>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ $faultReport->statusLabel() }}</span>
        </div>

        @if($faultReport->sent_to_owner_at === null && $faultReport->owner_approval_status === \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED)
            <p class="text-xs" style="color: var(--text-muted);">The owner cannot see this fault yet. Check the wording, choose the photos and add your note, then send it. The tenant's original is kept as it was reported.</p>
            @permission('rental_fault_reports.create')
            <form method="POST" action="{{ route('corex.rental-fault-reports.owner-version.store', $faultReport) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs font-medium">Title the owner sees</label>
                    <input type="text" name="owner_title" required maxlength="191" value="{{ old('owner_title', $faultReport->owner_title ?? $faultReport->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Description the owner sees</label>
                    <textarea name="owner_description" rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('owner_description', $faultReport->owner_description ?? $faultReport->description) }}</textarea>
                </div>
                @if($faultReport->photos->isNotEmpty())
                <div>
                    <label class="text-xs font-medium">Photos the owner sees <span class="font-normal" style="color: var(--text-muted);">(none are shared unless ticked)</span></label>
                    <div class="flex flex-wrap gap-3 mt-1">
                        @foreach($faultReport->photos as $photo)
                            <label class="text-xs flex flex-col items-center gap-1">
                                <img src="{{ $photo->storage_path }}" alt="" class="rounded-md object-cover" style="width: 90px; height: 70px; border: 1px solid var(--border);">
                                <span><input type="checkbox" name="owner_photo_ids[]" value="{{ $photo->id }}" @checked(in_array($photo->id, (array) old('owner_photo_ids', $faultReport->owner_photo_ids ?? []), true))> Share</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endif
                <div>
                    <label class="text-xs font-medium">Your note / recommendation to the owner</label>
                    <textarea name="owner_agent_note" rows="3" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. I recommend we approve this - a plumber can fix it this week.">{{ old('owner_agent_note', $faultReport->owner_agent_note) }}</textarea>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="corex-btn-outline text-xs">Save owner version</button>
                    @permission('rental_fault_reports.send_to_owner')
                        <x-confirm-submit title="Send to the owner" message="Send this to the owner now? They will see it in their portal and be emailed." confirm-label="Send to owner" name="send_now" value="1">Save and send to owner</x-confirm-submit>
                    @endpermission
                </div>
            </form>
            @if($faultReport->owner_version_saved_at)
                @permission('rental_fault_reports.send_to_owner')
                <form method="POST" action="{{ route('corex.rental-fault-reports.send-to-owner', $faultReport) }}">
                    @csrf
                    <x-confirm-submit title="Send to the owner" message="Send the saved owner version to the owner now? They will see it in their portal and be emailed." confirm-label="Send to owner">Send saved version to owner</x-confirm-submit>
                </form>
                @endpermission
            @endif
            @endpermission
        @else
            @php $ov = $faultReport->ownerVersion(); @endphp
            <p class="text-xs" style="color: var(--text-muted);">
                @if($faultReport->sent_to_owner_at)
                    Sent to the owner {{ $faultReport->sent_to_owner_at->format('j M Y H:i') }}. This is exactly what they see:
                @else
                    Decided without being sent through the portal. This is the version prepared for the owner:
                @endif
            </p>
            <div class="rounded-md p-3 text-sm space-y-1" style="background: var(--surface-2);">
                <div class="font-medium">{{ $ov['title'] }}</div>
                @if($ov['description'])<div>{{ $ov['description'] }}</div>@endif
                @if($ov['photos']->isNotEmpty())
                    <div class="flex flex-wrap gap-2 pt-1">@foreach($ov['photos'] as $photo)<img src="{{ $photo->storage_path }}" alt="" class="rounded-md object-cover" style="width: 90px; height: 70px;">@endforeach</div>
                @endif
                @if($ov['agent_note'])<div class="text-xs pt-1" style="color: var(--text-muted);">Agent note: {{ $ov['agent_note'] }}</div>@endif
            </div>
        @endif

        {{-- Before the owner has decided a work order is a deliberate, secondary step (spec rental-work-orders.md 17.3.2): allowed because the work order has
             its OWN approval gate (17.6) - a job inside the property's no-approval spend limit needs no owner approval, anything above it goes to the owner
             before work starts, and an emergency needs the owner's agreement recorded on the work order (17.8). Hence the plain reason below. --}}
        @permission('rental_fault_reports.raise_work_order')
            @if(in_array($stage, ['prepare', 'with_owner'], true) && $faultReport->workOrderBlockReason() === null)
                <div class="pt-3 text-xs space-y-2" style="border-top: 1px solid var(--border);" data-early-work-order>
                    <button type="button" onclick="document.getElementById('raise-work-order-form').classList.toggle('hidden')" class="underline" style="color: var(--text-muted);">Start a work order before the owner decides</button>
                    <span style="color: var(--text-muted);"> - only for an emergency, or a small job inside this property's no-approval spend limit (R{{ number_format($noApprovalLimit, 2) }}).</span>
                    @include('corex.rental-fault-reports._raise-work-order-form', ['secondary' => true])
                </div>
            @endif
        @endpermission
    </div>
    @endif

    {{-- §3a.1/§3.4a - the owner's decision. ONE decision, ever: once it is made - on the portal link or captured here by the
         agent - this card shows it read-only (who, how, when, why, contractor). Not shown once the report is closed. --}}
    @if($isOpen && $stage !== 'prepare')
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="owner-decision-card">
        <h2 class="text-sm font-semibold">Owner decision</h2>

        @if($decisionSummary)
            <div class="rounded-md p-3 text-sm space-y-1" style="background: var(--surface-2);">
                <div class="font-medium">{{ $decisionSummary['decision'] === \App\Models\RentalApproval::DECISION_APPROVED ? 'Approved' : 'Declined' }}
                    @if($decisionSummary['route'])<span class="font-normal" style="color: var(--text-muted);">&mdash; {{ $decisionSummary['route'] === \App\Models\RentalFaultReport::ROUTE_AGENCY_APPOINTS ? 'the agency appoints the contractor' : 'the owner appoints the contractor' }}</span>@endif
                </div>
                @if($decisionSummary['contractor'])<div>{{ $decisionSummary['contractor'] }}</div>@endif
                @if($decisionSummary['reason'])<div>Reason: {{ $decisionSummary['reason'] }}</div>@endif
                <div class="text-xs" style="color: var(--text-muted);">{{ $decisionSummary['how'] }} &middot; {{ $decisionSummary['via_link'] ? 'by ' . $decisionSummary['by'] : 'recorded by ' . $decisionSummary['by'] }} &middot; {{ $decisionSummary['at']?->format('j M Y H:i') }}</div>
            </div>
        @endif

        @if($faultReport->approvals->count() > 1)
            <ul class="space-y-1 text-xs" style="color: var(--text-muted);">
                @foreach($faultReport->approvals as $approval)
                    <li>{{ ucfirst($approval->decision) }} &middot; {{ ucfirst(str_replace('_', ' ', $approval->evidence_type)) }} &middot; {{ $approval->decided_at?->format('Y-m-d') }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Johan, 8 Oct 2026: "no title, no description. choose a contractor. that should create the work order." The agent only says WHO does
             the work. The title, description and photos (the version the owner saw), property, lease/tenant and the owner's decision come from
             this fault (RentalWorkOrderService::createFromFaultDecision) and stay editable on the work order. The owner's own contractor is
             only CONFIRMED; for "the agency appoints" the agent picks one of the agency's contractors (searchable) or the internal crew. --}}
        @permission('rental_fault_reports.raise_work_order')
            @if($faultReport->status === \App\Models\RentalFaultReport::STATUS_DECLINED && $faultReport->workOrderBlockReason() !== null)
                <p class="text-xs" style="color: var(--text-muted);">{{ $faultReport->workOrderBlockReason() }}</p>
            @endif
        @endpermission


        @permission('rental_fault_reports.record_approval')
            @if(! $decisionSummary && $faultReport->rental_work_order_id === null && $stage === 'with_owner')
            <p class="text-xs" style="color: var(--text-muted);" data-waiting-for-owner>Waiting for the owner. If they have told you their decision another way, record it for them.</p>
            <button type="button" onclick="document.getElementById('record-approval-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Record decision on the owner's behalf</button>
            <form id="record-approval-form" method="POST" action="{{ route('corex.rental-fault-reports.approval.store', $faultReport) }}" enctype="multipart/form-data" class="hidden space-y-3 pt-2" x-data="{ cq: '', decision: '{{ old('decision', 'approved') }}', route: '{{ old('approval_route', 'agency_appoints') }}' }">
                @csrf
                <p class="text-xs" style="color: var(--text-muted);">Use this when the owner has told you their decision by phone, WhatsApp, email or in person. If the owner decides on their portal link, the decision appears here by itself.</p>
                <div>
                    <label class="text-xs font-medium">Decision</label>
                    <select name="decision" x-model="decision" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="approved">Approved</option>
                        <option value="declined">Declined</option>
                    </select>
                </div>
                <div x-show="decision === 'approved'" x-cloak class="space-y-3">
                    <div>
                        <label class="text-xs font-medium">Who appoints the contractor?</label>
                        <div class="flex flex-wrap gap-4 mt-1 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="approval_route" value="owner_handles" x-model="route"> The owner</label>
                            <label class="flex items-center gap-2"><input type="radio" name="approval_route" value="agency_appoints" x-model="route"> The agency</label>
                        </div>
                    </div>
                    <div x-show="route === 'owner_handles'" x-cloak class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium">Owner's contractor &mdash; name <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                            <input type="text" name="contractor_name" maxlength="191" value="{{ old('contractor_name') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-xs font-medium">Phone <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                            <input type="text" name="contractor_phone" maxlength="40" value="{{ old('contractor_phone') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        </div>
                    </div>
                    <div x-show="route === 'agency_appoints'" x-cloak>
                        <label class="text-xs font-medium">Contractor <span class="font-normal" style="color: var(--text-muted);">({{ $faultReport->faultType?->category ? 'your suppliers for ' . $faultReport->faultType->category : 'all your contractors - this fault has no type yet' }})</span></label>
                        @if($contractors->isNotEmpty())
                            <input type="search" x-model="cq" placeholder="Search contractors by name or phone" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" data-decision-contractor-search>
                            <div class="space-y-1 mt-1" data-decision-contractor-list>
                                <label class="flex items-center gap-2 text-sm"><input type="radio" name="agency_service_provider_id" value="" @checked(old('agency_service_provider_id') === null || old('agency_service_provider_id') === '')> Choose later</label>
                                @foreach($contractors as $c)
                                    <label class="flex items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-search="{{ mb_strtolower($c['name'] . ' ' . $c['phone']) }}"
                                           x-show="cq === '' || $el.dataset.search.indexOf(cq.toLowerCase()) !== -1">
                                        <input type="radio" name="agency_service_provider_id" value="{{ $c['id'] }}" @checked((string) old('agency_service_provider_id') === (string) $c['id'])>
                                        <span class="font-medium">{{ $c['name'] }}</span>@if($c['phone'])<span class="text-xs" style="color: var(--text-muted);">{{ $c['phone'] }}</span>@endif
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs mt-1" style="color: var(--text-muted);">No suppliers are set up yet. Add one under Suppliers, or save the decision now and choose the contractor afterwards.</p>
                        @endif
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium">How was it agreed?</label>
                    <select name="evidence_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="whatsapp">WhatsApp reply</option>
                        <option value="email">Email</option>
                        <option value="verbal_note">Verbal / phone / in person</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium"><span x-show="decision === 'declined'" x-cloak>Reason for declining (required)</span><span x-show="decision !== 'declined'">What the owner said</span></label>
                    <textarea name="evidence_text" required rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('evidence_text') }}</textarea>
                </div>
                <div>
                    <label class="text-xs font-medium">Screenshot (optional)</label>
                    <input type="file" name="evidence_file" accept="image/*" class="text-xs">
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-confirm-submit title="Record the owner's decision" message="A decision can only be recorded once and cannot be changed afterwards. Record it now?" confirm-label="Record decision">Save decision</x-confirm-submit>
                    @permission('rental_fault_reports.raise_work_order')
                        <span x-show="decision === 'approved'" x-cloak><x-confirm-submit title="Record the decision and appoint the contractor" message="A decision can only be recorded once and cannot be changed afterwards. Record it and go on to appoint the contractor?" confirm-label="Record and appoint" name="after" value="create_work_order" class="corex-btn-outline text-xs">Save decision and appoint contractor</x-confirm-submit></span>
                    @endpermission
                </div>
            </form>
            @endif
        @endpermission
    </div>
    @endif

    {{-- §3a.2/§0c — the spine. Always reachable while the report is open,
         regardless of approval state. --}}
    @if((!in_array($faultReport->status, ['resolved', 'cancelled'], true) && in_array($stage, ['in_progress', 'declined'], true)) || ($faultReport->status === 'resolved' && $faultReport->outcome_set_automatically))
    @permission('rental_fault_reports.resolve')
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Outcome</h2>
        @if($faultReport->outcome_set_automatically)
            <p class="text-xs" style="color: var(--text-muted);" data-outcome-automatic>Closed automatically as <strong>{{ str_replace('_', ' ', $faultReport->outcome) }}</strong> {{ $declinedClosed ? 'when the owner declined. Nothing more is needed; the tenant sees a neutral "not approved" line. Change the outcome only if that is not right.' : 'when the work order was completed. If the repair was not complete, change it here.' }}</p>
        @endif
        @if($declinedClosed)<details data-change-outcome><summary class="text-xs cursor-pointer underline" style="color: var(--text-muted);">Change the outcome</summary><div class="pt-2">@endif
        <form method="POST" action="{{ route('corex.rental-fault-reports.outcome.store', $faultReport) }}" class="space-y-3" x-data="{ outcome: '{{ $faultReport->outcome_set_automatically ? $faultReport->outcome : '' }}' }">
            @csrf
            <div>
                <label class="text-xs font-medium">What happened</label>
                <select name="outcome" x-model="outcome" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Select…</option>
                    <option value="repaired">Repaired</option>
                    <option value="repaired_partially">Repaired partially</option>
                    <option value="not_repaired">Not repaired</option>
                    <option value="owner_declined">Owner declined</option>
                    <option value="tenant_liable">Tenant liable</option>
                    {{-- .ai/specs/rentals-faults-work-orders.md §4.4 — still logged, for
                         evidence, when the tenant's first-aid steps were enough. --}}
                    <option value="resolved_by_first_aid">Resolved by first aid (no repair needed)</option>
                </select>
            </div>
            <div x-show="outcome === 'repaired' || outcome === 'repaired_partially'" x-cloak>
                <label class="text-xs font-medium">Date repaired</label>
                <input type="date" name="repaired_at" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div x-show="outcome !== '' && outcome !== 'repaired' && outcome !== 'resolved_by_first_aid'" x-cloak>
                <label class="text-xs font-medium">Note</label>
                <textarea name="outcome_note" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            <x-confirm-submit title="Record the outcome" message="Recording the outcome closes this fault report. Record it now?" confirm-label="Record outcome">Save outcome</x-confirm-submit>
        </form>
        @if($declinedClosed)</div></details>@endif
    </div>
    @endpermission
    @endif

    </div>
    {{-- Side column: photos, history. --}}
    <div class="col-span-3 lg:col-span-1 space-y-4">

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Photos</h2>
        @if($faultReport->photos->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
        @else
            <div class="grid grid-cols-2 gap-2">
                @foreach($faultReport->photos as $photo)
                    <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                @endforeach
            </div>
        @endif
        @permission('rental_fault_reports.create')
        <form method="POST" action="{{ route('corex.rental-fault-reports.photos.store', $faultReport) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
            @csrf
            <input type="file" name="photo" accept="image/*" required class="text-xs min-w-0 max-w-full">
            <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
        </form>
        @endpermission
    </div>

    {{-- Johan, 2026-09-22 — "who did what": a plain chronological history,
         not a status badge on every row. RentalFaultReport::history() merges
         creation, every logged update, and every approval decision into one
         timeline, oldest first. Shown regardless of status — a resolved or
         cancelled report's history is exactly as real as an open one's. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">History</h2>
        <ul class="space-y-1 text-sm">
            @foreach($faultReport->history() as $entry)
                <li>
                    <span style="color: var(--text-muted);">{{ $entry['at']->format('Y-m-d H:i') }}</span>
                    —
                    {{ $entry['action'] }}
                    @if($entry['from'] || $entry['to'])
                        ({{ $entry['from'] ?? '—' }} &rarr; {{ $entry['to'] ?? '—' }})
                    @endif
                    @if($entry['note'])
                        — {{ $entry['note'] }}
                    @endif
                    @if($entry['actor'])
                        <span style="color: var(--text-muted);">({{ $entry['actor'] }})</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    </div>
    </div>
</div>
@endsection
