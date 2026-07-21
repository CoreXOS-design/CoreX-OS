{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    // Only what the AGENT holds is on this page at all — the controller filtered it. So every
    // row here is genuinely theirs to give, except the locked ones, which are shown precisely
    // so the lock can explain itself rather than looking like a missing feature.
    $sectionLabels = [
        'dashboard' => 'Dashboard', 'agency-tracker' => 'Agency Tracker', 'deals-v2' => 'Deal Register',
        'properties' => 'Properties', 'contacts' => 'Contacts', 'presentations' => 'Presentations',
        'docuperfect' => 'DocuPerfect', 'compliance' => 'Compliance', 'communication' => 'Communication',
        'command-center' => 'Command Centre', 'sidebar' => 'Menu visibility', 'assistants' => 'Assistants',
    ];
@endphp

<style>
/* ── Assistant Matrix: scope segmented control (mirrors Role Manager) ── */
#am-root .rm-scope-btn { transition: all 300ms; }
#am-root .rm-scope-btn[data-active="true"] { color: #fff; font-weight: 600; }
#am-root .rm-scope-btn[data-scope="none"][data-active="true"]   { background: var(--text-secondary); }
#am-root .rm-scope-btn[data-scope="own"][data-active="true"]    { background: var(--brand-button, #0ea5e9); }
#am-root .rm-scope-btn[data-scope="branch"][data-active="true"] { background: var(--ds-amber, #f59e0b); }
#am-root .rm-scope-btn[data-scope="all"][data-active="true"]    { background: var(--ds-green, #059669); }
</style>

<div id="am-root" class="w-full max-w-5xl space-y-5"
     x-data="assistantMatrix()"
     x-init="init()">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">
                    What {{ $assistant?->name }} can do
                </h1>
                <p class="text-sm text-white/60">
                    These are the things <strong class="text-white/90">you</strong> can do. Switch off anything
                    you don't want {{ $assistant?->name }} doing on your behalf.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-white/60" x-show="dirty" x-cloak>Unsaved changes…</span>
                <span class="text-xs text-white/60" x-show="saved && !dirty" x-cloak>Saved</span>
                <button type="button" class="corex-btn-primary" @click="save()">Save now</button>
            </div>
        </div>
    </div>

    @if($pendingDrift > 0)
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface, #fff); color:var(--text-primary, #111827); border:1px solid var(--ds-amber, #d97706);">
            <strong>{{ $pendingDrift }} new {{ \Illuminate\Support\Str::plural('permission', $pendingDrift) }} available.</strong>
            You've been given access to something you didn't have before. {{ $assistant?->name }} does
            <em>not</em> get it automatically — the new items are switched off below until you turn them on.
        </div>
    @endif

    <form method="POST" action="{{ route('agent.assistants.matrix.save', $assignment) }}" x-ref="form">
        @csrf

        {{-- AT-267 V2 — behaviour panel. Plain toggles, styled like the feature switchboard, that
             control HOW the assistant works for you. Ownership is not here: an assistant's work is
             ALWAYS filed as yours (the info row states it), never a toggle. --}}
        @php
            $behaviourToggles = [
                ['key' => 'can_manage_my_records', 'label' => $assistant?->name . ' can edit & delete my records, not just add them',
                 'desc' => 'When off, ' . $assistant?->name . ' can add to and view your book, but cannot change or remove records you already have.'],
                ['key' => 'show_attribution', 'label' => 'Show "added by ' . $assistant?->name . '" on things they do',
                 'desc' => 'A small tag on your calendar and records so you can see at a glance what ' . $assistant?->name . ' handled.'],
                ['key' => 'notify_on_action', 'label' => 'Notify me when ' . $assistant?->name . ' adds or changes something',
                 'desc' => 'An in-app notification each time ' . $assistant?->name . ' acts on your behalf. Off by default to keep things quiet.'],
            ];
        @endphp
        <div class="rounded-md mb-4 overflow-hidden"
             style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
            <div class="px-4 py-3 text-xs font-bold uppercase tracking-wide"
                 style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">
                How {{ $assistant?->name }} works for you
            </div>

            {{-- Always-on: an assistant's work is filed as the agent's. Stated, never toggleable. --}}
            <div class="px-4 py-3 flex items-start gap-3"
                 style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     style="width:18px; height:18px; margin-top:1px; color:var(--ds-green, #16a34a);">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
                <div>
                    <div class="text-sm font-semibold">Everything {{ $assistant?->name }} does is filed as yours</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">
                        Calendar entries, daily activity, contacts and deals {{ $assistant?->name }} adds appear on
                        your book as your own work. {{ $assistant?->name }} is recorded as the one who did it.
                    </div>
                </div>
            </div>

            @foreach($behaviourToggles as $t)
                <div class="px-4 py-3 flex items-center justify-between gap-4"
                     style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">{{ $t['label'] }}</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">{{ $t['desc'] }}</div>
                    </div>
                    <div class="shrink-0">
                        <input type="hidden" name="settings[{{ $t['key'] }}]" :value="settings['{{ $t['key'] }}'] ? '1' : '0'">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox"
                                   x-model="settings['{{ $t['key'] }}']"
                                   @change="dirty = true; scheduleSave()"
                                   class="w-5 h-5 rounded-md cursor-pointer"
                                   style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border, rgba(0,0,0,0.07));">
                            <span class="text-xs" style="color:var(--text-muted, #6b7280);"
                                  x-text="settings['{{ $t['key'] }}'] ? 'On' : 'Off'"></span>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>

        @foreach($sections as $section => $rows)
            <div class="rounded-md mb-4 overflow-hidden"
                 style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
                <div class="px-4 py-3 text-xs font-bold uppercase tracking-wide"
                     style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">
                    {{ $sectionLabels[$section] ?? \Illuminate\Support\Str::headline($section) }}
                </div>

                @foreach($rows as $row)
                    <div class="px-4 py-3 flex items-center justify-between gap-4"
                         style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold flex items-center gap-2">
                                {{ $row['label'] }}

                                @if($row['is_locked'])
                                    {{-- No Silent Locks: say WHY, on the page. An agent hunting for
                                         "why can't my assistant upload a listing?" must find the answer
                                         here rather than conclude the feature is broken. --}}
                                    <span class="px-1.5 py-0.5 rounded-md text-[10px] font-bold"
                                          style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);"
                                          title="CoreX switches property upload off for every assistant, at every agency. Nobody can turn this on — not you, not an admin. Only you can create a listing.">
                                        LOCKED BY COREX
                                    </span>
                                @elseif($row['is_new'])
                                    <span class="px-1.5 py-0.5 rounded-md text-[10px] font-bold"
                                          style="background:var(--surface-2, #f0f2f8); color:var(--ds-amber, #d97706);"
                                          title="New since this assistant was set up. Off until you turn it on.">NEW</span>
                                @endif
                            </div>

                            @if($row['is_locked'])
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">
                                    Assistants can never create or import a listing. Only you can.
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            @if($row['is_view'] && !$row['is_locked'])
                                {{-- Scope options are clamped to the agent's OWN breadth — an assistant
                                     may never see more than the agent whose work they are doing.
                                     Segmented control mirrors the Role Manager: the whole-agency ("all")
                                     pill turns green, matching the "all permission" styling there. --}}
                                @php
                                    $scopeOptions = [['none','No access'], ['own','My records']];
                                    if (in_array($row['scope_ceiling'], ['branch','all'], true)) $scopeOptions[] = ['branch','My branch'];
                                    if ($row['scope_ceiling'] === 'all') $scopeOptions[] = ['all','Whole agency'];
                                @endphp
                                <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border, rgba(0,0,0,0.07));">
                                    @foreach($scopeOptions as [$scopeVal, $scopeLabel])
                                    <label class="rm-scope-btn inline-flex items-center cursor-pointer px-3 py-1.5 text-xs whitespace-nowrap"
                                           style="border-right:1px solid var(--border, rgba(0,0,0,0.07));"
                                           :data-active="scopes['{{ $row['key'] }}'] === '{{ $scopeVal }}' ? 'true' : 'false'"
                                           data-scope="{{ $scopeVal }}"
                                           :style="scopes['{{ $row['key'] }}'] === '{{ $scopeVal }}'
                                               ? ''
                                               : 'background:var(--surface, #fff);color:var(--text-muted, #6b7280);'">
                                        <input type="radio"
                                               name="scopes[{{ $row['key'] }}]"
                                               value="{{ $scopeVal }}"
                                               x-model="scopes['{{ $row['key'] }}']"
                                               @change="dirty = true; scheduleSave()"
                                               class="sr-only">
                                        {{ $scopeLabel }}
                                    </label>
                                    @endforeach
                                </div>
                            @endif

                            <input type="hidden" name="permissions[{{ $row['key'] }}]"
                                   :value="matrix['{{ $row['key'] }}'] ? '1' : '0'">

                            <label class="inline-flex items-center gap-2 {{ $row['is_locked'] ? '' : 'cursor-pointer' }}">
                                <input type="checkbox"
                                       x-model="matrix['{{ $row['key'] }}']"
                                       @change="dirty = true; scheduleSave()"
                                       @disabled($row['is_locked'])
                                       @if($row['is_locked']) title="Locked by CoreX — assistants can never create or import a listing." @endif
                                       class="w-5 h-5 rounded-md {{ $row['is_locked'] ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}"
                                       style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border, rgba(0,0,0,0.07));">
                                <span class="text-xs" style="color:var(--text-muted, #6b7280);"
                                      x-text="matrix['{{ $row['key'] }}'] ? 'Enabled' : 'Disabled'"></span>
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('agent.assistants.index') }}" class="corex-btn-outline">Done</a>
            <button type="button" class="corex-btn-primary" @click="save()">Save</button>
        </div>
    </form>
