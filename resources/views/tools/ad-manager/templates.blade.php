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
    $inputStyle = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
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

    {{-- ── Search + filters ────────────────────────────────────── --}}
    <form method="GET" action="{{ route('tools.ad-manager.templates') }}"
          class="rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $f['sort'] }}">
        <input type="hidden" name="dir" value="{{ $f['dir'] }}">

        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="Template name or creator…"
                   class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Status</span>
            <select name="status" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                <option value="active"   @selected($f['status'] === 'active')>Active</option>
                <option value="archived" @selected($f['status'] === 'archived')>Archived</option>
                <option value="all"      @selected($f['status'] === 'all')>All</option>
            </select>
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Created</span>
            <select name="creator" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                <option value="all"  @selected($f['creator'] === 'all')>Everyone</option>
                <option value="mine" @selected($f['creator'] === 'mine')>Only mine</option>
                <option value="ai"   @selected($f['creator'] === 'ai')>AI-made</option>
            </select>
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Updated from</span>
            <input type="date" name="from" value="{{ $f['from'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
        </label>

        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Updated to</span>
            <input type="date" name="to" value="{{ $f['to'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
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
                            <td class="px-4 py-3 font-semibold">
                                {{ $t->name }}
                                @if($t->ai_generated)
                                    <span class="ml-1 text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded inline-flex items-center gap-1"
                                          style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 14%, transparent); color:var(--brand-icon,#0ea5e9);"
                                          title="Designed by the AI Template Builder{{ $t->ai_idea ? ' from the idea: ' . \Illuminate\Support\Str::limit($t->ai_idea, 160) : '' }}">AI</span>
                                @endif
                                @if($t->trashed())
                                    <span class="ml-1 text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:var(--surface-2); color:var(--text-muted);">Archived</span>
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

{{-- ══ Create with AI (AT-456 Part B) ═══════════════════════════════════════ --}}
@if($aiPanel)
<script src="{{ asset_v('js/corex-ad-render.js') }}"></script>
<div x-data="aiTemplatePanel(@js([
        'usage'    => $aiPanel['usage'],
        'sample'   => $aiPanel['sample'],
        'styles'   => $aiPanel['styles'],
        'templateCount' => $aiPanel['templateCount'],
        'generate' => route('api.v1.ad-manager.templates.ai.generate', [], false),
        'save'     => route('api.v1.ad-manager.templates.ai.save', ['generation' => '__GEN__'], false),
    ]))"
     x-on:open-ai-template-panel.window="openPanel()"
     x-on:keydown.escape.window="if (open && !loading) closePanel()"
     x-show="open" style="display:none;"
     class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-label="Create a template with AI">
    <div class="fixed inset-0" style="background:rgba(0,0,0,.55);" @click="if (!loading) closePanel()"></div>

    <div class="relative mx-auto my-6 w-[min(1100px,94vw)] rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">Create a template with AI</h2>
                <p class="text-sm" style="color:var(--text-muted);">Describe the look you want. You get three different designs to choose from — keep the ones you like, then fine-tune them in the Ad Builder.</p>
            </div>
            <button type="button" class="corex-btn-outline text-xs" :disabled="loading" @click="closePanel()">Close</button>
        </div>

        {{-- The idea form --}}
        <form @submit.prevent="generate()" class="grid gap-3 md:grid-cols-6 items-start">
            <label class="md:col-span-6 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Your idea</span>
                <textarea x-model="idea" maxlength="1000" rows="3" required :disabled="loading"
                          placeholder="e.g. A bold, modern look for new listings — big photo, price in a strong colour bar, agent photo bottom right."
                          class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}"></textarea>
                <span class="text-xs" style="color:var(--text-muted);" x-text="idea.length + ' / 1000'"></span>
            </label>
            <label class="md:col-span-2 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Ad size</span>
                <select x-model="size" :disabled="loading" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    @foreach($aiPanel['sizes'] as $sz)
                        <option value="{{ $sz['key'] }}">{{ $sz['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="md:col-span-2 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Mainly for (optional)</span>
                <select x-model="ptype" :disabled="loading" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    <option value="">Any property type</option>
                    @foreach($aiPanel['propertyTypes'] as $pt)
                        <option value="{{ $pt }}">{{ $pt }}</option>
                    @endforeach
                </select>
            </label>
            <label class="md:col-span-2 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Reference image (optional)</span>
                <input type="file" accept="image/jpeg,image/png" :disabled="loading" @change="pickFile($event)"
                       class="w-full text-xs" style="color:var(--text-secondary);">
                <span class="text-xs" style="color:var(--text-muted);">JPG or PNG, up to 5 MB. Used for inspiration only and deleted after 7 days.</span>
                <span class="block text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);" x-show="fileError" x-text="fileError"></span>
            </label>

            <label class="md:col-span-3 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Style</span>
                <select x-model="style" :disabled="loading" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    <option value="auto">Let the AI choose</option>
                    @foreach($aiPanel['styles'] as $st)
                        <option value="{{ $st['key'] }}">{{ $st['name'] }}</option>
                    @endforeach
                </select>
                <span class="text-xs" style="color:var(--text-muted);" x-text="styleHint()"></span>
            </label>
            {{-- Only offered when the agency has at least one saved, non-archived template (ticked by default then). --}}
            @if(($aiPanel['templateCount'] ?? 0) > 0)
            <div class="md:col-span-3 block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Your own look</span>
                <label class="flex items-start gap-2 text-sm cursor-pointer">
                    <input type="checkbox" x-model="match" :disabled="loading" class="mt-0.5">
                    <span>Match our existing templates
                        <span class="block text-xs" style="color:var(--text-muted);">The AI looks at up to three of your agency's saved templates and designs in the same spirit. It adds a little to the cost of each generation.</span>
                    </span>
                </label>
            </div>
            @endif

            <div class="md:col-span-6 flex flex-wrap items-center gap-3">
                <button type="submit" class="corex-btn-primary text-sm px-4 py-2" :disabled="loading || usage.remaining <= 0 || idea.trim() === ''">
                    <span x-show="!loading">Design 3 options</span>
                    <span x-show="loading">Designing…</span>
                </button>
                <span class="text-sm" style="color:var(--text-secondary);" x-show="usage.remaining > 0"
                      x-text="usage.remaining + ' of ' + usage.limit + ' generations left this month'"></span>
                <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.remaining <= 0 && usage.limit > 0"
                      x-text="'You have used all ' + usage.limit + ' generations this month. They reset on ' + usage.resets_on + '.'"></span>
                <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.limit <= 0">AI template generation is switched off for your agency.</span>
            </div>
        </form>

        <div x-show="loading" class="rounded-md px-4 py-3 text-sm" style="background:var(--surface-2); border:1px solid var(--border);">
            Designing your three templates — this usually takes about a minute. Please keep this window open.
        </div>

        <div x-show="error" class="rounded-md px-4 py-3 text-sm" role="alert"
             style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 35%, transparent);"
             x-text="error"></div>

        {{-- The three options --}}
        <div x-show="options.length" class="space-y-2">
            <h3 class="text-sm font-semibold">Pick the ones you like</h3>
            <div class="grid gap-4 md:grid-cols-3">
                <template x-for="opt in options" :key="generationId + '-' + opt.index">
                    <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="rounded overflow-hidden" style="background:#111;" x-init="$nextTick(() => renderPreview($el, opt))">
                            <div></div>
                        </div>
                        <div class="text-xs" style="color:var(--text-secondary);">
                            Style: <span class="font-semibold" x-text="opt.style_name || 'not named'"></span>
                        </div>
                        <input type="text" x-model="opt.name" maxlength="100" :disabled="opt.saved || opt.saving"
                               class="w-full rounded-md px-3 py-1.5 text-sm" style="{{ $inputStyle }}" aria-label="Template name">
                        <div class="text-xs" style="color:var(--ds-crimson,#c41e3a);" x-show="opt.err" x-text="opt.err"></div>
                        <div class="flex items-center gap-2" x-show="!opt.saved">
                            <button type="button" class="corex-btn-primary text-xs px-3 py-1.5" :disabled="opt.saving" @click="save(opt)">
                                <span x-show="!opt.saving">Save this template</span><span x-show="opt.saving">Saving…</span>
                            </button>
                        </div>
                        <div class="flex items-center gap-2 text-xs" x-show="opt.saved">
                            <span style="color:var(--ds-green,#059669);">Saved to your templates.</span>
                            <a :href="opt.editUrl" class="corex-btn-outline text-xs">Open in Ad Builder</a>
                        </div>
                    </div>
                </template>
            </div>
            <p class="text-xs" style="color:var(--text-muted);">Previews use sample photos and details, with your agency's own name and logo. Real listings are filled in when you make an ad.</p>
        </div>
    </div>
</div>

<script>
function aiTemplatePanel(cfg) {
    return {
        open: false, idea: '', size: 'facebook', ptype: '', file: null, fileError: '',
        style: 'auto', styles: cfg.styles || [],
        hasTemplates: (cfg.templateCount || 0) > 0, match: (cfg.templateCount || 0) > 0,
        usage: cfg.usage, sample: cfg.sample,
        loading: false, error: '', generationId: null, options: [], savedAny: false,

        openPanel() { this.open = true; document.body.style.overflow = 'hidden'; },
        closePanel() {
            this.open = false; document.body.style.overflow = '';
            // Newly saved templates only appear in the table after a reload.
            if (this.savedAny) window.location.reload();
        },
        styleHint() {
            if (this.style === 'auto') return 'The AI picks the best of our eight styles for each design, and tells you which.';
            const s = this.styles.find(x => x.key === this.style);
            return s ? s.summary : '';
        },
        pickFile(e) {
            this.fileError = ''; this.file = null;
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            if (!/^image\/(jpeg|png)$/.test(f.type)) { this.fileError = 'Please choose a JPG or PNG image.'; e.target.value = ''; return; }
            if (f.size > 5 * 1024 * 1024) { this.fileError = 'That image is larger than 5 MB.'; e.target.value = ''; return; }
            this.file = f;
        },
        async generate() {
            if (this.loading) return;
            this.loading = true; this.error = ''; this.options = []; this.generationId = null;
            const body = new FormData();
            body.append('idea', this.idea); body.append('ad_size', this.size);
            if (this.ptype) body.append('property_type', this.ptype);
            body.append('style', this.style);
            body.append('match_existing', (this.hasTemplates && this.match) ? '1' : '0');
            if (this.file) body.append('reference_image', this.file);
            try {
                // window.CoreX.api.fetch adds the CSRF token + JSON headers and throws on a non-2xx (error.status / error.data).
                const data = await window.CoreX.api.fetch(cfg.generate, { method: 'POST', body });
                if (data.usage) this.usage = data.usage;
                if (!data.ok) {
                    this.error = data.message || 'Something went wrong. Nothing was counted — please try again.';
                } else {
                    this.generationId = data.generation_id;
                    this.options = data.options.map(o => ({ ...o, saving: false, saved: false, editUrl: '', err: '' }));
                }
            } catch (e) {
                const data = (e && e.data && typeof e.data === 'object') ? e.data : null;
                if (data && data.usage) this.usage = data.usage;
                if (e && e.status === 429 && !(data && data.usage)) {
                    // The request throttle's own "Too Many Attempts." (no usage payload). The monthly-limit 429 carries usage + its own message and is shown below.
                    this.error = 'You are going a little fast — wait a minute and try again.';
                } else if (data) {
                    this.error = data.message
                        || (data.errors ? Object.values(data.errors).flat().join(' ') : '')
                        || 'Something went wrong. Nothing was counted — please try again.';
                } else if (e && e.status) {
                    this.error = e.status === 429 ? 'You are going a little fast — wait a minute and try again.' : 'Something went wrong. Nothing was counted — please try again.';
                } else {
                    this.error = 'We could not reach the server. Nothing was counted — please try again.';
                }
            } finally { this.loading = false; }
        },
        renderPreview(box, opt) {
            const l = opt.layout, inner = box.firstElementChild;
            if (!inner || !window.CoreXAd) return;
            const s = (box.clientWidth || 320) / l.canvasW;
            box.style.height = Math.round(l.canvasH * s) + 'px';
            inner.style.cssText = 'position:relative;width:' + l.canvasW + 'px;height:' + l.canvasH + 'px;overflow:hidden;transform-origin:top left;transform:scale(' + s + ');';
            window.CoreXAd.renderLayout(l, this.sample, inner, { paintBackground: true, placeholders: true });
        },
        async save(opt) {
            opt.saving = true; opt.err = '';
            try {
                const data = await window.CoreX.api.fetch(cfg.save.replace('__GEN__', this.generationId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ option: opt.index, name: opt.name }),
                });
                if (data.ok) { opt.saved = true; opt.editUrl = data.edit_url; this.savedAny = true; }
                else { opt.err = data.message || 'Could not save this template — please try again.'; }
            } catch (e) {
                if (e && e.data && typeof e.data === 'object') { opt.err = e.data.message || 'Could not save this template — please try again.'; }
                else if (e && e.status) { opt.err = 'Could not save this template — please try again.'; }
                else { opt.err = 'We could not reach the server — please try again.'; }
            }
            finally { opt.saving = false; }
        },
    };
}
</script>
@endif
@endsection
