{{--
    SHARED PUBLIC-PAGE COMPONENT — Company footer  (AT-204)
    Agency-branded (var(--brand-default) footer bg). Null-safe.

    Expects:
      $agency  App\Models\Agency|null — the company (nullable → generic fallback)
--}}
@php
    /** @var \App\Models\Agency|null $agency */
    $coName   = !empty($agency) ? ($agency->trading_name ?: $agency->name) : 'CoreX OS';
    $coAddr   = !empty($agency) ? ($agency->address ?? null) : null;
    $coPhone  = !empty($agency) ? ($agency->phone ?? null) : null;
    $coEmail  = !empty($agency) ? ($agency->email ?? null) : null;
    $coPpra   = !empty($agency) ? ($agency->ppra_number ?? $agency->ffc_no ?? null) : null;
@endphp

<footer style="background: var(--brand-default,#0b2a4a); color: rgba(255,255,255,.7); margin-top:2rem;">
    <div style="max-width:768px; margin:0 auto; padding:1.75rem 1.25rem; text-align:center;">
        <div style="font-size:.9375rem; font-weight:700; color:#fff;">{{ $coName }}</div>

        <div style="font-size:.75rem; margin-top:.375rem; color:rgba(255,255,255,.65);">
            Registered with the PPRA{{ $coPpra ? ' — ' . $coPpra : '' }}
        </div>

        @if($coAddr)
            <div style="font-size:.75rem; margin-top:.25rem; color:rgba(255,255,255,.55);">{{ $coAddr }}</div>
        @endif

        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:.25rem .875rem; margin-top:.5rem; font-size:.75rem;">
            @if($coPhone)
                <a href="tel:{{ $coPhone }}" style="color:rgba(255,255,255,.75);">{{ $coPhone }}</a>
            @endif
            @if($coEmail)
                <a href="mailto:{{ $coEmail }}" style="color:rgba(255,255,255,.75);">{{ $coEmail }}</a>
            @endif
        </div>

        <div style="font-size:.625rem; margin-top:1rem; color:rgba(255,255,255,.4);">Powered by CoreX OS</div>
    </div>
</footer>
