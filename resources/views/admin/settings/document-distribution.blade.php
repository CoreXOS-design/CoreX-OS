@extends('layouts.corex')
@section('title', 'Deal Document Distribution')

{{--
    .ai/specs/signed-document-distribution.md "The settings screen". ONE matrix: documents down the side, parties across the top,
    a tick per cell. Page standard: the left menu and this header stay put; the matrix is the content block and fits 1366x768 without
    scrolling the page (it scrolls INSIDE its own frame only when there are more document types than the height, with the party row and
    the document column pinned).
--}}
@section('corex-content')
@php
    $payload = [
        'types'    => $types->map(fn ($t) => ['id' => $t->id, 'label' => $t->label])->values(),
        'roles'    => collect($columns)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        'initial'  => (object) collect($cells)->map(fn ($r) => (object) $r)->all(),
        'defaults' => (object) $defaults,
    ];
@endphp
<style>
    .dd-page { height: 100%; display: flex; flex-direction: column; gap: .6rem; min-height: 0; }
    .dd-frame { flex: 1 1 auto; min-height: 0; overflow: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .dd-grid { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 760px; font-size: .8rem; }
    .dd-grid th, .dd-grid td { padding: .3rem .5rem; border-bottom: 1px solid var(--border); background: var(--surface); }
    .dd-grid thead th { position: sticky; top: 0; z-index: 3; background: var(--surface-2, #f3f4f6); font-weight: 600; color: var(--text-secondary); text-align: center; line-height: 1.15; vertical-align: bottom; font-size: .72rem; }
    .dd-grid th:first-child, .dd-grid td:first-child { position: sticky; left: 0; z-index: 2; text-align: left; min-width: 15rem; border-right: 1px solid var(--border); }
    .dd-grid thead th:first-child { z-index: 4; }
    .dd-grid td.dd-cell { text-align: center; width: 6.5rem; }
    .dd-grid tbody tr:hover td { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface)); }
    .dd-grid input[type=checkbox] { width: 1.05rem; height: 1.05rem; cursor: pointer; accent-color: var(--brand-icon, #0ea5e9); }
    .dd-changed { background: color-mix(in srgb, #f59e0b 16%, var(--surface)) !important; }
    .dd-dot { display: inline-block; width: .42rem; height: .42rem; border-radius: 50%; background: var(--brand-icon, #0ea5e9); margin-left: .25rem; vertical-align: middle; }
    .dd-modal-back { position: fixed; inset: 0; background: rgba(2,10,20,.45); z-index: 80; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .dd-modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-width: 34rem; width: 100%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(2,10,20,.35); }
    .dd-modal .dd-body { overflow: auto; padding: .9rem 1.1rem; font-size: .8rem; color: var(--text-secondary); }
</style>

<div class="dd-page" x-data="ddMatrix({{ \Illuminate\Support\Js::from($payload) }})" data-dd-page>

    {{-- Header (stays in view) --}}
    <div class="rounded-md corex-page-banner" style="padding:.7rem 1.1rem; flex: 0 0 auto;">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal Document Distribution</h1>
                <p class="text-xs" style="color: var(--text-muted); max-width: 62rem;">
                    Tick who may receive each kind of document. This one table decides which documents are offered when you email a party from a deal,
                    and which documents an attorney can download from their portal. A document is only ever offered if it is filed on that deal.
                </p>
            </div>
            <div class="text-xs flex items-center gap-3" style="color: var(--text-muted); white-space: nowrap;">
                @if($last)
                    <span data-dd-last>Last changed {{ $last->created_at->format('j M Y H:i') }} by {{ $last->user?->name ?? 'someone' }}</span>
                    <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.2rem .6rem;" @click="showHistory = true" data-dd-history>History</button>
                @else
                    <span>No changes saved yet &mdash; showing the standard set-up.</span>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);" data-dd-flash>{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.document-distribution.save') }}" x-ref="form" class="flex flex-col" style="flex: 1 1 auto; min-height: 0; gap:.6rem;">
        @csrf
        <input type="hidden" name="grid" value="1">

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-2" style="flex: 0 0 auto;">
            <input type="search" x-model="q" placeholder="Find a document type..." class="corex-input" style="font-size:.8rem; width: 16rem;" aria-label="Find a document type" data-dd-search>
            <select x-model="filter" class="corex-input" style="font-size:.8rem;" aria-label="Show" data-dd-filter>
                <option value="all">All document types</option>
                <option value="ticked">Only those someone can receive</option>
                <option value="unticked">Only those nobody can receive</option>
            </select>
            <span class="text-xs" style="color: var(--text-muted);" x-text="shownCount() + ' of ' + types.length + ' document types'"></span>
            <span class="text-xs flex-1" style="color: var(--text-muted);"><span class="dd-dot" style="margin-left:0"></span> = also sent automatically at a stage (set up under <a href="{{ route('admin.settings.deal-distribution-rules.index') }}" style="color: var(--brand-icon);">Deal Distribution Rules</a>)</span>
            <button type="button" class="corex-btn-outline" style="font-size:.75rem;padding:.3rem .7rem;" @click="restoreDefaults()" data-dd-restore
                    title="Puts the standard set-up back on screen. Nothing changes until you press Save.">Restore standard set-up</button>
        </div>

        {{-- The matrix --}}
        <div class="dd-frame" data-dd-frame>
            <table class="dd-grid" data-dd-grid>
                <thead>
                    <tr>
                        <th scope="col">Document</th>
                        @foreach($columns as $key => $label)
                            <th scope="col">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        <tr x-show="isShown({{ $type->id }})" data-type-id="{{ $type->id }}">
                            <td style="color: var(--text-primary); font-weight: 500;">{{ $type->label }}</td>
                            @foreach($columns as $key => $label)
                                <td class="dd-cell" :class="isChanged({{ $type->id }}, '{{ $key }}') ? 'dd-changed' : ''">
                                    <input type="checkbox" name="cell[{{ $type->id }}][{{ $key }}]" value="1"
                                           :checked="state[{{ $type->id }}] && state[{{ $type->id }}]['{{ $key }}']"
                                           @change="set({{ $type->id }}, '{{ $key }}', $event.target.checked)"
                                           aria-label="{{ $label }} may receive {{ $type->label }}">
                                    @if(!empty($auto[$type->id][$key]))<span class="dd-dot" title="Also sent automatically at: {{ implode(', ', $auto[$type->id][$key]) }}"></span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr x-show="shownCount() === 0" x-cloak><td colspan="{{ count($columns) + 1 }}" style="text-align:center; padding: 1.5rem; color: var(--text-muted);">No document types match. Clear the search to see them all.</td></tr>
                </tbody>
            </table>
        </div>

        {{-- Save bar --}}
        <div class="flex items-center justify-between gap-3" style="flex: 0 0 auto;">
            <div class="text-xs" style="color: var(--text-secondary);" data-dd-summary>
                <template x-if="changes.length === 0"><span>No changes yet. Tick or untick a box to change who receives a document.</span></template>
                <template x-if="changes.length > 0"><span><strong x-text="changes.length"></strong> change<span x-show="changes.length !== 1">s</span> not saved yet (highlighted).</span></template>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="corex-btn-outline" style="font-size:.8rem;" :disabled="changes.length === 0" @click="discard()" data-dd-discard>Discard changes</button>
                <button type="button" class="corex-btn-primary" style="font-size:.8rem;" :disabled="changes.length === 0" @click="showConfirm = true" data-dd-save>Review &amp; save</button>
            </div>
        </div>

        {{-- Change summary before saving --}}
        <div class="dd-modal-back" x-show="showConfirm" x-cloak @keydown.escape.window="showConfirm = false" data-dd-confirm>
            <div class="dd-modal" @click.outside="showConfirm = false">
                <div style="padding:.9rem 1.1rem; border-bottom:1px solid var(--border); font-weight:700; color: var(--text-primary);">Save these changes?</div>
                <div class="dd-body">
                    <p style="margin:0 0 .5rem;">This changes who is offered these documents on every deal, and what attorneys can download from their portal.</p>
                    <ul style="margin:0; padding-left:1.1rem; line-height:1.6;">
                        <template x-for="c in changes" :key="c.type + c.role">
                            <li><span :style="c.added ? 'color:#166534' : 'color:#b91c1c'" x-text="c.added ? 'Add' : 'Remove'"></span> <strong x-text="c.typeLabel"></strong> &rarr; <span x-text="c.roleLabel"></span></li>
                        </template>
                    </ul>
                </div>
                <div style="padding:.7rem 1.1rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:.5rem;">
                    <button type="button" class="corex-btn-outline" style="font-size:.8rem;" @click="showConfirm = false">Go back</button>
                    <button type="submit" class="corex-btn-primary" style="font-size:.8rem;" data-dd-confirm-save>Save changes</button>
                </div>
            </div>
        </div>
    </form>

    {{-- History --}}
    <div class="dd-modal-back" x-show="showHistory" x-cloak @keydown.escape.window="showHistory = false" data-dd-history-modal>
        <div class="dd-modal" @click.outside="showHistory = false">
            <div style="padding:.9rem 1.1rem; border-bottom:1px solid var(--border); font-weight:700; color: var(--text-primary);">Recent changes</div>
            <div class="dd-body">
                @forelse($history as $h)
                    <div style="margin-bottom:.8rem;">
                        <div style="color: var(--text-primary); font-weight:600;">{{ $h->created_at->format('j M Y H:i') }} &middot; {{ $h->user?->name ?? 'someone' }} &middot; {{ $h->summary }}</div>
                        <ul style="margin:.2rem 0 0; padding-left:1.1rem;">@foreach($h->changes as $line)<li>{{ $line }}</li>@endforeach</ul>
                    </div>
                @empty
                    <p>No changes have been saved yet.</p>
                @endforelse
            </div>
            <div style="padding:.7rem 1.1rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end;">
                <button type="button" class="corex-btn-outline" style="font-size:.8rem;" @click="showHistory = false">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.ddMatrix = function (cfg) {
        const clone = (o) => JSON.parse(JSON.stringify(o));
        const blank = () => { const s = {}; cfg.types.forEach(t => { s[t.id] = {}; cfg.roles.forEach(r => { s[t.id][r.key] = false; }); }); return s; };
        const from = (map) => { const s = blank(); Object.keys(map || {}).forEach(id => { const v = map[id]; (Array.isArray(v) ? v : Object.keys(v)).forEach(k => { if (s[id]) s[id][k] = true; }); }); return s; };
        return {
            types: cfg.types, roles: cfg.roles,
            initial: from(cfg.initial),
            state: from(cfg.initial),
            q: '', filter: 'all', showConfirm: false, showHistory: false,
            set(t, r, on) { this.state[t][r] = !!on; },
            isChanged(t, r) { return !!this.initial[t] && this.initial[t][r] !== this.state[t][r]; },
            anyTicked(t) { return this.roles.some(r => this.state[t][r.key]); },
            isShown(t) {
                const label = (this.types.find(x => x.id === t) || {}).label || '';
                if (this.q.trim() && !label.toLowerCase().includes(this.q.trim().toLowerCase())) return false;
                if (this.filter === 'ticked') return this.anyTicked(t);
                if (this.filter === 'unticked') return !this.anyTicked(t);
                return true;
            },
            shownCount() { return this.types.filter(t => this.isShown(t.id)).length; },
            get changes() {
                const out = [];
                this.types.forEach(t => this.roles.forEach(r => {
                    if (this.initial[t.id][r.key] !== this.state[t.id][r.key]) out.push({ type: t.id, role: r.key, typeLabel: t.label, roleLabel: r.label, added: this.state[t.id][r.key] });
                }));
                return out;
            },
            discard() { this.state = clone(this.initial); },
            restoreDefaults() { this.state = from(cfg.defaults); },
        };
    };
</script>
@endsection
