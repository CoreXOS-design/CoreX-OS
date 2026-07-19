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

<div class="w-full max-w-5xl space-y-5"
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
                                     may never see more than the agent whose work they are doing. --}}
                                <select name="scopes[{{ $row['key'] }}]"
                                        @change="dirty = true; scheduleSave()"
                                        class="rounded-md px-2 py-1 text-xs"
                                        style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
                                    <option value="none" @selected(!$row['granted'])>No access</option>
                                    <option value="own"    @selected($row['granted'] && $row['scope'] === 'own')>My records</option>
                                    @if(in_array($row['scope_ceiling'], ['branch','all'], true))
                                        <option value="branch" @selected($row['granted'] && $row['scope'] === 'branch')>My branch</option>
                                    @endif
                                    @if($row['scope_ceiling'] === 'all')
                                        <option value="all" @selected($row['granted'] && $row['scope'] === 'all')>Whole agency</option>
                                    @endif
                                </select>
                            @endif

                            <input type="hidden" name="permissions[{{ $row['key'] }}]"
                                   :value="matrix['{{ $row['key'] }}'] ? '1' : '0'">

                            <input type="checkbox"
                                   x-model="matrix['{{ $row['key'] }}']"
                                   @change="dirty = true; scheduleSave()"
                                   @disabled($row['is_locked'])
                                   @if($row['is_locked']) title="Locked by CoreX — assistants can never create or import a listing." @endif
                                   class="h-5 w-5 rounded-md"
                                   style="{{ $row['is_locked'] ? 'opacity:0.4; cursor:not-allowed;' : '' }}">
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
