{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Template Manager → Company ads — spec .ai/specs/company-ads.md §5 (E-1), the AI pop-up §8 (E-2), Post now / Schedule §7 (E-3) --}}
@extends('layouts.corex')

{{-- Thumbnails and downloads are drawn by the SAME render kernel + fonts as the Ad Builder (ad-manager.md §12). --}}
@push('head')
    @include('corex.properties._ad-fonts')
@endpush

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['status'] !== 'active' || $f['creator'] !== 'all' || $f['occasion'] !== '' || $f['from'] || $f['to'];

    $sortUrl = function (string $key) use ($f) {
        $next = ($f['sort'] === $key) ? ($f['dir'] === 'asc' ? 'desc' : 'asc') : (in_array($key, ['created', 'updated'], true) ? 'desc' : 'asc');
        return route('tools.ad-manager.templates.company', array_filter(
            array_merge(request()->except('page'), ['sort' => $key, 'dir' => $next]),
            fn ($v) => $v !== null && $v !== ''
        ));
    };
    $arrow = fn (string $key) => $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $th = 'text-left text-[11px] font-semibold uppercase tracking-wider px-4 py-2.5 whitespace-nowrap';

    // Layouts for the rows on THIS page only, keyed by id — the browser draws thumbnails / downloads from them.
    $layouts = $ads->getCollection()->mapWithKeys(fn ($ad) => [$ad->id => ['id' => $ad->id, 'name' => $ad->name, 'layout' => $ad->layout_json, 'caption' => (string) $ad->caption]])->all();
@endphp

