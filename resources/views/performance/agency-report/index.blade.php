@extends('layouts.corex-app')

@section('title', 'Agency Performance & ROI')

@section('content')
<div class="p-6 space-y-6" x-data>
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Agency Performance &amp; ROI</h1>
            <p class="text-xs" style="color:var(--text-muted);">
                {{ $report['period']['label'] }} · {{ ucfirst($report['scope']['level']) }} view
            </p>
        </div>

        {{-- Period selector (AT-366-A: preset + custom range) --}}
        <form method="GET" class="flex items-end gap-2 flex-wrap">
            <label class="text-[11px]" style="color:var(--text-muted);">
                Period
                <select name="period" onchange="this.form.submit()"
                        class="block mt-1 text-xs rounded px-2 py-1"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    @foreach($presets as $p)
                        <option value="{{ $p }}" @selected($preset === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                    @endforeach
                </select>
            </label>
            @if($preset === 'custom')
                <label class="text-[11px]" style="color:var(--text-muted);">Start
                    <input type="date" name="start" value="{{ request('start') }}"
                           class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <label class="text-[11px]" style="color:var(--text-muted);">End
                    <input type="date" name="end" value="{{ request('end') }}"
                           class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <button type="submit" class="text-xs px-3 py-1 rounded" style="background:var(--brand); color:#fff;">Apply</button>
            @endif
        </form>
    </div>

    @if(session('period_error'))
        <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">{{ session('period_error') }}</div>
    @endif

    {{-- Company totals --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Company</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($report['metrics'] as $m)
                <div class="rounded p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $report['company'][$m['key']] ?? 0 }}</div>
                    <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- AT-366-E — company buyer-activity summary --}}
    @includeWhen(isset($buyer), 'performance.agency-report._buyer-summary')

    {{-- Branch rollup --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">By branch</h2>
        <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Branch</th>
                        @foreach($report['metrics'] as $m)
                            <th class="text-right px-3 py-2" style="color:var(--text-muted);">{{ $m['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['branches'] as $branchKey => $branch)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-3 py-2">
                                <a href="{{ route('performance.agency-report.branch', ['branch' => $branchKey, 'period' => $preset]) }}"
                                   class="no-underline" style="color:var(--brand, #3b82f6);">{{ $branch['label'] }}</a>
                            </td>
                            @foreach($report['metrics'] as $m)
                                <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $branch['metrics'][$m['key']] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-center" style="color:var(--text-muted);" colspan="{{ count($report['metrics']) + 1 }}">No agents in scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Per-agent journey --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">By agent</h2>
        <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Agent</th>
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Branch</th>
                        @foreach($report['metrics'] as $m)
                            <th class="text-right px-3 py-2" style="color:var(--text-muted);">{{ $m['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['agents'] as $agent)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-3 py-2">
                                <a href="{{ route('performance.agency-report.agent', ['user' => $agent['user_id'], 'period' => $preset]) }}"
                                   class="no-underline" style="color:var(--brand, #3b82f6);">{{ $agent['name'] }}</a>
                            </td>
                            <td class="px-3 py-2" style="color:var(--text-muted);">{{ $agent['branch_label'] }}</td>
                            @foreach($report['metrics'] as $m)
                                <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $agent['metrics'][$m['key']] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-center" style="color:var(--text-muted);" colspan="{{ count($report['metrics']) + 2 }}">No agents in scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-[10px]" style="color:var(--text-muted);">
        AT-366-A foundation — {{ count($report['metrics']) }} metric(s) wired. Coverage expands in AT-366-B.
    </p>
</div>
@endsection
