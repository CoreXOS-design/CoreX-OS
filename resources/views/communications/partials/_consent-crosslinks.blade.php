{{-- AT-274 — cross-links between the three WhatsApp consent / ingestion surfaces so
     each is reachable from the others (Johan, 15 Jul: "all three cross-linked, all
     three findable without archaeology"). Each link is gated on the SAME permission
     as its own route, so a viewer never sees a link they'd 403 on. Pass $current
     ('my' | 'triage' | 'review') to omit the page you're already on. --}}
@php $cur = $current ?? ''; @endphp
<div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
    <span class="font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Related:</span>
    @permission('access_communication')
        @if($cur !== 'my')
        <a href="{{ route('communications.capture.my') }}" class="hover:underline" style="color: var(--brand-icon, #0ea5e9);">My WhatsApp Consent</a>
        @endif
    @endpermission
    @permission('triage_communications')
        @if($cur !== 'triage')
        <a href="{{ route('communications.triage.index') }}" class="hover:underline" style="color: var(--brand-icon, #0ea5e9);">Review Incoming Messages</a>
        @endif
    @endpermission
    @permission('communications.capture_review')
        @if($cur !== 'review')
        <a href="{{ route('communications.capture.review') }}" class="hover:underline" style="color: var(--brand-icon, #0ea5e9);">WhatsApp Consent — Review</a>
        @endif
    @endpermission
</div>
