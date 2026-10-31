{{-- AT-509 — shared banner + tabs for the Dev Settings compliance-switch pages. Needs $active ('agencies'|'history'). --}}
<div class="rounded-md px-6 py-5 corex-page-banner">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Agency compliance switches</h1>
            <p class="text-xs" style="color: var(--text-muted);">Which property compliance checks each agency has on or off, and the permanent record of every change.</p>
        </div>
        <a href="{{ route('admin.dev-settings.index') }}" class="corex-btn-outline text-xs">Back to Dev Settings</a>
    </div>
</div>

<div class="flex gap-2">
    <a href="{{ route('admin.dev-settings.compliance-switches') }}" class="{{ $active === 'agencies' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">Agencies</a>
    <a href="{{ route('admin.dev-settings.compliance-switches.history') }}" class="{{ $active === 'history' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">Change history</a>
</div>
