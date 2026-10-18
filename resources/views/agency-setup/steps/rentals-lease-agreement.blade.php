{{-- Rentals step — the agency's OWN lease agreement (leases.md §15.14, Build L0). An information row with a
     link: it posts no field and has no saver. Vars: $wzLeaseAgreementLinked. --}}
<div class="p-4 space-y-2" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); border-radius:6px;">
    <div class="flex items-center justify-between gap-3">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Your lease agreement</h4>
        @if (!empty($wzLeaseAgreementLinked))
            <span class="ds-badge ds-badge-success">Set up</span>
        @else
            <span class="ds-badge ds-badge-warning">Not set up yet</span>
        @endif
    </div>
    <p class="text-xs" style="color:var(--text-muted);">
        What it is: The lease agreement CoreX opens when you prepare a lease for signing.
    </p>
    <p class="text-xs" style="color:var(--text-muted);">
        What this changes: whether the "Create lease &amp; prepare for signing" button is available, and which of your own documents it produces. Until you set one up the button is greyed out.
    </p>
    @permission('rental_lease_templates.manage_settings')
        <a href="{{ route('corex.rental-lease-templates.index') }}" class="text-xs font-semibold" style="color:var(--accent,#2563eb);">Settings → Rental lease agreements</a>
    @else
        <p class="text-xs" style="color:var(--text-muted);">Ask your agency administrator to set this up under Settings → Rental lease agreements.</p>
    @endpermission
</div>