<div class="w-full space-y-5" x-data="companyAdsList(@js(['layouts' => $layouts, 'brand' => $brandData, 'post' => $postConfig]))" x-init="init()"
     x-on:company-ad-download.window="downloadLayout($event.detail)"
     x-on:company-ad-post.window="openPost($event.detail)">

    {{-- ── Page header (branded) ───────────────────────────────── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Template Manager</h1>
                <p class="text-sm text-white/60">Company ads — season's greetings, holiday closures, welcomes and other ads from your agency.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                @include('tools.ad-manager._switcher', ['inHeader' => true])
                @if($aiPanel)
                    <button type="button" class="corex-btn-outline text-sm px-4 py-2 inline-flex items-center gap-1.5 self-start md:self-auto"
                            data-cadai="open" @click="$dispatch('open-company-ad-ai')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
                        Create with AI
                    </button>
                @endif
                @if($canManage)
                    <a href="{{ route('tools.ad-manager.company-ads.builder') }}" class="corex-btn-primary text-sm px-4 py-2 inline-flex items-center gap-1.5 self-start md:self-auto">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        New company ad
                    </a>
                @endif
            </div>
        </div>
    </div>

    @include('tools.ad-manager._template-tabs')

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--success, #16a34a) 10%, transparent); color:var(--text-primary); border:1px solid color-mix(in srgb, var(--success, #16a34a) 35%, transparent);">{{ session('success') }}</div>
    @endif

    {{-- ── Search + filters ────────────────────────────────────── --}}
    <form method="GET" action="{{ route('tools.ad-manager.templates.company') }}"
          class="props-filter-panel rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-7 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $f['sort'] }}">
        <input type="hidden" name="dir" value="{{ $f['dir'] }}">

        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input class="list-header-filter {{ ($f['q'] !== '') ? 'is-active' : '' }} w-full" type="search" name="q" value="{{ $f['q'] }}" placeholder="Ad name, occasion or creator…">
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
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Occasion</span>
            <select class="list-header-filter {{ ($f['occasion'] !== '') ? 'is-active' : '' }} w-full" name="occasion">
                <option value="" @selected($f['occasion'] === '')>All occasions</option>
                @foreach($occasions as $key => $label)
                    <option value="{{ $key }}" @selected($f['occasion'] === $key)>{{ $label }}</option>
                @endforeach
                <option value="other" @selected($f['occasion'] === 'other')>Other</option>
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

        <div class="sm:col-span-2 lg:col-span-7 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)
                <a href="{{ route('tools.ad-manager.templates.company') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $ads->total() }} company ad{{ $ads->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    {{-- ── List ────────────────────────────────────────────────── --}}
    @if($ads->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364-6.364-1.414 1.414M7.05 16.95l-1.414 1.414m12.728 0-1.414-1.414M7.05 7.05 5.636 5.636M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
            </div>
            @if(! $agencyHasAny)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No company ads yet</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Make a Merry Christmas, Happy Easter or "closed for the holidays" ad in your agency's colours. There's a library of seasonal pictures you can use for free.</p>
                @if($canManage)
                    <a href="{{ route('tools.ad-manager.company-ads.builder') }}" class="corex-btn-primary text-sm px-4 py-2">Make your first company ad</a>
                @endif
                @if($aiPanel)
                    <button type="button" class="corex-btn-outline text-sm px-4 py-2 ml-2" @click="$dispatch('open-company-ad-ai')">Or let the AI design one</button>
                @endif
            @elseif($filtersActive)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ $f['status'] === 'archived' && $f['q'] === '' && ! $f['from'] && ! $f['to'] && $f['creator'] === 'all' && $f['occasion'] === '' ? 'No archived company ads' : 'No company ads match these filters' }}
                </h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters to see everything.</p>
                <a href="{{ route('tools.ad-manager.templates.company') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No active company ads</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Every company ad is archived. Switch the status filter to Archived to restore one.</p>
                <a href="{{ route('tools.ad-manager.templates.company', ['status' => 'archived']) }}" class="corex-btn-outline text-sm">View archived</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead style="background:var(--surface-2); color:var(--text-secondary);">
                    <tr>
                        <th class="{{ $th }}">Preview</th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('name') }}" style="color:inherit;">Name{{ $arrow('name') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('occasion') }}" style="color:inherit;">Occasion{{ $arrow('occasion') }}</a></th>
                        <th class="{{ $th }}">Size</th>
                        <th class="{{ $th }}">Created by</th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('created') }}" style="color:inherit;">Created{{ $arrow('created') }}</a></th>
                        <th class="{{ $th }}"><a href="{{ $sortUrl('updated') }}" style="color:inherit;">Last updated{{ $arrow('updated') }}</a></th>
                        <th class="{{ $th }} text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ads as $ad)
                        <tr style="border-top:1px solid var(--border); {{ $ad->trashed() ? 'opacity:.65;' : '' }}">
                            <td class="px-4 py-2">
                                <div class="adt-thumb" data-ad-thumb="{{ $ad->id }}" role="img" aria-label="Preview of {{ $ad->name }}"></div>
                            </td>
                            <td class="px-4 py-3 font-semibold">
                                {{ $ad->name }}
                                @if($ad->ai_generated)
                                    <span class="ds-badge ds-badge-pill ds-badge-info ml-1" title="Designed by the AI{{ $ad->ai_idea ? ' from the idea: ' . \Illuminate\Support\Str::limit($ad->ai_idea, 160) : '' }}">AI</span>
                                @endif
                                @if($ad->trashed())
                                    <span class="ds-badge ds-badge-pill ds-badge-default ml-1">Archived</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $ad->occasion_label }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $ad->canvas_label }}</td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $ad->user?->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);" title="{{ $ad->created_at?->format('Y-m-d H:i') }}">{{ $ad->created_at?->format('j M Y') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);" title="{{ $ad->updated_at?->format('Y-m-d H:i') }}">{{ $ad->updated_at?->format('j M Y') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    @unless($ad->trashed())
                                        <button type="button" class="corex-btn-outline text-xs" @click="download({{ $ad->id }})" :disabled="busy === {{ $ad->id }}"
                                                x-text="busy === {{ $ad->id }} ? 'Preparing…' : 'Download'">Download</button>
                                        @if($postConfig)
                                            <button type="button" class="corex-btn-outline text-xs" data-cadpost="open" @click="openPost(layouts[{{ $ad->id }}])">Post / Schedule</button>
                                        @endif
                                    @endunless
                                    @if($canManage)
                                        @if($ad->trashed())
                                            <form method="POST" action="{{ route('tools.ad-manager.company-ads.restore', $ad->id) }}">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                            </form>
                                        @else
                                            <a href="{{ route('tools.ad-manager.company-ads.builder.edit', $ad->id) }}" class="corex-btn-outline text-xs">Edit</a>
                                            <form method="POST" action="{{ route('tools.ad-manager.company-ads.duplicate', $ad->id) }}">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Duplicate</button>
                                            </form>
                                            <form method="POST" action="{{ route('tools.ad-manager.company-ads.archive', $ad->id) }}"
                                                  onsubmit="return confirm(@js('Archive "' . $ad->name . '"? It disappears from the list, but you can restore it from the Archived filter at any time.'));">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $ads->links() }}</div>
    @endif

    {{-- Off-screen stage the download is drawn on at the ad's real size (never visible, never in the layout flow). --}}
    <div class="cads-stage" aria-hidden="true"><div x-ref="stage"></div></div>

    {{-- E-3 — Post now / Schedule (company-ads.md §7). Above the AI pop-up, so it also works straight after saving a design there. --}}
    @if($postConfig)
    <template x-teleport="body">
        <div x-show="post.open" x-cloak class="cadpost-overlay" role="dialog" aria-modal="true" aria-labelledby="cadpost-title" data-cadpost="overlay"
             @keydown.escape.window="if (post.open) closePost()">
            <div class="cadpost-backdrop" @click="closePost()"></div>
            <div class="cadpost-dialog">
                <template x-if="!post.results && !post.scheduled">
                    <div class="space-y-4">
                        <div>
                            <h2 id="cadpost-title" class="text-base font-semibold" style="color:var(--text-primary);">Post or schedule “<span x-text="post.entry ? post.entry.name : ''"></span>”</h2>
                            <p class="text-xs mt-1" style="color:var(--text-muted);">It goes out on your own connected Page, with the picture of this ad and the text below. Times are {{ $postConfig['tzLabel'] }}.</p>
                        </div>

                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Post to</div>
                            <div class="flex flex-wrap gap-4">
                                @foreach($postConfig['platforms'] as $key => $label)
                                    <label class="inline-flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                        <input type="checkbox" x-model="post.platforms.{{ $key }}" @disabled(empty($postConfig['connected'][$key]))>
                                        {{ $label }}
                                        @if(empty($postConfig['connected'][$key]))<span class="text-xs" style="color:var(--ds-amber,#b45309);">not connected</span>@endif
                                    </label>
                                @endforeach
                            </div>
                            @if(collect($postConfig['connected'])->contains(false))
                                <p class="text-xs mt-2" style="color:var(--text-secondary);">
                                    A platform that says “not connected” cannot be used until you connect it. {{ $postConfig['connectHint'] }}
                                    <a href="{{ $postConfig['portalUrl'] }}" class="underline">Connect now</a>
                                </p>
                            @endif
                        </div>

                        <label class="block">
                            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Post text</span>
                            <textarea x-model="post.copy" rows="5" maxlength="2200" required class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" data-cadpost="copy"></textarea>
                            <span class="text-xs" style="color:var(--text-muted);" x-text="post.copy.length + ' / 2200'"></span>
                        </label>

                        <div class="space-y-1 text-sm" style="color:var(--text-primary);">
                            <label class="flex items-center gap-2"><input type="radio" value="now" x-model="post.when"> Post now</label>
                            <label class="flex items-center gap-2"><input type="radio" value="later" x-model="post.when"> Schedule for later</label>
                        </div>

                        <div class="grid grid-cols-2 gap-3" x-show="post.when === 'later'">
                            <label class="block">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Date</span>
                                <input type="date" x-model="post.date" :min="'{{ $postConfig['today'] }}'" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </label>
                            <label class="block">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Time</span>
                                <input type="time" x-model="post.time" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </label>
                            <p class="col-span-2 text-xs" style="color:var(--text-muted);">At least {{ $postConfig['minLead'] }} minutes from now and no more than {{ $postConfig['maxDays'] }} days ahead. You can edit or cancel it any time before it goes out, under Scheduled Posts.</p>
                        </div>

                        <div x-show="post.error" role="alert" class="rounded-md px-3 py-2 text-sm" style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);" x-text="post.error"></div>

                        <div class="flex items-center justify-end gap-2 flex-wrap">
                            <span class="text-xs mr-auto" style="color:var(--text-muted);" x-text="post.progress"></span>
                            <button type="button" class="corex-btn-outline text-sm" @click="closePost()" :disabled="post.busy">Close</button>
                            <button type="button" class="corex-btn-primary text-sm" @click="submitPost()" :disabled="post.busy">
                                <span x-show="!post.busy" x-text="post.when === 'now' ? 'Post now' : 'Schedule'"></span><span x-show="post.busy">Working…</span>
                            </button>
                        </div>
                    </div>
                </template>

                <template x-if="post.results">
                    <div class="space-y-3 py-2">
                        <h2 class="text-base font-semibold" style="color:var(--text-primary);" x-text="post.results.every(r => r.ok) ? 'Posted' : (post.results.some(r => r.ok) ? 'Partly posted' : 'Not posted')"></h2>
                        <ul class="text-sm space-y-2">
                            <template x-for="r in post.results" :key="r.platform">
                                <li style="color:var(--text-primary);">
                                    <strong x-text="r.platform"></strong>:
                                    <span x-show="r.ok" style="color:var(--ds-green,#059669);">it is live on your Page.</span>
                                    <span x-show="!r.ok" x-text="r.error"></span>
                                </li>
                            </template>
                        </ul>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="corex-btn-outline text-sm" @click="closePost()">Close</button>
                            <a href="{{ $postConfig['listUrl'] }}" class="corex-btn-primary text-sm">Open Scheduled Posts</a>
                        </div>
                    </div>
                </template>

                <template x-if="post.scheduled">
                    <div class="space-y-3 py-2 text-center">
                        <h2 class="text-base font-semibold" style="color:var(--text-primary);"><span x-text="post.scheduled"></span> post<span x-text="post.scheduled === 1 ? '' : 's'"></span> scheduled</h2>
                        <p class="text-sm" style="color:var(--text-secondary);">CoreX will publish it at the time you chose. You can see, edit or cancel it under Scheduled Posts.</p>
                        <div class="flex justify-center gap-2">
                            <button type="button" class="corex-btn-outline text-sm" @click="closePost()">Close</button>
                            <a href="{{ $postConfig['listUrl'] }}" class="corex-btn-primary text-sm">Open Scheduled Posts</a>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
    @endif
</div>

<style>
    .cads-stage { position: fixed; left: -100000px; top: 0; pointer-events: none; }
    .cads-stage > div { position: relative; overflow: hidden; }
    .cadpost-overlay { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: 16px; }
    .cadpost-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); }
    .cadpost-dialog { position: relative; width: 100%; max-width: 560px; max-height: 90vh; max-height: 90dvh; overflow-y: auto; border-radius: 8px; padding: 20px;
        background: var(--surface); border: 1px solid var(--border); box-shadow: 0 20px 50px rgba(0, 0, 0, .35); }
