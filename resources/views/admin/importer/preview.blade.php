@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Agents Import Preview — Run #{{ $run->id }}</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                Agency: {{ $run->agency?->name }} · Status: {{ $run->status }}
            </div>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.importer.cancel', $run) }}" onsubmit="return confirm('Cancel this run?');">
                @csrf
                <button class="rounded-md bg-surface-2 px-4 py-2 text-sm">Cancel</button>
            </form>
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

        // Action presentation: label, one-line reason, colour. Matches the
        // import job's create/link/skip outcomes (spec §4.1 / §13 Q1).
        $actionMeta = [
            'create' => ['Create',  'New agent — will be created (inactive).',                              'text-emerald-300'],
            'update' => ['Link',    'Matches an existing user in this agency — linked, not duplicated.',    'text-sky-300'],
            'skip'   => ['Skip',    'Email belongs to a user in another agency — excluded by default.',     'text-amber-300'],
        ];
    @endphp

    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="rounded-md bg-surface p-4">
            <div class="text-xs text-muted uppercase">Total</div>
            <div class="text-2xl font-bold">{{ $rows->count() }}</div>
        </div>
        <div class="rounded-md bg-surface p-4">
            <div class="text-xs text-muted uppercase">New</div>
            <div class="text-2xl font-bold text-emerald-400">{{ $newCount }}</div>
        </div>
        <div class="rounded-md bg-surface p-4">
            <div class="text-xs text-muted uppercase">Link existing</div>
            <div class="text-2xl font-bold text-sky-400">{{ $linkCount }}</div>
        </div>
        <div class="rounded-md bg-surface p-4">
            <div class="text-xs text-muted uppercase">Skip</div>
            <div class="text-2xl font-bold text-amber-400">{{ $skipCount }}</div>
        </div>
        <div class="rounded-md bg-surface p-4">
            <div class="text-xs text-muted uppercase">With errors</div>
            <div class="text-2xl font-bold text-red-400">{{ $errorCount }}</div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.importer.confirm', $run) }}" class="rounded-md bg-surface p-5 space-y-3">
        @csrf
        <table class="w-full text-sm">
            <thead class="text-xs uppercase text-muted border-b border-subtle">
                <tr>
                    <th class="px-2 py-2 text-left">Exclude</th>
                    <th class="px-2 py-2 text-left">AgentId</th>
                    <th class="px-2 py-2 text-left">Name</th>
                    <th class="px-2 py-2 text-left">Email</th>
                    <th class="px-2 py-2 text-left">P24 Status</th>
                    <th class="px-2 py-2 text-left">Action</th>
                    <th class="px-2 py-2 text-left">Errors</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                @php
                    $m = $r->mapped_json ?? [];
                    $act = $r->action ?? 'create';
                    [$actLabel, $actReason, $actCls] = $actionMeta[$act] ?? [$act, '', 'text-muted'];
                    $isSkip = $act === 'skip';
                @endphp
                <tr class="border-b border-subtle/40 {{ !empty($r->errors_json) ? 'bg-red-500/5' : ($isSkip ? 'bg-amber-500/5' : '') }}">
                    <td class="px-2 py-2">
                        <input type="checkbox" name="excluded[]" value="{{ $r->id }}" {{ $isSkip ? 'checked' : '' }}>
                    </td>
                    <td class="px-2 py-2 font-mono text-xs">{{ $m['p24_agent_id'] ?? '—' }}</td>
                    <td class="px-2 py-2">{{ $m['name'] ?? '' }}</td>
                    <td class="px-2 py-2">{{ $m['email'] ?? '' }}</td>
                    <td class="px-2 py-2 text-xs">{{ $m['p24_status'] ?? '' }}</td>
                    <td class="px-2 py-2 text-xs">
                        @if (empty($r->errors_json))
                            <span class="font-medium {{ $actCls }}">{{ $actLabel }}</span>
                            <div class="text-muted text-[11px] leading-tight mt-0.5">{{ $actReason }}</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-xs text-red-400">
                        @foreach ((array)($r->errors_json ?? []) as $e) <div>{{ $e }}</div> @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="flex justify-end">
            <button type="submit" class="rounded-md px-5 py-2 text-sm font-medium text-white" style="background:var(--brand-button, #0ea5e9);">
                Confirm &amp; Import Agents
            </button>
        </div>
    </form>
</div>
@endsection
