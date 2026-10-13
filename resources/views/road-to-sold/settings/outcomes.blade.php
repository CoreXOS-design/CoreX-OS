{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold outcomes')

@section('corex-content')
@php
    $formRows = $outcomes->getCollection()->mapWithKeys(fn ($o) => [$o->id => [
        'id'                     => $o->id,
        'label'                  => $o->label,
        'kind'                   => $o->kind,
        'next_action'            => $o->next_action,
        'delay_days'             => $o->delay_days,
        'list_tag'               => $o->list_tag,
        'counts_as_no_answer'    => (bool) $o->counts_as_no_answer,
        'records_do_not_contact' => (bool) $o->records_do_not_contact,
        'action'                 => route('road-to-sold.settings.outcomes.update', $o->id),
    ]])->all();
    $formConfig = [
        'rows'      => $formRows,
        'storeUrl'  => route('road-to-sold.settings.outcomes.store'),
        'openAdd'   => (bool) session('rts_open_add'),
        'openEdit'  => session('rts_open_edit'),
        'old'       => old() ?: null,
    ];
    $errs = $errors->getBag('outcome');
@endphp

<div class="w-full space-y-5 rts-page" x-data="rtsOutcomeForm(@js($formConfig))">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold outcomes</h1>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
                <button type="button" class="corex-btn-primary corex-btn-xs" @click="openNew()">Add outcome</button>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif

    @include('road-to-sold._controls')
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-q">Search name</label>
            <input id="rts-q" type="text" name="q" value="{{ $q }}" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-status">Show</label>
            <select id="rts-status" name="status" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" onchange="this.form.submit()">
                @foreach(['all' => 'All outcomes', 'active' => 'Switched on', 'off' => 'Switched off', 'archived' => 'Archived'] as $v => $l)
                    <option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sort">Sort by</label>
            <select id="rts-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" onchange="this.form.submit()">
                @foreach(['order' => 'Order shown to agents', 'name' => 'Name', 'kind' => 'Attempt or conversation'] as $v => $l)
                    <option value="{{ $v }}" @selected($sort === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Outcome</th>
                    <th class="px-3 py-2 text-left">Counts as</th>
                    <th class="px-3 py-2 text-left">What happens next</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($outcomes as $o)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $o->label }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $o->kind === 'conversation' ? 'Conversation' : 'Attempt' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        {{ $o->nextActionSummary() }}
                        @if($o->counts_as_no_answer)<span style="color: var(--text-muted);"> · counts toward no-answers in a row</span>@endif
                        @if($o->records_do_not_contact)<span style="color: var(--text-muted);"> · stops calls to the owner</span>@endif
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($o->trashed()) Archived @elseif($o->is_active) On @else Off @endif
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center justify-end gap-2 flex-wrap">
                            @if($o->trashed())
                                <form method="POST" action="{{ route('road-to-sold.settings.outcomes.restore', $o->id) }}">@csrf
                                    <button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button>
                                </form>
                            @else
                                @if($canReorder)
                                    <form method="POST" action="{{ route('road-to-sold.settings.outcomes.move', $o->id) }}">@csrf
                                        <input type="hidden" name="dir" value="up">
                                        <button type="submit" class="corex-btn-outline corex-btn-xs" title="Show earlier in the list" aria-label="Move up">Up</button>
                                    </form>
                                    <form method="POST" action="{{ route('road-to-sold.settings.outcomes.move', $o->id) }}">@csrf
                                        <input type="hidden" name="dir" value="down">
                                        <button type="submit" class="corex-btn-outline corex-btn-xs" title="Show later in the list" aria-label="Move down">Down</button>
                                    </form>
                                @endif
                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="openEdit({{ $o->id }})">Edit</button>
                                <form method="POST" action="{{ route('road-to-sold.settings.outcomes.toggle', $o->id) }}">@csrf
                                    <button type="submit" class="corex-btn-outline corex-btn-xs">{{ $o->is_active ? 'Switch off' : 'Switch on' }}</button>
                                </form>
                                <form method="POST" action="{{ route('road-to-sold.settings.outcomes.archive', $o->id) }}" data-confirm="Archive this outcome? Agents will no longer see it. Calls already logged with it keep their wording." data-confirm-danger data-confirm-label="Archive">@csrf @method('DELETE')
                                    <button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            @if($q !== '' || $status !== 'all') No outcomes match this search. @else No outcomes yet. @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $outcomes->links() }}

    {{-- Add / edit form --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="open = false">
        <div class="w-full max-w-lg rounded-md p-5 space-y-4 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;" @click.outside="open = false">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);" x-text="editId ? 'Edit outcome' : 'Add outcome'"></h2>

            @if($errs->any())
                <div class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($errs->all() as $m)<div>{{ $m }}</div>@endforeach
                </div>
            @endif

            <form method="POST" :action="formAction" class="space-y-3">
                @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                @csrf
                <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-f-label">Name</label>
                    <input id="rts-f-label" type="text" name="label" maxlength="120" x-model="f.label" class="w-full rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-f-kind">Counts as</label>
                    <select id="rts-f-kind" name="kind" x-model="f.kind" class="w-full rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="attempt">An attempt (no real conversation)</option>
                        <option value="conversation">A conversation</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-f-next">What happens next</label>
                    <select id="rts-f-next" name="next_action" x-model="f.next_action" class="w-full rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach($actions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>

                <div x-show="f.next_action === 'return_after_days'">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-f-days">Days until it comes back</label>
                    <input id="rts-f-days" type="number" min="1" max="3650" name="delay_days" x-model="f.delay_days" class="w-full rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                <div x-show="f.next_action === 'move_to_list'">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-f-tag">Name of the list</label>
                    <input id="rts-f-tag" type="text" name="list_tag" maxlength="60" x-model="f.list_tag" class="w-full rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary);" x-show="f.kind === 'attempt'">
                    <input type="checkbox" name="counts_as_no_answer" value="1" x-model="f.counts_as_no_answer">
                    Counts toward "no answer in a row"
                </label>

                <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary);" x-show="f.next_action === 'never_again'">
                    <input type="checkbox" name="records_do_not_contact" value="1" x-model="f.records_do_not_contact">
                    Also stop calls to the owner (records a call opt-out on their contact)
                </label>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="open = false">Cancel</button>
                    <button type="submit" class="corex-btn-primary corex-btn-xs">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function rtsOutcomeForm(cfg) {
    const blank = () => ({ label: '', kind: 'attempt', next_action: 'return_after_days', delay_days: 7, list_tag: '', counts_as_no_answer: false, records_do_not_contact: false });
    return {
        open: false,
        editId: null,
        formAction: cfg.storeUrl,
        f: blank(),
        init() {
            if (cfg.old) {
                this.f = Object.assign(blank(), {
                    label: cfg.old.label || '', kind: cfg.old.kind || 'attempt',
                    next_action: cfg.old.next_action || 'return_after_days',
                    delay_days: cfg.old.delay_days || '', list_tag: cfg.old.list_tag || '',
                    counts_as_no_answer: !!cfg.old.counts_as_no_answer,
                    records_do_not_contact: !!cfg.old.records_do_not_contact,
                });
            }
            if (cfg.openAdd) { this.editId = null; this.formAction = cfg.storeUrl; this.open = true; }
            if (cfg.openEdit && cfg.rows[cfg.openEdit]) { this.editId = cfg.openEdit; this.formAction = cfg.rows[cfg.openEdit].action; this.open = true; }
        },
        openNew() { this.editId = null; this.formAction = cfg.storeUrl; this.f = blank(); this.open = true; },
        openEdit(id) {
            const r = cfg.rows[id];
            if (!r) { return; }
            this.editId = id;
            this.formAction = r.action;
            this.f = { label: r.label, kind: r.kind, next_action: r.next_action, delay_days: r.delay_days || '', list_tag: r.list_tag || '', counts_as_no_answer: r.counts_as_no_answer, records_do_not_contact: r.records_do_not_contact };
            this.open = true;
        },
    };
}
</script>
@endsection
