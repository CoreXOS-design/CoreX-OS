@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3/§3.4 — the work order detail screen. --}}

@php
    $statusBadgeClass = match ($workOrder->status) {
        'completed' => 'ds-badge-success',
        'reported', 'ordered', 'in_progress' => 'ds-badge-info',
        'cancelled', 'disputed' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    $isOpen = !in_array($workOrder->status, ['completed', 'cancelled'], true);
@endphp

@section('content')
{{-- AT-442 — full-width (was max-w-3xl); every line of space here is data
     the agent needs or a control they act on, and the Job Card block below
     needs the room a narrow centre column didn't have. --}}
<div class="p-6 max-w-7xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $workOrder->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}" title="{{ \App\Models\RentalWorkOrder::statusWord($workOrder->status) }}">{{ $workOrder->stageLabel('agent') }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $workOrder->property?->trashed() ? ' (archived)' : '' }}</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-work-orders.pdf', $workOrder) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">&larr; All work orders</a>
        </div>
    </div>

    {{-- AT-439 Part 3, item 2 — the shared rental context bar, handed to
         this screen by the AT-440 build that created it (its own docblock
         names this exact include). --}}
    <x-rental-context-bar :property="$workOrder->property" :lease="$workOrder->lease" current="work_orders" />

    {{-- Johan, 8 Oct 2026: after the contractor is picked, say plainly what happens next. This describes the rule AS IT WORKS (RentalApprovalGateService::
         evaluateQuote): the selected quote - the amount the owner would see - is tested against the PROPERTY's no-approval limit (its own override,
         else the agency default). At or under: approved on the spot. Over: the owner is asked, and the work order cannot be sent until they approve. --}}
    @if($isOpen && $workOrder->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER && $workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
        @php $nextContractor = $workOrder->contractorLabel() ?? 'the contractor'; @endphp
        <div class="rounded-md p-3 text-sm space-y-1" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent); border: 1px solid var(--border);" data-next-step>
            <div class="font-semibold">What happens next</div>
            @if(! $selectedQuote && $workOrder->quotes->isNotEmpty())
                <div class="font-medium" style="color: var(--ds-crimson, #b3261e);" data-quote-not-selected>No quote chosen yet &mdash; the owner has not been asked, and the work order cannot be sent. Choose the quote below with its Select button.</div>
            @elseif(! $selectedQuote)
                <div>Capture {{ $nextContractor }}'s quote below and select it. If the quote is <strong>R{{ number_format($noApprovalThreshold, 2) }} or less</strong> (this property's no-approval limit) it is approved automatically and you can send the work order. If it is <strong>more</strong>, it goes to the owner for approval first, and the work order cannot be sent until they approve.</div>
            @elseif($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
                <div class="font-medium" data-quote-sent-to-owner>Quote sent to {{ app(\App\Services\Rentals\RentalWorkOrderService::class)->ownerNames($workOrder) ?: 'the owner' }} for approval.</div>
                <div>The quote of <strong>R{{ number_format($selectedQuote->ownerFacingAmount(), 2) }}</strong> is over this property's no-approval limit of R{{ number_format($noApprovalThreshold, 2) }}. You can send the work order to {{ $nextContractor }} once they approve.</div>
            @elseif($proceed->authorised)
                <div>The quote of <strong>R{{ number_format($selectedQuote->ownerFacingAmount(), 2) }}</strong> is approved{{ $workOrder->approvalBasisLabel() ? ' (' . strtolower($workOrder->approvalBasisLabel()) . ')' : '' }}. Next: send the work order to {{ $nextContractor }}.</div>
            @else
                <div>{{ $proceed->note }}</div>
            @endif
        </div>
    @endif

    {{-- W2/W6 (Johan, 8 Oct 2026) - the appointment for the repair. The agent normally coordinates it with the tenant; the tenant is
         emailed when it is set or changed, and sees it (with who is doing the work and the progress) on their portal. --}}
    @if($isOpen)
    @permission('rental_work_orders.create')
    @php
        $tzApp = $workOrder->agency?->outreachTimezone() ?: config('app.timezone');
        $apptValue = $workOrder->appointment_at?->copy()->setTimezone($tzApp)->format('Y-m-d\TH:i');
    @endphp
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);" id="appointment-card">
        <h2 class="text-sm font-semibold">Appointment <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; {{ $workOrder->stageLabel('agent') }}</span></h2>
        {{-- Johan, 9 Oct 2026: an appointment (and the email to the tenant, which names the contractor) only once the job is approved - within the
             owner's no-approval limit, approved by the owner, or an emergency. Before that the box is not shown. --}}
        @if($proceed->authorised || in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_ORDERED, \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_DISPUTED], true))
        <form method="POST" action="{{ route('corex.rental-work-orders.appointment.store', $workOrder) }}" class="grid grid-cols-3 gap-3 items-end">
            @csrf
            <div>
                <label class="text-xs font-medium">Date and time</label>
                <input type="datetime-local" name="appointment_at" required value="{{ old('appointment_at', $apptValue) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Note for the tenant (optional)</label>
                <input type="text" name="appointment_note" maxlength="500" value="{{ old('appointment_note', $workOrder->appointment_note) }}" placeholder="e.g. the plumber will call 30 minutes before" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div><button type="submit" class="corex-btn-primary text-xs">{{ $workOrder->appointment_at ? 'Change appointment' : 'Set appointment' }}</button></div>
        </form>
        <p class="text-xs" style="color: var(--text-muted);">The tenant is emailed when you set or change this. The owner can also set it from their portal.</p>
        @else
            <p class="text-xs" style="color: var(--text-muted);" data-appointment-locked>An appointment can be set once the job is approved &mdash; the tenant is not told about a date or a contractor before then. {{ $proceed->note }}</p>
        @endif
        @if($workOrder->isOwnerContractor())
            <form method="POST" action="{{ route('corex.rental-work-orders.owner-contractor.update', $workOrder) }}" class="grid grid-cols-3 gap-3 items-end pt-2">
                @csrf
                @method('PUT')
                <div>
                    <label class="text-xs font-medium">Owner's contractor &mdash; name</label>
                    <input type="text" name="contractor_name" maxlength="191" value="{{ old('contractor_name', $workOrder->contractor_name) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Phone</label>
                    <input type="text" name="contractor_phone" maxlength="40" value="{{ old('contractor_phone', $workOrder->contractor_phone) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div><button type="submit" class="corex-btn-outline text-xs">Save contractor details</button></div>
            </form>
        @endif
    </div>
    @endpermission
    @endif

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
            <div class="col-span-2"><span style="color: var(--text-muted);">Description:</span> {{ $workOrder->description }}</div>
            <div><span style="color: var(--text-muted);">Tenancy:</span> {{ $workOrder->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
            <div><span style="color: var(--text-muted);">Item:</span> {{ $workOrder->inspectionItem?->label ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Trade:</span> {{ $workOrder->trade_type ? ucfirst($workOrder->trade_type) : '—' }}</div>
            {{-- AT-442 req #1 — "who does the work" is the first choice on every work order. --}}
            <div><span style="color: var(--text-muted);">Who does the work:</span> {{ $workOrder->whoLabel() }}</div>
            @if($workOrder->isOwnerContractor())
            <div><span style="color: var(--text-muted);">Owner's contractor:</span> {{ $workOrder->contractor_name ?: 'name not given' }}{{ $workOrder->contractor_phone ? ' - ' . $workOrder->contractor_phone : '' }}</div>
            @endif
            @if($workOrder->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER)
            <div><span style="color: var(--text-muted);">Supplier:</span> {{ $workOrder->contractorLabel() ?? '—' }}@if(! $workOrder->supplier && $workOrder->contractorLabel()) <span class="text-xs" style="color: var(--text-muted);">(from the selected quote - assigned when the work order is sent)</span>@endif</div>
            @endif
            <div><span style="color: var(--text-muted);">Appointment:</span> {{ $workOrder->appointment_at ? $workOrder->appointment_at->copy()->setTimezone($workOrder->agency?->outreachTimezone() ?: config('app.timezone'))->format('D j M Y H:i') . ($workOrder->appointment_note ? ' - ' . $workOrder->appointment_note : '') : 'Not booked yet' }}</div>
            <div><span style="color: var(--text-muted);">Reported by:</span> {{ ucfirst(str_replace('_', ' ', $workOrder->reported_by_type)) }}</div>
            @if($workOrder->reportedFaultReport)
                <div><span style="color: var(--text-muted);">From fault report:</span> @feature('rental-faults')<a href="{{ route('corex.rental-fault-reports.show', $workOrder->reported_fault_report_id) }}" class="underline">#{{ $workOrder->reported_fault_report_id }}</a>@else #{{ $workOrder->reported_fault_report_id }} @endfeature</div>
            @endif
            {{-- §15 (AT-447) — "From inspection <type> <date>" back-link. --}}
            @if($workOrder->reportedInspectionObservation?->inspection)
                <div><span style="color: var(--text-muted);">From inspection:</span> @feature('rental-inspections')<a href="{{ route('corex.rental-inspections.show', $workOrder->reportedInspectionObservation->inspection) }}" class="underline">{{ ucfirst(str_replace('_', '-', $workOrder->reportedInspectionObservation->inspection->type)) }}-inspection {{ $workOrder->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $workOrder->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }}</a>@else {{ ucfirst(str_replace('_', '-', $workOrder->reportedInspectionObservation->inspection->type)) }}-inspection {{ $workOrder->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $workOrder->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }} @endfeature</div>
            @endif
            <div><span style="color: var(--text-muted);">Reported at:</span> {{ $workOrder->reported_at?->format('Y-m-d H:i') }}</div>
            @if($workOrder->owner_approval_status !== \App\Models\RentalWorkOrder::APPROVAL_NOT_REQUIRED)
                <div><span style="color: var(--text-muted);">Owner approval:</span> {{ ucfirst($workOrder->owner_approval_status) }}</div>
            @endif
            @if($workOrder->ordered_at)
                <div><span style="color: var(--text-muted);">{{ \App\Models\RentalWorkOrder::statusWord('ordered') }}:</span> {{ $workOrder->ordered_at->format('Y-m-d') }}</div>
            @endif
            @if($workOrder->completed_at)
                <div><span style="color: var(--text-muted);">Completed:</span> {{ $workOrder->completed_at->format('Y-m-d') }}</div>
                <div><span style="color: var(--text-muted);">Paid by:</span> {{ ucfirst(str_replace('_', ' ', $workOrder->paid_by)) }}</div>
                @if($workOrder->cost_amount)
                    <div><span style="color: var(--text-muted);">Cost:</span> R{{ number_format((float) $workOrder->cost_amount, 2) }}</div>
                @endif
            @endif
        </div>

        @permission('rental_work_orders.create')
        @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
        <div x-show="!editing" class="pt-1">
            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        </div>
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-work-orders.update', $workOrder) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="text-xs font-medium">Title</label>
                <input type="text" name="title" required maxlength="191" value="{{ old('title', $workOrder->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Description</label>
                <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('description', $workOrder->description) }}</textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endif
        @endpermission

        @if($workOrder->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $workOrder->cancelled_at?->format('Y-m-d') }} by {{ $workOrder->cancelledByUser?->name }}: {{ $workOrder->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_work_orders.cancel')
                @if($isOpen)
                    <button type="button" onclick="document.getElementById('cancel-work-order-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel work order</button>
                @endif
            @endpermission
            @permission('rental_work_orders.create')
                @if($workOrder->isDeletable() && $workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-work-orders.destroy', $workOrder) }}" onsubmit="return confirm('Archive this work order?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.cancel', $workOrder) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>

    {{-- AT-442 req #8 — the job card inline when internal; nothing else on
         this page duplicates its own tasks/lines/sign-off, which live on
         its own full screen. --}}
    @if($workOrder->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL)
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Job card</h2>
        @if($jobCard = $workOrder->jobCard)
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div><span style="color: var(--text-muted);">Status:</span> {{ ucfirst(str_replace('_', ' ', $jobCard->status)) }}</div>
                <div><span style="color: var(--text-muted);">Crew:</span> {{ $jobCard->crew?->name ?? ($jobCard->assigned_user_id ? 'Previously assigned: ' . ($jobCard->assignedUser?->name ?? '—') : '—') }}</div>
                <div><span style="color: var(--text-muted);">Scheduled:</span> {{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</div>
                <div><span style="color: var(--text-muted);">Total:</span> {{ $jobCard->total_amount !== null ? 'R' . number_format((float) $jobCard->total_amount, 2) : '—' }}</div>
            </div>
            @feature('rental-job-cards')
            <a href="{{ route('corex.rental-job-cards.show', $jobCard) }}" class="corex-btn-primary text-xs">Open job card</a>
            @endfeature
        @else
            <p class="text-xs" style="color: var(--text-muted);">No job card found for this work order.</p>
        @endif
    </div>
    @endif

    @if($isOpen && $workOrder->assignment_type !== \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL)
    {{-- §3.4c — the value the approval-limit gate rides on.
         AT-442 req #8 — shown only for the outside-supplier path; an
         internal job card's own quote-to-owner lives on its own screen. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Quotes</h2>
        <p class="text-xs" style="color: var(--text-muted);">No-approval spend limit for this property: R{{ number_format($noApprovalThreshold, 2) }}. Select a quote at or under this and it's approved automatically; over it, owner approval is required below.</p>
        @if($workOrder->quotes->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No quotes captured yet.</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach($workOrder->quotes as $quote)
                    <li class="flex items-center justify-between gap-2">
                        <span>
                            {{-- BUILD 2 (§17.9.1a) — the agency's fee on an outside contractor's quote. The owner (and everyone without the see-costs permission) sees one figure:
                                 the owner-facing total. Staff who can see costs also see the contractor's own quote and the fee, which is the agency's margin. --}}
                            @if((float) $quote->fee_amount > 0 && $canSeeFee)
                                {{ $quote->supplier?->name ?? 'Unknown supplier' }} — quote R{{ number_format((float) $quote->amount, 2) }} + fee R{{ number_format((float) $quote->fee_amount, 2) }} = <strong>R{{ number_format($quote->ownerFacingAmount(), 2) }}</strong> to the owner
                            @else
                                {{ $quote->supplier?->name ?? 'Unknown supplier' }} — R{{ number_format($quote->ownerFacingAmount(), 2) }}
                            @endif
                            <span style="color: var(--text-muted);">({{ $quote->quote_date?->format('Y-m-d') }})</span>
                            @if($quote->is_selected)
                                <span class="ds-badge ds-badge-success">Selected</span>
                            @endif
                            @if($quote->document_storage_path)
                                <a href="{{ route('corex.rental-work-orders.quotes.download', [$workOrder, $quote]) }}" class="underline text-xs">Document</a>
                            @endif
                            @if($quote->detail_text)
                                <span class="text-xs" style="color: var(--text-muted);">— {{ $quote->detail_text }}</span>
                            @endif
                        </span>
                        @permission('rental_work_orders.manage_quotes')
                        <span class="flex items-center gap-2">
                            @unless($quote->is_selected)
                                <form method="POST" action="{{ route('corex.rental-work-orders.quotes.select', [$workOrder, $quote]) }}">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">Select</button>
                                </form>
                            @endunless
                            <button type="button" onclick="document.getElementById('edit-quote-form-{{ $quote->id }}').classList.toggle('hidden')" class="corex-btn-outline text-xs">Edit</button>
                            <form method="POST" action="{{ route('corex.rental-work-orders.quotes.destroy', [$workOrder, $quote]) }}" onsubmit="return confirm('Archive this quote?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        </span>
                        @endpermission
                    </li>
                    @permission('rental_work_orders.manage_quotes')
                    <li id="edit-quote-form-{{ $quote->id }}" class="hidden">
                        <form method="POST" action="{{ route('corex.rental-work-orders.quotes.update', [$workOrder, $quote]) }}" enctype="multipart/form-data" class="space-y-2 pt-1">
                            @csrf
                            @method('PUT')
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs">Supplier</label><br>
                                    <select name="agency_service_provider_id" required class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                                        @foreach(\App\Models\DealV2\AgencyServiceProvider::active()->maintenanceContractors()->pickerOrder()->get() as $provider)
                                            <option value="{{ $provider->id }}" @selected($quote->agency_service_provider_id === $provider->id)>{{ $provider->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs">Amount (R)</label>
                                    <input type="number" name="amount" required min="0" step="0.01" value="{{ $quote->amount }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                                </div>
                                <div>
                                    <label class="text-xs">Quote date</label>
                                    <input type="date" name="quote_date" required value="{{ $quote->quote_date?->format('Y-m-d') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                                </div>
                                <div>
                                    <label class="text-xs">Replace document (optional)</label>
                                    <input type="file" name="document" accept=".pdf,image/*" class="w-full text-xs mt-1">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-xs">Details</label>
                                    <textarea name="detail_text" rows="2" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ $quote->detail_text }}</textarea>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                                <button type="button" onclick="document.getElementById('edit-quote-form-{{ $quote->id }}').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel</button>
                            </div>
                        </form>
                    </li>
                    @endpermission
                @endforeach
            </ul>
        @endif
        @permission('rental_work_orders.manage_quotes')
        @if($archivedQuotes->isNotEmpty())
            <button type="button" onclick="document.getElementById('archived-quotes').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedQuotes->count() }} archived quote(s)</button>
            <ul id="archived-quotes" class="hidden space-y-1 text-sm pt-1">
                @foreach($archivedQuotes as $archived)
                    <li class="flex items-center justify-between gap-2">
                        <span style="color: var(--text-muted);">{{ $archived->supplier?->name ?? 'Unknown supplier' }} — R{{ number_format((float) $archived->amount, 2) }} ({{ $archived->quote_date?->format('Y-m-d') }})</span>
                        <form method="POST" action="{{ route('corex.rental-work-orders.quotes.restore', [$workOrder, $archived->id]) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('corex.rental-work-orders.quotes.store', $workOrder) }}" enctype="multipart/form-data" class="space-y-2 pt-2">
            @csrf
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="text-xs">Supplier</label><br>
                    <select name="agency_service_provider_id" required class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                        <option value="">Select…</option>
                        @foreach(\App\Models\DealV2\AgencyServiceProvider::active()->maintenanceContractors()->pickerOrder()->get() as $provider)
                            <option value="{{ $provider->id }}" @selected((int) old('agency_service_provider_id', $workOrder->agency_service_provider_id) === (int) $provider->id)>{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs">Amount (R)</label>
                    <input type="number" name="amount" required min="0" step="0.01" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs">Quote date</label>
                    <input type="date" name="quote_date" required class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs">Document (optional)</label>
                    <input type="file" name="document" accept=".pdf,image/*" class="w-full text-xs mt-1">
                </div>
                <div class="col-span-2">
                    <label class="text-xs">Details (optional — required if no document attached)</label>
                    <textarea name="detail_text" rows="2" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);"></textarea>
                </div>
                @if($selectedQuote)
                    <label class="flex items-center gap-2 text-xs col-span-2">
                        <input type="checkbox" name="is_selected" value="1">
                        Use this quote instead of the selected one
                    </label>
                @else
                    <p class="text-xs col-span-2" style="color: var(--text-muted);" data-quote-autoselect>This is the first quote, so it is selected automatically: within the property's no-approval limit (R{{ number_format($noApprovalThreshold, 2) }}) it is approved on the spot, above it the owner is asked.</p>
                @endif
            </div>
            <button type="submit" class="corex-btn-outline text-xs">Capture quote</button>
        </form>
        @endpermission
        {{-- BUILD 2 (§17.9.1a) — per-work-order override of the agency's fee on this contractor's quote; blank = the agency default.
             Locked once the owner has approved an amount (a change would be a variation). --}}
        @permission('rental_job_cards.price')
        @if($canSeeFee && !$workOrder->hasApprovedBaseline() && $workOrder->quotes->isNotEmpty())
        <details class="pt-2" data-fee-collapse><summary class="text-xs cursor-pointer" style="color: var(--text-muted);">Change the agency fee on this quote</summary>
        <form method="POST" action="{{ route('corex.rental-work-orders.external-fee.update', $workOrder) }}" class="flex flex-wrap items-end gap-2 pt-2">
            @csrf
            @method('PUT')
            <div>
                <label class="text-xs">Fee on the contractor's quote (this work order)</label><br>
                <select name="external_markup_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                    <option value="percent" @selected($externalFeeType === 'percent')>% of the quote</option>
                    <option value="amount" @selected($externalFeeType === 'amount')>Fixed amount (R)</option>
                </select>
                <input type="number" name="external_markup_value" min="0" step="0.01" value="{{ $workOrder->external_markup_value !== null ? $externalFeeValue : '' }}" placeholder="Agency default: {{ rtrim(rtrim(number_format(\App\Models\RentalWorkOrderSetting::externalQuoteMarkupValueFor($workOrder->agency_id), 2, '.', ''), '0'), '.') ?: '0' }}" class="rounded-md px-3 py-2 text-xs w-32" style="border: 1px solid var(--border);">
            </div>
            <button type="submit" class="corex-btn-outline text-xs">Save fee</button>
            <span class="text-xs" style="color: var(--text-muted);">Blank uses the agency default. The owner sees the total only.</span>
        </form>
        </details>
        @endif
        @endpermission
    </div>
    @endif

    {{-- §17.21.1 plug-in slots — one empty partial per build (Build 2 → _approval-panel: "why was this approved?",
         emergency approval, variation; Build 3 → _completion-panel: contractor reports done, tenant check, dispute).
         A build may move its include; it edits only its own partial. --}}
    {{-- §17.31 — the supplier's invoice documents (office only; share-with-owner decides what the owner sees). --}}
    @if($workOrder->status !== \App\Models\RentalWorkOrder::STATUS_REPORTED || $workOrder->invoices()->withTrashed()->exists())
        @include('corex.rental-work-orders._invoices-panel')
    @endif

    @include('corex.rental-work-orders._approval-panel')
    @include('corex.rental-work-orders._completion-panel')

    {{-- §3.4a — only for a work order raised directly (no upstream fault
         report already satisfied this). Applies to both assignment paths —
         an internal job card's quote still rides this same gate. --}}
    @if($isOpen && ($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING || $workOrder->approvals->isNotEmpty()))
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Owner approval</h2>
        @if($workOrder->approvals->isNotEmpty())
            {{-- 2026-09-22, Johan — an approval is otherwise an unanchored fact
                 ("approved", nothing saying for what). quote_amount_at_decision
                 shows what it was actually recorded against WHEN a snapshot
                 exists; older rows (recorded before this column existed) have
                 none, and correctly show nothing extra rather than implying a
                 zero or an unknown amount. The "superseded" flag compares by
                 id (quote_id_at_decision), never by amount/supplier text —
                 two different quotes could coincidentally match on those. One
                 line per approval, no separate section, per screen-space rule. --}}
            @php($currentQuoteId = optional($workOrder->quotes->firstWhere('is_selected', true))->id)
            <ul class="space-y-1 text-sm">
                @foreach($workOrder->approvals as $approval)
                    <li>
                        {{ ucfirst($approval->decision) }}@if($approval->quote_amount_at_decision !== null) — R{{ number_format((float) $approval->quote_amount_at_decision, 2) }} ({{ $approval->quote_supplier_name_at_decision ?? 'Unknown supplier' }})@endif
                        <span style="color: var(--text-muted);">({{ ucfirst(str_replace('_', ' ', $approval->evidence_type)) }}, {{ $approval->decided_at?->format('Y-m-d') }})</span>
                        @if($approval->quote_id_at_decision !== null && $approval->quote_id_at_decision !== $currentQuoteId)
                            <span style="color: var(--ds-crimson);">— superseded, a different quote is now selected</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @permission('rental_work_orders.record_approval')
        @if($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)
        <button type="button" onclick="document.getElementById('wo-approval-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Record decision on the owner's behalf</button>
        <form id="wo-approval-form" method="POST" action="{{ route('corex.rental-work-orders.approval.store', $workOrder) }}" class="hidden space-y-3 pt-2">
            @csrf
            <div>
                <label class="text-xs font-medium">Decision</label>
                <select name="decision" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="approved">Approved</option>
                    <option value="declined">Declined</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Evidence</label>
                <select name="evidence_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="whatsapp">WhatsApp reply</option>
                    <option value="email">Email</option>
                    <option value="verbal_note">Verbal (undocumented)</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">What the owner said</label>
                <textarea name="evidence_text" required rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save decision</button>
        </form>
        @endif
        @endpermission
    </div>
    @endif

    {{-- AT-442 req #8 — an internal job card has its own assign-crew/
         start/complete controls on its own screen; this section (assign a
         supplier, start, complete via paid_by) only applies to the
         outside-supplier path. --}}
    @if($isOpen && $workOrder->assignment_type !== \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL)
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Supplier</h2>
        {{-- BUILD 2 (§17.9.5) — replaces "Assign supplier" + the plain supplier mail: the work order goes to the contractor of the SELECTED quote,
             with the owner's approval shown on it. Enabled only once the owner's approval (or the no-approval limit, or an emergency agreement) covers it. --}}
        @permission('rental_work_orders.create')
        @if(!$selectedQuote)
            <p class="text-xs" style="color: var(--text-muted);">Capture the contractor's quote and select it first — the work order goes to that contractor once the owner has approved.</p>
        @else
            <form method="POST" action="{{ route('corex.rental-work-orders.assign-supplier', $workOrder) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="agency_service_provider_id" value="{{ $selectedQuote->agency_service_provider_id }}">
                <span class="text-xs">Contractor: <strong>{{ $selectedQuote->supplier?->name ?? 'Unknown supplier' }}</strong></span>
                <button type="submit" class="corex-btn-primary text-xs" @disabled(!$proceed->authorised)
                        @if(!$proceed->authorised) title="{{ $proceed->note }}" @endif>{{ $workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED ? 'Send work order to contractor' : 'Resend work order to contractor' }}</button>
            </form>
            @if(!$proceed->authorised)
                <p class="text-xs" style="color: var(--ds-crimson);">{{ $proceed->note }}</p>
            @endif
        @endif
        @endpermission

        <div class="flex gap-2 pt-2">
            @permission('rental_work_orders.create')
                @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_ORDERED)
                    <form method="POST" action="{{ route('corex.rental-work-orders.start-progress', $workOrder) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs" @disabled(!$proceed->authorised)>Mark in progress</button>
                    </form>
                @endif
            @endpermission
            @permission('rental_work_orders.complete')
                @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS)
                    <button type="button" onclick="document.getElementById('complete-work-order-form').classList.toggle('hidden')" class="corex-btn-primary text-xs" data-complete-work-order>Complete</button>
                @else
                    <span class="text-xs" style="color: var(--text-muted);" data-complete-later>Complete becomes available once the work is in progress.</span>
                @endif
            @endpermission
        </div>

        @permission('rental_work_orders.complete')
        <form id="complete-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.complete', $workOrder) }}" enctype="multipart/form-data" class="hidden space-y-3 pt-2">
            @csrf
            <div>
                <label class="text-xs font-medium">Paid by</label>
                <select name="paid_by" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="owner">Owner</option>
                    <option value="tenant">Tenant</option>
                    <option value="deposit_deduction">Deposit deduction (label only — §5.1a)</option>
                    <option value="not_yet_paid">Not yet paid</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Cost (R, optional)</label>
                {{-- Inline one-line form on purpose: a block-style php tag here would pair with the earlier one-line tag and swallow the page between. --}}
                @php($suggestedInvoiceCost = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class)->suggestedCost($workOrder))
                <input type="number" name="cost_amount" step="0.01" min="0" value="{{ old('cost_amount', $suggestedInvoiceCost !== null ? number_format($suggestedInvoiceCost, 2, '.', '') : '') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                @if($suggestedInvoiceCost !== null)
                    <p class="text-xs mt-1" style="color: var(--text-muted);" data-cost-from-invoices>Filled in from the supplier invoices filed on this work order (R{{ number_format($suggestedInvoiceCost, 2) }}) — change it if the final cost is different.</p>
                @endif
            </div>
            <div>
                <label class="text-xs font-medium">Completion notes</label>
                <textarea name="completion_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
            </div>
            @if($completionRequiresPhoto)
                <p class="text-xs" style="color: var(--text-muted);">A "completed" photo is required below before this can be saved. Your agency has this switched on in settings.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">A "completed" photo below is optional — add one if it's useful evidence, but not every repair has a meaningful photo to take.</p>
            @endif
            <button type="submit" class="corex-btn-primary text-xs">Mark complete</button>
        </form>
        @endpermission
    </div>
    @endif

    <div id="wo-photos" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Photos</h2>
        @if(session('wo_photo_message'))
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('wo_photo_message') }}</div>
        @endif
        @if($errors->photo->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                @foreach($errors->photo->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
        @if($workOrder->photos->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
        @else
            <div class="grid grid-cols-4 gap-2">
                @foreach($workOrder->photos as $photo)
                    <div>
                        <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                        <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        @permission('rental_work_orders.create')
        <form method="POST" action="{{ route('corex.rental-work-orders.photos.store', $workOrder) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
            @csrf
            <select name="photo_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="reported">Reported (before)</option>
                <option value="in_progress">In progress</option>
                <option value="completed">Completed (after)</option>
            </select>
            <input type="file" name="photo" accept="image/*" required class="text-xs">
            <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
        </form>
        @endpermission
    </div>

    {{-- Johan, 2026-09-22 — "who did what": a plain chronological history,
         not a status badge on every row. RentalWorkOrder::history() merges
         creation, every logged update, and every approval decision into one
         timeline, oldest first. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">History</h2>
        <ul class="space-y-1 text-sm">
            @foreach($workOrder->history() as $entry)
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
        @permission('rental_work_orders.create')
        <form method="POST" action="{{ route('corex.rental-work-orders.notes.store', $workOrder) }}" class="flex items-end gap-2">
            @csrf
            <textarea name="note" required rows="1" placeholder="Add a note" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs">Add</button>
        </form>
        @endpermission
    </div>
</div>
@endsection
