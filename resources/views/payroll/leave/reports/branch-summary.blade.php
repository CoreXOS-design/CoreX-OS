@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Branch Leave Summary" :flush="true" />

    <div class="p-4 lg:p-6">
        {{-- Report navigation tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            <a href="{{ route('payroll.leave.reports.register') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Register</a>
            <a href="{{ route('payroll.leave.reports.branch-summary') }}" class="px-3 py-1.5 text-xs font-semibold" style="border-bottom:2px solid #00d4aa; color:#00d4aa;">Branch Summary</a>
            <a href="{{ route('payroll.leave.reports.audit-log') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Audit Log</a>
        </div>

        @if(empty($summary))
            <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No branches found.</div>
        @else
            <div class="space-y-4">
                @foreach($summary as $s)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ $s['branch']->name }}</h4>
                        <div class="flex items-center gap-2">
                            <span class="text-xs" style="color:var(--text-secondary, #6b7280);">{{ $s['employee_count'] }} employees</span>
                            @if($s['compliance_flags'] > 0)
                                <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(239,68,68,0.1); color:#ef4444; border-radius:6px;">{{ $s['compliance_flags'] }} at risk</span>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div class="text-center">
                            <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Annual Entitled</p>
                            <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$s['annual_entitled'], 1) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Annual Taken</p>
                            <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$s['annual_taken'], 1) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Annual Available</p>
                            <p class="text-sm font-bold" style="color:#00d4aa;">{{ number_format((float)$s['annual_available'], 1) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Sick Taken</p>
                            <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$s['sick_taken'], 1) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">At Risk (>1.5x)</p>
                            <p class="text-sm font-bold" style="color:{{ $s['annual_at_risk'] > 0 ? '#ef4444' : 'var(--text-primary, #0f172a)' }};">{{ $s['annual_at_risk'] }}</p>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
