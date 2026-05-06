@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payroll Employees" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.employees.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Employee
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        {{-- Search --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <form method="GET" action="{{ route('payroll.employees.index') }}" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search name or email..." class="px-3 py-1.5 text-xs w-56 focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                <input type="hidden" name="status" value="{{ $status }}">
                <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Search</button>
                @if($q)
                    <a href="{{ route('payroll.employees.index', ['status' => $status]) }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Clear</a>
                @endif
            </form>
        </div>

        {{-- Status filter tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated', 'all' => 'All'] as $key => $label)
                <a href="{{ route('payroll.employees.index', ['status' => $key, 'q' => $q]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $status === $key ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($employees->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                No payroll employees {{ $q ? 'matching your search' : 'yet' }}. Add someone from your user list to get started.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col>{{-- Name --}}
                        <col style="width:120px;">{{-- Branch --}}
                        <col style="width:120px;">{{-- Basic Salary --}}
                        <col style="width:110px;">{{-- Employment Date --}}
                        <col style="width:90px;">{{-- Status --}}
                        <col style="width:160px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Branch</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Basic Salary</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employed</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($employees as $emp)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb); {{ !$emp->is_active ? 'opacity:0.6;' : '' }}">
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white" style="background:#00d4aa;">
                                        {{ strtoupper(substr($emp->user->name ?? '?', 0, 1)) }}
                                    </div>
                                    <div>
                                        <a href="{{ route('payroll.employees.show', $emp) }}" class="font-semibold text-sm" style="color:var(--text-primary, #0f172a);">{{ $emp->user->name ?? 'Unknown' }}</a>
                                        <p class="text-[11px]" style="color:var(--text-secondary, #94a3b8);">{{ $emp->designation_snapshot }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $emp->user->branch->name ?? '-' }}</td>
                            <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">
                                @if($emp->basic_salary !== null)
                                    R {{ number_format($emp->basic_salary, 2) }}
                                @else
                                    <span style="color:var(--text-secondary, #94a3b8);">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $emp->employment_date?->format('d M Y') ?? '-' }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($emp->termination_date)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(239,68,68,0.1); color:#ef4444; border-radius:6px;">Terminated</span>
                                @elseif($emp->is_active)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Active</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Inactive</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('payroll.employees.show', $emp) }}" class="text-xs font-semibold" style="color:#00d4aa;">View</a>
                                    <a href="{{ route('payroll.employees.edit', $emp) }}" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Edit</a>
                                    @if($emp->is_active && !$emp->termination_date)
                                        <form method="POST" action="{{ route('payroll.employees.deactivate', $emp) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color:#eab308; background:none; border:none; cursor:pointer;" onclick="return confirm('Deactivate this employee? They will be skipped in future runs.')">Deactivate</button>
                                        </form>
                                    @elseif(!$emp->termination_date)
                                        <form method="POST" action="{{ route('payroll.employees.reactivate', $emp) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">Reactivate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Showing {{ $employees->firstItem() }}-{{ $employees->lastItem() }} of {{ $employees->total() }} results</p>
                {{ $employees->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
