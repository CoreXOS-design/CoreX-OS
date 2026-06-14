@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <nav class="text-xs" style="color: var(--text-muted);">
        <a href="{{ route('compliance.policy.index') }}" style="color: var(--brand-icon);">Policies</a>
        <span class="mx-1">/</span>
        <span>Register</span>
    </nav>

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Policy Register</h1>
                <p class="text-sm text-white/60">Monitor staff acknowledgement of agency policies.</p>
            </div>
            <div class="flex items-center gap-2">
                @if($selectedPolicy)
                <a href="{{ route('compliance.policy.dashboard.report', ['policy' => $selectedKey]) }}" target="_blank" class="corex-btn-outline">Export Report</a>
                @endif
            </div>
        </div>
    </div>

    {{-- Policy selector --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[260px]">
                <label for="policy" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Policy</label>
                <select id="policy" name="policy" onchange="this.form.submit()" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @forelse($policies as $p)
                    <option value="{{ $p->policy_key }}" {{ $selectedKey === $p->policy_key ? 'selected' : '' }}>{{ $p->name }}</option>
                    @empty
                    <option value="">No policies yet</option>
                    @endforelse
                </select>
            </div>
            {{-- preserve current filters on policy switch --}}
            @if($search)<input type="hidden" name="search" value="{{ $search }}">@endif
            @if($filterStatus)<input type="hidden" name="status" value="{{ $filterStatus }}">@endif
        </form>
    </div>

    @if(!$selectedPolicy)
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
        <strong>No active policies.</strong> Create one under <a href="{{ route('compliance.policy.index') }}" style="color: var(--brand-icon);">Policies</a> first.
    </div>
    @else
    <div class="space-y-6">
        @if($activeVersion)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent); color: var(--text-primary);">
            <div class="flex-1">
                <strong>{{ $selectedPolicy->name }} v{{ $activeVersion->version_number }}</strong>
                <span style="color: var(--text-secondary);">— approved {{ $activeVersion->approved_at?->format('d M Y') ?? 'pending' }} | Next review: {{ $activeVersion->next_review_due?->format('d M Y') ?? 'not set' }}</span>
            </div>
        </div>
        @else
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            <strong>No active version for {{ $selectedPolicy->name }}.</strong> Create and approve one first.
        </div>
        @endif

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

        @if($totalStaff > 0)
        @php
            $completion = (int) round(($validCount / $totalStaff) * 100);
            if ($completion >= 80) { $completionBar = 'ds-bar-green'; $completionColor = 'var(--ds-green)'; }
            elseif ($completion >= 40) { $completionBar = 'ds-bar-amber'; $completionColor = 'var(--ds-amber)'; }
            else { $completionBar = 'ds-bar-navy'; $completionColor = 'var(--brand-icon)'; }
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
                <input type="hidden" name="policy" value="{{ $selectedKey }}">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                    <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Search staff..."
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                    <select id="status" name="status" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm"
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
                <a href="{{ route('compliance.policy.dashboard.index', ['policy' => $selectedKey]) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
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
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Staff Member</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Role</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Acknowledged</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Valid Until</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($staffData as $s)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
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
                                <form method="POST" action="{{ route('compliance.policy.dashboard.reminder') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $s['user']->id }}">
                                    <input type="hidden" name="policy_key" value="{{ $selectedKey }}">
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
    @endif
</div>
@endsection
