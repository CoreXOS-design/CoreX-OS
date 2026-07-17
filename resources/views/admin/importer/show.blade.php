@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Import Run #{{ $run->id }}</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                {{ $run->kind }} · {{ $run->agency?->name }} · Status: {{ $run->status }}
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.importer.index') }}"
               class="rounded-md px-4 py-2 text-sm font-medium bg-surface-2 border border-subtle text-muted hover:text-inherit">
                Back to importer
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($run->kind === 'agents')
        {{-- Invites deliberately do not live here. They are the LAST step of
             onboarding and are sent per-agency from Property Onboarding once
             the agency's properties are in — not per-run, mid-import. --}}
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            These agents were imported <strong style="color: var(--text-primary);">inactive</strong> — no email has been sent.
            Invite links go out from
            <a href="{{ route('admin.importer.review') }}" style="color: var(--brand-icon);">Property Onboarding</a>
            once this agency's properties are imported, as the last step.
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach (($run->counts_json ?? []) as $k => $v)
            <div class="rounded-md bg-surface p-4">
                <div class="text-xs text-muted uppercase">{{ str_replace('_', ' ', $k) }}</div>
                <div class="text-2xl font-bold">{{ is_array($v) ? count($v) : $v }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-md bg-surface p-5">
        <h3 class="text-base font-semibold mb-3">Rows</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase text-muted border-b border-subtle">
                <tr>
                    <th class="px-2 py-2 text-left">#</th>
                    <th class="px-2 py-2 text-left">Type</th>
                    <th class="px-2 py-2 text-left">External ID</th>
                    <th class="px-2 py-2 text-left">Name / Title</th>
                    <th class="px-2 py-2 text-left">Status</th>
                    <th class="px-2 py-2 text-left">Action</th>
                    <th class="px-2 py-2 text-left">Target</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($run->rows as $r)
                @php $m = $r->mapped_json ?? []; @endphp
                <tr class="border-b border-subtle/40">
                    <td class="px-2 py-2 font-mono text-xs">{{ $r->id }}</td>
                    <td class="px-2 py-2">{{ $r->row_type }}</td>
                    <td class="px-2 py-2 font-mono text-xs">{{ $r->external_id }}</td>
                    <td class="px-2 py-2">{{ $m['name'] ?? ($m['title'] ?? '') }}</td>
                    <td class="px-2 py-2"><span class="px-2 py-0.5 rounded-md text-xs bg-surface-2">{{ $r->status }}</span></td>
                    <td class="px-2 py-2 text-xs">{{ $r->action }}</td>
                    <td class="px-2 py-2 font-mono text-xs">{{ $r->target_id ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
