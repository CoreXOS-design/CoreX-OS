@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Listing Targets</h2>
                <div class="text-sm text-white/60">Set per-agent listing targets for each month.</div>
            </div>

            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('admin.listing-targets') }}" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $period }}"
                           class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5">
                    <button class="corex-btn-primary text-sm">View</button>
                </form>

                <a href="{{ route('admin.dashboard', ['period' => $period]) }}" class="corex-btn-outline text-sm">&larr; Dashboard</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.listing-targets.store') }}">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">

        <div class="rounded-2xl border overflow-hidden" style="border-color:var(--border); background:var(--surface)">
            <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="ds-section-header">Targets for {{ $period }}</h3>
                <button class="corex-btn-primary text-sm">Save Targets</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b" style="border-color:var(--border); color:var(--text-secondary); background:var(--surface-2)">
                            <th class="text-left px-4 py-3">Agent</th>
                            <th class="text-left px-4 py-3">Target Listings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($agents as $agent)
                            @php
                                $existing = $targets->get($agent->id);
                                $value = old('targets.' . $agent->id, $existing?->target_listings ?? 0);
                            @endphp
                            <tr class="border-b hover:brightness-95" style="border-color:var(--border)">
                                <td class="px-4 py-3 font-medium" style="color:var(--text-primary)">{{ $agent->email }}</td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0"
                                           name="targets[{{ $agent->id }}]"
                                           value="{{ $value }}"
                                           class="w-40 rounded-lg border px-3 py-2 text-sm"
                                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-6 text-center" style="color:var(--text-muted)">No agents found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @error('targets') <div class="px-4 py-2 text-sm text-rose-600">{{ $message }}</div> @enderror
        </div>
    </form>

</div>
@endsection
