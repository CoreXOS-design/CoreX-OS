{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (branded — Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Deal → Property → Portal status sync</h1>
        <p class="text-sm text-white/60 mt-1">
            Let deals drive a linked property's listing status automatically. These rules use your existing
            statuses and flow to the portals through the normal syndication — nothing is invented. All are
            agency-configurable and conservative (off) by default.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.deal-property-sync.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        {{-- (a) under-offer on deal create --}}
        <div class="corex-card p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="flag_property_under_offer_on_deal" value="1" class="mt-1"
                       {{ $settings->flag_property_under_offer_on_deal ? 'checked' : '' }}>
                <span>
                    <span class="font-semibold" style="color: var(--text-primary);">Flag the property <em>Under Offer</em> when a deal is created</span>
                    <span class="block text-xs mt-1" style="color: var(--text-muted);">
                        When an agent captures a deal on a linked property, the property is set to
                        <strong>Under Offer</strong> and pushed to the portals. Only on-market listings are
                        touched; the prior status is remembered so it can be restored. <em>Default: off.</em>
                    </span>
                </span>
            </label>
        </div>

        {{-- (b) which milestone = sold --}}
        <div class="corex-card p-5">
            <div class="font-semibold mb-1" style="color: var(--text-primary);">Which milestone marks the property <em>Sold</em> on portals</div>
            <p class="text-xs mb-3" style="color: var(--text-muted);">When the deal reaches this stage, the linked property is set to <strong>Sold</strong>. <em>Default: off.</em></p>
            @php $sm = old('sold_milestone', $settings->sold_milestone); @endphp
            <div class="flex flex-col gap-2">
                <label class="inline-flex items-center gap-2"><input type="radio" name="sold_milestone" value="" {{ $sm ? '' : 'checked' }}> <span>Off — never auto-mark sold</span></label>
                <label class="inline-flex items-center gap-2"><input type="radio" name="sold_milestone" value="granted" {{ $sm === 'granted' ? 'checked' : '' }}> <span>Commission <strong>Granted</strong></span></label>
                <label class="inline-flex items-center gap-2"><input type="radio" name="sold_milestone" value="registered" {{ $sm === 'registered' ? 'checked' : '' }}> <span><strong>Registered</strong></span></label>
            </div>
        </div>

        {{-- (c) revert on decline/lapse --}}
        <div class="corex-card p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="revert_property_on_deal_declined" value="1" class="mt-1"
                       {{ $settings->revert_property_on_deal_declined ? 'checked' : '' }}>
                <span>
                    <span class="font-semibold" style="color: var(--text-primary);">Revert the property when a deal is declined or lapses</span>
                    <span class="block text-xs mt-1" style="color: var(--text-muted);">
                        If a deal falls through, an under-offer property automatically returns to the on-market
                        status it held before. <em>Default: on (the safety companion).</em>
                    </span>
                </span>
            </label>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary px-5 py-2.5 text-sm">Save settings</button>
        </div>
    </form>
</div>
@endsection
