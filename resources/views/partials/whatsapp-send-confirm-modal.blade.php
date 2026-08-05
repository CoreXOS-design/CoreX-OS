{{--
    AT-323 — SHARED "did you actually send it?" confirmation modal.

    ONE modal, reused on every WhatsApp send surface (contact quick-send, outreach
    pitch-send, and the Map/MIC pitches that funnel through the outreach sent page).
    No parallel copies — every surface @includes THIS file.

    Contract (the host Alpine component must provide):
      • state   sentConfirm  : { open: bool, ... }   — `open` drives x-show
      • method  confirmSent(didSend: bool)           — true = leave as sent,
                                                        false = record NOT sent
    WhatsApp is client-side (CoreX opens it but gets no delivery signal), so this is
    the only truthful signal. It is NOT dismissable-as-sent (no escape / click-outside)
    — the agent answers, so a closed-without-sending message is never a false "sent".

    NOTE: this markup lives in the page BODY, not inside an x-data="" attribute, so
    literal double-quotes here are safe (unlike inside the x-data JS).
--}}
<div x-show="sentConfirm.open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center px-4" style="background:rgba(0,0,0,0.45);">
    <div class="w-full max-w-sm rounded-lg p-5" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
        <div class="text-base font-semibold mb-1" style="color:var(--text-primary,#111827);">Did you send the WhatsApp?</div>
        <p class="text-xs mb-4" style="color:var(--text-muted,#6b7280);">CoreX opened WhatsApp on your device, but can't confirm the message actually went. If you closed WhatsApp without sending (or it didn't go through), choose "No, I didn't send it" so this is not recorded as sent.</p>
        <div class="flex gap-2">
            <button type="button" @click="confirmSent(true)"
                    class="flex-1 text-sm font-semibold px-3 py-2 rounded" style="background:var(--brand-default,#0b2a4a); color:#fff;">Yes, I sent it</button>
            <button type="button" @click="confirmSent(false)"
                    class="flex-1 text-sm font-semibold px-3 py-2 rounded" style="background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.4);">No, I didn't send it</button>
        </div>
    </div>
</div>
