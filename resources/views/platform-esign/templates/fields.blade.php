{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign field editor (AT-447, spec §3A).
     Pick a signer + a field type, then drag a box on the page. Positions are stored as % of the page. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $roles = $template->roles();
    $palette = ['#0ea5e9', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#64748b'];
    $colors = collect($roles)->mapWithKeys(fn ($r, $i) => [$r['key'] => $palette[$i % 6]])->all();
@endphp
<div class="w-full space-y-4"
     x-data="fieldEditor({
        fields: {{ \Illuminate\Support\Js::from($fields) }},
        roles: {{ \Illuminate\Support\Js::from($roles) }},
        colors: {{ \Illuminate\Support\Js::from($colors) }},
        pages: {{ (int) $template->page_count }},
        pageUrl: '{{ route('platform-esign.templates.page', [$template->id, '__P__']) }}',
        saveUrl: '{{ route('platform-esign.templates.fields.save', $template->id) }}',
        csrf: '{{ csrf_token() }}'
     })">
    @include('platform-esign._header', ['title' => 'Place fields — ' . $template->name, 'tab' => 'templates',
        'sub' => 'Choose who signs and what kind of field, then drag a box on the page. Click a box to select it; Delete removes it.',
        'actions' => '<a href="' . route('platform-esign.templates.index') . '" class="corex-btn-outline">← Templates</a>'])

    <div class="rounded-md p-3 flex flex-wrap items-center gap-3 sticky top-0 z-20" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold" style="color: var(--text-muted);">Signer</span>
            <template x-for="r in roles" :key="r.key">
                <button type="button" @click="role = r.key" class="px-3 py-1.5 rounded-md text-xs font-semibold"
                        :style="`border: 2px solid ${colors[r.key]}; background: ${role === r.key ? colors[r.key] : 'transparent'}; color: ${role === r.key ? '#fff' : 'var(--text-primary)'};`" x-text="r.label"></button>
            </template>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold" style="color: var(--text-muted);">Field</span>
            <template x-for="t in types" :key="t.k">
                <button type="button" @click="type = t.k" class="px-3 py-1.5 rounded-md text-xs font-semibold"
                        :style="`border: 1px solid var(--border); background: ${type === t.k ? 'var(--brand-icon)' : 'var(--surface-2)'}; color: ${type === t.k ? '#fff' : 'var(--text-primary)'};`" x-text="t.l"></button>
            </template>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs" style="color: var(--text-muted);" x-text="status"></span>
            <span class="text-xs tabular-nums" style="color: var(--text-muted);" x-text="fields.length + ' fields'"></span>
            <button type="button" class="corex-btn-primary" @click="save()" :disabled="saving">Save fields</button>
        </div>
    </div>

    <div class="flex gap-4 items-start">
        <div class="flex-1 min-w-0 space-y-4">
            <template x-for="p in pages" :key="p">
                <div class="mx-auto" style="max-width: 820px;">
                    <div class="text-xs mb-1" style="color: var(--text-muted);" x-text="'Page ' + p + ' of ' + pages"></div>
                    <div class="relative select-none" style="border: 1px solid var(--border); background:#fff; line-height:0;"
                         @mousedown="startDraw($event, p - 1)" :data-page="p - 1">
                        <img :src="pageUrl.replace('__P__', p - 1)" class="w-full block pointer-events-none" draggable="false" alt="">
                        <template x-for="f in fields.filter(x => x.page_index === p - 1)" :key="f._id">
                            <div @mousedown.stop="select(f, $event)"
                                 class="absolute flex items-center justify-center text-[10px] font-semibold overflow-hidden"
                                 :style="`left:${f.x}%; top:${f.y}%; width:${f.w}%; height:${f.h}%; border:2px solid ${colors[f.role_key]}; background:${colors[f.role_key]}22; color:${colors[f.role_key]}; cursor: move; line-height:1.1; outline:${sel === f ? '2px dashed #111' : 'none'};`">
                                <span x-text="label(f)"></span>
                                <span class="absolute right-0 bottom-0 w-3 h-3" style="background:#111; cursor: nwse-resize;" @mousedown.stop="startResize(f, $event)"></span>
                            </div>
                        </template>
                        <div x-show="draft && draft.page_index === p - 1" class="absolute" :style="draft ? `left:${draft.x}%; top:${draft.y}%; width:${draft.w}%; height:${draft.h}%; border:2px dashed ${colors[role]}; background:${colors[role]}22;` : ''"></div>
                    </div>
                </div>
            </template>
        </div>
        <div class="hidden lg:block w-64 shrink-0 rounded-md p-4 sticky top-20" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="ds-section-header mb-2">Selected field</div>
            <template x-if="sel">
                <div class="space-y-3">
                    <div><label class="ds-label block mb-1">Signer</label>
                        <select class="ds-field w-full" x-model="sel.role_key"><template x-for="r in roles" :key="r.key"><option :value="r.key" x-text="r.label"></option></template></select></div>
                    <div><label class="ds-label block mb-1">Type</label>
                        <select class="ds-field w-full" x-model="sel.type"><template x-for="t in types" :key="t.k"><option :value="t.k" x-text="t.l"></option></template></select></div>
                    <div x-show="sel.type === 'text' || sel.type === 'date'"><label class="ds-label block mb-1">Label (shown to the signer)</label>
                        <input class="ds-field w-full" x-model="sel.label" maxlength="120" placeholder="e.g. Company registration no."></div>
                    <label class="flex items-center gap-2 text-xs" x-show="sel.type === 'text' || sel.type === 'date'"><input type="checkbox" x-model="sel.required"> Required</label>
                    <button type="button" class="corex-btn-outline w-full" style="color: var(--ds-crimson);" @click="remove(sel)">Delete field</button>
                </div>
            </template>
            <p class="text-xs" style="color: var(--text-muted);" x-show="!sel">Nothing selected. Draw a box on a page, or click an existing one.</p>
            <p class="text-xs mt-4" style="color: var(--text-muted);">Every signer needs at least one <strong>Signature</strong> field before the template can be sent. Date fields fill themselves with the signing date if left empty.</p>
        </div>
    </div>
    @include('platform-esign._end')
