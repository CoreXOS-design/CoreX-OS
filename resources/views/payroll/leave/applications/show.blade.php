@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Application {{ $application->application_number }}" :back-route="route('payroll.leave.applications.index')" back-label="Applications" :flush="true" />

    <div class="p-4 lg:p-6 max-w-7xl">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--brand-icon);">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); border-radius:6px; color:var(--ds-crimson);">{{ session('error') }}</div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            {{-- LEFT (1/3) --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Applicant --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Applicant</h4>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" style="background:var(--brand-icon);">{{ strtoupper(substr($application->user->name ?? '?', 0, 1)) }}</div>
                        <div>
                            <p class="text-sm font-semibold" style="color:var(--text-primary, #0f172a);">{{ $application->user->name }}</p>
                            <p class="text-xs" style="color:var(--text-secondary, #94a3b8);">{{ $application->payrollEmployee?->designation_snapshot }} | {{ $application->user->branch->name ?? '-' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Application details --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Leave Details</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Type</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $application->leaveType->label ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Period</dt><dd style="color:var(--text-primary, #0f172a);">{{ $application->start_date?->format('d M') }} â€” {{ $application->end_date?->format('d M Y') }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Working Days</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format($application->working_days_requested, 1) }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Calendar Days</dt><dd style="color:var(--text-primary, #0f172a);">{{ $application->calendar_days_requested }}</dd></div>
                        @if($application->is_half_day)
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Half Day</dt><dd style="color:var(--text-primary, #0f172a);">{{ ucfirst($application->half_day_period) }}</dd></div>
                        @endif
                        @if($application->reason)
                            <div><dt style="color:var(--text-secondary, #6b7280);">Reason:</dt><dd class="mt-0.5" style="color:var(--text-primary, #0f172a);">{{ $application->reason }}</dd></div>
                        @endif
                    </dl>
                </div>

                {{-- Balance impact --}}
                @if($balanceBefore)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Balance Impact</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Before</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$balanceBefore['available_days'], 2) }} days</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">This Application</dt><dd class="font-semibold" style="color:var(--ds-crimson);">-{{ number_format($application->working_days_requested, 2) }} days</dd></div>
                        @php $afterBalance = bcsub($balanceBefore['available_days'], (string)$application->working_days_requested, 2); @endphp
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">After (if approved)</dt><dd class="font-semibold" style="color:{{ (float)$afterBalance < 0 ? '#ef4444' : '#00d4aa' }};">{{ number_format((float)$afterBalance, 2) }} days</dd></div>
                    </dl>
                    @if((float)$afterBalance < 0 && !$application->leaveType->allows_negative_balance)
                        <div class="mt-2 p-2 text-[10px] font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid rgba(239,68,68,0.15); border-radius:6px; color:var(--ds-crimson);">
                            This will create a negative balance. {{ $application->leaveType->label }} does not allow negative balances.
                        </div>
                    @endif
                </div>
                @endif

                {{-- Documents --}}
                @if($application->documents->count() > 0)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Documents</h4>
                    @foreach($application->documents as $doc)
                        <p class="text-xs" style="color:var(--text-primary, #0f172a);">{{ $doc->original_name ?? $doc->file_name ?? 'Document' }}</p>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- RIGHT (2/3) --}}
            <div class="lg:w-2/3 space-y-4">
                {{-- Status banner --}}
                @php $statusColors = ['submitted'=>['#eab308','color-mix(in srgb, var(--ds-amber) 8%, transparent)'],'approved'=>['#00d4aa','color-mix(in srgb, var(--brand-icon) 8%, transparent)'],'rejected'=>['#ef4444','color-mix(in srgb, var(--ds-crimson) 8%, transparent)'],'cancelled'=>['#94a3b8','rgba(148,163,184,0.08)'],'taken'=>['#3b82f6','rgba(59,130,246,0.08)']]; @endphp
                @php $sc = $statusColors[$application->status] ?? ['#94a3b8','rgba(148,163,184,0.08)']; @endphp
                <div class="p-4" style="background:{{ $sc[1] }}; border:1px solid {{ $sc[0] }}25; border-radius:6px;">
                    <div class="flex items-center gap-3">
                        <span class="text-lg font-bold" style="color:{{ $sc[0] }};">{{ ucfirst($application->status) }}</span>
                        @if($application->decided_at)
                            <span class="text-xs" style="color:var(--text-secondary, #6b7280);">
                                by {{ $application->decidedBy->name ?? '?' }} ({{ $application->decided_by_role }}) on {{ $application->decided_at->format('d M Y H:i') }}
                            </span>
                        @endif
                    </div>
                    @if($application->decision_reason)
                        <p class="text-xs mt-1" style="color:var(--text-primary, #0f172a);">Reason: {{ $application->decision_reason }}</p>
                    @endif
                    @if($application->cancellation_reason)
                        <p class="text-xs mt-1" style="color:var(--text-primary, #0f172a);">Cancellation: {{ $application->cancellation_reason }}</p>
                    @endif
                </div>

                {{-- Decision panel (pending only) --}}
                @if($application->isSubmitted())
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;" x-data="{ showReject: false }">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Decision</h4>
                    <div class="flex gap-3">
                        <form method="POST" action="{{ route('payroll.leave.applications.approve', $application) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;" onclick="return confirm('Approve this leave application?')">Approve</button>
                        </form>
                        <button @click="showReject = !showReject" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); border-radius:6px; background:none; cursor:pointer;">Reject</button>
                    </div>

                    <form method="POST" action="{{ route('payroll.leave.applications.reject', $application) }}" x-show="showReject" x-cloak class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Reason for rejection (min 10 chars) <span class="text-red-500">*</span></label>
                            <textarea name="decision_reason" required minlength="10" rows="3" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;" placeholder="Explain why this application is being rejected..."></textarea>
                            @error('decision_reason') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--ds-crimson); border-radius:6px;">Confirm Reject</button>
                            <button type="button" @click="showReject = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
                @endif

                {{-- Conflict check --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Team Leave Conflicts</h4>
                    @if($conflicts->isEmpty())
                        <p class="text-xs" style="color:var(--text-secondary, #94a3b8);">No other staff on leave during this period.</p>
                    @else
                        <ul class="space-y-1">
                            @foreach($conflicts as $c)
                                <li class="text-xs" style="color:var(--text-primary, #0f172a);">
                                    {{ $c->user->name ?? '?' }} â€” {{ $c->leaveType->label ?? '?' }} ({{ number_format($c->working_days_requested, 1) }} days)
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Transaction history --}}
                @if($appTransactions->count() > 0)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Transactions</h4>
                    <div class="space-y-2">
                        @foreach($appTransactions as $txn)
                            <div class="flex items-start gap-3 py-1.5" style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <div class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background:{{ (float)$txn->days_delta >= 0 ? '#00d4aa' : '#ef4444' }};"></div>
                                <div>
                                    <p class="text-xs" style="color:var(--text-primary, #0f172a);">{{ $txn->description }} <span class="font-semibold" style="color:{{ (float)$txn->days_delta >= 0 ? '#00d4aa' : '#ef4444' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }} days</span></p>
                                    <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">{{ $txn->createdBy->name ?? 'System' }} | {{ $txn->created_at?->format('d M Y H:i') }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
