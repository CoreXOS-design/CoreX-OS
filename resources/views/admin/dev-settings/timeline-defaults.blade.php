{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Agency timeline defaults (AT-447).
     Merge fields are written @{{ like_this }} so Blade does not evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
@php $th = 'text-left px-5 py-2.5 text-xs font-semibold uppercase tracking-wider'; @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency timeline defaults</h1>
                <p class="text-sm text-white/60">The plan every <strong>new</strong> agency timeline starts from. Each step has a number of days after the start date. Changing a default never changes a timeline that is already running — those keep their own copy.</p>
                <p class="text-sm text-white/60 mt-1">In any text you can use @{{ agency_name }}, @{{ start_date }}, @{{ go_live_date }} and @{{ billing_start_date }}.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.dev-settings.index') }}" class="corex-btn-outline">← Dev Settings</a>
            </div>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    {{-- Steps --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ adding: false }">
        <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="ds-section-header">Default steps <span class="ds-badge ds-badge-muted ml-1">{{ $milestones->count() }}</span></div>
                <div class="ds-section-sub">The dated checklist each new agency works through, counted from their start date.</div>
            </div>
            <button type="button" class="corex-btn-primary" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add step'">+ Add step</button>
        </div>
        <div x-show="adding" x-cloak class="px-5 py-5" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            @include('admin.dev-settings._timeline-default-form', ['kind' => 'milestone', 'item' => null, 'triggers' => $triggers])
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">When</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Step</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                @forelse($milestones as $m)
                    <tbody x-data="{ edit: false }" style="border-top: 1px solid var(--border);">
                        <tr class="corex-tl-row">
                            <td class="px-5 py-4 whitespace-nowrap align-top" style="width: 7rem;"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">Day +{{ $m->offset_days }}</span></td>
                            <td class="px-5 py-4 align-top">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $m->title }}</div>
                                @if($m->body)<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $m->body }}</div>@endif
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @if($m->is_go_live)<span class="ds-badge ds-badge-success">Go live</span>@endif
                                    @unless($m->is_public)<span class="ds-badge ds-badge-default">Hidden</span>@endunless
                                    @if($m->agency_can_complete)<span class="ds-badge ds-badge-info" title="The agency can tick this from their public link">Agency ticks</span>@endif
                                    @if($m->auto_complete_trigger)<span class="ds-badge ds-badge-muted" title="Ticks itself when this happens">Auto: {{ $triggers[$m->auto_complete_trigger] ?? $m->auto_complete_trigger }}</span>@endif
                                </div>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $m->id) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move up (same-day order)" aria-label="Move up">▲</button></form>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $m->id) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move down (same-day order)" aria-label="Move down">▼</button></form>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.destroy', $m->id) }}" onsubmit="return confirm('Archive this default step? New timelines will not include it. You can restore it.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak>
                            <td colspan="3" class="px-5 py-5" style="background: var(--surface-2);">@include('admin.dev-settings._timeline-default-form', ['kind' => 'milestone', 'item' => $m, 'triggers' => $triggers])</td>
                        </tr>
                    </tbody>
                @empty
                    <tbody><tr><td colspan="3" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">No default steps yet — use “+ Add step” to create the first one.</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </div>

    {{-- Sections --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ adding: false }">
        <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="ds-section-header">Default information sections <span class="ds-badge ds-badge-muted ml-1">{{ $blocks->count() }}</span></div>
                <div class="ds-section-sub">Text blocks shown on the agency's timeline page, below the steps.</div>
            </div>
            <button type="button" class="corex-btn-primary" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add section'">+ Add section</button>
        </div>
        <div x-show="adding" x-cloak class="px-5 py-5" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
            @include('admin.dev-settings._timeline-default-form', ['kind' => 'block', 'item' => null, 'triggers' => $triggers])
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">Section</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                @forelse($blocks as $b)
                    <tbody x-data="{ edit: false }" style="border-top: 1px solid var(--border);">
                        <tr class="corex-tl-row">
                            <td class="px-5 py-4 align-top">
                                <div class="font-medium" style="color: var(--text-primary);">{{ $b->title }} @unless($b->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden</span>@endunless</div>
                                @if($b->body)<div class="text-xs mt-0.5 whitespace-pre-line" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($b->body, 300) }}</div>@endif
                            </td>
                            <td class="px-5 py-4 align-top">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move up" aria-label="Move up">▲</button></form>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="corex-btn-outline corex-btn-xs" title="Move down" aria-label="Move down">▼</button></form>
                                    <form method="POST" action="{{ route('admin.timeline-defaults.destroy', $b->id) }}" onsubmit="return confirm('Archive this default section? You can restore it.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak>
                            <td colspan="2" class="px-5 py-5" style="background: var(--surface-2);">@include('admin.dev-settings._timeline-default-form', ['kind' => 'block', 'item' => $b, 'triggers' => $triggers])</td>
                        </tr>
                    </tbody>
                @empty
                    <tbody><tr><td colspan="2" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">No default sections yet — use “+ Add section” to create one.</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </div>

    @if($archivedCount)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4 flex items-center justify-between gap-3">
                <div class="ds-section-header" style="color: var(--text-primary);">Archived <span class="font-normal text-xs" style="color: var(--text-muted);">({{ $archivedCount }})</span></div>
                <a class="corex-btn-outline" href="{{ request()->fullUrlWithQuery(['archived' => request()->boolean('archived') ? 0 : 1]) }}">{{ request()->boolean('archived') ? 'Hide' : 'Show' }}</a>
            </div>
            @foreach($archived as $a)
                <div class="px-5 py-3 flex items-center justify-between gap-3 text-sm" style="border-top: 1px solid var(--border); color: var(--text-muted);">
                    <span>{{ $a->kind === 'block' ? 'Section' : 'Step' }}: {{ $a->title }}</span>
                    <form method="POST" action="{{ route('admin.timeline-defaults.restore', $a->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
