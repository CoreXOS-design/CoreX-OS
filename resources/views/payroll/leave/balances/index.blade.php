@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Leave Balances" :flush="true" />

    <div class="p-4 lg:p-6">
        {{-- Search + filter --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <form method="GET" action="{{ route('payroll.leave.balances.index') }}" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search employee..." class="px-3 py-1.5 text-xs w-48 focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                <select name="branch" class="px-3 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ ($branchFilter ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Filter</button>
                @if($q || $branchFilter)
                    <a href="{{ route('payroll.leave.balances.index') }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Clear</a>
                @endif
            </form>
        </div>

        @if($employees->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">No active payroll employees found.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col>
                        <col style="width:100px;">
                        <col style="width:100px;">
                        <col style="width:100px;">
                        <col style="width:70px;">
                        <col style="width:90px;">
                        <col style="width:60px;">
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Branch</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Annual</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Sick</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">FRL</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Take-On</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($employees as $emp)
                        @php $bal = $balances[$emp->id] ?? []; @endphp
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5 font-semibold" style="color:var(--text-primary, #0f172a);">{{ $emp->user->name ?? 'Unknown' }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $emp->user->branch->name ?? '-' }}</td>
                            <td class="px-2 py-2.5 text-center text-xs">
                                @if(isset($bal['annual']))
                                    <span class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$bal['annual']['available_days'], 1) }}</span>
                                    <span style="color:var(--text-secondary, #94a3b8);">/ {{ number_format((float)$bal['annual']['entitlement_days'], 0) }}</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2.5 text-center text-xs">
                                @if(isset($bal['sick']))
                                    <span class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$bal['sick']['available_days'], 1) }}</span>
                                    <span style="color:var(--text-secondary, #94a3b8);">/ {{ number_format((float)$bal['sick']['entitlement_days'], 0) }}</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2.5 text-center text-xs">
                                @if(isset($bal['frl']))
                                    <span class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$bal['frl']['available_days'], 1) }}</span>
                                    <span style="color:var(--text-secondary, #94a3b8);">/ {{ number_format((float)$bal['frl']['entitlement_days'], 0) }}</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                @php $to = \App\Models\Leave\StaffTakeOnRecord::where('user_id', $emp->user_id)->first(); @endphp
                                @if($to?->isComplete())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Done</span>
                                @elseif($to)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">{{ $to->progressPercentage() }}%</span>
                                @else
                                    <span class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a href="{{ route('payroll.leave.balances.show', $emp) }}" class="text-xs font-semibold" style="color:#00d4aa;">View</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $employees->links() }}</div>
        @endif
    </div>
</div>
@endsection