</div>

<script>
function fieldEditor(cfg) {
    let seq = 1;
    return {
        fields: cfg.fields.map(f => ({...f, x: +f.x, y: +f.y, w: +f.w, h: +f.h, required: !!f.required, _id: seq++})),
        roles: cfg.roles, colors: cfg.colors, pages: cfg.pages, pageUrl: cfg.pageUrl,
        role: cfg.roles[0].key, type: 'signature', sel: null, draft: null, status: '', saving: false,
        types: [{k:'signature', l:'Signature'}, {k:'initial', l:'Initials'}, {k:'date', l:'Date'}, {k:'text', l:'Text'}],
        label(f) {
            const r = this.roles.find(r => r.key === f.role_key);
            const t = this.types.find(t => t.k === f.type);
            return (r ? r.label : '') + ' · ' + (f.label || t.l);
        },
        pt(e, el) { const b = el.getBoundingClientRect(); return {x: (e.clientX - b.left) / b.width * 100, y: (e.clientY - b.top) / b.height * 100}; },
        clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); },
        select(f, e) {
            this.sel = f;
            const el = e.currentTarget.parentElement, start = this.pt(e, el), ox = f.x, oy = f.y;
            const move = ev => { const p = this.pt(ev, el); f.x = this.clamp(ox + p.x - start.x, 0, 100 - f.w); f.y = this.clamp(oy + p.y - start.y, 0, 100 - f.h); };
            const up = () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
            window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        },
        startResize(f, e) {
            const el = e.currentTarget.parentElement.parentElement;
            const move = ev => { const p = this.pt(ev, el); f.w = this.clamp(p.x - f.x, 2, 100 - f.x); f.h = this.clamp(p.y - f.y, 1.2, 100 - f.y); };
            const up = () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
            window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        },
        startDraw(e, page) {
            if (e.button !== 0) return;
            this.sel = null;
            const el = e.currentTarget, s = this.pt(e, el);
            this.draft = {page_index: page, x: s.x, y: s.y, w: 0, h: 0};
            const move = ev => { const p = this.pt(ev, el); this.draft = {page_index: page, x: Math.min(s.x, p.x), y: Math.min(s.y, p.y), w: Math.abs(p.x - s.x), h: Math.abs(p.y - s.y)}; };
            const up = () => {
                window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up);
                const d = this.draft; this.draft = null;
                const dflt = {signature: [24, 5], initial: [9, 4], date: [18, 3], text: [30, 3]}[this.type];
                const f = {_id: seq++, page_index: page, x: this.clamp(d.x, 0, 100), y: this.clamp(d.y, 0, 100),
                    w: d.w > 1.5 ? d.w : dflt[0], h: d.h > 1 ? d.h : dflt[1], type: this.type, role_key: this.role, label: '', required: true};
                f.x = this.clamp(f.x, 0, 100 - f.w); f.y = this.clamp(f.y, 0, 100 - f.h);
                this.fields.push(f); this.sel = f;
            };
            window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        },
        remove(f) { this.fields = this.fields.filter(x => x !== f); this.sel = null; },
        init() { window.addEventListener('keydown', e => { if ((e.key === 'Delete' || e.key === 'Backspace') && this.sel && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); this.remove(this.sel); } }); },
        async save() {
            this.saving = true; this.status = 'Saving…';
            try {
                const res = await fetch(cfg.saveUrl, {method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf},
                    body: JSON.stringify({fields: this.fields.map(({_id, ...f}) => f)})});
                this.status = res.ok ? 'Saved ✓' : 'Could not save (' + res.status + ')';
            } catch (e) { this.status = 'Could not save'; }
            this.saving = false;
        },
    };
}
</script>
@endsection
