{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-385 / AT-332 — "Send via WhatsApp" resend button. ONE canonical
    partial, included everywhere the button appears (recipient-1 confirmation
    screens + My E-Sign Documents), matching the pattern
    _recipient-resend.blade.php already established for the email resend.

    Johan's decision: email stays the automatic primary; this is a MANUAL,
    agent-clicked convenience only. It opens wa.me pre-filled — the agent
    sends it themselves — then logs ONLY that the link was opened (never
    "sent"), via a fire-and-forget POST after the tab opens (AT-323 lesson:
    open WhatsApp FIRST, inside the click gesture, or popup-blockers eat it).

    2026-09-07 (Johan tested and found four defects — all fixed here):
    1. REAL BUTTON, not coloured text. Uses `corex-btn-outline` — the design
       system's own secondary-action button component (UI_DESIGN_SYSTEM.md:
       "Secondary/tertiary actions use corex-btn-outline or inline text
       links... Never use raw Tailwind... Always use tokens via the
       corex-btn-* classes"). The email Resend button next to this one is
       itself just coloured text (matches it would have meant repeating the
       same defect), so this deliberately does NOT match that one.
    2. HONEST ON-SCREEN CONFIRMATION. After the click registers, shows
       "Opened WhatsApp to <number> at <time> — not confirmed sent" right
       under the button. Never claims delivery — CoreX cannot confirm it.
    3. CONTACT COMMUNICATION COUNTER. Mirrors the send into the SAME
       mechanism every other WhatsApp send in CoreX already uses (Johan:
       "contact comm for wa already exists... everywhere else we use wa
       this counter works") via SigningWhatsAppLinkService::logOpened() —
       a provisional Communication row, born not_delivered, plus the SAME
       shared "Did you send it?" modal (partials.whatsapp-send-confirm-modal)
       and the SAME endpoint (ContactController::markCommunicationSent,
       route corex.contacts.communications.mark-sent) every other WhatsApp
       surface in CoreX already uses to move the counter. No parallel
       counter, no parallel modal, no parallel endpoint. Skipped gracefully
       (no modal, no counter attempt) when this recipient has no linked
       Contact — nothing to attach a communication to.
    4. CORRECT PHONE FIELD. SigningWhatsAppLinkService now resolves the
       contact's dedicated WhatsApp-designated number
       (Contact::primaryWhatsAppPhone(), contact_phones.is_primary_whatsapp)
       when the recipient is linked to a real Contact and one is flagged,
       falling back to signer_phone (the Fill & Review-captured cell)
       exactly as before when no WhatsApp-specific number is on file.

    Required param:
      $signatureRequest — App\Models\Docuperfect\SignatureRequest
      $document         — App\Models\Docuperfect\Document (for the route binding)

    Availability (including the disabled-with-reason state) is decided
    server-side by SigningWhatsAppLinkService::resolveAvailability() —
    never re-derived in this blade.
--}}
@php
    $waState = app(\App\Services\Docuperfect\SigningWhatsAppLinkService::class)
        ->resolveAvailability($signatureRequest, (int) ($document->agency_id ?? auth()->user()?->effectiveAgencyId() ?? 0));
@endphp
@if($waState['available'])
    <div x-data="{
            openedAt: null,
            openedNumber: null,
            sentConfirm: { open: false, communicationId: null, contactId: null },
            async sendWhatsApp() {
                window.open(@js($waState['link']), '_blank', 'noopener');
                try {
                    const res = await fetch(@js(route('docuperfect.signatures.whatsappOpened', ['document' => $document->id, 'signatureRequest' => $signatureRequest->id])), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ normalized_phone: @js($waState['normalizedPhone']) })
                    });
                    const data = await res.json();
                    if (data && data.ok) {
                        this.openedAt = data.opened_at;
                        this.openedNumber = data.normalized_phone;
                        if (data.communication_id && data.contact_id) {
                            this.sentConfirm = { open: true, communicationId: data.communication_id, contactId: data.contact_id };
                        }
                    }
                } catch (e) {}
            },
            // AT-323 — SAME confirm contract as the contact quick-send (see
            // corex/contacts/show.blade.php's confirmSent()). "No" leaves the
            // row not_delivered (nothing to do); "Yes" is the ONLY path this
            // reaches sent and the contact's WhatsApp counter moves.
            async confirmSent(didSend) {
                const commId = this.sentConfirm.communicationId;
                const contactId = this.sentConfirm.contactId;
                this.sentConfirm.open = false;
                if (!commId || !contactId || !didSend) return;
                try {
                    await fetch('{{ url('corex/contacts') }}/' + contactId + '/communications/' + commId + '/mark-sent', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' },
                        body: '{}'
                    });
                } catch (e) {}
            }
         }" class="inline-flex flex-col items-start gap-1">
        @include('partials.whatsapp-send-confirm-modal')
        <button type="button" @click="sendWhatsApp()"
                class="corex-btn-outline"
                title="Opens WhatsApp with the signing link pre-filled — you send it yourself. CoreX cannot confirm delivery.">
            <svg class="w-4 h-4" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            WhatsApp &#8594; {{ \Illuminate\Support\Str::limit($signatureRequest->signer_name, 14) }}
        </button>
        <span x-show="openedAt" x-cloak class="text-[10px]" style="color: var(--text-muted);">
            Opened WhatsApp to <span x-text="openedNumber"></span> at <span x-text="openedAt"></span> &mdash; not confirmed sent
        </span>
    </div>
@elseif($waState['reason'])
    <span class="text-xs" style="color: var(--text-muted);" title="{{ $waState['reason'] }}">
        WhatsApp unavailable &mdash; {{ $waState['reason'] }}
    </span>
@endif
