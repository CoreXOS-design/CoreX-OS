{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — flat neutral) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal → Property → Portal status sync</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Let deals drive a linked property's listing status automatically. These rules use your existing
                    statuses and flow to the portals through the normal syndication — nothing is invented. All are
                    agency-configurable and conservative (off) by default.
                </p>
            </div>
        </div>
    </div>

    {{-- Alerts — §3.9 --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.deal-property-sync.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        {{-- (a) under-offer on deal create --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="flag_property_under_offer_on_deal" value="1" class="mt-1"
                       {{ $settings->flag_property_under_offer_on_deal ? 'checked' : '' }}>
                <span>
                    <span class="text-sm font-semibold" style="color: var(--text-primary);">Flag the property <em>Under Offer</em> when a deal is created</span>
                    <span class="block text-xs mt-1" style="color: var(--text-muted);">
                        When an agent captures a deal on a linked property, the property is set to
                        <strong>Under Offer</strong> and pushed to the portals. Only on-market listings are
                        touched; the prior status is remembered so it can be restored. <em>Default: off.</em>
                    </span>
                </span>
            </label>
        </div>

        {{-- (b) which milestone = sold --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Which milestone marks the property <em>Sold</em> on portals</div>
            <p class="text-xs mb-3" style="color: var(--text-muted);">When the deal reaches this stage, the linked property is set to <strong>Sold</strong>. <em>Default: off.</em></p>
            @php $sm = old('sold_milestone', $settings->sold_milestone); @endphp
            <div class="flex flex-col gap-2">
                <label class="flex items-center gap-2 p-3 rounded-md cursor-pointer" style="background: var(--surface-2); border: 1px solid var(--border);"><input type="radio" name="sold_milestone" value="" {{ $sm ? '' : 'checked' }}> <span class="text-sm" style="color: var(--text-secondary);">Off — never auto-mark sold</span></label>
                <label class="flex items-center gap-2 p-3 rounded-md cursor-pointer" style="background: var(--surface-2); border: 1px solid var(--border);"><input type="radio" name="sold_milestone" value="granted" {{ $sm === 'granted' ? 'checked' : '' }}> <span class="text-sm" style="color: var(--text-secondary);">Commission <strong style="color: var(--text-primary);">Granted</strong></span></label>
                <label class="flex items-center gap-2 p-3 rounded-md cursor-pointer" style="background: var(--surface-2); border: 1px solid var(--border);"><input type="radio" name="sold_milestone" value="registered" {{ $sm === 'registered' ? 'checked' : '' }}> <span class="text-sm" style="color: var(--text-secondary);"><strong style="color: var(--text-primary);">Registered</strong></span></label>
            </div>
        </div>

        {{-- (c) revert on decline/lapse --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="revert_property_on_deal_declined" value="1" class="mt-1"
                       {{ $settings->revert_property_on_deal_declined ? 'checked' : '' }}>
                <span>
                    <span class="text-sm font-semibold" style="color: var(--text-primary);">Revert the property when a deal is declined or lapses</span>
                    <span class="block text-xs mt-1" style="color: var(--text-muted);">
                        If a deal falls through, an under-offer property automatically returns to the on-market
                        status it held before. <em>Default: on (the safety companion).</em>
                    </span>
                </span>
            </label>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary">Save settings</button>
        </div>
    </form>
</div>
@endsection
