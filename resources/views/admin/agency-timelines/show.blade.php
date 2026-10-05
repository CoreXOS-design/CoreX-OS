{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — one agency's timeline, owner working view (AT-447).
     NB: merge fields are written @{{ like_this }} in help text so Blade does not try to evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $agency = $timeline->agency;
    $routeFor = fn ($name, $item = null) => route('admin.agency-timelines.' . $name, $item ? [$timeline, $item->id] : [$timeline]);
    $stateTone = ['done' => ['Done', 'var(--ds-green)'], 'overdue' => ['Overdue', 'var(--ds-crimson)'], 'upcoming' => ['Upcoming', 'var(--text-muted)'], 'skipped' => ['Skipped', 'var(--ds-amber)']];
    $done = $milestones->where('status', 'done')->count();
    $counted = $milestones->whereIn('status', ['pending', 'done'])->count();
    $statusTone = ['running' => ['Running', 'var(--brand-icon)'], 'live' => ['Live', 'var(--ds-green)'], 'paused' => ['Paused', 'var(--ds-amber)']][$timeline->status];
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.agency-timelines.index') }}" class="text-xs underline" style="color:var(--text-muted);">← All agency timelines</a>
                <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">{{ $agency->name }} — onboarding timeline
                    <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold align-middle ml-2" style="background: color-mix(in srgb, {{ $statusTone[1] }} 14%, transparent); color: {{ $statusTone[1] }};">{{ $statusTone[0] }}</span>
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Started {{ $timeline->start_date->format('j M Y') }} ·
                    @if($goLive['expected'])
                        Go live <strong>{{ $goLive['expected']->format('j M Y') }}</strong>
                        @if($goLive['slip_days'])<span style="color:var(--ds-crimson);">— pushed back {{ $goLive['slip_days'] }} day{{ $goLive['slip_days'] === 1 ? '' : 's' }} by overdue steps (planned {{ $goLive['planned']->format('j M Y') }})</span>@endif
                    @else No go-live step on this timeline. @endif
                    · {{ $done }} of {{ $counted }} steps done
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf
                    @if($timeline->status === 'live')
                        <input type="hidden" name="action" value="resume"><button class="corex-btn-outline text-xs">Re-open timeline</button>
                    @else
                        <input type="hidden" name="action" value="live"><button class="corex-btn-primary text-xs" onclick="return confirm('Mark {{ e($agency->name) }} as live? This is a record only — it does not switch anything on or change billing.')">Mark agency live</button>
                    @endif
                </form>
                @if($timeline->status === 'running')
                    <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf<input type="hidden" name="action" value="pause"><button class="corex-btn-outline text-xs">Pause</button></form>
                @elseif($timeline->status === 'paused')
                    <form method="POST" action="{{ $routeFor('lifecycle') }}">@csrf<input type="hidden" name="action" value="resume"><button class="corex-btn-outline text-xs">Resume</button></form>
                @endif
            </div>
        </div>
    </div>

    @include('admin.partials.platform-flash')

    {{-- Public link + start date --}}
    <div class="grid md:grid-cols-2 gap-4">
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border:1px solid var(--border);" x-data="{ copied: false }">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Public link for the agency</div>
            @if($timeline->public_link_enabled)
                <input type="text" readonly value="{{ $timeline->publicUrl() }}" class="ds-field w-full text-xs" onclick="this.select()">
                <div class="flex flex-wrap gap-3 items-center text-xs">
                    <button type="button" class="corex-btn-primary text-xs" @click="navigator.clipboard.writeText(@js($timeline->publicUrl())); copied = true; setTimeout(() => copied = false, 1800)" x-text="copied ? 'Copied ✓' : 'Copy link'"></button>
                    <a href="{{ $timeline->publicUrl() }}" target="_blank" rel="noopener" class="underline" style="color:var(--brand-icon);">Open</a>
                    <form method="POST" action="{{ $routeFor('link') }}">@csrf<input type="hidden" name="action" value="disable"><button class="underline" style="color:var(--ds-amber);">Switch off</button></form>
                    <form method="POST" action="{{ $routeFor('link') }}" onsubmit="return confirm('Make a new link? The current link stops working immediately.');">@csrf<input type="hidden" name="action" value="regenerate"><button class="underline" style="color:var(--ds-crimson);">Make a new link</button></form>
                </div>
                <p class="text-xs" style="color:var(--text-muted);">Anyone with this link can see the steps marked "shown to agency" — no login. No names or emails are shown.</p>
            @else
                <p class="text-xs" style="color:var(--text-muted);">The public link is switched off — the agency sees nothing.</p>
                <form method="POST" action="{{ $routeFor('link') }}">@csrf<input type="hidden" name="action" value="enable"><button class="corex-btn-primary text-xs">Switch on</button></form>
            @endif
        </div>

        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Start date</div>
            <form method="POST" action="{{ $routeFor('start-date') }}" class="space-y-2">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center gap-2">
                    <input type="date" name="start_date" value="{{ $timeline->start_date->toDateString() }}" class="ds-field">
                    <button class="corex-btn-outline text-xs">Change</button>
                </div>
                <label class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);"><input type="checkbox" name="shift" value="1" checked> Move every step that isn't done yet by the same number of days</label>
            </form>
            <form method="POST" action="{{ $routeFor('reset-dates') }}" onsubmit="return confirm('Put every default step back to its default date from the start date? Dates you moved by hand will be overwritten.');">@csrf
                <button class="text-xs underline" style="color:var(--text-muted);">Reset all dates to the defaults</button>
            </form>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1" style="border-bottom:1px solid var(--border);">
        @foreach(['plan' => 'Plan', 'history' => 'History (' . $events->count() . ')'] as $k => $label)
            <a href="{{ request()->fullUrlWithQuery(['tab' => $k]) }}" class="px-4 py-2 text-sm {{ $tab === $k ? 'font-semibold' : '' }}"
               style="{{ $tab === $k ? 'border-bottom:2px solid var(--brand-icon); color:var(--brand-icon);' : 'color:var(--text-secondary);' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($tab === 'history')
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wider"><th class="px-4 py-3">When</th><th class="px-4 py-3">What happened</th><th class="px-4 py-3">By</th></tr></thead>
                <tbody>
                @forelse($events as $ev)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-2.5 whitespace-nowrap tabular-nums" style="color:var(--text-muted);">{{ $ev->created_at->format('j M Y H:i') }}</td>
                        <td class="px-4 py-2.5" style="color:var(--text-primary);">{{ $ev->summary }}</td>
                        <td class="px-4 py-2.5" style="color:var(--text-secondary);">{{ $ev->actor_user_id ? ($actors[$ev->actor_user_id] ?? 'User #' . $ev->actor_user_id) : ($ev->source === 'manual' ? '—' : 'Automatic') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center" style="color:var(--text-muted);">Nothing recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @else

    {{-- Milestones --}}
    <div class="rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Steps</div>
            <details class="relative"><summary class="corex-btn-outline text-xs cursor-pointer list-none">+ Add custom step</summary>
                <form method="POST" action="{{ $routeFor('items.store') }}" class="absolute right-0 z-10 mt-2 w-80 rounded-md p-3 space-y-2" style="background: var(--surface); border:1px solid var(--border); box-shadow:0 8px 24px rgba(0,0,0,.15);">
                    @csrf <input type="hidden" name="kind" value="milestone">
                    <input name="title" required maxlength="255" placeholder="Step title" class="ds-field w-full">
                    <textarea name="body" rows="2" maxlength="5000" placeholder="Optional description" class="ds-field w-full"></textarea>
                    <input type="date" name="due_date" required class="ds-field w-full">
                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_public" value="1" checked> Show on the agency's public page</label>
                    <button class="corex-btn-primary text-xs">Add step</button>
                </form>
            </details>
        </div>
        @forelse($milestones as $m)
            @php $state = $svc->state($m, $today); [$sl, $stc] = $stateTone[$state]; $late = $svc->daysOverdue($m, $today); @endphp
            <div class="px-4 py-3 flex flex-col md:flex-row md:items-start gap-3" style="border-top:1px solid var(--border);">
                <div class="md:w-28 flex-shrink-0 text-sm tabular-nums" style="color:var(--text-secondary);">{{ $m->due_date?->format('D j M') ?? 'No date' }}</div>
                <div class="flex-1 min-w-0">
                    <div class="font-medium" style="color:var(--text-primary);">
                        {{ $svc->mergeText($m->title, $timeline, $goLive) }}
                        <span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold ml-1" style="background: color-mix(in srgb, {{ $stc }} 14%, transparent); color: {{ $stc }};">{{ $sl }}{{ $late ? ' · ' . $late . 'd late' : '' }}</span>
                        @if($m->is_go_live)<span class="ds-badge ds-badge-success ml-1">Go live</span>@endif
                        @if($m->is_custom)<span class="ds-badge ds-badge-info ml-1">Custom</span>@endif
                        @unless($m->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden from agency</span>@endunless
                        @if($m->auto_complete_trigger && $m->status === 'pending')<span class="ds-badge ds-badge-muted ml-1" title="Ticks itself when this happens">Auto: {{ \App\Models\Platform\AgencyTimelineDefaultItem::TRIGGERS[$m->auto_complete_trigger] ?? $m->auto_complete_trigger }}</span>@endif
                    </div>
                    @if($m->body)<div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $svc->mergeText($m->body, $timeline, $goLive) }}</div>@endif
                    @if($m->status === 'done' && $m->completed_at)<div class="text-xs mt-0.5" style="color:var(--ds-green);">Done {{ $m->completed_at->format('j M Y') }}{{ $m->completed_source && $m->completed_source !== 'manual' ? ' (automatic)' : '' }}</div>@endif
                    <details class="mt-2"><summary class="text-xs underline cursor-pointer" style="color:var(--brand-icon);">Edit</summary>
                        <form method="POST" action="{{ $routeFor('items.update', $m) }}" class="mt-2 space-y-2 max-w-lg">
                            @csrf @method('PUT')
                            <input name="title" value="{{ $m->title }}" required maxlength="255" class="ds-field w-full">
                            <textarea name="body" rows="2" maxlength="5000" class="ds-field w-full">{{ $m->body }}</textarea>
                            <input type="date" name="due_date" value="{{ $m->due_date?->toDateString() }}" class="ds-field">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_public" value="1" @checked($m->is_public)> Show on the agency's public page</label>
                            <button class="corex-btn-primary text-xs">Save</button>
                        </form>
                    </details>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs md:justify-end">
                    @if($m->status === 'pending')
                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="done"><button class="corex-btn-primary text-xs">Mark done</button></form>
                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="skipped"><button class="underline" style="color:var(--text-muted);">Skip</button></form>
                    @else
                        <form method="POST" action="{{ $routeFor('items.status', $m) }}">@csrf<input type="hidden" name="status" value="pending"><button class="underline" style="color:var(--text-muted);">Reopen</button></form>
                    @endif
                    <form method="POST" action="{{ $routeFor('items.move', $m) }}">@csrf<input type="hidden" name="direction" value="up"><button title="Move up (same-day order)" class="px-1" style="color:var(--text-muted);">▲</button></form>
                    <form method="POST" action="{{ $routeFor('items.move', $m) }}">@csrf<input type="hidden" name="direction" value="down"><button title="Move down" class="px-1" style="color:var(--text-muted);">▼</button></form>
                    <form method="POST" action="{{ $routeFor('items.archive', $m) }}" onsubmit="return confirm('Archive this step? You can restore it.');">@csrf @method('DELETE')<button class="underline" style="color:var(--ds-crimson);">Archive</button></form>
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No steps yet. Add a custom step, or add defaults in Dev Settings (they apply to <em>new</em> timelines).</div>
        @endforelse
    </div>

    {{-- Info blocks --}}
    <div class="rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Information sections <span class="font-normal text-xs" style="color:var(--text-muted);">(text can use @{{ agency_name }}, @{{ go_live_date }}, @{{ billing_start_date }}, @{{ start_date }})</span></div>
            <details class="relative"><summary class="corex-btn-outline text-xs cursor-pointer list-none">+ Add section</summary>
                <form method="POST" action="{{ $routeFor('items.store') }}" class="absolute right-0 z-10 mt-2 w-80 rounded-md p-3 space-y-2" style="background: var(--surface); border:1px solid var(--border); box-shadow:0 8px 24px rgba(0,0,0,.15);">
                    @csrf <input type="hidden" name="kind" value="block">
                    <input name="title" required maxlength="255" placeholder="Heading" class="ds-field w-full">
                    <textarea name="body" rows="4" maxlength="5000" placeholder="Text" class="ds-field w-full"></textarea>
                    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_public" value="1" checked> Show on the agency's public page</label>
                    <button class="corex-btn-primary text-xs">Add section</button>
                </form>
            </details>
        </div>
        @forelse($blocks as $b)
            <div class="px-4 py-3 flex flex-col md:flex-row gap-3" style="border-top:1px solid var(--border);">
                <div class="flex-1 min-w-0">
                    <div class="font-medium" style="color:var(--text-primary);">{{ $svc->mergeText($b->title, $timeline, $goLive) }}
                        @if($b->is_custom)<span class="ds-badge ds-badge-info ml-1">Custom</span>@endif
                        @unless($b->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden from agency</span>@endunless</div>
                    @if($b->body)<div class="text-xs mt-0.5 whitespace-pre-line" style="color:var(--text-muted);">{{ \Illuminate\Support\Str::limit($svc->mergeText($b->body, $timeline, $goLive), 260) }}</div>@endif
                    <details class="mt-2"><summary class="text-xs underline cursor-pointer" style="color:var(--brand-icon);">Edit</summary>
                        <form method="POST" action="{{ $routeFor('items.update', $b) }}" class="mt-2 space-y-2 max-w-xl">
                            @csrf @method('PUT')
                            <input name="title" value="{{ $b->title }}" required maxlength="255" class="ds-field w-full">
                            <textarea name="body" rows="6" maxlength="5000" class="ds-field w-full">{{ $b->body }}</textarea>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_public" value="1" @checked($b->is_public)> Show on the agency's public page</label>
                            <button class="corex-btn-primary text-xs">Save</button>
                        </form>
                    </details>
                </div>
                <div class="flex items-start gap-2 text-xs">
                    <form method="POST" action="{{ $routeFor('items.move', $b) }}">@csrf<input type="hidden" name="direction" value="up"><button class="px-1" style="color:var(--text-muted);">▲</button></form>
                    <form method="POST" action="{{ $routeFor('items.move', $b) }}">@csrf<input type="hidden" name="direction" value="down"><button class="px-1" style="color:var(--text-muted);">▼</button></form>
                    <form method="POST" action="{{ $routeFor('items.archive', $b) }}" onsubmit="return confirm('Archive this section? You can restore it.');">@csrf @method('DELETE')<button class="underline" style="color:var(--ds-crimson);">Archive</button></form>
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">No information sections.</div>
        @endforelse
    </div>

    {{-- Archived --}}
    @if($archivedCount)
        <div class="text-xs"><a class="underline" style="color:var(--text-muted);" href="{{ request()->fullUrlWithQuery(['archived' => request()->boolean('archived') ? 0 : 1]) }}">{{ request()->boolean('archived') ? 'Hide' : 'Show' }} archived ({{ $archivedCount }})</a></div>
        @foreach($archived as $a)
            <div class="rounded-md px-4 py-2.5 flex items-center justify-between text-sm" style="background: var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                <span>{{ ucfirst($a->kind === 'block' ? 'section' : 'step') }}: {{ $a->title }}</span>
                <form method="POST" action="{{ $routeFor('items.restore', $a) }}">@csrf<button class="underline text-xs" style="color:var(--brand-icon);">Restore</button></form>
            </div>
        @endforeach
    @endif

    {{-- Contracts --}}
    <div class="rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Contracts for {{ $agency->name }}</div>
            <a href="{{ route('admin.agency-contracts.create', ['agency_id' => $agency->id]) }}" class="corex-btn-primary text-xs">Send contract</a>
        </div>
        @forelse($contracts as $c)
            <div class="px-4 py-2.5 flex items-center justify-between text-sm" style="border-top:1px solid var(--border);">
                <a href="{{ route('admin.agency-contracts.show', $c->id) }}" class="underline" style="color:var(--brand-icon);">{{ $c->title }}</a>
                <span class="ds-badge {{ $c->status === 'signed' ? 'ds-badge-success' : ($c->status === 'declined' ? 'ds-badge-danger' : 'ds-badge-default') }}">{{ ucfirst($c->status) }}</span>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">No contract sent yet.</div>
        @endforelse
    </div>
    @endif
</div>
@endsection
