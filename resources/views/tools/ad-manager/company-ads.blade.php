{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Template Manager → Company ads — spec .ai/specs/company-ads.md §5 (E-1) and the AI pop-up §8 (E-2) --}}
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
    $layouts = $ads->getCollection()->mapWithKeys(fn ($ad) => [$ad->id => ['name' => $ad->name, 'layout' => $ad->layout_json]])->all();
@endphp

<div class="w-full space-y-5" x-data="companyAdsList(@js(['layouts' => $layouts, 'brand' => $brandData]))" x-init="init()"
     x-on:company-ad-download.window="downloadLayout($event.detail)">

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
                                <div class="cads-thumb" data-ad-thumb="{{ $ad->id }}" aria-hidden="true"></div>
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
</div>

<style>
    .cads-thumb { width: 96px; height: 96px; border-radius: 6px; overflow: hidden; position: relative; background: var(--surface-2); border: 1px solid var(--border); }
    .cads-thumb > .cads-thumb-inner { position: absolute; left: 0; top: 0; transform-origin: 0 0; }
    .cads-stage { position: fixed; left: -100000px; top: 0; pointer-events: none; }
    .cads-stage > div { position: relative; overflow: hidden; }
</style>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="{{ asset_v('js/corex-ad-render.js') }}"></script>
<script>
function companyAdsList(cfg) {
    return {
        layouts: cfg.layouts || {},
        brand: cfg.brand || {},
        busy: null,

        init() {
            this.$nextTick(() => {
                document.querySelectorAll('[data-ad-thumb]').forEach(box => this.thumb(box));
            });
        },

        /** Draw a row's ad scaled down into its 96 px box, letterboxed to keep the aspect. */
        thumb(box) {
            const entry = this.layouts[box.dataset.adThumb];
            if (!entry || !window.CoreXAd) return;
            const l = entry.layout || {};
            const w = l.canvasW || 1200, h = l.canvasH || 628;
            const scale = Math.min(box.clientWidth / w, box.clientHeight / h);
            const inner = document.createElement('div');
            inner.className = 'cads-thumb-inner';
            inner.style.width = w + 'px';
            inner.style.height = h + 'px';
            inner.style.transform = 'translate(' + ((box.clientWidth - w * scale) / 2) + 'px,' + ((box.clientHeight - h * scale) / 2) + 'px) scale(' + scale + ')';
            box.appendChild(inner);
            window.CoreXAd.renderLayout(l, this.brand, inner, { paintBackground: true });
        },

        /** A row's Download button. */
        download(id) {
            return this.downloadLayout(this.layouts[id], id);
        },

        /** Draw an ad ({name, layout}) at its real size off-screen and save it as a PNG. Also used by the AI pop-up. */
        async downloadLayout(entry, busyKey = 'ai') {
            if (!entry || !entry.layout || this.busy) return;
            this.busy = busyKey;
            const stage = this.$refs.stage;
            let restore = () => {};
            try {
                const l = entry.layout || {};
                stage.style.width = (l.canvasW || 1200) + 'px';
                stage.style.height = (l.canvasH || 628) + 'px';
                window.CoreXAd.renderLayout(l, this.brand, stage, { paintBackground: true });
                if (document.fonts?.ready) { try { await document.fonts.ready; } catch (_) {} }
                await Promise.all([...stage.querySelectorAll('img')].map(img => img.complete ? null : new Promise(r => { img.onload = img.onerror = r; })));
                try { restore = await window.CoreXAd.prepareImagesForCapture(stage); } catch (_) {}
                const canvas = await html2canvas(stage, {
                    useCORS: true, allowTaint: false, scale: 1, logging: false,
                    backgroundColor: window.CoreXAd.canvasBgSolid(l),
                });
                const a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = (entry.name || 'company-ad').replace(/[^a-z0-9\- ]+/gi, '').trim().replace(/\s+/g, '-').toLowerCase() + '.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
            } catch (e) {
                alert('The picture could not be prepared. Please try again.');
            } finally {
                restore();
                stage.innerHTML = '';
                this.busy = null;
            }
        },
    };
}
</script>

@if($aiPanel)
    @include('tools.ad-manager._company-ad-ai-modal')
@endif
@endsection
