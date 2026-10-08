@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3a — the fault report detail screen. --}}

@php
    $statusBadgeClass = match ($faultReport->status) {
        'resolved' => 'ds-badge-success',
        'reported', 'under_review', 'awaiting_approval' => 'ds-badge-info',
        'declined', 'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
{{-- AT-439 Part 3 — full-width (was max-w-3xl), matching the Lease Hub/Work
     Order show precedent: every line of space is data the agent needs or a
     control they act on. Two-column below the header: record + actions
     left, photos/history right. --}}
<div class="p-6 max-w-7xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    {{-- §17.3.2 / §17.10.6 — a refused "Create work order" or a refused "repaired" outcome says WHY, in plain words. --}}
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $faultReport->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $faultReport->status)) }}</span>
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
            {{-- §17.3 (R0) — ONE "Create work order" action, in the header. Shown whenever the gate allows it
                 (reported, awaiting approval, or approved with the agency-appoints route); the form it opens
                 (#raise-work-order-form) still lives in the Owner approval card below. --}}
            @permission('rental_fault_reports.raise_work_order')
                @if($faultReport->workOrderBlockReason() === null)
                    <button type="button" onclick="document.getElementById('raise-work-order-form').classList.toggle('hidden')" class="corex-btn-primary text-xs">Create work order</button>
                @endif
            @endpermission
            <a href="{{ route('corex.rental-fault-reports.pdf', $faultReport) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">&larr; All fault reports</a>
        </div>
    </div>

    <x-rental-context-bar :property="$faultReport->property" :lease="$faultReport->lease" current="faults" />

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
                    <span class="ds-badge {{ $faultReport->workOrder->status === \App\Models\RentalWorkOrder::STATUS_DISPUTED ? 'ds-badge-danger' : 'ds-badge-muted' }}">{{ ucfirst(str_replace('_', ' ', $faultReport->workOrder->status)) }}</span>
                    @if($faultReport->workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
                        <span class="ds-badge ds-badge-info">Awaiting owner approval</span>
                    @endif
                    @if($faultReport->workOrder->approval_basis === \App\Models\RentalWorkOrder::BASIS_EMERGENCY)
                        <span class="ds-badge ds-badge-danger">Emergency approved</span>
                    @endif
                    @php $faultRound = $faultReport->workOrder->latestCompletionRound(); @endphp
                    @if($faultRound && $faultRound->isAwaitingTenant())
                        <span class="ds-badge ds-badge-info">Tenant check — answer due {{ $faultRound->window_ends_at?->format('j M') ?? 'by phone' }}</span>
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
                @if(!in_array($faultReport->status, ['cancelled', 'resolved'], true))
                    <button type="button" onclick="document.getElementById('cancel-fault-report-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel report</button>
                @endif
            @endpermission
            @permission('rental_fault_reports.create')
                @if($faultReport->isDeletable() && $faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-fault-reports.destroy', $faultReport) }}" onsubmit="return confirm('Archive this fault report?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
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
                        <button type="submit" name="send_now" value="1" class="corex-btn-primary text-xs" onclick="return confirm('Send this to the owner now? They will see it in their portal and be emailed.');">Save and send to owner</button>
                    @endpermission
                </div>
            </form>
            @if($faultReport->owner_version_saved_at)
                @permission('rental_fault_reports.send_to_owner')
                <form method="POST" action="{{ route('corex.rental-fault-reports.send-to-owner', $faultReport) }}" onsubmit="return confirm('Send the saved owner version to the owner now?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">Send saved version to owner</button>
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
    </div>
    @endif

    {{-- §3a.1/§3.4a - the owner's decision. ONE decision, ever: once it is made - on the portal link or captured here by the
         agent - this card shows it read-only (who, how, when, why, contractor). Not shown once the report is closed. --}}
    @if($isOpen)
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

        {{-- §17.3 (R0) — "Create work order": who does the work (Internal crew is the default), the title and the
             description (pre-filled from the fault) and, for an outside contractor, the trade. Internal creates the work
             order AND its job card and lands on the card; External creates the work order only. --}}
        @permission('rental_fault_reports.raise_work_order')
            @if($faultReport->workOrderBlockReason() === null)
                <form id="raise-work-order-form" method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}" class="{{ request()->boolean('create_work_order') ? '' : 'hidden' }} space-y-3 pt-2" x-data="{ who: '{{ old('assignment_type', $prefillOwner ? \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR : ($prefillSupplier ? \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER : \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL)) }}' }">
                    @csrf
                    @if($prefillSupplier)
                        <input type="hidden" name="agency_service_provider_id" value="{{ $prefillSupplier->id }}">
                        <p class="text-xs rounded-md p-2" style="background: var(--surface-2);">Contractor chosen on the decision: <strong>{{ $prefillSupplier->name }}</strong>{{ $prefillSupplier->phone ? ' (' . $prefillSupplier->phone . ')' : '' }}. Ordering still follows the usual quote and authorisation steps.</p>
                    @endif
                    <div>
                        <label class="text-xs font-medium">Who does the work?</label>
                        <div class="flex flex-wrap gap-4 mt-1 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="assignment_type" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}" x-model="who"> Internal crew <span class="text-xs" style="color: var(--text-muted);">(creates a job card)</span></label>
                            <label class="flex items-center gap-2"><input type="radio" name="assignment_type" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}" x-model="who"> Agency contractor</label>
                            <label class="flex items-center gap-2"><input type="radio" name="assignment_type" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}" x-model="who"> Owner's contractor</label>
                        </div>
                        <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}'">Creates the work order and a job card for your maintenance crew.</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}'" x-cloak>Creates the work order. You then capture the contractor's quote and send them the work order.</p>
                    </div>
                    <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}'" x-cloak>Creates the work order for the owner's own contractor. The owner arranges and pays them; you coordinate, and the tenant is kept informed. No job card is created.</p>
                    <div x-show="who !== '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}'" x-cloak>
                        <label class="text-xs font-medium">Trade type</label>
                        <select name="trade_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            <option value="">— Not yet known —</option>
                            @foreach(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get() as $type)
                                <option value="{{ $type->code }}" @selected($prefillTradeCode === $type->code)>{{ $type->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}'" x-cloak class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium">Owner's contractor &mdash; name <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                            <input type="text" name="contractor_name" maxlength="191" value="{{ old('contractor_name', $prefillOwner?->contractor_name) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-xs font-medium">Phone <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                            <input type="text" name="contractor_phone" maxlength="40" value="{{ old('contractor_phone', $prefillOwner?->contractor_phone) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-medium">Title</label>
                        <input type="text" name="title" required maxlength="191" value="{{ old('title', $faultReport->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="text-xs font-medium">Description</label>
                        <textarea name="description" required rows="3" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $faultReport->description) }}</textarea>
                    </div>
                    <button type="submit" class="corex-btn-primary text-xs">Create work order</button>
                </form>
            @elseif($faultReport->status === \App\Models\RentalFaultReport::STATUS_DECLINED)
                <p class="text-xs" style="color: var(--text-muted);">{{ $faultReport->workOrderBlockReason() }}</p>
            @endif
        @endpermission


        @permission('rental_fault_reports.record_approval')
            @if(! $decisionSummary && $faultReport->rental_work_order_id === null)
            <button type="button" onclick="document.getElementById('record-approval-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Record decision</button>
            <form id="record-approval-form" method="POST" action="{{ route('corex.rental-fault-reports.approval.store', $faultReport) }}" enctype="multipart/form-data" class="hidden space-y-3 pt-2" x-data="{ decision: '{{ old('decision', 'approved') }}', route: '{{ old('approval_route', 'agency_appoints') }}' }">
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
                        <label class="text-xs font-medium">Contractor <span class="font-normal" style="color: var(--text-muted);">(from your suppliers for {{ $faultReport->faultType?->category ?? 'this type of work' }})</span></label>
                        @if($contractors->isNotEmpty())
                            <select name="agency_service_provider_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                                <option value="">&mdash; Choose later &mdash;</option>
                                @foreach($contractors as $c)
                                    <option value="{{ $c['id'] }}" @selected((string) old('agency_service_provider_id') === (string) $c['id'])>{{ $c['name'] }}{{ $c['phone'] ? ' (' . $c['phone'] . ')' : '' }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="text-xs mt-1" style="color: var(--text-muted);">No suppliers are set up for {{ $faultReport->faultType?->category ? '“' . $faultReport->faultType->category . '”' : 'this type of work' }} yet. Add one under Suppliers, or save the decision now and choose the contractor on the work order.</p>
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
                    <button type="submit" class="corex-btn-primary text-xs">Save decision</button>
                    @permission('rental_fault_reports.raise_work_order')
                        <button type="submit" name="after" value="create_work_order" x-show="decision === 'approved'" x-cloak class="corex-btn-outline text-xs">Save decision and create work order</button>
                    @endpermission
                </div>
            </form>
            @endif
        @endpermission
    </div>
    @endif

    {{-- §3a.2/§0c — the spine. Always reachable while the report is open,
         regardless of approval state. --}}
    @if(!in_array($faultReport->status, ['resolved', 'cancelled'], true))
    @permission('rental_fault_reports.resolve')
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Outcome</h2>
        <form method="POST" action="{{ route('corex.rental-fault-reports.outcome.store', $faultReport) }}" class="space-y-3" x-data="{ outcome: '' }">
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
            <button type="submit" class="corex-btn-primary text-xs">Save outcome</button>
        </form>
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
        <form method="POST" action="{{ route('corex.rental-fault-reports.photos.store', $faultReport) }}" enctype="multipart/form-data" class="flex items-end gap-2">
            @csrf
            <input type="file" name="photo" accept="image/*" required class="text-xs">
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
