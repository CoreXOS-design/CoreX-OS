{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — clause-level editor for one section of a draft (spec §11.14). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $parts = \App\Services\PlatformEsign\Agreement\AgreementContent::PARTS;
    $cfg = ['items' => $items, 'rev' => (int) $v->rev, 'urls' => ['save' => route('platform-esign.wording.section.save', [$v->id, $part]), 'render' => route('platform-esign.wording.render', $v->id)]];
@endphp
<div class="w-full space-y-4">
    @include('platform-esign._header', [
        'title' => 'Edit wording — ' . $partLabel, 'tab' => 'wording',
        'sub' => 'Draft copied from version ' . ($v->parent?->version ?? '—') . '. Click a clause to change it. Nothing reaches an agency until you publish.',
        'actions' => '<a href="' . route('platform-esign.wording.show', [$v->id, 'part' => $part]) . '" class="corex-btn-outline">← Back to the draft</a><a href="' . route('platform-esign.wording.preview', $v->id) . '" class="corex-btn-outline">Preview</a>',
    ])
    @include('platform-esign.wording._css')
    <style>
        .clause { position:relative; border:1px solid transparent; border-radius:4px; margin: 0 -10px; padding: 2px 10px; }
        .clause:hover { border-color:#cbd5e1; background:#f8fafc; }
        .clause.editing { border-color:#00b4d8; background:#f0fbfd; padding: 8px 10px 10px; }
        .clause .cbar { display:none; position:absolute; right:6px; top:-13px; gap:4px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:2px 4px; z-index:2; box-shadow:0 1px 4px rgba(15,23,42,.12); }
        .clause:hover .cbar, .clause.editing .cbar { display:flex; }
        .cbar button, .tbar button { font:inherit; font-size:.74rem; padding:.12rem .5rem; border:1px solid #cbd5e1; background:#f8fafc; border-radius:4px; cursor:pointer; color:#0b2a4a; }
        .cbar button:hover, .tbar button:hover { background:#e2f6fb; }
        .tbar { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:6px; }
        .clause textarea { width:100%; min-height: 6.5em; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; border:1px solid #94a3b8; border-radius:4px; padding:.5rem; background:#fff; color:#0f172a; resize: vertical; }
        .clause .live { margin-top:8px; padding:8px 10px; background:#fff; border:1px dashed #cbd5e1; border-radius:4px; }
        .savebar { position: sticky; top: 0; z-index: 20; background: var(--surface); border:1px solid var(--border); border-radius:6px; padding:.55rem .8rem; display:flex; flex-wrap:wrap; gap:.5rem 1rem; align-items:center; box-shadow: 0 2px 8px rgba(15,23,42,.08); }
        [x-cloak] { display:none !important; }
    </style>

    <div class="part-tabs">
        @foreach($parts as $k => $label)<a href="{{ route('platform-esign.wording.edit', [$v->id, $k]) }}" class="{{ $part === $k ? 'on' : '' }}" onclick="if (window.__clausesDirty && !confirm('You have unsaved changes in this section. Leave without saving?')) { event.preventDefault(); }">{{ $label }}</a>@endforeach
    </div>

    <div x-data="clauseEditor({{ \Illuminate\Support\Js::from($cfg) }})" x-init="init()" class="space-y-3" x-cloak>
        <div class="savebar">
            <button type="button" class="corex-btn-primary" :disabled="saving || !dirty" @click="save()" x-text="saving ? 'Saving…' : 'Save {{ $partLabel }}'"></button>
            <span class="text-sm" :style="dirty ? 'color: var(--ds-amber, #b45309);' : 'color: var(--text-muted);'" x-text="dirty ? 'Unsaved changes' : (msg || 'All changes saved')"></span>
            <span class="text-xs ml-auto" style="color: var(--text-muted);" x-text="items.length + ' clauses'"></span>
        </div>
        <div x-show="errors.length" class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">
            <div class="font-semibold" x-text="errMsg"></div>
            <template x-for="e in errors"><div x-text="e"></div></template>
        </div>

        <div class="doc-col agr">
            <template x-for="(it, i) in items" :key="it.id">
                <div class="clause" :class="{ editing: it.editing }">
                    <div class="cbar">
                        <button type="button" @click="it.editing ? done(it, i) : edit(it)" x-text="it.editing ? 'Done' : 'Edit'"></button>
                        <button type="button" @click="move(i, -1)" :disabled="i === 0" title="Move up">↑</button>
                        <button type="button" @click="move(i, 1)" :disabled="i === items.length - 1" title="Move down">↓</button>
                        <button type="button" @click="addBelow(i)" title="Add a clause below">+ Add below</button>
                        <button type="button" @click="remove(i)" title="Remove this clause" style="color:#b91c1c;">Remove</button>
                    </div>
                    <div x-show="!it.editing" @click="edit(it)" style="cursor:text;" x-html="it.html || '<p style=&quot;color:#94a3b8&quot;>(empty clause — click to write)</p>'"></div>
                    <div x-show="it.editing" x-cloak>
                        <div class="tbar" role="toolbar" aria-label="Formatting">
                            <button type="button" @click="fmt(it, 'bold')"><strong>B</strong> Bold</button>
                            <button type="button" @click="fmt(it, 'italic')"><em>I</em> Italic</button>
                            <button type="button" @click="fmt(it, 'link')">Link</button>
                            <button type="button" @click="fmt(it, 'h2')">Heading</button>
                            <button type="button" @click="fmt(it, 'clause')">Clause number</button>
                            <button type="button" @click="fmt(it, 'list')">List item</button>
                            <button type="button" @click="fmt(it, 'table')">Table</button>
                        </div>
                        <textarea :id="'ta' + it.id" x-model="it.md" @input="touch(it)" rows="5" spellcheck="true" aria-label="Clause text"></textarea>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            Write plain text. <code>**bold**</code> · <code>*italic*</code> · <code>[words](https://link)</code> · <code>## heading</code> · a blank line starts a new clause.
                            <span x-show="/\{\{/.test(it.md)" style="color:#92400e;"> Field markers like <code>&#123;&#123;f:reg_no&#125;&#125;</code> stay exactly as they are — move them, never remove or retype them.</span>
                        </div>
                        <div class="live"><div class="text-xs mb-1" style="color: var(--text-muted);">As it will read:</div><div x-html="it.html || '<em>(empty)</em>'"></div></div>
                    </div>
                </div>
            </template>
            <div class="pt-3"><button type="button" class="corex-btn-outline corex-btn-xs" @click="addBelow(items.length - 1)">+ Add a clause at the end</button></div>
        </div>
    </div>
</div>

<script>
function clauseEditor(cfg) {
    return {
        items: cfg.items.map((it, i) => ({ id: i + 1, md: it.md, html: it.html, editing: false })),
        nextId: cfg.items.length + 1, rev: cfg.rev, dirty: false, saving: false, msg: '', errors: [], errMsg: '', timers: {},
        init() {
            window.__clausesDirty = false;
            window.addEventListener('beforeunload', (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } });
        },
        setDirty(v) { this.dirty = v; window.__clausesDirty = v; },
        edit(it) { it.editing = true; this.$nextTick(() => document.getElementById('ta' + it.id)?.focus()); },
        done(it, i) { it.editing = false; if (!it.md.trim()) { this.items.splice(i, 1); this.setDirty(true); } },
        touch(it) { this.setDirty(true); this.msg = ''; clearTimeout(this.timers[it.id]); this.timers[it.id] = setTimeout(() => this.render(it), 350); },
        async render(it) {
            try {
                const r = await fetch(cfg.urls.render, { method: 'POST', headers: this.headers(), body: JSON.stringify({ md: it.md }) });
                if (r.ok) { it.html = (await r.json()).html; }
            } catch (e) { /* the preview is a convenience; saving does not depend on it */ }
        },
        headers() { return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' }; },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.items.length) return; const t = this.items[i]; this.items[i] = this.items[j]; this.items[j] = t; this.items = [...this.items]; this.setDirty(true); },
        addBelow(i) { const it = { id: this.nextId++, md: '', html: '', editing: true }; this.items.splice(i + 1, 0, it); this.setDirty(true); this.$nextTick(() => document.getElementById('ta' + it.id)?.focus()); },
        remove(i) { if (!confirm('Remove this clause from the draft? (The published version is not affected.)')) return; this.items.splice(i, 1); this.setDirty(true); },
        fmt(it, kind) {
            const ta = document.getElementById('ta' + it.id); if (!ta) return;
            const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value, sel = v.slice(s, e);
            let out = v, a = s, b = e;
            const wrap = (l, r, ph) => { const t = sel || ph; out = v.slice(0, s) + l + t + r + v.slice(e); a = s + l.length; b = a + t.length; };
            if (kind === 'bold') wrap('**', '**', 'bold text');
            else if (kind === 'italic') wrap('*', '*', 'italic text');
            else if (kind === 'link') { const t = sel || 'link text'; out = v.slice(0, s) + '[' + t + '](https://)' + v.slice(e); a = s + t.length + 3; b = a + 8; }
            else if (kind === 'h2' || kind === 'list') {
                const ls = v.lastIndexOf('\n', s - 1) + 1, mark = kind === 'h2' ? '## ' : '- ';
                const has = v.slice(ls).startsWith(mark);
                out = has ? v.slice(0, ls) + v.slice(ls + mark.length) : v.slice(0, ls) + mark + v.slice(ls);
                a = b = s + (has ? -mark.length : mark.length);
            } else if (kind === 'clause') { const ls = v.lastIndexOf('\n', s - 1) + 1; out = v.slice(0, ls) + '**0.0** ' + v.slice(ls); a = ls + 2; b = ls + 5; }
            else if (kind === 'table') { const t = '\n\n| Heading | Heading |\n|---|---|\n| Cell | Cell |\n\n'; out = v.slice(0, e) + t + v.slice(e); a = b = e + t.length; }
            ta.value = out; ta.focus(); ta.setSelectionRange(Math.max(0, a), Math.max(0, b));
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        },
        async save() {
            this.saving = true; this.errors = []; this.errMsg = '';
            const clauses = this.items.map(i => i.md).filter(m => m.trim() !== '');
            try {
                const r = await fetch(cfg.urls.save, { method: 'PUT', headers: this.headers(), body: JSON.stringify({ clauses, rev: this.rev }) });
                const d = await r.json().catch(() => ({}));
                if (r.ok) { this.rev = d.rev; this.setDirty(false); this.msg = 'Saved.'; this.items.forEach(i => { if (!i.md.trim()) { i.editing = false; } }); this.items = this.items.filter(i => i.md.trim() !== ''); }
                else { this.errMsg = d.message || 'Not saved.'; this.errors = d.errors || []; window.scrollTo({ top: 0, behavior: 'smooth' }); }
            } catch (e) { this.errMsg = 'Could not reach the server — your changes are still on this screen. Try Save again.'; }
            this.saving = false;
        },
    };
}
</script>
@endsection
