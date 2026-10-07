{{--
    .ai/specs/rental-inspections.md §46 — "Sign by link": per-party status of the personal signing links, and the ways to
    get each one to its party (email, WhatsApp, copy, a full-screen QR code, or sign on this device). The behaviour is
    resources/js/rental-inspection-signing.js (window.inspectionSigningLinks). One partial for BOTH the inspection page
    and the phone recording screen.

    In:
      $inspectionIdJs  a JS expression for the inspection id (a number on the inspection page,
                       "currentInspection(section).id" on the recording screen)
      $reloadOnChange  true on the inspection page (reload when someone signs); false on the recording screen, where the
                       host screen listens for the 'signing-links-changed' event instead
--}}
@php
    $reloadOnChange = $reloadOnChange ?? false;
@endphp
<div x-data="inspectionSigningLinks({ inspectionId: {{ $inspectionIdJs }}, base: @js(url('/corex/rental-inspections')), reloadOnChange: @js($reloadOnChange) })"
     x-init="init()" x-show="!loading && panel && panel.enabled" x-cloak
     class="space-y-2 pt-2" data-qa="signing-links-panel" style="border-top:1px solid var(--border);">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <label class="block text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Sign by link</label>
        <button type="button" @click="sendAll()" :disabled="busy !== ''"
                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--brand-button,#0ea5e9); color:#fff;" data-qa="signing-links-send-all">
            Email everyone their link
        </button>
    </div>
    <p class="text-xs" style="color:var(--text-muted);" x-show="panel && !panel.ready_to_sign">
        Each person can already read the report from their link. Signing opens when the inspection is marked ready to sign.
    </p>
    <p class="text-xs" style="color:#dc2626;" x-show="error" x-cloak x-text="error" data-qa="signing-links-error"></p>
    <p class="text-xs" style="color:#059669;" x-show="notice" x-cloak x-text="notice"></p>

    <template x-for="row in (panel ? panel.rows : [])" :key="row.key">
        <div class="py-1.5" style="border-bottom:1px solid var(--border);" :data-qa="'signing-row-' + row.key">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <span class="text-sm" style="color:var(--text-primary);">
                    <span x-text="row.name"></span>
                    <span class="text-xs" style="color:var(--text-muted);" x-text="'(' + row.role_label + ')'"></span>
                </span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-md" :style="chipStyle(row)" x-text="chipText(row)" data-qa="signing-row-status"></span>
            </div>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);" x-show="detail(row)" x-text="detail(row)"></p>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);" x-show="row.blocked_reason && !row.recorded" x-text="row.blocked_reason"></p>

            <div class="flex items-center gap-2 flex-wrap mt-1.5" x-show="row.can_issue">
                <button type="button" @click="email(row)" :disabled="busy !== '' || !row.email" :title="row.email ? ('Email to ' + row.email) : 'No email address on file'"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                        x-text="row.link && row.link.last_sent_channel === 'email' && row.link.live ? 'Resend email' : 'Email'"></button>
                <template x-if="!row.is_agent">
                    <button type="button" @click="whatsapp(row)" :disabled="busy !== ''"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">WhatsApp</button>
                </template>
                <button type="button" @click="copy(row)" :disabled="busy !== ''"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Copy link</button>
                <template x-if="!row.is_agent">
                    <button type="button" @click="showQr(row)" :disabled="busy !== ''"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);" data-qa="signing-row-qr">Show QR</button>
                </template>
                <template x-if="!row.is_agent">
                    <button type="button" @click="signHere(row)" :disabled="busy !== '' || !panel.ready_to_sign" :title="panel.ready_to_sign ? '' : 'Mark the inspection ready to sign first'"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);" data-qa="signing-row-device">Sign on this device</button>
                </template>
                <button type="button" x-show="row.link && row.link.live && !row.link.outcome_at" @click="revoke(row)" :disabled="busy !== ''"
                        class="text-xs font-medium underline" style="color:var(--text-secondary);">Revoke</button>
            </div>
        </div>
    </template>

    {{-- Full-screen QR: the agent holds their phone up, the tenant or owner scans it and the report opens on their own phone. --}}
    <div x-show="qr.open" x-cloak @keydown.escape.window="qr.open = false"
         class="fixed inset-0 flex flex-col items-center justify-center p-6 text-center" style="z-index:10000; background:#fff;" data-qa="signing-qr-overlay">
        <p class="text-lg font-semibold mb-1" style="color:#111827;" x-text="qr.name"></p>
        <p class="text-sm mb-4" style="color:#6b7280;">Scan this with your phone camera to read the report and sign.</p>
        <img :src="qr.dataUri" alt="QR code to open the inspection report" style="width:min(80vw, 70vh); height:auto; image-rendering:pixelated;">
        <p class="text-xs mt-4 break-all" style="color:#9ca3af; max-width:90vw;" x-text="qr.url"></p>
        <button type="button" @click="qr.open = false" class="mt-6 px-6 py-3 rounded-md text-base font-semibold" style="background:#111827; color:#fff;">Close</button>
    </div>
</div>
