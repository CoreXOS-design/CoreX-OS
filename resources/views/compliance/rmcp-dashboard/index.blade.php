@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="RMCP Compliance Dashboard" :back-route="route('compliance.rmcp.index')" back-label="RMCP" :flush="true">
        <x-slot:actions>
            <a href="{{ route('compliance.rmcp.dashboard.report') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-secondary, #6b7280);">Export Report</a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Version info --}}
        @if($activeVersion)
        <div class="mb-4 px-4 py-3 text-sm" style="background:rgba(0,212,170,0.06); border:1px solid rgba(0,212,170,0.2); border-radius:3px; color:#64748b;">
            <strong style="color:#0f172a;">RMCP v{{ $activeVersion->version_number }}</strong> — approved {{ $activeVersion->approved_at?->format('d M Y') ?? 'pending' }}
            | Next review: {{ $activeVersion->next_review_due?->format('d M Y') ?? 'not set' }}
        </div>
        @else
        <div class="mb-4 px-4 py-3 text-sm" style="background:rgba(234,179,8,0.1); border:1px solid rgba(234,179,8,0.3); border-radius:3px; color:#ca8a04;">
            No active RMCP version. Create and approve one first.
        </div>
        @endif

        {{-- Metric cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
            @php
                $metrics = [
                    ['label' => 'Active Staff', 'value' => $totalStaff, 'color' => '#64748b'],
                    ['label' => 'Acknowledged', 'value' => $validCount, 'color' => '#00d4aa'],
                    ['label' => 'In Progress', 'value' => $inProgressCount, 'color' => '#eab308'],
                    ['label' => 'Expiring (30d)', 'value' => $expiringSoonCount, 'color' => '#f97316'],
                    ['label' => 'Not Started', 'value' => $neverStartedCount, 'color' => '#ef4444'],
                ];
            @endphp
            @foreach($metrics as $m)
            <div class="px-4 py-3" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                <div class="text-2xl font-bold" style="color:{{ $m['color'] }}; font-family:'Plus Jakarta Sans',sans-serif;">{{ $m['value'] }}</div>
                <div class="text-xs font-semibold mt-0.5" style="color:#64748b;">{{ $m['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Completion bar --}}
        @if($totalStaff > 0)
        <div class="mb-6 px-4 py-3" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold" style="color:#64748b;">Overall Completion</span>
                <span class="text-xs font-bold" style="color:#00d4aa;">{{ $totalStaff > 0 ? round(($validCount / $totalStaff) * 100) : 0 }}%</span>
            </div>
            <div class="w-full h-2 rounded-full overflow-hidden" style="background:rgba(0,212,170,0.15);">
                <div class="h-full rounded-full" style="background:#00d4aa; width:{{ $totalStaff > 0 ? round(($validCount / $totalStaff) * 100) : 0 }}%;"></div>
            </div>
        </div>
        @endif

        {{-- Filters --}}
        <form method="GET" class="flex items-center gap-3 mb-4">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search staff..." class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px; font-family:'Plus Jakarta Sans',sans-serif; max-width:220px;">
            <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <option value="">All statuses</option>
                <option value="valid" {{ $filterStatus === 'valid' ? 'selected' : '' }}>Acknowledged</option>
                <option value="in_progress" {{ $filterStatus === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="expired" {{ $filterStatus === 'expired' ? 'selected' : '' }}>Expired</option>
                <option value="not_started" {{ $filterStatus === 'not_started' ? 'selected' : '' }}>Not Started</option>
            </select>
            @if($search || $filterStatus)
            <a href="{{ route('compliance.rmcp.dashboard.index') }}" class="text-xs" style="color:#6b7280;">Clear</a>
            @endif
        </form>

        <div class="text-xs mb-2" style="color:#64748b;">Showing {{ count($staffData) }} of {{ $totalStaff }} staff</div>

        {{-- Staff table --}}
        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">
                            <a href="?sort=name&direction={{ $sort === 'name' && $direction === 'asc' ? 'desc' : 'asc' }}&search={{ $search }}&status={{ $filterStatus }}">Staff Member</a>
                        </th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Role</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">
                            <a href="?sort=status&direction={{ $sort === 'status' && $direction === 'asc' ? 'desc' : 'asc' }}&search={{ $search }}&status={{ $filterStatus }}">Status</a>
                        </th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Acknowledged</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">
                            <a href="?sort=valid_until&direction={{ $sort === 'valid_until' && $direction === 'asc' ? 'desc' : 'asc' }}&search={{ $search }}&status={{ $filterStatus }}">Valid Until</a>
                        </th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($staffData as $s)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary, #1f2937);">{{ $s['user']->name }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s['user']->role }}</td>
                        <td class="px-4 py-3">
                            @if($s['status'] === 'valid')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(0,212,170,0.15); color:#00d4aa; border-radius:3px;">Valid</span>
                            @elseif($s['status'] === 'in_progress')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(234,179,8,0.15); color:#eab308; border-radius:3px;">{{ $s['progress'] }}%</span>
                            @elseif($s['status'] === 'expired')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(239,68,68,0.15); color:#ef4444; border-radius:3px;">Expired</span>
                            @elseif($s['status'] === 'not_started')
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(239,68,68,0.15); color:#ef4444; border-radius:3px;">Not Started</span>
                            @else
                            <span class="text-xs" style="color:#94a3b8;">N/A</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s['acknowledged_on']?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s['valid_until']?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if(in_array($s['status'], ['not_started', 'expired']))
                            <form method="POST" action="{{ route('compliance.rmcp.dashboard.reminder') }}" class="inline">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $s['user']->id }}">
                                <button type="submit" class="text-xs font-semibold px-2 py-1" style="color:#f59e0b;">Remind</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center" style="color:#94a3b8;">No staff found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
