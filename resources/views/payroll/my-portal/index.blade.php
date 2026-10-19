{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('agent.portal') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; My Portal</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">My Payslips</h1>
                <p class="text-xs" style="color: var(--text-muted);" data-tour="portal-payslips-intro">Your finalised payslips. Click View to see details or Download to get the PDF.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    <div>

        @if($payslips->isEmpty())
            <div class="rounded-md py-12 px-6 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color:var(--text-muted);">
                No payslips yet. Your payslips will appear here once your employer finalises a payroll run.
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto" data-tour="portal-payslips-table">
                <table class="w-full text-sm ds-table">
                    <colgroup>
                        <col style="width:120px;">{{-- Period --}}
                        <col style="width:110px;">{{-- Pay Date --}}
                        <col style="width:120px;">{{-- Gross --}}
                        <col style="width:120px;">{{-- Net --}}
                        <col style="width:140px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Period</th>
                            <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Pay Date</th>
                            <th class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Net Pay</th>
                            <th class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);" data-tour="portal-payslips-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payslips as $ps)
                        <tr>
                            <td class="px-3 py-2.5 text-sm font-semibold" style="color:var(--text-primary, #0f172a);">{{ $ps->period_month?->format('M Y') }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $ps->pay_date?->format('d M Y') }}</td>
                            <td class="px-3 py-2.5 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->total_earnings, 2) }}</td>
                            <td class="px-3 py-2.5 text-right text-sm font-semibold" style="color:var(--brand-icon);">R {{ number_format($ps->net_pay, 2) }}</td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('my-portal.payslips.show', $ps) }}" data-tour="portal-payslips-view" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                                    <a href="{{ route('my-portal.payslips.pdf', $ps) }}" data-tour="portal-payslips-download" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Download</a>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 flex items-center justify-between" style="border-top: 1px solid var(--border);">
                <p class="text-xs" style="color:var(--text-muted);">Showing {{ $payslips->firstItem() }}-{{ $payslips->lastItem() }} of {{ $payslips->total() }} payslips</p>
                {{ $payslips->links() }}
            </div>
            </div>
        @endif
    </div>
</div>
@endsection
