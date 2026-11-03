{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 6 - the manager's review of captured tasks. Needs $rows (paginator), $filters, $links, $choices, $agents, $waiting, $returned,
     $track, $review, $viewer. Search, sort, filters, pagination and the empty state are all on this page; the rows are the team's completions
     inside the viewer's breadth (branch manager = branch, admin = agency). --}}
@extends('layouts.corex-app')
@section('title', 'Review captured tasks')

@section('corex-content')
@php
    $f = $filters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $states = \App\Services\RoadToSold\CompletionListService::STATES;
    $doneBy = \App\Services\RoadToSold\CompletionListService::DONE_BY;
    $statuses = \App\Services\RoadToSold\CompletionListService::STATUSES;
    $params = \App\Services\RoadToSold\Track::params($track);
    $stateNow = $f['state_raw'] === null ? 'provisional' : ($f['state_raw'] === 'any' ? 'any' : (string) $f['state_raw']);
    $filtered = collect(['q', 'task', 'step', 'by', 'from', 'to', 'agent'])->contains(fn ($k) => ($f[$k] ?? '') !== '' && ($f[$k] ?? null) !== null)
        || $stateNow !== 'provisional' || ($f['status'] ?? 'active') !== 'active';
@endphp

<div class="w-full space-y-4 rts-page">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Review captured tasks</h1>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ $waiting }}</strong> waiting for review</span>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ $returned }}</strong> sent back</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Road to Sold</a>
                @if(\Illuminate\Support\Facades\Route::has('road-to-sold.settings.index') && $viewer->hasPermission('road_to_sold.manage_settings'))
                    <a href="{{ route('road-to-sold.settings.index') }}" class="corex-btn-outline corex-btn-xs">Settings</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('road-to-sold.review.index') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-q">Search</label>
            <input id="rv-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent, task or note" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 14rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-state">Status</label>
            <select id="rv-state" name="state" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['provisional' => 'Waiting for review', 'returned' => 'Sent back', 'confirmed' => 'Counting', 'any' => 'Any'] as $k => $l)<option value="{{ $k }}" @selected($stateNow === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-agent">Agent</label>
            <select id="rv-agent" name="agent" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All agents</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected((string) ($f['agent'] ?? '') === (string) $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-task">Task</label>
            <select id="rv-task" name="task" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All tasks</option>
                @foreach($choices['tasks'] as $k => $l)<option value="{{ $k }}" @selected((string) ($f['task'] ?? '') === (string) $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-step">Step</label>
            <select id="rv-step" name="step" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All steps</option>
                @foreach($choices['steps'] as $k => $l)<option value="{{ $k }}" @selected((string) ($f['step'] ?? '') === (string) $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-by">Done by</label>
            <select id="rv-by" name="by" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">Anyone</option>
                @foreach($doneBy as $k => $l)<option value="{{ $k }}" @selected(($f['by'] ?? '') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-archived">Archived</label>
            <select id="rv-archived" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($statuses as $k => $l)<option value="{{ $k }}" @selected(($f['status'] ?? 'active') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-from">From</label>
            <input id="rv-from" type="date" name="from" value="{{ $f['from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-to">To</label>
            <input id="rv-to" type="date" name="to" value="{{ $f['to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rv-sort">Sort</label>
            <select id="rv-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(\App\Http\Controllers\RoadToSold\ReviewController::SORTS as $k => $l)<option value="{{ $k }}" @selected(($f['sort'] ?? 'when') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <select name="dir" aria-label="Sort direction" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="desc" @selected(($f['dir'] ?? 'desc') !== 'asc')>Newest first</option>
                <option value="asc" @selected(($f['dir'] ?? 'desc') === 'asc')>Oldest first</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route('road-to-sold.review.index', $params) }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ returning: null }">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">When</th>
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Task</th>
                    <th class="px-3 py-2 text-right">Points</th>
                    <th class="px-3 py-2 text-left">Found</th>
                    <th class="px-3 py-2 text-left">Note</th>
                    <th class="px-3 py-2 text-left">Done by</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $c)
                @php
                    $archived = $c->trashed();
                    $reviewable = $review->reviewable($c);
                    $own = $review->isOwnWork($viewer, $c);
                    $canApprove = $reviewable && ! $own && in_array($c->state, ['provisional', 'returned'], true);
                    $canReturn = $reviewable && ! $own && in_array($c->state, ['provisional', 'confirmed'], true);
                @endphp
                <tr style="border-top: 1px solid var(--border);{{ $archived ? ' opacity: .65;' : '' }}">
                    <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ \App\Support\AppDate::dateTime($c->occurred_at) }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $c->agent?->name }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        <div class="font-semibold">{{ $c->task?->label ?? '-' }}</div>
                        <div style="color: var(--text-muted);">{{ $c->step?->label }}</div>
                    </td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ rtrim(rtrim(number_format((float) $c->points_awarded, 2), '0'), '.') }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @forelse($links[$c->id] ?? [] as [$label, $url])
                            <div>@if($url)<a href="{{ $url }}" class="underline">{{ $label }}</a>@else{{ $label }}@endif</div>
                        @empty
                            -
                        @endforelse
                        @foreach($c->evidence['photos'] ?? [] as $n => $photo)
                            <div><a href="{{ route('road-to-sold.captures.photo', [$c->id, $n]) }}" class="underline" target="_blank" rel="noopener">Photo {{ $n + 1 }}</a></div>
                        @endforeach
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary); max-width: 18rem;">{{ $c->evidence_note ?: '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        {{ $doneBy[$c->recorded_by_kind] ?? $c->recorded_by_kind }}
                        @if($c->recorded_by_kind === 'assistant')<div style="color: var(--text-muted);">{{ $c->recordedBy?->name }}</div>@endif
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary); max-width: 16rem;">
                        {{ $states[$c->state] ?? $c->state }}
                        @if($c->state === 'returned' && $c->review_comment)<div style="color: var(--text-muted);">"{{ $c->review_comment }}"</div>@endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2 flex-wrap">
                            <a href="{{ route('road-to-sold.review.show', $c->id) }}" class="corex-btn-outline corex-btn-xs">Open</a>
                            @if($canApprove && ! $archived)
                                <form method="POST" action="{{ route('road-to-sold.review.approve', $c->id) }}">@csrf<button type="submit" class="corex-btn-primary corex-btn-xs">Approve</button></form>
                            @endif
                            @if($canReturn && ! $archived)
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="returning = {{ $c->id }}">Send back</button>
                            @endif
                            @if($reviewable && $own)
                                <span style="color: var(--text-muted);">Another manager reviews your own work</span>
                            @endif
                            @if($c->source === 'captured')
                                @if($archived)
                                    <form method="POST" action="{{ route('road-to-sold.captures.restore', $c->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                @else
                                    <form method="POST" action="{{ route('road-to-sold.captures.archive', $c->id) }}" data-confirm="Archive this task? It stops counting and can be restored from the Archived list.">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button></form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
                @if($canReturn && ! $archived)
                    <tr x-show="returning === {{ $c->id }}" x-cloak style="border-top: 1px solid var(--border);">
                        <td colspan="9" class="px-3 py-3">
                            <form method="POST" action="{{ route('road-to-sold.review.send-back', $c->id) }}" class="flex items-start gap-2 flex-wrap">
                                @csrf
                                <label class="sr-only" for="rv-comment-{{ $c->id }}">What needs to change</label>
                                <textarea id="rv-comment-{{ $c->id }}" name="comment" rows="2" maxlength="2000" required placeholder="What needs to change? The agent sees this." class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }} min-width: 22rem;"></textarea>
                                <button type="submit" class="corex-btn-primary corex-btn-xs">Send back</button>
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="returning = null">Cancel</button>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="9" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            {{ $filtered ? 'Nothing matches this search.' : 'Nothing is waiting for review.' }}
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            {{ $filtered
                                ? 'Clear the search or change a filter to see more.'
                                : 'Captured tasks wait here when your agency asks for approval (Settings, Road to Sold), and any task you send back shows under "Sent back".' }}
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
