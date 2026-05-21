{{-- MIC Phase D1 — Market Pulse tab. Folds /admin/p24 content in Phase D6. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1640px; margin: 0 auto; padding: 0 20px;">
    @include('corex.market-intelligence.partials.tabs')

    <section style="padding: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; margin-bottom: 16px;">
        <h1 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin: 0 0 6px 0;">Market Pulse</h1>
        <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
            Agency-wide market signal: listings flowing in, recent CMA imports, portal velocity.
            Phase D6 folds the admin P24 import surface into this tab.
        </p>
    </section>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px;">
        <div style="padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.6875rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Tracked properties</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">{{ number_format($pulse['tracked_total'] ?? 0) }}</div>
        </div>
        <div style="padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.6875rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">New this week</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: var(--ds-green, #10b981);">{{ number_format($pulse['new_this_week'] ?? 0) }}</div>
        </div>
        <div style="padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.6875rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Active P24 listings</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">{{ number_format($pulse['p24_active'] ?? 0) }}</div>
        </div>
        <div style="padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.6875rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Active PP listings</div>
            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">{{ number_format($pulse['pp_active'] ?? 0) }}</div>
        </div>
    </div>

    @permission('manage_p24')
        @if(\Illuminate\Support\Facades\Route::has('admin.p24.listings'))
            <section style="padding: 16px 20px; background: color-mix(in srgb, var(--brand-button) 6%, var(--surface));
                            border: 1px solid var(--brand-button); border-radius: 6px;">
                <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0 0 6px 0;">P24 import controls</h2>
                <p style="font-size: 0.75rem; color: var(--text-secondary); margin: 0 0 10px 0;">
                    The admin P24 browse + import controls are mounted at /admin/p24/listings. Phase D6 will inline these here.
                </p>
                <a href="{{ route('admin.p24.listings') }}"
                   style="display: inline-block; padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                          background: var(--brand-button); color: #fff; text-decoration: none; border-radius: 4px;">
                    Open admin P24 listings →
                </a>
            </section>
        @endif
    @endpermission
</div>
@endsection
