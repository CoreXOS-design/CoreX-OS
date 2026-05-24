{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="My Payslips" :back-route="route('agent.portal')" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6">
        <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">Your finalised payslips. Click View to see details or Download to get the PDF.</p>

        @if($payslips->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                No payslips yet. Your payslips will appear here once your employer finalises a payroll run.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col style="width:120px;">{{-- Period --}}
                        <col style="width:110px;">{{-- Pay Date --}}
                        <col style="width:120px;">{{-- Gross --}}
                        <col style="width:120px;">{{-- Net --}}
                        <col style="width:140px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Period</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Pay Date</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Net Pay</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payslips as $ps)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5 text-sm font-semibold" style="color:var(--text-primary, #0f172a);">{{ $ps->period_month?->format('M Y') }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $ps->pay_date?->format('d M Y') }}</td>
                            <td class="px-3 py-2.5 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->total_earnings, 2) }}</td>
                            <td class="px-3 py-2.5 text-right text-sm font-semibold" style="color:var(--brand-icon);">R {{ number_format($ps->net_pay, 2) }}</td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('my-portal.payslips.show', $ps) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                                    <a href="{{ route('my-portal.payslips.pdf', $ps) }}" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Download</a>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Showing {{ $payslips->firstItem() }}-{{ $payslips->lastItem() }} of {{ $payslips->total() }} payslips</p>
                {{ $payslips->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
