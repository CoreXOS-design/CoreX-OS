@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Agents Import Preview — Run #{{ $run->id }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Agency: {{ $run->agency?->name }} · Status: {{ $run->status }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.importer.cancel', $run) }}" onsubmit="return confirm('Cancel this run?');">
                    @csrf
                    <button class="corex-btn-outline text-xs">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    @php
        $rows = $run->rows->where('row_type', 'agent');
        $errorRows  = $rows->filter(fn($r) => !empty($r->errors_json));
        $errorCount = $errorRows->count();
        $validRows  = $rows->filter(fn($r) => empty($r->errors_json));
        $newCount   = $validRows->where('action', 'create')->count();
        $linkCount  = $validRows->where('action', 'update')->count();
        $skipCount  = $validRows->where('action', 'skip')->count();
        $chooseCount = $validRows->where('action', 'choose')->count();

        // Action presentation: label, one-line reason, colour. Matches the
        // import job's create/link/skip outcomes (spec §4.1 / §13 Q1).
        $actionMeta = [
            'create' => ['Create',  'New agent — will be created (inactive).',                              'var(--ds-green)'],
            'update' => ['Link',    'Matches an existing user in this agency — linked, not duplicated.',    'var(--brand-icon)'],
            'skip'   => ['Skip',    'Email belongs to a user in another agency — excluded by default.',     'var(--ds-amber)'],
            // AT-423 (importer.md §15) — email cannot identify one person; the admin picks.
            'choose' => ['Choose who this is', '',                                                          'var(--ds-amber)'],
            'link'   => ['Link',    'Linked to the person you chose.',                                      'var(--brand-icon)'],
        ];
        $peopleList = $people ?? collect();
    @endphp

    @if($errors->has('links'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach($errors->get('links') as $msg)<div>{{ $msg }}</div>@endforeach
        </div>
    @endif

    @if($chooseCount > 0)
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            <strong>{{ $chooseCount }} {{ $chooseCount === 1 ? 'agent needs' : 'agents need' }} you to choose who they are.</strong>
            Their email can't tell CoreX which person they are (no email, your shared Team Inbox address, or the same
            email on several agents). Link each one to an existing person — sub-users included — or Skip them.
            Add anyone missing under Admin → Users first.
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Total</div>
            <div class="text-2xl font-bold mt-1 tabular-nums" style="color: var(--text-primary);">{{ $rows->count() }}</div>
        </div>
        <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">New</div>
            <div class="text-2xl font-bold mt-1 tabular-nums" style="color: var(--ds-green);">{{ $newCount }}</div>
        </div>
        <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Link existing</div>
            <div class="text-2xl font-bold mt-1 tabular-nums" style="color: var(--brand-icon);">{{ $linkCount }}</div>
        </div>
        <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Skip</div>
            <div class="text-2xl font-bold mt-1 tabular-nums" style="color: var(--ds-amber);">{{ $skipCount }}</div>
        </div>
        <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">With errors</div>
            <div class="text-2xl font-bold mt-1 tabular-nums" style="color: var(--ds-crimson);">{{ $errorCount }}</div>
        </div>
    </div>

    {{-- AT-423 — Confirm stays disabled while any "Choose who this is" row (not excluded) has no answer. --}}
    <form method="POST" action="{{ route('admin.importer.confirm', $run) }}" class="rounded-lg p-5 space-y-3"
          x-data="{ left: 0, twice: false, recount() { const ex = new Set([...this.$el.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value)); const live = [...this.$el.querySelectorAll('select[data-choose-row]')].filter(s => !ex.has(s.dataset.chooseRow)); this.left = live.filter(s => !s.value).length; const people = live.map(s => s.value).filter(v => v && v !== 'skip'); this.twice = new Set(people).size !== people.length; } }"
          x-init="recount()" @change="recount()"
          style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <table class="w-full text-sm ds-table">
            <thead>
                <tr class="text-xs uppercase tracking-wider" style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="px-2 py-2 text-left font-semibold">Exclude</th>
                    <th class="px-2 py-2 text-left font-semibold">AgentId</th>
                    <th class="px-2 py-2 text-left font-semibold">Name</th>
                    <th class="px-2 py-2 text-left font-semibold">Email</th>
                    <th class="px-2 py-2 text-left font-semibold">P24 Status</th>
                    <th class="px-2 py-2 text-left font-semibold">Action</th>
                    <th class="px-2 py-2 text-left font-semibold">Errors</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                @php
                    $m = $r->mapped_json ?? [];
                    $act = $r->action ?? 'create';
                    [$actLabel, $actReason, $actCls] = $actionMeta[$act] ?? [$act, '', 'var(--text-muted)'];
                    $isSkip = $act === 'skip';
                @endphp
                <tr class="{{ !empty($r->errors_json) ? 'bg-red-500/5' : ($isSkip ? 'bg-amber-500/5' : '') }}" style="border-top: 1px solid var(--border);">
                    <td class="px-2 py-2">
                        <input type="checkbox" name="excluded[]" value="{{ $r->id }}" {{ $isSkip ? 'checked' : '' }}>
                    </td>
                    <td class="px-2 py-2 font-mono text-xs tabular-nums" style="color: var(--text-muted);">{{ $m['p24_agent_id'] ?? '—' }}</td>
                    <td class="px-2 py-2" style="color: var(--text-primary);">{{ $m['name'] ?? '' }}</td>
                    <td class="px-2 py-2" style="color: var(--text-secondary);">{{ $m['email'] ?? '' }}</td>
                    <td class="px-2 py-2 text-xs" style="color: var(--text-muted);">{{ $m['p24_status'] ?? '' }}</td>
                    <td class="px-2 py-2 text-xs">
                        @if (empty($r->errors_json) && $act === 'choose')
                            <span class="font-medium" style="color: {{ $actCls }};">{{ $actLabel }}</span>
                            <div class="text-[11px] leading-tight mt-0.5 mb-1" style="color: var(--text-muted);">{{ $m['link_reason'] ?? '' }}</div>
                            @php $chosen = (string) old('links.' . $r->id, $r->resolved_agent_id); @endphp
                            <select name="links[{{ $r->id }}]" data-choose-row="{{ $r->id }}"
                                    class="rounded-md px-2 py-1 text-xs w-full max-w-[16rem]"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="">— Choose who this is —</option>
                                @foreach ($peopleList as $p)
                                    <option value="{{ $p->id }}" @selected($chosen === (string) $p->id)>{{ $p->name }} — {{ $p->email }}{{ $p->is_sub_user ? ' (sub-user)' : '' }}</option>
                                @endforeach
                                <option value="skip" @selected($chosen === 'skip')>Skip — don't import this agent</option>
                            </select>
                        @elseif (empty($r->errors_json))
                            <span class="font-medium" style="color: {{ $actCls }};">{{ $actLabel }}</span>
                            <div class="text-[11px] leading-tight mt-0.5" style="color: var(--text-muted);">{{ $actReason }}</div>
                        @else
                            <span style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-xs" style="color: var(--ds-crimson);">
                        @foreach ((array)($r->errors_json ?? []) as $e) <div>{{ $e }}</div> @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="flex items-center justify-end gap-3">
            <span x-show="left > 0" x-cloak class="text-xs" style="color: var(--ds-amber);"
                  x-text="'Choose who ' + left + (left === 1 ? ' agent is' : ' agents are') + ' — or Skip them — first'"></span>
            <span x-show="left === 0 && twice" x-cloak class="text-xs" style="color: var(--ds-amber);">
                The same person is chosen for two agents — one person can only be one Property24 agent
            </span>
            <button type="submit" class="corex-btn-primary"
                    :disabled="left > 0 || twice" :style="(left > 0 || twice) ? 'opacity:.5; cursor:not-allowed;' : ''">
                Confirm &amp; Import Agents
            </button>
        </div>
    </form>
</div>
@endsection
