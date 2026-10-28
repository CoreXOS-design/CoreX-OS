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
                <a href="{{ route('admin.feature-rollout-defaults.copy.index') }}" class="corex-btn-outline" data-nav="feature-wording">Feature wording &amp; videos</a>
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
        <form method="POST" id="pace-form" action="{{ route('admin.feature-rollout-defaults.pace') }}" class="px-5 py-5 space-y-4"
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

    {{-- Checks that only the server can know (saved "Admin decides" waves). Everything else is shown live on the board. --}}
    @if($checks['notLimitable'] || $checks['shared'])
        <div class="rounded-md px-5 py-4 space-y-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">Things to check in the saved "Admin decides" waves</div>
            @foreach($checks['notLimitable'] as $n)
                <div data-check-not-limitable><strong>{{ $n['feature'] }}</strong> is in "{{ $n['wave'] }}" (Admin decides) but has no access setting of its own, so it cannot be kept for the admins. New agencies get it from day one. Move it to another wave.</div>
            @endforeach
            @foreach($checks['shared'] as $s)
                <div data-check-shared><strong>{{ $s['feature'] }}</strong> (Admin decides) uses the same access as <strong>{{ $s['other'] }}</strong> in "{{ $s['otherWave'] }}". Giving or taking one changes the other — keep them in the same wave.</div>
            @endforeach
        </div>
    @endif

    @if($board)
        {{-- Wave planner board (spec §20). Edited in the browser, saved with one post. --}}
        <style>
            .wp .hidden { display:none !important; }
            .wp .chip { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; padding:3px 10px; border-radius:999px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); cursor:grab; user-select:none; }
            .wp .chip:hover { border-color:var(--brand-button); }
            .wp .chip.sel { background:var(--brand-button); border-color:var(--brand-button); color:#fff; }
            .wp .chip.warn { background:color-mix(in srgb, var(--ds-amber) 14%, transparent); border-color:var(--ds-amber); }
            .wp .chip.dragging { opacity:.4; }
            .wp .chip small { color:var(--text-muted); font-size:11px; }
            .wp .chip.sel small { color:inherit; }
            .wp-wave { display:grid; grid-template-columns:156px minmax(0,1fr); border:1px solid var(--border); border-radius:6px; background:var(--surface); overflow:hidden; }
            .wp-wave.over { outline:2px solid var(--brand-button); outline-offset:-2px; }
            .wp-wave.before > .wp-when { box-shadow:inset 0 3px 0 var(--brand-button); }
            .wp-wave.after > .wp-when { box-shadow:inset 0 -3px 0 var(--brand-button); }
            .wp-when { background:var(--surface-2); border-right:1px solid var(--border); padding:12px 14px; display:flex; flex-direction:column; gap:2px; }
            .wp-when .n { font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--text-muted); }
            .wp-when .d { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:18px; font-weight:600; color:var(--brand-icon, var(--brand-button)); line-height:1.15; }
            .wp-when .t { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; color:var(--text-muted); }
            .wp-when .skip { font-size:11.5px; color:var(--ds-amber); margin-top:4px; }
            .wp-body { padding:12px 14px 14px; display:flex; flex-direction:column; gap:9px; min-width:0; }
            .wp-head { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
            .wp-grip { cursor:grab; color:var(--text-muted); font-size:18px; line-height:1; padding:2px 4px; border-radius:3px; background:transparent; border:0; }
            .wp-grip:hover { background:var(--surface-2); }
            .wp-title { flex:1 1 160px; min-width:0; font-weight:600; font-size:15px; background:transparent; border:1px solid transparent; color:var(--text-primary); border-radius:4px; padding:3px 7px; }
            .wp-title:hover, .wp-title:focus { border-color:var(--border); background:var(--surface-2); }
            .wp-sel { font-size:12px; padding:3px 6px; border-radius:4px; background:var(--surface); border:1px solid var(--border); color:var(--text-primary); }
            .wp-zone { display:flex; flex-wrap:wrap; gap:6px; min-height:40px; padding:6px; border-radius:5px; border:1px dashed var(--border); align-content:flex-start; }
            .wp-zone.over, .wp-tray-body.over { border-color:var(--brand-button); background:color-mix(in srgb, var(--brand-button) 12%, transparent); }
            .wp-hint { font-size:12.5px; color:var(--text-muted); padding:4px 6px; }
            .wp-issue { font-size:12.5px; background:color-mix(in srgb, var(--ds-amber) 14%, transparent); color:var(--text-primary); border-radius:4px; padding:7px 10px; display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; justify-content:space-between; }
            .wp-day1 { display:grid; grid-template-columns:156px minmax(0,1fr); border:1px dashed var(--border); border-radius:6px; }
            .wp-day1 > div { padding:10px 14px; }
            .wp-work { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:16px; align-items:start; }
            .wp-tray { position:sticky; top:12px; max-height:calc(100vh - 24px); display:flex; flex-direction:column; overflow:hidden; border:1px solid var(--border); border-radius:6px; background:var(--surface); }
            .wp-tray-body { overflow-y:auto; padding:8px 14px 14px; display:flex; flex-direction:column; gap:12px; }
            .wp-cat h3 { margin:0 0 5px; font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--text-muted); }
            .wp-cat .chips { display:flex; flex-wrap:wrap; gap:6px; }
            .wp-bar { position:sticky; top:8px; z-index:5; border-radius:5px; padding:8px 12px; display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center; justify-content:space-between; font-size:13px; }
            .wp-stat b { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:17px; font-weight:600; display:block; }
            .wp-stat span { font-size:11.5px; color:var(--text-muted); }
            .wp-stat.bad b { color:var(--ds-amber); }
            @media (max-width: 900px) { .wp-work { grid-template-columns:1fr; } .wp-tray { position:static; max-height:none; } }
            @media (max-width: 560px) { .wp-wave, .wp-day1 { grid-template-columns:1fr; } .wp-when { border-right:0; border-bottom:1px solid var(--border); } }
        </style>

        <div class="wp space-y-4" id="wave-planner">
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
                    <div>
                        <div class="ds-section-header">Waves, in order</div>
                        <div class="ds-section-sub">Drag a feature into a wave, or drag a wave up or down. Each wave's date is when a new agency that starts on the date below would get it, using the pace above (public holidays follow the saved country, {{ $defaults['holiday_country'] }}). It shows one list in order (sales and rentals together). Nothing here is saved until you press Save plan; the pace has its own Save pace.</div>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="block">
                            <span class="text-[11px] font-semibold" style="color:var(--text-muted);">If an agency starts on</span>
                            <input type="date" id="wp-start" value="{{ $board['today'] }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                        </label>
                        <a href="{{ route('admin.feature-rollout-defaults.index', ['status' => 'archived']) }}" class="corex-btn-outline">Archived waves ({{ $archivedCount }})</a>
                    </div>
                </div>
                <div class="px-5 py-3 flex flex-wrap gap-x-8 gap-y-2" id="wp-summary" aria-live="polite"></div>
            </div>

            @if($errors->has('layout'))
                <div class="rounded-md px-5 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border:1px solid var(--ds-crimson, #c41e3a); color: var(--text-primary);" role="alert">{{ $errors->first('layout') }}</div>
            @endif
            <div class="rounded-md px-5 py-3 text-sm hidden" id="wp-error" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border:1px solid var(--ds-crimson, #c41e3a); color: var(--text-primary);" role="alert"></div>

            <div class="wp-bar hidden" id="wp-dirty" style="background: var(--brand-button); color:#fff;">
                <span>You have changes that are not saved yet.</span>
                <span class="flex gap-2">
                    <button type="button" class="corex-btn-outline corex-btn-xs" id="wp-discard" style="background:transparent; color:#fff; border-color:#fff;">Discard</button>
                    <button type="button" class="corex-btn-xs" id="wp-save" style="background:#fff; color:var(--brand-button); font-weight:600; border-radius:6px; padding:3px 12px;">Save plan</button>
                </span>
            </div>

            <div class="wp-work">
                <div class="space-y-3 min-w-0" id="wp-waves"></div>
                <aside class="wp-tray" aria-label="Features not in any wave">
                    <div class="px-4 py-3 space-y-2" style="border-bottom:1px solid var(--border);">
                        <div class="flex justify-between items-baseline gap-2"><span class="font-semibold text-sm">Not placed yet</span><span id="wp-tray-count" class="font-mono text-sm" style="color:var(--ds-amber);"></span></div>
                        <p class="text-xs" style="color:var(--text-muted);">These switch on from day one for every new agency. Drag one into a wave to hold it back.</p>
                        <input type="text" id="wp-q" placeholder="Search features" aria-label="Search features" class="w-full {{ $input }}" style="{{ $inputStyle }}">
                    </div>
                    <div class="wp-tray-body" id="wp-tray"></div>
                </aside>
            </div>

            <form method="POST" action="{{ route('admin.feature-rollout-defaults.layout') }}" id="wp-form">
                @csrf @method('PUT')
                <input type="hidden" name="layout" id="wp-layout">
                <input type="hidden" name="known" id="wp-known" value="{{ json_encode(array_column($board['waves'], 'id')) }}">
                <input type="hidden" name="confirm_empty" id="wp-confirm-empty" value="0">
            </form>
        </div>
    @else
        {{-- Archived waves: the list screen (search, filters, sort, pagination, restore). --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
                <div>
                    <div class="ds-section-header">Archived waves <span class="ds-badge ds-badge-muted ml-1">{{ $waves->total() }}</span></div>
                    <div class="ds-section-sub">New plans do not include these. Restore one to put it back at the end of the order.</div>
                </div>
                <a href="{{ route('admin.feature-rollout-defaults.index') }}" class="corex-btn-outline">← Back to the wave planner</a>
            </div>

            <form method="GET" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                <input type="hidden" name="status" value="archived">
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
                    <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Changed from</span>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                </label>
                <label class="block">
                    <span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                </label>
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="dir" value="{{ $filters['dir'] }}">
                <button type="submit" class="corex-btn-outline">Apply</button>
                <a href="{{ route('admin.feature-rollout-defaults.index', ['status' => 'archived']) }}" class="corex-btn-outline">Clear</a>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr style="background: var(--surface-2);">
                        <th class="{{ $th }}"><a href="{{ $sortLink('position') }}" style="color: var(--text-muted);">Order{{ $arrow('position') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortLink('title') }}" style="color: var(--text-muted);">Wave{{ $arrow('title') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortLink('updated_at') }}" style="color: var(--text-muted);">Changed{{ $arrow('updated_at') }}</a></th>
                        <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse($waves as $w)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-5 py-4 align-top whitespace-nowrap" style="width: 6rem;">
                                    <span class="ds-badge ds-badge-pill ds-badge-info tabular-nums">{{ $waves->firstItem() + $loop->index }}</span>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-semibold" style="color: var(--text-primary);">{{ $w->title }} <span class="ds-badge ds-badge-muted ml-1">{{ $tracks[$w->track] ?? $w->track }}</span></div>
                                    @if($w->intro_text)<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $w->intro_text }}</div>@endif
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                        @foreach((array) $w->feature_keys as $key)
                                            <span class="ds-badge ds-badge-default">{{ $labels[$key] ?? $key }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap text-xs" style="color: var(--text-muted);">{{ $w->updated_at?->format('j M Y') }}</td>
                                <td class="px-5 py-4 align-top">
                                    <div class="flex items-center justify-end">
                                        <form method="POST" action="{{ route('admin.feature-rollout-defaults.restore', $w->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">
                                {{ request()->hasAny(['q', 'track', 'from', 'to']) ? 'No archived waves match these filters.' : 'No archived waves.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($waves->hasPages())
                <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $waves->links() }}</div>
            @endif
        </div>
    @endif

    {{-- §12.7 — agencies on gradual rollout (CoreX team overview) --}}
    <div id="rollout-overview" class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">Agencies on gradual rollout <span class="ds-badge ds-badge-muted ml-1">{{ $overview->total() }}</span></div>
            <div class="ds-section-sub">Who to call: paused for a long time, waiting on setup, or hardly using what switched on last. Open an agency to see and change its plan.</div>
        </div>
        <form method="GET" action="#rollout-overview" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
            @foreach (request()->except(['o_show', 'o_q', 'opage']) as $k => $v)
                @if (is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Agency name</span>
                <input type="text" name="o_q" value="{{ $overviewFilters['o_q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
            <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
                <select name="o_show" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                    <option value="attention" @selected($overviewFilters['o_show'] === 'attention')>Needs attention</option>
                    <option value="all" @selected($overviewFilters['o_show'] === 'all')>All agencies on gradual rollout</option>
                </select></label>
            <button type="submit" class="corex-btn-outline">Apply</button>
        </form>
        @if ($overview->count())
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr style="background: var(--surface-2);">
                        <th class="{{ $th }}" style="color: var(--text-muted);">Agency</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Status</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Groups on</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Next</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">Needs attention</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($overview as $row)
                            <tr style="border-top: 1px solid var(--border);" data-overview-row>
                                <td class="px-5 py-2.5"><a href="{{ route('admin.agency-setup-progress.show', $row['agency']->id) }}" style="color: var(--brand-icon);">{{ $row['agency']->name }}</a></td>
                                <td class="px-5 py-2.5" style="color: var(--text-secondary);">{{ ['active' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished'][$row['plan']->status] ?? $row['plan']->status }}</td>
                                <td class="px-5 py-2.5 tabular-nums" style="color: var(--text-secondary);">{{ $row['on'] }} of {{ $row['groups'] }}</td>
                                <td class="px-5 py-2.5" style="color: var(--text-secondary);">{{ $row['next']?->format('D j M') ?? '—' }}</td>
                                <td class="px-5 py-2.5" style="color: var(--text-primary);">{{ $row['flags'] ? implode(' · ', $row['flags']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($overview->hasPages())
                <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $overview->fragment('rollout-overview')->links() }}</div>
            @endif
        @else
            <div class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">
                {{ $overviewFilters['o_show'] === 'attention' ? 'No agency on gradual rollout needs attention right now.' : 'No agency is on gradual rollout yet.' }}
            </div>
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

@if($board)
@push('scripts')
<script>
(function () {
    var B = @json($board);
    var root = document.getElementById('wave-planner');
    if (!root) return;

    var BY = {};
    B.features.forEach(function (f) { BY[f.k] = f; });
    var pace = document.getElementById('pace-form');
    var $ = function (id) { return document.getElementById(id); };

    /* ---------- state ---------- */
    var uid = 0;
    function norm(w) { return { cid: ++uid, id: w.id, title: w.title, track: w.track, intro: w.intro || '', keys: (w.keys || []).slice(), open: false }; }
    var dropped = [];
    function dedupe(list) {
        var seen = {};
        dropped = [];
        list.forEach(function (w) { w.keys = w.keys.filter(function (k) {
            if (!BY[k]) return false;
            if (seen[k]) { dropped.push(BY[k].l + ' (kept in \u201C' + seen[k] + '\u201D)'); return false; }
            seen[k] = w.title; return true;
        }); });
        return list;
    }
    function load(src, raw) {
        var all = raw ? src.map(norm) : dedupe(src.map(norm));
        var s = { waves: all.filter(function (w) { return w.track !== 'admin'; }), admin: all.filter(function (w) { return w.track === 'admin'; }), sel: null, undo: null, q: '', notice: raw ? '' : (dropped.length ? 'These were in more than one wave, so each now stays in the earliest one: ' + dropped.join(', ') + '. Save plan to keep this.' : '') };
        if (!s.admin.length) s.admin.push(norm({ id: null, title: 'Admin decides', track: 'admin', keys: [] }));
        return s;
    }
    var baseline = null, state, drag = null;
    function snapshot() { return JSON.stringify(payload(false)); }
    function payload(dropEmptyAdmin) {
        var out = [];
        state.waves.forEach(function (w) { out.push(row(w)); });
        state.admin.forEach(function (w) { if (!dropEmptyAdmin || w.keys.length) out.push(row(w)); });
        return out;
    }
    function row(w) { return { id: w.id, title: w.title, track: w.track, intro_text: w.intro, feature_keys: w.keys }; }
    function all() { return state.waves.concat(state.admin); }
    function dirty() { return snapshot() !== baseline; }

    state = load(B.waves, true);          // the plan exactly as saved: what "unsaved" is measured against
    baseline = snapshot();
    state = load(B.draft || B.waves);     // a refused save brings the browser's own edits back; repeats are dropped

    /* ---------- dates: same rule as RolloutCalendar ---------- */
    function pad(n) { return String(n).padStart(2, '0'); }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parse(s) { var p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2], 12); }
    function plus(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
    function fmt(d) { return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }); }
    function rules() {
        return {
            weekdays: pace.querySelector('[name=weekdays_only]').checked,
            holidays: pace.querySelector('[name=skip_holidays]').checked,
            step: Math.max(1, parseInt(pace.querySelector('[name=interval_days]').value, 10) || 1),
            time: pace.querySelector('[name=release_time]').value || '07:00'
        };
    }
    function isRelease(d, r) {
        if (r.weekdays && (d.getDay() === 0 || d.getDay() === 6)) return false;
        if (r.holidays && B.holidays[iso(d)]) return false;
        return true;
    }
    function onOrAfter(d, r) { var x = d, g = 0; while (!isRelease(x, r) && g++ < 400) x = plus(x, 1); return x; }
    function addRelease(d, n, r) { var x = onOrAfter(d, r); for (var i = 0; i < n; i++) x = onOrAfter(plus(x, 1), r); return x; }
    function schedule() {
        var r = rules(), start = parse($('wp-start').value || B.today), out = [], last = null, prev = start;
        function skipped(from, to) {
            var names = [];
            if (r.holidays) for (var x = plus(from, 1); x < to; x = plus(x, 1)) if (B.holidays[iso(x)] && !(r.weekdays && (x.getDay() === 0 || x.getDay() === 6))) names.push(B.holidays[iso(x)] + ' (' + fmt(x) + ')');
            return names;
        }
        state.waves.forEach(function (w, i) {
            var d = addRelease(start, (i + 1) * r.step, r);
            out.push({ date: d, skipped: skipped(prev, d) }); prev = d; last = d;
        });
        state.admin.forEach(function (w, i) {
            var d = addRelease(last || start, (i + 1) * r.step, r);
            out.push({ date: d, skipped: skipped(prev, d) }); prev = d;
        });
        return { list: out, r: r, start: start };
    }

    /* ---------- helpers ---------- */
    function h(tag, attrs) {
        var e = document.createElement(tag), kids = Array.prototype.slice.call(arguments, 2);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (k === 'class') e.className = v;
            else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), v);
            else if (v === true) e.setAttribute(k, '');
            else if (v !== false && v != null) e.setAttribute(k, v);
        });
        (function add(list) { list.forEach(function (c) { if (Array.isArray(c)) add(c); else if (c != null && c !== false) e.append(c.nodeType ? c : document.createTextNode(c)); }); })(kids);
        return e;
    }
    function placement() { var p = {}; all().forEach(function (w, i) { w.keys.forEach(function (k) { p[k] = i; }); }); return p; }
    function issuesFor(w, i, place) {
        var out = [];
        w.keys.forEach(function (k) {
            BY[k].d.forEach(function (d) {
                if (place[d] === undefined) out.push({ k: k, d: d, kind: 'unplaced' });
                else if (place[d] > i) out.push({ k: k, d: d, kind: 'later', at: all()[place[d]] });
            });
        });
        return out;
    }
    function moveKey(key, target) {
        all().forEach(function (w) { w.keys = w.keys.filter(function (x) { return x !== key; }); });
        if (target !== 'tray') { var w = all().filter(function (x) { return x.cid === target; })[0]; if (w) w.keys.push(key); }
        state.sel = null; render();
    }
    function clearDrag() { drag = null; root.querySelectorAll('.over,.before,.after,.dragging').forEach(function (n) { n.classList.remove('over', 'before', 'after', 'dragging'); }); }

    function chip(key, flag) {
        var f = BY[key], toggle = function () { state.sel = state.sel === key ? null : key; render(); };
        var c = h('span', {
            class: 'chip' + (state.sel === key ? ' sel' : '') + (flag ? ' warn' : ''), role: 'button', tabindex: '0', draggable: 'true',
            'aria-pressed': state.sel === key ? 'true' : 'false', title: flag || 'Drag to a wave, or press Enter to pick it up',
            onclick: toggle,
            onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } },
            ondragstart: function (e) { drag = { type: 'chip', key: key }; e.dataTransfer.setData('text/plain', key); e.dataTransfer.effectAllowed = 'move'; setTimeout(function () { c.classList.add('dragging'); }, 0); },
            ondragend: clearDrag
        }, flag ? '⚠ ' : '', f.l, f.t ? null : h('small', {}, ' · no tour'));
        return c;
    }

    /* ---------- render ---------- */
    function renderSummary(sched) {
        var place = placement(), placed = Object.keys(place).length, total = B.features.length, problems = 0;
        all().forEach(function (w, i) { problems += issuesFor(w, i, place).length; });
        var used = all().filter(function (w) { return w.keys.length; }), lastWave = all().filter(function (w) { return w.keys.length; }), li = -1;
        all().forEach(function (w, i) { if (w.keys.length) li = i; });
        function stat(v, l, bad) { return h('div', { class: 'wp-stat' + (bad ? ' bad' : '') }, h('b', {}, v), h('span', {}, l)); }
        $('wp-summary').replaceChildren(
            stat(String(used.length), 'waves with features'),
            stat(placed + ' of ' + total, 'features in a wave'),
            stat(String(total - placed), 'on from day one', total - placed > 0),
            stat(String(problems), problems ? 'arrive before what they need' : 'order problems', problems > 0),
            stat(li >= 0 ? fmt(sched.list[li].date) : '—', 'last wave switches on')
        );
    }

    function waveCard(w, i, sched, place) {
        var isAdmin = w.track === 'admin', s = sched.list[i], issues = issuesFor(w, i, place), flagged = {};
        issues.forEach(function (x) { flagged[x.k] = BY[x.d].l + (x.kind === 'later' ? ' arrives later' : ' is not in any wave'); });
        w.keys.forEach(function (k) { if (isAdmin && !BY[k].lim) flagged[k] = 'No access setting of its own: it cannot be kept for the admins. Move it to another wave.'; });
        var n = state.waves.length;
        var card = h('section', {
            class: 'wp-wave', 'data-cid': w.cid,
            ondragover: function (e) {
                if (!drag) return; e.preventDefault();
                if (drag.type === 'chip') card.classList.add('over');
                else if (!isAdmin) { var r = card.getBoundingClientRect(), below = e.clientY > r.top + r.height / 2; card.classList.toggle('before', !below); card.classList.toggle('after', below); }
            },
            ondragleave: function (e) { if (!card.contains(e.relatedTarget)) card.classList.remove('over', 'before', 'after'); },
            ondrop: function (e) {
                if (!drag) return; e.preventDefault();
                if (drag.type === 'chip') moveKey(drag.key, w.cid);
                else if (!isAdmin && drag.cid !== w.cid) {
                    var r = card.getBoundingClientRect(), below = e.clientY > r.top + r.height / 2;
                    var moving = state.waves.filter(function (x) { return x.cid === drag.cid; })[0];
                    state.waves = state.waves.filter(function (x) { return x !== moving; });
                    var at = state.waves.indexOf(w); state.waves.splice(below ? at + 1 : at, 0, moving); render();
                }
                clearDrag();
            }
        },
            h('div', { class: 'wp-when' },
                h('span', { class: 'n' }, isAdmin ? 'After every wave' : 'Wave ' + (i + 1) + ' of ' + n),
                h('span', { class: 'd' }, fmt(s.date)),
                h('span', { class: 't' }, sched.r.time),
                s.skipped.length ? h('span', { class: 'skip' }, 'Skips ' + s.skipped.join(', ')) : null),
            h('div', { class: 'wp-body' },
                h('div', { class: 'wp-head' },
                    isAdmin ? null : h('button', {
                        class: 'wp-grip', type: 'button', draggable: 'true', title: 'Drag to reorder (or use the arrow keys)', 'aria-label': 'Reorder wave ' + (i + 1),
                        ondragstart: function (e) { drag = { type: 'wave', cid: w.cid }; e.dataTransfer.setData('text/plain', 'wave'); e.dataTransfer.setDragImage(card, 20, 20); },
                        ondragend: clearDrag,
                        onkeydown: function (e) { if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); shift(w.cid, e.key === 'ArrowUp' ? -1 : 1); } }
                    }, '⠇'),
                    h('input', { type: 'text', class: 'wp-title', id: 'wp-title-' + w.cid, value: w.title, 'aria-label': 'Wave name', maxlength: '255', oninput: function (e) { w.title = e.target.value; refreshBar(); } }),
                    isAdmin ? h('span', { class: 'ds-badge ds-badge-info' }, 'Admin decides') :
                        h('select', { class: 'wp-sel', 'aria-label': 'Track', onchange: function (e) { w.track = e.target.value; refreshBar(); } },
                            Object.keys(B.tracks).map(function (v) { return h('option', { value: v, selected: w.track === v }, B.tracks[v]); })),
                    state.sel ? h('button', { type: 'button', class: 'corex-btn-primary corex-btn-xs', onclick: function () { moveKey(state.sel, w.cid); } }, 'Move here') : null,
                    h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', onclick: function () { w.open = !w.open; render(); } }, w.intro ? 'Message ✓' : 'Message'),
                    isAdmin ? null : h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', 'aria-label': 'Move wave earlier', title: 'Move earlier', disabled: i === 0, onclick: function () { shift(w.cid, -1); } }, '▲'),
                    isAdmin ? null : h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', 'aria-label': 'Move wave later', title: 'Move later', disabled: i === n - 1, onclick: function () { shift(w.cid, 1); } }, '▼'),
                    isAdmin ? null : h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', style: 'color:var(--ds-crimson);', onclick: function () { archive(w.cid); } }, 'Archive')),
                w.open ? h('label', { class: 'block' }, h('span', { class: 'text-xs font-semibold', style: 'color:var(--text-secondary);' }, 'What to tell people when it switches on'),
                    h('textarea', { rows: '2', maxlength: '2000', class: 'w-full mt-1 rounded-md px-3 py-2 text-sm', style: 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);', oninput: function (e) { w.intro = e.target.value; refreshBar(); } }, w.intro)) : null,
                isAdmin ? h('p', { class: 'text-xs', style: 'color:var(--text-muted);' }, 'Switches on for the agency’s admins only, after every other wave. The admin then chooses who else gets these. An empty wave here is simply not saved.') : null,
                h('div', { class: 'wp-zone' }, w.keys.length ? w.keys.map(function (k) { return chip(k, flagged[k]); }) : h('span', { class: 'wp-hint' }, 'Drop features here')),
                issues.map(function (x) {
                    return h('div', { class: 'wp-issue' },
                        h('span', {}, h('strong', {}, BY[x.k].l), ' needs ', h('strong', {}, BY[x.d].l), x.kind === 'later' ? ', which arrives in “' + x.at.title + '”.' : ', which is not in any wave.'),
                        h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', onclick: function () { moveKey(x.d, w.cid); } }, 'Bring ' + BY[x.d].l + ' into this wave'));
                })));
        return card;
    }

    function renderWaves(sched) {
        var place = placement(), bits = [];
        if (state.sel) bits.push(h('div', { class: 'wp-bar', style: 'background:var(--brand-button); color:#fff;' },
            h('span', {}, 'Moving “' + BY[state.sel].l + '”. Press “Move here” on a wave, or drop it on a wave.'),
            h('span', { class: 'flex gap-2' },
                h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', style: 'background:transparent;color:#fff;border-color:#fff;', onclick: function () { moveKey(state.sel, 'tray'); } }, 'Send to tray'),
                h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', style: 'background:transparent;color:#fff;border-color:#fff;', onclick: function () { state.sel = null; render(); } }, 'Cancel'))));
        if (state.notice) bits.push(h('div', { class: 'wp-bar', style: 'background:color-mix(in srgb, var(--ds-amber) 14%, transparent); border:1px solid var(--ds-amber); color:var(--text-primary);' }, h('span', {}, state.notice)));
        if (state.undo) bits.push(h('div', { class: 'wp-bar', style: 'background:var(--surface-2); border:1px solid var(--border);' },
            h('span', {}, 'Archived “' + state.undo.w.title + '”. Its ' + state.undo.w.keys.length + ' features are back in the tray.'),
            h('button', { type: 'button', class: 'corex-btn-outline corex-btn-xs', onclick: restore }, 'Undo')));
        bits.push(h('div', { class: 'wp-day1' },
            h('div', { class: 'wp-when', style: 'border-radius:6px 0 0 6px;' }, h('span', { class: 'n' }, 'Day one'), h('span', { class: 'd' }, fmt(sched.start)), h('span', { class: 't' }, 'on sign-up')),
            h('div', { class: 'text-sm', style: 'color:var(--text-secondary);' }, 'Properties, Contacts and the always-on screens, plus any feature that is not in a wave (see “Not placed yet”).')));
        if (!state.waves.length) bits.push(h('p', { class: 'text-sm py-3', style: 'color:var(--text-muted);' }, 'No waves yet. Until you add one, an agency that chooses gradual rollout gets every feature at once. Use “+ Add wave” to create the first one.'));
        all().forEach(function (w, i) { bits.push(waveCard(w, i, sched, place)); });
        bits.push(h('div', {}, h('button', { type: 'button', class: 'corex-btn-primary', onclick: addWave }, '+ Add wave')));
        $('wp-waves').replaceChildren.apply($('wp-waves'), bits);
    }

    function renderTray() {
        var place = placement(), q = state.q.toLowerCase();
        var free = B.features.filter(function (f) { return place[f.k] === undefined; });
        $('wp-tray-count').textContent = String(free.length);
        var shown = free.filter(function (f) { return !q || f.l.toLowerCase().indexOf(q) >= 0 || f.c.toLowerCase().indexOf(q) >= 0; });
        var groups = {};
        shown.forEach(function (f) { (groups[f.c] = groups[f.c] || []).push(f); });
        var cats = Object.keys(groups).sort(), body = $('wp-tray');
        body.replaceChildren.apply(body, cats.length ? cats.map(function (c) {
            return h('div', { class: 'wp-cat' }, h('h3', {}, c + ' · ' + groups[c].length), h('div', { class: 'chips' }, groups[c].map(function (f) { return chip(f.k); })));
        }) : [h('p', { class: 'text-sm', style: 'color:var(--text-muted);' }, free.length ? 'No unplaced feature matches “' + state.q + '”.' : 'Every feature is in a wave.')]);
    }

    function refreshBar() { $('wp-dirty').classList.toggle('hidden', !dirty()); }
    function render() { var sched = schedule(); renderSummary(sched); renderWaves(sched); renderTray(); refreshBar(); }

    /* ---------- actions ---------- */
    function shift(cid, dir) {
        var i = state.waves.findIndex(function (w) { return w.cid === cid; }), j = i + dir;
        if (i < 0 || j < 0 || j >= state.waves.length) return;
        var t = state.waves[i]; state.waves[i] = state.waves[j]; state.waves[j] = t; render();
        var g = root.querySelector('[data-cid="' + cid + '"] .wp-grip'); if (g) g.focus();
    }
    function archive(cid) { var w = state.waves.filter(function (x) { return x.cid === cid; })[0], at = state.waves.indexOf(w); state.undo = { w: w, at: at }; state.waves.splice(at, 1); render(); }
    function restore() { var u = state.undo; if (!u) return; state.waves.splice(u.at, 0, u.w); state.undo = null; render(); }
    function addWave() {
        var w = norm({ id: null, title: 'New wave', track: 'general', keys: [] });
        state.waves.push(w); state.undo = null; render();
        var t = $('wp-title-' + w.cid); if (t) { t.focus(); t.select(); }
    }
    function showError(msg) { var e = $('wp-error'); e.textContent = msg || ''; e.classList.toggle('hidden', !msg); if (msg) e.scrollIntoView({ block: 'center' }); }

    $('wp-save').addEventListener('click', function () {
        showError('');
        for (var i = 0; i < state.waves.length; i++) {
            var w = state.waves[i];
            if (!w.title.trim()) return showError('Every wave needs a name.');
            if (!w.keys.length) return showError('“' + w.title + '” has no features. Drag at least one in, or archive the wave.');
        }
        for (var j = 0; j < state.admin.length; j++) if (state.admin[j].keys.length && !state.admin[j].title.trim()) return showError('Every wave needs a name.');
        var out = payload(true);
        if (!out.length) {
            if (!window.confirm('This archives every wave, so a new agency would get every feature at once. Save anyway?')) return;
            $('wp-confirm-empty').value = '1';
        }
        $('wp-layout').value = JSON.stringify(out);
        window.__wpSaving = true;
        $('wp-form').submit();
    });
    $('wp-discard').addEventListener('click', function () { state = load(B.waves); showError(''); render(); });
    $('wp-q').addEventListener('input', function (e) { state.q = e.target.value; renderTray(); });
    $('wp-start').addEventListener('input', render);
    pace.addEventListener('input', render);
    pace.addEventListener('change', render);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.sel) { state.sel = null; render(); } });
    window.addEventListener('beforeunload', function (e) { if (!window.__wpSaving && dirty()) { e.preventDefault(); e.returnValue = ''; } });

    var tray = $('wp-tray');
    tray.addEventListener('dragover', function (e) { if (drag && drag.type === 'chip') { e.preventDefault(); tray.classList.add('over'); } });
    tray.addEventListener('dragleave', function () { tray.classList.remove('over'); });
    tray.addEventListener('drop', function (e) { if (drag && drag.type === 'chip') { e.preventDefault(); tray.classList.remove('over'); moveKey(drag.key, 'tray'); clearDrag(); } });

    render();
})();
</script>
@endpush
@endif
@endsection
