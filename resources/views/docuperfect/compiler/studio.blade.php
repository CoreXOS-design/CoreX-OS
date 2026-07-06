{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-177 / WS4-S — the Compile Studio workbench: segmentation → binding → topology → gate → publish. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    // Flatten the dictionary for the binding <select> (grouped by category → options).
    $dictJson = json_encode($dictionary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $structureJson = json_encode($structure ?: (object)[], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lintJson = json_encode($lintReport ?: null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<div class="w-full space-y-4"
     x-data="compileStudio({
        draftId: {{ $draft->id }},
        structure: {{ $structureJson }},
        dictionary: {{ $dictJson }},
        lint: {{ $lintJson }},
        lintStatus: @js($draft->lint_status),
        published: @js($isPublished),
        urls: {
            bindField: '{{ route('docuperfect.compiler.bindField', $draft->id) }}',
            structure: '{{ route('docuperfect.compiler.updateStructure', $draft->id) }}',
            party: '{{ route('docuperfect.compiler.declareParty', $draft->id) }}',
            suggest: '{{ route('docuperfect.compiler.suggest', $draft->id) }}',
            lint: '{{ route('docuperfect.compiler.lint', $draft->id) }}',
            certify: '{{ route('docuperfect.compiler.certify', $draft->id) }}',
            publish: '{{ route('docuperfect.compiler.publish', $draft->id) }}',
            index: '{{ route('docuperfect.compiler.index') }}',
        }
     })">

    {{-- Header --}}
    <div class="rounded-md px-6 py-4" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('docuperfect.compiler.index') }}" class="text-white/60 text-xs">Compile Studio</a>
                    <span class="text-white/40">/</span>
                    <h1 class="text-lg font-bold text-white leading-tight">{{ $draft->family ?: 'Untitled draft' }}</h1>
                </div>
                <p class="text-xs text-white/60 mt-0.5" x-text="`${blocks.length} blocks · ${allFields.length} fields (${unboundFields.length} unbound) · ${(structure.parties||[]).length} parties`"></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="ds-badge" :class="lintBadge.cls" x-text="lintBadge.label"></span>
            </div>
        </div>
    </div>

    {{-- Toast --}}
    <template x-if="toast">
        <div class="rounded-md px-4 py-2.5 text-sm" :style="toastStyle" x-text="toast"></div>
    </template>

    {{-- Stage tabs --}}
    <div class="flex flex-wrap gap-1 rounded-md p-1" style="background: var(--surface-2); border:1px solid var(--border);">
        <template x-for="s in stages" :key="s.key">
            <button type="button" @click="stage = s.key"
                    class="text-xs font-semibold px-4 py-2 rounded"
                    :style="stage===s.key ? 'background:var(--surface);color:var(--text-primary);box-shadow:0 1px 2px rgba(0,0,0,0.06);' : 'color:var(--text-secondary);'"
                    x-text="s.label"></button>
        </template>
    </div>

    {{-- ── SEGMENTATION ─────────────────────────────────────────── --}}
    <div x-show="stage==='segment'" class="space-y-2">
        <p class="text-xs" style="color: var(--text-muted);">Review the typed blocks. Retype a mis-segmented block or remove it. Each block has a stable id — it is never addressed by position.</p>
        <template x-for="(b, i) in blocks" :key="b.block_id">
            <div class="rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);" :id="'block-'+b.block_id">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-mono text-[11px] px-1.5 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-muted);" x-text="b.block_id"></span>
                            <select @change="retypeBlock(i, $event.target.value)" class="text-[11px] rounded px-1.5 py-0.5" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                                <template x-for="t in blockTypes" :key="t">
                                    <option :value="t" :selected="b.type===t" x-text="t"></option>
                                </template>
                            </select>
                            <span class="text-[11px]" style="color: var(--text-muted);" x-text="visSummary(b)"></span>
                        </div>
                        <div class="text-xs prose-preview" style="color: var(--text-secondary); max-height: 4.5rem; overflow: hidden;" x-html="b.html || fieldPreview(b)"></div>
                    </div>
                    <button type="button" @click="removeBlock(i)" class="text-[11px] font-semibold shrink-0" style="color: var(--ds-crimson);">Remove</button>
                </div>
            </div>
        </template>
        <template x-if="!blocks.length"><div class="text-sm px-4 py-8 text-center" style="color: var(--text-muted);">No blocks — this draft is empty. Add prose/signature blocks or start from a reference template.</div></template>
    </div>

    {{-- ── FIELD BINDING ────────────────────────────────────────── --}}
    <div x-show="stage==='bind'" class="space-y-2">
        <p class="text-xs" style="color: var(--text-muted);">Every fill-point must bind to a typed Data Dictionary entry. <span style="color: var(--ds-crimson);">Unbound fields are blocking</span> — a template with any unbound field cannot publish (linter L1).</p>
        <template x-if="!allFields.length"><div class="text-sm px-4 py-8 text-center" style="color: var(--text-muted);">This template has no fill-points (a zero-field document). Nothing to bind — proceed to Topology.</div></template>
        <template x-for="f in allFields" :key="f.block_id+'::'+f.field_id">
            <div class="rounded-md p-3 flex items-center gap-3 flex-wrap" style="background: var(--surface);"
                 :style="f.binding ? 'border:1px solid var(--border);' : 'border:1px solid var(--ds-crimson);'">
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium" style="color: var(--text-primary);" x-text="f.label || f.field_id"></div>
                    <div class="text-[11px] font-mono" style="color: var(--text-muted);" x-text="f.block_id + ' · ' + f.field_id"></div>
                </div>
                <template x-if="!f.binding"><span class="ds-badge ds-badge-danger">Unbound</span></template>
                <template x-if="f.binding"><span class="ds-badge ds-badge-success" x-text="f.binding"></span></template>
                <select @change="bindField(f, $event.target.value)" class="text-xs rounded px-2 py-1.5" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary); min-width: 14rem;">
                    <option value="">— bind to dictionary —</option>
                    <template x-for="(entries, cat) in dictionary" :key="cat">
                        <optgroup :label="cat">
                            <template x-for="e in entries" :key="e.key">
                                <option :value="e.key" :selected="f.binding===e.key" x-text="e.label + ' ('+e.key+')'"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
                <button type="button" @click="suggestFor(f)" class="text-[11px] font-semibold px-2 py-1.5 rounded" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">Suggest</button>
                <template x-if="suggestions[f.field_id]">
                    <div class="w-full flex flex-wrap gap-1 mt-1">
                        <template x-for="s in suggestions[f.field_id]" :key="s.dictionary_key">
                            <button type="button" @click="bindField(f, s.dictionary_key)" class="text-[11px] px-2 py-1 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);"
                                    x-text="s.dictionary_key + ' · ' + Math.round(s.confidence*100) + '%'"></button>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ── TOPOLOGY ─────────────────────────────────────────────── --}}
    <div x-show="stage==='topology'" class="space-y-4">
        {{-- Parties --}}
        <div class="rounded-md p-4" style="background: var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-muted);">Signing parties</h3>
            <template x-for="(p, i) in (structure.parties||[])" :key="p.key">
                <div class="flex items-center gap-2 py-1.5 flex-wrap" style="border-bottom:1px solid var(--border);">
                    <span class="font-mono text-[11px] px-1.5 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-primary);" x-text="p.key"></span>
                    <span class="text-xs" style="color: var(--text-secondary);" x-text="p.role"></span>
                    <span class="text-[11px]" style="color: var(--text-muted);" x-text="p.cardinality==='one_or_more' ? '1..n' : '1'"></span>
                    <span class="text-[11px]" style="color: var(--text-muted);" x-text="'order '+ (p.ordering||0)"></span>
                    <button type="button" @click="removeParty(i)" class="text-[11px] font-semibold ml-auto" style="color: var(--ds-crimson);">Remove</button>
                </div>
            </template>
            <div class="flex items-end gap-2 mt-3 flex-wrap">
                <div><label class="block text-[10px] uppercase" style="color:var(--text-muted);">Key</label><input x-model="newParty.key" placeholder="seller" class="text-xs rounded px-2 py-1.5 w-28" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></div>
                <div><label class="block text-[10px] uppercase" style="color:var(--text-muted);">Role</label><input x-model="newParty.role" placeholder="Seller" class="text-xs rounded px-2 py-1.5 w-28" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></div>
                <div><label class="block text-[10px] uppercase" style="color:var(--text-muted);">Cardinality</label>
                    <select x-model="newParty.cardinality" class="text-xs rounded px-2 py-1.5" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"><option value="one">1</option><option value="one_or_more">1..n</option></select></div>
                <div><label class="block text-[10px] uppercase" style="color:var(--text-muted);">Order</label><input type="number" x-model.number="newParty.ordering" class="text-xs rounded px-2 py-1.5 w-16" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></div>
                <button type="button" @click="addParty()" class="text-xs font-semibold px-3 py-1.5 rounded" style="background: var(--brand-default,#0b2a4a); color:#fff;">Add / update party</button>
            </div>
        </div>

        {{-- Signature anchors --}}
        <div class="rounded-md p-4" style="background: var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-muted);">Signature surfaces (anchors)</h3>
            <p class="text-[11px] mb-2" style="color: var(--text-muted);">Every declared party needs ≥1 signature anchor (linter L3). Add an anchor onto a signature block for a party.</p>
            <template x-for="(b, bi) in signatureBlocks" :key="b.block_id">
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-mono text-[11px]" style="color: var(--text-muted);" x-text="b.block_id"></span>
                        <template x-for="a in (b.anchors||[])" :key="a.anchor_id">
                            <span class="ds-badge ds-badge-info" x-text="a.kind + ' → ' + a.party_key"></span>
                        </template>
                        <select x-model="anchorDraft[b.block_id]" class="text-[11px] rounded px-1.5 py-0.5 ml-auto" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                            <option value="">party…</option>
                            <template x-for="p in (structure.parties||[])" :key="p.key"><option :value="p.key" x-text="p.key"></option></template>
                        </select>
                        <button type="button" @click="addAnchor(b)" class="text-[11px] font-semibold px-2 py-0.5 rounded" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">+ signature</button>
                    </div>
                </div>
            </template>
            <template x-if="!signatureBlocks.length"><p class="text-[11px]" style="color: var(--text-muted);">No signature blocks. Retype a block to 'signature' in Segmentation to add signing surfaces.</p></template>
        </div>
    </div>

    {{-- ── GATE + PUBLISH ───────────────────────────────────────── --}}
    <div x-show="stage==='gate'" class="space-y-3">
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" @click="runLint()" :disabled="busy" class="corex-btn-primary text-sm">Run linter (L1–L7)</button>
            <button type="button" @click="runCertify()" :disabled="busy" class="text-sm font-semibold px-4 py-2 rounded" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">Certify (golden harness)</button>
            <div class="ml-auto flex items-center gap-2">
                <span class="text-[11px]" style="color: var(--text-muted);" x-text="publishable ? 'Gate clean — ready to publish' : 'Publish blocked until lint-clean + certified'"></span>
                <button type="button" @click="publish()" :disabled="!publishable || busy"
                        class="text-sm font-semibold px-4 py-2 rounded"
                        :style="(publishable && !busy) ? 'background: var(--ds-green,#059669); color:#fff;' : 'background: var(--surface-2); color: var(--text-muted); border:1px solid var(--border); cursor:not-allowed;'">
                    Publish immutable version
                </button>
            </div>
        </div>

        {{-- Lint report --}}
        <template x-if="lint">
            <div class="rounded-md p-4" style="background: var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-3 mb-2">
                    <span class="ds-badge" :class="lint.publishable ? 'ds-badge-success' : 'ds-badge-danger'" x-text="lint.publishable ? 'Publishable' : 'Blocked'"></span>
                    <span class="text-xs" style="color: var(--text-muted);" x-text="`${lint.counts.error} errors · ${lint.counts.pending} pending · ${lint.counts.warning} warnings`"></span>
                </div>
                <template x-for="(f, i) in lint.findings" :key="i">
                    <template x-if="f.severity !== 'pass'">
                        <div class="flex items-start gap-2 py-1.5 text-xs" style="border-top:1px solid var(--border);">
                            <span class="ds-badge" :class="sevCls(f.severity)" x-text="f.rule"></span>
                            <div class="min-w-0">
                                <span x-text="f.message" style="color: var(--text-secondary);"></span>
                                <template x-if="f.target">
                                    <button type="button" @click="gotoBlock(f.target)" class="ml-1 font-mono text-[11px]" style="color: var(--brand-icon);" x-text="'['+f.target+']'"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </template>
                <template x-if="lint.publishable"><div class="text-xs pt-2" style="color: var(--ds-green,#059669);">All rules pass. ✓</div></template>
            </div>
        </template>

        {{-- Golden report --}}
        <template x-if="golden">
            <div class="rounded-md p-4" style="background: var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-3 mb-2">
                    <span class="ds-badge" :class="golden.certifiable ? 'ds-badge-success' : 'ds-badge-danger'" x-text="golden.certifiable ? 'Certified' : 'Not certifiable'"></span>
                    <span class="text-xs" style="color: var(--text-muted);" x-text="`${golden.combination_count} party combinations`"></span>
                </div>
                <template x-for="(c, i) in golden.combinations" :key="i">
                    <div class="flex items-center gap-2 py-1 text-xs" style="border-top:1px solid var(--border);">
                        <span class="ds-badge" :class="c.structurally_passed ? 'ds-badge-success' : 'ds-badge-danger'" x-text="c.structurally_passed ? 'ok' : 'fail'"></span>
                        <span x-text="c.label" style="color: var(--text-secondary);"></span>
                        <template x-if="c.render_pending"><span class="text-[11px]" style="color: var(--ds-amber,#f59e0b);">render pending</span></template>
                    </div>
                </template>
            </div>
        </template>
    </div>

