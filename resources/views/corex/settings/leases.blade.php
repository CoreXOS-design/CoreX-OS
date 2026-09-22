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

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
