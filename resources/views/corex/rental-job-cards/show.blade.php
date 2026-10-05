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
        'cancelled' => 'ds-badge-danger',
        'in_progress', 'scheduled' => 'ds-badge-info',
        default => 'ds-badge-muted',
    } : null;
    $isOpen = $jobCard && !in_array($jobCard->status, ['completed', 'cancelled'], true);

    $catalogueItemsJson = $catalogueItems->map(fn ($ci) => [
        'id' => $ci->id, 'name' => $ci->name, 'kind' => $ci->kind(),
        'unit' => $ci->catalogueUnit?->name, 'price' => $ci->default_price !== null ? (float) $ci->default_price : null,
        'vatTypeId' => $ci->default_rental_vat_type_id,
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
                <span class="text-xs" style="color: var(--text-muted);">{{ $jobCard->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $jobCard->property?->trashed() ? ' (archived)' : '' }}</span>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($jobCard)
                <a href="{{ route('corex.rental-job-cards.print', $jobCard) }}" target="_blank" class="corex-btn-outline text-xs">Print job card</a>
                @if($jobCard->rental_work_order_id)
                    <a href="{{ route('corex.rental-work-orders.show', $jobCard->rental_work_order_id) }}" class="corex-btn-outline text-xs">View work order</a>
                @endif
            @endif
            <a href="{{ route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">&larr; All job cards</a>
        </div>
    </div>

    <div x-data="rentalJobCardBuilder({
            isDraft: {{ $isDraft ? 'true' : 'false' }},
            catalogueItems: {{ $catalogueItemsJson->toJson() }},
            draftTasks: {{ $draftTasksJson->toJson() }},
        })" class="grid grid-cols-3 gap-4">
        <div class="col-span-2 space-y-4">

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
                        <form x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-job-cards.update', $jobCard) }}" class="col-span-2 space-y-3">
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

                            <div class="grid grid-cols-5 gap-2 items-end pt-1">
                                <div>
                                    <label class="text-xs">Catalogue item</label>
                                    <select x-ref="catItem" @change="addDraftLine(task, $event.target)" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                                        <option value="">— Pick to add —</option>
                                        <template x-for="ci in catalogueItems" :key="ci.id">
                                            <option :value="ci.id" x-text="ci.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <label class="text-xs">Or free text</label>
                                    <input type="text" x-ref="freeDesc" placeholder="Description" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                                </div>
                                <div>
                                    <label class="text-xs">Qty</label>
                                    <input type="number" x-ref="freeQty" step="0.01" min="0.01" value="1" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                                </div>
                                <div>
                                    <button type="button" @click="addFreeTextLine(task, $refs)" class="corex-btn-outline text-xs">+ line</button>
                                </div>
                            </div>
                            {{-- The hidden, bracket-indexed inputs Laravel actually parses on submit. --}}
                            <template x-for="(line, li) in task.lines" :key="'f-' + line.key">
                                <div>
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][rental_catalogue_item_id]`" :value="line.catalogueItemId" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][description]`" :value="line.description" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][unit]`" :value="line.unit" form="job-card-create-form">
                                    <input type="hidden" :name="`tasks[${ti}][lines][${li}][quantity]`" :value="line.quantity" form="job-card-create-form">
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
                        <div class="grid grid-cols-5 gap-2 items-end">
                            <div class="col-span-2">
                                <label class="text-xs">Free text (e.g. call-out fee)</label>
                                <input type="text" x-ref="genDesc" placeholder="Description" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs">Qty</label>
                                <input type="number" x-ref="genQty" step="0.01" min="0.01" value="1" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <button type="button" @click="addFreeTextLine(null, $refs, 'gen')" class="corex-btn-outline text-xs">+ line</button>
                            </div>
                        </div>
                        <template x-for="(line, li) in generalLines" :key="'f-' + line.key">
                            <div>
                                <input type="hidden" :name="`general_lines[${li}][rental_catalogue_item_id]`" :value="line.catalogueItemId" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][description]`" :value="line.description" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][unit]`" :value="line.unit" form="job-card-create-form">
                                <input type="hidden" :name="`general_lines[${li}][quantity]`" :value="line.quantity" form="job-card-create-form">
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
                        <div class="rounded-md p-3 space-y-2" style="border: 1px solid var(--border);" x-data="{ renaming: false }">
                            <div class="flex items-center justify-between gap-2">
                                <form method="POST" action="{{ route('corex.rental-job-cards.tasks.toggle', [$jobCard, $task]) }}" class="flex items-center gap-2 flex-1">
                                    @csrf
                                    <button type="submit" class="flex items-center gap-2 text-left">
                                        <input type="checkbox" @checked($task->is_done) onclick="return false;" class="rounded">
                                    </button>
                                    <span class="text-sm font-medium" x-show="!renaming">{{ $loop->iteration }} - <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span></span>
                                </form>
                                @permission('rental_job_cards.create')
                                <div class="flex items-center gap-2">
                                    <button type="button" x-show="!renaming" @click="renaming = true" class="text-xs">Rename</button>
                                    <form method="POST" action="{{ route('corex.rental-job-cards.tasks.destroy', [$jobCard, $task]) }}" onsubmit="return confirm('Archive this task and its lines?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                                    </form>
                                </div>
                                @endpermission
                            </div>
                            @permission('rental_job_cards.create')
                            <form x-show="renaming" x-cloak method="POST" action="{{ route('corex.rental-job-cards.tasks.update', [$jobCard, $task]) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="text" name="description" value="{{ $task->description }}" maxlength="500" required class="flex-1 rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                <button type="submit" class="corex-btn-outline text-xs">Save</button>
                                <button type="button" @click="renaming = false" class="corex-btn-outline text-xs">Cancel</button>
                            </form>
                            @endpermission

                            @include('corex.rental-job-cards._lines-table', ['lines' => $task->lines, 'pricesOn' => $pricesOn, 'vat' => $vat, 'jobCard' => $jobCard])

                            @if($pricesOn)
                                <div class="text-xs text-right" style="color: var(--text-muted);">Task subtotal: R{{ number_format($task->subtotal(), 2) }}</div>
                            @endif

                            @permission('rental_job_cards.create')
                            @include('corex.rental-job-cards._add-line-form', ['action' => route('corex.rental-job-cards.lines.store', $jobCard), 'taskId' => $task->id, 'catalogueItems' => $catalogueItems, 'catalogueUnits' => $catalogueUnits, 'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered']])
                            @endpermission
                        </div>
                    @empty
                        <p class="text-xs" style="color: var(--text-muted);">No tasks yet.</p>
                    @endforelse

                    @permission('rental_job_cards.create')
                    <form method="POST" action="{{ route('corex.rental-job-cards.tasks.store', $jobCard) }}" class="flex items-end gap-2">
                        @csrf
                        <input type="text" name="description" required maxlength="500" placeholder="Add a task" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <button type="submit" class="corex-btn-outline text-xs">Add task</button>
                    </form>
                    @if($archivedTasks->isNotEmpty())
                        <button type="button" onclick="document.getElementById('archived-tasks').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedTasks->count() }} archived task(s)</button>
                        <ul id="archived-tasks" class="hidden space-y-1 text-sm pt-1">
                            @foreach($archivedTasks as $at)
                                <li class="flex items-center justify-between gap-2">
                                    <span style="color: var(--text-muted);">{{ $at->description }}</span>
                                    <form method="POST" action="{{ route('corex.rental-job-cards.tasks.restore', [$jobCard, $at->id]) }}">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @endpermission

                    {{-- General — lines with no task (e.g. a call-out fee). --}}
                    <div class="rounded-md p-3 space-y-2" style="border: 1px dashed var(--border);">
                        <span class="text-sm font-medium">General</span>
                        @include('corex.rental-job-cards._lines-table', ['lines' => $generalLines, 'pricesOn' => $pricesOn, 'vat' => $vat, 'jobCard' => $jobCard])
                        @permission('rental_job_cards.create')
                        @include('corex.rental-job-cards._add-line-form', ['action' => route('corex.rental-job-cards.lines.store', $jobCard), 'taskId' => null, 'catalogueItems' => $catalogueItems, 'catalogueUnits' => $catalogueUnits, 'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered']])
                        @endpermission
                        @if($archivedLines->isNotEmpty())
                            <button type="button" onclick="document.getElementById('archived-lines').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedLines->count() }} archived line(s)</button>
                            <ul id="archived-lines" class="hidden space-y-1 text-sm pt-1">
                                @foreach($archivedLines as $al)
                                    <li class="flex items-center justify-between gap-2">
                                        <span style="color: var(--text-muted);">{{ $al->description }}</span>
                                        @permission('rental_job_cards.create')
                                        <form method="POST" action="{{ route('corex.rental-job-cards.lines.restore', [$jobCard, $al->id]) }}">
                                            @csrf
                                            <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                        </form>
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
                    <form method="POST" action="{{ route('corex.rental-job-cards.photos.store', $jobCard) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
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
        <div class="space-y-4">
            {{-- 3. WHO & WHEN, one line --}}
            <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm flex flex-wrap gap-3">
                    <span><span style="color: var(--text-muted);">Crew:</span> {{ $jobCard->assignedUser?->name ?? '—' }}</span>
                    <span><span style="color: var(--text-muted);">Scheduled:</span> {{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    <span><span style="color: var(--text-muted);">Due:</span> {{ $jobCard->due_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
                @permission('rental_job_cards.create')
                @if($isOpen)
                <form method="POST" action="{{ route('corex.rental-job-cards.assign-crew', $jobCard) }}" class="flex gap-2">
                    @csrf
                    <select name="assigned_user_id" required class="flex-1 rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                        <option value="">Select crew member…</option>
                        @foreach($crew as $c)
                            <option value="{{ $c->id }}" @selected($jobCard->assigned_user_id === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="corex-btn-outline text-xs">Assign</button>
                </form>
                <form method="POST" action="{{ route('corex.rental-job-cards.schedule', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="datetime-local" name="scheduled_at" aria-label="Scheduled" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <input type="datetime-local" name="due_at" aria-label="Due" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <button type="submit" class="corex-btn-outline text-xs w-full">Set</button>
                </form>
                @if($jobCard->status === \App\Models\RentalJobCard::STATUS_SCHEDULED)
                <form method="POST" action="{{ route('corex.rental-job-cards.start', $jobCard) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Mark in progress</button>
                </form>
                @endif
                @endif
                @endpermission
            </div>

            {{-- 4. Next steps — only when they apply, in order --}}
            @if($isOpen && in_array($jobCard->status, [\App\Models\RentalJobCard::STATUS_DRAFT, \App\Models\RentalJobCard::STATUS_QUOTED], true))
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold">Send quote to owner</h2>
                @if($jobCard->quotes->isNotEmpty())
                    <ul class="space-y-1 text-xs">
                        @foreach($jobCard->quotes as $q)
                            <li>R{{ number_format((float) $q->amount, 2) }} — {{ $q->quote_date?->format('Y-m-d') }} @if($q->is_selected)<span class="ds-badge ds-badge-success">Selected</span>@endif</li>
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
                @else
                <form method="POST" action="{{ route('corex.rental-job-cards.send-quote', $jobCard) }}" onsubmit="return confirm('Send this job card to the owner as a quote?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">Send to owner as quote</button>
                </form>
                @endif
                @endpermission
            </div>
            @endif

            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @permission('rental_job_cards.sign_off')
                @unless($jobCard->worker_signed_off_at)
                <form method="POST" action="{{ route('corex.rental-job-cards.worker-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs w-full">Worker sign-off</button></form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Worker — done: {{ $jobCard->workerSignedOffByUser?->name }} ({{ $jobCard->worker_signed_off_at->format('Y-m-d H:i') }})</div>
                @endunless

                @unless($jobCard->agent_signed_off_at)
                <form method="POST" action="{{ route('corex.rental-job-cards.agent-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs w-full">Agent sign-off</button></form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Agent — checked: {{ $jobCard->agentSignedOffByUser?->name }} ({{ $jobCard->agent_signed_off_at->format('Y-m-d H:i') }})</div>
                @endunless

                @unless($jobCard->tenant_confirmed_at)
                <form method="POST" action="{{ route('corex.rental-job-cards.tenant-confirm', $jobCard) }}" class="space-y-2">
                    @csrf
                    <textarea name="tenant_confirmation_note" rows="2" placeholder="Tenant confirmation note (optional)" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full">Record tenant confirmation</button>
                </form>
                @else
                <div class="text-xs" style="color: var(--text-muted);">Tenant — confirmed fixed: {{ $jobCard->tenantConfirmedByUser?->name }} ({{ $jobCard->tenant_confirmed_at->format('Y-m-d H:i') }})</div>
                @endunless

                @if($jobCard->worker_signed_off_at && $jobCard->agent_signed_off_at)
                <form method="POST" action="{{ route('corex.rental-job-cards.complete', $jobCard) }}" onsubmit="return confirm('Mark this job card complete?');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs w-full">Complete job card</button>
                </form>
                @endif
                @endpermission

                @permission('rental_job_cards.cancel')
                <button type="button" onclick="document.getElementById('cancel-job-card-form').classList.toggle('hidden')" class="text-xs w-full text-left" style="color: var(--text-muted);">Cancel job card</button>
                <form id="cancel-job-card-form" method="POST" action="{{ route('corex.rental-job-cards.cancel', $jobCard) }}" class="hidden space-y-2 pt-2">
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
function rentalJobCardBuilder({ isDraft, catalogueItems, draftTasks }) {
    let seq = 0;
    const key = () => ++seq;

    return {
        isDraft,
        catalogueItems,
        tasks: (draftTasks || []).map(t => ({ key: key(), description: t.description, lines: [] })),
        generalLines: [],
        addTask() {
            this.tasks.push({ key: key(), description: '', lines: [] });
        },
        removeTask(i) {
            this.tasks.splice(i, 1);
        },
        addDraftLine(task, selectEl) {
            const id = selectEl.value;
            if (!id) return;
            const item = this.catalogueItems.find(c => String(c.id) === String(id));
            if (!item) return;
            task.lines.push({ key: key(), catalogueItemId: item.id, description: item.name, unit: item.unit, quantity: 1 });
            selectEl.value = '';
        },
        addFreeTextLine(task, refs, prefix = '') {
            const descRef = prefix === 'gen' ? refs.genDesc : refs.freeDesc;
            const qtyRef = prefix === 'gen' ? refs.genQty : refs.freeQty;
            const description = descRef.value.trim();
            if (!description) return;
            const line = { key: key(), catalogueItemId: '', description, unit: '', quantity: qtyRef.value || 1 };
            if (task) { task.lines.push(line); } else { this.generalLines.push(line); }
            descRef.value = '';
            qtyRef.value = 1;
        },
    };
}
</script>
@endpush
