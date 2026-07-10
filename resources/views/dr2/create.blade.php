{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-215 (DR2) — DR2 capture screen PLACEHOLDER (shared shell).

    ⚠️ AT-217 (cc3) OWNS THIS FILE. Replace this placeholder with the DR1-parity capture
    form + the §2 enhancements (branch default via effectiveBranchId; REQUIRED property with
    unit/complex disambiguation; commission prefill from Property.commission_percent;
    sides/splits/agents; PAYE; non-colliding External-agency layout). The form posts to
    route('deals-dr2.store') and writes the SAME DR1 tables (deals / deal_user /
    deal_settlements). Edit mode receives $deal. Spec: .ai/specs/deal-register-v2-rebuild-spec.md §2.
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="corex-page">
    <div class="corex-page-header">
        <h1 class="corex-page-title">{{ isset($deal) && $deal ? 'Edit Deal (DR2)' : 'New Deal (DR2)' }}</h1>
    </div>

    <div class="corex-card" style="margin-top:1rem;padding:1.5rem;">
        <p style="color:var(--corex-text-muted,#6b7280);">
            DR2 capture is under construction (<strong>AT-217</strong>). This is the shared shell —
            the DR1-parity fields and the capture enhancements are being built here.
        </p>
        <div style="margin-top:1rem;">
            <a href="{{ route('deals-dr2.index') }}" class="corex-btn-secondary">← Back to DR2 Register</a>
        </div>
    </div>
</div>
@endsection
