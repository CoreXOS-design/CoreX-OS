{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Feature rollout (AT-478).
     Same shell as Agency timeline defaults. Spec: .ai/specs/agency-feature-rollout.md §5.1. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-5 py-2.5 text-xs font-semibold uppercase tracking-wider';
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $archivedView = ($filters['status'] ?? 'active') === 'archived';
    $sortLink = function (string $col) use ($filters) {
        $dir = ($filters['sort'] === $col && $filters['dir'] === 'asc') ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $dir, 'page' => null]);
    };
    $arrow = fn (string $col) => $filters['sort'] === $col ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $isCustomInterval = ! array_key_exists($defaults['interval_days'], $intervals);
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Feature rollout</h1>
                <p class="text-sm text-white/60">How CoreX introduces itself to a <strong>new</strong> agency that chooses to start gradually: it begins with Properties and Contacts, then switches on one wave of features at a time. Changing this never changes an agency that has already started — each keeps its own copy.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.dev-settings.index') }}" class="corex-btn-outline">← Dev Settings</a>
            </div>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    {{-- Pace --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">How often</div>
            <div class="ds-section-sub">The pace new plans start with. An agency's admin can change their own pace later.</div>
        </div>
        <form method="POST" action="{{ route('admin.feature-rollout-defaults.pace') }}" class="px-5 py-5 space-y-4"
              x-data="{ custom: {{ $isCustomInterval ? 'true' : 'false' }} }">
            @csrf @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold" style="color:var(--text-secondary);">New features arrive</span>
                    <select class="w-full mt-1 {{ $input }}" style="{{ $inputStyle }}"
                            @change="custom = $event.target.value === 'custom'; if (!custom) $refs.days.value = $event.target.value">
                        @foreach($intervals as $days => $label)
                            <option value="{{ $days }}" @selected(! $isCustomInterval && $defaults['interval_days'] === $days)>{{ $label }}</option>
                        @endforeach
                        <option value="custom" @selected($isCustomInterval)>Custom…</option>
                    </select>
                </label>
                <label class="block" x-show="custom" x-cloak>
                    <span class="text-xs font-semibold" style="color:var(--text-secondary);">Every how many days (1–{{ $intervalMax }})</span>
                    <input x-ref="days" type="number" name="interval_days" min="1" max="{{ $intervalMax }}" required
                           value="{{ old('interval_days', $defaults['interval_days']) }}" class="w-full mt-1 {{ $input }} font-mono" style="{{ $inputStyle }}">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold" style="color:var(--text-secondary);">Release time</span>
                    <input type="time" name="release_time" required value="{{ old('release_time', $defaults['release_time']) }}" class="w-full mt-1 {{ $input }} font-mono" style="{{ $inputStyle }}">
                    <span class="text-[11px] mt-1 block" style="color:var(--text-muted);">Features switch on at this time on their day, so people see them at the start of work.</span>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold" style="color:var(--text-secondary);">Public holiday calendar (country code)</span>
                    <input type="text" name="holiday_country" required maxlength="2" value="{{ old('holiday_country', $defaults['holiday_country']) }}" class="w-full mt-1 {{ $input }} font-mono uppercase" style="{{ $inputStyle }}">
                </label>
            </div>
            <div class="flex flex-col md:flex-row gap-4">
                <label class="flex items-start gap-2 text-sm" style="color:var(--text-primary);">
                    <input type="checkbox" name="weekdays_only" value="1" @checked($defaults['weekdays_only']) class="w-4 h-4 mt-0.5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span><strong>Weekdays only</strong> — nothing switches on on a Saturday or Sunday, and weekends do not count towards the days between waves.</span>
                </label>
                <label class="flex items-start gap-2 text-sm" style="color:var(--text-primary);">
                    <input type="checkbox" name="skip_holidays" value="1" @checked($defaults['skip_holidays']) class="w-4 h-4 mt-0.5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span><strong>Skip public holidays</strong> — a wave due on a public holiday moves to the next working day.</span>
                </label>
            </div>
            @if($errors->hasAny(['interval_days', 'release_time', 'holiday_country']))
                <p class="text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $errors->first('interval_days') ?: ($errors->first('release_time') ?: $errors->first('holiday_country')) }}</p>
            @endif
            <div class="flex justify-end"><button type="submit" class="corex-btn-primary">Save pace</button></div>
        </form>
    </div>

    {{-- Checks --}}
    @if($checks['unassigned'] || $checks['duplicates'] || $checks['early'])
        <div class="rounded-md px-5 py-4 space-y-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">Things to check in this order of waves</div>
            @if($checks['unassigned'])
                <div><strong>Not in any wave ({{ count($checks['unassigned']) }}):</strong> {{ implode(', ', $checks['unassigned']) }}. New agencies get these from day one.</div>
            @endif
            @foreach($checks['duplicates'] as $key => $titles)
                <div><strong>{{ $labels[$key] ?? $key }}</strong> is in more than one wave ({{ implode(', ', $titles) }}). It arrives with the first one.</div>
            @endforeach
            @foreach($checks['early'] as $e)
                <div><strong>{{ $e['feature'] }}</strong> comes before <strong>{{ $e['parent'] }}</strong>, which it needs. Nobody can use it until {{ $e['parent'] }} arrives.</div>
            @endforeach
        </div>
    @endif

    {{-- Waves --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ adding: {{ $errors->has('feature_keys') || $errors->has('title') ? 'true' : 'false' }} }">
        <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="ds-section-header">{{ $archivedView ? 'Archived waves' : 'Waves, in order' }} <span class="ds-badge ds-badge-muted ml-1">{{ $waves->total() }}</span></div>
                <div class="ds-section-sub">Each wave switches on together. Waves arrive top to bottom.</div>
            </div>
            @unless($archivedView)
                <button type="button" class="corex-btn-primary" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add wave'">+ Add wave</button>
            @endunless
        </div>

        <form method="GET" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Search wave or feature</span>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Track</span>
                <select name="track" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                    <option value="">All</option>
                    @foreach($tracks as $value => $label)<option value="{{ $value }}" @selected(($filters['track'] ?? '') === $value)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Status</span>
                <select name="status" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                    <option value="active" @selected(! $archivedView)>Active</option>
                    <option value="archived" @selected($archivedView)>Archived ({{ $archivedCount }})</option>
                </select>
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Changed from</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="dir" value="{{ $filters['dir'] }}">
            <button type="submit" class="corex-btn-outline">Apply</button>
            <a href="{{ route('admin.feature-rollout-defaults.index') }}" class="corex-btn-outline">Clear</a>
        </form>

        <div x-show="adding" x-cloak class="px-5 py-5" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            @include('admin.dev-settings._feature-rollout-wave-form', ['item' => null])
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}"><a href="{{ $sortLink('position') }}" style="color: var(--text-muted);">Order{{ $arrow('position') }}</a></th>
                    <th class="{{ $th }}"><a href="{{ $sortLink('title') }}" style="color: var(--text-muted);">Wave{{ $arrow('title') }}</a></th>
                    <th class="{{ $th }}"><a href="{{ $sortLink('updated_at') }}" style="color: var(--text-muted);">Changed{{ $arrow('updated_at') }}</a></th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                @forelse($waves as $w)
                    <tbody x-data="{ edit: false }" style="border-top: 1px solid var(--border);">
                        <tr>
                            <td class="px-5 py-4 align-top whitespace-nowrap" style="width: 6rem;">
                                <span class="ds-badge ds-badge-pill ds-badge-info tabular-nums">{{ $waves->firstItem() + $loop->index }}</span>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $w->title }} <span class="ds-badge ds-badge-muted ml-1">{{ $tracks[$w->track] ?? $w->track }}</span></div>
                                @if($w->intro_text)<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $w->intro_text }}</div>@endif
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @foreach((array) $w->feature_keys as $key)
                                        <span class="ds-badge ds-badge-default">{{ $labels[$key] ?? $key }}@if(empty($tourCoverage[$key])) · no tour @endif</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-4 align-top whitespace-nowrap text-xs" style="color: var(--text-muted);">{{ $w->updated_at?->format('j M Y') }}</td>
                            <td class="px-5 py-4 align-top">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    @if($archivedView)
                                        <form method="POST" action="{{ route('admin.feature-rollout-defaults.restore', $w->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                    @else
                                        <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                        <form method="POST" action="{{ route('admin.feature-rollout-defaults.move', $w->id) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move earlier" aria-label="Move earlier">▲</button></form>
                                        <form method="POST" action="{{ route('admin.feature-rollout-defaults.move', $w->id) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move later" aria-label="Move later">▼</button></form>
                                        <form method="POST" action="{{ route('admin.feature-rollout-defaults.destroy', $w->id) }}" onsubmit="return confirm('Archive this wave? New plans will not include it. You can restore it.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @unless($archivedView)
                            <tr x-show="edit" x-cloak>
                                <td colspan="4" class="px-5 py-5" style="background: var(--surface-2);">@include('admin.dev-settings._feature-rollout-wave-form', ['item' => $w])</td>
                            </tr>
                        @endunless
                    </tbody>
                @empty
                    <tbody><tr><td colspan="4" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">
                        @if(request()->hasAny(['q', 'track', 'from', 'to']))
                            No waves match these filters.
                        @elseif($archivedView)
                            No archived waves.
                        @else
                            No waves yet. Until you add one, an agency that chooses gradual rollout gets every feature at once. Use “+ Add wave” to create the first one.
                        @endif
                    </td></tr></tbody>
                @endforelse
            </table>
        </div>
        @if($waves->hasPages())
            <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $waves->links() }}</div>
        @endif
    </div>

    {{-- Tour gap report --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">Features with no guided tour <span class="ds-badge ds-badge-muted ml-1">{{ count($tourGap['missing']) }}</span></div>
            <div class="ds-section-sub">{{ $tourGap['covered'] }} of {{ $tourGap['waved'] }} features in the waves have a tour. These do not — listed in the order agencies receive them, so write the top ones first. Until then, their announcement shows a short explanation instead of a tour.</div>
        </div>
        @if($tourGap['missing'])
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr style="background: var(--surface-2);">
                        <th class="{{ $th }}" style="color: var(--text-muted);">#</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Feature</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Arrives in</th>
                    </tr></thead>
                    <tbody>
                        @foreach($tourGap['missing'] as $i => $row)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-5 py-2.5 tabular-nums" style="color: var(--text-muted);">{{ $i + 1 }}</td>
                                <td class="px-5 py-2.5" style="color: var(--text-primary);">{{ $row['label'] }}</td>
                                <td class="px-5 py-2.5" style="color: var(--text-secondary);">{{ $row['wave'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">
                {{ $tourGap['waved'] ? 'Every feature in the waves has a guided tour.' : 'Add waves above to see which features still need a tour.' }}
            </div>
        @endif
    </div>
</div>
@endsection
