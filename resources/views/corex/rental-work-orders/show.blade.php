@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3/§3.4 + .ai/specs/rentals-faults-work-orders.md section 24 - the work order detail screen.
     Top to bottom: header, "Where this job is" strip, the "Next" bar, two summary cards (the job / the money), then the working sections as collapsible panels
     (only the one for the current step is open), then Edit / Archive / Cancel, small, at the very bottom. Layout only: every form below posts to the same route it always did. --}}

@php
    $statusBadgeClass = match ($workOrder->status) {
        'completed' => 'ds-badge-success',
        'reported', 'ordered', 'in_progress' => 'ds-badge-info',
        'cancelled', 'disputed' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    $isOpen = !in_array($workOrder->status, ['completed', 'cancelled'], true);
    // L2 (Johan, 9 Oct 2026): the work is reported finished (owner / contractor / crew) and waits for the AGENT to check and close it.
    $woFinishedRound = app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($workOrder) ? $workOrder->latestCompletionRound() : null;
    $woReportedFinished = $woFinishedRound !== null;
    $isInternal = $workOrder->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;
    $isOutside = $workOrder->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER;
    $woUser = auth()->user();
    $woCan = [
        'create' => $woUser->hasPermission('rental_work_orders.create'),
        'record_approval' => $woUser->hasPermission('rental_work_orders.record_approval'),
        'complete' => $woUser->hasPermission('rental_work_orders.complete'),
        'manage_completion' => $woUser->hasPermission('rental_work_orders.manage_completion'),
    ];
    $js = $contractorStatus;
    $screen = app(\App\Services\Rentals\RentalWorkOrderScreen::class)->build($workOrder, [
        'proceed' => $proceed, 'selectedQuote' => $selectedQuote, 'costActive' => $costActive, 'costRepair' => $costRepair, 'costMeasure' => $costMeasure,
        'js' => $js, 'finishedRound' => $woFinishedRound, 'can' => $woCan,
    ]);
    $woNext = $screen['next'];
    $woPanels = $screen['panels'];
    $woTz = $screen['tz'];
    $fmtDt = fn ($d) => $d ? \App\Support\AppDate::dateTime($d, $woTz) : '';
    $money = fn ($n) => \App\Services\Rentals\RentalWorkOrderScreen::money($n);
    $woFault = app(\App\Services\Rentals\RentalWhoPaysService::class)->faultFor($workOrder);
    $woRuling = $woFault ? app(\App\Services\Rentals\RentalWhoPaysService::class)->currentRuling($workOrder) : null;
    $openKey = $woNext['panel'] ?? 'history';
    if ($errors->has('cost_decision') || $errors->has('cost_owner_decision')) { $openKey = 'cost'; }
    elseif ($errors->has('invoice') || $errors->has('document')) { $openKey = 'invoices'; }
    elseif ($errors->has('who_pays')) { $openKey = 'whopays'; }
    elseif ($errors->has('completion') || $errors->has('rental_work_order')) { $openKey = 'completion'; }
    elseif ($errors->photo->any() || session('wo_photo_message')) { $openKey = 'photos'; }
    // the panel that holds a contractor / job card slot is the same slot for the strip's purposes
    if ($openKey === 'jobcard' && ! $isInternal) { $openKey = 'contractor'; }
    $isOpenPanel = fn (string $k) => $openKey === $k || ($k === 'jobcard' && $openKey === 'contractor' && $isInternal);
    $panelHref = fn (string $p) => $p === 'cost' ? '#cost-decision' : ($p === 'whopays' ? '#who-pays' : '#wo-panel-' . $p);
    $tenantContacts = $workOrder->lease ? $workOrder->lease->tenants->map(fn ($t) => $t->contact)->filter() : collect();
    $apptText = $workOrder->appointment_at ? \App\Support\AppDate::dateTime($workOrder->appointment_at, $woTz) . ($workOrder->appointment_note ? ' - ' . $workOrder->appointment_note : '') : 'Not booked';
    $woProv = $js['provider'] ?? null;
    $woCt = $js['contact'] ?? ['email' => null, 'phone' => null, 'has_contact' => false];
    $goAheadAt = $workOrder->updates->where('update_type', 'work_order_sent')->filter(fn ($u) => ! str_contains((string) $u->note, 'could not'))->sortByDesc('created_at')->first()?->created_at;
    $woOwnerSettings = $workOrder->property ? \App\Services\Rentals\RentalOwnerFaultSettings::for($workOrder->property) : null;
    $invoiceCount = $workOrder->invoices()->count();
@endphp

@section('content')
<style>
    .wo-strip { display: flex; list-style: none; margin: 0; padding: 12px 8px 10px; overflow-x: auto; gap: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
    .wo-step { flex: 1 0 auto; min-width: 104px; position: relative; text-align: center; padding: 0 6px; font-size: 12px; line-height: 1.25; }
    .wo-step + .wo-step::before { content: ''; position: absolute; top: 11px; left: -50%; width: 100%; height: 2px; background: var(--border); }
    .wo-step[data-state="done"] + .wo-step::before, .wo-step[data-state="done"] + .wo-step[data-state="current"]::before { background: var(--ds-green, #16a34a); }
    .wo-dot { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--surface); border: 2px solid var(--border); color: var(--text-muted); }
    .wo-step[data-state="done"] .wo-dot { background: var(--ds-green, #16a34a); border-color: var(--ds-green, #16a34a); color: #fff; }
    .wo-step[data-state="current"] .wo-dot { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, transparent); }
    .wo-step-label { display: block; margin-top: 4px; font-weight: 600; color: var(--text-primary); }
    .wo-step[data-state="todo"] .wo-step-label { font-weight: 500; color: var(--text-muted); opacity: .75; }
    .wo-step[data-state="current"] .wo-step-label { color: var(--brand-icon, #0ea5e9); }
    .wo-step-at { display: block; color: var(--text-muted); font-size: 11px; }
    .wo-next { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); border-left-width: 5px; background: var(--surface); }
    .wo-next-info { border-left-color: var(--brand-icon, #0ea5e9); }
    .wo-next-warn { border-left-color: var(--ds-amber, #f59e0b); }
    .wo-next-danger { border-left-color: var(--ds-crimson, #b3261e); }
    .wo-next-ok { border-left-color: var(--ds-green, #16a34a); }
    .wo-next-muted { border-left-color: var(--text-muted); }
    .wo-next-text { flex: 1 1 320px; font-size: 14px; }
    .wo-next-label { display: inline-block; margin-right: 8px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
    .wo-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .wo-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; }
    .wo-card h2 { font-size: 13px; font-weight: 700; margin: 0 0 8px; }
    .wo-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; font-size: 13px; margin: 0; }
    .wo-facts dt { color: var(--text-muted); }
    .wo-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .wo-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
    .wo-panel-head { display: flex; align-items: center; gap: 10px; padding: 11px 16px; cursor: pointer; list-style: none; }
    .wo-panel-head::-webkit-details-marker { display: none; }
    .wo-chev { width: 8px; height: 8px; border-right: 2px solid var(--text-muted); border-bottom: 2px solid var(--text-muted); transform: rotate(-45deg); transition: transform .12s; flex: none; }
    .wo-panel[open] > .wo-panel-head .wo-chev { transform: rotate(45deg); }
    .wo-panel-title { font-size: 14px; font-weight: 700; flex: none; }
    .wo-panel-sum { font-size: 12px; color: var(--text-muted); min-width: 0; flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wo-panel[open] > .wo-panel-head { border-bottom: 1px solid var(--border); }
    .wo-panel-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }
    .wo-panel-body > div, .wo-panel-body > [id] { border: 0 !important; padding: 0 !important; background: transparent !important; border-radius: 0 !important; }
    .wo-panel-body > * + * { border-top: 1px solid var(--border) !important; padding-top: 12px !important; }
    .wo-panel-body .wo-embedded-title { display: none; }
    .wo-btnrow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .wo-btnrow .corex-btn-primary, .wo-btnrow .corex-btn-outline, .wo-next .corex-btn-primary, .wo-next .corex-btn-outline, .wo-foot .corex-btn-outline { min-height: 34px; display: inline-flex; align-items: center; justify-content: center; }
    .wo-appt-form { display: grid; grid-template-columns: minmax(440px, 2.4fr) minmax(170px, 1fr) auto; gap: 12px; align-items: end; }
    @media (max-width: 760px) { .wo-appt-form { grid-template-columns: 1fr; } }
    .wo-foot { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding-top: 4px; }
    @media (max-width: 900px) { .wo-cards { grid-template-columns: 1fr; } }
    @media (max-width: 600px) {
        .wo-panel-head { flex-wrap: wrap; } .wo-panel-sum { flex-basis: 100%; white-space: normal; padding-left: 18px; }
        .wo-facts { grid-template-columns: 1fr; gap: 0; } .wo-facts dt { margin-top: 6px; }
        .wo-next .corex-btn-primary, .wo-next .corex-btn-outline { width: 100%; }
    }
</style>
<div class="p-4 md:p-6 max-w-7xl mx-auto space-y-3 cx-equal-btns" data-wo-screen>
    {{-- (success and warning messages are the layout's toasts - J8: not repeated here as a second banner) --}}
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @include('partials.paused-by-owner-banner', ['pauseSubject' => $workOrder])

    {{-- A. header --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">{{ $workOrder->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}" title="{{ $workOrder->statusLabel() }}">{{ $workOrder->stageLabel('agent') }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $workOrder->property?->trashed() ? ' (archived)' : '' }}{{ $workOrder->trashed() ? ' - this work order is archived' : '' }}</span>
        </div>
        @include('partials.header-actions-style')
        <div class="flex flex-wrap items-center gap-2" data-header-actions>
            <a href="{{ route('corex.rental-work-orders.pdf', $workOrder) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">&larr; All work orders</a>
        </div>
    </div>

    {{-- AT-439 Part 3, item 2 - the shared rental context bar. --}}
    <x-rental-context-bar :property="$workOrder->property" :lease="$workOrder->lease" current="work_orders" />

    {{-- B. where this job is --}}
    <ol class="wo-strip" data-wo-strip aria-label="Where this job is">
        @foreach($screen['steps'] as $st)
            <li class="wo-step" data-step="{{ $st['key'] }}" data-state="{{ $st['state'] }}" @if($st['state'] === 'current') aria-current="step" @endif>
                <span class="wo-dot">@if($st['state'] === 'done')&#10003;@elseif($st['state'] === 'current')&#9679;@else&nbsp;@endif</span>
                <span class="wo-step-label">{{ $st['label'] }}</span>
                @if($st['at'])<span class="wo-step-at">{{ $st['at'] }}</span>@endif
            </li>
        @endforeach
    </ol>

    {{-- C. next --}}
    @php $nb = $woNext['button'] ?? null; @endphp
    <div class="wo-next wo-next-{{ $woNext['tone'] }}" data-next-step data-next="{{ $woNext['key'] }}"
         @if($woNext['key'] === 'owner_declined') data-quote-declined @endif
         @if($woNext['key'] === 'waiting_owner') data-quote-sent-to-owner @endif
         @if($woNext['key'] === 'quote_not_selected') data-quote-not-selected @endif
         @if(in_array($woNext['key'], ['quote_inside_limit', 'cost_inside_limit'], true)) data-cost-needed @endif
         @if($woNext['key'] === 'reported_finished') data-reported-finished-step @endif
         @if($woNext['key'] === 'owner_arranges') data-owner-route @endif>
        <div class="wo-next-text"><span class="wo-next-label">Next</span><span data-next-text>{{ $woNext['text'] }}</span></div>
        @if($nb)
            @if($nb['kind'] === 'form')
                <button type="submit" form="{{ $nb['form'] }}" class="corex-btn-primary text-xs" data-next-button>{{ $nb['label'] }}</button>
            @elseif($nb['kind'] === 'link')
                <a href="{{ $nb['href'] }}" class="corex-btn-primary text-xs" data-next-button>{{ $nb['label'] }}</a>
            @else
                <a href="{{ $panelHref($nb['panel']) }}" class="corex-btn-primary text-xs" data-wo-go="{{ $nb['panel'] }}" @if($nb['click']) data-wo-click="{{ $nb['click'] }}" @endif data-next-button @if($nb['panel'] === 'cost') data-go-to-cost @endif>{{ $nb['label'] }}</a>
            @endif
        @endif
    </div>

    {{-- D. the two summary cards: facts only --}}
    <div class="wo-cards">
        <div class="wo-card" data-card-job>
            <h2>The job</h2>
            <dl class="wo-facts">
                <dt>Description</dt><dd>{{ $workOrder->description }}</dd>
                <dt>Tenant</dt>
                <dd>@if($tenantContacts->isEmpty()){{ $workOrder->lease ? 'No tenant linked' : 'None - vacancy period' }}@else
                    @foreach($tenantContacts as $tc){{ $tc->full_name }}{{ $tc->phone ? ' - ' . $tc->phone : '' }}@if(! $loop->last)<br>@endif @endforeach
                @endif</dd>
                <dt>Contractor</dt>
                <dd>
                    @if($isInternal)
                        Our maintenance team{{ $workOrder->jobCard?->crew?->name ? ' - ' . $workOrder->jobCard->crew->name : '' }}
                    @elseif($workOrder->isOwnerContractor())
                        Owner arranges: {{ $workOrder->contractor_name ?: 'name not given' }}{{ $workOrder->contractor_phone ? ' - ' . $workOrder->contractor_phone : '' }}
                    @elseif($woProv)
                        {{ $woProv->name }}{{ $woCt['phone'] ? ' - ' . $woCt['phone'] : '' }}{{ $woCt['email'] ? ' - ' . $woCt['email'] : '' }}
                        <br><span data-supplier-callout-fact>Call-out fee: @if($woProv->calloutFeeStatus() === 'yes'){{ \App\Models\DealV2\AgencyServiceProvider::formatRand($woProv->calloutFeeAmount()) }}@if($woProv->calloutFeeNote()) ({{ $woProv->calloutFeeNote() }})@endif @elseif($woProv->calloutFeeStatus() === 'no')none @else not known @endif</span>
                    @elseif($workOrder->contractorLabel())
                        {{ $workOrder->contractorLabel() }} (from the selected quote)
                    @else
                        Not chosen yet
                    @endif
                </dd>
                <dt>Appointment</dt><dd>{{ $apptText }}</dd>
                <dt>From</dt>
                <dd>
                    @if($workOrder->reportedFaultReport)
                        @feature('rental-faults')<a href="{{ route('corex.rental-fault-reports.show', $workOrder->reported_fault_report_id) }}" class="underline">Fault #{{ $workOrder->reported_fault_report_id }}</a>@else Fault #{{ $workOrder->reported_fault_report_id }} @endfeature
                    @elseif($workOrder->reportedInspectionObservation?->inspection)
                        @php $woInsp = $workOrder->reportedInspectionObservation->inspection; @endphp
                        @feature('rental-inspections')<a href="{{ route('corex.rental-inspections.show', $woInsp) }}" class="underline">{{ ucfirst(str_replace('_', '-', $woInsp->type)) }}-inspection {{ \App\Support\AppDate::day($woInsp->scheduled_for ?? $woInsp->created_at, $woTz) }}</a>@else {{ ucfirst(str_replace('_', '-', $woInsp->type)) }}-inspection @endfeature
                    @else
                        {{ ucfirst(str_replace('_', ' ', $workOrder->reported_by_type)) }}
                    @endif
                </dd>
                <dt>Reported</dt><dd>{{ $fmtDt($workOrder->reported_at) }}</dd>
                @if($workOrder->trade_type)<dt>Trade</dt><dd>{{ ucfirst($workOrder->trade_type) }}</dd>@endif
                @if($workOrder->inspectionItem?->label)<dt>Item</dt><dd>{{ $workOrder->inspectionItem->label }}</dd>@endif
            </dl>
        </div>

        <div class="wo-card" data-card-money>
            <h2>The money</h2>
            <dl class="wo-facts">
                @if($selectedQuote && $isOutside)
                    <dt>Quote</dt>
                    <dd data-money-quote>
                        @if((float) $selectedQuote->fee_amount > 0 && $canSeeFee)
                            <strong>{{ $money($selectedQuote->ownerFacingAmount()) }}</strong> to the owner ({{ $money($selectedQuote->amount) }} + fee {{ $money($selectedQuote->fee_amount) }})
                        @else
                            <strong>{{ $money($selectedQuote->ownerFacingAmount()) }}</strong>
                        @endif
                        - {{ $selectedQuote->supplier?->name ?? 'the contractor' }}, {{ \App\Support\AppDate::day($selectedQuote->quote_date, $woTz) }}
                        @if($selectedQuote->document_storage_path) &middot; <a href="{{ route('corex.rental-work-orders.quotes.download', [$workOrder, $selectedQuote]) }}" class="underline">Document</a>@endif
                    </dd>
                @elseif($isInternal && ($costMeasure['source'] ?? '') === 'crew')
                    <dt>Crew price</dt><dd data-money-quote><strong>{{ $money($costMeasure['amount']) }}</strong></dd>
                @endif
                @php $authSvc = app(\App\Services\Rentals\RentalAuthorisationService::class); @endphp
                @if($costActive && $costActive->isAuthorised())
                    <dt>Authorised</dt>
                    <dd data-money-authorised><strong>{{ $money($costActive->amount_up_to ?? $workOrder->approved_amount ?? $selectedQuote?->ownerFacingAmount() ?? 0) }}</strong> {{ lcfirst(preg_replace('/^Authorised /', '', $authSvc->recordTextFor($costActive))) }}</dd>
                @elseif($costActive)
                    <dt>Owner's answer</dt>
                    <dd data-money-authorised>{{ $authSvc->recordTextFor($costActive) }}</dd>
                @elseif($proceed->authorised && $workOrder->approved_amount !== null)
                    <dt>Authorised</dt>
                    <dd data-money-authorised><strong>{{ $money($workOrder->approved_amount) }}</strong> &mdash; {{ $workOrder->approvalBasisLabel() ?? 'approved' }} (before authorisation records were kept)</dd>
                @else
                    <dt>Authorised</dt>
                    <dd data-money-authorised>
                        @if($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING)Waiting for the owner
                        @elseif($workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_DECLINED)The owner declined
                        @else Not authorised yet @endif
                    </dd>
                @endif
                @if($woOwnerSettings)
                    @php
                        $cdRepair = $woOwnerSettings->repairLimit();
                        $cdEmerg = $woOwnerSettings->emergencyLimit();
                        $limitStatus = match ($woOwnerSettings->status()) {
                            'confirmed_by_owner' => 'confirmed by the owner',
                            'captured_by_agent' => 'recorded by ' . ($woOwnerSettings->capturedBy() ?: 'an agent') . ' - not yet confirmed by the owner',
                            'disputed' => 'the owner does not agree',
                            default => 'not set yet',
                        };
                    @endphp
                    <dt>Owner's limits</dt>
                    <dd data-cost-owner-limits>
                        <span data-limit-repair>Repairs: {{ $cdRepair !== null ? 'up to ' . $money($cdRepair) : 'not set - ask the owner' }}</span><br>
                        <span data-limit-emergency>Emergencies: {{ $cdEmerg !== null ? 'up to ' . $money($cdEmerg) : 'not set - ask the owner' }}</span><br>
                        <span class="text-xs" style="color: var(--text-muted);" data-limit-status>{{ ucfirst($limitStatus) }}
                            @if($workOrder->property_id) &middot; <a class="underline" href="{{ route('corex.properties.show', ['property' => $workOrder->property_id, 'tab' => 'rental']) }}#owner-fault-settings-panel" data-owner-fault-settings-link>Owner's fault settings</a>@endif</span>
                    </dd>
                @endif
                @if($workOrder->openVariation())
                    <dt>Extra work</dt><dd>{{ $money($workOrder->openVariation()->extra_amount) }} waiting for the owner (new total {{ $money($workOrder->openVariation()->new_total) }})</dd>
                @endif
                @if($workOrder->cost_amount)
                    <dt>Final cost</dt><dd data-money-final><strong>{{ $money($workOrder->cost_amount) }}</strong>{{ $invoiceCount > 0 ? ' &middot; ' . $invoiceCount . ($invoiceCount === 1 ? ' invoice' : ' invoices') . ' filed' : '' }}</dd>
                @elseif($invoiceCount > 0)
                    <dt>Invoices</dt><dd>{{ $invoiceCount }} filed, {{ $money(app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class)->total($workOrder)) }}</dd>
                @endif
                @if($woRuling)
                    <dt>Who pays</dt><dd data-money-who-pays>{{ $woRuling->summary() }}</dd>
                @elseif($workOrder->completed_at && ! $workOrder->reported_fault_report_id)
                    <dt>Paid by</dt><dd>{{ ucfirst(str_replace('_', ' ', $workOrder->paid_by)) }}</dd>
                @endif
            </dl>
        </div>
    </div>

    {{-- E. the working sections --}}

    {{-- 1. Contractor (or, for our own crew, the job card) --}}
    @if($isInternal)
    <x-wo-panel key="jobcard" title="Job card" :summary="$woPanels['jobcard']" :open="$isOpenPanel('jobcard')">
        @if($jobCard = $workOrder->jobCard)
            <dl class="wo-facts">
                <dt>Status</dt><dd>{{ ucfirst(str_replace('_', ' ', $jobCard->status)) }}</dd>
                <dt>Crew</dt><dd>{{ $jobCard->crew?->name ?? ($jobCard->assigned_user_id ? 'Previously assigned: ' . ($jobCard->assignedUser?->name ?? '-') : '-') }}</dd>
                <dt>Scheduled</dt><dd>{{ $fmtDt($jobCard->scheduled_at) ?: '-' }}</dd>
                <dt>Total</dt><dd>{{ $jobCard->total_amount !== null ? $money($jobCard->total_amount) : '-' }}</dd>
            </dl>
            @feature('rental-job-cards')
            <div class="wo-btnrow"><a href="{{ route('corex.rental-job-cards.show', $jobCard) }}" class="corex-btn-primary text-xs">Open job card</a></div>
            @endfeature
        @else
            <p class="text-xs" style="color: var(--text-muted);">No job card found for this work order.</p>
        @endif
    </x-wo-panel>
    @elseif($isOpen || $woProv)
    <x-wo-panel key="contractor" title="Contractor" :summary="$woPanels['contractor']" :open="$isOpenPanel('contractor')">
        @if($workOrder->isOwnerContractor())
            @if($woCan['create'])
            <form method="POST" action="{{ route('corex.rental-work-orders.owner-contractor.update', $workOrder) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
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
            <p class="text-xs" style="color: var(--text-muted);" data-owner-arranges-note data-owner-arranges>The owner arranges and pays their own contractor &mdash; nothing is sent to them from here.</p>
        @elseif($woCan['create'])
            @php
                $classes = $contractorClassify;
                $pressKey = (string) \Illuminate\Support\Str::uuid();
            @endphp
            <div class="space-y-2" data-supplier-card data-refresh-on-contractor-change="wo-supplier-card">
                @if($woProv)
                    <div class="text-sm"><strong data-supplier-name>{{ $woProv->name }}</strong>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $woCt['email'] ?: 'no email' }} &middot; {{ $woCt['phone'] ?: 'no mobile' }}</span></div>
                    <p class="text-xs" data-supplier-callout="{{ $woProv->calloutFeeStatus() }}">Call-out fee: <strong>@if($woProv->calloutFeeStatus() === 'yes'){{ \App\Models\DealV2\AgencyServiceProvider::formatRand($woProv->calloutFeeAmount()) }}@if($woProv->calloutFeeNote()) ({{ $woProv->calloutFeeNote() }})@endif @elseif($woProv->calloutFeeStatus() === 'no')none @else not known &mdash; check before sending @endif</strong></p>

                    @if($js['sent'])
                        <p class="text-xs" data-job-sent><span class="ds-badge ds-badge-success">Job sent</span>
                            {{ $fmtDt($js['sent']->sent_at) }} by {{ $js['sent']->channelLabel() }}@if($js['sent']->destination) to {{ $js['sent']->destination }}@endif
                            @if($js['sent']->sentBy) &mdash; {{ $js['sent']->sentBy->name }}@endif</p>
                    @elseif($js['not_sent'])
                        <p class="text-xs font-semibold" style="color: var(--ds-crimson, #b3261e);" data-job-not-sent>
                            NOT sent &mdash; @if(! $woCt['has_contact'])no email or mobile on file for {{ $woProv->name }}.@else the email could not be delivered.@endif
                            @if(! $woCt['has_contact'] && $canManageContractors)
                                <button type="button" class="underline" data-contractor-popup="details" data-provider-id="{{ $woProv->id }}" data-provider-name="{{ $woProv->name }}" data-work-order-id="{{ $workOrder->id }}" data-add-contact-details>Add their details</button>
                            @endif
                        </p>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);" data-job-not-sent-yet>The job has not been sent to {{ $woProv->name }} yet.</p>
                    @endif

                    <p class="text-xs" data-contractor-response>Their answer: <strong>{{ $js['response_label'] }}</strong>@if($js['response'] === 'declined' && $js['decline_reason']) &mdash; {{ $js['decline_reason'] }}@endif</p>

                    {{-- the go-ahead (the work order PDF with the owner's approval on it) - what was sent and when, from the same data the Next bar reads --}}
                    @if($goAheadAt)
                        <p class="text-xs" data-go-ahead-sent><span class="ds-badge ds-badge-success">Go-ahead sent</span> {{ $fmtDt($goAheadAt) }}</p>
                    @endif

                    <div class="wo-btnrow">
                        {{-- Send / Resend: needs no typed quote; one press = one send (the key is single-use) --}}
                        @if($js['can_send'])
                            <form id="wo-send-job-form" method="POST" action="{{ route('corex.rental-work-orders.contractor.resend', $workOrder) }}">
                                @csrf
                                <input type="hidden" name="press_key" value="{{ $pressKey }}">
                                <button type="submit" class="{{ $js['sent'] ? 'corex-btn-outline' : 'corex-btn-primary' }} text-xs" data-send-job>{{ $js['sent'] ? 'Send the job again' : 'Send the job to ' . $woProv->name }}</button>
                            </form>
                        @else
                            <button type="button" class="corex-btn-primary text-xs" disabled title="Add an email address or a mobile number first" data-send-job>Send the job to {{ $woProv->name }}</button>
                            @if($canManageContractors)
                                <button type="button" class="corex-btn-outline text-xs" data-contractor-popup="details" data-provider-id="{{ $woProv->id }}" data-provider-name="{{ $woProv->name }}" data-work-order-id="{{ $workOrder->id }}">Add an email or mobile number</button>
                            @endif
                        @endif

                        @if($selectedQuote)
                            <form id="wo-go-ahead-form" method="POST" action="{{ route('corex.rental-work-orders.assign-supplier', $workOrder) }}">
                                @csrf
                                <input type="hidden" name="agency_service_provider_id" value="{{ $selectedQuote->agency_service_provider_id }}">
                                <button type="submit" class="{{ $goAheadAt ? 'corex-btn-outline' : 'corex-btn-primary' }} text-xs" @disabled(!$proceed->authorised)
                                        @if(!$proceed->authorised) title="{{ $proceed->note }}" @endif data-send-go-ahead>{{ $goAheadAt ? 'Send the go-ahead again' : 'Send the go-ahead' }}</button>
                            </form>
                        @endif
                    </div>
                    @if($selectedQuote && ! $proceed->authorised)
                        <p class="text-xs" style="color: var(--ds-crimson);">{{ $proceed->note }}</p>
                    @elseif(! $selectedQuote)
                        <p class="text-xs" style="color: var(--text-muted);" data-go-ahead-note>The go-ahead goes out once a quote is selected and its cost is authorised.</p>
                    @endif

                    {{-- WhatsApp: never "sent" on the page's word - open it, THEN mark it as sent --}}
                    @if($js['whatsapp'])
                        <div class="flex flex-wrap items-center gap-2 text-xs" data-whatsapp-row>
                            <span>WhatsApp to {{ $js['whatsapp']->destination }}:</span>
                            <form method="POST" action="{{ route('corex.rental-work-orders.contractor.whatsapp-open', [$workOrder, $js['whatsapp']->id]) }}" target="_blank">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs" data-whatsapp-open>{{ $js['whatsapp']->status === 'opened' ? 'Open WhatsApp again' : 'Open WhatsApp and send' }}</button>
                            </form>
                            @if($js['whatsapp']->status === 'opened')
                                <span style="color: var(--text-muted);">opened {{ $js['whatsapp']->opened_at?->format('H:i') }}</span>
                                <form method="POST" action="{{ route('corex.rental-work-orders.contractor.whatsapp-sent', [$workOrder, $js['whatsapp']->id]) }}">
                                    @csrf
                                    <button type="submit" class="corex-btn-primary text-xs" data-whatsapp-mark-sent>Mark as sent</button>
                                </form>
                            @else
                                <span style="color: var(--text-muted);">&ldquo;Mark as sent&rdquo; appears after you open WhatsApp.</span>
                            @endif
                        </div>
                    @endif
                @else
                    <p class="text-sm" data-no-contractor-chosen>No contractor has been chosen for this job yet. Choose one below &mdash; the job is sent to them straight away.</p>
                @endif

                {{-- choose / change the contractor: the SAME single rule as the owner portal and the pre-send warning --}}
                <details class="text-xs" @if(! $woProv) open @endif>
                    <summary class="cursor-pointer font-semibold" data-choose-contractor>{{ $woProv ? 'Choose a different contractor' : 'Choose the contractor' }}</summary>
                    <form method="POST" action="{{ route('corex.rental-work-orders.contractor.appoint', $workOrder) }}" class="space-y-2 pt-2" x-data="{ chosenContractor: '{{ (int) $workOrder->agency_service_provider_id ?: '' }}' }">
                        @csrf
                        @if($classes['suits']->isEmpty() && $classes['category'])
                            <p class="font-semibold" data-no-contractor-suits>No contractor loaded for {{ $classes['type'] ?: $classes['category'] }}.
                                @if($classes['mapping_gap']) Your trade list has no &ldquo;{{ $classes['category'] }}&rdquo; yet. @endif
                                @if($canManageContractors)
                                    <button type="button" class="underline" data-contractor-popup="add" data-work-order-id="{{ $workOrder->id }}" data-type="{{ $classes['type'] ?: $classes['category'] }}" data-trade-code="{{ $woTradeCode ?? '' }}">Add a contractor</button> &middot;
                                    <button type="button" class="underline" data-contractor-popup="types" data-work-order-id="{{ $workOrder->id }}" data-type="{{ $classes['type'] ?: $classes['category'] }}">Add {{ $classes['type'] ?: $classes['category'] }} to an existing contractor</button>
                                @endif
                            </p>
                        @endif
                        @php $contractorGroups = [['label' => 'Suits ' . ($classes['type'] ?: $classes['category']), 'rows' => $classes['suits']], ['label' => 'Other contractors', 'rows' => $classes['others']]]; @endphp
                        @foreach($contractorGroups as $group)
                            @if($group['rows']->isNotEmpty())
                                <div class="font-semibold pt-1" data-contractor-group>{{ $classes['category'] ? $group['label'] : 'Contractors' }}</div>
                                @foreach($group['rows'] as $c)
                                    <label class="flex flex-wrap items-center gap-2 rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-contractor-row="{{ $c['id'] }}">
                                        <input type="radio" name="agency_service_provider_id" value="{{ $c['id'] }}" x-model="chosenContractor" @checked((int) $workOrder->agency_service_provider_id === $c['id'])>
                                        <span class="font-medium">{{ $c['name'] }}</span>
                                        @if($c['trades'])<span style="color: var(--text-muted);">{{ $c['trades'] }}</span>@endif
                                        @if(! $c['has_contact'])<span class="ds-badge ds-badge-danger" data-no-contact-details>no contact details</span>@endif
                                        <span style="color: var(--text-muted);" data-callout-suffix="{{ $c['callout_status'] }}">- {{ $c['callout'] }}</span>
                                    </label>
                                @endforeach
                            @endif
                        @endforeach
                        @include('partials.contractor-inspect-choice', ['notices' => $classes['suits']->concat($classes['others'])->mapWithKeys(fn ($c) => [(string) $c['id'] => ['name' => $c['name'], 'text' => $c['callout_notice'], 'status' => $c['callout_status']]])->all(), 'model' => 'chosenContractor'])
                        <button type="submit" class="corex-btn-outline text-xs" data-appoint-contractor-submit>Choose and send the job</button>
                    </form>
                </details>
            </div>
        @else
            <p class="text-xs" style="color: var(--text-muted);">{{ $woPanels['contractor'] }}</p>
        @endif
    </x-wo-panel>
    @endif

    {{-- 2. Quotes: only for a contractor the AGENCY appoints (O3) --}}
    @if($isOpen && $isOutside)
    <x-wo-panel key="quotes" title="Quotes" :summary="$woPanels['quotes']" :open="$isOpenPanel('quotes')">
        @if($workOrder->quotes->isNotEmpty())
            <ul class="space-y-2 text-sm">
                @foreach($workOrder->quotes as $quote)
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            {{-- BUILD 2 (§17.9.1a) - the agency's fee on an outside contractor's quote is the agency's margin: only staff who can see costs see it. --}}
                            @if((float) $quote->fee_amount > 0 && $canSeeFee)
                                {{ $quote->supplier?->name ?? 'Unknown supplier' }} &mdash; quote {{ $money($quote->amount) }} + fee {{ $money($quote->fee_amount) }} = <strong>{{ $money($quote->ownerFacingAmount()) }}</strong> to the owner
                            @else
                                {{ $quote->supplier?->name ?? 'Unknown supplier' }} &mdash; <strong>{{ $money($quote->ownerFacingAmount()) }}</strong>
                            @endif
                            <span style="color: var(--text-muted);">({{ \App\Support\AppDate::day($quote->quote_date, $woTz) }})</span>
                            @if($quote->is_selected && $workOrder->owner_approval_status !== \App\Models\RentalWorkOrder::APPROVAL_DECLINED)
                                <span class="ds-badge ds-badge-success">Selected</span>
                            @endif
                            @if($quote->declined_at || ($quote->is_selected && $workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_DECLINED))
                                <span class="ds-badge ds-badge-danger" data-quote-declined-badge>Declined by the owner</span>
                                @if($quote->decline_reason)<span class="text-xs" style="color: var(--ds-crimson, #b3261e);">&mdash; {{ $quote->decline_reason }}</span>@endif
                            @endif
                            @if($quote->document_storage_path)
                                <a href="{{ route('corex.rental-work-orders.quotes.download', [$workOrder, $quote]) }}" class="underline text-xs">Document</a>
                            @endif
                            @if($quote->detail_text)
                                <span class="text-xs" style="color: var(--text-muted);">&mdash; {{ $quote->detail_text }}</span>
                            @endif
                        </span>
                        @permission('rental_work_orders.manage_quotes')
                        <span class="wo-btnrow">
                            @unless($quote->is_selected)
                                <form method="POST" action="{{ route('corex.rental-work-orders.quotes.select', [$workOrder, $quote]) }}">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">Select</button>
                                </form>
                            @endunless
                            <button type="button" onclick="document.getElementById('edit-quote-form-{{ $quote->id }}').classList.toggle('hidden')" class="corex-btn-outline text-xs">Edit</button>
                            <form method="POST" action="{{ route('corex.rental-work-orders.quotes.destroy', [$workOrder, $quote]) }}" data-confirm="Archive this quote?" data-confirm-danger data-confirm-label="Archive">
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
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs">Supplier</label><br>
                                    <select name="agency_service_provider_id" required class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                                        @foreach(\App\Models\DealV2\AgencyServiceProvider::active()->maintenanceContractors()->pickerOrder()->get() as $provider)
                                            <option value="{{ $provider->id }}" @selected($quote->agency_service_provider_id === $provider->id)>{{ $provider->name }} - {{ $provider->calloutFeeSuffix() }}</option>
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
                                <div class="sm:col-span-2">
                                    <label class="text-xs">Details</label>
                                    <textarea name="detail_text" rows="2" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ $quote->detail_text }}</textarea>
                                </div>
                            </div>
                            <div class="wo-btnrow">
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
            <div>
                <button type="button" onclick="document.getElementById('archived-quotes').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedQuotes->count() }} archived quote(s)</button>
                <ul id="archived-quotes" class="hidden space-y-1 text-sm pt-1">
                    @foreach($archivedQuotes as $archived)
                        <li class="flex items-center justify-between gap-2">
                            <span style="color: var(--text-muted);">{{ $archived->supplier?->name ?? 'Unknown supplier' }} &mdash; {{ $money($archived->amount) }} ({{ \App\Support\AppDate::day($archived->quote_date, $woTz) }})</span>
                            <form method="POST" action="{{ route('corex.rental-work-orders.quotes.restore', [$workOrder, $archived->id]) }}">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if(! $woReportedFinished)
        <details data-capture-quote @if($workOrder->quotes->isEmpty() || $errors->has('amount') || $errors->has('quote_date')) open @endif>
            <summary class="corex-btn-outline text-xs cursor-pointer" style="display:inline-flex; align-items:center; min-height:34px; list-style:none;" data-capture-quote-toggle>Capture a quote</summary>
        <form method="POST" action="{{ route('corex.rental-work-orders.quotes.store', $workOrder) }}" enctype="multipart/form-data" class="space-y-2 pt-3">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                    <label class="text-xs">Supplier</label><br>
                    @php $quotePickList = \App\Models\DealV2\AgencyServiceProvider::active()->maintenanceContractors()->pickerOrder()->get(); @endphp
                    <div x-data="{ chosen: '{{ (int) old('agency_service_provider_id', $workOrder->agency_service_provider_id) ?: '' }}' }" class="space-y-1">
                    <select name="agency_service_provider_id" required x-model="chosen" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                        <option value="">Select…</option>
                        @foreach($quotePickList as $provider)
                            <option value="{{ $provider->id }}" @selected((int) old('agency_service_provider_id', $workOrder->agency_service_provider_id) === (int) $provider->id)>{{ $provider->name }} - {{ $provider->calloutFeeSuffix() }}</option>
                        @endforeach
                    </select>
                    @include('partials.contractor-inspect-choice', ['notices' => \App\Models\DealV2\AgencyServiceProvider::noticeMap($quotePickList), 'model' => 'chosen'])
                    </div>
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
                <div class="sm:col-span-2">
                    <label class="text-xs">Details (optional &mdash; required if no document attached)</label>
                    <textarea name="detail_text" rows="2" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);"></textarea>
                </div>
                @if($selectedQuote)
                    <label class="flex items-center gap-2 text-xs sm:col-span-2">
                        <input type="checkbox" name="is_selected" value="1">
                        Use this quote instead of the selected one
                    </label>
                @elseif($workOrder->quotes->whereNull('declined_at')->isEmpty())
                    <p class="text-xs sm:col-span-2" style="color: var(--text-muted);" data-quote-autoselect>The first quote is selected for you.</p>
                @endif
            </div>
            <button type="submit" class="corex-btn-outline text-xs">Capture quote</button>
        </form>
        </details>
        @endif
        @endpermission
        {{-- BUILD 2 (§17.9.1a) - per-work-order override of the agency's fee on this contractor's quote; locked once the owner approved an amount. --}}
        @permission('rental_job_cards.price')
        @if($canSeeFee && !$workOrder->hasApprovedBaseline() && $workOrder->quotes->isNotEmpty())
        <details data-fee-collapse><summary class="text-xs cursor-pointer" style="color: var(--text-muted);">Change the agency fee on this quote</summary>
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
            <span class="text-xs" style="color: var(--text-muted);">Blank uses the agency default.</span>
        </form>
        </details>
        @endif
        @endpermission
    </x-wo-panel>
    @endif

    {{-- 3. Approve the cost (as built in stage 2; the owner's limits are shown once, in "The money") --}}
    @php $cdHideLimits = true; @endphp
    <x-wo-panel key="cost" title="Approve the cost" :summary="$woPanels['cost']" :open="$isOpenPanel('cost')">
        @include('corex.rental-work-orders._approval-panel')
    </x-wo-panel>

    {{-- 4. Appointment (W2/W6: the agent normally coordinates it with the tenant; the tenant is emailed when it is set or changed) --}}
    <x-wo-panel key="appointment" title="Appointment" :summary="$woPanels['appointment']" :open="$isOpenPanel('appointment')">
        <div @if($isOpen && ! $woReportedFinished && $woCan['create']) id="appointment-card" @endif>
        @if($isOpen && ! $woReportedFinished && $woCan['create'])
            {{-- Johan, 9 Oct 2026: an appointment (and the email to the tenant) only once the job is approved. --}}
            @if($proceed->authorised || in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_ORDERED, \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_DISPUTED], true))
                @php $apptValue = $workOrder->appointment_at?->copy()->setTimezone($woTz)->format('Y-m-d\TH:i'); @endphp
                <form method="POST" action="{{ route('corex.rental-work-orders.appointment.store', $workOrder) }}" class="wo-appt-form">
                    @csrf
                    <div>
                        <label class="text-xs font-medium">Date and time</label>
                        <x-date-time-field name="appointment_at" id="wo-appointment-at" class="mt-1" :value="old('appointment_at', $apptValue)" />
                    </div>
                    <div>
                        <label class="text-xs font-medium">Note for the tenant (optional)</label>
                        <input type="text" name="appointment_note" maxlength="500" value="{{ old('appointment_note', $workOrder->appointment_note) }}" placeholder="e.g. the plumber will call 30 minutes before" class="w-full cx-dt-ctl mt-1">
                    </div>
                    <div><button type="submit" class="corex-btn-primary text-xs" style="height:38px; margin-top:4px;">{{ $workOrder->appointment_at ? 'Change appointment' : 'Set appointment' }}</button></div>
                </form>
                <p class="text-xs mt-2" style="color: var(--text-muted);">The tenant is emailed when you set or change this.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);" data-appointment-locked>An appointment can be set once the cost is authorised.</p>
            @endif
        @else
            <p class="text-sm">{{ $apptText }}</p>
        @endif
        </div>
    </x-wo-panel>

    {{-- 5. Completion: mark in progress, complete, the contractor reports done, the tenant check --}}
    <x-wo-panel key="completion" title="Completion" :summary="$woPanels['completion']" :open="$isOpenPanel('completion')">
        @if($isOpen && ! $isInternal)
            @php
                $whoPaysSvc = app(\App\Services\Rentals\RentalWhoPaysService::class);
                $completeNeedsFindings = $woFault && ! $whoPaysSvc->hasFindings($workOrder);
                $causeOptions = $completeNeedsFindings ? $whoPaysSvc->causeOptions($workOrder) : collect();
                $canCompleteNow = $woCan['complete'] && $proceed->authorised && in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_ORDERED, \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS], true);
                $completeOpen = old('cost_amount') !== null || old('found_text') !== null;
            @endphp
            <div class="wo-btnrow">
                @if($canCompleteNow)
                    <button type="button" onclick="document.getElementById('complete-work-order-form').classList.toggle('hidden')" class="corex-btn-primary text-xs" data-complete-work-order>Mark the work as complete</button>
                @elseif($woCan['complete'])
                    <span class="text-xs" style="color: var(--text-muted);" data-complete-later>The work can be marked complete once the cost is authorised and the go-ahead has gone.</span>
                @endif
                {{-- a small secondary action, for crews and job cards: the contractor rarely presses it --}}
                @if($woCan['create'] && $workOrder->status === \App\Models\RentalWorkOrder::STATUS_ORDERED)
                    <form id="wo-start-progress-form" method="POST" action="{{ route('corex.rental-work-orders.start-progress', $workOrder) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs" @disabled(!$proceed->authorised)>Mark in progress</button>
                    </form>
                @endif
            </div>
            @if($woCan['complete'])
            <form id="complete-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.complete', $workOrder) }}" enctype="multipart/form-data" class="{{ $completeOpen ? '' : 'hidden ' }}space-y-3">
                @csrf
                @if($workOrder->reported_fault_report_id)
                    {{-- section 22.13: a job from a fault has ONE place for who pays - the "Who pays" panel. This field follows the ruling (owner / tenant), else stays "not yet paid". --}}
                    <input type="hidden" name="paid_by" value="{{ in_array($workOrder->who_pays, ['owner', 'tenant'], true) ? $workOrder->who_pays : 'not_yet_paid' }}">
                @else
                <div>
                    <label class="text-xs font-medium">Paid by</label>
                    <select name="paid_by" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <option value="owner">Owner</option>
                        <option value="tenant">Tenant</option>
                        <option value="deposit_deduction">Deposit deduction (label only)</option>
                        <option value="not_yet_paid">Not yet paid</option>
                    </select>
                </div>
                @endif
                <div>
                    <label class="text-xs font-medium">Final cost (R, optional)</label>
                    @php $suggestedInvoiceCost = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class)->suggestedCost($workOrder); @endphp
                    <input type="number" name="cost_amount" step="0.01" min="0" value="{{ old('cost_amount', $suggestedInvoiceCost !== null ? number_format($suggestedInvoiceCost, 2, '.', '') : '') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    @if($suggestedInvoiceCost !== null)
                        <p class="text-xs mt-1" style="color: var(--text-muted);" data-cost-from-invoices>From the supplier invoices filed ({{ $money($suggestedInvoiceCost) }}) &mdash; change it if the final cost is different.</p>
                    @endif
                </div>
                @if($completeNeedsFindings)
                <div data-complete-findings>
                    <label class="text-xs font-medium" for="wo-found">What was found (required)</label>
                    <textarea id="wo-found" name="found_text" rows="2" required maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('found_text') }}</textarea>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium" for="wo-cause">Cause (required)</label>
                        <select id="wo-cause" name="cause_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            @foreach($causeOptions as $o)<option value="{{ $o->id }}" @selected((int) old('cause_id') === $o->id)>{{ $o->label }}</option>@endforeach
                            <option value="" @selected(old('found_text') !== null && ! old('cause_id'))>Something else (describe it)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium" for="wo-cause-note">Cause in words (for &ldquo;Something else&rdquo;)</label>
                        <input id="wo-cause-note" type="text" name="cause_note" maxlength="500" value="{{ old('cause_note') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                </div>
                @endif
                <div>
                    <label class="text-xs font-medium">Completion notes</label>
                    <textarea name="completion_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('completion_notes') }}</textarea>
                </div>
                <p class="text-xs" style="color: var(--text-muted);">{{ $completionRequiresPhoto ? 'A "completed" photo is required before this can be saved.' : 'A "completed" photo is optional.' }}</p>
                <button type="submit" class="corex-btn-primary text-xs">Mark complete</button>
            </form>
            @endif
        @elseif($isInternal && $isOpen)
            <p class="text-xs" style="color: var(--text-muted);">Our crew starts and completes this job from the job card.</p>
        @endif
        @include('corex.rental-work-orders._completion-panel')
    </x-wo-panel>

    {{-- 6. Who pays (a logged ruling; only for a job that came from a fault) --}}
    @if($woFault)
    <x-wo-panel key="whopays" title="Who pays" :summary="$woPanels['whopays']" :open="$isOpenPanel('whopays')">
        @include('corex.rental-work-orders._who-pays-panel')
    </x-wo-panel>
    @endif

    {{-- 7. Supplier invoices (office only) --}}
    @if($workOrder->status !== \App\Models\RentalWorkOrder::STATUS_REPORTED || $invoiceCount > 0)
    @permission('rental_work_orders.manage_invoices')
    <x-wo-panel key="invoices" title="Supplier invoices" :summary="$woPanels['invoices']" :open="$isOpenPanel('invoices')">
        @include('corex.rental-work-orders._invoices-panel')
    </x-wo-panel>
    @endpermission
    @endif

    {{-- 8. Photos --}}
    <x-wo-panel key="photos" title="Photos" :summary="$woPanels['photos']" :open="$isOpenPanel('photos')">
        <div id="wo-photos" class="space-y-3">
        @if(session('wo_photo_message'))
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('wo_photo_message') }}</div>
        @endif
        @if($errors->photo->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                @foreach($errors->photo->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
        @if($workOrder->photos->isNotEmpty())
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach($workOrder->photos as $photo)
                    <div>
                        {{-- AT-452 - opens in the shared viewer (a popup); a copy of a fault photo carries the agent's marks and note --}}
                        <button type="button" data-open-wo-photo="{{ $loop->index }}" aria-label="Open photo {{ $loop->iteration }}" class="p-0 bg-transparent border-0 cursor-zoom-in w-full"><img src="{{ $woViewerPhotos[$loop->index]['thumb_url'] ?? $photo->storage_path }}" alt="{{ $woViewerPhotos[$loop->index]['alt'] ?? 'Photo' }}" loading="lazy" class="rounded-md w-full h-24 object-cover"></button>
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
    </x-wo-panel>

    {{-- 9. History - "who did what", oldest first (RentalWorkOrder::history() merges creation, every update and every approval decision) --}}
    <x-wo-panel key="history" title="History" :summary="$woPanels['history']" :open="$isOpenPanel('history')">
        <ul class="space-y-1 text-sm">
            @foreach($workOrder->history() as $entry)
                <li>
                    <span style="color: var(--text-muted);">{{ \App\Support\AppDate::dateTime($entry['at'], $woTz) }}</span>
                    &mdash; {{ $entry['action'] }}
                    @if($entry['from'] || $entry['to'])
                        ({{ $entry['from'] ?? '-' }} &rarr; {{ $entry['to'] ?? '-' }})
                    @endif
                    @if($entry['note']) &mdash; {{ $entry['note'] }}@endif
                    @if($entry['actor'])<span style="color: var(--text-muted);">({{ $entry['actor'] }})</span>@endif
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
    </x-wo-panel>

    {{-- Edit / Archive / Cancel: small, secondary, at the very bottom, away from the job facts --}}
    <div class="wo-foot" x-data="{ editing: false }" data-wo-foot>
        @permission('rental_work_orders.create')
            @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
                <button type="button" @click="editing = !editing" class="corex-btn-outline text-xs">Edit title and description</button>
            @endif
            @if($workOrder->isDeletable() && $workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED && ! $workOrder->trashed())
                <form method="POST" action="{{ route('corex.rental-work-orders.destroy', $workOrder) }}" data-confirm="Archive this work order?" data-confirm-danger data-confirm-label="Archive">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                </form>
            @endif
        @endpermission
        @permission('rental_work_orders.cancel')
            @if($isOpen)
                <button type="button" onclick="document.getElementById('cancel-work-order-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel work order</button>
            @endif
        @endpermission

        @permission('rental_work_orders.create')
        @if($workOrder->status === \App\Models\RentalWorkOrder::STATUS_REPORTED)
        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-work-orders.update', $workOrder) }}" class="space-y-3 w-full">
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
            <div class="wo-btnrow">
                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
        @endif
        @endpermission

        <form id="cancel-work-order-form" method="POST" action="{{ route('corex.rental-work-orders.cancel', $workOrder) }}" class="hidden space-y-2 w-full">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>
</div>
{{-- AT-452 round 2 (H) - the contractor popups open over this screen. --}}
@if(!empty($canManageContractors))
    @include('partials.contractor-popups')
@endif

{{-- AT-452 - the shared photo viewer (popup, never a new tab). --}}
@include('partials.photo-viewer')
<script type="application/json" id="wo-photo-data">@json($woViewerPhotos)</script>
<script>
(function () {
    var photos = JSON.parse(document.getElementById('wo-photo-data').textContent || '[]');
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-open-wo-photo]') : null;
        if (!b || !window.CorexPhotoViewer) return;
        e.preventDefault();
        window.CorexPhotoViewer.open({ photos: JSON.parse(JSON.stringify(photos)), index: parseInt(b.getAttribute('data-open-wo-photo'), 10) || 0, editable: false, returnFocus: b });
    });
})();
// section 24 - the Next bar's button, and any "#..." link, opens the collapsed panel that holds its target, scrolls there, and (for "Authorise", "Record the owner's decision" ...) presses the control that was already on the page.
(function () {
    function openAround(el) { for (var d = el; d; d = d.parentElement) { if (d.tagName === 'DETAILS') { d.open = true; } } }
    function press(root, sel) {
        var t = root.querySelector(sel) || document.querySelector(sel);
        if (!t) return;
        if (t.tagName === 'SUMMARY') { t.parentElement.open = true; } else { t.click(); }
    }
    document.addEventListener('click', function (e) {
        var go = e.target.closest ? e.target.closest('[data-wo-go]') : null;
        if (go) {
            e.preventDefault();
            var panel = document.getElementById('wo-panel-' + go.getAttribute('data-wo-go'));
            if (!panel) return;
            openAround(panel);
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            var sel = go.getAttribute('data-wo-click');
            if (sel) { setTimeout(function () { press(panel, sel); }, 250); }
            return;
        }
        var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
        if (a && a.getAttribute('href').length > 1) {
            var t = document.getElementById(a.getAttribute('href').slice(1));
            if (t) { openAround(t); }
        }
    });
    if (location.hash.length > 1) { var h = document.getElementById(location.hash.slice(1)); if (h) { openAround(h); } }
})();
</script>
@endsection
