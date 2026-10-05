{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Agency timeline defaults (AT-447).
     Merge fields are written @{{ like_this }} so Blade does not evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('admin.dev-settings.index') }}" class="text-xs underline" style="color:var(--text-muted);">← Dev Settings</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Agency timeline defaults</h1>
        <p class="text-xs" style="color: var(--text-muted);">The plan every <strong>new</strong> agency timeline starts from. Each step has a number of days after the start date. Changing a default never changes a timeline that is already running — those keep their own copy.</p>
        <p class="text-xs mt-1" style="color: var(--text-muted);">In any text you can use @{{ agency_name }}, @{{ start_date }}, @{{ go_live_date }} and @{{ billing_start_date }}.</p>
    </div>
    @include('admin.partials.platform-flash')

    {{-- Steps --}}
    <div class="rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Default steps</div>
            <details class="relative"><summary class="corex-btn-outline text-xs cursor-pointer list-none">+ Add step</summary>
                @include('admin.dev-settings._timeline-default-form', ['kind' => 'milestone', 'item' => null, 'triggers' => $triggers, 'floating' => true])
            </details>
        </div>
        @forelse($milestones as $m)
            <div class="px-4 py-3 flex flex-col md:flex-row md:items-start gap-3" style="border-top:1px solid var(--border);">
                <div class="md:w-24 flex-shrink-0 text-sm tabular-nums font-semibold" style="color:var(--brand-icon);">Day +{{ $m->offset_days }}</div>
                <div class="flex-1 min-w-0">
                    <div class="font-medium" style="color:var(--text-primary);">{{ $m->title }}
                        @if($m->is_go_live)<span class="ds-badge ds-badge-success ml-1">Go live</span>@endif
                        @unless($m->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden from agency</span>@endunless
                        @if($m->auto_complete_trigger)<span class="ds-badge ds-badge-muted ml-1">Auto: {{ $triggers[$m->auto_complete_trigger] ?? $m->auto_complete_trigger }}</span>@endif
                    </div>
                    @if($m->body)<div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $m->body }}</div>@endif
                    <details class="mt-2"><summary class="text-xs underline cursor-pointer" style="color:var(--brand-icon);">Edit</summary>
                        @include('admin.dev-settings._timeline-default-form', ['kind' => 'milestone', 'item' => $m, 'triggers' => $triggers, 'floating' => false])
                    </details>
                </div>
                <div class="flex items-start gap-2 text-xs">
                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $m->id) }}">@csrf<input type="hidden" name="direction" value="up"><button class="px-1" title="Move up (same-day order)" style="color:var(--text-muted);">▲</button></form>
                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $m->id) }}">@csrf<input type="hidden" name="direction" value="down"><button class="px-1" style="color:var(--text-muted);">▼</button></form>
                    <form method="POST" action="{{ route('admin.timeline-defaults.destroy', $m->id) }}" onsubmit="return confirm('Archive this default step? New timelines will not include it. You can restore it.');">@csrf @method('DELETE')<button class="underline" style="color:var(--ds-crimson);">Archive</button></form>
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No default steps yet. Add the first one.</div>
        @endforelse
    </div>

    {{-- Sections --}}
    <div class="rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Default information sections</div>
            <details class="relative"><summary class="corex-btn-outline text-xs cursor-pointer list-none">+ Add section</summary>
                @include('admin.dev-settings._timeline-default-form', ['kind' => 'block', 'item' => null, 'triggers' => $triggers, 'floating' => true])
            </details>
        </div>
        @forelse($blocks as $b)
            <div class="px-4 py-3 flex flex-col md:flex-row gap-3" style="border-top:1px solid var(--border);">
                <div class="flex-1 min-w-0">
                    <div class="font-medium" style="color:var(--text-primary);">{{ $b->title }} @unless($b->is_public)<span class="ds-badge ds-badge-default ml-1">Hidden from agency</span>@endunless</div>
                    @if($b->body)<div class="text-xs mt-0.5 whitespace-pre-line" style="color:var(--text-muted);">{{ \Illuminate\Support\Str::limit($b->body, 300) }}</div>@endif
                    <details class="mt-2"><summary class="text-xs underline cursor-pointer" style="color:var(--brand-icon);">Edit</summary>
                        @include('admin.dev-settings._timeline-default-form', ['kind' => 'block', 'item' => $b, 'triggers' => $triggers, 'floating' => false])
                    </details>
                </div>
                <div class="flex items-start gap-2 text-xs">
                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="up"><button class="px-1" style="color:var(--text-muted);">▲</button></form>
                    <form method="POST" action="{{ route('admin.timeline-defaults.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="down"><button class="px-1" style="color:var(--text-muted);">▼</button></form>
                    <form method="POST" action="{{ route('admin.timeline-defaults.destroy', $b->id) }}" onsubmit="return confirm('Archive this default section? You can restore it.');">@csrf @method('DELETE')<button class="underline" style="color:var(--ds-crimson);">Archive</button></form>
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">No default sections yet.</div>
        @endforelse
    </div>

    @if($archivedCount)
        <div class="text-xs"><a class="underline" style="color:var(--text-muted);" href="{{ request()->fullUrlWithQuery(['archived' => request()->boolean('archived') ? 0 : 1]) }}">{{ request()->boolean('archived') ? 'Hide' : 'Show' }} archived ({{ $archivedCount }})</a></div>
        @foreach($archived as $a)
            <div class="rounded-md px-4 py-2.5 flex items-center justify-between text-sm" style="background: var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                <span>{{ $a->kind === 'block' ? 'Section' : 'Step' }}: {{ $a->title }}</span>
                <form method="POST" action="{{ route('admin.timeline-defaults.restore', $a->id) }}">@csrf<button class="underline text-xs" style="color:var(--brand-icon);">Restore</button></form>
            </div>
        @endforeach
    @endif
</div>
@endsection
