{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Template Manager — spec .ai/specs/ad-manager.md §19 --}}
@extends('layouts.corex')

{{-- AT-456 Part B: the "Create with AI" previews use the SAME render kernel + fonts as the Ad Builder (ad-manager.md §12). --}}
@push('head')
    @include('corex.properties._ad-fonts')
@endpush

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['status'] !== 'active' || $f['creator'] !== 'all' || $f['from'] || $f['to'];

    // Clickable column headers: same column toggles direction, a new column starts at its natural direction.
    $sortUrl = function (string $key) use ($f) {
        $next = ($f['sort'] === $key) ? ($f['dir'] === 'asc' ? 'desc' : 'asc') : (in_array($key, ['created', 'updated'], true) ? 'desc' : 'asc');
        return route('tools.ad-manager.templates', array_filter(
            array_merge(request()->except('page'), ['sort' => $key, 'dir' => $next]),
            fn ($v) => $v !== null && $v !== ''
        ));
    };
    $arrow = fn (string $key) => $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $th = 'text-left text-[11px] font-semibold uppercase tracking-wider px-4 py-2.5 whitespace-nowrap';

    // The Default design of the rows on THIS page only, keyed by id — the browser draws the previews from them.
    $previewLayouts = $templates->getCollection()->mapWithKeys(function ($t) {
        $layout = (array) $t->layout_json;
        unset($layout['variants']);

        return [$t->id => ['layout' => $layout]];
    })->all();
@endphp

