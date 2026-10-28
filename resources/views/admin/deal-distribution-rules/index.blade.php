@extends('layouts.corex')
@section('title', 'Deal Distribution Rules')

{{--
    .ai/specs/signed-document-distribution.md "The rules screen". "When a deal reaches a stage, send these documents to ...".
    Page standard: the left menu and this header stay put; the list is the content block and fits 1366x768 without scrolling the page
    (the table scrolls INSIDE its own frame, header row pinned; the pager sits under it).
--}}
@section('corex-content')
@php
    $sortUrl = function (string $key) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null]);
    };
    $arrow = fn (string $key) => $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $filtered = $f['q'] !== '' || $f['stage'] !== '' || $f['party'] !== '' || $f['status'] !== 'current';
    $old = old('document_type_id') ? [
        'id' => (int) old('_rule_id', 0), 'step' => (int) old('pipeline_step_id'), 'doc' => (int) old('document_type_id'), 'party' => old('party_role'),
        'mode' => old('delivery_mode', 'secure_link'), 'auto' => (bool) old('auto_on_stage_tick'), 'active' => (bool) old('is_active', true),
    ] : null;
    $payload = [
        'updateUrl' => route('admin.settings.deal-distribution-rules.update', ['rule' => '__ID__']),
        'storeUrl'  => route('admin.settings.deal-distribution-rules.store'),
        'baseUrl'   => route('admin.settings.deal-distribution-rules.index'),
        'reopen'    => $old,
    ];
