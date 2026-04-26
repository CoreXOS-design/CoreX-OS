@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <nav class="text-xs" style="color: var(--text-muted);">
        <a href="{{ route('compliance.rmcp.index') }}" style="color: var(--brand-icon);">RMCP</a>
        <span class="mx-1">/</span>
        <span>Dashboard</span>
    </nav>

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">RMCP Compliance Dashboard</h1>
                <p class="text-sm text-white/60">Monitor staff acknowledgement of the active Risk Management & Compliance Programme.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('compliance.rmcp.dashboard.report') }}" target="_blank" class="corex-btn-outline">Export Report</a>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Version info --}}
        @if($activeVersion)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">
                <strong>RMCP v{{ $activeVersion->version_number }}</strong>
                <span style="color: var(--text-secondary);">— approved {{ $activeVersion->approved_at?->format('d M Y') ?? 'pending' }} | Next review: {{ $activeVersion->next_review_due?->format('d M Y') ?? 'not set' }}</span>
            </div>
        </div>
        @else
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">
                <strong>No active RMCP version.</strong> Create and approve one first.
            </div>
        </div>
        @endif

        {{-- Metric cards --}}
        @php
            $metrics = [
                ['label' => 'Active Staff',    'value' => $totalStaff,         'tone' => 'default'],
                ['label' => 'Acknowledged',    'value' => $validCount,         'tone' => 'success'],
                ['label' => 'In Progress',     'value' => $inProgressCount,    'tone' => 'warning'],
                ['label' => 'Expiring (30d)',  'value' => $expiringSoonCount,  'tone' => 'warning'],
                ['label' => 'Not Started',     'value' => $neverStartedCount,  'tone' => 'info'],
            ];
            $toneColor = [
                'default' => 'var(--text-primary)',
                'success' => 'var(--ds-green)',
                'warning' => 'var(--ds-amber)',
                'info'    => 'var(--brand-icon)',
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            @foreach($metrics as $m)
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-[1.625rem] font-semibold leading-tight" style="color: {{ $toneColor[$m['tone']] }};">{{ number_format($m['value']) }}</div>
                <div class="text-xs font-medium mt-1" style="color: var(--text-secondary);">{{ $m['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Completion bar --}}
        @if($totalStaff > 0)
        @php
            $completion = (int) round(($validCount / $totalStaff) * 100);
            if ($completion >= 80) {
                $completionBar = 'ds-bar-green';
                $completionColor = 'var(--ds-green)';
            } elseif ($completion >= 40) {
                $completionBar = 'ds-bar-amber';
                $completionColor = 'var(--ds-amber)';
            } else {
                $completionBar = 'ds-bar-navy';
                $completionColor = 'var(--brand-icon)';
            }
        @endphp
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold" style="color: var(--text-secondary);">Overall Completion</span>
                <span class="text-xs font-semibold" style="color: {{ $completionColor }};">{{ $completion }}%</span>
            </div>
            <div class="ds-progress-track">
                <div class="ds-progress-bar {{ $completionBar }}" style="width: {{ $completion }}%"></div>
            </div>
        </div>
        @endif

        {{-- Filters --}}
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                    <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Search staff..."
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                    <select id="status" name="status" onchange="this.form.submit()" class="list-header-filter rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">All statuses</option>
                        <option value="valid" {{ $filterStatus === 'valid' ? 'selected' : '' }}>Acknowledged</option>
                        <option value="in_progress" {{ $filterStatus === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                        <option value="expired" {{ $filterStatus === 'expired' ? 'selected' : '' }}>Expired</option>
                        <option value="not_started" {{ $filterStatus === 'not_started' ? 'selected' : '' }}>Not Started</option>
                    </select>
                </div>
                <button type="submit" class="corex-btn-primary">Apply</button>
                @if($search || $filterStatus)
                <a href="{{ route('compliance.rmcp.dashboard.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
                @endif
            </form>
            <div class="text-xs mt-3" style="color: var(--text-muted);">Showing {{ number_format(count($staffData)) }} of {{ number_format($totalStaff) }} staff</div>
        </div>

        {{-- Staff table --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <x-sort-header field="name" label="Staff Member" :current-sort="$sort" :current-direction="$direction" />
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Role</th>
                            <x-sort-header field="status" label="Status" :current-sort="$sort" :current-direction="$direction" />
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Acknowledged</th>
                            <x-sort-header field="valid_until" label="Valid Until" :current-sort="$sort" :current-direction="$direction" />
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($staffData as $s)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $s['user']->name }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $s['user']->role }}</td>
                            <td class="px-4 py-3">
                                @if($s['status'] === 'valid')
                                    <span class="ds-badge ds-badge-success">Valid</span>
                                @elseif($s['status'] === 'in_progress')
                                    <span class="ds-badge ds-badge-warning">{{ (int) $s['progress'] }}%</span>
                                @elseif($s['status'] === 'expired')
                                    <span class="ds-badge ds-badge-warning">Expired</span>
                                @elseif($s['status'] === 'not_started')
                                    <span class="ds-badge ds-badge-warning">Not Started</span>
                                @else
                                    <span class="ds-badge ds-badge-default">N/A</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $s['acknowledged_on']?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $s['valid_until']?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if(in_array($s['status'], ['not_started', 'expired']))
                                <form method="POST" action="{{ route('compliance.rmcp.dashboard.reminder') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $s['user']->id }}">
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Remind</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No staff found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
