{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Admin → Agency Setup Progress → one agency (AT-478 Part 4, spec .ai/specs/agency-feature-rollout.md §5.5).
     CoreX-team only: setup progress + the agency's gradual introduction, and changes to its plan.
     AgencyRolloutAdminController. Same badges and wording as the agency's own Settings → Feature rollout. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $stateLabel = [
        'held'           => ['Coming',                  'ds-badge-info'],
        'released'       => ['On',                      'ds-badge-success'],
        'released_early' => ['On (switched on early)',  'ds-badge-success'],
        'awaiting_admin' => ['Waiting for the agency',  'ds-badge-warning'],
        'dropped'        => ['Skipped',                 'ds-badge-muted'],
    ];
    $statusLabel = ['active' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Stopped'];
    $typeLabel = ['sales' => 'Sales only', 'rentals' => 'Rentals only', 'both' => 'Sales and rentals'];
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $done = (array) ($setup?->completed_steps ?? []);
    $archivedView = $filters['status'] === 'archived';
    $base = ['agency' => $agency->id];
    $interval = $plan->interval_days ?? $defaults['interval_days'];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.agency-setup-progress') }}" class="text-xs" style="color: var(--text-muted);">← Agency Setup Progress</a>
                <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">{{ $agency->name }}</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    {{ $typeLabel[\App\Services\Features\AgencyTypeFeatureSeeder::normalise($agency->agency_type)] ?? '' }}.
                    Changes made here apply to this agency only and never change the default list in Dev Settings.
                </p>
            </div>
            @if ($plan)
                <div class="text-sm whitespace-nowrap" style="color: var(--text-secondary);">
                    <span class="ds-badge {{ $plan->status === 'paused' ? 'ds-badge-warning' : ($open ? 'ds-badge-info' : 'ds-badge-success') }}">{{ $statusLabel[$plan->status] ?? $plan->status }}</span>
                    <strong class="ml-2" style="color: var(--text-primary);">{{ $totals['released'] }}</strong> of {{ $totals['features'] }} features on
                </div>
            @endif
        </div>
    </div>

    @include('admin.partials.platform-flash')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {{-- Setup guide progress --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="ds-section-header">Setup guide</div>
            @if ($setup)
                <div class="flex items-center gap-2 mt-2">
                    <span class="ds-badge ds-badge-muted">{{ $setup->statusLabel() }}</span>
                    <span class="text-xs tabular-nums" style="color: var(--text-muted);">{{ $setup->progressPercent($agency) }}% done</span>
                </div>
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($steps as $step)
                        <li class="flex items-center gap-2" style="color: {{ in_array($step, $done, true) ? 'var(--text-primary)' : 'var(--text-muted)' }};">
                            <span aria-hidden="true">{{ in_array($step, $done, true) ? '✓' : '○' }}</span>
                            {{ config("agency-onboarding-copy.$step.title") ?: \Illuminate\Support\Str::headline($step) }}
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm mt-2" style="color: var(--text-muted);">This agency has no setup guide.</p>
            @endif
        </div>

        {{-- Gradual introduction: start, or status + controls + pace --}}
        <div class="lg:col-span-2 rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="ds-section-header">Gradual introduction</div>

            @if (! $plan)
                <p class="text-sm" style="color: var(--text-secondary);">This agency has every feature it uses switched on. You can start a gradual introduction for it from here.</p>
                @if ($preview === [])
                    <p class="text-sm" style="color: var(--text-muted);">There is nothing to hold back: no feature this agency has on is in a group in Dev Settings → Feature rollout.</p>
                @else
                    <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.start', $base) }}" class="space-y-3">
                        @csrf
                        <div class="flex flex-wrap items-end gap-4">
                            <label class="block text-xs" style="color: var(--text-secondary);">How often
                                <select name="interval_days" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                                    @for ($d = 1; $d <= $intervalMax; $d++)
                                        <option value="{{ $d }}" @selected((int) $interval === $d)>{{ $intervals[$d] ?? "Every $d days" }}</option>
                                    @endfor
                                </select>
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                                <input type="checkbox" name="weekdays_only" value="1" @checked($defaults['weekdays_only'])> Weekdays only
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                                <input type="checkbox" name="skip_holidays" value="1" @checked($defaults['skip_holidays'])> Skip public holidays
                            </label>
                            @if ($isBoth)
                                <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                                    <input type="checkbox" name="side_by_side" value="1"> Introduce sales and rentals side by side
                                </label>
                            @endif
                        </div>
                        <label class="flex items-start gap-2 text-sm rounded-md px-3 py-2" style="color: var(--text-primary); background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                            <input type="checkbox" name="confirm" value="1" class="mt-1">
                            <span>I understand these {{ count($preview) }} features switch off for everyone at {{ $agency->name }} now and come back in groups:
                                {{ collect($preview)->map(fn ($k) => $labels[$k] ?? $k)->implode(', ') }}.</span>
                        </label>
                        <div class="flex justify-end"><button type="submit" class="corex-btn-primary">Start gradual introduction</button></div>
                    </form>
                @endif
            @else
                <div class="text-sm" style="color: var(--text-primary);">
                    Started {{ $plan->started_on?->format('j M Y') }}.
                    @if ($plan->status === 'paused')
                        Paused since {{ $plan->paused_at?->format('j M Y') }}.
                    @elseif ($open && $next)
                        Next: <strong>{{ $next->title }}</strong> on <strong>{{ $next->scheduled_for->format('l j M') }}</strong> at {{ substr((string) $plan->release_time, 0, 5) }}.
                    @elseif (! $open)
                        Finished {{ $plan->completed_at?->format('j M Y') }}.
                    @endif
                </div>

                @if ($open)
                    <div class="flex flex-wrap gap-2">
                        @if ($plan->status === 'paused')
                            <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.control', $base + ['action' => 'resume']) }}">@csrf<button type="submit" class="corex-btn-primary">Resume</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.control', $base + ['action' => 'pause']) }}">@csrf<button type="submit" class="corex-btn-outline">Pause</button></form>
                        @endif
                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.control', $base + ['action' => 'release-next']) }}" onsubmit="return confirm('Switch this agency\'s next group on now? Their team sees it straight away.');">@csrf<button type="submit" class="corex-btn-outline">Switch the next group on now</button></form>
                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.control', $base + ['action' => 'finish']) }}" onsubmit="return confirm('Switch every remaining feature on for this agency now, including the ones that reach people outside it? This ends the gradual introduction.');">@csrf<button type="submit" class="corex-btn-outline">Switch everything on</button></form>
                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.control', $base + ['action' => 'reset']) }}" onsubmit="return confirm('Rebuild this agency\'s groups still to come from today\'s default list? Your changes to those groups are replaced (they are archived, not deleted). Groups already switched on stay as they are.');">@csrf<button type="submit" class="corex-btn-outline">Reset to the default list</button></form>
                    </div>

                    <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.pace', $base) }}" class="flex flex-wrap items-end gap-4 pt-3" style="border-top: 1px solid var(--border);">
                        @csrf
                        <label class="block text-xs" style="color: var(--text-secondary);">How often
                            <select name="interval_days" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                                @for ($d = 1; $d <= $intervalMax; $d++)
                                    <option value="{{ $d }}" @selected((int) $plan->interval_days === $d)>{{ $intervals[$d] ?? "Every $d days" }}</option>
                                @endfor
                            </select>
                        </label>
                        <label class="block text-xs" style="color: var(--text-secondary);">Release time
                            <input type="time" name="release_time" value="{{ substr((string) $plan->release_time, 0, 5) }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                        </label>
                        <input type="hidden" name="weekdays_only" value="0">
                        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                            <input type="checkbox" name="weekdays_only" value="1" @checked($plan->weekdays_only)> Weekdays only
                        </label>
                        <input type="hidden" name="skip_holidays" value="0">
                        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                            <input type="checkbox" name="skip_holidays" value="1" @checked($plan->skip_holidays)> Skip public holidays
                        </label>
                        @if ($isBoth)
                            <input type="hidden" name="side_by_side" value="0">
                            <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                                <input type="checkbox" name="side_by_side" value="1" @checked($plan->side_by_side)> Sales and rentals side by side
                            </label>
                        @endif
                        <button type="submit" class="corex-btn-primary">Save pace</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    {{-- Groups --}}
    @if ($plan)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="ds-section-header">{{ $agency->name }}'s groups of features <span class="ds-badge ds-badge-muted ml-1">{{ $waves->total() }}</span></div>
                <div class="ds-section-sub">In the order they switch on{{ $plan->side_by_side ? ' — sales and rentals groups switch on together' : '' }}. Groups already switched on are history and can't be changed.</div>
            </div>
            <form method="GET" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Search group or feature</span>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
                <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
                    <select name="status" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                        <option value="all" @selected($filters['status'] === 'all')>All groups</option>
                        <option value="upcoming" @selected($filters['status'] === 'upcoming')>Still to come</option>
                        <option value="released" @selected($filters['status'] === 'released')>Switched on</option>
                        <option value="archived" @selected($archivedView)>Archived</option>
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
                <a href="{{ route('admin.agency-setup-progress.show', $base) }}" class="corex-btn-outline">Clear</a>
            </form>

            @forelse ($waves as $w)
                @php
                    $editable = $open && ! $archivedView && ! $w->released_at;
                    $diff = $diffs[$w->id] ?? null;
                    $others = $openWaves->where('id', '!=', $w->id);
                @endphp
                <div class="px-5 py-4" style="border-top: 1px solid var(--border);" x-data="{ edit: false, diff: false }" data-wave="{{ $w->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="ds-badge ds-badge-pill {{ $w->released_at ? 'ds-badge-success' : 'ds-badge-info' }} tabular-nums">
                                {{ $archivedView ? 'Archived' : ($w->released_at ? 'On since ' . $w->released_at->format('j M') : ($w->scheduled_for ? $w->scheduled_for->format('D j M') : 'Not dated')) }}
                            </span>
                            @if ($plan->side_by_side)<span class="ds-badge ds-badge-muted">{{ $w->lane === 2 ? 'Rentals' : 'Sales & general' }}</span>@endif
                            <span class="font-semibold text-sm" style="color: var(--text-primary);">{{ $w->title }}</span>
                            @if (! $w->source_wave_id)
                                <span class="ds-badge ds-badge-warning">Added for this agency</span>
                            @elseif ($w->customised_at)
                                <span class="ds-badge ds-badge-warning" title="Changed {{ $w->customised_at->format('j M Y H:i') }}">Changed for this agency</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @if ($diff !== null && ! $w->released_at)
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="diff = !diff">Show differences from the default</button>
                            @endif
                            @if ($editable)
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit">Edit</button>
                                <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.move', $base + ['wave' => $w->id]) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Switch on earlier">↑ Earlier</button></form>
                                <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.move', $base + ['wave' => $w->id]) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Switch on later">↓ Later</button></form>
                                @if ($w->items->isEmpty())
                                    <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.archive', $base + ['wave' => $w->id]) }}">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button></form>
                                @endif
                            @endif
                            @if ($archivedView && $open)
                                <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.restore', $base + ['wave' => $w->id]) }}">@csrf<button type="submit" class="corex-btn-primary corex-btn-xs">Restore</button></form>
                            @endif
                        </div>
                    </div>
                    @if ($w->intro_text)<p class="text-xs mt-1" style="color: var(--text-muted);">{{ $w->intro_text }}</p>@endif
                    @if ($w->access_reviewed_at)
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Who gets access was checked by {{ $reviewers[$w->access_reviewed_by] ?? 'an admin' }} on {{ $w->access_reviewed_at->format('j M Y') }}.</p>
                    @elseif ($w->released_at)
                        <p class="text-xs mt-1" style="color: var(--text-muted);">The agency's admin has not checked who has access yet.</p>
                    @endif

                    @if ($editable)
                        <form x-show="edit" x-cloak method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.update', $base + ['wave' => $w->id]) }}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                            @csrf @method('PUT')
                            <input type="text" name="title" value="{{ $w->title }}" maxlength="120" required class="{{ $input }}" style="{{ $inputStyle }}" aria-label="Group name">
                            <input type="text" name="intro_text" value="{{ $w->intro_text }}" maxlength="1000" placeholder="What the team is told when it switches on" class="md:col-span-2 {{ $input }}" style="{{ $inputStyle }}" aria-label="Message">
                            <div class="md:col-span-3 flex justify-end"><button type="submit" class="corex-btn-primary corex-btn-xs">Save group</button></div>
                        </form>
                    @endif

                    @if ($diff !== null)
                        <div x-show="diff" x-cloak class="mt-3 rounded-md px-4 py-3 text-xs space-y-1" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">
                            @if ($diff['added'] === [] && $diff['missing'] === [])
                                <div>Same features as the default group.</div>
                            @endif
                            @if ($diff['added'] !== [])
                                <div><strong>Only in this agency's group:</strong> {{ collect($diff['added'])->map(fn ($k) => $labels[$k] ?? $k)->implode(', ') }}</div>
                            @endif
                            @if ($diff['missing'] !== [])
                                <div><strong>In the default but not here:</strong> {{ collect($diff['missing'])->map(fn ($k) => $labels[$k] ?? $k)->implode(', ') }} <span style="color: var(--text-muted);">(moved, skipped, or off for this agency when it started)</span></div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-2 space-y-1.5">
                        @forelse ($w->items as $item)
                            @php [$sl, $sc] = $stateLabel[$item->state] ?? [$item->state, 'ds-badge-muted']; @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <div class="flex items-center gap-2" style="color: var(--text-primary);">
                                    <span>{{ $labels[$item->feature_key] ?? $item->feature_key }}</span>
                                    <span class="ds-badge {{ $sc }}">{{ $sl }}</span>
                                    @if (empty($tours[$item->feature_key]))<span class="ds-badge ds-badge-muted">No tour yet</span>@endif
                                </div>
                                @if ($editable && $item->state === 'held')
                                    <div class="flex flex-wrap gap-1.5 items-center">
                                        @if ($others->isNotEmpty())
                                            <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.items.action', $base + ['item' => $item->id, 'action' => 'move']) }}" class="flex gap-1.5 items-center">
                                                @csrf
                                                <select name="wave_id" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" aria-label="Move to group">
                                                    @foreach ($others as $o)
                                                        <option value="{{ $o->id }}">{{ $o->title }}{{ $o->scheduled_for ? ' (' . $o->scheduled_for->format('j M') . ')' : '' }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="corex-btn-outline corex-btn-xs">Move</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.items.action', $base + ['item' => $item->id, 'action' => 'on']) }}" onsubmit="return confirm('Switch this on for the agency now, ahead of its group?');">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Switch on now</button></form>
                                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.items.action', $base + ['item' => $item->id, 'action' => 'skip']) }}" onsubmit="return confirm('Skip this feature? It stays off for the agency; they can still switch it on from Features.');">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Skip</button></form>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs" style="color: var(--text-muted);">No features in this group yet.</p>
                        @endforelse
                    </div>

                    @if ($editable && $addable !== [])
                        <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.features.store', $base + ['wave' => $w->id]) }}" class="mt-3 flex flex-wrap items-center gap-2"
                              onsubmit="return confirm('Add this feature to the group? It switches off for the agency now and comes back with this group.');">
                            @csrf
                            <select name="feature_key" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" aria-label="Feature to add">
                                @foreach ($addable as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="corex-btn-outline corex-btn-xs">Add feature to this group</button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">
                    @if (request()->hasAny(['q', 'from', 'to']) || $filters['status'] !== 'all')
                        No groups match these filters.
                    @else
                        This agency has no groups. Add one below.
                    @endif
                </div>
            @endforelse
            @if ($waves->hasPages())
                <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $waves->links() }}</div>
            @endif
        </div>

        @if ($open)
            <form method="POST" action="{{ route('admin.agency-setup-progress.rollout.waves.store', $base) }}" class="rounded-md p-5 grid grid-cols-1 md:grid-cols-4 gap-3 items-end" style="background: var(--surface); border: 1px solid var(--border);">
                @csrf
                <div class="md:col-span-4 ds-section-header">Add a group for this agency</div>
                <label class="block text-xs" style="color: var(--text-secondary);">Group name
                    <input type="text" name="title" maxlength="120" required class="block mt-1 w-full {{ $input }}" style="{{ $inputStyle }}"></label>
                <label class="block text-xs md:col-span-2" style="color: var(--text-secondary);">Message when it switches on
                    <input type="text" name="intro_text" maxlength="1000" class="block mt-1 w-full {{ $input }}" style="{{ $inputStyle }}"></label>
                @if ($plan->side_by_side)
                    <label class="block text-xs" style="color: var(--text-secondary);">Switches on with
                        <select name="lane" class="block mt-1 w-full {{ $input }}" style="{{ $inputStyle }}">
                            <option value="1">Sales &amp; general groups</option>
                            <option value="2">Rentals groups</option>
                        </select></label>
                @endif
                <div class="md:col-span-4 flex justify-end"><button type="submit" class="corex-btn-primary">Add group at the end</button></div>
            </form>
        @endif
    @endif
</div>
@endsection
