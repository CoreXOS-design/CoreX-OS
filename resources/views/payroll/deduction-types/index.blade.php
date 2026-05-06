@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payroll Deduction Types" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.deduction-types.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Deduction Type
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">Configure agency-specific deduction categories. Statutory deductions (PAYE, UIF) are auto-calculated by the payroll engine.</p>

        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        {{-- Search + filters --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <form method="GET" action="{{ route('payroll.deduction-types.index') }}" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search code or label..." class="px-3 py-1.5 text-xs w-56 focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                <input type="hidden" name="status" value="{{ $status }}">
                <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Search</button>
                @if($q)
                    <a href="{{ route('payroll.deduction-types.index', ['status' => $status]) }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Clear</a>
                @endif
            </form>
        </div>

        {{-- Status filter tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                <a href="{{ route('payroll.deduction-types.index', ['status' => $key, 'q' => $q]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $status === $key ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($types->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                No deduction types {{ $q ? 'matching your search' : 'yet' }}. Click <strong>+ Add Deduction Type</strong> to create your first one.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col style="width:120px;">{{-- Code --}}
                        <col>{{-- Label --}}
                        <col style="width:80px;">{{-- SARS --}}
                        <col style="width:80px;">{{-- Statutory --}}
                        <col style="width:60px;">{{-- Sort --}}
                        <col style="width:90px;">{{-- Status --}}
                        <col style="width:140px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Code</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Label</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">SARS</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Statutory</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Sort</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($types as $type)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb); {{ !$type->is_active ? 'opacity:0.5;' : '' }}">
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #94a3b8); font-family:monospace;">{{ $type->code }}</td>
                            <td class="px-3 py-2.5 font-semibold" style="color:var(--text-primary, #0f172a);">
                                {{ $type->label }}
                                @if($type->is_system)
                                    <span class="ml-1.5 px-1.5 py-0.5 text-[10px] font-bold uppercase" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">System</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-xs" style="font-family:monospace; color:var(--text-secondary, #6b7280);">{{ $type->sars_source_code ?? '-' }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($type->is_statutory)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Statutory</span>
                                @else
                                    <span class="text-xs" style="color:var(--text-secondary, #94a3b8);">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2.5 text-center text-xs" style="color:var(--text-secondary, #6b7280);">{{ $type->sort_order }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($type->is_active)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Active</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Inactive</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('payroll.deduction-types.edit', $type) }}" class="text-xs font-semibold" style="color:#00d4aa;">Edit</a>
                                    @if(!$type->is_system && !$type->is_statutory)
                                        <form method="POST" action="{{ route('payroll.deduction-types.destroy', $type) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Delete this deduction type? This action cannot be undone.')">Delete</button>
                                        </form>
                                    @else
                                        <span class="text-xs" style="color:var(--text-secondary, #cbd5e1); cursor:not-allowed;" title="{{ $type->is_statutory ? 'Statutory types cannot be deleted' : 'System types cannot be deleted' }}">Delete</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Showing {{ $types->firstItem() }}-{{ $types->lastItem() }} of {{ $types->total() }} results</p>
                {{ $types->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
