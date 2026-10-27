@extends('layouts.corex')

{{-- .ai/specs/rental-work-orders.md §3a — the fault report detail screen. --}}

@php
        // AT-452 - photo thumbnails come from the viewer payload (small copies); `noContractor` is the owner portal's own test (item 9).
        $vp = collect($viewerPhotos ?? [])->keyBy('id');
        $typeName = $faultReport->faultType?->name ?: 'this fault';
        // Johan, 8 Oct 2026: the screen shows only what fits where the fault IS, not every stage at once (section 28: prepare / with_owner / appoint / declined / work_order / closed).
        // section 28 - the stage (and everything the Returned | Next bar says) comes from RentalFaultScreen, one place; $screen is built by the controller
        $stage = $screen['stage'];
        // section 22.2: the owner's repair limit is read ONLY through the owner-settings resolver (null = the agent must ask the owner)
        $ownerRepairLimit = $faultReport->property ? \App\Services\Rentals\RentalOwnerFaultSettings::for($faultReport->property)->repairLimit() : null;
    $statusBadgeClass = match ($faultReport->status) {
        'resolved' => 'ds-badge-success',
        'reported', 'under_review', 'awaiting_approval' => 'ds-badge-info',
        'declined', 'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    // The owner's decision, read at the TOP of the page: the appoint-contractor step is the first thing the agent sees once the owner has approved.
    $latestDecision = $faultReport->decision();
    $prefillOwner = ($latestDecision && $latestDecision->contractor_source === \App\Models\RentalApproval::CONTRACTOR_OWN) ? $latestDecision : null;
    // Johan, 20 Oct 2026 (spec 29.R3): the owner's pick, else the property's default contractor (section 21), else NOTHING - the agent picks.
    // The contractor the agent proposed when sending this to the owner is not a pick by itself (RentalFaultContractorService::defaultSupplierId).
    $prefillSupplierId = $prefillOwner ? null : app(\App\Services\Rentals\RentalFaultContractorService::class)->defaultSupplierId($faultReport, collect($contractorPicker));
    $prefillSupplier = $prefillSupplierId ? \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $faultReport->agency_id)->find($prefillSupplierId) : null;
    // J7: a declined fault whose outcome was filled in automatically - the Outcome block is then a summary, not a task.
    $declinedClosed = $faultReport->status === \App\Models\RentalFaultReport::STATUS_DECLINED && (bool) $faultReport->outcome_set_automatically;
    $isOpen = ! in_array($faultReport->status, ['resolved', 'cancelled'], true);
@endphp

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@include('corex.rentals._stage-screen-style')
{{-- AT-439 Part 3 — full-width (was max-w-3xl), matching the Lease Hub/Work
     Order show precedent: every line of space is data the agent needs or a
     control they act on. Two-column below the header: record + actions
     left, photos/history right. --}}
