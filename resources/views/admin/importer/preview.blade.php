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

        // Action presentation: label, one-line reason, colour. Matches the
        // import job's create/link/skip outcomes (spec §4.1 / §13 Q1).
        $actionMeta = [
            'create' => ['Create',  'New agent — will be created (inactive).',                              'var(--ds-green)'],
            'update' => ['Link',    'Matches an existing user in this agency — linked, not duplicated.',    'var(--brand-icon)'],
            'skip'   => ['Skip',    'Email belongs to a user in another agency — excluded by default.',     'var(--ds-amber)'],
        ];
    @endphp

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

    <form method="POST" action="{{ route('admin.importer.confirm', $run) }}" class="rounded-lg p-5 space-y-3"
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
                        @if (empty($r->errors_json))
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
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary">
                Confirm &amp; Import Agents
            </button>
        </div>
    </form>
</div>
@endsection
