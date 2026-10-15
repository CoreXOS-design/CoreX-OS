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
    <style>.sg-btn{display:inline-flex;align-items:center;justify-content:center;height:2rem;padding:0 .75rem;font-size:.75rem;font-weight:600;border-radius:.375rem;white-space:nowrap}</style>
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <label class="block text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Sign by link</label>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" x-show="canMarkReady" x-cloak @click="markReady()" :disabled="busy !== ''" data-qa="signing-links-ready"
                    class="sg-btn" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">Ready to sign</button>
            <button type="button" @click="sendAll()" :disabled="busy !== ''"
                    class="sg-btn" style="background:var(--brand-button,#0ea5e9); color:#fff;" data-qa="signing-links-send-all">
                Email everyone their link
            </button>
        </div>
    </div>
    <p class="text-xs font-semibold" style="color:#b45309;" x-show="readinessLine" x-cloak x-text="readinessLine" data-qa="signing-readiness"></p>
    <p class="text-xs" style="color:#dc2626;" x-show="error && error !== readinessLine" x-cloak x-text="error" data-qa="signing-links-error"></p>
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

            {{-- AT-463 / §61 - "Sign now": draw the signature on this screen (the same pad as the party's signing page). The agent's
                 own row has it as the primary action (email / copy link stay as the fallbacks); a party who is with the agent gets
                 "Sign now on this device" beside Send link / QR. --}}
            <p class="text-xs mt-1" style="color:var(--text-muted);" x-show="row.is_agent && !row.recorded && agentBlock" x-text="agentBlock" data-qa="agent-sign-blocked"></p>
            <div class="flex items-center gap-2 flex-wrap mt-1.5" x-show="(row.is_agent && !row.recorded) || (!row.is_agent && !row.recorded && row.can_issue)">
                <template x-if="row.is_agent">
                    <button type="button" @click="openSignNow(row)" :disabled="busy !== '' || !!agentBlock"
                            class="sg-btn" :style="agentBlock ? 'background:var(--surface-2); color:var(--text-muted);' : 'background:var(--brand-button,#0ea5e9); color:#fff;'" data-qa="signing-row-sign-now">Sign now</button>
                </template>
                <template x-if="!row.is_agent">
                    <button type="button" @click="openSignNow(row)" :disabled="busy !== ''"
                            class="sg-btn" style="background:var(--brand-button,#0ea5e9); color:#fff;" data-qa="signing-row-sign-now">Sign now on this device</button>
                </template>
            </div>

            <div class="flex items-center gap-2 flex-wrap mt-1.5" x-show="row.can_issue">
                <button type="button" @click="email(row)" :disabled="busy !== '' || !row.email" :title="row.email ? ('Email to ' + row.email) : 'No email address on file'"
                        class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);"
                        x-text="row.link && row.link.last_sent_channel === 'email' && row.link.live ? 'Resend email' : 'Email'"></button>
                <template x-if="!row.is_agent">
                    <button type="button" @click="whatsapp(row)" :disabled="busy !== ''"
                            class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);">WhatsApp</button>
                </template>
                <button type="button" @click="copy(row)" :disabled="busy !== ''"
                        class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);">Copy link</button>
                <template x-if="!row.is_agent">
                    <button type="button" @click="showQr(row)" :disabled="busy !== ''"
                            class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);" data-qa="signing-row-qr">Show QR</button>
                </template>
                <template x-if="!row.is_agent">
                    <button type="button" @click="signHere(row)" :disabled="busy !== ''" title="Opens the whole report first, then the signing step"
                            class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);" data-qa="signing-row-device">Read report first, then sign</button>
                </template>
                <button type="button" x-show="row.link && row.link.live && !row.link.outcome_at" @click="revoke(row)" :disabled="busy !== ''"
                        class="text-xs font-medium underline" style="color:var(--text-secondary);">Revoke</button>
            </div>
        </div>
    </template>

    {{-- "Sign now" overlay - ONE pad (resources/js/corex-signature-pad-mixin.js), for the agent signing themselves and for a
         party signing on the agent's device in the agent's presence. Records through the existing signature storage. --}}
    <div x-show="signNow.open" x-cloak @keydown.escape.window="closeSignNow()"
         class="fixed inset-0 flex items-end sm:items-center justify-center p-3" style="z-index:10000; background:rgba(15,23,42,.55);" data-qa="sign-now-overlay">
        <div class="w-full space-y-3 rounded-xl p-4" style="max-width:34rem; background:var(--surface); border:1px solid var(--border); box-shadow:0 20px 50px rgba(0,0,0,.25);">
            <p class="text-sm font-semibold" style="color:var(--text-primary);" x-text="signNow.title" data-qa="sign-now-title"></p>
            <p class="text-xs" style="color:var(--text-secondary);" x-text="signNow.sub" data-qa="sign-now-sub"></p>
            <div x-show="signNow.kind === 'party'">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sign-now-name">Full name of the person signing</label>
                <input id="sign-now-name" type="text" x-model="signNow.typedName" maxlength="191" autocomplete="off" class="prop-input w-full">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Signature - draw with a finger or pen</label>
                <canvas x-ref="pad" @pointerdown="signNow.error = ''" class="w-full block rounded-lg" style="height:180px; touch-action:none; background:#fff; border:1px solid var(--border);" data-qa="sign-now-pad"></canvas>
                <button type="button" @click="clearPad()" class="mt-1 text-xs underline" style="color:var(--text-muted);">Clear</button>
            </div>
            <label class="flex items-start gap-2 text-sm" style="color:var(--text-secondary);" x-show="signNow.kind === 'party'">
                <input type="checkbox" x-model="signNow.readConfirmed" class="mt-1" data-qa="sign-now-read">
                <span>I have read this inspection report.</span>
            </label>
            <p class="text-xs" style="color:#dc2626;" x-show="signNow.error" x-cloak x-text="signNow.error" data-qa="sign-now-error"></p>
            <div class="flex items-center justify-end gap-2">
                <button type="button" @click="closeSignNow()" class="sg-btn" style="background:var(--surface-2); color:var(--text-secondary);">Cancel</button>
                <button type="button" @click="submitSignNow()" :disabled="signNow.busy || (signNow.kind === 'party' && !signNow.readConfirmed)"
                        class="sg-btn" style="background:var(--brand-button,#0ea5e9); color:#fff;" data-qa="sign-now-save" x-text="signNow.busy ? 'Saving…' : 'Save signature'"></button>
            </div>
        </div>
    </div>

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
