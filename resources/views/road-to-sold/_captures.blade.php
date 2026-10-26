{{-- AT-454 v2 phase 2 - the agent's task completions: what was done, what it found, archive and restore. Needs $captures (paginator), $captureFilters,
     $viewingOwn. Search, sort, filters, pagination and the empty state come from the filter form on the page; archived rows are restored here. --}}
@php
    $links = app(\App\Services\RoadToSold\CompletionListService::class)->recordLinks($captures);
    $sources = \App\Services\RoadToSold\CompletionListService::SOURCES;
    $states = \App\Services\RoadToSold\CompletionListService::STATES;
    $filtered = collect(['q', 'task', 'step', 'state', 'source', 'from', 'to'])->contains(fn ($k) => ($captureFilters[$k] ?? '') !== '' && ($captureFilters[$k] ?? null) !== null)
        || ($captureFilters['status'] ?? 'active') !== 'active';
@endphp
@if($isTeam)
    {{-- Points per agent over the range (default: this month so far), split manual / automatic through the one shared rule. --}}
    <div class="rounded-md overflow-x-auto mb-4" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-right">Done</th>
                    <th class="px-3 py-2 text-right">Points</th>
                </tr>
            </thead>
            <tbody>
            @forelse($teamTotals as $t)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2" style="color: var(--text-primary);"><a href="{{ route('road-to-sold.index', ['agent' => $t['user_id']] + \App\Services\RoadToSold\Track::params($track)) }}" class="font-semibold underline">{{ $t['name'] }}</a></td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $t['done'] }}</td>
                    <td class="px-3 py-2 text-right"><x-points-split-total :split="['manual' => $t['manual'], 'automatic' => $t['automatic'], 'total' => $t['total']]" size="sm" /></td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-6 py-8 text-center" style="color: var(--text-muted);">No points in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif
<div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: null }">
    <table class="w-full text-xs ds-table">
        <thead>
            <tr style="color: var(--text-muted);">
                <th class="px-3 py-2 text-left">When</th>
                @if($isTeam)<th class="px-3 py-2 text-left">Agent</th>@endif
                <th class="px-3 py-2 text-left">Task</th>
                <th class="px-3 py-2 text-right">Done</th>
                <th class="px-3 py-2 text-right">Points</th>
                <th class="px-3 py-2 text-left">Found</th>
                <th class="px-3 py-2 text-left">Note</th>
                <th class="px-3 py-2 text-left">Made</th>
                <th class="px-3 py-2 text-right"></th>
            </tr>
        </thead>
        <tbody>
        @forelse($captures as $c)
            @php $archived = $c->trashed(); $mine = $c->source === 'captured'; @endphp
            <tr style="border-top: 1px solid var(--border);{{ $archived ? ' opacity: .65;' : '' }}">
                <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ \App\Support\AppDate::dateTime($c->occurred_at) }}</td>
                @if($isTeam)<td class="px-3 py-2" style="color: var(--text-primary);">{{ $c->agent?->name }}</td>@endif
                <td class="px-3 py-2" style="color: var(--text-primary);">
                    <div class="font-semibold">{{ $c->task?->label ?? '-' }}</div>
                    <div style="color: var(--text-muted);">{{ $c->step?->label }}</div>
                </td>
                <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $c->quantity }}</td>
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
                <td class="px-3 py-2" style="color: var(--text-primary); max-width: 20rem;">
                    <template x-if="editing !== {{ $c->id }}"><span>{{ $c->evidence_note ?: '-' }}</span></template>
                    @if($mine && ! $archived)
                        <template x-if="editing === {{ $c->id }}">
                            <form method="POST" action="{{ route('road-to-sold.captures.update', $c->id) }}" class="flex items-start gap-2">
                                @csrf @method('PUT')
                                <textarea name="note" rows="2" maxlength="5000" class="w-full rounded-md px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $c->evidence_note }}</textarea>
                                <button type="submit" class="corex-btn-primary corex-btn-xs">Save</button>
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="editing = null">Cancel</button>
                            </form>
                        </template>
                    @endif
                </td>
                <td class="px-3 py-2" style="color: var(--text-primary);">
                    {{ $sources[$c->source] ?? $c->source }}
                    @if($c->recorded_by_kind === 'assistant')<div style="color: var(--text-muted);">by {{ $c->recordedBy?->name }}</div>@endif
                    @if($c->state !== 'confirmed')<div style="color: var(--text-muted);">{{ $states[$c->state] ?? $c->state }}</div>@endif
                    @if($c->state === 'returned' && $c->review_comment)<div style="color: var(--text-muted);">"{{ $c->review_comment }}"</div>@endif
                </td>
                <td class="px-3 py-2 text-right">
                    @if($mine)
                        <div class="flex items-center justify-end gap-2">
                            @if($archived)
                                <form method="POST" action="{{ route('road-to-sold.captures.restore', $c->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                            @else
                                @if($c->state === 'returned' && ($viewingOwn ?? false))
                                    <form method="POST" action="{{ route('road-to-sold.captures.resubmit', $c->id) }}">@csrf<button type="submit" class="corex-btn-primary corex-btn-xs">Send again</button></form>
                                @endif
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="editing = {{ $c->id }}">Edit note</button>
                                <form method="POST" action="{{ route('road-to-sold.captures.archive', $c->id) }}">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button></form>
                            @endif
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $isTeam ? 9 : 8 }}" class="px-6 py-12 text-center">
                    <div class="text-base font-semibold" style="color: var(--text-primary);">
                        {{ $filtered ? 'Nothing matches this search.' : 'Nothing has been recorded yet.' }}
                    </div>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $captures->links() }}
