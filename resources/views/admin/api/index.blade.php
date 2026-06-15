@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">API Catalog</h1>
                <p class="text-sm text-white/60">
                    Live registry of every API endpoint in CoreX OS — generated from Laravel's route table.
                    {{ number_format($total) }} endpoints registered.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-white/60">Test global call:</span>
                <code class="px-2 py-1 rounded-md text-xs font-mono"
                      style="background: rgba(255,255,255,0.10); color: #fff; border: 1px solid rgba(255,255,255,0.18);">window.CoreX.api.loggedUser()</code>
            </div>
        </div>
    </div>

    @forelse($groups as $groupName => $rows)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">
                    {{ $groupName }}
                    <span class="ml-2 text-xs font-normal" style="color: var(--text-secondary);">({{ number_format($rows->count()) }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Method</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">URI</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Middleware</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-md text-xs font-semibold font-mono whitespace-nowrap"
                                          style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                                        {{ $r['methods'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-primary);">{{ $r['uri'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-secondary);">{{ $r['name'] ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-secondary);">{{ \Illuminate\Support\Str::after($r['action'], 'App\\Http\\Controllers\\') }}</td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                    {{ implode(', ', $r['middleware']) ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No API endpoints registered</h3>
            <p class="text-sm" style="color: var(--text-muted);">Register a route under <code>/api/v1/*</code> with a <code>->name()</code> and it will appear here automatically.</p>
        </div>
    @endforelse

    <div class="rounded-md p-4 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
        <strong style="color: var(--text-primary);">Adding a new API?</strong>
        Per <code>CLAUDE.md</code>, every new endpoint must (1) be registered under the matching <code>/api/v1/*</code> prefix, (2) have a route <code>->name()</code>, and (3) appear automatically in this catalog. No manual list to maintain — Laravel's route table is the source of truth.
    </div>
</div>
@endsection
