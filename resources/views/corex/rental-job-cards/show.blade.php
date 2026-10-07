@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14 (AT-442), REBUILT 2026-10-05 — Johan
    rejected the original two-screen (create.blade.php + this file), flat-
    Tasks-checklist design after testing it on QA1. ONE screen now: this
    same file renders both create (RentalJobCardController::create(),
    $jobCard null, a draft context) and edit ($jobCard set). Nothing is
    saved in draft mode until the single Save button below submits the
    whole job — property/lease/source, every task, every task's own lines,
    and the General (task-less) lines — in one POST to store(), which
    creates the card and all of it in one transaction
    (RentalJobCardService::createStandalone()).

    Structure, top to bottom, matches Johan's own numbered spec exactly:
    1. THE JOB — property, tenant+landlord, access notes, SOURCE in full.
    2. TASKS — numbered, each owning its own parts & labour lines; a
       built-in "General" group for lines with no task; grand totals.
    3. WHO & WHEN, one line (edit mode only — nothing to show in draft).
    4. Next steps, shown only when they apply, in order: send quote to
       owner, worker sign-off, agent sign-off, tenant confirmation,
       complete; Cancel as a quiet secondary action.
    5. Photos and History, collapsed at the bottom (edit mode only).
--}}

@php
    $isDraft = $jobCard === null;
    $displayProperty = $jobCard?->property ?? $property ?? null;
    $displayLease = $jobCard?->lease ?? $lease ?? null;
    $displayFaultReport = $jobCard?->rentalFaultReport ?? $faultReport ?? null;
    $displayWorkOrder = $jobCard?->workOrder ?? $workOrder ?? null;
    $landlords = $displayLease
        ? $displayLease->landlordContacts()
        : ($displayProperty ? collect([$displayProperty->landlordContact()])->filter() : collect());

    $statusBadgeClass = $jobCard ? match ($jobCard->status) {
        'completed' => 'ds-badge-success',
        'cancelled', 'disputed' => 'ds-badge-danger',
        'in_progress', 'scheduled' => 'ds-badge-info',
        default => 'ds-badge-muted',
    } : null;
    $isOpen = $jobCard && !in_array($jobCard->status, ['completed', 'cancelled'], true);

    $jcAgency = $jobCard?->agency ?? ($property?->agency ?? auth()->user()->agency);
    $catalogueItemsJson = $catalogueItems->map(fn ($ci) => [
        'id' => $ci->id, 'code' => $ci->code, 'description' => $ci->description, 'label' => $ci->label(),
        'kind' => $ci->kind(), 'unit' => $ci->catalogueUnit?->name,
        'priceForLine' => $jcAgency ? app(\App\Services\Rentals\RentalJobCardVatService::class)->catalogueDefaultPriceForLine($ci, $jcAgency) : ($ci->default_price !== null ? (float) $ci->default_price : null),
        // §17.4.4 — the catalogue's default COST prefills the Cost box, but only for someone who can see and set costs.
        'costForLine' => (($canViewCosts ?? false) && ($canPrice ?? false) && $jcAgency) ? app(\App\Services\Rentals\RentalJobCardVatService::class)->catalogueDefaultCostForLine($ci, $jcAgency) : null,
        'vatTypeId' => $ci->default_rental_vat_type_id,
        'customVatRate' => $ci->default_custom_vat_rate !== null ? (float) $ci->default_custom_vat_rate : null,
    ])->values();
    $draftTasksJson = collect($draftTasks ?? [])->map(fn ($d) => ['description' => $d, 'lines' => []])->values();
@endphp

