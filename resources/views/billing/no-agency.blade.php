{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    The billing page reached with NO agency context — a CoreX System Owner who
    has not switched into an agency (STANDARDS Rule 17: owners carry a NULL
    agency_id).

    There is no bill to show, because "which agency's bill?" has no answer. Say
    that plainly and hand them the two ways forward, rather than 500ing on a null
    agency or showing a misleading R 0.00.
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Billing</h1>
        <p class="text-sm text-white/60">What an agency pays for CoreX.</p>
    </div>

    <div class="rounded-md p-6" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-base font-semibold mb-1" style="color:var(--text-primary);">No agency selected</div>
        <p class="text-sm mb-4" style="color:var(--text-secondary);">
            You are signed in as a CoreX System Owner, which is not attached to any single agency —
            so there is no single bill to show you here.
        </p>

        <div class="flex flex-wrap gap-3">
            @if(auth()->user()?->isOwnerRole())
                <a href="{{ route('admin.billing.index') }}" class="corex-btn-primary text-sm">
                    Open Agency Billing (all agencies)
                </a>
            @endif
            <span class="text-sm self-center" style="color:var(--text-muted);">
                Or switch into an agency to see that agency's bill.
            </span>
        </div>
    </div>

</div>
@endsection
