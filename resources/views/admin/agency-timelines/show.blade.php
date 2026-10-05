{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — one agency's timeline, owner working view (AT-447).
     NB: merge fields are written @{{ like_this }} in help text so Blade does not try to evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $agency = $timeline->agency;
    $routeFor = fn ($name, $item = null) => route('admin.agency-timelines.' . $name, $item ? [$timeline, $item->id] : [$timeline]);
    // state => [label, ds-badge variant]
    $stateBadge = ['done' => ['Done', 'ds-badge-success'], 'overdue' => ['Overdue', 'ds-badge-danger'], 'upcoming' => ['Upcoming', 'ds-badge-default'], 'skipped' => ['Skipped', 'ds-badge-warning']];
    $done = $milestones->where('status', 'done')->count();
    $counted = $milestones->whereIn('status', ['pending', 'done'])->count();
    $statusBadge = ['running' => ['Running', 'ds-badge-info'], 'live' => ['Live', 'ds-badge-success'], 'paused' => ['Paused', 'ds-badge-warning']][$timeline->status];
    $th = 'text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider';
@endphp
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    {{ $agency->name }} — onboarding timeline
                    <span class="ds-badge {{ $statusBadge[1] }} ml-2 align-middle">{{ $statusBadge[0] }}</span>
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Started {{ $timeline->start_date->format('j M Y') }} ·
                    @if($goLive['expected'])
                        Go live <strong>{{ $goLive['expected']->format('j M Y') }}</strong>
                        @if($goLive['slip_days'])<span style="color: var(--ds-crimson);">— pushed back {{ $goLive['slip_days'] }} day{{ $goLive['slip_days'] === 1 ? '' : 's' }} by overdue steps (planned {{ $goLive['planned']->format('j M Y') }})</span>@endif
                    @else No go-live step on this timeline. @endif
                    · {{ $done }} of {{ $counted }} steps done
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.agency-timelines.index') }}" class="corex-btn-outline text-xs">← All timelines</a>
                <form method="POST" action="{{ $routeFor('archive') }}" onsubmit="return confirm('Archive this timeline? Its public link goes offline straight away. You can restore it from the Archived filter on the timelines list.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Archive</button></form>
                @if($timeline->status === 'running')
                    <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf<input type="hidden" name="action" value="pause"><button type="submit" class="corex-btn-outline text-xs">Pause</button></form>
                @elseif($timeline->status === 'paused')
                    <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf<input type="hidden" name="action" value="resume"><button type="submit" class="corex-btn-outline text-xs">Resume</button></form>
                @endif
                <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf
                    @if($timeline->status === 'live')
                        <input type="hidden" name="action" value="resume"><button type="submit" class="corex-btn-outline text-xs">Re-open timeline</button>
                    @else
                        <input type="hidden" name="action" value="live"><button type="submit" class="corex-btn-primary text-xs" onclick="return confirm('Mark {{ e($agency->name) }} as live? This is a record only — it does not switch anything on or change billing.')">Mark agency live</button>
                    @endif
                </form>
            </div>
        </div>
    </div>

    @include('admin.partials.platform-flash')

    {{-- Public link + start date --}}
    <div class="grid md:grid-cols-2 gap-4">
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ copied: false }">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Public link for the agency</div>
            @if($timeline->public_link_enabled)
                <input type="text" readonly value="{{ $timeline->publicUrl() }}" class="ds-field w-full text-xs" onclick="this.select()" aria-label="Public link">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="corex-btn-primary text-xs" @click="navigator.clipboard.writeText(@js($timeline->publicUrl())); copied = true; setTimeout(() => copied = false, 1800)" x-text="copied ? 'Copied ✓' : 'Copy link'">Copy link</button>
                    <a href="{{ $timeline->publicUrl() }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">Open</a>
                    <form method="POST" action="{{ $routeFor('link') }}">@csrf<input type="hidden" name="action" value="disable"><button type="submit" class="corex-btn-outline text-xs">Switch off</button></form>
                    <form method="POST" action="{{ $routeFor('link') }}" onsubmit="return confirm('Make a new link? The current link stops working immediately.');">@csrf<input type="hidden" name="action" value="regenerate"><button type="submit" class="corex-btn-outline text-xs">Make a new link</button></form>
                </div>
                <p class="text-xs" style="color: var(--text-muted);">Anyone with this link can see the steps marked "shown to agency" — no login. No names or emails are shown.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">The public link is switched off — the agency sees nothing.</p>
                <form method="POST" action="{{ $routeFor('link') }}">@csrf<input type="hidden" name="action" value="enable"><button type="submit" class="corex-btn-primary text-xs">Switch on</button></form>
            @endif
        </div>

        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Start date</div>
            <form method="POST" action="{{ $routeFor('start-date') }}" class="space-y-2">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center gap-2">
                    <input type="date" name="start_date" value="{{ $timeline->start_date->toDateString() }}" class="ds-field" aria-label="Start date">
                    <button type="submit" class="corex-btn-outline text-xs">Change</button>
                </div>
                <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="shift" value="1" checked> Move every step that isn't done yet by the same number of days</label>
            </form>
            <form method="POST" action="{{ $routeFor('reset-dates') }}" onsubmit="return confirm('Put every default step back to its default date from the start date? Dates you moved by hand will be overwritten.');">@csrf
                <button type="submit" class="corex-btn-outline text-xs">Reset all dates to the defaults</button>
            </form>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1" style="border-bottom: 1px solid var(--border);">
        @foreach(['plan' => 'Plan', 'history' => 'History (' . $events->count() . ')'] as $k => $label)
            <a href="{{ request()->fullUrlWithQuery(['tab' => $k]) }}" class="px-4 py-2 text-sm -mb-px {{ $tab === $k ? 'font-semibold' : '' }}"
               style="{{ $tab === $k ? 'border-bottom: 2px solid var(--brand-icon); color: var(--brand-icon);' : 'color: var(--text-secondary);' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($tab === 'history')
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead><tr style="background: var(--surface-2);">
                        <th class="{{ $th }}" style="color: var(--text-muted);">When</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">What happened</th>
                        <th class="{{ $th }}" style="color: var(--text-muted);">By</th>
                    </tr></thead>
                    <tbody>
                    @forelse($events as $ev)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap tabular-nums" style="color: var(--text-muted);">{{ $ev->created_at->format('j M Y H:i') }}</td>
                            <td class="px-4 py-3" style="color: var(--text-primary);">{{ $ev->summary }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $ev->actor_user_id ? ($actors[$ev->actor_user_id] ?? 'User #' . $ev->actor_user_id) : ($ev->source === 'manual' ? '—' : 'Automatic') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-10 text-center" style="color: var(--text-muted);">Nothing recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else

    {{-- Steps --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ adding: false }">
        <div class="px-4 py-3 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Steps</div>
            <button type="button" class="corex-btn-outline text-xs" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add custom step'">+ Add custom step</button>
        </div>
        <div x-show="adding" x-cloak class="px-4 py-4" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            <form method="POST" action="{{ $routeFor('items.store') }}" class="grid md:grid-cols-4 gap-3 items-end">
                @csrf <input type="hidden" name="kind" value="milestone">
                <div class="md:col-span-2"><label class="ds-label block mb-1">Step title</label><input name="title" required maxlength="255" class="ds-field w-full"></div>
                <div><label class="ds-label block mb-1">Date</label><input type="date" name="due_date" required class="ds-field w-full"></div>
                <div class="md:col-span-4"><label class="ds-label block mb-1">Description <span style="color: var(--text-muted);">(optional)</span></label><textarea name="body" rows="2" maxlength="5000" class="ds-field w-full"></textarea></div>
                <div class="md:col-span-3 space-y-1">
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_public" value="1" checked> Show on the agency's public page</label>
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="agency_can_complete" value="1"> The agency can mark this step completed from their public link</label>
                </div>
                <div class="flex justify-end"><button type="submit" class="corex-btn-primary text-xs">Add step</button></div>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">Date</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Step</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Status</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                @forelse($milestones as $m)
                    @php $state = $svc->state($m, $today); [$sl, $sc] = $stateBadge[$state]; $late = $svc->daysOverdue($m, $today); @endphp
                    <tbody x-data="{ edit: false }">
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap tabular-nums align-top" style="color: var(--text-secondary);">{{ $m->due_date?->format('D j M Y') ?? 'No date' }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium" style="color: var(--text-primary);">{{ $svc->mergeText($m->title, $timeline, $goLive) }}</div>
                                @if($m->body)<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $svc->mergeText($m->body, $timeline, $goLive) }}</div>@endif
                                @if($m->status === 'done' && $m->completed_at)<div class="text-xs mt-0.5" style="color: var(--ds-green);">Done {{ $m->completed_at->format('j M Y') }}{{ $m->completed_source === 'agency' ? ' (by the agency)' : ($m->completed_source && $m->completed_source !== 'manual' ? ' (automatic)' : '') }}</div>@endif
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @if($m->is_go_live)<span class="ds-badge ds-badge-success">Go live</span>@endif
                                    @if($m->is_custom)<span class="ds-badge ds-badge-info">Custom</span>@endif
                                    @unless($m->is_public)<span class="ds-badge ds-badge-default">Hidden</span>@endunless
                                    @if($m->agency_can_complete)<span class="ds-badge ds-badge-info" title="The agency can tick this from their public link">Agency ticks</span>@endif
                                    @if($m->auto_complete_trigger && $m->status === 'pending')<span class="ds-badge ds-badge-muted" title="Ticks itself when this happens">Auto</span>@endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <span class="ds-badge {{ $sc }}">{{ $sl }}</span>
                                @if($late)<div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $late }}d late</div>@endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    @if($m->status === 'pending')
                                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="done"><button type="submit" class="corex-btn-primary corex-btn-xs">Mark done</button></form>
                                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="skipped"><button type="submit" class="corex-btn-outline corex-btn-xs">Skip</button></form>
                                    @else
                                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="pending"><button type="submit" class="corex-btn-outline corex-btn-xs">Reopen</button></form>
                                    @endif
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                    <form method="POST" action="{{ $routeFor('items.move', $m) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move up (same-day order)" aria-label="Move up">▲</button></form>
                                    <form method="POST" action="{{ $routeFor('items.move', $m) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move down (same-day order)" aria-label="Move down">▼</button></form>
                                    <form method="POST" action="{{ $routeFor('items.archive', $m) }}" onsubmit="return confirm('Archive this step? You can restore it.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak>
                            <td colspan="4" class="px-4 py-4" style="background: var(--surface-2);">
                                <form method="POST" action="{{ $routeFor('items.update', $m) }}" class="grid md:grid-cols-4 gap-3 items-end">
                                    @csrf @method('PUT')
                                    <div class="md:col-span-2"><label class="ds-label block mb-1">Step title</label><input name="title" value="{{ $m->title }}" required maxlength="255" class="ds-field w-full"></div>
                                    <div><label class="ds-label block mb-1">Date</label><input type="date" name="due_date" value="{{ $m->due_date?->toDateString() }}" class="ds-field w-full"></div>
                                    <div class="md:col-span-4"><label class="ds-label block mb-1">Description</label><textarea name="body" rows="2" maxlength="5000" class="ds-field w-full">{{ $m->body }}</textarea></div>
                                    <div class="md:col-span-3 space-y-1">
                                        <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_public" value="1" @checked($m->is_public)> Show on the agency's public page</label>
                                        <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="agency_can_complete" value="1" @checked($m->agency_can_complete)> The agency can mark this step completed from their public link</label>
                                    </div>
                                    <div class="flex justify-end"><button type="submit" class="corex-btn-primary text-xs">Save</button></div>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody><tr><td colspan="4" class="px-4 py-10 text-center" style="color: var(--text-muted);">No steps yet. Add a custom step, or add defaults in Dev Settings (they apply to <em>new</em> timelines).</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </div>

    {{-- Information sections --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ adding: false }">
        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Information sections</div>
                <div class="text-xs" style="color: var(--text-muted);">Text can use @{{ agency_name }}, @{{ go_live_date }}, @{{ billing_start_date }}, @{{ start_date }}</div>
            </div>
            <button type="button" class="corex-btn-outline text-xs" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add section'">+ Add section</button>
        </div>
        <div x-show="adding" x-cloak class="px-4 py-4" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            <form method="POST" action="{{ $routeFor('items.store') }}" class="space-y-3 max-w-2xl">
                @csrf <input type="hidden" name="kind" value="block">
                <div><label class="ds-label block mb-1">Heading</label><input name="title" required maxlength="255" class="ds-field w-full"></div>
                <div><label class="ds-label block mb-1">Text</label><textarea name="body" rows="4" maxlength="5000" class="ds-field w-full"></textarea></div>
                <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_public" value="1" checked> Show on the agency's public page</label>
                <button type="submit" class="corex-btn-primary text-xs">Add section</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">Section</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                @forelse($blocks as $b)
                    <tbody x-data="{ edit: false }">
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium" style="color: var(--text-primary);">{{ $svc->mergeText($b->title, $timeline, $goLive) }}
                                    @if($b->is_custom)<span class="ds-badge ds-badge-info ml-1">Custom</span>@endif
                                    @unless($b->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden</span>@endunless
                                </div>
                                @if($b->body)<div class="text-xs mt-0.5 whitespace-pre-line" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($svc->mergeText($b->body, $timeline, $goLive), 260) }}</div>@endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                    <form method="POST" action="{{ $routeFor('items.move', $b) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move up" aria-label="Move up">▲</button></form>
                                    <form method="POST" action="{{ $routeFor('items.move', $b) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move down" aria-label="Move down">▼</button></form>
                                    <form method="POST" action="{{ $routeFor('items.archive', $b) }}" onsubmit="return confirm('Archive this section? You can restore it.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak>
                            <td colspan="2" class="px-4 py-4" style="background: var(--surface-2);">
                                <form method="POST" action="{{ $routeFor('items.update', $b) }}" class="space-y-3 max-w-2xl">
                                    @csrf @method('PUT')
                                    <div><label class="ds-label block mb-1">Heading</label><input name="title" value="{{ $b->title }}" required maxlength="255" class="ds-field w-full"></div>
                                    <div><label class="ds-label block mb-1">Text</label><textarea name="body" rows="6" maxlength="5000" class="ds-field w-full">{{ $b->body }}</textarea></div>
                                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_public" value="1" @checked($b->is_public)> Show on the agency's public page</label>
                                    <button type="submit" class="corex-btn-primary text-xs">Save</button>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody><tr><td colspan="2" class="px-4 py-10 text-center" style="color: var(--text-muted);">No information sections.</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </div>

    {{-- Archived --}}
    @if($archivedCount)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-3 flex items-center justify-between gap-3">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Archived <span class="font-normal text-xs" style="color: var(--text-muted);">({{ $archivedCount }})</span></div>
                <a class="corex-btn-outline text-xs" href="{{ request()->fullUrlWithQuery(['archived' => request()->boolean('archived') ? 0 : 1]) }}">{{ request()->boolean('archived') ? 'Hide' : 'Show' }}</a>
            </div>
            @foreach($archived as $a)
                <div class="px-4 py-3 flex items-center justify-between gap-3 text-sm" style="border-top: 1px solid var(--border); color: var(--text-muted);">
                    <span>{{ ucfirst($a->kind === 'block' ? 'section' : 'step') }}: {{ $a->title }}</span>
                    <form method="POST" action="{{ $routeFor('items.restore', $a) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Agreement — the CoreX contract, sent through Platform E-Sign --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Agreement for {{ $agency->name }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Send the contract from Platform E-Sign, then link it here. When it is fully signed the "sign agreement" step ticks itself.</div>
            </div>
            <form method="POST" action="{{ route('admin.platform-esign.enter') }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Open Platform E-Sign</button></form>
        </div>
        <form method="POST" action="{{ route('admin.agency-timelines.agreement', $timeline) }}" class="px-4 py-4 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="ds-label block mb-1">Linked document</label>
                <select name="template_id" class="ds-field" style="min-width: 18rem;">
                    <option value="">— none —</option>
                    @foreach($agreementDocs as $d)
                        <option value="{{ $d->id }}" @selected((int) $timeline->agreement_template_id === (int) $d->id)>{{ $d->name }} — {{ str_replace('_', ' ', $d->status) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save link</button>
            @if($timeline->agreement_template_id)
                @php $linked = $agreementDocs->firstWhere('id', $timeline->agreement_template_id); @endphp
                <span class="ds-badge {{ ($linked->status ?? '') === 'completed' ? 'ds-badge-success' : 'ds-badge-default' }}">{{ ($linked->status ?? '') === 'completed' ? 'Signed' : 'Awaiting' }}</span>
            @endif
        </form>
    </div>
    @endif
</div>
@endsection