<div class="rx-page w-full space-y-4 cx-equal-btns">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $faultReport->title }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ $faultReport->statusLabel() }}</span>
            @if($faultReport->typeName())
                <span class="ds-badge ds-badge-muted" @if($faultReport->isOtherType()) data-type-other @endif>{{ $faultReport->typeName() }}</span>
            @endif
            {{-- AT-452 - a fault marked "Other - not on the list": the tenant's words stay; the agent can give it a proper type (logged in the history). --}}
            @if($faultReport->isOtherType() && ! $faultReport->isClosedState())
                @permission('rental_fault_reports.create')
                    <form method="POST" action="{{ route('corex.rental-fault-reports.reclassify', $faultReport) }}" class="inline-flex items-center gap-1" data-reclassify>
                        @csrf
                        <select name="rental_fault_type_id" required class="prop-input text-xs" aria-label="Change the type of this fault">
                            <option value="">Change type&hellip;</option>
                            @foreach(\App\Models\RentalFaultType::where('is_active', true)->orderBy('sort_order')->get() as $ft)
                                <option value="{{ $ft->id }}">{{ $ft->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="corex-btn-outline text-xs">Save type</button>
                    </form>
                @endpermission
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
        @include('partials.header-actions-style')
        <div class="flex flex-wrap items-center gap-2" data-header-actions>
            @permission('rental_fault_reports.cancel')
                {{-- J7 (Johan, 9 Oct 2026): "Cancel report" only while the owner has not decided; once decided the fault is handled through its work order / outcome. --}}
                @if(in_array($faultReport->status, [\App\Models\RentalFaultReport::STATUS_REPORTED, \App\Models\RentalFaultReport::STATUS_UNDER_REVIEW, \App\Models\RentalFaultReport::STATUS_AWAITING_APPROVAL], true))
                    <button type="button" onclick="document.getElementById('cancel-fault-report-form').classList.toggle('hidden')" class="corex-btn-outline text-xs" data-cancel-report>Cancel report</button>
                @endif
            @endpermission
            @permission('rental_fault_reports.create')
                @if($faultReport->isDeletable() && $faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED)
                    <form method="POST" action="{{ route('corex.rental-fault-reports.destroy', $faultReport) }}">
                        @csrf
                        @method('DELETE')
                        <x-confirm-submit title="Archive this fault report" message="Archive this fault report? It leaves the list, and an admin can restore it." confirm-label="Archive" :plain="true" class="corex-btn-outline text-xs">Archive</x-confirm-submit>
                    </form>
                @endif
            @endpermission
            <a href="{{ route('corex.rental-fault-reports.pdf', $faultReport) }}" target="_blank" class="corex-btn-outline text-xs">Download PDF</a>
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">&larr; All fault reports</a>
        </div>
    </div>
    </div>
    {{-- (the success message is the layout's toast - J8: it is not repeated here as a second banner) --}}
    {{-- §17.3.2 / §17.10.6 — a refused "Create work order" or a refused "repaired" outcome says WHY, in plain words. --}}
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- AT-464 / section 28 - after an action the page lands on the Returned | Next bar (data-land-on-next), just under the tabs. --}}


    <form id="cancel-fault-report-form" method="POST" action="{{ route('corex.rental-fault-reports.cancel', $faultReport) }}" class="hidden space-y-2 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <label class="text-xs font-medium">Reason for cancellation (required)</label>
        <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
    </form>

    <x-rental-context-bar :property="$faultReport->property" :lease="$faultReport->lease" current="faults" />

    {{-- AT-452 round 2 (F) - the tenant closed this themselves with the suggested step: for the agent to SEE, nothing to work. --}}
    @if($faultReport->isTenantSelfResolved())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 8%, var(--surface)); border: 1px solid var(--ds-green);" data-tenant-self-resolved>
            <strong>{{ \App\Models\RentalFaultReport::SELF_RESOLVED_LABEL }}.</strong>
            {{ $faultReport->reportedByContact ? trim($faultReport->reportedByContact->first_name . ' ' . $faultReport->reportedByContact->last_name) : 'The tenant' }} read the suggested steps and pressed &ldquo;That fixed it&rdquo; on {{ $faultReport->resolved_at?->format('j M Y H:i') }}.
            This fault is closed &mdash; there is nothing to review, send to the owner or raise a work order for. If it comes back, the tenant reports it again.
            <div class="mt-2 flex flex-wrap items-center gap-3" data-self-resolved-actions>
                @if($followUp)
                    <span data-repair-asked>A repair was asked for on {{ ($followUp->reported_at ?? $followUp->created_at)->format('j M Y') }} &mdash; <a class="underline" href="{{ route('corex.rental-fault-reports.show', $followUp) }}">open the request</a>.</span>
                @else
                    @permission('rental_fault_reports.create')
                    <form method="POST" action="{{ route('corex.rental-fault-reports.repair-anyway', $faultReport) }}">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs" data-repair-anyway>Have this repaired anyway</button>
                    </form>
                    @endpermission
                @endif
            </div>
        </div>
    @endif

    {{-- AT-452 (§19.14) - raised by the owner: their instruction is their authority to go to a quote. --}}
    @if($faultReport->isOwnerRequested())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 9%, var(--surface)); border: 1px solid var(--ds-green, #16a34a);" data-requested-by-owner>
            <strong>Requested by the owner.</strong>
            {{ $faultReport->reportedByContact ? trim($faultReport->reportedByContact->first_name . ' ' . $faultReport->reportedByContact->last_name) : 'The owner' }} asked the agency to arrange this on {{ ($faultReport->reported_at ?? $faultReport->created_at)?->format('j M Y H:i') }}.
            Their instruction is their authority to go to a quote &mdash; they are not asked to approve it again. @if($ownerRepairLimit !== null)A quote above the owner's repair limit (R{{ number_format($ownerRepairLimit, 2) }}) still goes to them to approve.@else Every quote goes to them to approve - they have not set a repair limit.@endif
        </div>
    @endif

    {{-- AT-452 (§19.12) - the owner's OWN words to their agent: who, when, what. Separate from the tenant's report and from the agent's note to the owner. --}}
    @if($ownerCommentBlock = $faultReport->ownerComment())
        <div class="rounded-md px-4 py-3 text-sm space-y-1" style="background: color-mix(in srgb, var(--ds-blue, #2563eb) 8%, var(--surface)); border: 1px solid var(--ds-blue, #2563eb);" data-owner-comment id="owner-comment">
            <div class="text-xs font-semibold">Owner's comment</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ $ownerCommentBlock['by'] }}@if($ownerCommentBlock['at']) &middot; {{ $ownerCommentBlock['at']->format('j M Y H:i') }}@endif</div>
            <div style="white-space: pre-line;" data-owner-comment-text>{{ $ownerCommentBlock['text'] }}</div>
        </div>
    @endif

    {{-- AT-452 round 2 (item I) - the agent went ahead; the owner was told, not asked. --}}
    @if($faultReport->isArrangedByAgent())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-blue, #2563eb) 8%, var(--surface)); border: 1px solid var(--ds-blue, #2563eb);" data-arranged-by-agent>
            {{ app(\App\Services\Rentals\RentalAuthorisationService::class)->arrangedLineForAgent($faultReport) ?? ('The owner was told on ' . $faultReport->arranged_by_agent_at?->format('j M Y H:i') . '.') }}
        </div>
    @endif

    {{-- section 22.7 - PAUSED BY OWNER (with Resume) and the owner's questions (with Answer) --}}
    <div id="owner-questions">@include('corex.rental-fault-reports._owner-hold-and-questions')</div>

    {{-- "Reported before" (F-OWNER): for a tenant-resolved fault it is the banner here; for every other fault it is the last block of the tenant picture below. --}}
    @if($reportedBefore && $faultReport->isTenantSelfResolved())
        <div class="rounded-md px-4 py-2 text-xs" style="background: var(--surface-2); border: 1px solid var(--border);" data-reported-before>
            {{ $reportedBefore['text'] }} &mdash; <a class="underline" href="{{ route('corex.rental-fault-reports.show', $reportedBefore['id']) }}">open it</a>.
        </div>
    @endif
    @if($followsReport && ! $faultReport->isTenantSelfResolved())
        <div class="rounded-md px-4 py-2 text-xs" style="background: var(--surface-2); border: 1px solid var(--border);" data-follows-report>
            Follows the report of {{ ($followsReport->reported_at ?? $followsReport->created_at)->format('j M Y') }} that the tenant resolved themselves &mdash; <a class="underline" href="{{ route('corex.rental-fault-reports.show', $followsReport) }}">open it</a>.
        </div>
    @endif

    {{-- section 28.1 - WHERE THIS FAULT IS, then the Returned | Next bar: what came back last, and the one next action. The page lands here after every saved action. --}}
    @php
        $fsNext = $screen['next'];
        $fsReturned = $screen['returned'];
        $fsAnchor = in_array($fsReturned['anchor'], ['owner-questions', 'owner-comment'], true) ? $fsReturned['anchor'] : 'wo-panel-' . $fsReturned['anchor'];
        $fsButton = function (?array $b, bool $primary) {
            if (! $b) { return ''; }
            $cls = $primary ? 'corex-btn-primary text-xs' : 'corex-btn-outline text-xs';
            $attr = $primary ? 'data-next-button' : 'data-next-secondary';
            if ($b['kind'] === 'link') {
                return '<a href="' . e($b['href']) . '" class="' . $cls . '" ' . $attr . '>' . e($b['label']) . '</a>';
            }

            return '<a href="#wo-panel-' . e($b['panel']) . '" class="' . $cls . '" data-wo-go="' . e($b['panel']) . '"' . (! empty($b['click']) ? ' data-wo-click="' . e($b['click']) . '"' : '') . ' ' . $attr . '>' . e($b['label']) . '</a>';
        };
    @endphp
    <ol class="wo-strip" data-wo-strip aria-label="Where this fault is">
        @foreach($screen['steps'] as $st)
            <li class="wo-step" data-step="{{ $st['key'] }}" data-state="{{ $st['state'] }}" @if($st['state'] === 'current') aria-current="step" @endif>
                <span class="wo-dot">@if($st['state'] === 'done')&#10003;@elseif($st['state'] === 'current')&#9679;@else&nbsp;@endif</span>
                <span class="wo-step-label">{{ $st['label'] }}</span>
                @if($st['at'])<span class="wo-step-at">{{ $st['at'] }}</span>@endif
            </li>
        @endforeach
    </ol>
    <div class="wo-next wo-next-{{ $fsNext['tone'] }}" data-next-step data-land-on-next data-stage="{{ $stage }}" data-next="{{ $fsNext['key'] }}">
        <div class="jc-bar-half" data-returned data-returned-anchor="{{ $fsReturned['anchor'] }}">
            <div><span class="wo-next-label">{{ $fsReturned['label'] }}</span>
                <a href="#{{ $fsAnchor }}" class="jc-returned-link" data-returned-text>{{ $fsReturned['text'] }}</a>@if($fsReturned['waiting'])<span class="jc-waiting">waiting for you</span>@endif</div>
        </div>
        <div class="jc-bar-half">
            <div class="jc-bar-next">
                <div><span class="wo-next-label">Next</span><span data-next-text>{{ $fsNext['text'] }}</span></div>
                <div class="wo-btnrow">{!! $fsButton($fsNext['button'], true) !!}{!! $fsButton($fsNext['secondary'], false) !!}</div>
            </div>
        </div>
    </div>

    {{-- Johan, 8 Oct 2026 (night): once the owner has approved, appointing the contractor is THE next action - open by default, right here
         under the Returned | Next bar (section 28), no toggle. --}}
    @permission('rental_fault_reports.raise_work_order')
        @if($stage === 'appoint')
            <div class="rounded-md p-4 space-y-2" style="background: color-mix(in srgb, var(--ds-green) 8%, var(--surface)); border: 1px solid var(--ds-green);" data-appoint-contractor id="wo-panel-appoint">
                <div class="text-sm font-semibold">Appoint the contractor</div>
                @if($faultReport->workOrderBlockReason() === null)
                    @include('corex.rental-fault-reports._raise-work-order-form', ['secondary' => false])
                @else
                    <p class="text-xs" style="color: var(--text-muted);">{{ $faultReport->workOrderBlockReason() }}</p>
                @endif
            </div>
        @endif
    @endpermission

    {{-- section 28.1 - the folded panels, in working order. Finished stages are ONE summary line; the current stage's panel is open. --}}
    @if(! $faultReport->isTenantSelfResolved())
        <x-wo-panel key="tenant" title="What the tenant reported" :summary="$screen['panels']['tenant']" :open="$stage === 'prepare' && ! $faultReport->owner_version_saved_at">
            @include('corex.rental-fault-reports._tenant-picture')
        </x-wo-panel>
    @endif

    {{-- Fault flow F2 (Johan 2026-10-08) / section 28.2 - what the OWNER will see. The tenant's original above stays exactly as reported; this is the agent's reviewed copy.
         Shown as a preview with "Edit wording"; the visible box is the agent's note (the "add to it" field). The two buttons in "Who goes to look first?" save this too. --}}
    @if($isOpen)
    <x-wo-panel key="ownercopy" title="What the owner will see" :summary="$screen['panels']['ownercopy']" :open="$stage === 'prepare'">
    <div class="rounded-md p-4 space-y-3" id="owner-version-card" data-owner-version>
        @if($faultReport->sent_to_owner_at === null && $faultReport->owner_approval_status === \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED)
            @php
                $ovTitle = old('owner_title', $faultReport->owner_title ?? $faultReport->title);
                // a deliberate blank is kept once the owner version was saved; before that the tenant's words are the starting point
                $ovDescription = old('owner_description', $faultReport->owner_version_saved_at ? $faultReport->owner_description : $faultReport->description);
            @endphp
            <p class="text-xs" style="color: var(--text-muted);">The owner cannot see this fault yet. They read your wording, not the tenant's. Add your note, choose the photos, then send it with the buttons under &ldquo;Who goes to look first?&rdquo; - those save this too.</p>
            @permission('rental_fault_reports.create')
            <form id="owner-version-form" method="POST" action="{{ route('corex.rental-fault-reports.owner-version.store', $faultReport) }}" class="space-y-3" x-data="{ edit: {{ $errors->has('owner_title') || $errors->has('owner_description') ? 'true' : 'false' }} }">
                @csrf
                <div x-show="!edit" class="rounded-md p-3 text-sm space-y-1" style="background: var(--surface-2);" data-owner-preview>
                    <div class="font-medium">{{ trim((string) $ovTitle) !== '' ? $ovTitle : $faultReport->title }}</div>
                    <div style="white-space: pre-line;">{{ trim((string) $ovDescription) !== '' ? $ovDescription : 'No description.' }}</div>
                    <button type="button" class="underline text-xs" style="color: var(--text-muted);" @click="edit = true" data-owner-edit-wording>Edit wording</button>
                </div>
                <div x-show="edit" x-cloak class="space-y-3">
                    <div>
                        <label class="text-xs font-medium">Title the owner sees <span class="font-normal" style="color: var(--text-muted);">(leave as it is to use the fault's title)</span></label>
                        <input type="text" name="owner_title" maxlength="191" value="{{ $ovTitle }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="text-xs font-medium">Description the owner sees</label>
                        <textarea name="owner_description" rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ $ovDescription }}</textarea>
                    </div>
                </div>
                @if($faultReport->photos->isNotEmpty())
                <div>
                    <label class="text-xs font-medium">Photos the owner sees <span class="font-normal" style="color: var(--text-muted);">(none are shared unless ticked)</span></label>
                    <div class="flex flex-wrap gap-3 mt-1">
                        @foreach($faultReport->photos as $photo)
                            <div class="text-xs flex flex-col items-center gap-1">
                                <button type="button" data-open-photo="{{ $photo->id }}" aria-label="Open photo {{ $loop->iteration }}" class="p-0 bg-transparent border-0 cursor-zoom-in">
                                    <img src="{{ $vp[$photo->id]['thumb_url'] ?? $photo->displayUrl() }}" alt="Photo {{ $loop->iteration }}" loading="lazy" class="rounded-md object-cover" style="width: 90px; height: 70px; border: 1px solid var(--border);">
                                </button>
                                <label><input type="checkbox" name="owner_photo_ids[]" value="{{ $photo->id }}" @checked(in_array($photo->id, (array) old('owner_photo_ids', $faultReport->owner_photo_ids ?? []), true))> Share</label>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
                <div>
                    <label class="text-xs font-medium">Your note / recommendation to the owner <span class="font-normal" style="color: var(--text-muted);">(added to what they read)</span></label>
                    <textarea name="owner_agent_note" rows="3" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. I recommend we approve this - a plumber can fix it this week.">{{ old('owner_agent_note', $faultReport->owner_agent_note) }}</textarea>
                </div>
                <div class="cx-action-row" data-owner-version-actions>
                    <button type="submit" class="corex-btn-outline text-xs" data-owner-version-save>Save wording</button>
                </div>
            </form>
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
                    <div class="flex flex-wrap gap-2 pt-1">@foreach($ov['photos'] as $photo)<button type="button" data-open-photo="{{ $photo->id }}" aria-label="Open photo" class="p-0 bg-transparent border-0 cursor-zoom-in"><img src="{{ $vp[$photo->id]['thumb_url'] ?? $photo->displayUrl() }}" alt="Photo" loading="lazy" class="rounded-md object-cover" style="width: 90px; height: 70px;"></button>@endforeach</div>
                @endif
                @if($ov['agent_note'])<div class="text-xs pt-1" style="color: var(--text-muted);">Agent note: {{ $ov['agent_note'] }}</div>@endif
            </div>
        @endif
    </div>
    </x-wo-panel>
    @endif

    {{-- section 22.4 - "Who goes to look first?". Only while the fault is open and has no work order; nothing is sent without one of the buttons. --}}
    @if(in_array($stage, ['prepare', 'with_owner'], true) && ! $faultReport->workOrder && ! $faultReport->isTenantSelfResolved())
        <x-wo-panel key="decision" title="Who goes to look first?" :summary="$screen['panels']['decision']" :open="true">
            @include('corex.rental-fault-reports._decision-one')
        </x-wo-panel>
    @elseif($stage === 'declined')
        {{-- section 28.5 - a recorded "Declined" can be changed from the screen (until a work order exists; the owner's own press on their link is final) --}}
        <x-wo-panel key="decision" title="The owner's decision" :summary="$screen['panels']['records']" :open="false">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2" data-change-decision>@include('corex.rental-fault-reports._record-owner-decision')</div>
        </x-wo-panel>
    @endif

    <x-wo-panel key="records" title="Authorisation records" :summary="$screen['panels']['records']" :open="false">
        {{-- §3a.1/§3.4a - the owner's decision, read-only: who, how, when, why, contractor (the Returned half of the bar says it in one line; this is the full record). Every entry stays: a decision that was changed shows both. --}}
        @if($decisionSummary)
        <div class="rounded-md p-3 text-sm space-y-1" style="background: var(--surface-2);" id="owner-decision-card" data-owner-decision-summary>
            <div class="font-medium">{{ $decisionSummary['decision'] === \App\Models\RentalApproval::DECISION_APPROVED ? 'Approved' : 'Declined' }}
                @if($decisionSummary['route'])<span class="font-normal" style="color: var(--text-muted);">&mdash; {{ $decisionSummary['route'] === \App\Models\RentalFaultReport::ROUTE_AGENCY_APPOINTS ? 'the agency appoints the contractor' : 'the owner appoints the contractor' }}</span>@endif
            </div>
            @if($decisionSummary['contractor'])<div>{{ $decisionSummary['contractor'] }}</div>@endif
            @if($decisionSummary['reason'])<div>Reason: {{ $decisionSummary['reason'] }}</div>@endif
            <div class="text-xs" style="color: var(--text-muted);">{{ $decisionSummary['how'] }} &middot; {{ $decisionSummary['via_link'] ? 'by ' . $decisionSummary['by'] : 'recorded by ' . $decisionSummary['by'] }} &middot; {{ $decisionSummary['at']?->format('j M Y H:i') }}</div>
        </div>
        @if($faultReport->approvals->count() > 1)
            <ul class="space-y-1 text-xs" style="color: var(--text-muted);">
                @foreach($faultReport->approvals as $approval)
                    <li>{{ ucfirst($approval->decision) }} &middot; {{ ucfirst(\App\Models\RentalApproval::evidenceLabel($approval->evidence_type)) }} &middot; {{ $approval->decided_at?->format('Y-m-d') }}</li>
                @endforeach
            </ul>
        @endif
        @endif
        @include('corex.rental-fault-reports._authorisation-records')
        @if($authorisations->isEmpty() && $ownerHolds->isEmpty())<p class="text-xs" style="color: var(--text-muted);">Nothing has been authorised or declined yet.</p>@endif
    </x-wo-panel>
    @include('corex.rental-fault-reports._who-pays-summary')

    @php
        // the loose "Tenancy / Item / Reported by / Channel / Captured by / Reported at" lines are gone (they repeat "What the tenant reported"); this card
        // now exists only when it has something the page does not already say
        $detailHas = $faultReport->reportedInspectionObservation?->inspection || $faultReport->workOrder
            || $faultReport->outcome || $faultReport->status === 'cancelled'
            || ($faultReport->status === \App\Models\RentalFaultReport::STATUS_REPORTED && ! in_array($faultReport->reported_by_type, [\App\Models\RentalFaultReport::REPORTED_BY_TENANT, \App\Models\RentalFaultReport::REPORTED_BY_LANDLORD], true));
    @endphp
    @if($detailHas)
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
        <div class="grid grid-cols-2 gap-3 text-sm" x-show="!editing">
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

    </div>
    @endif

    {{-- §3a.2/§0c — the spine. Always reachable while the report is open, regardless of approval state. Section 28.1: folded while a work order is live
         (the outcome is set automatically when the work is completed) and for an owner-declined fault that closed itself. --}}
    @if((!in_array($faultReport->status, ['resolved', 'cancelled'], true) && in_array($stage, ['work_order', 'declined'], true)) || ($faultReport->status === 'resolved' && $faultReport->outcome_set_automatically))
    @permission('rental_fault_reports.resolve')
    <x-wo-panel key="outcome" title="Outcome" :summary="$screen['panels']['outcome']" :open="false">
    <div class="rounded-md p-4 space-y-3">
        @if($faultReport->outcome_set_automatically)
            <p class="text-xs" style="color: var(--text-muted);" data-outcome-automatic>Closed automatically as <strong>{{ str_replace('_', ' ', $faultReport->outcome) }}</strong> {{ $declinedClosed ? 'when the owner declined. Nothing more is needed; the tenant sees a neutral "not approved" line. Change the outcome only if that is not right.' : 'when the work order was completed. If the repair was not complete, change it here.' }}</p>
        @elseif($stage === 'work_order')
            <p class="text-xs" style="color: var(--text-muted);" data-outcome-automatic>Set automatically when the work order is completed. Change it here only if that is not right.</p>
        @endif
        @if($declinedClosed || $stage === 'work_order')<details data-change-outcome @if($errors->has('outcome')) open @endif><summary class="text-xs cursor-pointer underline" style="color: var(--text-muted);">{{ $stage === 'work_order' && ! $faultReport->outcome_set_automatically ? 'Set the outcome by hand' : 'Change the outcome' }}</summary><div class="pt-2">@endif
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
                    {{-- .ai/specs/rentals-faults-work-orders.md §4.4 — still logged, for evidence, when the tenant's first-aid steps were enough. --}}
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
        @if($declinedClosed || $stage === 'work_order')</div></details>@endif
    </div>
    </x-wo-panel>
    @endpermission
    @endif

    <x-wo-panel key="photos" title="Photos" :summary="$screen['panels']['photos']" :open="false">
    <div class="rounded-md p-4 space-y-3">
        @if($faultReport->photos->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
        @else
            <div class="grid grid-cols-2 gap-2">
                @foreach($faultReport->photos as $photo)
                    <button type="button" data-open-photo="{{ $photo->id }}" aria-label="Open photo {{ $loop->iteration }}" class="p-0 bg-transparent border-0 cursor-zoom-in">
                        <img src="{{ $vp[$photo->id]['thumb_url'] ?? $photo->displayUrl() }}" alt="Photo {{ $loop->iteration }}" loading="lazy" class="rounded-md w-full h-24 object-cover">
                        @php($pv = $vp[$photo->id] ?? null)
                        @if($pv && (count($pv['marks']) || $pv['note']))<span class="text-xs block" style="color: var(--text-muted);">{{ count($pv['marks']) ? count($pv['marks']) . ' mark' . (count($pv['marks']) > 1 ? 's' : '') : '' }}{{ count($pv['marks']) && $pv['note'] ? ' · ' : '' }}{{ $pv['note'] ? 'note' : '' }}</span>@endif
                    </button>
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
    </x-wo-panel>

    {{-- Johan, 2026-09-22 — "who did what": a plain chronological history, not a status badge on every row. RentalFaultReport::history() merges
         creation, every logged update, and every approval decision into one timeline, oldest first. Shown regardless of status. Collapsed by default (section 28.1). --}}
    <x-wo-panel key="history" title="History" :summary="$screen['panels']['history']" :open="false">
    <div class="rounded-md p-4 space-y-3">
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
    </x-wo-panel>
</div>

{{-- AT-452 round 2 (H) - "Add a contractor" / "Add <type> to an existing contractor" open over this screen, never navigate away (no form on this page is touched). --}}
@if(!empty($canManageContractors))
    @include('partials.contractor-popups')
@endif

{{-- AT-452 - the shared photo viewer (popup, never a new tab). Staff who may edit the fault get the marks tools; everyone here reads the marks. --}}
@include('partials.photo-viewer')
<script type="application/json" id="fault-photo-data">@json($viewerPhotos)</script>
<script>
(function () {
    var photos = JSON.parse(document.getElementById('fault-photo-data').textContent || '[]');
    var editable = @json((bool) $canMarkPhotos);
    var base = @json(route('corex.rental-fault-reports.show', $faultReport));
    var csrf = @json(csrf_token());
    function call(method, path, body) {
        return fetch(base + path, {
            method: method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                if (r.ok) return d;
                var m = d && d.errors ? Object.keys(d.errors).map(function (k) { return d.errors[k][0]; })[0] : (d && d.message);
                throw new Error(m || 'That could not be saved. Please try again.');
            });
        });
    }
    var handlers = {
        addMark: function (p, m) { return call('POST', '/photos/' + p.id + '/marks', m).then(function (d) { return d.mark; }); },
        updateMark: function (p, m) { return call('PUT', '/photos/' + p.id + '/marks/' + m.id, { shape: m.shape, geometry: m.geometry, colour: m.colour }).then(function (d) { return d.mark; }); },
        removeMark: function (p, m) { return call('DELETE', '/photos/' + p.id + '/marks/' + m.id); },
        restoreMark: function (p, m) { return call('POST', '/photos/' + p.id + '/marks/' + m.id + '/restore').then(function (d) { return d.mark; }); },
        saveNote: function (p, text) { return call('PUT', '/photos/' + p.id + '/note', { description: text }).then(function (d) { return d.note; }); },
        clearNote: function (p) { return call('DELETE', '/photos/' + p.id + '/note'); },
    };
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-open-photo]') : null;
        if (!b) return;
        e.preventDefault();
        var id = parseInt(b.getAttribute('data-open-photo'), 10);
        var i = photos.findIndex(function (p) { return p.id === id; });
        if (i < 0) return;
        window.CorexPhotoViewer.open({ photos: photos, index: i, editable: editable, handlers: handlers, returnFocus: b });
    });
})();
</script>
<script>
// section 28.1 - the Returned | Next bar's button (and any "#..." link) opens the folded panel that holds its target, scrolls there and - when the button names one -
// presses the control that was already on the page (the confirm dialog still asks before anything is sent). Nothing here submits anything by itself.
(function () {
    function openAround(el) { for (var d = el; d; d = d.parentElement) { if (d.tagName === 'DETAILS') { d.open = true; } } }
    function press(root, list) {
        list.split(';').map(function (x) { return x.trim(); }).filter(Boolean).forEach(function (sel) {
            var t = root.querySelector(sel) || document.querySelector(sel);
            if (!t) return;
            if (t.tagName === 'SUMMARY') { t.parentElement.open = true; }
            else if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') { t.focus(); }
            else { t.click(); }
        });
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
