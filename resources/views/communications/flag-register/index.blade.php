@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Communication Flag Register</h1>
                <p class="text-sm text-white/60">Triage audit — who discarded or kept which contact, when, and whether anyone disagreed. No message content is stored or shown.</p>
            </div>
            @if($openAlerts > 0)
            <span class="ds-badge ds-badge-warning">{{ $openAlerts }} open contradiction alert(s)</span>
            @endif
        </div>
    </div>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search identifier / name</label>
                <input type="text" name="search" value="{{ $search }}" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Flag</label>
                <select name="flag" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All</option>
                    <option value="not_real_estate" {{ $flag === 'not_real_estate' ? 'selected' : '' }}>Not real estate</option>
                    <option value="real_estate" {{ $flag === 'real_estate' ? 'selected' : '' }}>Real estate</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                <input type="checkbox" name="contradicted" value="1" onchange="this.form.submit()" {{ request('contradicted') === '1' ? 'checked' : '' }}> Contradicted only
            </label>
            <button type="submit" class="corex-btn-primary">Apply</button>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">#</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Identifier</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Flag</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">AI verdict</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Flagged</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contradiction</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($flags as $row)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3" style="color: var(--text-muted);">{{ $row->id }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $row->identifier }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $row->identifier_name ?: '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $row->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $row->flag === 'real_estate' ? 'ds-badge-success' : 'ds-badge-default' }}">{{ str_replace('_', ' ', $row->flag) }}</span>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                            @if($row->ai_is_real_estate === null) — @else {{ $row->ai_is_real_estate ? 'real estate' : 'personal' }} ({{ rtrim(rtrim((string) $row->ai_confidence, '0'), '.') }}) @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $row->flagged_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            @if($row->contradicted_at)
                            <span class="ds-badge ds-badge-warning">by {{ $row->contradictedBy?->name ?? 'another agent' }} · {{ $row->contradicted_at->format('d M') }}</span>
                            @else
                            <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No flags recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($flags->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $flags->links() }}</div>
        @endif
    </div>
</div>
@endsection
