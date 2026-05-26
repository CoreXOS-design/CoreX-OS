{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Application {{ $application->application_number }}" :back-route="route('my-portal.leave.index')" back-label="My Leave" :flush="true" />

    <div class="p-4 lg:p-6 max-w-3xl">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--brand-icon);">{{ session('success') }}</div>
        @endif

        {{-- Status banner --}}
        @php
            $sc = [
                'submitted' => ['var(--ds-amber, #f59e0b)', 'color-mix(in srgb, var(--ds-amber) 8%, transparent)', 'color-mix(in srgb, var(--ds-amber) 25%, transparent)'],
                'approved'  => ['var(--brand-icon)', 'color-mix(in srgb, var(--brand-icon) 8%, transparent)', 'color-mix(in srgb, var(--brand-icon) 25%, transparent)'],
                'rejected'  => ['var(--ds-crimson)', 'color-mix(in srgb, var(--ds-crimson) 8%, transparent)', 'color-mix(in srgb, var(--ds-crimson) 25%, transparent)'],
                'cancelled' => ['var(--text-muted, #94a3b8)', 'var(--surface-2)', 'var(--border)'],
                'taken'     => ['var(--ds-navy, #0b2a4a)', 'color-mix(in srgb, var(--ds-navy) 8%, transparent)', 'color-mix(in srgb, var(--ds-navy) 25%, transparent)'],
            ];
            $c = $sc[$application->status] ?? ['var(--text-muted, #94a3b8)', 'var(--surface-2)', 'var(--border)'];
        @endphp
        <div class="p-4 mb-4" style="background:{{ $c[1] }}; border:1px solid {{ $c[2] }}; border-radius:6px;">
            <span class="text-lg font-bold" style="color:{{ $c[0] }};">{{ ucfirst($application->status) }}</span>
            @if($application->decided_at)
                <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
                    {{ ucfirst($application->status) }} by {{ $application->decidedBy->name ?? '?' }} on {{ $application->decided_at->format('d M Y H:i') }}
                </p>
            @endif
            @if($application->decision_reason)
                <p class="text-xs mt-1" style="color:var(--text-primary, #0f172a);">Reason: {{ $application->decision_reason }}</p>
            @endif
        </div>

        {{-- Details --}}
        <div class="p-4 mb-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <dl class="space-y-1.5 text-xs">
                <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Leave Type</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $application->leaveType->label ?? '-' }}</dd></div>
                <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Period</dt><dd style="color:var(--text-primary, #0f172a);">{{ $application->start_date?->format('d M Y') }} â€” {{ $application->end_date?->format('d M Y') }}</dd></div>
                <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Working Days</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format($application->working_days_requested, 1) }}</dd></div>
                @if($application->is_half_day)
                    <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Half Day</dt><dd style="color:var(--text-primary, #0f172a);">{{ ucfirst($application->half_day_period) }}</dd></div>
                @endif
                @if($application->reason)
                    <div><dt style="color:var(--text-secondary, #6b7280);">Reason:</dt><dd class="mt-0.5" style="color:var(--text-primary, #0f172a);">{{ $application->reason }}</dd></div>
                @endif
                <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Submitted</dt><dd style="color:var(--text-secondary, #6b7280);">{{ $application->submitted_at?->format('d M Y H:i') }}</dd></div>
            </dl>
        </div>

        {{-- Transactions --}}
        @if($transactions->count() > 0)
        <div class="p-4 mb-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Balance Transactions</h4>
            @foreach($transactions as $txn)
                <div class="flex items-center gap-2 py-1.5" style="border-bottom:1px solid var(--border, #e5e7eb);">
                    <span class="text-xs font-semibold" style="color:{{ (float)$txn->days_delta >= 0 ? 'var(--ds-green, #059669)' : 'var(--ds-crimson, #c41e3a)' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }}</span>
                    <span class="text-xs" style="color:var(--text-primary, #0f172a);">{{ $txn->description }}</span>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Cancel (if submitted) --}}
        @if($application->isSubmitted())
        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;" x-data="{ showCancel: false }">
            <button @click="showCancel = !showCancel" class="text-xs font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;">Cancel this application</button>
            <form method="POST" action="{{ route('my-portal.leave.cancel', $application) }}" x-show="showCancel" x-cloak class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Reason (optional)</label>
                    <input type="text" name="cancellation_reason" maxlength="500" placeholder="e.g. Plans changed" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--ds-crimson); border-radius:6px;" onclick="return confirm('Cancel this leave application?')">Confirm Cancel</button>
                    <button type="button" @click="showCancel = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Keep Application</button>
                </div>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection
