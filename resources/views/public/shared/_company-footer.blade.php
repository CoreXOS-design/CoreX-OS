{{--
    SHARED PUBLIC-PAGE COMPONENT — Company footer  (AT-204)
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens via var(--token,#fallback))

    CONTRACT (proposed by cc2 / buyer-portal; cc1 / seller page to converge — see
    .ai/tickets/AT-204-buyer-portal-redesign.md).

    Expects:
      $agency  App\Models\Agency|null — the company (nullable → generic fallback)

    Relies on host :root tokens: --brand-default (footer bg). All null-safe.
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
    <div style="max-width:640px; margin:0 auto; padding:1.75rem 1.25rem; text-align:center;">
        <div style="font-size:.9375rem; font-weight:700; color:#fff;">{{ $coName }}</div>

        @if($coAddr)
            <div style="font-size:.75rem; margin-top:.375rem; color:rgba(255,255,255,.6);">{{ $coAddr }}</div>
        @endif

        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:.25rem .875rem; margin-top:.5rem; font-size:.75rem;">
            @if($coPhone)
                <a href="tel:{{ $coPhone }}" style="color:rgba(255,255,255,.75);">{{ $coPhone }}</a>
            @endif
            @if($coEmail)
                <a href="mailto:{{ $coEmail }}" style="color:rgba(255,255,255,.75);">{{ $coEmail }}</a>
            @endif
        </div>

        @if($coPpra)
            <div style="font-size:.6875rem; margin-top:.5rem; color:rgba(255,255,255,.5);">PPRA registered — {{ $coPpra }}</div>
        @endif

        <div style="font-size:.625rem; margin-top:1rem; color:rgba(255,255,255,.4);">Powered by CoreX OS</div>
    </div>
</footer>
