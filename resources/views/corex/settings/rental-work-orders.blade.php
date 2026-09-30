@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §3.4b/§8 — Johan's standing rule: any
    threshold is agency-configurable with a sensible default, never
    hardcoded, and the control ships with the feature.
--}}

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Work Order Settings</h1>
            <p class="text-sm text-white/60">The spend threshold below which an agent doesn't need the owner's approval first.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-work-orders.update') }}" class="space-y-4">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Spend threshold</h3>
            </div>
            <div class="p-5 space-y-5">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">No-approval spend threshold (R)</label>
                    <input type="number" name="no_approval_spend_threshold" value="{{ old('no_approval_spend_threshold', $noApprovalSpendThreshold) }}"
                           min="0" step="0.01" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is R{{ number_format($defaultNoApprovalSpendThreshold, 2) }}. Below this, an agent
                        doesn't need the owner's written approval before proceeding. A specific tenancy's
                        own threshold can be set higher or lower on the lease itself.
                    </p>
                </div>
                <div>
                    <input type="hidden" name="completion_requires_photo" value="0">
                    <label class="flex items-center gap-2 text-sm font-semibold" style="color:var(--text-primary);">
                        <input type="checkbox" name="completion_requires_photo" value="1" @checked(old('completion_requires_photo', $completionRequiresPhoto))>
                        Require a photo before a work order can be marked complete
                    </label>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        When on, a job needs photo evidence before it can be completed. Off by default, as some repairs cannot sensibly be photographed.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Overdue reminder window (days)</label>
                    <input type="number" name="overdue_reminder_days" value="{{ old('overdue_reminder_days', $overdueReminderDays) }}"
                           min="1" max="365" step="1"
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultOverdueReminderDays }} days. A work order with no status change for this long triggers an overdue reminder.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
