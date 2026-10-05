{{-- AT-447 — shown on every e-sign page while the owner is in Platform E-Sign mode. --}}
@if(\App\Support\PlatformEsignMode::active())
    <div class="rounded-md px-4 py-2.5 mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm"
         style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
        <span><strong>Platform E-Sign</strong> — CoreX's own contracts. Nothing here belongs to an agency.</span>
        <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold">
            <a href="{{ route('docuperfect.platform.hub') }}" style="color: var(--brand-icon);">Home</a>
            <a href="{{ route('docuperfect.esign.create') }}" style="color: var(--brand-icon);">Send a contract</a>
            <a href="{{ route('docuperfect.templates.index') }}" style="color: var(--brand-icon);">Templates</a>
            <a href="{{ route('docuperfect.import.index') }}" style="color: var(--brand-icon);">Import a document</a>
            <a href="{{ route('docuperfect.esign.myDocuments') }}" style="color: var(--brand-icon);">Sent contracts</a>
            <form method="POST" action="{{ route('admin.platform-esign.exit') }}" class="inline">@csrf<button type="submit" class="underline" style="color: var(--text-muted);">Exit</button></form>
        </span>
    </div>
@endif
