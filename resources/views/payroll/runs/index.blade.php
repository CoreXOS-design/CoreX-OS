@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payroll Runs" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                New Run
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        {{-- Status filter tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['all' => 'All', 'draft' => 'Draft', 'finalised' => 'Finalised', 'cancelled' => 'Cancelled'] as $key => $label)
                <a href="{{ route('payroll.runs.index', ['status' => $key]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $status === $key ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($runs->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                No payroll runs {{ $status !== 'all' ? 'with this status' : 'yet' }}. Click <strong>+ New Run</strong> to create your first one.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col style="width:110px;">{{-- Run # --}}
                        <col style="width:100px;">{{-- Period --}}
                        <col style="width:100px;">{{-- Pay date --}}
                        <col style="width:80px;">{{-- Status --}}
                        <col style="width:60px;">{{-- Headcount --}}
                        <col style="width:120px;">{{-- Total Net --}}
                        <col style="width:130px;">{{-- Finalised --}}
                        <col style="width:100px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Run #</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Period</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Pay Date</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Slips</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Total Net</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Finalised</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $run)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb); {{ $run->isCancelled() ? 'opacity:0.5;' : '' }}">
                            <td class="px-3 py-2.5 text-xs font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $run->run_number }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $run->period_month?->format('M Y') }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $run->pay_date?->format('d M Y') }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($run->isDraft())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Draft</span>
                                @elseif($run->isFinalised())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Finalised</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Cancelled</span>
                                @endif
                            </td>
                            <td class="px-2 py-2.5 text-center text-xs" style="color:var(--text-secondary, #6b7280);">{{ $run->payslip_count ?? 0 }}</td>
                            <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($run->total_net ?? 0, 2) }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                @if($run->finalised_at)
                                    {{ $run->finalisedBy->name ?? '?' }}<br>{{ $run->finalised_at->format('d M H:i') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a href="{{ route('payroll.runs.show', $run) }}" class="text-xs font-semibold" style="color:#00d4aa;">View</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Showing {{ $runs->firstItem() }}-{{ $runs->lastItem() }} of {{ $runs->total() }} results</p>
                {{ $runs->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
