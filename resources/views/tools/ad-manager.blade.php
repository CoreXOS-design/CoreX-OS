@extends('layouts.corex')

@section('corex-content')
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<div class="w-full" x-data="adManager()">

    {{-- Header --}}
    <div class="mb-5">
        <h1 class="text-xl font-extrabold" style="color:var(--text-primary);">Ad Manager</h1>
        <p class="text-sm mt-1" style="color:var(--text-muted);">Pick properties, choose a template, and generate ready-to-post ads with grounded AI descriptions.</p>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-2 mb-5 text-xs font-semibold" style="color:var(--text-muted);">
        <span :style="step==='select' ? 'color:var(--brand-icon,#0ea5e9);' : ''">1. Properties</span>
        <span>›</span>
        <span :style="step==='template' ? 'color:var(--brand-icon,#0ea5e9);' : ''">2. Template</span>
        <span>›</span>
        <span :style="step==='results' ? 'color:var(--brand-icon,#0ea5e9);' : ''">3. Ads</span>
    </div>

    {{-- ════════ STEP 1 — SELECT PROPERTIES ════════ --}}
    <div x-show="step==='select'">
        @if($properties->isEmpty())
            <div class="rounded-xl p-6 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                No properties found that you can advertise.
            </div>
        @else

        @if($allAgents)
            {{-- Agent-by-agent grouping --}}
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Select properties by agent</div>
                <button type="button" @click="selectAllEverything()" class="text-xs font-semibold px-3 py-1.5 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">Select all properties</button>
            </div>
            <div class="space-y-2">
                <template x-for="ag in agents" :key="ag.id">
                    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);"
                         :style="skippedAgents.includes(ag.id) ? 'opacity:0.55;' : ''">
                        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="toggleAgent(ag.id)">
                            <svg class="w-4 h-4 transition-transform" :style="openAgents.includes(ag.id) ? 'transform:rotate(90deg);' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="ag.name"></div>
                                <div class="text-xs" style="color:var(--text-muted);"><span x-text="ag.count"></span> properties · <span x-text="agentSelectedCount(ag.id)"></span> selected</div>
                            </div>
                            <button type="button" @click.stop="selectAllForAgent(ag.id)" class="text-xs font-semibold px-2.5 py-1 rounded-md" style="background:rgba(0,180,216,0.12); color:#00b4d8;">Select all</button>
                            <button type="button" @click.stop="skipAgent(ag.id)" class="text-xs font-semibold px-2.5 py-1 rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">Skip</button>
                        </div>
                        <div x-show="openAgents.includes(ag.id)" x-cloak class="px-4 pb-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                <template x-for="p in agentProperties(ag.id)" :key="p.id">
                                    <label class="flex items-center gap-3 rounded-lg p-2 cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);"
                                           :style="selected.includes(p.id) ? 'border-color:#00b4d8;' : ''">
                                        <input type="checkbox" :value="p.id" x-model="selected" class="rounded flex-shrink-0">
                                        <div class="w-12 h-9 rounded overflow-hidden flex-shrink-0" style="background:var(--surface);">
                                            <template x-if="p.thumb"><img :src="p.thumb" alt="" class="w-full h-full object-cover"></template>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-xs font-semibold truncate" style="color:var(--text-primary);" x-text="p.title"></div>
                                            <div class="text-[11px] truncate" style="color:var(--text-muted);"><span x-text="p.suburb"></span> · <span x-text="p.price"></span></div>
                                        </div>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @else
            {{-- Own properties (flat list) --}}
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">Select your properties</div>
                <button type="button" @click="selectAllEverything()" class="text-xs font-semibold px-3 py-1.5 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">Select all</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <template x-for="p in properties" :key="p.id">
                    <label class="flex items-center gap-3 rounded-lg p-2 cursor-pointer" style="background:var(--surface); border:1px solid var(--border);"
                           :style="selected.includes(p.id) ? 'border-color:#00b4d8;' : ''">
                        <input type="checkbox" :value="p.id" x-model="selected" class="rounded flex-shrink-0">
                        <div class="w-12 h-9 rounded overflow-hidden flex-shrink-0" style="background:var(--surface-2);">
                            <template x-if="p.thumb"><img :src="p.thumb" alt="" class="w-full h-full object-cover"></template>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs font-semibold truncate" style="color:var(--text-primary);" x-text="p.title"></div>
                            <div class="text-[11px] truncate" style="color:var(--text-muted);"><span x-text="p.suburb"></span> · <span x-text="p.price"></span></div>
                        </div>
                    </label>
                </template>
            </div>
        @endif

        {{-- Footer --}}
        <div class="sticky bottom-0 mt-5 py-3 flex items-center justify-between gap-3" style="background:var(--bg, transparent);">
            <div class="text-sm" style="color:var(--text-muted);"><span class="font-bold" style="color:var(--text-primary);" x-text="selected.length"></span> propert<span x-text="selected.length===1?'y':'ies'"></span> selected</div>
            <button type="button" @click="step='template'" :disabled="!selected.length"
                    class="corex-btn-primary px-5 py-2.5 text-sm font-semibold rounded-xl disabled:opacity-40 disabled:cursor-not-allowed">
                Next: Choose template →
            </button>
        </div>
        @endif
    </div>

    {{-- ════════ STEP 2 — CHOOSE TEMPLATE ════════ --}}
    <div x-show="step==='template'" x-cloak>
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <button type="button" @click="step='select'" class="text-xs font-semibold" style="color:var(--text-muted);">← Back to properties</button>
            <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" x-model="emojis" class="rounded">
                <span class="text-xs" style="color:var(--text-secondary);">Include emojis ✨ <span style="color:var(--text-muted);">in the descriptions</span></span>
            </label>
        </div>

        <div class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Pre-built templates</div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
            <template x-for="t in prebuilt" :key="t.key">
                <button type="button" @click="template = t.key"
                        class="rounded-xl p-4 text-left transition-all"
                        :style="template===t.key ? 'background:rgba(0,180,216,0.1); border:1.5px solid #00b4d8;' : 'background:var(--surface); border:1.5px solid var(--border);'">
                    <div class="text-sm font-bold" style="color:var(--text-primary);" x-text="t.name"></div>
                </button>
            </template>
        </div>

        <template x-if="custom.length">
            <div>
                <div class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Your agency's custom templates</div>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                    <template x-for="t in custom" :key="t.id">
                        <button type="button" @click="template = t.id"
                                class="rounded-xl p-4 text-left transition-all"
                                :style="template===t.id ? 'background:rgba(0,180,216,0.1); border:1.5px solid #00b4d8;' : 'background:var(--surface); border:1.5px solid var(--border);'">
                            <div class="text-sm font-bold" style="color:var(--text-primary);" x-text="t.name"></div>
                            <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">Custom</div>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <div class="sticky bottom-0 py-3 flex items-center justify-end gap-3" style="background:var(--bg, transparent);">
            <button type="button" @click="generate()" :disabled="!template || !selected.length || generating"
                    class="corex-btn-primary px-5 py-2.5 text-sm font-semibold rounded-xl disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-show="!generating">Generate <span x-text="selected.length"></span> ad<span x-text="selected.length===1?'':'s'"></span></span>
                <span x-show="generating">Generating…</span>
            </button>
        </div>
    </div>

    {{-- ════════ STEP 3 — RESULTS ════════ --}}
    <div x-show="step==='results'" x-cloak>
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <div class="text-sm font-semibold" style="color:var(--text-primary);"><span x-text="results.length"></span> ad<span x-text="results.length===1?'':'s'"></span> ready</div>
            <button type="button" @click="reset()" class="text-xs font-semibold px-3 py-1.5 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">Start over</button>
        </div>

        <div x-show="generating" class="rounded-xl p-8 text-center text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            <svg class="animate-spin w-6 h-6 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Building your ads and writing descriptions…
        </div>

        <template x-if="error">
            <div class="rounded-xl p-4 text-sm mb-3" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); color:var(--ds-crimson,#b91c1c);" x-text="error"></div>
        </template>

        <div class="space-y-4">
            <template x-for="r in results" :key="r.id">
                <div class="rounded-xl p-4 flex flex-col lg:flex-row gap-4" style="background:var(--surface); border:1px solid var(--border);">
                    {{-- Preview --}}
                    <div class="flex-shrink-0">
                        <div style="width:380px; max-width:100%; height:199px; overflow:hidden; border-radius:8px; background:#071325;">
                            <div class="adm-canvas" :id="'adm-canvas-'+r.id"
                                 style="width:1200px; height:628px; transform:scale(0.3167); transform-origin:top left; background:#071325; position:relative; font-family:Figtree,Arial,sans-serif;"
                                 x-html="r.custom ? '' : r.html"></div>
                        </div>
                        <button type="button" @click="downloadRow(r)" class="mt-2 w-full corex-btn-primary px-4 py-2 text-xs font-semibold rounded-lg flex items-center justify-center gap-2">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download PNG
                        </button>
                    </div>
                    {{-- Info / description --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-bold mb-2" style="color:var(--text-primary);" x-text="r.title"></div>
                        <template x-if="r.ai_error">
                            <div class="text-xs rounded-lg px-3 py-2" style="background:var(--surface-2); color:var(--text-muted);" x-text="r.ai_error"></div>
                        </template>
                        <template x-if="r.description">
                            <div>
                                <textarea readonly rows="7" x-text="r.description"
                                          class="w-full rounded-lg px-3 py-2 text-sm resize-y"
                                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                                <button type="button" @click="copyText(r.description, $event)" class="mt-2 px-4 py-1.5 text-xs font-semibold rounded-lg" style="background:rgba(0,180,216,0.12); color:#00b4d8;">Copy description</button>
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
const ADM_GENERATE_URL = @json(route('tools.ad-manager.generate'));
const ADM_CSRF         = '{{ csrf_token() }}';

const ADM_IMAGE_FIELDS    = ['image_1','image_2','image_3','image_4','image_5','agent_avatar','agency_logo'];
const ADM_NON_TEXT_FIELDS = [...ADM_IMAGE_FIELDS, 'logo', 'watermark', 'color_block', 'gradient', 'line', 'shape'];

function adManager() {
    return {
        step: 'select',
        properties: ADM_PROPERTIES,
        agents: ADM_AGENTS,
        prebuilt: ADM_PREBUILT,
        custom: ADM_CUSTOM,
        selected: [],
        openAgents: [],
        skippedAgents: [],
        emojis: false,
        template: null,
        generating: false,
        results: [],
        error: null,

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

        async generate() {
            if (!this.template || !this.selected.length) return;
            this.generating = true; this.error = null; this.results = []; this.step = 'results';
            try {
                const res = await fetch(ADM_GENERATE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ADM_CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ property_ids: this.selected, template: this.template, emojis: this.emojis }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) { this.error = data.error || 'Could not generate ads.'; this.generating = false; return; }
                this.results = data.results;
                this.generating = false;
                this.$nextTick(() => this.results.forEach(r => { if (r.custom) this.renderCustom(r); }));
            } catch (e) {
                this.error = 'Request failed: ' + e.message;
                this.generating = false;
            }
        },

        reset() { this.step = 'select'; this.results = []; this.error = null; this.template = null; },

        async downloadRow(r) {
            const el = document.getElementById('adm-canvas-' + r.id);
            if (!el) return;
            const saved = el.style.transform;
            el.style.transform = 'none';
            await new Promise(res => setTimeout(res, 60));
            try {
                const c = await html2canvas(el, { width: 1200, height: 628, scale: 2, useCORS: true, backgroundColor: '#071325', logging: false });
                const a = document.createElement('a');
                a.download = 'ad-' + r.id + '.png';
                a.href = c.toDataURL('image/png');
                a.click();
            } catch (e) {
                alert('Download failed: ' + (e?.message || 'unknown'));
            } finally {
                el.style.transform = saved;
            }
        },

        async copyText(text, ev) {
            try {
                await navigator.clipboard.writeText(text);
                const b = ev.target; const orig = b.textContent;
                b.textContent = 'Copied!';
                setTimeout(() => { b.textContent = orig; }, 1500);
            } catch (e) { alert('Copy failed — select and copy manually.'); }
        },

        // Client-side render for custom templates (mirrors the property ad generator).
        hexToRgba(hex, a) {
            const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
            if (!m) return hex;
            return 'rgba(' + parseInt(m[1],16) + ',' + parseInt(m[2],16) + ',' + parseInt(m[3],16) + ',' + a + ')';
        },
        renderCustom(r) {
            const root = document.getElementById('adm-canvas-' + r.id);
            if (!root || !r.layout) return;
            root.innerHTML = '';
            const l = r.layout, prop = r.data || {};
            if (l.canvasBgMode === 'gradient') root.style.background = 'linear-gradient(' + (l.canvasBgAngle ?? 160) + 'deg,' + l.canvasBgFrom + ',' + l.canvasBgTo + ')';
            else root.style.background = l.canvasBg || '#071325';
            root.style.width = (l.canvasW || 1200) + 'px';
            root.style.height = (l.canvasH || 628) + 'px';
            (l.elements || []).forEach(el => {
                const div = document.createElement('div');
                let css = 'position:absolute;left:' + el.x + 'px;top:' + el.y + 'px;width:' + el.w + 'px;height:' + el.h + 'px;z-index:' + (el.zIndex || 1) + ';overflow:hidden;border-radius:' + (el.borderRadius || 0) + 'px;';
                if (el.rotation) css += 'transform:rotate(' + el.rotation + 'deg);';
                if (el.frameBorderWidth) css += 'border:' + el.frameBorderWidth + 'px solid ' + (el.frameBorderColor || '#fff') + ';';
                div.style.cssText = css;
                const f = el.field;
                if (ADM_IMAGE_FIELDS.includes(f)) {
                    const src = f === 'agency_logo' ? prop.logo : prop[f];
                    if (src) { const img = document.createElement('img'); img.src = src; img.style.cssText = 'width:100%;height:100%;object-fit:' + (el.objectFit || 'cover') + ';display:block;'; div.appendChild(img); }
                    else { div.style.background = 'linear-gradient(135deg,#0b2a4a,#143d6e)'; }
                } else if (f === 'color_block') { div.style.background = el.bg || '#07111e'; div.style.opacity = el.opacity ?? 1; }
                else if (f === 'shape') { div.style.background = el.bg || '#00b4d8'; div.style.opacity = el.opacity ?? 1; div.style.borderRadius = (el.borderRadius ?? 50) + '%'; }
                else if (f === 'gradient') { div.style.background = 'linear-gradient(' + (el.gradAngle || 180) + 'deg,' + (el.gradFrom || '#071325') + ',' + (el.gradTo || 'rgba(7,19,37,0)') + ')'; div.style.opacity = el.opacity ?? 1; }
                else if (f === 'line') { const bar = document.createElement('div'); bar.style.cssText = 'width:100%;height:' + (el.borderWidth || 3) + 'px;background:' + (el.color || '#00b4d8') + ';border-radius:2px;'; div.style.display = 'flex'; div.style.alignItems = 'center'; div.appendChild(bar); }
                else if (f === 'logo') {
                    div.style.display = 'flex'; div.style.alignItems = 'center'; div.style.padding = (el.padding || 0) + 'px';
                    if (prop.logo) { const img = document.createElement('img'); img.src = prop.logo; img.style.cssText = 'max-height:100%;max-width:100%;object-fit:contain;object-position:left center;'; div.appendChild(img); }
                    else { div.style.fontFamily = "'Figtree',Arial,sans-serif"; div.style.fontWeight = '900'; div.style.fontSize = (el.fontSize || 28) + 'px'; div.style.color = el.color || '#fff'; div.innerHTML = 'corex<span style="color:#33c4e0">os</span>'; }
                } else if (f === 'watermark') {
                    Object.assign(div.style, { display:'flex', alignItems:'center', justifyContent:'center', fontFamily:"'Figtree',Arial,sans-serif", fontWeight:'900', letterSpacing:'0.06em', textTransform:'uppercase' });
                    div.style.fontSize = (el.fontSize || 60) + 'px'; div.style.color = el.color || '#fff'; div.style.opacity = el.opacity ?? 0.06;
                    div.textContent = prop.watermark || el.text || 'COREX';
                } else {
                    let value = (f === 'custom_text' || f === 'badge') ? (el.text || el.label)
                        : ((prop[f] !== undefined && prop[f] !== null && prop[f] !== '') ? prop[f] : (el.preview || el.label));
                    Object.assign(div.style, { display:'flex', alignItems:'center', overflow:'hidden', fontFamily:"'Figtree',Arial,sans-serif" });
                    div.style.fontSize = (el.fontSize || 18) + 'px'; div.style.fontWeight = el.fontWeight || '600'; div.style.color = el.color || '#fff';
                    div.style.textAlign = el.textAlign || 'left'; div.style.textTransform = el.textTransform || 'none';
                    div.style.letterSpacing = (el.letterSpacing || 0) + 'em'; div.style.lineHeight = el.lineHeight ?? 1.2; div.style.padding = (el.padding || 8) + 'px';
                    const op = el.bgOpacity ?? 0;
                    if (op > 0) { div.style.background = this.hexToRgba(el.bgColor || '#000000', op); if (el.textAlign === 'center') div.style.justifyContent = 'center'; if (el.textAlign === 'right') div.style.justifyContent = 'flex-end'; }
                    const span = document.createElement('span'); span.style.width = '100%'; span.textContent = value; div.appendChild(span);
                }
                root.appendChild(div);
            });
        },
    };
}
</script>
@endsection
