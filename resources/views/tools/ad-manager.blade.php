{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@push('head')
    @include('corex.properties._ad-fonts')
@endpush

@section('corex-content')

{{-- The shared render kernel — the SAME code the Ad Builder previews with and the
     single-property generator renders with. Before it, this page carried its own copy
     that had fallen behind: it knew nothing about shapeType/clip-paths, custom image
     and video elements, the features chooser, or the agent-2 empty-slot rule, so those
     elements rendered wrong on a real bulk ad. Spec: ad-manager.md §12. --}}
<script src="{{ asset_v('js/corex-ad-render.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
{{-- Ad Manager "Remove background" hole-fill guard thresholds — agency-
     configurable, nullable (ad-manager.md §15.1 round 4).
     @json() splits its argument on EVERY top-level comma (it supports
     @json($value, $options, $depth)) — an inline multi-key array literal
     breaks that. Assign to a bare variable first, then @json($var). --}}
@php
    $_bgRemovalCfg = [
        'holeMinPx'          => $agency->ad_bg_removal_hole_min_px ?? null,
        'holeMaxPx'          => $agency->ad_bg_removal_hole_max_px ?? null,
        'holeMaxDimensionPx' => $agency->ad_bg_removal_hole_max_dimension_px ?? null,
        'floodFillDriftCapPx' => $agency->ad_bg_removal_flood_fill_drift_cap_px ?? null,
    ];
@endphp
<script>
    window.CoreXAd.configureBgRemoval(@json($_bgRemovalCfg));
</script>

<div class="w-full space-y-5" x-data="adManager()">

    {{-- ── Page header (branded) ───────────────────────────────── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="tools-ad-manager-header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Ad Manager</h1>
                <p class="text-sm text-white/60">Turn your listings into ready-to-post ads — an image and a written description, in three steps.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                @include('tools.ad-manager._switcher', ['inHeader' => true])
            </div>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row gap-6 items-start">

        {{-- ════════ LEFT RAIL — progress · ad size · tip (ad-manager.md §20.1) ════════ --}}
        <aside class="w-full lg:w-72 shrink-0 space-y-4 lg:sticky lg:top-4">

            <div class="rounded-xl p-2 lg:p-4 flex lg:block gap-1 lg:space-y-1" data-tour="tools-ad-manager-steps" style="background:var(--surface); border:1px solid var(--border);">
                <div class="hidden lg:block text-[11px] font-bold uppercase tracking-wider px-3 pb-2" style="color:var(--text-muted);">Your progress</div>
                @foreach([['select', 'Properties', 'Pick what to advertise'], ['template', 'Template', 'Choose a design'], ['results', 'Ads', 'Download and copy']] as $i => [$stepKey, $stepName, $stepHint])
                    <div class="flex flex-1 lg:flex-none items-center gap-2 lg:gap-3 p-2 lg:p-3 rounded-lg"
                         :style="step === '{{ $stepKey }}' ? 'background:color-mix(in srgb, var(--brand-button,#0ea5e9) 12%, transparent); border:1px solid color-mix(in srgb, var(--brand-button,#0ea5e9) 40%, transparent);' : 'border:1px solid transparent;'"
                         :aria-current="step === '{{ $stepKey }}' ? 'step' : null">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold shrink-0 box-border"
                             :style="stepIndex > {{ $i }} ? 'background:var(--ds-green,#059669); color:#fff;' : (step === '{{ $stepKey }}' ? 'background:var(--brand-button,#0ea5e9); color:#fff;' : 'background:var(--surface); color:var(--text-muted); border:2px solid var(--border);')">
                            <svg x-show="stepIndex > {{ $i }}" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            <span x-show="stepIndex <= {{ $i }}">{{ $i + 1 }}</span>
                        </div>
                        <div>
                            <div class="text-sm font-bold" :style="step === '{{ $stepKey }}' ? 'color:var(--text-primary);' : 'color:var(--text-secondary);'">{{ $stepName }}</div>
                            <div class="hidden lg:block text-xs" style="color:var(--text-muted);">{{ $stepHint }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Ad size — step 1 only: previews are rendered for the chosen size, so it must not change mid-flow. --}}
            <div x-show="step==='select'" class="rounded-xl p-4" data-tour="tools-ad-manager-size" style="background:var(--surface); border:1px solid var(--border);">
                <div class="text-sm font-bold" style="color:var(--text-primary);">Where will you post?</div>
                <div class="text-xs mb-3" style="color:var(--text-muted);">This sets the image size.</div>
                <div class="grid grid-cols-2 gap-2.5">
                    <template x-for="(p, key) in platforms" :key="key">
                        <button type="button" @click="platform = key" :aria-pressed="platform === key"
                                class="rounded-lg px-2 py-3 flex flex-col items-center gap-2 transition-all duration-200"
                                :style="platform === key ? 'border:2px solid var(--brand-button,#0ea5e9); background:color-mix(in srgb, var(--brand-button,#0ea5e9) 8%, transparent);' : 'border:1px solid var(--border); background:var(--surface-2);'">
                            <div class="flex items-center" style="height:40px;">
                                <div :style="tileShape(p) + 'border-radius:3px; background:' + (platform === key ? 'var(--brand-button,#0ea5e9)' : 'var(--text-muted,#94a3b8)') + '; opacity:' + (platform === key ? '1' : '.45') + ';'"></div>
                            </div>
                            <div class="text-xs font-bold" style="color:var(--text-primary);" x-text="platformName(p)"></div>
                            <div class="text-[11px]" style="color:var(--text-muted); margin-top:-4px;" x-text="p.w + ' × ' + p.h"></div>
                        </button>
                    </template>
                </div>
            </div>

            <div x-show="step!=='select'" x-cloak class="rounded-xl p-4" style="background:var(--surface); border:1px solid var(--border);">
                <div class="text-[11px] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Ad size</div>
                <div class="text-sm font-bold mt-1" style="color:var(--text-primary);" x-text="platforms[platform].label"></div>
                <div class="text-xs mt-1" style="color:var(--text-muted);"><span x-text="selected.length"></span> propert<span x-text="selected.length===1?'y':'ies'"></span> selected · use <em>Start over</em> to change either.</div>
            </div>

            @if($allAgents)
            <div x-show="step==='select'" class="hidden lg:block rounded-xl px-4 py-3 text-xs leading-relaxed"
                 style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 35%, transparent); color:var(--text-primary);">
                <strong>Tip:</strong> use <em>Select all</em> on an agent to tick every one of their active listings in one go.
            </div>
            @endif
        </aside>

        <div class="flex-1 min-w-0 w-full">

    {{-- ════════ STEP 1 — SELECT PROPERTIES ════════ --}}
    <div x-show="step==='select'" class="space-y-4">

        @if($properties->isEmpty())
            {{-- Empty state --}}
            <div class="rounded-xl py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 11 18-5v12L3 14v-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No active listings to advertise</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Only active listings appear here — sold, let and withdrawn ones are left out.</p>
                <a href="{{ route('corex.properties.index') }}" class="corex-btn-outline text-sm">Go to Properties</a>
            </div>
        @else

        {{-- Heading + search + select-all --}}
        <div class="flex items-center gap-3 flex-wrap" data-tour="tools-ad-manager-select">
            <div class="mr-auto">
                <div class="text-lg font-extrabold" style="color:var(--text-primary);">{{ $allAgents ? 'Select properties by agent' : 'Select your properties' }}</div>
                <div class="text-sm" style="color:var(--text-secondary);">Every active listing is here — open an agent to see all of theirs.</div>
            </div>
            <label class="flex items-center gap-2 rounded-lg px-3 h-10 w-full sm:w-56" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                <input type="search" x-model="search" placeholder="Search properties" aria-label="Search properties"
                       class="w-full bg-transparent border-0 outline-none text-sm p-0 focus:ring-0" style="color:var(--text-primary);">
            </label>
            <button type="button" @click="selectAllEverything()" class="corex-btn-outline text-xs h-10">
                <span x-text="search.trim() ? 'Select all ' + visibleProperties.length + ' matches' : 'Select all properties'"></span>
            </button>
        </div>

        <div x-show="search.trim() && !visibleProperties.length" x-cloak class="rounded-xl py-10 px-6 text-center text-sm"
             style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            No properties match "<span x-text="search"></span>". <button type="button" @click="search=''" class="font-semibold underline" style="color:var(--brand-icon,#0ea5e9);">Clear search</button>
        </div>

        @if($allAgents)
            {{-- Agent-by-agent grouping — every active property of every agent the user may see (§20.2) --}}
            <div class="space-y-3">
                <template x-for="ag in visibleAgents" :key="ag.id">
                    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);"
                         :style="skippedAgents.includes(ag.id) ? 'opacity:0.55;' : ''">
                        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer flex-wrap" role="button" tabindex="0"
                             :aria-expanded="isOpen(ag.id)" @click="toggleAgent(ag.id)" @keydown.enter.prevent="toggleAgent(ag.id)" @keydown.space.prevent="toggleAgent(ag.id)">
                            <svg class="w-4 h-4 shrink-0 transition-transform duration-300" :style="isOpen(ag.id) ? 'transform:rotate(90deg);' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
                                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 14%, transparent); color:var(--brand-icon,#0ea5e9);" x-text="initials(ag.name)"></div>
                            <div class="flex-1 min-w-[10rem]">
                                <div class="text-sm font-bold" style="color:var(--text-primary);" x-text="ag.name"></div>
                                <div class="text-xs" style="color:var(--text-muted);">
                                    <span x-text="agentAll(ag.id).length"></span> active · <span x-text="agentSelectedCount(ag.id)"></span> selected<span x-show="search.trim()"> · <span x-text="agentProperties(ag.id).length"></span> match</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 ml-auto">
                                <button type="button" @click.stop="selectAllForAgent(ag.id)" class="corex-btn-outline text-xs" x-text="search.trim() ? 'Select matches' : 'Select all'">Select all</button>
                                <button type="button" @click.stop="skipAgent(ag.id)" class="corex-btn-outline text-xs">Skip</button>
                            </div>
                        </div>
                        <div x-show="isOpen(ag.id)" x-cloak class="px-4 pb-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3.5">
                                <template x-for="p in agentProperties(ag.id)" :key="p.id">
                                    @include('tools._ad-manager-property-card')
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @else
            {{-- Own properties --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3.5">
                <template x-for="p in visibleProperties" :key="p.id">
                    @include('tools._ad-manager-property-card')
                </template>
            </div>
        @endif

        {{-- Selection bar --}}
        <div class="sticky bottom-3 z-20 rounded-xl px-4 py-3 flex items-center justify-between gap-3 flex-wrap"
             style="background:var(--surface); border:1px solid var(--border); box-shadow:0 10px 30px rgba(0,0,0,.18);">
            <div class="flex items-center gap-3 min-w-0">
                <div class="flex pl-2.5" x-show="selected.length" x-cloak>
                    <template x-for="(t, i) in selectedThumbs" :key="i">
                        <div class="w-9 h-9 rounded-lg overflow-hidden -ml-2.5" style="border:2px solid var(--surface); background:var(--surface-2);">
                            <img x-show="t" :src="t" alt="" loading="lazy" class="w-full h-full object-cover block">
                        </div>
                    </template>
                </div>
                <div>
                    <div class="text-sm font-extrabold" style="color:var(--text-primary);"><span x-text="selected.length"></span> propert<span x-text="selected.length===1?'y':'ies'"></span> selected</div>
                    <div class="text-xs" style="color:var(--text-muted);" x-text="platforms[platform].label"></div>
                </div>
            </div>
            <button type="button" @click="goTemplate()" :disabled="!selected.length"
                    data-tour="tools-ad-manager-next"
                    class="corex-btn-primary text-base px-5 py-2.5 disabled:opacity-40 disabled:cursor-not-allowed">
                Next: Choose template →
            </button>
        </div>
        @endif
    </div>

    {{-- ════════ STEP 2 — CHOOSE TEMPLATE ════════ --}}
    <div x-show="step==='template'" x-cloak class="space-y-4" data-tour="tools-ad-manager-template-step">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <button type="button" @click="step='select'" class="corex-btn-outline text-xs">← Back to properties</button>
            <label class="flex items-center gap-2 cursor-pointer select-none" data-tour="tools-ad-manager-emojis">
                <input type="checkbox" x-model="emojis" class="rounded" style="accent-color:var(--brand-button,#0ea5e9);">
                <span class="text-sm" style="color:var(--text-secondary);">Include emojis ✨ <span style="color:var(--text-muted);">in the descriptions</span></span>
            </label>
        </div>

        <div class="text-sm font-semibold" style="color:var(--text-primary);">Pre-built templates <span class="font-normal" style="color:var(--text-muted);">— previewed with your first selected property</span></div>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3" data-tour="tools-ad-manager-templates">
            <template x-for="t in prebuilt" :key="t.key">
                <button type="button" @click="template = t.key"
                        class="rounded-md overflow-hidden text-left transition-all duration-300" style="background:var(--surface);"
                        :style="template===t.key ? 'border:1.5px solid var(--brand-button,#0ea5e9); box-shadow:0 0 0 1px var(--brand-button,#0ea5e9);' : 'border:1.5px solid var(--border);'">
                    <div class="adm-tpl-thumb" :style="'aspect-ratio:'+tplCanvas(t.key).w+'/'+tplCanvas(t.key).h+'; overflow:hidden; background:'+(t.key==='brochure'?'#fff':'#071325')+'; position:relative;'">
                        <div class="adm-scaled" :data-cw="tplCanvas(t.key).w" :style="'position:absolute;top:0;left:0;transform-origin:top left;width:'+tplCanvas(t.key).w+'px;height:'+tplCanvas(t.key).h+'px;'" x-html="previews[t.key] || ''"></div>
                        <div x-show="previewLoading" class="absolute inset-0 flex items-center justify-center text-[0.6875rem]" style="color:var(--text-muted); background:var(--surface-2);">Loading…</div>
                    </div>
                    <div class="px-3 py-2 text-sm font-semibold flex items-center gap-2" style="color:var(--text-primary);">
                        <span x-text="t.name"></span>
                        <template x-if="t.key==='brochure'"><span class="ds-badge ds-badge-default">PDF · A4</span></template>
                    </div>
                </button>
            </template>
        </div>

        <template x-if="custom.length">
            <div class="space-y-3">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Your agency's custom templates</div>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                    <template x-for="t in custom" :key="t.id">
                        <button type="button" @click="template = t.id"
                                class="rounded-md overflow-hidden text-left transition-all duration-300" style="background:var(--surface);"
                                :style="template===t.id ? 'border:1.5px solid var(--brand-button,#0ea5e9); box-shadow:0 0 0 1px var(--brand-button,#0ea5e9);' : 'border:1.5px solid var(--border);'">
                            <div class="adm-tpl-thumb" :style="'aspect-ratio:'+(thumbLayout(t).canvasW||1200)+'/'+(thumbLayout(t).canvasH||628)+'; overflow:hidden; background:#071325; position:relative;'">
                                <div class="adm-scaled" :id="'tplthumb-custom-'+t.id" :data-cw="thumbLayout(t).canvasW||1200" :style="'position:absolute;top:0;left:0;transform-origin:top left;width:'+(thumbLayout(t).canvasW||1200)+'px;height:'+(thumbLayout(t).canvasH||628)+'px;'"></div>
                                <div x-show="previewLoading" class="absolute inset-0 flex items-center justify-center text-[0.6875rem]" style="color:var(--text-muted); background:var(--surface-2);">Loading…</div>
                            </div>
                            <div class="px-3 py-2">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="t.name"></div>
                                <span class="ds-badge ds-badge-default">Custom</span>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <div class="sticky bottom-0 z-20 py-3 flex items-center justify-end gap-3" style="background:var(--bg,#0d0f14);">
            <button type="button" data-tour="tools-ad-manager-generate" @click="generate()" :disabled="!template || !selected.length || generating"
                    class="corex-btn-primary text-base px-5 py-2.5 disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-show="!generating">Generate <span x-text="selected.length"></span> ad<span x-text="selected.length===1?'':'s'"></span></span>
                <span x-show="generating">Generating…</span>
            </button>
        </div>
    </div>

    {{-- ════════ STEP 3 — RESULTS ════════ --}}
    <div x-show="step==='results'" x-cloak class="space-y-4" data-tour="tools-ad-manager-results">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="text-sm font-semibold" style="color:var(--text-primary);"><span x-text="filteredResults.length"></span> ad<span x-text="filteredResults.length===1?'':'s'"></span> ready</div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($allAgents)
                <select x-model="resultAgentFilter" class="rounded-md px-3 py-1.5 text-sm"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All agents</option>
                    <template x-for="a in resultAgents" :key="a"><option :value="a" x-text="a"></option></template>
                </select>
                @endif
                <button type="button" @click="reset()" class="corex-btn-outline text-xs">Start over</button>
            </div>
        </div>

        <div x-show="generating" class="rounded-md py-12 px-6 text-center text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            <svg class="animate-spin w-6 h-6 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Building your ads and writing descriptions…
        </div>

        <template x-if="error">
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);">
                <span x-text="error"></span>
            </div>
        </template>

        <div class="space-y-4">
            <template x-for="r in filteredResults" :key="r.id">
                <div class="rounded-md p-4 flex flex-col lg:flex-row gap-4" style="background:var(--surface); border:1px solid var(--border);">
                    {{-- Preview --}}
                    <div class="flex-shrink-0" style="width:100%; max-width:380px;">
                        <div class="adm-result-thumb" :style="'width:100%; aspect-ratio:'+r.cw+'/'+r.ch+'; overflow:hidden; border-radius:6px; background:'+(r.brochure?'#fff':'#071325')+';'">
                            <div class="adm-scaled" :id="'adm-canvas-'+r.id" :data-cw="r.cw"
                                 :style="'width:'+r.cw+'px; height:'+r.ch+'px; transform-origin:top left; background:'+(r.brochure?'#fff':'#071325')+'; position:relative; font-family:Figtree,Arial,sans-serif;'"
                                 x-html="r.custom ? '' : r.html"></div>
                        </div>
                        <template x-if="r.brochure">
                            <a :href="r.brochure_url + '?dl=1'" target="_blank" rel="noopener" class="corex-btn-primary text-sm w-full mt-2 justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Download Brochure PDF
                            </a>
                        </template>
                        <template x-if="!r.brochure">
                            <button type="button" @click="downloadRow(r)" class="corex-btn-primary text-sm w-full mt-2 justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Download PNG
                            </button>
                        </template>
                    </div>
                    {{-- Info / description --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <span class="text-sm font-bold" style="color:var(--text-primary);" x-text="r.title"></span>
                            @if($allAgents)<span class="ds-badge ds-badge-info" x-text="r.agent_name"></span>@endif
                        </div>
                        <template x-if="r.ai_error">
                            <div class="rounded-md px-3 py-2 text-xs" style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 30%, transparent); color:var(--text-primary);" x-text="r.ai_error"></div>
                        </template>
                        <template x-if="r.description">
                            <div>
                                <textarea readonly rows="7" x-text="r.description"
                                          class="w-full rounded-md px-3 py-2 text-sm resize-y"
                                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                                <button type="button" @click="copyText(r.description, $event)" class="corex-btn-outline text-xs mt-2">Copy description</button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
        </div>{{-- /main column --}}
    </div>{{-- /rail + main --}}

</div>

<script>
const ADM_PROPERTIES   = @json($properties);
const ADM_AGENTS       = @json($agents);
const ADM_PREBUILT     = @json($prebuilt);
const ADM_CUSTOM       = @json($customTemplates);
const ADM_PLATFORMS    = @json($platforms);
const ADM_GENERATE_URL = @json(route('tools.ad-manager.generate'));
const ADM_PREVIEW_URL  = @json(route('tools.ad-manager.previews'));
const ADM_CSRF         = '{{ csrf_token() }}';

function adManager() {
    return {
        step: 'select',
        properties: ADM_PROPERTIES,
        agents: ADM_AGENTS,
        prebuilt: ADM_PREBUILT,
        custom: ADM_CUSTOM,
        platforms: ADM_PLATFORMS,
        platform: 'facebook',
        selected: [],
        openAgents: [],
        skippedAgents: [],
        emojis: false,
        template: null,
        generating: false,
        results: [],
        error: null,
        previews: {},
        previewData: null,
        previewLoading: false,
        previewCanvas: { w: 1200, h: 628 },
        resultAgentFilter: '',
        search: '',

        init() {
            window.addEventListener('resize', () => this.fitCanvases());
        },

        // Brochure is A4 portrait; every other template uses the social canvas.
        tplCanvas(key) { return key === 'brochure' ? { w: 794, h: 1123 } : this.previewCanvas; },

        get resultAgents() { return [...new Set(this.results.map(r => r.agent_name))].sort(); },
        get filteredResults() {
            let rows = this.results;
            if (this.resultAgentFilter) rows = rows.filter(r => r.agent_name === this.resultAgentFilter);
            return [...rows].sort((a, b) => (a.agent_name || '').localeCompare(b.agent_name || ''));
        },

        get stepIndex() { return ['select', 'template', 'results'].indexOf(this.step); },

        // ── Search (client-side, over the list already loaded — ad-manager.md §20.1) ──
        matchesSearch(p) {
            const q = this.search.trim().toLowerCase();
            if (!q) return true;
            return [p.title, p.address, p.suburb, p.agent_name].join(' ').toLowerCase().includes(q);
        },
        get visibleProperties() { return this.properties.filter(p => this.matchesSearch(p)); },
        // While a search is typed, agents with no match are hidden.
        get visibleAgents() { return this.agents.filter(a => !this.search.trim() || this.agentProperties(a.id).length); },
        isOpen(agentId) { return this.search.trim() !== '' || this.openAgents.includes(agentId); },

        // ALL of an agent's active properties, never trimmed — counts, Skip and "N selected" use this.
        agentAll(agentId) { return this.properties.filter(p => p.agent_id === agentId); },
        // What the open group displays: all of them, or just the matches while searching.
        agentProperties(agentId) { return this.agentAll(agentId).filter(p => this.matchesSearch(p)); },
        agentSelectedCount(agentId) { const ids = this.agentAll(agentId).map(p => p.id); return this.selected.filter(s => ids.includes(s)).length; },

        // First four ticked properties' photos for the selection bar.
        get selectedThumbs() {
            return this.selected.slice(0, 4).map(id => (this.properties.find(p => p.id === id) || {}).thumb || null);
        },

        // Ad-size tiles: each drawn in its real aspect ratio inside a 44 × 36 box.
        tileShape(p) {
            const k = Math.min(44 / p.w, 36 / p.h);
            return 'width:' + Math.round(p.w * k) + 'px; height:' + Math.round(p.h * k) + 'px;';
        },
        platformName(p) { return String(p.label).split(' — ')[0]; },
        initials(name) {
            const parts = String(name || '?').replace(/\./g, '').trim().split(/\s+/);
            return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
        },

        /** "Last generated" tooltip on a property card's ad-count badge. */
        formatAdDate(iso) {
            if (!iso) return 'never';
            return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        },

        toggleAgent(id) {
            if (this.openAgents.includes(id)) { this.openAgents = this.openAgents.filter(a => a !== id); }
            else { this.openAgents.push(id); this.skippedAgents = this.skippedAgents.filter(a => a !== id); }
        },
        selectAllForAgent(id) {
            this.agentProperties(id).map(p => p.id).forEach(i => { if (!this.selected.includes(i)) this.selected.push(i); });
            if (!this.openAgents.includes(id)) this.openAgents.push(id);
            this.skippedAgents = this.skippedAgents.filter(a => a !== id);
        },
        skipAgent(id) {
            const ids = this.agentAll(id).map(p => p.id);
            this.selected = this.selected.filter(s => !ids.includes(s));
            if (!this.skippedAgents.includes(id)) this.skippedAgents.push(id);
            this.openAgents = this.openAgents.filter(a => a !== id);
        },
        selectAllEverything() {
            // With a search typed this ticks the matches; otherwise every listing.
            this.visibleProperties.map(p => p.id).forEach(i => { if (!this.selected.includes(i)) this.selected.push(i); });
        },

        async goTemplate() {
            if (!this.selected.length) return;
            this.step = 'template';
            await this.loadPreviews();
        },
        async loadPreviews() {
            if (!this.selected.length) return;
            this.previewLoading = true; this.previews = {}; this.previewData = null;
            try {
                const res = await fetch(ADM_PREVIEW_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ADM_CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ property_id: this.selected[0], platform: this.platform }),
                });
                const data = await res.json();
                if (res.ok && data.ok) { this.previews = data.prebuilt; this.previewData = data.data; this.previewCanvas = data.canvas || this.previewCanvas; }
            } catch (e) { /* leave previews empty — names still show */ }
            this.previewLoading = false;
            this.$nextTick(() => {
                this.custom.forEach(t => this.renderCustomInto(this.thumbLayout(t), this.previewData, document.getElementById('tplthumb-custom-' + t.id)));
                this.fitCanvases();
            });
        },

        /**
         * §18 — the picker thumbnail (and its aspect-ratio frame) shows the
         * design that will ACTUALLY be used for the first selected property,
         * not always the Default — if the template has a custom variant for
         * this property's type, resolve it here exactly like generate()
         * resolves it server-side, per property.
         */
        thumbLayout(t) {
            return CoreXAd.resolveTemplateLayout(t.layout_json, this.previewData?.property_type_raw);
        },
        fitCanvases() {
            document.querySelectorAll('.adm-scaled').forEach(el => {
                const cw = parseInt(el.dataset.cw || '1200', 10);
                const wrap = el.parentElement;
                if (wrap && wrap.clientWidth) el.style.transform = 'scale(' + (wrap.clientWidth / cw) + ')';
            });
        },

        async generate() {
            if (!this.template || !this.selected.length) return;
            this.generating = true; this.error = null; this.results = []; this.step = 'results';
            try {
                const res = await fetch(ADM_GENERATE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ADM_CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ property_ids: this.selected, template: this.template, emojis: this.emojis, platform: this.platform }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) { this.error = data.error || 'Could not generate ads.'; this.generating = false; return; }
                this.results = data.results;
                this.generating = false;
                this.$nextTick(() => {
                    this.results.forEach(r => { if (r.custom) this.renderCustom(r); });
                    this.fitCanvases();
                });
            } catch (e) {
                this.error = 'Request failed: ' + e.message;
                this.generating = false;
            }
        },

        reset() { this.step = 'select'; this.results = []; this.error = null; this.template = null; this.resultAgentFilter = ''; },

        async downloadRow(r) {
            const el = document.getElementById('adm-canvas-' + r.id);
            if (!el) return;
            const saved = el.style.transform;
            el.style.transform = 'none';
            // A batch of Agent Images with "Remove background" on need their in-browser
            // cutout to finish before a bulk run rasterises them — otherwise a fast
            // capture could grab the un-stripped original.
            try { await CoreXAd.backgroundRemovalsSettled(); } catch (_) {}
            await new Promise(res => setTimeout(res, 60));
            let restoreImages = () => {};
            try {
                // html2canvas has known gaps in its object-fit support — pre-bake the
                // SAME cover/contain crop onto an offscreen canvas so there is nothing
                // left for it to get wrong (a real ad hit this with an Agent Image).
                try { restoreImages = await CoreXAd.prepareImagesForCapture(el); } catch (_) {}
                const c = await html2canvas(el, { width: r.cw, height: r.ch, scale: 2, useCORS: true, backgroundColor: '#071325', logging: false });
                const a = document.createElement('a');
                a.download = 'ad-' + r.id + '.png';
                a.href = c.toDataURL('image/png');
                a.click();
            } catch (e) {
                window.showToast ? window.showToast('Download failed: ' + (e?.message || 'unknown'), 'error') : alert('Download failed.');
            } finally {
                restoreImages();
                el.style.transform = saved;
                this.fitCanvases();
            }
        },

        async copyText(text, ev) {
            try {
                await navigator.clipboard.writeText(text);
                const b = ev.target; const orig = b.textContent;
                b.textContent = 'Copied!';
                setTimeout(() => { b.textContent = orig; }, 1500);
            } catch (e) { window.showToast ? window.showToast('Copy failed — select manually.', 'error') : alert('Copy failed.'); }
        },

        /**
         * Custom templates are drawn by the shared render kernel — the SAME code the
         * Ad Builder previews with and the single-property generator renders with.
         * paintBackground: this page draws each template straight into a bare div, so
         * the kernel paints the layout's canvas colour/gradient onto it.
         */
        renderCustom(r) { this.renderCustomInto(r.layout, r.data, document.getElementById('adm-canvas-' + r.id)); },
        renderCustomInto(layout, prop, root) {
            CoreXAd.renderLayout(layout, prop, root, { paintBackground: true });
        },
    };
}
</script>
@endsection