</div>

@push('scripts')
<script>
function assistantMatrix() {
    return {
        matrix: @json(collect($sections)->flatten(1)->mapWithKeys(fn ($r) => [$r['key'] => $r['granted'] && !$r['is_locked']])),
        {{-- @json must stay on ONE line: Blade's directive parser mishandles a multi-line
             array literal and drops the closing ']', producing invalid PHP (unclosed '['). --}}
        scopes: @json(collect($sections)->flatten(1)->filter(fn ($r) => $r['is_view'] && !$r['is_locked'])->mapWithKeys(fn ($r) => [$r['key'] => ($r['granted'] ? $r['scope'] : 'none')])),
        settings: @json(['can_manage_my_records' => (bool) $assignment->can_manage_my_records, 'show_attribution' => (bool) $assignment->show_attribution, 'notify_on_action' => (bool) $assignment->notify_on_action]),
        dirty: false,
        saved: false,
        timer: null,

        init() {
            // Don't let an agent wander off mid-edit believing they saved.
            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        },

        scheduleSave() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(), 800);
        },

        async save() {
            clearTimeout(this.timer);

            const form = this.$refs.form;
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });

            if (res.ok) {
                this.dirty = false;
                this.saved = true;
            }
        },
    };
}
</script>
@endpush
@endsection