@endphp
<style>
    .dr-page { height: 100%; display: flex; flex-direction: column; gap: .6rem; min-height: 0; }
    .dr-frame { flex: 1 1 auto; min-height: 0; overflow: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .dr-grid { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 860px; font-size: .8rem; }
    .dr-grid th, .dr-grid td { padding: .38rem .6rem; border-bottom: 1px solid var(--border); background: var(--surface); text-align: left; vertical-align: middle; overflow-wrap: anywhere; }
    .dr-grid thead th { position: sticky; top: 0; z-index: 3; background: var(--surface-2, #f3f4f6); font-weight: 600; color: var(--text-secondary); font-size: .72rem; white-space: nowrap; }
    .dr-grid thead th a { color: inherit; text-decoration: none; }
    .dr-grid tbody tr:hover td { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface)); }
    .dr-grid td.act { white-space: nowrap; text-align: right; }
    .dr-grid tr.dr-archived td { color: var(--text-muted); }
    .dr-sub { display: block; font-size: .68rem; color: var(--text-muted); }
    .dr-pill { display: inline-block; padding: .05rem .5rem; border-radius: 99px; font-size: .68rem; font-weight: 600; white-space: nowrap; }
    .dr-on { background: color-mix(in srgb, #059669 14%, transparent); color: #047857; }
    .dr-off { background: color-mix(in srgb, #f59e0b 18%, transparent); color: #92400e; }
    .dr-arch { background: color-mix(in srgb, #6b7280 16%, transparent); color: #4b5563; }
    .dr-auto { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent); color: var(--text-primary); }
    .dr-warn { color: #92400e; font-size: .68rem; display: block; }
    .dr-tabs a { font-size: .75rem; padding: .25rem .7rem; border: 1px solid var(--border); border-radius: 99px; color: var(--text-secondary); text-decoration: none; background: var(--surface); }
    .dr-tabs a.is-here { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; font-weight: 600; }
    .dr-field label { display: block; font-size: .72rem; font-weight: 600; color: var(--text-secondary); margin-bottom: .2rem; }
    .dr-field select { width: 100%; }
    .dr-modal-back { position: fixed; inset: 0; background: rgba(2,10,20,.45); z-index: 80; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .dr-modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-width: 36rem; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(2,10,20,.35); }
    .dr-modal .dr-body { overflow: auto; padding: .9rem 1.1rem; font-size: .8rem; color: var(--text-secondary); }
</style>

<div class="dr-page" x-data="ddRules({{ \Illuminate\Support\Js::from($payload) }})" data-dr-page>

    {{-- Header (stays in view) --}}
    <div class="rounded-md corex-page-banner" style="padding:.7rem 1.1rem; flex: 0 0 auto;">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal Distribution Rules</h1>
                <p class="text-xs" style="color: var(--text-muted); max-width: 62rem;">
                    When a deal reaches a stage, send these documents to ... Each rule below names a stage, a kind of document and who gets it.
                    A rule set to <strong>Automatically</strong> sends the moment that stage is completed; one set to <strong>By hand</strong> is offered to the agent under
                    &ldquo;Distribute documents&rdquo; while the deal is at that stage. A document is only ever sent if it is filed on that deal.
                </p>
            </div>
            <div class="text-xs flex flex-col items-end gap-1" style="color: var(--text-muted); white-space: nowrap;">
                <div class="flex items-center gap-3">
                    @if($last)
                        <span data-dr-last>Last changed {{ $last->created_at->format('j M Y H:i') }} by {{ $last->user?->name ?? 'someone' }}</span>
                        <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.2rem .6rem;" @click="showHistory = true" data-dr-history>History</button>
                    @else
                        <span>No changes saved yet.</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.adhoc-toggle') }}" class="flex items-center gap-2" data-dr-adhoc>
                    @csrf
                    <input type="hidden" name="adhoc_document_distribution_enabled" value="0">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer" title="When on, an agent can also email a document to any address from a deal's Email Parties. Off by default.">
                        <input type="checkbox" name="adhoc_document_distribution_enabled" value="1" {{ $adhocEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                        Let agents email a document to any address
                    </label>
                </form>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);" data-dr-flash>{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent); color: var(--text-primary);" data-dr-error>{{ $errors->first() }}</div>
    @endif

    {{-- Toolbar: the two screens, search, filters, add --}}
    <div class="flex flex-wrap items-center gap-2" style="flex: 0 0 auto;">
        <div class="dr-tabs flex items-center gap-1.5 mr-2" data-dr-tabs>
            <a href="{{ route('admin.settings.document-distribution') }}">Who may receive</a>
            <a href="{{ route('admin.settings.deal-distribution-rules.index') }}" class="is-here" aria-current="page">Send at a stage</a>
        </div>
        <form method="GET" action="{{ route('admin.settings.deal-distribution-rules.index') }}" class="flex flex-wrap items-center gap-2 flex-1" data-dr-filters>
            <input type="hidden" name="sort" value="{{ $f['sort'] }}"><input type="hidden" name="dir" value="{{ $f['dir'] }}">
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="Find a document, person or stage..." class="corex-input" style="font-size:.8rem; width: 15rem;" aria-label="Search rules" data-dr-search>
            <select name="stage" class="corex-input" style="font-size:.8rem; max-width: 14rem;" aria-label="Stage" onchange="this.form.submit()" data-dr-stage>
                <option value="">Every stage</option>
                @foreach($steps as $s)<option value="{{ $s->id }}" @selected($f['stage'] === (string) $s->id)>{{ $s->name }} ({{ $s->template_name }})</option>@endforeach
            </select>
            <select name="party" class="corex-input" style="font-size:.8rem;" aria-label="Goes to" onchange="this.form.submit()" data-dr-party>
                <option value="">Anyone</option>
                @foreach($labels as $key => $label)<option value="{{ $key }}" @selected($f['party'] === $key)>{{ $label }}</option>@endforeach
            </select>
            <select name="status" class="corex-input" style="font-size:.8rem;" aria-label="Show" onchange="this.form.submit()" data-dr-status>
                <option value="current" @selected($f['status'] === 'current')>Current rules</option>
                <option value="on" @selected($f['status'] === 'on')>Switched on</option>
                <option value="off" @selected($f['status'] === 'off')>Switched off</option>
                <option value="archived" @selected($f['status'] === 'archived')>Archived</option>
            </select>
            <button type="submit" class="corex-btn-outline" style="font-size:.75rem;padding:.3rem .7rem;">Search</button>
            @if($filtered)<a href="{{ route('admin.settings.deal-distribution-rules.index') }}" class="text-xs" style="color: var(--brand-icon);">Clear</a>@endif
        </form>
        <button type="button" class="corex-btn-primary" style="font-size:.8rem;" @click="openAdd()" data-dr-add>Add a rule</button>
    </div>

    {{-- The list --}}
    <div class="dr-frame" data-dr-frame>
        @if($rules->total() === 0)
            <div style="padding: 2.5rem 1.5rem; text-align: center;" data-dr-empty>
                @if($filtered)
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">No rules match</h3>
                    <p class="text-sm mt-1" style="color: var(--text-muted);">Nothing fits that search or filter. <a href="{{ route('admin.settings.deal-distribution-rules.index') }}" style="color: var(--brand-icon);">Show every rule</a>.</p>
                @else
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">No documents are sent at a stage yet</h3>
                    <p class="text-sm mt-1" style="color: var(--text-muted);">A rule says: when a deal reaches a stage, send this kind of document to this person. Press <strong>Add a rule</strong> to make the first one.</p>
                @endif
            </div>
        @else
            <table class="dr-grid" data-dr-grid>
                <thead>
                    <tr>
                        <th scope="col"><a href="{{ $sortUrl('document') }}">Document{{ $arrow('document') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('party') }}">Goes to{{ $arrow('party') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('stage') }}">When the deal reaches{{ $arrow('stage') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('delivery') }}">Delivered as{{ $arrow('delivery') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('sent') }}">Sent{{ $arrow('sent') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('status') }}">Status{{ $arrow('status') }}</a></th>
                        <th scope="col" style="text-align:right;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rules as $rule)
                        @php
                            $archived = $rule->deleted_at !== null;
                            $inMatrix = ! empty($matrix[$rule->document_type_id][$rule->party_role]);
                            $row = ['id' => $rule->id, 'step' => (int) $rule->pipeline_step_id, 'doc' => (int) $rule->document_type_id, 'party' => $rule->party_role,
                                    'mode' => $rule->delivery_mode, 'auto' => (bool) $rule->auto_on_stage_tick, 'active' => (bool) $rule->is_active];
                        @endphp
                        <tr class="{{ $archived ? 'dr-archived' : '' }}" data-rule-id="{{ $rule->id }}">
                            <td style="color: var(--text-primary); font-weight: 500;">
                                {{ $rule->doc_label }}
                                @if(! $archived && $rule->is_active && ! $inMatrix)
                                    <a class="dr-warn" href="{{ route('admin.settings.document-distribution') }}" title="This rule still works. But this person is not ticked for this document on the Who may receive screen, so the Email Parties button and the attorney portal do not offer it to them.">Not on the &ldquo;Who may receive&rdquo; list</a>
                                @endif
                            </td>
                            <td>{{ $labels[$rule->party_role] ?? \Illuminate\Support\Str::headline($rule->party_role) }}</td>
                            <td>{{ $rule->stage_name ?? 'A removed stage' }}@if($rule->template_name)<span class="dr-sub">{{ $rule->template_name }}</span>@endif</td>
                            <td>{{ $modes[$rule->delivery_mode] ?? $rule->delivery_mode }}</td>
                            <td><span class="dr-pill {{ $rule->auto_on_stage_tick ? 'dr-auto' : 'dr-arch' }}" title="{{ $rule->auto_on_stage_tick ? 'Sent the moment the stage is completed' : 'Offered to the agent under Distribute documents' }}">{{ $rule->auto_on_stage_tick ? 'Automatically' : 'By hand' }}</span></td>
                            <td><span class="dr-pill {{ $archived ? 'dr-arch' : ($rule->is_active ? 'dr-on' : 'dr-off') }}">{{ $archived ? 'Archived' : ($rule->is_active ? 'On' : 'Switched off') }}</span></td>
                            <td class="act">
                                @if($archived)
                                    <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.restore', ['rule' => $rule->id]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline" style="font-size:.72rem;padding:.15rem .55rem;" data-dr-restore>Restore</button>
                                    </form>
                                @else
                                    <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.15rem .55rem;" @click='openEdit(@json($row))' data-dr-edit>Edit</button>
                                    <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.15rem .55rem;" @click="askArchive({{ $rule->id }}, @js($rule->doc_label . ' to ' . ($labels[$rule->party_role] ?? $rule->party_role)))" data-dr-archive>Archive</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Pager --}}
    <div class="flex items-center justify-between gap-3 text-xs" style="flex: 0 0 auto; color: var(--text-muted);" data-dr-pager>
        <span>
            @if($rules->total() > 0) Showing {{ $rules->firstItem() }}&ndash;{{ $rules->lastItem() }} of {{ $rules->total() }} {{ $rules->total() === 1 ? 'rule' : 'rules' }} @else 0 rules @endif
            @if($f['status'] === 'current' && $totalRules !== $rules->total()) &middot; {{ $totalRules }} in total @endif
        </span>
        @if($rules->hasPages())
            <span class="flex items-center gap-2">
                @if($rules->onFirstPage()) <span style="opacity:.45;">Previous</span> @else <a href="{{ $rules->previousPageUrl() }}" style="color: var(--brand-icon);">Previous</a> @endif
                <span>Page {{ $rules->currentPage() }} of {{ $rules->lastPage() }}</span>
                @if($rules->hasMorePages()) <a href="{{ $rules->nextPageUrl() }}" style="color: var(--brand-icon);">Next</a> @else <span style="opacity:.45;">Next</span> @endif
            </span>
        @endif
    </div>

    {{-- Add / edit --}}
    <div class="dr-modal-back" x-show="showForm" x-cloak @keydown.escape.window="showForm = false" data-dr-form-modal>
        <form method="POST" :action="editing ? cfg.updateUrl.replace('__ID__', form.id) : cfg.storeUrl" class="dr-modal" @click.outside="showForm = false">
            @csrf
            <template x-if="editing"><input type="hidden" name="_method" value="PUT"></template>
            <input type="hidden" name="_rule_id" :value="editing ? form.id : ''">
            <div style="padding:.9rem 1.1rem; border-bottom:1px solid var(--border); font-weight:700; color: var(--text-primary);" x-text="editing ? 'Edit this rule' : 'Add a rule'"></div>
            <div class="dr-body" style="display:grid; gap:.7rem;">
                <p style="margin:0;">When a deal reaches this stage, send this document to this person.</p>
                <div class="dr-field">
                    <label for="dr-step">When the deal reaches this stage</label>
                    <select id="dr-step" name="pipeline_step_id" required class="corex-input" x-model.number="form.step">
                        <option value="">Choose a stage...</option>
                        @foreach($steps as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->template_name }})</option>@endforeach
                    </select>
                </div>
                <div class="dr-field">
                    <label for="dr-doc">Send this document</label>
                    <select id="dr-doc" name="document_type_id" required class="corex-input" x-model.number="form.doc">
                        <option value="">Choose a document...</option>
                        @foreach($documentTypes as $dt)<option value="{{ $dt->id }}">{{ $dt->label }}</option>@endforeach
                    </select>
                </div>
                <div class="dr-field">
                    <label for="dr-party">To</label>
                    <select id="dr-party" name="party_role" required class="corex-input" x-model="form.party">
                        <option value="">Choose who receives it...</option>
                        @foreach($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="dr-field">
                    <label for="dr-mode">Delivered as</label>
                    <select id="dr-mode" name="delivery_mode" class="corex-input" x-model="form.mode">
                        @foreach($modes as $key => $label)<option value="{{ $key }}">{{ $label }}{{ $key === 'secure_link' ? ' (recommended)' : ' (low-sensitivity documents only)' }}</option>@endforeach
                    </select>
                </div>
                <div class="dr-field">
                    <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:500;">
                        <input type="checkbox" name="auto_on_stage_tick" value="1" x-model="form.auto" style="margin-top:.15rem;">
                        <span>Send it automatically the moment this stage is completed<span class="dr-sub" style="display:block;">Leave this unticked and the agent is offered the document under &ldquo;Distribute documents&rdquo; instead.</span></span>
                    </label>
                </div>
                <div class="dr-field" x-show="editing">
                    <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:500;">
                        <input type="hidden" name="is_active" value="0" :disabled="!editing">
                        <input type="checkbox" name="is_active" value="1" x-model="form.active" :disabled="!editing" style="margin-top:.15rem;">
                        <span>This rule is switched on<span class="dr-sub" style="display:block;">Untick to stop it without archiving it.</span></span>
                    </label>
                </div>
            </div>
            <div style="padding:.7rem 1.1rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:.5rem;">
                <button type="button" class="corex-btn-outline" style="font-size:.8rem;" @click="showForm = false">Cancel</button>
                <button type="submit" class="corex-btn-primary" style="font-size:.8rem;" data-dr-form-save x-text="editing ? 'Save changes' : 'Add rule'"></button>
            </div>
        </form>
    </div>

    {{-- Archive confirm --}}
    <div class="dr-modal-back" x-show="archive.id" x-cloak @keydown.escape.window="archive.id = 0" data-dr-archive-modal>
        <form method="POST" :action="cfg.baseUrl + '/' + archive.id" class="dr-modal" style="max-width:28rem;" @click.outside="archive.id = 0">
            @csrf @method('DELETE')
            <div style="padding:.9rem 1.1rem; border-bottom:1px solid var(--border); font-weight:700; color: var(--text-primary);">Archive this rule?</div>
            <div class="dr-body"><p style="margin:0;"><strong x-text="archive.label"></strong> will stop being sent at that stage. Nothing is deleted: you can bring it back from the <em>Archived</em> list.</p></div>
            <div style="padding:.7rem 1.1rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:.5rem;">
                <button type="button" class="corex-btn-outline" style="font-size:.8rem;" @click="archive.id = 0">Keep it</button>
                <button type="submit" class="corex-btn-primary" style="font-size:.8rem;" data-dr-archive-confirm>Archive</button>
            </div>
        </form>
    </div>

    {{-- History --}}
    <div class="dr-modal-back" x-show="showHistory" x-cloak @keydown.escape.window="showHistory = false" data-dr-history-modal>
        <div class="dr-modal" @click.outside="showHistory = false">
            <div style="padding:.9rem 1.1rem; border-bottom:1px solid var(--border); font-weight:700; color: var(--text-primary);">Recent changes</div>
            <div class="dr-body">
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
    window.ddRules = function (cfg) {
        const blank = () => ({ id: 0, step: '', doc: '', party: '', mode: 'secure_link', auto: false, active: true });
        return {
            cfg, showForm: false, showHistory: false, editing: false, form: blank(), archive: { id: 0, label: '' },
            init() { if (cfg.reopen) { this.editing = !!cfg.reopen.id; this.form = Object.assign(blank(), cfg.reopen); this.showForm = true; } },
            openAdd() { this.editing = false; this.form = blank(); this.showForm = true; },
            openEdit(row) { this.editing = true; this.form = Object.assign(blank(), row); this.showForm = true; },
            askArchive(id, label) { this.archive = { id, label }; },
        };
    };
</script>
@endsection
