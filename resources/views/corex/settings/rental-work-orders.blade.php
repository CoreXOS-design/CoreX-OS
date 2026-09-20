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
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
