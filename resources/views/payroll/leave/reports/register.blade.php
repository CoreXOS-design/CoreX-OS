@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Leave Register" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.leave.reports.register.export', ['format' => 'xlsx', 'from' => $dateFrom, 'to' => $dateTo, 'status' => $status, 'type' => $typeFilter, 'branch' => $branchFilter]) }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Export CSV</a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Report navigation tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            <a href="{{ route('payroll.leave.reports.register') }}" class="px-3 py-1.5 text-xs font-semibold" style="border-bottom:2px solid #00d4aa; color:var(--brand-icon);">Register</a>
            <a href="{{ route('payroll.leave.reports.branch-summary') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Branch Summary</a>
            <a href="{{ route('payroll.leave.reports.audit-log') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Audit Log</a>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('payroll.leave.reports.register') }}" class="flex flex-wrap items-end gap-3 mb-4">
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">From</label>
                <input type="date" name="from" value="{{ $dateFrom }}" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);"></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">To</label>
                <input type="date" name="to" value="{{ $dateTo }}" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);"></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Status</label>
                <select name="status" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                    <option value="">All</option>
                    @foreach(['submitted','approved','rejected','cancelled','taken'] as $s)
                        <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Type</label>
                <select name="type" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                    <option value="">All</option>
                    @foreach($leaveTypes as $lt)
                        <option value="{{ $lt->id }}" {{ ($typeFilter ?? '') == $lt->id ? 'selected' : '' }}>{{ $lt->label }}</option>
                    @endforeach
                </select></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Search</label>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Employee name..." class="px-2 py-1.5 text-xs w-36 focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);"></div>
            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-icon); border-radius:6px;">Apply</button>
            <a href="{{ route('payroll.leave.reports.register') }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Reset</a>
        </form>

        @if($applications->isEmpty())
            <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No applications found for this period.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">App #</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Employee</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Type</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Period</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Days</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Status</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Decided By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $app)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-2 py-2 text-xs" style="font-family:monospace; color:var(--text-secondary, #94a3b8);">{{ $app->application_number }}</td>
                            <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $app->user->name ?? '-' }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $app->leaveType->label ?? '-' }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $app->start_date?->format('d M') }} â€” {{ $app->end_date?->format('d M') }}</td>
                            <td class="px-2 py-2 text-center text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format($app->working_days_requested, 1) }}</td>
                            <td class="px-2 py-2 text-center">
                                @php $sc = ['submitted'=>'#eab308','approved'=>'#00d4aa','rejected'=>'#ef4444','cancelled'=>'#94a3b8','taken'=>'#3b82f6']; @endphp
                                <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:{{ $sc[$app->status] ?? '#94a3b8' }}15; color:{{ $sc[$app->status] ?? '#94a3b8' }}; border-radius:6px;">{{ ucfirst($app->status) }}</span>
                            </td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $app->decidedBy->name ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $applications->links() }}</div>
        @endif
    </div>
</div>
@endsection
