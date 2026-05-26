@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Leave Applications" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--brand-icon);">{{ session('success') }}</div>
        @endif

        {{-- Search + type filter --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <form method="GET" action="{{ route('payroll.leave.applications.index') }}" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search name or app #..." class="px-3 py-1.5 text-xs w-48 focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                <select name="type" class="px-3 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                    <option value="">All Types</option>
                    @foreach($leaveTypes as $lt)
                        <option value="{{ $lt->id }}" {{ ($typeFilter ?? '') == $lt->id ? 'selected' : '' }}>{{ $lt->label }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="status" value="{{ $status }}">
                <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-icon); border-radius:6px;">Filter</button>
                @if($q || $typeFilter)
                    <a href="{{ route('payroll.leave.applications.index', ['status' => $status]) }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Clear</a>
                @endif
            </form>
        </div>

        {{-- Filter tabs --}}
        <div class="flex flex-wrap gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'this_month' => 'This Month'] as $key => $label)
                <a href="{{ route('payroll.leave.applications.index', ['status' => $key, 'q' => $q, 'type' => $typeFilter]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $status === $key ? 'border-bottom:2px solid #00d4aa; color:var(--brand-icon);' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($applications->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">No leave applications {{ $status !== 'all' ? 'with this status' : 'yet' }}.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col style="width:110px;">
                        <col>
                        <col style="width:100px;">
                        <col style="width:150px;">
                        <col style="width:50px;">
                        <col style="width:80px;">
                        <col style="width:90px;">
                        <col style="width:60px;">
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">App #</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Type</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Period</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Days</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-left px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Submitted</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $app)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #94a3b8); font-family:monospace;">{{ $app->application_number }}</td>
                            <td class="px-3 py-2.5 font-semibold text-xs" style="color:var(--text-primary, #0f172a);">{{ $app->user->name ?? 'Unknown' }}</td>
                            <td class="px-2 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $app->leaveType->label ?? '-' }}</td>
                            <td class="px-2 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $app->start_date?->format('d M') }} â€” {{ $app->end_date?->format('d M Y') }}</td>
                            <td class="px-2 py-2.5 text-center text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format($app->working_days_requested, 1) }}</td>
                            <td class="px-2 py-2.5 text-center">
                                @php $statusColors = ['submitted'=>'#eab308','approved'=>'#00d4aa','rejected'=>'#ef4444','cancelled'=>'#94a3b8','taken'=>'#3b82f6','draft'=>'#6b7280','no_show'=>'#ef4444']; @endphp
                                <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:{{ $statusColors[$app->status] ?? '#94a3b8' }}15; color:{{ $statusColors[$app->status] ?? '#94a3b8' }}; border-radius:6px;">{{ ucfirst($app->status) }}</span>
                            </td>
                            <td class="px-2 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $app->submitted_at?->format('d M H:i') }}</td>
                            <td class="px-3 py-2.5 text-right">
                                <a href="{{ route('payroll.leave.applications.show', $app) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                            </td>
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