<div class="w-full space-y-5">

    {{-- ── Page header (branded) ───────────────────────────────── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Template Manager</h1>
                <p class="text-sm text-white/60">Create, edit and manage your agency's custom ad templates.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                @include('tools.ad-manager._switcher', ['inHeader' => true])
                @if($aiPanel)
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-ai-template-panel'))" class="corex-btn-outline text-sm px-4 py-2 inline-flex items-center gap-1.5 self-start md:self-auto">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/></svg>
                        Create with AI
                    </button>
                @endif
                @if($canBuild)
                    <a href="{{ route('corex.ad-templates.builder', ['from' => 'templates']) }}" class="corex-btn-primary text-sm px-4 py-2 inline-flex items-center gap-1.5 self-start md:self-auto">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        New Template
                    </a>
                @endif
            </div>
        </div>
    </div>

    @include('tools.ad-manager._template-tabs')

    {{-- ── Search + filters ────────────────────────────────────── --}}
    <form method="GET" action="{{ route('tools.ad-manager.templates') }}"
          class="props-filter-panel rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $f['sort'] }}">
        <input type="hidden" name="dir" value="{{ $f['dir'] }}">

        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input class="list-header-filter {{ ($f['q'] !== '') ? 'is-active' : '' }} w-full" type="search" name="q" value="{{ $f['q'] }}" placeholder="Template name or creator…"
                  >
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Status</span>
            <select class="list-header-filter {{ ($f['status'] !== 'active') ? 'is-active' : '' }} w-full" name="status">
                <option value="active"   @selected($f['status'] === 'active')>Active</option>
                <option value="archived" @selected($f['status'] === 'archived')>Archived</option>
                <option value="all"      @selected($f['status'] === 'all')>All statuses</option>
            </select>
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Created</span>
            <select class="list-header-filter {{ ($f['creator'] !== 'all') ? 'is-active' : '' }} w-full" name="creator">
                <option value="all"  @selected($f['creator'] === 'all')>Everyone</option>
                <option value="mine" @selected($f['creator'] === 'mine')>Only mine</option>
                <option value="ai"   @selected($f['creator'] === 'ai')>AI-made</option>
            </select>
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Updated from</span>
            <input class="list-header-filter {{ (!empty($f['from'])) ? 'is-active' : '' }} w-full" type="date" name="from" value="{{ $f['from'] }}">
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Updated to</span>
            <input class="list-header-filter {{ (!empty($f['to'])) ? 'is-active' : '' }} w-full" type="date" name="to" value="{{ $f['to'] }}">
        </label>

        <div class="sm:col-span-2 lg:col-span-6 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)
                <a href="{{ route('tools.ad-manager.templates') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $templates->total() }} template{{ $templates->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    {{-- ── List ────────────────────────────────────────────────── --}}
    @if($templates->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M3 9h18M9 21V9"/></svg>
            </div>
            @if(! $agencyHasAny)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No custom templates yet</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Design a template once and reuse it on every listing. The ready-made CoreX designs stay available in the meantime.</p>
                @if($canBuild)
                    <a href="{{ route('corex.ad-templates.builder', ['from' => 'templates']) }}" class="corex-btn-primary text-sm px-4 py-2">Create your first template</a>
                @endif
            @elseif($filtersActive)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ $f['status'] === 'archived' && $f['q'] === '' && ! $f['from'] && ! $f['to'] && $f['creator'] === 'all' ? 'No archived templates' : 'No templates match these filters' }}
                </h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters to see everything.</p>
                <a href="{{ route('tools.ad-manager.templates') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No active templates</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Every template is archived. Switch the status filter to Archived to restore one.</p>
                <a href="{{ route('tools.ad-manager.templates', ['status' => 'archived']) }}" class="corex-btn-outline text-sm">View archived</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead style="background:var(--surface-2); color:var(--text-secondary);">
                    <tr>
                        <th class="{{ $th }}">Preview</th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('name') }}" style="color:inherit;">Name{{ $arrow('name') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('creator') }}" style="color:inherit;">Created by{{ $arrow('creator') }}</a></th>
                        <th class="{{ $th }}">Canvas</th>
                        <th class="{{ $th }}">Designs</th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('created') }}" style="color:inherit;">Created{{ $arrow('created') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('updated') }}" style="color:inherit;">Last updated{{ $arrow('updated') }}</a></th>
                        <th class="{{ $th }} text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $t)
                        <tr style="border-top:1px solid var(--border); {{ $t->trashed() ? 'opacity:.65;' : '' }}">
                            <td class="px-4 py-2">
                                <div class="adt-thumb" data-ad-thumb="{{ $t->id }}" role="img" aria-label="Preview of {{ $t->name }}"></div>
                            </td>
                            <td class="px-4 py-3 font-semibold">
                                {{ $t->name }}
                                @if($t->ai_generated)
                                    <span class="ds-badge ds-badge-pill ds-badge-info ml-1" title="Designed by the AI Template Builder{{ $t->ai_idea ? ' from the idea: ' . \Illuminate\Support\Str::limit($t->ai_idea, 160) : '' }}">AI</span>
                                    @if($t->human_edited_at)
                                        <span class="ds-badge ds-badge-pill ds-badge-default ml-0.5" title="A person has edited this since the AI made it ({{ $t->human_edited_at->format('j M Y H:i') }})">edited</span>
                                    @endif
                                @endif
                                @if($t->trashed())
                                    <span class="ds-badge ds-badge-pill ds-badge-default ml-1">Archived</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $t->user?->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $t->canvas_label }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">
                                Default{{ $t->variant_count ? ' + ' . $t->variant_count . ' property-type variant' . ($t->variant_count === 1 ? '' : 's') : '' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);" title="{{ $t->created_at?->format('Y-m-d H:i') }}">{{ $t->created_at?->format('j M Y') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);" title="{{ $t->updated_at?->format('Y-m-d H:i') }}">{{ $t->updated_at?->format('j M Y') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    @if($t->can_manage)
                                        @if($t->trashed())
                                            <form method="POST" action="{{ route('tools.ad-manager.templates.restore', $t->id) }}">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                            </form>
                                        @else
                                            @if($canBuild)
                                                <a href="{{ route('corex.ad-templates.builder.edit', [$t->id, 'from' => 'templates']) }}" class="corex-btn-outline text-xs">Edit</a>
                                            @endif
                                            <form method="POST" action="{{ route('tools.ad-manager.templates.archive', $t->id) }}"
                                                  onsubmit="return confirm(@js('Archive "' . $t->name . '"? It disappears from the template pickers, but you can restore it from the Archived filter at any time.'));">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-xs" style="color:var(--text-muted);" title="Only the creator or someone with template-management rights can change this template.">View only</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $templates->links() }}</div>
    @endif
</div>

@include('tools.ad-manager._ad-preview-thumbs')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // A made-up listing (demo photos) in the user's own agency's brand; placeholders show an element's sample text
        // where the sample listing has no value (e.g. a rental-only fact on a sale template).
        window.corexDrawAdThumbs(@js($previewLayouts), @js($previewData), { placeholders: true });
    });
</script>

{{-- ══ Create with AI (AT-456 Part B; pop-up rebuilt in §B.10) ═══════════════════════════ --}}
@if($aiPanel)
    @include('tools.ad-manager._ai-template-modal')
@endif
@endsection
