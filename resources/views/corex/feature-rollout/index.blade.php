{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Settings → Feature rollout (AT-478, spec .ai/specs/agency-feature-rollout.md §5.2): the agency's own gradual
     introduction — progress, controls and pace. Own agency only (FeatureRolloutController). --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $running = $plan && in_array($plan->status, ['active', 'paused'], true);
    $stateLabel = [
        'held'           => ['Coming',               'ds-badge-info'],
        'released'       => ['On',                   'ds-badge-success'],
        'released_early' => ['On (switched on early)', 'ds-badge-success'],
        'awaiting_admin' => ['Ready when you are',   'ds-badge-warning'],
        'dropped'        => ['Skipped',              'ds-badge-muted'],
        'awaiting_setup' => ['Set up first',         'ds-badge-warning'],
    ];
    $statusLabel = ['active' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Stopped'];
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Feature rollout</h1>
                <p class="text-xs mt-1 max-w-2xl" style="color: var(--text-muted);">
                    Introduce CoreX to your team a few features at a time. Features switch on in groups on working days, at the start of the day.
                    Nothing is deleted, and you stay in control: pause, switch the next group on early, skip a feature, or switch everything on.
                </p>
            </div>
            @if ($plan)
                <div class="text-sm whitespace-nowrap" style="color: var(--text-secondary);">
                    <span class="ds-badge {{ $plan->status === 'paused' ? 'ds-badge-warning' : ($running ? 'ds-badge-info' : 'ds-badge-success') }}">{{ $statusLabel[$plan->status] ?? $plan->status }}</span>
                    <strong class="ml-2" style="color: var(--text-primary);">{{ $totals['released'] }}</strong> of {{ $totals['features'] }} features on
                </div>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    @if ($running)
        <div class="rounded-md px-5 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm" style="color: var(--text-primary);">
                @if ($plan->status === 'paused')
                    Paused since {{ $plan->paused_at?->format('j M Y') }}. Nothing new switches on until you resume.
                @elseif ($next)
                    Next: <strong>{{ $next->title }}</strong> on <strong>{{ $next->scheduled_for->format('l j M') }}</strong> at {{ substr((string) $plan->release_time, 0, 5) }}.
                @else
                    Every group has been switched on.
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($plan->status === 'paused')
                    <form method="POST" action="{{ route('corex.feature-rollout.resume') }}">@csrf<button type="submit" class="corex-btn-primary">Resume</button></form>
                @else
                    <form method="POST" action="{{ route('corex.feature-rollout.pause') }}">@csrf<button type="submit" class="corex-btn-outline">Pause</button></form>
                @endif
                <form method="POST" action="{{ route('corex.feature-rollout.release-next') }}" onsubmit="return confirm('Switch the next group of features on now? Everyone with access will see them straight away.');">@csrf<button type="submit" class="corex-btn-outline">Switch the next group on now</button></form>
                <form method="POST" action="{{ route('corex.feature-rollout.finish') }}" onsubmit="return confirm('Switch every remaining feature on now, including the ones that reach people outside your agency? This ends the gradual introduction.');">@csrf<button type="submit" class="corex-btn-outline">Switch everything on</button></form>
            </div>
        </div>
    @endif

        @if (! empty($lowUse))
            <div class="rounded-md px-4 py-3 text-sm" data-low-use style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
                Only {{ $lowUse['opened'] }} of {{ $lowUse['people'] }} people with access have opened what switched on in "{{ $lowUse['group'] }}". You could pause before the next group so the team can catch up — this is only a suggestion; nothing is paused for you.
            </div>
        @endif

    {{-- Choice + pace (same block as the onboarding wizard) --}}
    <form method="POST" action="{{ route('corex.feature-rollout.settings') }}" class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @include('corex.feature-rollout._choice')
        @if ($running || $canStart)
            <div class="flex justify-end"><button type="submit" class="corex-btn-primary">Save</button></div>
        @endif
    </form>

    {{-- Waves --}}
    @if ($plan)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="ds-section-header">Your groups of features <span class="ds-badge ds-badge-muted ml-1">{{ $waves->total() }}</span></div>
                <div class="ds-section-sub">In the order they switch on{{ $plan->side_by_side ? ' — sales and rentals groups switch on together' : '' }}.</div>
            </div>
            <form method="GET" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Search group or feature</span>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
                    <select name="status" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                        <option value="all" @selected($filters['status'] === 'all')>All groups</option>
                        <option value="upcoming" @selected($filters['status'] === 'upcoming')>Still to come</option>
                        <option value="released" @selected($filters['status'] === 'released')>Switched on</option>
                    </select></label>
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Date from</span>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Sort</span>
                    <select name="sort" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                        <option value="date" @selected($filters['sort'] === 'date')>By date</option>
                        <option value="name" @selected($filters['sort'] === 'name')>By name</option>
                    </select></label>
                <button type="submit" class="corex-btn-outline">Apply</button>
                <a href="{{ route('corex.feature-rollout.index') }}" class="corex-btn-outline">Clear</a>
            </form>

            @forelse ($waves as $w)
                <div class="px-5 py-4" style="border-top: 1px solid var(--border);">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="ds-badge ds-badge-pill {{ $w->released_at ? 'ds-badge-success' : 'ds-badge-info' }} tabular-nums">
                            {{ $w->released_at ? 'On since ' . $w->released_at->format('j M') : ($w->scheduled_for ? $w->scheduled_for->format('D j M') : 'Not dated') }}
                        </span>
                        @if ($plan->side_by_side)<span class="ds-badge ds-badge-muted">{{ $w->lane === 2 ? 'Rentals' : 'Sales & general' }}</span>@endif
                        <span class="font-semibold text-sm" style="color: var(--text-primary);">{{ $w->title }}</span>
                    </div>
                    @if ($w->intro_text)<p class="text-xs mt-1" style="color: var(--text-muted);">{{ $w->intro_text }}</p>@endif
                    <div class="mt-2 space-y-1.5">
                        @foreach ($w->items as $item)
                            @php [$sl, $sc] = $stateLabel[$item->state] ?? [$item->state, 'ds-badge-muted']; @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <div class="flex items-center gap-2" style="color: var(--text-primary);">
                                    <span>{{ $labels[$item->feature_key] ?? $item->feature_key }}</span>
                                    <span class="ds-badge {{ $sc }}">{{ $sl }}</span>
                                    @if (! empty($tours[$item->feature_key]))<span class="ds-badge ds-badge-muted" title="{{ collect($tours[$item->feature_key])->pluck('title')->implode(', ') }}">Guided tour</span>@endif
                                </div>
                                @if (isset($usage[$item->feature_key]))
                                    @include('corex.feature-rollout._usage', ['s' => $usage[$item->feature_key]])
                                @endif
                                @if ($item->state === 'awaiting_setup' && ! empty($readiness[$item->feature_key]))
                                    <div class="w-full text-xs" style="color: var(--text-secondary);" data-readiness="{{ $item->feature_key }}">
                                        This switches on by itself once you add
                                        @foreach ($readiness[$item->feature_key] as $i => $m){{ $i > 0 ? ($loop->last ? ' and ' : ', ') : '' }}<a href="{{ $m['url'] }}" style="color: var(--brand-icon);">{{ $m['label'] }}</a> ({{ $m['step_title'] }})@endforeach.
                                    </div>
                                @endif
                                <div class="flex gap-1.5">
                                    @if ($item->state === 'awaiting_admin')
                                        <form method="POST" action="{{ route('corex.feature-rollout.items.on', $item->id) }}" onsubmit="return confirm('Switch this on now? It reaches people outside your agency.');">@csrf<button type="submit" class="corex-btn-primary corex-btn-xs">Switch on</button></form>
                                    @endif
                                    @if ($item->state === 'awaiting_setup')
                                        <form method="POST" action="{{ route('corex.feature-rollout.items.on', $item->id) }}" onsubmit="return confirm('Switch this on before its setup is done? Your team may find parts of it empty.');">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Switch on anyway</button></form>
                                    @endif
                                    @if ($running && in_array($item->state, ['held', 'awaiting_admin', 'awaiting_setup'], true))
                                        <form method="POST" action="{{ route('corex.feature-rollout.items.skip', $item->id) }}" onsubmit="return confirm('Skip this feature? It stays off; you can switch it on from Features any time.');">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Skip</button></form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">
                    {{ request()->hasAny(['q', 'from', 'to']) || $filters['status'] !== 'all' ? 'No groups match these filters.' : 'Nothing is being held back: every feature is already on.' }}
                </div>
            @endforelse
            @if ($waves->hasPages())
                <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $waves->links() }}</div>
            @endif
        </div>

        @include('corex.feature-rollout._history')
    @endif
</div>
@endsection