</style>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
@include('tools.ad-manager._ad-preview-thumbs')
<script>
function companyAdsList(cfg) {
    return {
        layouts: cfg.layouts || {},
        brand: cfg.brand || {},
        busy: null,
        postCfg: cfg.post || null,
        post: { open: false, entry: null, platforms: { facebook: false, instagram: false }, copy: '', when: 'now', date: '', time: '',
                busy: false, error: '', progress: '', results: null, scheduled: 0 },

        init() {
            this.$nextTick(() => {
                window.corexDrawAdThumbs(this.layouts, this.brand, { placeholders: false });
            });
        },

        /** Draw a row's ad scaled down into its 96 px box, letterboxed to keep the aspect. */

        /** A row's Download button. */
        download(id) {
            return this.downloadLayout(this.layouts[id], id);
        },

        /** Draw an ad ({name, layout}) at its real size off-screen and save it as a PNG. Also used by the AI pop-up. */
        /** Draw an ad's layout off-screen at its real size and capture it (scale 1 = real pixels). The one capture path. */
        async capture(layout, scale) {
            const stage = this.$refs.stage;
            let restore = () => {};
            try {
                const l = layout || {};
                stage.style.width = (l.canvasW || 1200) + 'px';
                stage.style.height = (l.canvasH || 628) + 'px';
                window.CoreXAd.renderLayout(l, this.brand, stage, { paintBackground: true });
                if (document.fonts?.ready) { try { await document.fonts.ready; } catch (_) {} }
                await Promise.all([...stage.querySelectorAll('img')].map(img => img.complete ? null : new Promise(r => { img.onload = img.onerror = r; })));
                try { restore = await window.CoreXAd.prepareImagesForCapture(stage); } catch (_) {}
                return await html2canvas(stage, {
                    useCORS: true, allowTaint: false, scale: scale, logging: false,
                    backgroundColor: window.CoreXAd.canvasBgSolid(l),
                });
            } finally {
                restore();
                stage.innerHTML = '';
            }
        },

        async downloadLayout(entry, busyKey = 'ai') {
            if (!entry || !entry.layout || this.busy) return;
            this.busy = busyKey;
            try {
                const canvas = await this.capture(entry.layout, 1);
                const a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = (entry.name || 'company-ad').replace(/[^a-z0-9\- ]+/gi, '').trim().replace(/\s+/g, '-').toLowerCase() + '.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
            } catch (e) {
                alert('The picture could not be prepared. Please try again.');
            } finally {
                this.busy = null;
            }
        },

        // ── Post now / Schedule (company-ads.md §7) ──────────────────────────────
        openPost(entry) {
            if (!this.postCfg || !entry || !entry.layout) return;
            const p = this.post, c = this.postCfg.connected || {};
            Object.assign(p, { open: true, entry, copy: entry.caption || '', when: 'now', busy: false, error: '', progress: '', results: null, scheduled: 0 });
            p.platforms = { facebook: !!c.facebook, instagram: !c.facebook && !!c.instagram };
            if (!p.date) { const d = new Date(this.postCfg.today + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + 1); p.date = d.toISOString().slice(0, 10); }
            if (!p.time) p.time = '09:00';
            window.__cadPostOpen = true;
        },
        closePost() {
            if (this.post.busy) return;
            this.post.open = false;
            window.__cadPostOpen = false;
        },
        reason(err, fallback) {
            const d = (err && err.data && typeof err.data === 'object') ? err.data : {};
            const first = d.errors ? Object.values(d.errors)[0][0] : null;
            return d.error || first || d.message || fallback;
        },
        async submitPost() {
            const p = this.post, cfg = this.postCfg;
            const platforms = Object.keys(p.platforms).filter(k => p.platforms[k]);
            p.error = '';
            if (!platforms.length) { p.error = 'Tick at least one platform to post to.'; return; }
            if (!p.copy.trim()) { p.error = 'Every post needs some text.'; return; }
            if (p.when === 'later' && (!p.date || !p.time)) { p.error = 'Choose a date and a time.'; return; }
            p.busy = true;
            try {
                p.progress = 'Preparing the picture…';
                const l = p.entry.layout || {};
                // Same picture rules as scheduling from the Ad Manager: a JPEG, never wider than Instagram's 1440px.
                const canvas = await this.capture(l, Math.min(2, 1440 / (l.canvasW || 1200)));
                const image = canvas.toDataURL('image/jpeg', 0.92);
                let up;
                try {
                    up = await window.CoreX.api.fetch(cfg.imageUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image }) });
                } catch (err) { throw new Error(this.reason(err, 'The picture could not be saved. Please try again.')); }
                if (!up.ok) throw new Error(up.error || 'The picture could not be saved. Please try again.');

                p.progress = p.when === 'now' ? 'Posting…' : 'Scheduling…';
                const body = { items: [{ company_ad_id: p.entry.id, copy: p.copy, image_path: up.path }], platforms, mode: p.when === 'now' ? 'now' : 'at' };
                if (p.when === 'later') { body.date = p.date; body.time = p.time; }
                let data;
                try {
                    data = await window.CoreX.api.fetch(cfg.storeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
                } catch (err) { throw new Error(this.reason(err, 'Could not post. Please try again.')); }
                if (!data.ok) throw new Error(data.error || 'Could not post. Please try again.');
                if (p.when === 'now') p.results = data.results || []; else p.scheduled = data.scheduled || 0;
            } catch (e) {
                p.error = e.message || 'Could not post. Please try again.';
            } finally {
                p.busy = false; p.progress = '';
            }
        },
    };
}
</script>

@if($aiPanel)
    @include('tools.ad-manager._company-ad-ai-modal')
@endif
@endsection
