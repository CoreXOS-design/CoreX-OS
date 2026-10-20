{{--
    AT-460 - the work itself: ONE column header (Description · Qty · Cost · Selling · Total · actions - item code, type, unit, VAT and margin sit in a small line under each description; 19 Oct 2026), then a band per task with its lines under it,
    the crew's not-yet-accepted lines inline with Accept / Reject on the row, and the add-line row as a tinted input row on the SAME grid at the foot of each task. The General
    bucket (lines with no task) is the last block. Totals follow in _totals. Every form below posts to the route it always did.
    Receives what show.blade.php has in scope.
--}}
@php
    $tlShowCost = $canViewCosts ?? false;
    $tlAwaitingByTask = $pricesOn ? $awaitingLines->groupBy(fn ($l) => $l->rental_job_card_task_id ?? 0) : collect();
    $tlLineArgs = fn ($lines) => ['lines' => $lines, 'pricesOn' => $pricesOn, 'vat' => $vat, 'jobCard' => $jobCard, 'canEditLines' => $isOpen, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'vatTypes' => $vatTypes, 'showCost' => $tlShowCost, 'canPrice' => $canPrice ?? false, 'costVat' => $costVat ?? null];
    $tlAddArgs = fn ($taskId) => ['mode' => 'form', 'action' => route('corex.rental-job-cards.lines.store', $jobCard), 'taskId' => $taskId, 'catalogueItemsForJs' => $catalogueItemsJson, 'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits, 'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered'], 'showCost' => $tlShowCost, 'canPrice' => $canPrice ?? false];
@endphp
<div class="jc-card" id="jc-lines">
    <div class="jc-card-head">
        <h2>Tasks &amp; lines</h2>
        @if($awaitingLines->isNotEmpty() && $pricesOn && $canPrice && $isOpen)
            <div class="flex items-center gap-2 text-xs">
                <span class="ds-badge" style="background: color-mix(in srgb, #f59e0b 18%, transparent); color: #b45309;" data-chip-pricing>Priced by crew — awaiting you ({{ $awaitingLines->count() }})</span>
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.accept-all', $jobCard) }}" data-confirm="Accept every line the crew sent and price them by the rules?">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Accept all</button>
                </form>
            </div>
        @endif
    </div>

    <div class="jc-linebox"><div class="jc-lines-inner">
        @include('corex.rental-job-cards._line-columns-header', ['pricesOn' => $pricesOn, 'vatRegistered' => $vat['registered'], 'showCost' => $tlShowCost])

        @forelse($jobCard->tasks as $task)
            <div class="jc-task" x-data="{ renaming: false }" data-task-id="{{ $task->id }}">
                <div class="jc-task-head">
                    @if($isOpen)
                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.toggle', [$jobCard, $task]) }}" class="flex items-center gap-2 flex-1 min-w-0">
                        @csrf
                        <input type="hidden" name="done" value="{{ $task->is_done ? 0 : 1 }}">{{-- §17.43: set the state, do not flip it --}}
                        <button type="submit" class="flex items-center gap-2 text-left">
                            {{-- the box submits the form itself (requestSubmit keeps the data-keep-scroll handler); the state shown always comes from the server after the reload --}}
                            <input type="checkbox" @checked($task->is_done) onclick="event.preventDefault(); this.form.requestSubmit();" class="rounded">
                        </button>
                        <span class="text-sm font-medium" x-show="!renaming">{{ $loop->iteration }} - <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span></span>
                    </form>
                    @else
                    {{-- §14.21 - a closed card is a read-only record: no tick, no rename, no archive. --}}
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <input type="checkbox" @checked($task->is_done) disabled class="rounded">
                        <span class="text-sm font-medium">{{ $loop->iteration }} - <span style="{{ $task->is_done ? 'text-decoration: line-through; color: var(--text-muted);' : '' }}">{{ $task->description }}</span></span>
                    </div>
                    @endif
                    <div class="flex items-center gap-3 text-xs">
                        @if($pricesOn)<span style="color: var(--text-muted);">Task subtotal: R{{ number_format($task->subtotal(), 2) }}</span>@endif
                        @permission('rental_job_cards.create')
                        @if($isOpen)
                            <button type="button" x-show="!renaming" @click="renaming = true" class="text-xs">Rename</button>
                            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.destroy', [$jobCard, $task]) }}" data-confirm="Archive this task and its lines?" data-confirm-danger data-confirm-label="Archive">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        @endif
                        @endpermission
                    </div>
                </div>
                <div class="jc-task-body">
                    @permission('rental_job_cards.create')
                    @if($isOpen)
                    <form data-keep-scroll x-show="renaming" x-cloak method="POST" action="{{ route('corex.rental-job-cards.tasks.update', [$jobCard, $task]) }}" class="flex items-center gap-2 py-1">
                        @csrf
                        @method('PUT')
                        <input type="text" name="description" value="{{ $task->description }}" maxlength="500" required class="flex-1 rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                        <button type="submit" class="corex-btn-outline text-xs">Save</button>
                        <button type="button" @click="renaming = false" class="corex-btn-outline text-xs">Cancel</button>
                    </form>
                    @endif
                    @endpermission

                    @include('corex.rental-job-cards._lines-table', $tlLineArgs($task->acceptedLines))
                    @if(($tlAwaitingByTask[$task->id] ?? collect())->isNotEmpty())
                        @include('corex.rental-job-cards._awaiting-rows', ['rows' => $tlAwaitingByTask[$task->id]])
                    @endif

                    @permission('rental_job_cards.create')
                    @if($isOpen)
                    <div class="jc-addrow" data-add-line-row>
                        <div class="jc-addrow-cap">Add a line</div>
                        @include('corex.rental-job-cards._add-line-row', $tlAddArgs($task->id))
                    </div>
                    @endif
                    @endpermission
                </div>
            </div>
        @empty
            <p class="text-xs pt-2" style="color: var(--text-muted);">No tasks yet.</p>
        @endforelse

        @permission('rental_job_cards.create')
        @if($isOpen)
        <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tasks.store', $jobCard) }}" class="flex items-end gap-2 pt-3">
            @csrf
            <input type="text" name="description" required maxlength="500" placeholder="Add a task" class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <button type="submit" class="corex-btn-outline text-xs">Add task</button>
        </form>
        @endif
        @if($archivedTasks->isNotEmpty())
            <div class="pt-2">
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
            </div>
        @endif
        @endpermission

        {{-- General - lines with no task (e.g. a call-out fee). --}}
        <div class="jc-task jc-general" data-general-lines>
            <div class="jc-task-head"><span class="text-sm font-medium">General</span></div>
            <div class="jc-task-body">
                @include('corex.rental-job-cards._lines-table', $tlLineArgs($generalLines))
                @if(($tlAwaitingByTask[0] ?? collect())->isNotEmpty())
                    @include('corex.rental-job-cards._awaiting-rows', ['rows' => $tlAwaitingByTask[0]])
                @endif
                @permission('rental_job_cards.create')
                @if($isOpen)
                <div class="jc-addrow" data-add-line-row>
                    <div class="jc-addrow-cap">Add a line</div>
                    @include('corex.rental-job-cards._add-line-row', $tlAddArgs(null))
                </div>
                @endif
                @endpermission
                @if($archivedLines->isNotEmpty())
                    <div class="pt-2">
                        <button type="button" onclick="document.getElementById('archived-lines').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedLines->count() }} archived line(s)</button>
                        <ul id="archived-lines" class="hidden space-y-1 text-sm pt-1">
                            @foreach($archivedLines as $al)
                                <li class="flex items-center justify-between gap-2">
                                    <span style="color: var(--text-muted);">{{ $al->description }}</span>
                                    @permission('rental_job_cards.create')
                                    @if($isOpen)
                                    <form method="POST" action="{{ route('corex.rental-job-cards.lines.restore', [$jobCard, $al->id]) }}" data-keep-scroll data-line-ajax="restore">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                    @endif
                                    @endpermission
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div></div>

    @include('corex.rental-job-cards._totals')
</div>