@section('content')
<div class="p-6 max-w-7xl mx-auto space-y-4">
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <x-rental-context-bar :property="$displayProperty" :lease="$displayLease" current="work_orders" />

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $jobCard?->title ?? 'New job card' }}</h1>
            @if($jobCard)
                <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $jobCard->status)) }}</span>
                @if($jobCard->worker_signed_off_at)
                    <span class="ds-badge ds-badge-success" title="{{ $jobCard->worker_signed_off_at->format('Y-m-d H:i') }}">Crew completed — {{ $jobCard->crewCompletionLabel() }}</span>
                @endif
                @if($currentQuote)
                    {{-- §14.21 — which revision the owner currently holds, and whether the card has moved on since. --}}
                    <span class="ds-badge ds-badge-muted" title="Last quote sent {{ $currentQuote->created_at?->format('Y-m-d H:i') }}">Quote Rev {{ $currentQuote->revision }}</span>
                    @if($quoteChanged)
                        <span class="ds-badge" style="background: color-mix(in srgb, #f59e0b 18%, transparent); color: #b45309;">Changed since sent</span>
                    @endif
                @endif
                {{-- §17.5.5 — where the crew's pricing stands, at a glance. --}}
                @if($openPriceRequest ?? null)
                    <span class="ds-badge" style="background: color-mix(in srgb, #f59e0b 18%, transparent); color: #b45309;" data-chip-pricing>Pricing requested</span>
                @elseif(($awaitingLines ?? collect())->isNotEmpty())
                    <span class="ds-badge" style="background: color-mix(in srgb, #f59e0b 18%, transparent); color: #b45309;" data-chip-pricing>Priced by crew — awaiting you</span>
                @endif
                <span class="text-xs" style="color: var(--text-muted);">{{ $jobCard->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $jobCard->property?->trashed() ? ' (archived)' : '' }}</span>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($jobCard)
                <a href="{{ route('corex.rental-job-cards.print', $jobCard) }}" target="_blank" class="corex-btn-outline text-xs">Print job card</a>
                @if($isOpen && $crewLinksEnabled)
                    @permission('rental_job_cards.share')
                    <a href="{{ route('corex.rental-job-cards.print', ['rentalJobCard' => $jobCard, 'with_link' => 1]) }}" target="_blank" class="corex-btn-outline text-xs" onclick="return confirm('Print with a crew link? This creates a new link (the old one stops working) and puts its QR code on the paper.');">Print with link</a>
                    @endpermission
                @endif
                @if($jobCard->rental_work_order_id)
                    <a href="{{ route('corex.rental-work-orders.show', $jobCard->rental_work_order_id) }}" class="corex-btn-outline text-xs">View work order</a>
                @endif
            @endif
            <a href="{{ route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">&larr; All job cards</a>
        </div>
    </div>

    {{--
        2026-10-05 round 2 (Johan, real-browser check) — same pattern as
        the Command Centre (resources/views/corex/rentals/command-centre/
        index.blade.php): left and right columns scroll INDEPENDENTLY,
        each filling the viewport below the header, no whole-page scroll.
        #jc-layout/#jc-left-col/#jc-right-col sizing JS at the bottom of
        this file is the identical measure-actual-#appScroll-overflow
        technique that screen's own JS comment explains in full (flat
        `innerHeight - top` alone leaves an outer scrollbar — it can't see
        every padding layer between here and the viewport's bottom edge).
        Below the lg breakpoint (<1024px) this reverts to plain stacked
        page scroll — the JS below never applies a fixed height there.
        Right panel narrowed (lg:basis-[300px], same shape queue panel
        already uses) — Johan: "shave the right-hand panel's width a
        little to give the left column room" for the add-line row.
    --}}
    <div x-data="rentalJobCardBuilder({
            isDraft: {{ $isDraft ? 'true' : 'false' }},
            draftTasks: {{ $draftTasksJson->toJson() }},
        })" id="jc-layout" class="flex flex-col lg:flex-row gap-4 items-stretch" style="min-height:0;">
        <div id="jc-left-col" class="w-full lg:flex-1 min-w-0 space-y-4 lg:overflow-y-auto" style="min-height:0;">

            {{-- 1. THE JOB --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">The job</h2>

                @if($isDraft)
                    <form id="job-card-create-form" method="POST" action="{{ route('corex.rental-job-cards.store') }}">
                        @csrf
                        @if($displayFaultReport)<input type="hidden" name="fault_report_id" value="{{ $displayFaultReport->id }}">@endif
                        @if($displayWorkOrder)<input type="hidden" name="rental_work_order_id" value="{{ $displayWorkOrder->id }}">@endif
                        @if($displayLease)<input type="hidden" name="lease_id" value="{{ $displayLease->id }}">@endif

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-medium">Property</label>
                                @if($displayProperty)
                                    <input type="hidden" name="property_id" value="{{ $displayProperty->id }}">
                                    <div class="text-sm mt-1">{{ $displayProperty->buildDisplayAddress() }}</div>
                                @else
                                    <div class="relative mt-1" x-data="{ q: '', results: [], open: false }">
                                        <input type="hidden" name="property_id" x-ref="propertyId">
                                        <input type="text" x-model="q" @input.debounce.300ms="
                                                if (q.length < 2) { results = []; open = false; return; }
                                                fetch('{{ route('corex.rental-job-cards.search-properties') }}?q=' + encodeURIComponent(q))
                                                    .then(r => r.json()).then(data => { results = data; open = true; });
                                            "
                                            placeholder="Search for a property…" required
                                            class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                                        <div x-show="open && results.length" x-cloak class="absolute z-10 w-full mt-1 rounded-md text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                                            <template x-for="r in results" :key="r.id">
                                                <button type="button" class="block w-full text-left px-3 py-2 hover:underline"
                                                        @click="$refs.propertyId.value = r.id; q = r.label; open = false;" x-text="r.label"></button>
                                            </template>
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <div>
                                <label class="text-xs font-medium">Tenancy</label>
                                @if($displayLease)
                                    <div class="text-sm mt-1">{{ $displayLease->tenantNames() }}</div>
                                @elseif($displayProperty)
                                    <select name="lease_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                                        <option value="">— No tenancy (vacancy period) —</option>
                                        @foreach(\App\Models\Lease::where('property_id', $displayProperty->id)->orderByDesc('start_date')->limit(50)->get() as $l)
                                            <option value="{{ $l->id }}">{{ $l->tenantNames() }} ({{ $l->start_date?->format('Y-m-d') }}&ndash;{{ $l->end_date?->format('Y-m-d') ?? 'ongoing' }})</option>
                                        @endforeach
                                    </select>
                                @else
                                    <div class="text-sm mt-1" style="color: var(--text-muted);">—</div>
                                @endif
                            </div>
                        </div>

                        <div class="text-sm">
                            <span style="color: var(--text-muted);">Landlord:</span>
                            {{ $landlords->isNotEmpty() ? $landlords->map(fn ($c) => $c->full_name)->implode(', ') : 'No landlord linked' }}
                        </div>

                        <div>
                            <label class="text-xs font-medium">Title</label>
                            <input type="text" name="title" required maxlength="191" value="{{ old('title', $displayFaultReport?->title ?? $displayWorkOrder?->title) }}" placeholder="e.g. Paint lounge and fix floor" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-xs font-medium">Access notes</label>
                            <textarea name="access_notes" rows="2" placeholder="Gate code, dog on site, tenant works from home, etc." class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
                        </div>
                    </form>
                @else
                    <div class="grid grid-cols-2 gap-3 text-sm" x-data="{ editing: false }">
                        <div x-show="!editing"><span style="color: var(--text-muted);">Tenancy:</span> {{ $jobCard->lease?->tenantNames() ?? 'None — vacancy period' }}</div>
                        <div x-show="!editing"><span style="color: var(--text-muted);">Landlord:</span> {{ $landlords->isNotEmpty() ? $landlords->map(fn ($c) => $c->full_name)->implode(', ') : 'No landlord linked' }}</div>
                        <div x-show="!editing" class="col-span-2"><span style="color: var(--text-muted);">Access notes:</span> {{ $jobCard->access_notes ?? '—' }}</div>

                        @permission('rental_job_cards.create')
                        @if($jobCard->status === \App\Models\RentalJobCard::STATUS_DRAFT)
                        <div x-show="!editing" class="col-span-2">
                            <button type="button" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
                        </div>
                        <form data-keep-scroll x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-job-cards.update', $jobCard) }}" class="col-span-2 space-y-3">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="text-xs font-medium">Title</label>
                                <input type="text" name="title" required maxlength="191" value="{{ old('title', $jobCard->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs font-medium">Access notes</label>
                                <textarea name="access_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('access_notes', $jobCard->access_notes) }}</textarea>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                                <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
                            </div>
                        </form>
                        @endif
                        @endpermission
                    </div>

                    @if($jobCard->status === 'cancelled')
                        <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $jobCard->cancelled_at?->format('Y-m-d') }} by {{ $jobCard->cancelledByUser?->name }}: {{ $jobCard->cancel_reason }}</p>
                    @endif
                @endif

                {{-- Source — "the fault report and/or work order it came from, or 'No source — created directly'." --}}
                <div class="pt-2 space-y-2" style="border-top: 1px solid var(--border);">
                    <h3 class="text-xs font-semibold" style="color: var(--text-muted);">Source</h3>
                    @if(!$displayFaultReport && !$displayWorkOrder)
                        <p class="text-sm">No source — created directly.</p>
                    @else
                        @if($displayFaultReport)
                            <div class="text-sm space-y-1 rounded-md p-2" style="background: var(--surface-alt, rgba(0,0,0,.02)); border: 1px solid var(--border);">
                                <div>
                                    <a href="{{ route('corex.rental-fault-reports.show', $displayFaultReport) }}" class="underline">Fault report #{{ $displayFaultReport->id }}</a>
                                    — {{ $displayFaultReport->title }}
                                </div>
                                <div style="color: var(--text-muted);">{{ $displayFaultReport->description }}</div>
                                <div style="color: var(--text-muted);">
                                    Reported by {{ $displayFaultReport->reportedByContact?->full_name ?? $displayFaultReport->reportedByUser?->name ?? 'unknown' }}
                                    @if($displayFaultReport->reported_at) on {{ $displayFaultReport->reported_at->format('Y-m-d H:i') }} @endif
                                </div>
                                @if($displayFaultReport->photos->isNotEmpty())
                                    <div class="flex gap-2 pt-1">
                                        @foreach($displayFaultReport->photos as $photo)
                                            <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded w-16 h-16 object-cover"></a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                        @if($displayWorkOrder)
                            <div class="text-sm space-y-1 rounded-md p-2" style="background: var(--surface-alt, rgba(0,0,0,.02)); border: 1px solid var(--border);">
                                <div>
                                    <a href="{{ route('corex.rental-work-orders.show', $displayWorkOrder) }}" class="underline">Work order #{{ $displayWorkOrder->id }}</a>
                                    — {{ $displayWorkOrder->title }}
                                </div>
                                <div style="color: var(--text-muted);">{{ $displayWorkOrder->description }}</div>
                                <div style="color: var(--text-muted);">
                                    Reported {{ ucfirst(str_replace('_', ' ', $displayWorkOrder->reported_by_type)) }}
                                    @if($displayWorkOrder->reported_at) on {{ $displayWorkOrder->reported_at->format('Y-m-d H:i') }} @endif
                                </div>
                            </div>
                        @endif
                        @if($jobCard?->workOrder?->reportedInspectionObservation?->inspection)
                            <div class="text-sm">
                                <span style="color: var(--text-muted);">From inspection:</span>
                                <a href="{{ route('corex.rental-inspections.show', $jobCard->workOrder->reportedInspectionObservation->inspection) }}" class="underline">{{ ucfirst(str_replace('_', '-', $jobCard->workOrder->reportedInspectionObservation->inspection->type)) }}-inspection {{ $jobCard->workOrder->reportedInspectionObservation->inspection->scheduled_for?->format('Y-m-d') ?? $jobCard->workOrder->reportedInspectionObservation->inspection->created_at?->format('Y-m-d') }}</a>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            {{-- 2. TASKS — each owns its own parts & labour lines --}}
            <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Tasks</h2>

                @if($isDraft)
                    {{-- Client-side only — nothing posted here exists until the Save button submits job-card-create-form below. --}}
                    <template x-for="(task, ti) in tasks" :key="task.key">
                        <div class="rounded-md p-3 space-y-2" style="border: 1px solid var(--border);" :data-draft-task-index="ti">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium" x-text="(ti + 1) + ' -'"></span>
                                <input type="text" :name="`tasks[${ti}][description]`" x-model="task.description" form="job-card-create-form" required maxlength="500" class="flex-1 rounded-md px-2 py-1.5 text-sm" style="border: 1px solid var(--border);">
                                <button type="button" @click="removeTask(ti)" class="text-xs" style="color: var(--ds-red, #dc2626);">Remove</button>
                            </div>

                            <table class="w-full text-xs" x-show="task.lines.length">
                                <template x-for="(line, li) in task.lines" :key="line.key">
                                    <tr style="border-top: 1px solid var(--border);">
                                        <td class="py-1" x-text="line.description"></td>
                                        <td class="py-1" x-text="line.quantity + ' ' + (line.unit || '')"></td>
                                        <td class="py-1 text-right"><button type="button" @click="task.lines.splice(li, 1)" style="color: var(--ds-red, #dc2626);">Remove</button></td>
                                    </tr>
                                </template>
                            </table>

                            @include('corex.rental-job-cards._add-line-row', [
                                'mode' => 'draft', 'refPrefix' => 'free', 'taskExpr' => 'task',
                                'addLineCall' => "addLineFromRow(task, \$refs, 'free')",
                                'catalogueItemsForJs' => $catalogueItemsJson, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits,
                                'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vatRegistered,
                            ])
                            {{-- The hidden, bracket-indexed inputs Laravel actually parses on submit. --}}
                            <template x-for="(line, li) in task.lines" :key="'f-' + line.key">
                                <div>
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][rental_catalogue_item_id]`" :value="line.catalogueItemId" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][description]`" :value="line.description" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][type]`" :value="line.type" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][unit]`" :value="line.unit" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][quantity]`" :value="line.quantity" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][unit_price]`" :value="line.unitPrice" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][rental_vat_type_id]`" :value="line.vatTypeId" form="job-card-create-form">
                                </div>
                            </template>
                        </div>
                    </template>
                    <button type="button" @click="addTask()" class="corex-btn-outline text-xs">+ Add task</button>

                    {{-- General — lines with no task (e.g. a call-out fee). --}}
                    <div class="rounded-md p-3 space-y-2" style="border: 1px dashed var(--border);">
                        <span class="text-sm font-medium">General</span>
                        <table class="w-full text-xs" x-show="generalLines.length">
                            <template x-for="(line, li) in generalLines" :key="line.key">
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-1" x-text="line.description"></td>
                                    <td class="py-1" x-text="line.quantity + ' ' + (line.unit || '')"></td>
                                    <td class="py-1 text-right"><button type="button" @click="generalLines.splice(li, 1)" style="color: var(--ds-red, #dc2626);">Remove</button></td>
                                </tr>
                            </template>
                        </table>
                        @include('corex.rental-job-cards._add-line-row', [
                            'mode' => 'draft', 'refPrefix' => 'gen', 'taskExpr' => 'null',
                            'addLineCall' => "addLineFromRow(null, \$refs, 'gen')",
                            'catalogueItemsForJs' => $catalogueItemsJson, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits,
                            'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vatRegistered,
                        ])
                        <template x-for="(line, li) in generalLines" :key="'f-' + line.key">
                            <div>
                                <input type="hidden" :name="`general_lines[${li}][rental_catalogue_item_id]`" :value="line.catalogueItemId" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][description]`" :value="line.description" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][type]`" :value="line.type" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][unit]`" :value="line.unit" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][quantity]`" :value="line.quantity" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][unit_price]`" :value="line.unitPrice" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][rental_vat_type_id]`" :value="line.vatTypeId" form="job-card-create-form">
                            </div>
                        </template>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <a href="{{ $displayProperty ? route('corex.properties.show', $displayProperty->id) : route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">Cancel</a>
                        <button type="submit" form="job-card-create-form" class="corex-btn-primary text-xs">Save</button>
                    </div>
                @else
                    @php $compareTotal = $vat['registered'] ? (float) $vat['totalIncl'] : (float) ($jobCard->total_amount ?? 0); @endphp

                    @forelse($jobCard->tasks as $task)
                        <div class="rounded-md p-3 space-y-2" style="border: 1px solid var(--border);" x-data="{ renaming: false }" data-task-id="{{ $task->id }}">
                            <div class="flex items-center justify-between gap-2">
                                @if($isOpen)
                                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.toggle', [$jobCard, $task]) }}" class="flex items-center gap-2 flex-1">
                                    @csrf
                                    <button type="submit" class="flex items-center gap-2 text-left">
                                        {{-- onclick="return false" used to swallow the click, so ticking only worked on the button's padding around the box — clicking the box itself did nothing. The box now submits the form itself (requestSubmit keeps the data-keep-scroll handler); the box state shown always comes from the server after the reload. --}}
                                        <input type="checkbox" @checked($task->is_done) onclick="event.preventDefault(); this.form.requestSubmit();" class="rounded">
                                    </button>
                                    <span class="text-sm font-medium" x-show="!renaming">{{ $loop->iteration }} - <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span></span>
                                </form>
                                @else
                                {{-- §14.21 — a closed card is a read-only record: no tick, no rename, no archive. --}}
                                <div class="flex items-center gap-2 flex-1">
                                    <input type="checkbox" @checked($task->is_done) disabled class="rounded">
                                    <span class="text-sm font-medium">{{ $loop->iteration }} - <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span></span>
                                </div>
                                @endif
                                @permission('rental_job_cards.create')
                                @if($isOpen)
                                <div class="flex items-center gap-2">
                                    <button type="button" x-show="!renaming" @click="renaming = true" class="text-xs">Rename</button>
                                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.destroy', [$jobCard, $task]) }}" onsubmit="return confirm('Archive this task and its lines?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                                    </form>
                                </div>
                                @endif
                                @endpermission
                            </div>
                            @permission('rental_job_cards.create')
                            @if($isOpen)
                            <form data-keep-scroll x-show="renaming" x-cloak method="POST" action="{{ route('corex.rental-job-cards.tasks.update', [$jobCard, $task]) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="text" name="description" value="{{ $task->description }}" maxlength="500" required class="flex-1 rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                <button type="submit" class="corex-btn-outline text-xs">Save</button>
                                <button type="button" @click="renaming = false" class="corex-btn-outline text-xs">Cancel</button>
                            </form>
                            @endif
                            @endpermission

                            @include('corex.rental-job-cards._line-columns-header', ['pricesOn' => $pricesOn, 'vatRegistered' => $vat['registered'], 'showCost' => $canViewCosts ?? false])
                            @include('corex.rental-job-cards._lines-table', ['lines' => $task->acceptedLines, 'pricesOn' => $pricesOn, 'vat' => $vat, 'jobCard' => $jobCard, 'canEditLines' => $isOpen, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'vatTypes' => $vatTypes, 'showCost' => $canViewCosts ?? false, 'canPrice' => $canPrice ?? false, 'costVat' => $costVat ?? null])

                            @if($pricesOn)
                                <div class="text-xs text-right" style="color: var(--text-muted);">Task subtotal: R{{ number_format($task->subtotal(), 2) }}</div>
                            @endif

                            @permission('rental_job_cards.create')
                            @if($isOpen)
                            @include('corex.rental-job-cards._add-line-row', ['mode' => 'form', 'action' => route('corex.rental-job-cards.lines.store', $jobCard), 'taskId' => $task->id, 'catalogueItemsForJs' => $catalogueItemsJson, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered'], 'showCost' => $canViewCosts ?? false, 'canPrice' => $canPrice ?? false])
                            @endif
                            @endpermission
                        </div>
                    @empty
                        <p class="text-xs" style="color: var(--text-muted);">No tasks yet.</p>
                    @endforelse

                    @permission('rental_job_cards.create')
                    @if($isOpen)
                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.store', $jobCard) }}" class="flex items-end gap-2">
                        @csrf
                        <input type="text" name="description" required maxlength="500" placeholder="Add a task" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <button type="submit" class="corex-btn-outline text-xs">Add task</button>
                    </form>
                    @endif
                    @if($archivedTasks->isNotEmpty())
                        <button type="button" onclick="document.getElementById('archived-tasks').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedTasks->count() }} archived task(s)</button>
                        <ul id="archived-tasks" class="hidden space-y-1 text-sm pt-1">
                            @foreach($archivedTasks as $at)
                                <li class="flex items-center justify-between gap-2">
                                    <span style="color: var(--text-muted);">{{ $at->description }}</span>
                                    @if($isOpen)
                                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.restore', [$jobCard, $at->id]) }}">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @endpermission

                    {{-- General — lines with no task (e.g. a call-out fee). --}}
                    <div class="rounded-md p-3 space-y-2" style="border: 1px dashed var(--border);">
                        <span class="text-sm font-medium">General</span>
                        @include('corex.rental-job-cards._line-columns-header', ['pricesOn' => $pricesOn, 'vatRegistered' => $vat['registered'], 'showCost' => $canViewCosts ?? false])
                        @include('corex.rental-job-cards._lines-table', ['lines' => $generalLines, 'pricesOn' => $pricesOn, 'vat' => $vat, 'jobCard' => $jobCard, 'canEditLines' => $isOpen, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'vatTypes' => $vatTypes, 'showCost' => $canViewCosts ?? false, 'canPrice' => $canPrice ?? false, 'costVat' => $costVat ?? null])
                        @permission('rental_job_cards.create')
                        @if($isOpen)
                        @include('corex.rental-job-cards._add-line-row', ['mode' => 'form', 'action' => route('corex.rental-job-cards.lines.store', $jobCard), 'taskId' => null, 'catalogueItemsForJs' => $catalogueItemsJson, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered'], 'showCost' => $canViewCosts ?? false, 'canPrice' => $canPrice ?? false])
                        @endif
                        @endpermission
                        @if($archivedLines->isNotEmpty())
                            <button type="button" onclick="document.getElementById('archived-lines').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedLines->count() }} archived line(s)</button>
                            <ul id="archived-lines" class="hidden space-y-1 text-sm pt-1">
                                @foreach($archivedLines as $al)
                                    <li class="flex items-center justify-between gap-2">
                                        <span style="color: var(--text-muted);">{{ $al->description }}</span>
                                        @permission('rental_job_cards.create')
                                        @if($isOpen)
                                        <form method="POST" action="{{ route('corex.rental-job-cards.lines.restore', [$jobCard, $al->id]) }}" data-keep-scroll>
                                            @csrf
                                            <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                        </form>
                                        @endif
                                        @endpermission
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Grand totals --}}
                    @if($pricesOn)
                    <div class="pt-2 space-y-1 text-sm" style="border-top: 1px solid var(--border);">
                        @if($vat['registered'])
                            <div class="flex justify-between"><span>Subtotal (excl VAT)</span><span>R{{ number_format((float) $vat['subtotalExcl'], 2) }}</span></div>
                            @foreach($vat['groups'] as $group)
                                <div class="flex justify-between" style="color: var(--text-muted);"><span>{{ $group['label'] }}</span><span>R{{ number_format((float) $group['amount'], 2) }}</span></div>
                            @endforeach
                        @endif
                        <div class="flex justify-between font-semibold">
                            <span>{{ $vat['registered'] ? 'Total (incl VAT)' : 'Total' }}</span>
                            <span>R{{ number_format($compareTotal, 2) }}</span>
                        </div>
                        {{-- §17.4.5 — cost and margin (excl VAT) exist in the page only for `rental_job_cards.view_costs`. --}}
                        @if(($canViewCosts ?? false) && $margin)
                            <div class="pt-2 mt-2 space-y-0.5 text-xs" style="border-top: 1px dashed var(--border);" data-cost-margin-totals>
                                <div class="flex justify-between"><span>Cost total{{ $costVat && $costVat['registered'] ? ' (excl VAT)' : '' }}</span><span>{{ $margin['marginableLines'] > 0 || $margin['costExcl'] > 0 ? 'R' . number_format($margin['costExcl'], 2) : '—' }}</span></div>
                                <div class="flex justify-between font-semibold"><span>Margin (excl VAT)</span>
                                    <span>{{ $margin['marginableLines'] > 0 ? 'R' . number_format($margin['marginExcl'], 2) . ($margin['marginPct'] !== null ? ' (' . $margin['marginPct'] . ' %)' : '') : '— no cost recorded' }}</span></div>
                                @if($margin['linesWithoutCost'] > 0)
                                    <div style="color: var(--ds-crimson);" data-lines-without-cost>{{ $margin['linesWithoutCost'] }} {{ $margin['linesWithoutCost'] === 1 ? 'line has' : 'lines have' }} no cost recorded — the margin above covers the other lines only.</div>
                                @endif
                            </div>
                        @endif
                        <div class="text-xs text-right" style="color: {{ $compareTotal <= $noApprovalThreshold ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">
                            @if($compareTotal <= $noApprovalThreshold)
                                Within the landlord's R{{ number_format($noApprovalThreshold, 2) }} no-approval limit (incl VAT)
                            @else
                                Over the landlord's R{{ number_format($noApprovalThreshold, 2) }} no-approval limit (incl VAT) — owner approval required
                            @endif
                        </div>
                    </div>
                    @endif
                @endif
            </div>

            @if($jobCard)
            {{-- 5. Photos — collapsed --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <button type="button" onclick="document.getElementById('jc-photos').classList.toggle('hidden')" class="text-sm font-semibold w-full text-left">Photos ({{ $jobCard->photos->count() + ($jobCard->workOrder?->photos->count() ?? 0) }})</button>
                <div id="jc-photos" class="hidden space-y-3">
                    @php $allPhotos = $jobCard->photos->concat($jobCard->workOrder?->photos ?? collect()); @endphp
                    @if($allPhotos->isEmpty())
                        <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
                    @else
                        <div class="grid grid-cols-4 gap-2">
                            @foreach($allPhotos as $photo)
                                <div>
                                    <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                                    <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @permission('rental_job_cards.create')
                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.photos.store', $jobCard) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <select name="photo_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                            <option value="reported">Before</option>
                            <option value="in_progress">In progress</option>
                            <option value="completed">Completed (after)</option>
                        </select>
                        <input type="file" name="photo" accept="image/*" required class="text-xs">
                        <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
                    </form>
                    @endpermission
                </div>
            </div>

            {{-- 5. History — collapsed --}}
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <button type="button" onclick="document.getElementById('jc-history').classList.toggle('hidden')" class="text-sm font-semibold w-full text-left">History</button>
                <ul id="jc-history" class="hidden space-y-1 text-sm">
                    @foreach($jobCard->history() as $entry)
                        <li>
                            <span style="color: var(--text-muted);">{{ $entry['at']->format('Y-m-d H:i') }}</span>
                            — {{ $entry['action'] }}
                            @if($entry['from'] || $entry['to'])({{ $entry['from'] ?? '—' }} &rarr; {{ $entry['to'] ?? '—' }})@endif
                            @if($entry['note'])— {{ $entry['note'] }}@endif
                            @if($entry['actor'])<span style="color: var(--text-muted);">({{ $entry['actor'] }})</span>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>

        @if($jobCard)
        <div id="jc-right-col" class="w-full lg:basis-[300px] lg:max-w-[320px] lg:flex-shrink-0 lg:grow-0 space-y-4 lg:overflow-y-auto" style="min-height:0;">
            {{-- 3. WHO & WHEN, one line --}}
            <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm flex flex-wrap gap-3">
                    @if($jobCard->crew)
                        <span><span style="color: var(--text-muted);">Crew:</span> {{ $jobCard->crew->name }}@if($jobCard->crew->trashed()) <span style="color: var(--text-muted);">(archived)</span>@endif</span>
                    @elseif($jobCard->assigned_user_id)
                        {{-- 2026-10-05 — legacy only: a card assigned before crews existed. Read-only — never re-selectable, never written to again. --}}
                        <span style="color: var(--text-muted);">Previously assigned: {{ $jobCard->assignedUser?->name ?? '—' }}</span>
                    @else
                        <span><span style="color: var(--text-muted);">Crew:</span> —</span>
                    @endif
                    <span><span style="color: var(--text-muted);">Scheduled:</span> {{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    <span><span style="color: var(--text-muted);">Due:</span> {{ $jobCard->due_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
                @if($jobCard->crew && $jobCard->crew->members->isNotEmpty())
                    <div class="text-xs" style="color: var(--text-muted);">{{ $jobCard->crew->members->pluck('name')->implode(', ') }}</div>
                @endif
                @permission('rental_job_cards.create')
                @if($isOpen)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.assign-crew', $jobCard) }}" class="flex gap-2">
                    @csrf
                    <select name="rental_crew_id" required class="flex-1 rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                        <option value="">Select crew…</option>
                        @foreach($crews as $c)
                            <option value="{{ $c->id }}" @selected($jobCard->rental_crew_id === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="corex-btn-outline text-xs">Assign</button>
                </form>
                @permission('rental_catalogue.manage')
                <a href="{{ route('corex.rental-crews.index') }}" class="text-xs underline" style="color: var(--text-muted);">Manage crews</a>
                @endpermission
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.schedule', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $jobCard->scheduleInputValue('scheduled_at')) }}" aria-label="Scheduled" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <input type="datetime-local" name="due_at" value="{{ old('due_at', $jobCard->scheduleInputValue('due_at')) }}" aria-label="Due" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <button type="submit" class="corex-btn-outline text-xs w-full">Set</button>
                </form>
                @if($jobCard->status === \App\Models\RentalJobCard::STATUS_SCHEDULED)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.start', $jobCard) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Mark in progress</button>
                </form>
                @endif
                @endif
                @endpermission
            </div>

            {{-- §14.28 — share with the crew (per-job link) and the wet-ink signed copy --}}
            @include('corex.rental-job-cards._crew-link-panel')
            @include('corex.rental-job-cards._signed-copy-panel')

            {{-- §17.21.1 plug-in slots — one empty partial per build, so the three builds never edit the same hunk of this view.
                 Build 1 → _pricing-panel (Pricing panel, "Ask crew to price", crew lines awaiting the office).
                 Build 2 → _approval-panel (why approved, variation, emergency approval).
                 Build 3 → _completion-panel (tenant check, dispute, "Send back to crew"). A build may move its include. --}}
            @include('corex.rental-job-cards._pricing-panel')
            @include('corex.rental-job-cards._approval-panel')
            @include('corex.rental-job-cards._completion-panel')

            {{-- 4. Next steps — only when they apply, in order --}}
            {{-- 4. Quote to the owner. §14.21/§14.23 — offered on EVERY open card (Draft, Quoted, Approved, Scheduled, In progress): first send and every re-send (the next revision replaces the old one). It used to need a sent quote or a Draft/Quoted status, so scheduling a Draft card before its quote was sent hid the box for good. Completed/Cancelled cards are locked, so no box (the service refuses too). --}}
            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="jc-quote-box">
                <h2 class="text-sm font-semibold">{{ $currentQuote ? 'Quote to owner' : 'Send quote to owner' }}</h2>
                @if($currentQuote)
                    <p class="text-xs">
                        Last sent: <strong>Rev {{ $currentQuote->revision }}</strong>, R{{ number_format((float) $currentQuote->amount, 2) }}, {{ $currentQuote->created_at?->format('Y-m-d H:i') }}.
                    </p>
                    @if($quoteChanged)
                        <p class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;">
                            This job card has changed since Rev {{ $currentQuote->revision }} was sent. Re-send to update the owner.
                        </p>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);">The owner has the latest version — nothing has changed since it was sent.</p>
                    @endif
                @endif
                @if($quoteRevisions->isNotEmpty())
                    <ul class="space-y-1 text-xs">
                        @foreach($quoteRevisions as $q)
                            <li class="flex flex-wrap items-center gap-x-2" @if($q->superseded_at) style="color: var(--text-muted);" @endif>
                                <span>Rev {{ $q->revision }} — R{{ number_format((float) $q->amount, 2) }} — {{ $q->quote_date?->format('Y-m-d') }}</span>
                                @if($q->superseded_at)
                                    <span class="ds-badge ds-badge-muted">Superseded</span>
                                @else
                                    <span class="ds-badge ds-badge-success">Current</span>
                                @endif
                                @if($q->document_storage_path)
                                    <a href="{{ route('corex.rental-job-cards.quotes.download', [$jobCard, $q->id]) }}" target="_blank" class="underline">View</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @permission('rental_job_cards.send_quote')
                @if(!$jobCard->property?->landlordContact())
                    <p class="text-xs" style="color: var(--ds-crimson);">No landlord linked — link a landlord before sending the quote.
                        @if($jobCard->property && !$jobCard->property->trashed())
                            <a href="{{ route('corex.properties.show', ['property' => $jobCard->property_id, 'tab' => 'contacts']) }}" class="underline">Link landlord</a>
                        @elseif($jobCard->property?->trashed())
                            (property archived)
                        @endif
                    </p>
                @elseif($jobCard->workOrder?->hasApprovedBaseline())
                    {{-- BUILD 2 (§17.7.1) — once the owner has approved an amount a re-send would drop that approval; extra work now goes to the owner as a variation instead, raised automatically. --}}
                    {{-- Reconciliation 7 Oct: this used to say "The owner has approved this job" even when the job was only COVERED by the owner's no-approval limit (nobody asked him). The words follow the basis, as the approval panel's own chip does. --}}
                    <p class="text-xs" style="color: var(--text-muted);">{{ $jobCard->workOrder->approval_basis === \App\Models\RentalWorkOrder::BASIS_OWNER_DECISION ? 'The owner has approved this job.' : ($jobCard->workOrder->approvalBasisLabel() ?: 'This job is approved') . ' — no quote to the owner is needed.' }} Any extra work is put to the owner automatically as a variation — see the approval panel above.</p>
                @else
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.send-quote', $jobCard) }}"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from($currentQuote ? 'Re-send this job card to the owner as Rev ' . ($currentQuote->revision + 1) . '? It replaces Rev ' . $currentQuote->revision . ', and any approval of Rev ' . $currentQuote->revision . ' no longer applies.' : 'Send this job card to the owner as a quote?') }});">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">{{ $currentQuote ? 'Re-send revised quote (Rev ' . ($currentQuote->revision + 1) . ')' : 'Send to owner as quote' }}</button>
                </form>
                @endif
                @endpermission
            </div>
            @endif

            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @permission('rental_job_cards.sign_off')
                @unless($jobCard->worker_signed_off_at)
                {{-- 2026-10-05 — crew have no CoreX login to sign off themselves; the agent
                     records it, naming who on the (already-assigned) crew actually did the
                     work — free text, with that crew's own member names offered via the
                     native datalist below as a convenience, not a constraint. --}}
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.worker-sign-off', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="text" name="worker_sign_off_name" list="worker-sign-off-names" maxlength="191" placeholder="Who did the work (optional)" aria-label="Who did the work" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <datalist id="worker-sign-off-names">
                        @foreach($jobCard->crew?->members ?? [] as $member)
                            <option value="{{ $member->name }}">
                        @endforeach
                    </datalist>
                    <button type="submit" class="corex-btn-outline text-xs w-full">Worker sign-off</button>
                </form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Worker — done: {{ $jobCard->workerSignedOffByUser?->name ?? 'crew' }} ({{ $jobCard->worker_signed_off_at->format('Y-m-d H:i') }})@if($jobCard->worker_sign_off_name) — {{ $jobCard->worker_sign_off_name }}@endif @if($jobCard->worker_sign_off_via && $jobCard->worker_sign_off_via !== 'office')<span class="ds-badge ds-badge-muted">{{ str_replace('_', ' ', $jobCard->worker_sign_off_via) }}</span>@endif</div>
                @endunless

                @unless($jobCard->agent_signed_off_at)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.agent-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs w-full">Agent sign-off</button></form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Agent — checked: {{ $jobCard->agentSignedOffByUser?->name }} ({{ $jobCard->agent_signed_off_at->format('Y-m-d H:i') }})</div>
                @endunless

                @unless($jobCard->tenant_confirmed_at)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tenant-confirm', $jobCard) }}" class="space-y-2">
                    @csrf
                    <textarea name="tenant_confirmation_note" rows="2" placeholder="Tenant confirmation note (optional)" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full">Record tenant confirmation</button>
                </form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Tenant — confirmed fixed: {{ $jobCard->tenantConfirmedByUser?->name }} ({{ $jobCard->tenant_confirmed_at->format('Y-m-d H:i') }})</div>
                @endunless

                @if($jobCard->worker_signed_off_at && $jobCard->agent_signed_off_at)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.complete', $jobCard) }}" onsubmit="return confirm('Mark this job card complete?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs w-full">Complete job card</button>
                </form>
                @endif
                @endpermission

                @permission('rental_job_cards.cancel')
                <button type="button" onclick="document.getElementById('cancel-job-card-form').classList.toggle('hidden')" class="text-xs w-full text-left" style="color: var(--text-muted);">Cancel job card</button>
                <form data-keep-scroll id="cancel-job-card-form" method="POST" action="{{ route('corex.rental-job-cards.cancel', $jobCard) }}" class="hidden space-y-2 pt-2">
                    @csrf
                    <textarea name="cancel_reason" required placeholder="Reason for cancellation" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-crimson);">Confirm cancel</button>
                </form>
                @endpermission
            </div>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
// The agency's active catalogue, printed ONCE — the in-place line editors
// (_add-line-row.blade.php, mode 'edit') read it from here instead of each
// embedding their own copy.
window.jcCatalogueItems = {!! \Illuminate\Support\Js::from($catalogueItemsJson) !!};

function rentalJobCardBuilder({ isDraft, draftTasks }) {
    let seq = 0;
    const key = () => ++seq;

    return {
        isDraft,
        tasks: (draftTasks || []).map(t => ({ key: key(), description: t.description, lines: [] })),
        generalLines: [],
        addTask() {
            this.tasks.push({ key: key(), description: '', lines: [] });
        },
        removeTask(i) {
            this.tasks.splice(i, 1);
        },
        // The "+" button — reads whatever is CURRENTLY in the row's own
        // fields, whether that came from picking a catalogue item (which
        // pre-fills them, via catalogueLinePicker() below) or free typing/
        // editing afterward. 2026-10-05 round 3 (Johan QA1 findings A/B):
        // catalogueItemId now actually read (was hardcoded '' — a picked
        // item was never linked on the draft-mode path at all), and every
        // field it pre-filled stays live/editable, nothing disabled.
        addLineFromRow(task, refs, prefix) {
            const ref = (field) => refs[prefix + field];
            const description = ref('Desc').value.trim();
            if (!description) return;
            const line = {
                key: key(), catalogueItemId: ref('CatalogueItem')?.value || '', description,
                type: ref('Type')?.value || 'labour',
                unit: ref('Unit')?.value || '',
                quantity: ref('Qty')?.value || 1,
                unitPrice: ref('UnitPrice')?.value || '',
                vatTypeId: ref('VatType')?.value || '',
            };
            if (task) { task.lines.push(line); } else { this.generalLines.push(line); }
            ref('Desc').value = '';
            if (ref('CatalogueItem')) ref('CatalogueItem').value = '';
            if (ref('Qty')) ref('Qty').value = 1;
            if (ref('UnitPrice')) ref('UnitPrice').value = '';
        },
    };
}

// 2026-10-05 round 3 (Johan QA1 findings A/B) — the catalogue item picker
// shared by every add-line row, form AND draft mode alike. A searchable
// combobox (filters the agency's own active items by code OR description
// client-side — small lists, no search endpoint needed) that, on pick,
// fills description/type/unit/unit price/VAT type directly via plain DOM
// lookups relative to its OWN row (`$el`) rather than Alpine's $refs — this
// component is scoped locally to EACH row, so it never needs to know that
// row's refPrefix/namespace at all, and never collides with the OUTER
// page-level rentalJobCardBuilder()'s own $refs. Every field it fills
// stays fully editable afterward — nothing here ever disables anything,
// which is the root fix for "selecting a Parts item showed Labour and the
// type couldn't be changed" (the OLD onchange handler only ever disabled
// Type/Unit, never actually set their value, and disabled fields are never
// submitted at all, so the server never even saw a type to disagree with).
//
// 2026-10-05 round 4 (Johan): the dropdown list is teleported to <body> and
// placed `fixed` from the Item input's rectangle (place()) — in place it was
// as narrow as the 100px Item box and clipped by the scrolling left panel.
// `opts` is only used by the in-place line EDITOR: the line's current
// catalogue item (selectedId) and, for a line with a code whose item is no
// longer in the active catalogue, its code as the box's text (fallbackQuery).
function catalogueLinePicker(items, opts) {
    opts = opts || {};
    return {
        items, query: '', open: false, selectedId: '', highlighted: -1, rootEl: null, listEl: null,
        dropStyle: 'display:none;',
        uid: 'jcpick' + Math.random().toString(36).slice(2, 8),
        // $el inside a method called from an x-for child's @click (e.g. pick(it))
        // resolves to the CLICKED child element, not this component's root — Alpine
        // binds magics per evaluation context, not per component. field() needs the
        // row's root to find sibling inputs, so init() captures it once, here, while
        // $el still means "the element x-data is declared on".
        init() {
            this.rootEl = this.$el;
            if (opts.selectedId) {
                this.selectedId = opts.selectedId;
                const current = this.items.find(it => String(it.id) === String(opts.selectedId));
                this.query = current ? current.label : (opts.fallbackQuery || '');
            }
            const reposition = () => { if (this.open) this.place(); };
            window.addEventListener('resize', reposition);
            document.addEventListener('scroll', reposition, true); // capture: the panels scroll, not the page
            // The list lives in <body>, outside this component's DOM — close on a press anywhere else.
            document.addEventListener('mousedown', (e) => {
                if (!this.open) return;
                const input = this.$refs.itemInput;
                if ((input && input.contains(e.target)) || (this.listEl && this.listEl.contains(e.target))) return;
                this.open = false;
            });
        },
        show() {
            this.open = true;
            this.place();
        },
        onType() {
            // The typed text changed the list: highlight the first match so
            // "type a few letters, press Enter" picks it, and a stale index
            // from the previous list can never point past the new one.
            this.highlighted = this.filtered().length ? 0 : -1;
            this.show();
        },
        // Width: at least Item + Description (the two columns the list sits
        // under), never less than 360px, never wider than the window. Opens
        // below the input, or above it when there is little room below.
        place() {
            const input = this.$refs.itemInput;
            if (!input) return;
            const r = input.getBoundingClientRect();
            const panel = document.getElementById('jc-left-col');
            if (panel) {
                const p = panel.getBoundingClientRect();
                if (r.bottom < p.top || r.top > p.bottom) { this.open = false; return; } // scrolled out of the panel
            }
            const grid = this.rootEl.querySelector('[style*="grid-template-columns"]');
            const descCell = grid && grid.children[1];
            const right = descCell ? descCell.getBoundingClientRect().right : r.right;
            const width = Math.min(Math.max(right - r.left, 360), window.innerWidth - 16);
            const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
            const below = window.innerHeight - r.bottom - 8;
            const above = r.top - 8;
            const flip = below < 150 && above > below;
            const maxH = Math.max(100, Math.min(260, flip ? above : below));
            // The list's whole look lives here — Alpine's string :style replaces a static style attribute.
            this.dropStyle = 'position:fixed; z-index:70; overflow-y:auto; background:var(--surface); color:var(--text, inherit); border:1px solid var(--border); border-radius:6px; box-shadow:0 6px 18px rgba(0,0,0,0.18); '
                + 'left:' + Math.round(left) + 'px; width:' + Math.round(width) + 'px; max-height:' + Math.round(maxH) + 'px; '
                + (flip ? 'bottom:' + Math.round(window.innerHeight - r.top + 4) + 'px;' : 'top:' + Math.round(r.bottom + 4) + 'px;');
        },
        filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.items;
            return this.items.filter(it => it.code.toLowerCase().includes(q) || it.description.toLowerCase().includes(q));
        },
        moveSelection(dir) {
            const list = this.filtered();
            if (!list.length) return;
            const wasOpen = this.open;
            this.show();
            // A closed list opens on the first press instead of skipping a row.
            this.highlighted = wasOpen || this.highlighted >= 0
                ? (this.highlighted + dir + list.length) % list.length
                : (dir > 0 ? 0 : list.length - 1);
            this.$nextTick(() => {
                const active = this.listEl && this.listEl.querySelector('[data-active="1"]');
                if (active) active.scrollIntoView({ block: 'nearest' });
            });
        },
        pickHighlighted() {
            if (!this.open) { this.show(); return; }
            const list = this.filtered();
            if (this.highlighted >= 0 && list[this.highlighted]) this.pick(list[this.highlighted]);
        },
        field(selector) {
            return this.rootEl.querySelector(selector);
        },
        pick(item) {
            this.selectedId = item.id;
            this.query = item.label;
            this.open = false;
            this.highlighted = -1;
            const d = this.field('[name="description"], [x-ref$="Desc"]'); if (d) d.value = item.description;
            const t = this.field('[name="type"], [x-ref$="Type"]'); if (t) t.value = item.kind || 'labour';
            const u = this.field('[name="unit"], [x-ref$="Unit"]'); if (u && item.unit) u.value = item.unit;
            // §17.4.3 — the selling price is NOT copied from the catalogue any more: left blank it resolves by the pricing rules
            // (the catalogue's default price is rule 5), so a picked item still prices itself but is not frozen as "typed by hand".
            const p = this.field('[name="unit_price"], [x-ref$="UnitPrice"]'); if (p) { p.value = ''; p.placeholder = (item.priceForLine !== null && item.priceForLine !== undefined) ? ('catalogue ' + item.priceForLine) : 'auto'; }
            const k = this.field('[name="unit_cost"], [x-ref$="UnitCost"]'); if (k && item.costForLine !== null && item.costForLine !== undefined) k.value = item.costForLine;
            const v = this.field('[name="rental_vat_type_id"], [x-ref$="VatType"]');
            if (v && item.vatTypeId) { v.value = item.vatTypeId; v.dispatchEvent(new Event('change')); }
            const c = this.field('[name="custom_vat_rate"]'); if (c && item.customVatRate !== null && item.customVatRate !== undefined) c.value = item.customVatRate;
        },
        clear() {
            this.selectedId = '';
            this.query = '';
            this.open = false;
            this.highlighted = -1;
        },
    };
}

// 2026-10-05 round 2 (Johan, real-browser check) — left/right columns
// scroll independently instead of the whole page, same technique as
// resources/views/corex/rentals/command-centre/index.blade.php's own
// #rcc-layout sizing JS (that file's comment has the full reasoning: a
// flat `innerHeight - top` estimate alone still leaves an outer scrollbar
// because it can't see every padding layer between #jc-layout and the
// viewport's bottom edge that it doesn't own; instead measure the ACTUAL
// resulting overflow on #appScroll, the real scrolling element — html/body
// never scroll, by the shared layout's own h-screen+overflow-hidden
// wrapper — and subtract exactly that much).
//
// 2026-10-05 round 4 (Johan, "outer scrollbar on top of the two inner ones")
// — root cause: that estimate was a ONE-SHOT with a 240px floor, and #appScroll
// stayed scrollable. Any slop — 1px of sub-pixel rounding (the old code was
// exactly 1px over at 1366x600), a window shorter than header+240px (the
// floor won over the real space), a late layout shift above the panels —
// left #appScroll with overflow, so the browser drew a page scrollbar
// beside the two panel ones. Fixed at the cause: at lg+ #appScroll is set
// to overflow-y:hidden (the page itself can never scroll here; the two
// panels are the only scrollers), the floor is 120px, the height is floored
// to whole pixels, and the sizing re-runs whenever anything above the
// panels changes size (ResizeObserver) and once fonts have loaded.
//
// Below the lg breakpoint (<1024px) this never applies a height and leaves
// #appScroll alone — the columns fall back to plain stacked page scroll
// (lg:overflow-y-auto on each column is likewise a no-op below lg), per
// Johan's own instruction.
(function () {
    var LG_BREAKPOINT = 1024;
    var MIN_PANEL = 120;

    function applyHeight(px) {
        var left = document.getElementById('jc-left-col');
        var right = document.getElementById('jc-right-col');
        if (left) { left.style.height = px === null ? '' : px + 'px'; }
        if (right) { right.style.height = px === null ? '' : px + 'px'; }
    }

    function sizeColumns() {
        var layout = document.getElementById('jc-layout');
        if (!layout) { return; }
        var appScroll = document.getElementById('appScroll');

        if (window.innerWidth < LG_BREAKPOINT) {
            applyHeight(null);
            if (appScroll) { appScroll.style.overflowY = ''; }
            return;
        }

        if (appScroll) {
            appScroll.style.overflowY = 'hidden';
            appScroll.scrollTop = 0;
        }

        var top = layout.getBoundingClientRect().top;
        var height = Math.max(MIN_PANEL, Math.floor(window.innerHeight - top));
        applyHeight(height);

        if (appScroll) {
            var overflow = appScroll.scrollHeight - appScroll.clientHeight;
            if (overflow > 0) {
                applyHeight(Math.max(MIN_PANEL, height - overflow));
            }
        }
    }

    window.addEventListener('resize', sizeColumns);
    document.addEventListener('DOMContentLoaded', sizeColumns);
    window.addEventListener('load', sizeColumns);
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(sizeColumns); }
    sizeColumns();

    // Anything above the panels (title row, context bar, a wrapped button) changing height moves #jc-layout's top.
    var layoutEl = document.getElementById('jc-layout');
    if (layoutEl && window.ResizeObserver) {
        var ro = new ResizeObserver(sizeColumns);
        Array.prototype.forEach.call(layoutEl.parentElement.children, function (c) { if (c !== layoutEl) { ro.observe(c); } });
        var appScrollEl = document.getElementById('appScroll');
        if (appScrollEl) { ro.observe(appScrollEl); }
    }
})();

// 2026-10-05 round 4 (Johan, "adding a line jumps back to the top") — root
// cause: adding/editing/archiving a line is a plain form POST + redirect, so
// the browser loads a fresh document. The two panels (#jc-left-col /
// #jc-right-col) are inner scrollers whose scrollTop is just a property of the
// old DOM — browser scroll restoration only handles the window — so the new
// page always starts both at 0. Fix: any form marked `data-keep-scroll`
// (add / edit / archive / restore line) records both panels' scrollTop in
// sessionStorage as it submits; the next load puts it back, then brings the
// changed line (jc_focus_line, flashed by the controller) into view with the
// MINIMUM scroll needed and briefly highlights it. Scrolling is done by hand
// on the panel — Element.scrollIntoView() would also scroll #appScroll
// (overflow:hidden is still programmatically scrollable) and shift the page.
(function () {
    var KEY = 'jcScroll:' + location.pathname;
    var focusLineId = {!! \Illuminate\Support\Js::from(session('jc_focus_line')) !!};
    var focusTaskId = {!! \Illuminate\Support\Js::from(session('jc_focus_task')) !!}; // §14.21 — task add / rename / restore / tick

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !form.hasAttribute || !form.hasAttribute('data-keep-scroll')) { return; } // a cancelled confirm()
        var left = document.getElementById('jc-left-col');
        var right = document.getElementById('jc-right-col');
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ l: left ? left.scrollTop : 0, r: right ? right.scrollTop : 0, t: Date.now() }));
        } catch (err) { /* private window / storage blocked — falls back to the old behaviour, nothing breaks */ }
    });

    var saved = null;
    try {
        var raw = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
        saved = raw ? JSON.parse(raw) : null;
        if (saved && Date.now() - saved.t > 120000) { saved = null; } // stale — not from the submit that just happened
    } catch (err) { saved = null; }

    function ensureVisible(panel, el) {
        var p = panel.getBoundingClientRect();
        var r = el.getBoundingClientRect();
        if (r.top < p.top) { panel.scrollTop -= (p.top - r.top) + 8; }
        else if (r.bottom > p.bottom) { panel.scrollTop += (r.bottom - p.bottom) + 8; }
    }

    function restore(highlight) {
        var left = document.getElementById('jc-left-col');
        var right = document.getElementById('jc-right-col');
        if (saved) {
            if (left) { left.scrollTop = saved.l; }
            if (right) { right.scrollTop = saved.r; }
        }
        if (!left) { return; }
        // A failed validation reopens that line's editor — bring it into view.
        var open = document.querySelector('[id^="jc-edit-line-"]');
        var target = open
            || (focusLineId ? document.querySelector('[data-line-id="' + focusLineId + '"]') : null)
            || (focusTaskId ? document.querySelector('[data-task-id="' + focusTaskId + '"]') : null);
        if (target) {
            ensureVisible(left, target);
            if (highlight && !open) {
                target.style.transition = 'background-color .4s';
                target.style.backgroundColor = 'rgba(14,165,233,.16)';
                setTimeout(function () { target.style.backgroundColor = ''; }, 2200);
            }
        }
    }

    // Sync (heights already applied by the sizing block above), then again once
    // Alpine has rendered (an editor reopened by a failed validation only exists
    // after it) and once everything has loaded — idempotent, same target each time.
    restore(false);
    document.addEventListener('DOMContentLoaded', function () { restore(false); });
    window.addEventListener('load', function () { restore(true); });
})();
</script>
@endpush
