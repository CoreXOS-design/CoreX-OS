@extends('layouts.corex')

{{--
    .ai/specs/leases.md §5.2 / conductor ruling 2026-09-15 — Johan's
    standing rule: any threshold is agency-configurable with a sensible
    default, never hardcoded, and the control ships with the feature.
--}}

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Lease Settings</h1>
            <p class="text-sm text-white/60">How CoreX warns your agents before a lease expires.</p>
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

    <form method="POST" action="{{ route('corex.settings.leases.update') }}" class="space-y-4">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Lease expiry warning</h3>
            </div>
            <div class="p-5 space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Warn me this many days before a lease expires</label>
                    <input type="number" name="expiry_notice_window_days" value="{{ old('expiry_notice_window_days', $expiryNoticeWindowDays) }}"
                           min="1" max="365" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultDays }} days. Change it to match your agency's own notice
                        practice at any time — this does not need to wait on anything.
                    </p>
                </div>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Lease type field</h3>
            </div>
            <div class="p-5">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="show_lease_type_field" value="0">
                    <input type="checkbox" name="show_lease_type_field" value="1" @checked(old('show_lease_type_field', $showLeaseTypeField))>
                    Show the Lease Type control on the lease screen and the property Rental tab
                </label>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    Off by default. The Lease Type list, its values, and the Property24
                    commercial lease-type mapping stay in place either way — this only
                    decides whether the control appears on screen for your agency.
                </p>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Deposit default</h3>
            </div>
            <div class="p-5 space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Default deposit, as a multiple of monthly rent</label>
                    <input type="number" name="default_deposit_months" value="{{ old('default_deposit_months', $defaultDepositMonths) }}"
                           step="0.1" min="0.1" max="12" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ number_format((float) $defaultDepositMonthsDefault, 1) }} month(s) of rent. Used to fill in a property's
                        deposit amount when an agent ticks "Has deposit" but leaves the amount blank —
                        always shown as a starting figure the agent can change, never locked in.
                    </p>
                </div>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Notice and early cancellation (defaults for new leases)</h3>
            </div>
            <div class="p-5 space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notice a tenant is expected to give</label>
                    <div class="flex gap-2">
                        <input type="number" name="tenant_notice_period_days" value="{{ old('tenant_notice_period_days', $tenantNoticePeriodDays) }}"
                               min="1" max="365" required
                               class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <select name="tenant_notice_period_unit" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                            @foreach(['days', 'weeks', 'months'] as $u)
                                <option value="{{ $u }}" @selected(old('tenant_notice_period_unit', $tenantNoticePeriodUnit) === $u)>{{ $u }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $tenantNoticePeriodDaysDefault }} days. Every new lease starts with this notice period and you can change it
                        on each lease; old leases are filled from it by the back-fill. Not a legal minimum CoreX enforces.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notice may not be given before (months into the lease)</label>
                    <input type="number" name="default_earliest_notice_months" value="{{ old('default_earliest_notice_months', $earliestNoticeMonths) }}"
                           min="0" max="60" placeholder="No rule"
                           class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">Blank = notice may be given from the start. Sets each new lease's "earliest date notice may be given" to its start date plus this many months.</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Early cancellation of a fixed term is</label>
                    <select name="default_early_cancellation_allowed" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <option value="yes" @selected(old('default_early_cancellation_allowed', $earlyCancellationAllowed) === 'yes')>Allowed</option>
                        <option value="no" @selected(old('default_early_cancellation_allowed', $earlyCancellationAllowed) === 'no')>Not allowed</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notice needed to cancel early</label>
                    <div class="flex gap-2">
                        <input type="number" name="default_early_cancellation_notice" value="{{ old('default_early_cancellation_notice', $earlyCancellationNotice) }}"
                               min="1" max="999" placeholder="Same as above"
                               class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <select name="default_early_cancellation_notice_unit" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                            @foreach(['days', 'weeks', 'months'] as $u)
                                <option value="{{ $u }}" @selected(old('default_early_cancellation_notice_unit', $earlyCancellationNoticeUnit ?? $tenantNoticePeriodUnit) === $u)>{{ $u }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Early-cancellation penalty (wording)</label>
                    <textarea name="default_early_cancellation_penalty" rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('default_early_cancellation_penalty', $earlyCancellationPenalty) }}</textarea>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">Blank = no penalty wording. These are only starting values: each lease keeps its own, shown to the tenant and owner in the portal FAQ.</p>
                </div>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Automatic month-to-month</h3>
            </div>
            <div class="p-5 space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days after a lease's end date before it goes month-to-month</label>
                    <input type="number" name="month_to_month_after_end_days" value="{{ old('month_to_month_after_end_days', $monthToMonthAfterEndDays) }}"
                           min="0" max="365" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $monthToMonthAfterEndDaysDefault }} day (the day after the end date). When a lease has reached its end date and there is
                        no notice to vacate and no renewal on record, CoreX switches it to month-to-month on its own, adds a line to the
                        tenancy log and tells the agent. A notice or a renewal (including one out for signing) always stops it.
                    </p>
                </div>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Active rental stock (Command Centre)</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    The Rentals Command Centre's "Unoccupied" tile counts a vacant
                    property only if its status is one of these — tick every status
                    that still counts as live, available rental stock for your agency.
                    Anything else with no active lease (withdrawn, expired, sold, let
                    out elsewhere, etc.) shows under "Inactive / off market" instead.
                </p>
                <input type="hidden" name="active_rental_statuses_present" value="1">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach($allowedPropertyStatuses as $statusSlug)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="active_rental_statuses[]" value="{{ $statusSlug }}"
                                   @checked(in_array($statusSlug, old('active_rental_statuses', $activeRentalStatuses), true))>
                            {{ ucwords(str_replace('_', ' ', $statusSlug)) }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    Default: {{ implode(', ', array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), $activeRentalStatusesDefault)) }}
                    — the same "on market" definition CoreX already uses for your Properties list.
                </p>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
