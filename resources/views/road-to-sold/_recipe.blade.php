{{-- AT-454 v2 phase 2 - "do this next" and the recipe's tasks. Needs $nextAction, $progress, $viewingOwn, $captureTasks, $canManageRecipe, $track.
     Data and controls only: the next thing with the button that does it, then each task of the agent's level with its progress. --}}
@php
    $stateBadge = fn ($st) => match ($st) {
        'achieved' => 'ds-badge-achieved', 'ahead' => 'ds-badge-ahead', 'on_track' => 'ds-badge-ontrack',
        'amber' => 'ds-badge-warning', 'behind' => 'ds-badge-behind', default => 'ds-badge-default',
    };
    $stateBar = fn ($st) => match ($st) {
        'achieved', 'ahead', 'on_track' => 'ds-bar-green', 'amber' => 'ds-bar-amber', 'behind' => 'ds-bar-crimson', default => 'ds-bar-navy',
    };
    $recipeUrl = $canManageRecipe && \Illuminate\Support\Facades\Route::has('road-to-sold.settings.recipe.index')
        ? route('road-to-sold.settings.recipe.index', \App\Services\RoadToSold\Track::params($track)) : null;
    $na = $nextAction;
    $naEdge = $na['kind'] === 'done' ? 'var(--rts-achieved)' : 'var(--brand-button, #0ea5e9)';
@endphp

@if(($part ?? 'next') === 'next')
{{-- Next action: the one most important thing now, with the control that does it. --}}
<div class="rounded-md px-4 py-3 flex items-center justify-between gap-3 flex-wrap" id="rts-next-action"
     style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $naEdge }};">
    <div>
        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $na['title'] }}</div>
        @if($na['detail'])<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $na['detail'] }}</div>@endif
    </div>
    @if($na['action'])
        @php $act = $na['action']; @endphp
        @if($act['type'] === 'link')
            <a href="{{ $act['url'] }}" class="corex-btn-primary corex-btn-xs">{{ $act['label'] }}</a>
        @elseif($act['type'] === 'contact')
            <button type="button" class="corex-btn-primary corex-btn-xs"
                    @click="openContact(@js(['tp' => (string) $act['tp'], 'item' => (string) $act['item'], 'address' => $act['address']]))">{{ $act['label'] }}</button>
        @elseif($act['type'] === 'capture')
            <button type="button" class="corex-btn-primary corex-btn-xs" @click="openCapture({{ (int) $act['task_id'] }})">{{ $act['label'] }}</button>
        @endif
    @endif
</div>

@else
{{-- The agent's tasks, step by step, with progress against each target. --}}
@if(! $progress['started'])
    <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        No recipe is set up for this agency yet.
        @if($recipeUrl)<a href="{{ $recipeUrl }}" class="font-semibold underline">Start the recipe</a>@endif
    </div>
@elseif(! $progress['level'] || count($progress['tasks']) === 0)
    <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        {{ $progress['level'] ? $progress['level']['name'] . ' has no tasks yet.' : 'The recipe has no active level.' }}
        @if($recipeUrl)<a href="{{ $recipeUrl }}" class="font-semibold underline">Add tasks to the level</a>@endif
    </div>
@elseif(collect($progress['tasks'])->every(fn ($t) => $t['target'] <= 0 && $t['actual'] <= 0))
    {{-- Tasks with no target and nothing done are not progress; they are reached with the Capture button. --}}
    <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        No targets are set for {{ $progress['level']['name'] }}.
        @if($recipeUrl)<a href="{{ $recipeUrl }}" class="font-semibold underline">Set targets in the recipe</a>@endif
    </div>
@else
    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <tbody>
            @php $lastStep = null; @endphp
            {{-- A task with a target, or with something done this period, is in play; the rest are reached with the Capture button. --}}
            @foreach(collect($progress['tasks'])->filter(fn ($t) => $t['target'] > 0 || $t['actual'] > 0) as $t)
                @if($lastStep !== $t['step_id'])
                    @php $lastStep = $t['step_id']; @endphp
                    <tr style="border-top: 1px solid var(--border); background: var(--bg, #f4f6fb);">
                        <td colspan="5" class="px-3 py-1.5 font-bold uppercase tracking-widest" style="color: var(--text-muted);">{{ $t['step_label'] }}</td>
                    </tr>
                @endif
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2" style="color: var(--text-primary); width: 34%;">{{ $t['label'] }}</td>
                    <td class="px-3 py-2" style="width: 26%;">
                        @if($t['target'] > 0)
                            <div class="ds-progress-track" style="background: var(--ds-track, var(--border));"><div class="ds-progress-bar {{ $stateBar($t['state']) }}" style="width: {{ $t['pct'] }}%;"></div></div>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">
                        {{ $t['actual'] }}@if($t['target'] > 0) of {{ $t['target'] }}@endif
                        <span style="color: var(--text-muted);">a {{ $t['unit_label'] }}</span>
                    </td>
                    <td class="px-3 py-2">
                        @if(($label = \App\Services\RoadToSold\RecipeProgressService::STATE_LABELS[$t['state']] ?? '') !== '')
                            <span class="ds-badge {{ $stateBadge($t['state']) }}">{{ $label }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($viewingOwn)
                            @if($t['is_custom'] && $captureTasks->contains('id', $t['task_id']))
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="openCapture({{ (int) $t['task_id'] }})">Capture</button>
                            @elseif(! $t['is_custom'] && ($url = \App\Services\RoadToSold\RecipeCatalogue::screenUrl($t['event_key'])))
                                <a href="{{ $url }}" class="corex-btn-outline corex-btn-xs">Go</a>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endif
