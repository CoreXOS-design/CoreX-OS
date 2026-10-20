{{-- AT-478 — the gradual-introduction choice + pace. ONE block shared by the onboarding wizard (capabilities
     step) and Settings → Feature rollout, so the two can never drift (non-negotiable #10a).
     Saved by FeatureRolloutController@saveChoice. §6.1: the `feature_rollout_present` marker is rendered only
     when there is something to choose; without it the saver changes nothing. Every toggle has a hidden "0".
     Expects: $plan, $canStart, $isBoth, $intervals, $defaults, $preview (list of feature keys), $labels. --}}
@php
    $running = $plan && in_array($plan->status, ['active', 'paused'], true);
    $interval = $plan->interval_days ?? $defaults['interval_days'];
    $weekdays = $plan ? $plan->weekdays_only : $defaults['weekdays_only'];
    $sideBySide = $plan ? $plan->side_by_side : false;
    $choices = $intervals;
    if (! array_key_exists($interval, $choices)) {
        $choices[$interval] = 'Every ' . $interval . ' days';
        ksort($choices);
    }
    $initialMode = old('feature_rollout_mode', $running ? 'gradual' : 'all');
    $toggle = 'w-4 h-4 mt-0.5 rounded';
    $field = 'rounded-md px-3 py-2 text-sm';
    $fieldStyle = 'background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);';
@endphp

<div class="rounded-md p-4 space-y-3" style="border:1px solid var(--border,#e5e7eb); background:var(--surface-2,#f8fafc);"
     x-data="{ mode: @js($initialMode) }" data-test="feature-rollout-choice">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary,#0f172a);">How should CoreX switch on for your team?</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted,#64748b);">
            A whole operating system on day one can be a lot. CoreX can start your team on <strong>Properties and Contacts</strong>
            only, then switch on a few more features every couple of working days, each with a short introduction and a guided tour.
        </p>
        <p class="text-[11px] mt-1" style="color:var(--text-muted,#94a3b8);">
            <span class="font-semibold">What this changes:</span> with gradual introduction, the features below that you leave on are
            hidden from everyone now and come back in groups, on working days, at the start of the day. The day before each group,
            you choose which roles get it. Nothing is deleted, and you can pause, switch the next group on early, or switch everything on at any time.
        </p>
    </div>

    @if ($plan && ! $running)
        <p class="text-sm" style="color:var(--text-primary,#0f172a);">Your gradual introduction is finished: everything it was holding back is switched on.</p>
    @elseif (! $plan && ! $canStart)
        <p class="text-sm" style="color:var(--text-secondary,#475569);">Gradual introduction is offered while an agency is first setting up CoreX. Your setup is already complete, so every feature you switch on is available straight away.</p>
    @else
        <input type="hidden" name="feature_rollout_present" value="1">

        <div class="flex flex-col md:flex-row gap-3">
            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary,#0f172a);">
                <input type="radio" name="feature_rollout_mode" value="all" x-model="mode" class="mt-0.5">
                <span>{!! $running ? '<strong>Switch everything on now</strong>, ending the gradual introduction' : '<strong>Everything now</strong>: every feature you leave on is available straight away' !!}</span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary,#0f172a);">
                <input type="radio" name="feature_rollout_mode" value="gradual" x-model="mode" class="mt-0.5">
                <span>{!! $running ? '<strong>Keep introducing gradually</strong>' : '<strong>Introduce gradually</strong>: start with Properties and Contacts' !!}</span>
            </label>
        </div>

        <div x-show="mode === 'gradual'" x-cloak class="space-y-3 pt-1">
            <div class="flex flex-col md:flex-row gap-4">
                <label class="block">
                    <span class="text-xs font-semibold" style="color:var(--text-secondary,#475569);">New features arrive</span>
                    <select name="feature_rollout_interval" class="block mt-1 {{ $field }}" style="{{ $fieldStyle }}">
                        @foreach ($choices as $days => $label)
                            <option value="{{ $days }}" @selected((int) old('feature_rollout_interval', $interval) === (int) $days)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-start gap-2 text-sm md:pt-6" style="color:var(--text-primary,#0f172a);">
                    <input type="hidden" name="feature_rollout_weekdays" value="0">
                    <input type="checkbox" name="feature_rollout_weekdays" value="1" @checked($weekdays) class="{{ $toggle }}" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span><strong>Weekdays only</strong>: nothing switches on at the weekend, and weekends do not count as days.</span>
                </label>
            </div>
            @if ($isBoth)
                <label class="flex items-start gap-2 text-sm" style="color:var(--text-primary,#0f172a);">
                    <input type="hidden" name="feature_rollout_side_by_side" value="0">
                    <input type="checkbox" name="feature_rollout_side_by_side" value="1" @checked($sideBySide) class="{{ $toggle }}" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span><strong>Introduce sales and rentals side by side</strong>: each time, the next sales group and the next rentals group switch on together, instead of one after the other.</span>
                </label>
            @endif

            @if (! $plan)
                @if (count($preview))
                    <label class="flex items-start gap-2 text-sm rounded-md p-3" style="color:var(--text-primary,#0f172a); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);">
                        <input type="checkbox" name="feature_rollout_confirm" value="1" class="{{ $toggle }}">
                        <span>
                            I understand <strong>{{ count($preview) }} features</strong> will be switched off for everyone now and switched back on a few at a time:
                            <span class="text-xs" style="color:var(--text-secondary,#475569);">{{ implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, $preview)) }}</span>
                        </span>
                    </label>
                @else
                    <p class="text-xs" style="color:var(--text-muted,#64748b);">CoreX has no introduction plan set up yet, so everything you leave on stays available straight away.</p>
                @endif
                @error('feature_rollout_confirm')<p class="text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
            @endif
        </div>
    @endif
</div>
