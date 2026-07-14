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
<script src="{{ asset('js/corex-ad-render.js') }}?v=1"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<div class="w-full space-y-5" x-data="adManager()">

    {{-- ── Page header (branded) ───────────────────────────────── --}}
    <div class="rounded-md px-6 py-5" data-tour="tools-ad-manager-header" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Ad Manager</h1>
                <p class="text-sm text-white/60">Generate ready-to-post ads for multiple properties — image and grounded AI description.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                <div class="flex items-center gap-2 text-xs font-semibold text-white/70" data-tour="tools-ad-manager-steps">
                    <span :class="step==='select' ? 'text-white' : ''">1. Properties</span>
                    <span class="text-white/40">›</span>
                    <span :class="step==='template' ? 'text-white' : ''">2. Template</span>
                    <span class="text-white/40">›</span>
                    <span :class="step==='results' ? 'text-white' : ''">3. Ads</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ════════ STEP 1 — SELECT PROPERTIES ════════ --}}
    <div x-show="step==='select'" class="space-y-4">

        {{-- Ad size selector --}}
        <div class="rounded-md p-4 flex flex-col sm:flex-row sm:items-center gap-3" data-tour="tools-ad-manager-size" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex-1">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Ad size</div>
                <div class="text-xs" style="color:var(--text-muted);">Where will you post these ads? This sets the image dimensions.</div>
            </div>
            <select x-model="platform" class="rounded-md px-3 py-2 text-sm w-full sm:w-auto"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <template x-for="(p, key) in platforms" :key="key">
                    <option :value="key" x-text="p.label"></option>
                </template>
            </select>
        </div>

        @if($properties->isEmpty())
            {{-- Empty state --}}
            <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 11 18-5v12L3 14v-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No live listings to advertise</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Only active listings that are live on the website, Property24 or Private Property appear here.</p>
                <a href="{{ route('corex.properties.index') }}" class="corex-btn-outline text-sm">Go to Properties</a>
            </div>
        @else

        @if($allAgents)
            {{-- Agent-by-agent grouping --}}
            <div class="flex items-center justify-between flex-wrap gap-2" data-tour="tools-ad-manager-select">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Select properties by agent</div>
                <button type="button" @click="selectAllEverything()" class="corex-btn-outline text-xs">Select all properties</button>
            </div>
            <div class="space-y-3">
                <template x-for="ag in agents" :key="ag.id">
                    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);"
                         :style="skippedAgents.includes(ag.id) ? 'opacity:0.55;' : ''">
                        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="toggleAgent(ag.id)">
                            <svg class="w-4 h-4 transition-transform duration-300" :style="openAgents.includes(ag.id) ? 'transform:rotate(90deg);' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="ag.name"></div>
                                <div class="text-xs" style="color:var(--text-muted);"><span x-text="ag.count"></span> live · <span x-text="agentSelectedCount(ag.id)"></span> selected</div>
                            </div>
                            <button type="button" @click.stop="selectAllForAgent(ag.id)" class="corex-btn-outline text-xs">Select all</button>
                            <button type="button" @click.stop="skipAgent(ag.id)" class="corex-btn-outline text-xs">Skip</button>
                        </div>
                        <div x-show="openAgents.includes(ag.id)" x-cloak class="px-4 pb-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
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
            <div class="flex items-center justify-between flex-wrap gap-2" data-tour="tools-ad-manager-select">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Select your properties</div>
                <button type="button" @click="selectAllEverything()" class="corex-btn-outline text-xs">Select all</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="p in properties" :key="p.id">
                    @include('tools._ad-manager-property-card')
                </template>
            </div>
        @endif

        {{-- Footer --}}
        <div class="sticky bottom-0 z-20 py-3 flex items-center justify-between gap-3" style="background:var(--bg,#0d0f14);">
            <div class="text-sm" style="color:var(--text-muted);"><span class="font-bold" style="color:var(--text-primary);" x-text="selected.length"></span> propert<span x-text="selected.length===1?'y':'ies'"></span> selected</div>
            <button type="button" @click="goTemplate()" :disabled="!selected.length"
                    data-tour="tools-ad-manager-next"
                    class="corex-btn-primary text-base px-5 py-2.5 disabled:opacity-40 disabled:cursor-not-allowed">
                Next: Choose template →
            </button>
        </div>
        @endif
    </div>

    {{-- ════════ STEP 2 — CHOOSE TEMPLATE ════════ --}}
    <div x-show="step==='template'" x-cloak class="space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <button type="button" @click="step='select'" class="corex-btn-outline text-xs">← Back to properties</button>
            <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" x-model="emojis" class="rounded" style="accent-color:var(--brand-button,#0ea5e9);">
                <span class="text-sm" style="color:var(--text-secondary);">Include emojis ✨ <span style="color:var(--text-muted);">in the descriptions</span></span>
            </label>
        </div>

        <div class="text-sm font-semibold" style="color:var(--text-primary);">Pre-built templates <span class="font-normal" style="color:var(--text-muted);">— previewed with your first selected property</span></div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
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
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="t in custom" :key="t.id">
                        <button type="button" @click="template = t.id"
                                class="rounded-md overflow-hidden text-left transition-all duration-300" style="background:var(--surface);"
                                :style="template===t.id ? 'border:1.5px solid var(--brand-button,#0ea5e9); box-shadow:0 0 0 1px var(--brand-button,#0ea5e9);' : 'border:1.5px solid var(--border);'">
                            <div class="adm-tpl-thumb" :style="'aspect-ratio:'+((t.layout_json&&t.layout_json.canvasW)||1200)+'/'+((t.layout_json&&t.layout_json.canvasH)||628)+'; overflow:hidden; background:#071325; position:relative;'">
                                <div class="adm-scaled" :id="'tplthumb-custom-'+t.id" :data-cw="(t.layout_json&&t.layout_json.canvasW)||1200" :style="'position:absolute;top:0;left:0;transform-origin:top left;width:'+((t.layout_json&&t.layout_json.canvasW)||1200)+'px;height:'+((t.layout_json&&t.layout_json.canvasH)||628)+'px;'"></div>
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
            <button type="button" @click="generate()" :disabled="!template || !selected.length || generating"
                    class="corex-btn-primary text-base px-5 py-2.5 disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-show="!generating">Generate <span x-text="selected.length"></span> ad<span x-text="selected.length===1?'':'s'"></span></span>
                <span x-show="generating">Generating…</span>
            </button>
        </div>
    </div>

    {{-- ════════ STEP 3 — RESULTS ════════ --}}
    <div x-show="step==='results'" x-cloak class="space-y-4">
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

        agentProperties(agentId) { return this.properties.filter(p => p.agent_id === agentId); },
        agentSelectedCount(agentId) { const ids = this.agentProperties(agentId).map(p => p.id); return this.selected.filter(s => ids.includes(s)).length; },

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
            const ids = this.agentProperties(id).map(p => p.id);
            this.selected = this.selected.filter(s => !ids.includes(s));
            if (!this.skippedAgents.includes(id)) this.skippedAgents.push(id);
            this.openAgents = this.openAgents.filter(a => a !== id);
        },
        selectAllEverything() {
            this.properties.map(p => p.id).forEach(i => { if (!this.selected.includes(i)) this.selected.push(i); });
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
                this.custom.forEach(t => this.renderCustomInto(t.layout_json, this.previewData, document.getElementById('tplthumb-custom-' + t.id)));
                this.fitCanvases();
            });
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
            await new Promise(res => setTimeout(res, 60));
            try {
                const c = await html2canvas(el, { width: r.cw, height: r.ch, scale: 2, useCORS: true, backgroundColor: '#071325', logging: false });
                const a = document.createElement('a');
                a.download = 'ad-' + r.id + '.png';
                a.href = c.toDataURL('image/png');
                a.click();
            } catch (e) {
                window.showToast ? window.showToast('Download failed: ' + (e?.message || 'unknown'), 'error') : alert('Download failed.');
            } finally {
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