</div>

<script>
function compileStudio(cfg) {
    return {
        draftId: cfg.draftId,
        structure: cfg.structure && cfg.structure.blocks ? cfg.structure : { family:'', parties:[], blocks:[], delivery_modes:['web_esign','pdf_wetink','download'], legal_class:'general', data_dictionary_version:1 },
        dictionary: cfg.dictionary || {},
        urls: cfg.urls,
        lint: cfg.lint || null,
        golden: null,
        lintStatus: cfg.lintStatus,
        published: cfg.published,
        stage: 'segment',
        stages: [ {key:'segment',label:'Segmentation'}, {key:'bind',label:'Field binding'}, {key:'topology',label:'Topology'}, {key:'gate',label:'Gate & publish'} ],
        blockTypes: ['prose','clause','field_group','signature','initial','insertable_slot','letterhead','page_break','conditional'],
        newParty: { key:'', role:'', cardinality:'one', ordering:0, required:true },
        anchorDraft: {},
        suggestions: {},
        busy: false,
        toast: '',
        toastKind: 'ok',

        get blocks() { return this.structure.blocks || []; },
        get allFields() {
            const out = [];
            (this.structure.blocks||[]).forEach(b => (b.fields||[]).forEach(f => out.push({...f, block_id: b.block_id})));
            return out;
        },
        get unboundFields() { return this.allFields.filter(f => !f.binding || !String(f.binding).trim()); },
        get signatureBlocks() { return (this.structure.blocks||[]).filter(b => b.type==='signature' || b.type==='initial'); },
        get publishable() { return this.lint && this.lint.publishable && this.golden && this.golden.certifiable; },
        get toastStyle() {
            const g = 'background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);';
            const r = 'background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);';
            return this.toastKind==='err' ? r : g;
        },
        get lintBadge() {
            if (this.published) return {label:'Published', cls:'ds-badge-info'};
            if (this.lintStatus==='passed') return {label:'Lint clean', cls:'ds-badge-success'};
            if (this.lintStatus==='failed') return {label:'Lint failing', cls:'ds-badge-danger'};
            return {label:'Not linted', cls:'ds-badge-default'};
        },

        sevCls(s) { return {error:'ds-badge-danger', pending:'ds-badge-default', warning:'ds-badge-default', pass:'ds-badge-success'}[s] || 'ds-badge-default'; },
        visSummary(b) { const v=(b.visibility&&b.visibility.mode)||'all'; return 'sees: '+v; },
        fieldPreview(b) { return (b.fields||[]).map(f => (f.label||f.field_id)).join(', ') || '<span style="color:var(--text-muted)">(no preview)</span>'; },

        flash(msg, kind='ok') { this.toast = msg; this.toastKind = kind; setTimeout(()=>{ if(this.toast===msg) this.toast=''; }, 4500); },

        async post(url, body) {
            const r = await fetch(url, {
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'},
                body: JSON.stringify(body||{})
            });
            let data = {};
            try { data = await r.json(); } catch(e) {}
            return { ok: r.ok && data.ok !== false, status: r.status, data };
        },

        // Bindings go through the dedicated endpoint (satisfies L1 directly).
        async bindField(f, key) {
            if (!key) return;
            this.busy = true;
            const res = await this.post(this.urls.bindField, { block_id: f.block_id, field_id: f.field_id, dictionary_key: key });
            this.busy = false;
            if (res.ok) { this.structure = res.data.structure; this.lintStatus = res.data.lint_status; delete this.suggestions[f.field_id]; this.flash('Bound '+f.field_id+' → '+key); }
            else this.flash(res.data.error || 'Bind failed', 'err');
        },

        async suggestFor(f) {
            const res = await this.post(this.urls.suggest, { label: f.label||f.field_id, context: '' });
            this.suggestions[f.field_id] = (res.data.suggestions||[]);
            if (!this.suggestions[f.field_id].length) this.flash('No confident suggestion — pick manually', 'err');
        },

        // Structural edits mutate local state then bulk-save (round-tripped through the CDS DTO server-side).
        async saveStructure() {
            this.busy = true;
            const res = await this.post(this.urls.structure, { structure: this.structure });
            this.busy = false;
            if (res.ok) { this.structure = res.data.structure; this.lintStatus = res.data.lint_status; }
            else this.flash(res.data.error || 'Save failed', 'err');
            return res.ok;
        },
        retypeBlock(i, type) { this.structure.blocks[i].type = type; this.saveStructure(); },
        removeBlock(i) { if(!confirm('Remove this block?')) return; this.structure.blocks.splice(i,1); this.saveStructure(); },
        removeParty(i) { this.structure.parties.splice(i,1); this.saveStructure(); },

        async addParty() {
            if (!this.newParty.key.trim()) { this.flash('A party needs a key','err'); return; }
            const res = await this.post(this.urls.party, { party: { ...this.newParty, role: this.newParty.role || this.newParty.key } });
            if (res.ok) { this.structure = res.data.structure; this.lintStatus = res.data.lint_status; this.newParty = {key:'',role:'',cardinality:'one',ordering:0,required:true}; this.flash('Party declared'); }
            else this.flash(res.data.error||'Failed','err');
        },
        addAnchor(b) {
            const party = this.anchorDraft[b.block_id];
            if (!party) { this.flash('Pick a party for the anchor','err'); return; }
            b.anchors = b.anchors || [];
            b.anchors.push({ anchor_id: b.block_id+'_'+party+'_'+(b.anchors.length+1), kind:'signature', party_key: party });
            this.anchorDraft[b.block_id] = '';
            this.saveStructure().then(ok => { if(ok) this.flash('Anchor added for '+party); });
        },

        async runLint() {
            this.busy = true; this.golden = null;
            const res = await this.post(this.urls.lint, {});
            this.busy = false;
            if (res.data && res.data.lint) { this.lint = res.data.lint; this.lintStatus = res.data.lint_status; this.flash(this.lint.publishable ? 'Lint clean ✓' : 'Lint found blocking issues', this.lint.publishable?'ok':'err'); }
            else this.flash((res.data&&res.data.error)||'Lint failed','err');
        },
        async runCertify() {
            this.busy = true;
            const res = await this.post(this.urls.certify, {});
            this.busy = false;
            if (res.data && res.data.golden) { this.golden = res.data.golden; this.flash(this.golden.certifiable ? 'Certified ✓' : 'Not certifiable', this.golden.certifiable?'ok':'err'); }
            else this.flash((res.data&&res.data.error)||'Certify failed','err');
        },
        async publish() {
            if (!this.publishable) return;
            if (!confirm('Publish this as an immutable, content-hashed version? This cannot be edited afterwards — only superseded by a new version.')) return;
            this.busy = true;
            const res = await this.post(this.urls.publish, {});
            this.busy = false;
            if (res.ok) { this.flash(res.data.message || 'Published'); setTimeout(()=>{ window.location = this.urls.index; }, 1200); }
            else this.flash(res.data.error || 'Publish blocked', 'err');
        },
        gotoBlock(target) {
            this.stage = 'segment';
            this.$nextTick(() => { const el = document.getElementById('block-'+target); if (el) { el.scrollIntoView({block:'center'}); el.style.outline='2px solid var(--ds-amber,#f59e0b)'; setTimeout(()=>el.style.outline='',2000); } });
        },
    };
}
</script>
@endsection
