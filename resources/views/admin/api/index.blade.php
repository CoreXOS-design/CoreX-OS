@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold" style="color:var(--text-primary)">API Catalog</h1>
            <p class="text-sm mt-1" style="color:var(--text-secondary)">
                Live registry of every API endpoint in CoreX OS — generated from Laravel's route table.
                Total: <strong>{{ $total }}</strong> endpoints.
            </p>
        </div>
        <div class="text-xs" style="color:var(--text-secondary)">
            Test global call: <code class="px-2 py-1 rounded" style="background:var(--surface-2)">window.CoreX.api.loggedUser()</code>
        </div>
    </div>

    @foreach($groups as $groupName => $rows)
        <div class="hfc-card mb-4">
            <div class="px-4 py-3 border-b" style="border-color:var(--border)">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand-icon)">
                    {{ $groupName }}
                    <span class="ml-2 text-xs font-normal" style="color:var(--text-secondary)">({{ $rows->count() }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider" style="color:var(--text-secondary); background:var(--surface-2)">
                            <th class="px-4 py-2">Method</th>
                            <th class="px-4 py-2">URI</th>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Action</th>
                            <th class="px-4 py-2">Middleware</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="px-4 py-2 font-mono text-xs">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                          style="background:var(--surface-2); color:var(--brand-icon)">
                                        {{ $r['methods'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs" style="color:var(--text-primary)">{{ $r['uri'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs" style="color:var(--text-secondary)">{{ $r['name'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs" style="color:var(--text-secondary)">{{ \Illuminate\Support\Str::after($r['action'], 'App\\Http\\Controllers\\') }}</td>
                                <td class="px-4 py-2 text-xs" style="color:var(--text-secondary)">
                                    {{ implode(', ', $r['middleware']) ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <div class="mt-6 hfc-card p-4 text-xs" style="color:var(--text-secondary)">
        <strong style="color:var(--text-primary)">Adding a new API?</strong>
        Per <code>CLAUDE.md</code>, every new endpoint must (1) be registered under the matching <code>/api/v1/*</code> prefix, (2) have a route <code>->name()</code>, and (3) appear automatically in this catalog. No manual list to maintain — Laravel's route table is the source of truth.
    </div>
</div>
@endsection
