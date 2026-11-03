{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold')

@section('corex-content')
@php
    $rowsPage = $rows;
    $swapReasons = app(\App\Services\RoadToSold\ParkReasonService::class)->choices((int) (auth()->user()->effectiveAgencyId() ?: 0), $track ?? 'sales', 'found')->filter(fn ($r) => $r->station === 'found');
    $addresses = [];
    foreach ($rowsPage as $r) { $addresses[$r['tracked_property_id']] = $r['address']; }
    $pageConfig = [
        'choices'  => $outcomeChoices,
        'reopen'   => session('rts_reopen'),
        'oldOutcome' => old('outcome_definition_id'),
        'oldNote'  => old('note'),
        'oldDate'  => old('next_action_at'),
        'oldValuation' => (bool) old('valuation_booked'),
        'oldAppointment' => old('appointment_at'),
        'oldChannel' => old('channel'),
        'lastChannel' => $lastChannel ?? '',
        'channels' => \App\Models\RoadToSold\RtsContactOutcome::CHANNELS,
        'fit' => (object) ($outcomeFit ?? []),
        'addresses' => $addresses,
        'history'   => $history,
        'captureTasks' => $captureTasks->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'kind' => $t->capture_kind, 'evidence' => $t->evidence_required])->values()->all(),
        'captureReopen' => old('task_id'),
        'captureOld' => [
            'area' => old('area_label'), 'when' => old('occurred_at'), 'qty' => old('quantity'), 'note' => old('note'),
            'properties' => array_values((array) old('properties', [])), 'contacts' => array_values((array) old('contacts', [])),
        ],
    ];
    $money = fn ($v) => $v ? 'R ' . number_format((int) $v, 0, '.', ',') : '';
    $viewLabels = ['today' => 'Today\'s list', 'no_answer' => 'No answer', 'wrong_number' => 'Wrong number', 'no_owner' => 'No owner', 'captures' => 'Captures'];
    if ($agents->count() > 1) { $viewLabels['team'] = 'Team'; }
    $statusLabels = ['open' => 'Open', 'worked' => 'Worked', 'missed' => 'Missed', 'all' => 'All'];
    $sortLabels = $isCaptures
        ? \App\Services\RoadToSold\CompletionListService::SORTS
        : ['order' => 'List order', 'address' => 'Address', 'price' => 'Price', 'dom' => 'Days on market', 'last_outcome' => 'Last outcome'];
    $cf = $captureFilters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $listView = in_array($filters['view'], ['today', 'no_owner'], true);
@endphp

