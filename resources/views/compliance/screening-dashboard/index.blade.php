@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Staff Screening Dashboard" :back-route="route('compliance.fica.index')" back-label="Compliance" :flush="true">
        <x-slot:actions>
            <a href="{{ route('compliance.screenings.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-secondary, #6b7280);">All Screenings</a>
            <a href="{{ route('compliance.screenings.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="background:#00d4aa; color:#0f172a; border-radius:3px;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Screening
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Metric cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-6">
            @php
                $metrics = [
                    ['label' => 'Active Staff', 'value' => $totalStaff, 'color' => '#64748b'],
                    ['label' => 'Clear', 'value' => $clearCount, 'color' => '#00d4aa'],
                    ['label' => 'Flagged', 'value' => $flaggedCount, 'color' => '#ef4444'],
                    ['label' => 'Overdue', 'value' => $overdueCount, 'color' => '#f97316'],
                    ['label' => 'Pending', 'value' => $pendingCount, 'color' => '#eab308'],
                    ['label' => 'Never Screened', 'value' => $neverCount, 'color' => '#ef4444'],
                ];
            @endphp
            @foreach($metrics as $m)
            <div class="px-4 py-3" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                <div class="text-2xl font-bold" style="color:{{ $m['color'] }}; font-family:'Plus Jakarta Sans',sans-serif;">{{ $m['value'] }}</div>
                <div class="text-xs font-semibold mt-0.5" style="color:#64748b;">{{ $m['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Filters --}}
        <form method="GET" class="flex items-center gap-3 mb-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search staff..." class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px; max-width:200px; font-family:'Plus Jakarta Sans',sans-serif;">
            <select name="risk_tier" onchange="this.form.submit()" class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <option value="">All tiers</option>
                <option value="high" {{ request('risk_tier') === 'high' ? 'selected' : '' }}>High</option>
                <option value="medium" {{ request('risk_tier') === 'medium' ? 'selected' : '' }}>Medium</option>
                <option value="low" {{ request('risk_tier') === 'low' ? 'selected' : '' }}>Low</option>
            </select>
            <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <option value="">All statuses</option>
                <option value="clear" {{ request('status') === 'clear' ? 'selected' : '' }}>Clear</option>
                <option value="concerns_flagged" {{ request('status') === 'concerns_flagged' ? 'selected' : '' }}>Flagged</option>
                <option value="overdue" {{ request('status') === 'overdue' ? 'selected' : '' }}>Overdue</option>
                <option value="never_screened" {{ request('status') === 'never_screened' ? 'selected' : '' }}>Never Screened</option>
            </select>
            @if(request('search') || request('risk_tier') || request('status'))
            <a href="{{ route('compliance.screening.dashboard.index') }}" class="text-xs" style="color:#6b7280;">Clear</a>
            @endif
        </form>

        {{-- Staff table --}}
        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Staff Member</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Role</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Risk Tier</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Status</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Next Due</th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($staffData as $s)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary, #1f2937);">{{ $s['user']->name }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $s['user']->role }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold" style="color:{{ $s['risk_tier'] === 'high' ? '#ef4444' : ($s['risk_tier'] === 'medium' ? '#eab308' : '#00d4aa') }};">{{ ucfirst($s['risk_tier']) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php $st = $s['status']; @endphp
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="border-radius:3px; background:{{ match($st) { 'clear' => 'rgba(0,212,170,0.15)', 'concerns_flagged' => 'rgba(239,68,68,0.15)', 'overdue','expired','never_screened' => 'rgba(239,68,68,0.15)', 'pre_employment_pending' => 'rgba(234,179,8,0.15)', default => 'rgba(148,163,184,0.15)' } }}; color:{{ match($st) { 'clear' => '#00d4aa', 'concerns_flagged','overdue','expired','never_screened' => '#ef4444', 'pre_employment_pending' => '#eab308', default => '#94a3b8' } }};">
                                {{ str_replace('_', ' ', ucfirst($st)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:{{ $s['screening_due'] && \Carbon\Carbon::parse($s['screening_due'])->isPast() ? '#ef4444' : '#64748b' }};">
                            {{ $s['screening_due'] ? \Carbon\Carbon::parse($s['screening_due'])->format('d M Y') : '-' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($s['latest'])
                            <a href="{{ route('compliance.screenings.show', $s['latest']) }}" class="text-xs font-semibold px-2 py-1" style="color:#00d4aa;">View</a>
                            @endif
                            @if(in_array($st, ['never_screened', 'overdue', 'expired']))
                            <a href="{{ route('compliance.screenings.create', $s['user']) }}" class="text-xs font-semibold px-2 py-1" style="background:rgba(0,212,170,0.15); color:#00d4aa; border-radius:3px; text-decoration:none;">Screen</a>
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
