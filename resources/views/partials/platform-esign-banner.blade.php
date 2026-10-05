{{-- AT-447 — shown on every e-sign page while the owner is in Platform E-Sign mode. --}}
@if(\App\Support\PlatformEsignMode::active())
    <div class="rounded-md px-4 py-2.5 mb-4 flex flex-wrap items-center justify-between gap-2 text-sm"
         style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
        <span><strong>Platform E-Sign</strong> — you are working on CoreX's own contracts. Nothing here belongs to an agency.</span>
        <form method="POST" action="{{ route('admin.platform-esign.exit') }}">@csrf<button type="submit" class="text-xs font-semibold underline" style="color: var(--brand-icon);">Exit Platform E-Sign</button></form>
    </div>
@endif