<div class="w-full rts-page rts-fit" x-data="rtsPage(@js($pageConfig))">
    @include('road-to-sold._controls')

    <div class="rounded-md px-6 py-3 corex-page-banner rts-sticky" data-tour="smk-rts-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold</h1>
                @if($progress['level'])<span class="ds-badge ds-badge-info">{{ $progress['level']['name'] }}</span>@endif
                @if(($levelStatus['eligible'] ?? false) && $levelStatus['level'] && $levelStatus['required'] !== [])
                    <span class="text-xs" style="color: var(--text-primary);" data-level-status>
                        @if($levelStatus['status'] === 'awaiting_signoff')
                            Waiting for your manager's sign-off
                        @elseif($levelStatus['status'] === 'signed_off')
                            Top level reached
                        @else
                            <strong>{{ min($levelStatus['streak'], $levelStatus['needed']) }} of {{ $levelStatus['needed'] }}</strong> weeks on target
                        @endif
                    </span>
                @endif
                @php
                    // 23.24: the day in one line - the same three figures the tiles show (moved along = line 2 of every tile but Loaded; late = line 3)
                    $beltTiles = collect($belt ?? []);
                    $movedAlong = (int) ($movedAlong ?? 0);   // distinct records that moved on today (ConveyorService::movedAlong), not the sum of the tiles' lines
                    $lateAll = (int) $beltTiles->sum('late');
                    $loadedT = $beltTiles->firstWhere('key', 'loaded');
                @endphp
                @if(! $isCaptures && ($counts['any'] > 0 || $beltTiles->isNotEmpty()))
                    <span class="text-xs" style="color: var(--text-primary);" data-qa="today-line">{{ $date->isToday() ? 'Today' : $date->format('j M') }}: <strong>{{ $counts['any'] > 0 ? $counts['worked'] : ($loadedT['moved'] ?? 0) }} of {{ $counts['any'] > 0 ? $counts['target_items'] : ($loadedT['target'] ?? 0) }}</strong> worked @if($counts['added'] > 0)<span style="color: var(--text-muted);"> ({{ $counts['added_worked'] }} of {{ $counts['added'] }} added on top)</span>@endif · <strong data-qa="today-moved">{{ $movedAlong }}</strong> moved along · <strong data-qa="today-late" @if($lateAll > 0) style="color: var(--ds-red, #f87171);" @endif>{{ $lateAll }}</strong> late</span>
                @endif
                @if($progress['counted'] > 0)<span class="text-xs" style="color: var(--text-primary);"><strong>{{ $progress['met'] }} of {{ $progress['counted'] }}</strong> targets met</span>@endif
                @if($progress['level'] || (($pointsTotals['month'] ?? 0) > 0))
                    @php
                        $pt = $pointsTotals ?? null;
                        $ptMonth = $pt ? (float) $pt['month'] : (float) $progress['points'];
                        $ptLabel = $pt ? \Illuminate\Support\Carbon::parse($pt['date'])->format('F') : $progress['points_label'];
                    @endphp
                    <span class="text-xs inline-flex items-center gap-2" style="color: var(--text-primary);" data-qa="header-points">
                        {{-- the figure is the agent's own points log ("why did I get these points"); another agent's log is theirs to read --}}
                        @if($viewingOwn && \Illuminate\Support\Facades\Route::has('road-to-sold.points'))
                            <a href="{{ route('road-to-sold.points') }}" class="underline" style="color: inherit;" title="Why did I get these points?" data-qa="header-points-link"><strong>{{ number_format($ptMonth, 0) }}</strong> points in {{ $ptLabel }}</a>
                        @else
                            <span><strong>{{ number_format($ptMonth, 0) }}</strong> points in {{ $ptLabel }}</span>
                        @endif
                        @if($pt && (float) $pt['day'] > 0)<span style="color: var(--text-muted);" data-qa="header-points-day">{{ number_format((float) $pt['day'], 0) }} today</span>@endif
                        @if($progress['level'])<x-points-split-total :split="$progress['points_split']" split-only />@endif
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($agents->count() > 1)
                    <form method="GET" class="flex items-center gap-2">
                        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                        <label class="text-xs font-semibold" style="color: var(--text-muted);" for="rts-agent">Agent</label>
                        <select id="rts-agent" name="agent" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                            @foreach($agents as $a)
                                <option value="{{ $a->id }}" @selected($a->id === $agentId)>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                @if($captureTasks->isNotEmpty())
                    <button type="button" class="corex-btn-primary corex-btn-xs" @click="openCapture()">Capture</button>
                @endif
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if(session('rts_error'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('rts_error') }}</div>
    @endif
    @if($errors->any())
        @php $capErr = old('task_id') !== null; @endphp
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->getMessages() as $field => $messages)
                @foreach($messages as $m)
                    {{-- A message from the capture form links to the field to fix (the form opens if it is closed). --}}
                    @if($capErr)
                        <div><a href="#cap-{{ str_replace('.', '-', $field) }}" class="underline" @click.prevent="focusCapture('cap-{{ str_replace('.', '-', $field) }}')">{{ $m }}</a></div>
                    @else
                        <div>{{ $m }}</div>
                    @endif
                @endforeach
            @endforeach
        </div>
    @endif

    {{-- v4 belt (spec 23.13): the tile row (or the Start card) - the team view is about everyone, so not there. --}}
    @unless($isTeam)
        @include('road-to-sold._belt-tiles')
    @endunless

    {{-- What to do next and where the month stands stay in view on every block (the team view is about everyone, so not there). --}}
    {{-- 23.31: the next-action banner is gone - the list's top row is the next action and carries a "Next" marker --}}
    @if(! $isTeam && ! empty($progress['steps']))
        <div class="rts-top">
            @include('road-to-sold._summary-bar')
        </div>
    @endif

    @include('road-to-sold._blocks-bar')

    @if(($canRelease ?? false) && in_array($block, ['prospects', 'parked'], true))
        @include('road-to-sold._release-modal')
    @endif

    <div class="rts-block-body {{ $block === 'progress' ? '' : 'is-fit' }}">
    @if($block === 'progress')
    @unless($isTeam)
        @include('road-to-sold._recipe', ['part' => 'tasks'])
    @endunless

    @elseif($block === 'prospects')
        @include('road-to-sold._block-prospects')
    @elseif($block === 'parked')
        @include('road-to-sold._block-parked')
    @elseif($block === 'station')
        @include('road-to-sold._block-station')
    @else
    {{-- Filters --}}
    @if(! $isCaptures)
    {{-- the list's search / Status / Filters sit on the toolbar row above (_blocks-bar -> _list-search) --}}
    @else
    <form method="GET" class="flex items-end gap-3 flex-wrap" data-tour="smk-rts-filters">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        <div data-tour="smk-rts-view">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-view">Show</label>
            <select id="rts-view" name="view" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($viewLabels as $v => $l)<option value="{{ $v }}" @selected($filters['view'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div data-tour="smk-rts-search">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-q">Search</label>
            <input id="rts-q" type="text" name="q" value="{{ $isCaptures ? ($cf['q'] ?? '') : $filters['q'] }}" placeholder="{{ $isCaptures ? 'Task or note' : 'Address, suburb, owner or phone' }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 14rem;">
        </div>
        @if($isCaptures)
            @if($isTeam)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-agentf">Agent</label>
                    <select id="rts-agentf" name="agent_filter" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                        <option value="">All agents</option>
                        @foreach($agents as $a)<option value="{{ $a->id }}" @selected((string) ($cf['agent'] ?? '') === (string) $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-task">Task</label>
                <select id="rts-task" name="task" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All tasks</option>
                    @foreach($captureChoices['tasks'] as $k => $l)<option value="{{ $k }}" @selected((string) ($cf['task'] ?? '') === (string) $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-step">Step</label>
                <select id="rts-step" name="step" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All steps</option>
                    @foreach($captureChoices['steps'] as $k => $l)<option value="{{ $k }}" @selected((string) ($cf['step'] ?? '') === (string) $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-source">Made</label>
                <select id="rts-source" name="source" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">Any</option>
                    @foreach(\App\Services\RoadToSold\CompletionListService::SOURCES as $k => $l)<option value="{{ $k }}" @selected(($cf['source'] ?? '') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-cstatus">Status</label>
                <select id="rts-cstatus" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    @foreach(\App\Services\RoadToSold\CompletionListService::STATUSES as $k => $l)<option value="{{ $k }}" @selected(($cf['status'] ?? 'active') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-from">From</label>
                <input id="rts-from" type="date" name="from" value="{{ $cf['from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-to">To</label>
                <input id="rts-to" type="date" name="to" value="{{ $cf['to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
            </div>
        @endif
        @if($listView)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sel">Selection</label>
                <select id="rts-sel" name="selection" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All selections</option>
                    @foreach($selections as $k => $l)<option value="{{ $k }}" @selected($filters['selection'] === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div data-tour="smk-rts-status">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-status">Status</label>
                <select id="rts-status" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    @foreach($statusLabels as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-date">Day</label>
                <x-dmy-date id="rts-date" name="date" :value="$date->toDateString()" :required="true" :submit="true" />
            </div>
        @endif
        <div data-tour="smk-rts-sort">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sort">Sort</label>
            <select id="rts-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($sortLabels as $v => $l)<option value="{{ $v }}" @selected(($isCaptures ? ($cf['sort'] ?? 'when') : $filters['sort']) === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <select name="dir" aria-label="Sort direction" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @php $dirNow = $isCaptures ? (($cf['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc') : $filters['dir']; @endphp
                <option value="asc" @selected($dirNow === 'asc')>Ascending</option>
                <option value="desc" @selected($dirNow === 'desc')>Descending</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs" data-tour="smk-rts-search-btn">Search</button>
        @if($isCaptures || $filters['q'] !== '' || $filters['selection'] !== '' || $filters['status'] !== 'open' || $filters['view'] !== 'today')
            <a href="{{ route('road-to-sold.index', ($viewingOwn ? [] : ['agent' => $agentId]) + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
    </form>
    @endif


    @if($isCaptures)
        @include('road-to-sold._captures')
    @else
    {{-- The list: fills the block under the filters; only IT scrolls (inside itself) when there are more rows than fit --}}
    <div class="rounded-md rts-grow" style="background: var(--surface); border: 1px solid var(--border);" data-qa="intake-table" data-tour="smk-rts-list">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Why - start here</th>
                    <th class="px-3 py-2 text-left">Address</th>
                    <th class="px-3 py-2 text-left">Price · days on market · agency</th>
                    <th class="px-3 py-2 text-left">Owner</th>
                    <th class="px-3 py-2 text-left rts-col-last">Last outcome</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @php $__pitchCards = app(\App\Services\Prospecting\MicWork\PitchCardResolver::class)->trackedWithCards((int) auth()->user()->effectiveAgencyId(), collect($rowsPage)->pluck('tracked_property_id')); @endphp
            @forelse($rowsPage as $r)
                @php $lo = $r['last_outcome']; $ownerMissing = $viewingOwn ? ($ownerBlocked[(int) $r['tracked_property_id']] ?? null) : null; $nextRow = $loop->first && ($r['status'] ?? '') === 'open' && (! method_exists($rowsPage, 'currentPage') || $rowsPage->currentPage() === 1); @endphp
                <tr style="border-top: 1px solid var(--border);">
                    @include('road-to-sold._row-cells')
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @if(! empty($history[$r['tracked_property_id']]))
                                <button type="button" class="corex-btn-outline corex-btn-xs" data-tp="{{ $r['tracked_property_id'] }}" data-address="{{ $r['address'] }}" @if($loop->first) data-tour="smk-rts-history" @endif
                                        @click="openHistory($el.dataset)">View</button>
                            @endif
                            @if($viewingOwn && $r['status'] === 'open' && ! empty($intakeProspects[$r['tracked_property_id']]))
                                @php
                                    // Belt v2: the row's ONE button is the record's own stage button (the same map as the station lists): only a true Loaded row says Find owner (and claims first);
                                    // a row on today's desk that is already past Loaded shows its real next step
                                    $rowStage = (string) ($intakeStages[$r['tracked_property_id']] ?? 'found');
                                    $rowStep = app(\App\Services\RoadToSold\ProspectStepService::class)->primary(['stage' => $rowStage], true);
                                    $stepKey = $rowStep['key'];
                                    $rowLabel = $rowStage === 'evaluation_done' ? 'Record result' : $rowStep['label'];
                                @endphp
                                <button type="button" class="corex-btn-outline corex-btn-xs" data-qa="intake-step" data-action="{{ $rowLabel }}" data-tracked="{{ isset($__pitchCards[(int) $r['tracked_property_id']]) ? $r['tracked_property_id'] : '' }}"
                                        data-step-url="{{ route('road-to-sold.prospects.step', [$intakeProspects[$r['tracked_property_id']], $stepKey]) }}"
                                        data-step-label="{{ $rowLabel }} - {{ $r['address'] }}"
                                        @if($rowStage === 'found' && ! empty($r['list_item_id']) && ($r['origin'] ?? '') !== 'follow_up') data-claim-url="{{ route('road-to-sold.claim-loaded', $r['list_item_id']) }}" @endif
                                        @click="rtsClaimFirst($el)">{{ $rowLabel }}</button>
                            @endif
                            @if($viewingOwn && $r['status'] === 'open' && ! empty($r['list_item_id']) && ($r['origin'] ?? '') !== 'follow_up' && ! in_array((int) $r['tracked_property_id'], $startedTracked ?? [], true))
                                {{-- 23.14 + Belt v2: swap this loaded property for the next one - the Stops here control (public/js/pitch-offramp.js), the step 1 "Not for me" reasons, counted at Today's work --}}
                                <span class="relative" data-qa="intake-swap">
                                    <button type="button" class="corex-btn-outline corex-btn-xs" data-qa="swap-open" title="Swap this property for the next one"
                                            data-item="{{ $r['list_item_id'] }}" data-address="{{ $r['address'] }}"
                                            @click="rtsSwap($el.dataset)">Swap</button>
                                </span>
                            @endif
                            @if($viewingOwn && ($r['status'] === 'open' || in_array($filters['view'], ['no_answer', 'wrong_number'], true)))
                                {{-- v4 gate (23.6): no owner yet = no Log contact here; the Find owner button on this row (and on the card above) is the one way forward --}}
                                @if(! $ownerMissing && (($r['origin'] ?? '') === 'follow_up' || in_array($filters['view'], ['no_answer', 'wrong_number'], true)))
                                <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="log-contact" @if($loop->first) data-tour="smk-rts-contact" @endif
                                        data-tp="{{ $r['tracked_property_id'] }}" data-item="{{ $r['list_item_id'] }}" data-address="{{ $r['address'] }}"
                                        @click="openContact($el.dataset)">Contact</button>
                                @endif
                            @elseif($r['status'] !== 'open')
                                <span style="color: var(--text-muted);">{{ ucfirst($r['status']) }}</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center">
                        @php
                            $filtered = $filters['q'] !== '' || $filters['selection'] !== '' || $filters['status'] !== 'open';
                        @endphp
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            @if($filtered)
                                Nothing matches this search.
                            @elseif($filters['view'] === 'no_answer')
                                No properties are waiting on an answer.
                            @elseif($filters['view'] === 'wrong_number')
                                No properties are on a wrong-number list.
                            @elseif($filters['view'] === 'no_owner')
                                Every property on this list has an owner on file.
                            @elseif($counts['any'] > 0)
                                Everything on this day's list is done.
                            @else
                                No list for {{ $date->isToday() ? 'today' : $date->format('j M') }}.
                            @endif
                        </div>
                        {{-- Why it is empty and the one thing to do about it (spec 10.5): only when the day has no rows at all. --}}
                        @if(! $filtered && $filters['view'] === 'today' && (int) $counts['any'] === 0 && ! empty($emptyState))
                            <div class="mt-2 text-xs" style="color: var(--text-muted);" data-empty-reason="{{ $emptyState['code'] }}">{{ $emptyState['line'] }}</div>
                            @php $act = $emptyState['action'] ?? null; @endphp
                            @if(($act['kind'] ?? null) === 'link')
                                <a href="{{ $act['url'] }}" class="corex-btn-primary corex-btn-xs mt-4 inline-flex" data-rts-popup data-step-label="{{ $act['label'] }}">{{ $act['label'] }}</a>
                            @elseif(($act['kind'] ?? null) === 'build')
                                <form method="POST" action="{{ route('road-to-sold.build-list') }}" class="mt-4">
                                    @csrf
                                    <input type="hidden" name="agent" value="{{ $act['agent'] }}">
                                    @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                                    <button type="submit" class="corex-btn-primary corex-btn-xs">{{ $act['label'] }}</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($capped)
        <div class="text-xs" style="color: var(--text-muted);">Showing the first {{ \App\Services\RoadToSold\CallListService::MAX_ROWS }}. Narrow the search to see the rest.</div>
    @endif
    {{ $rowsPage->links() }}
    @endif

    {{-- Other work, not counted against today's target --}}
    @php
        $feedBlocks = [
            ['key' => 'buyers',   'title' => 'Buyers to follow up', 'empty' => 'No buyers are waiting for contact.',      'all' => feature('buyer-pipeline') ? route('command-center.buyers.pipeline') : null],
            ['key' => 'leads',    'title' => 'Enquiries waiting',   'empty' => 'No enquiries are waiting for a response.', 'all' => null],
            ['key' => 'mandates', 'title' => 'Mandates ending soon', 'empty' => 'No mandates end soon.',                      'all' => route('corex.properties.index')],
        ];
    @endphp
    @if(! empty($feeds))
    {{-- The three other-work panels as a compact strip of counters under the table; click one to open its list (it floats over the page, it never pushes the table). --}}
    <div class="relative flex items-center gap-2 flex-wrap text-xs" x-data="{ open: null }" @click.outside="open = null" @keydown.escape.window="open = null" data-qa="feeds-strip">
        <span class="font-bold uppercase tracking-widest" style="color: var(--text-muted);">Other work</span>
        @foreach($feedBlocks as $fb)
            @php $feed = $feeds[$fb['key']] ?? ['count' => 0, 'items' => []]; @endphp
            <div class="relative">
                <button type="button" class="corex-btn-outline corex-btn-xs" :aria-expanded="open === '{{ $fb['key'] }}'" @click="open = open === '{{ $fb['key'] }}' ? null : '{{ $fb['key'] }}'" data-feed="{{ $fb['key'] }}">{{ $fb['title'] }} <strong>{{ $feed['count'] }}</strong></button>
                <div x-show="open === '{{ $fb['key'] }}'" x-cloak class="absolute left-0 rounded-md overflow-auto" style="bottom: 100%; margin-bottom: .25rem; width: 22rem; max-height: 16rem; z-index: 30; background: var(--surface); border: 1px solid var(--border); box-shadow: 0 6px 18px rgba(0,0,0,.18);">
                    @forelse($feed['items'] as $it)
                        <div class="px-4 py-2" style="border-top: 1px solid var(--border);">
                            @if($it['url'])<a href="{{ $it['url'] }}" class="font-semibold" style="color: var(--text-primary);" data-rts-popup data-step-label="{{ $it['title'] }}">{{ $it['title'] }}</a>
                            @else<span class="font-semibold" style="color: var(--text-primary);">{{ $it['title'] }}</span>@endif
                            <div style="color: var(--text-muted);">{{ $it['sub'] }}</div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center" style="color: var(--text-muted);">{{ $fb['empty'] }}</div>
                    @endforelse
                    @if($fb['all'] && $feed['count'] > count($feed['items']))
                        <div class="px-4 py-2" style="border-top: 1px solid var(--border);"><a href="{{ $fb['all'] }}" style="color: var(--text-primary);" data-rts-popup data-step-label="{{ $fb['title'] }}">See all {{ $feed['count'] }}</a></div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    @endif

    @endif
    </div>{{-- /rts-block-body --}}

    @if($captureTasks->isNotEmpty())
        @include('road-to-sold._capture-modal')
    @endif

    {{-- History popup --}}
    <div x-show="historyOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="historyOpen = false">
        <div class="w-full max-w-lg rounded-md p-5 space-y-3 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;">
            <div>
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">History</h2>
                <div class="text-xs mt-1" style="color: var(--text-muted);" x-text="address"></div>
            </div>
            <template x-for="(h, i) in historyRows" :key="i">
                <div class="text-xs py-2" style="border-top: 1px solid var(--border); color: var(--text-primary);">
                    <div><span class="font-semibold" x-text="h.label"></span>
                        <span style="color: var(--text-muted);" x-text="(h.valuation ? ' · appointment booked' : '')"></span></div>
                    <div style="color: var(--text-muted);" x-text="h.when + (h.by ? ' · ' + h.by : '') + (h.next ? ' · back on ' + h.next : '')"></div>
                    <div x-show="h.note" x-text="h.note"></div>
                </div>
            </template>
            <div class="flex items-center justify-end">
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="historyOpen = false">Close</button>
            </div>
        </div>
    </div>

    {{-- Log contact popup (was "Contacted client") --}}
    {{-- Belt v2: Swap = the Stops here control for step 1 ("Not for me") --}}
    <div x-show="swapOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="swapOpen = false" data-qa="swap-modal">
        <div class="w-full rounded-md p-4 space-y-2" style="max-width: 44rem; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <div class="text-xs" style="color: var(--text-muted);">Swap this property for the next one - <span x-text="swapAddress"></span></div>
            <div x-ref="swapHost" class="cxo-fold" style="max-height: 60vh;"></div>
        </div>
    </div>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="open = false">
        <form method="POST" action="{{ route('road-to-sold.outcomes.record') }}" data-tour="smk-rts-contact-form" class="w-full max-w-lg rounded-md p-5 space-y-4 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;">
            @csrf
            @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <input type="hidden" name="tracked_property_id" :value="tp">
            <input type="hidden" name="list_item_id" :value="item">

            <div>
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">Contact</h2>
                <div class="text-xs mt-1" style="color: var(--text-muted);" x-text="address"></div>
            </div>

            {{-- how the owner was contacted comes first (road-to-sold.md 23.7); the agent's last way is ticked --}}
            <fieldset class="space-y-1" data-qa="channel-pick">
                <legend class="text-xs font-semibold mb-1" style="color: var(--text-muted);">How did you contact them?</legend>
                <div class="flex flex-wrap gap-3">
                    <template x-for="(label, key) in channels" :key="key">
                        <label class="flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-primary);">
                            <input type="radio" name="channel" :value="key" x-model="channel" required>
                            <span x-text="label"></span>
                        </label>
                    </template>
                </div>
            </fieldset>

            <div class="space-y-3" data-tour="smk-rts-outcome-pick">
                <template x-for="g in groups" :key="g.key">
                    <div x-show="g.items.length">
                        <div class="text-[0.6875rem] font-bold uppercase mb-1" style="color: var(--text-muted);" x-text="g.title"></div>
                        <div class="space-y-2">
                            <template x-for="c in g.items" :key="c.id">
                                <label class="flex items-start gap-2 text-xs cursor-pointer" style="color: var(--text-primary);">
                                    <input type="radio" name="outcome_definition_id" :value="c.id" x-model="chosen" class="mt-0.5">
                                    <span>
                                        <span class="font-semibold" x-text="labelOf(c)"></span>
                                        <span style="color: var(--text-muted);" x-show="c.summary" x-text="' - ' + c.summary"></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="text-xs" style="color: var(--text-muted);" x-show="current" x-text="current ? current.summary : ''"></div>

            <div x-show="current && current.needs_date">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-next">Bring it back on</label>
                <input id="rts-next" type="date" name="next_action_at" x-model="nextDate" :required="current && current.needs_date && !valuation" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
            </div>

            <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary);" x-show="current && current.offers_valuation">
                <input type="checkbox" name="valuation_booked" value="1" x-model="valuation">
                Appointment booked
            </label>

            <div x-show="needsAppointment">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-appt">Appointment date and time</label>
                <input id="rts-appt" type="datetime-local" name="appointment_at" x-model="appointmentAt" :required="needsAppointment" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
            </div>

            <div data-tour="smk-rts-note">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-note">Note</label>
                <textarea id="rts-note" name="note" rows="3" maxlength="5000" x-model="note" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 rts-actions">
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="open = false">Cancel</button>
                <button type="submit" class="corex-btn-primary corex-btn-xs" :disabled="!current" data-tour="smk-rts-save">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// The page refreshes itself in place after a pop-up closes (the owner was found, a contact was logged): the tiles, the list and the line are fetched
// again and swapped in, so nothing the agent had open is lost and the row "moves to Owner found" without a reload.
window.__cxpNoReload = true;
window.rtsRefreshLive = function () {
    var sels = ['[data-qa="belts"]', '[data-qa="today-line"]', '[data-qa="intake-table"]', '[data-qa="station-list"]', '[data-qa="block-prospects"]'];
    return fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            sels.forEach(function (sel) {
                var cur = document.querySelector(sel), fresh = doc.querySelector(sel);
                if (cur && fresh) { cur.replaceWith(fresh); }
            });
        })
        .catch(function () { window.location.reload(); });
};
window.addEventListener('corex-pitch-closed', function (e) { if (e.detail && e.detail.touched) { window.rtsRefreshLive(); } });
function rtsPage(cfg) {
    return {
        swapOpen: false, swapAddress: '',
        // Belt v2: the Swap on a Today's work row is the Stops here control (step 1 reasons); the swap itself is the one swap route (JSON when asked), counted at Today's work
        rtsSwap(ds) {
            var self = this; this.swapOpen = true; this.swapAddress = ds.address || '';
            var q = new URLSearchParams(); q.set('item', String(ds.item));
            this.$nextTick(function () { if (window.CorexOffRamp) { window.CorexOffRamp.mount(self.$refs.swapHost, { url: @js(route('road-to-sold.offramp')), post: @js(route('road-to-sold.swap', 0)).replace('/0/', '/' + ds.item + '/'), query: q, step: 1, title: 'Not for me', goLabel: 'Swap', onCancel: function () { self.swapOpen = false; }, onDone: function () { window.location.reload(); } }); } });
        },
        open: false,
        historyOpen: false,
        historyRows: [],
        tp: '',
        item: '',
        address: '',
        choices: cfg.choices || [],
        channels: cfg.channels || {},
        channel: cfg.oldChannel || cfg.lastChannel || '',
        chosen: '',
        nextDate: '',
        valuation: false,
        appointmentAt: '',
        note: '',
        // capture form (v2 phase 2)
        capOpen: false,
        capTasks: cfg.captureTasks || [],
        capTaskId: '',
        capToken: '',
        capArea: '',
        capWhen: '',
        capQty: 1,
        capNote: '',
        capProps: [],
        capContacts: [],
        capKey: 0,
        get capTask() {
            return this.capTasks.find(t => String(t.id) === String(this.capTaskId)) || null;
        },
        newToken() {
            return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2, 12);
        },
        blankProp(v) {
            return Object.assign({ key: ++this.capKey, street_number: '', street_name: '', suburb: '', complex_name: '', unit_number: '', erf_number: '', owner_name: '', owner_phone: '', owner_email: '' }, v || {});
        },
        blankContact(v) {
            return Object.assign({ key: ++this.capKey, name: '', phone: '', email: '' }, v || {});
        },
        capAddProp() { this.capProps.push(this.blankProp()); },
        capAddContact() { this.capContacts.push(this.blankContact()); },
        capReset() {
            this.capProps = [this.blankProp()];
            this.capContacts = [this.blankContact()];
        },
        openCapture(taskId) {
            if (!this.capTasks.length) { return; }
            this.capTaskId = taskId || (this.capTaskId || this.capTasks[0].id);
            this.capToken = this.newToken();
            this.capArea = ''; this.capWhen = ''; this.capQty = 1; this.capNote = '';
            this.capReset();
            this.capOpen = true;
        },
        // An error message links to the field to fix: open the form if it is closed, then focus the field.
        focusCapture(id) {
            this.capOpen = true;
            this.$nextTick(() => { const el = document.getElementById(id); if (el) { el.scrollIntoView({ block: 'center' }); el.focus(); } });
        },
        // one vocabulary (road-to-sold.md 23.11): forward moves, try again, off-ramps; the owner-problem outcomes stay available to report it
        get groups() {
            const t = { forward: 'Moves it forward', again: 'Try again later', off_ramp: 'Stops here (say why)' };
            var fit = (cfg.fit && cfg.fit[this.tp]) ? cfg.fit[this.tp].map(String) : null;   // only the outcomes that fit this record's station (23.7a)
            var shown = fit ? this.choices.filter(c => fit.indexOf(String(c.id)) !== -1) : this.choices;
            return ['forward', 'again', 'off_ramp'].map(k => ({ key: k, title: t[k], items: shown.filter(c => (c.group || 'again') === k) }));
        },
        labelOf(c) {
            return (this.channel && c.labels && c.labels[this.channel]) ? c.labels[this.channel] : c.label;
        },
        get current() {
            return this.choices.find(c => String(c.id) === String(this.chosen)) || null;
        },
        get needsAppointment() {
            return !!this.current && (this.current.needs_appointment || (this.current.offers_valuation && this.valuation));
        },
        init() {
            // A refused capture comes back with what was typed (photos cannot be put back): reopen it on the same task.
            if (cfg.captureReopen) {
                const o = cfg.captureOld || {};
                this.capTaskId = cfg.captureReopen;
                this.capToken = this.newToken();
                this.capArea = o.area || ''; this.capWhen = o.when || ''; this.capQty = o.qty || 1; this.capNote = o.note || '';
                this.capProps = (o.properties && o.properties.length ? o.properties : [{}]).map(p => this.blankProp(p));
                this.capContacts = (o.contacts && o.contacts.length ? o.contacts : [{}]).map(c => this.blankContact(c));
                this.capOpen = true;
            }
            const r = cfg.reopen;
            if (r && r.tp) {
                this.tp = r.tp;
                this.item = r.item || '';
                this.address = (cfg.addresses && cfg.addresses[r.tp]) || '';
                this.chosen = cfg.oldOutcome || '';
                this.channel = cfg.oldChannel || this.channel;
                this.nextDate = cfg.oldDate || '';
                this.valuation = !!cfg.oldValuation;
                this.appointmentAt = cfg.oldAppointment || '';
                this.note = cfg.oldNote || '';
                this.open = true;
            }
        },
        openHistory(ds) {
            this.address = ds.address || '';
            this.historyRows = (cfg.history && cfg.history[ds.tp]) || [];
            this.historyOpen = true;
        },
        openContact(ds) {
            this.tp = ds.tp;
            this.item = ds.item || '';
            this.address = ds.address || '';
            this.chosen = '';
            this.nextDate = '';
            this.valuation = false;
            this.appointmentAt = '';
            this.note = '';
            this.open = true;
        },
    };
}
</script>
@endsection
