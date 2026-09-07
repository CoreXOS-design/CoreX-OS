<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;

/**
 * AT-385 / AT-332 — "Send via WhatsApp" for e-sign signing links.
 *
 * Johan's decision (recorded verbatim by the coordinator): "EMAIL STAYS
 * KING. Nothing about the automatic routing changes... WhatsApp is a
 * MANUAL RE-SEND CONVENIENCE layered on top — never a routing step, never
 * something the flow waits for." This service does exactly two things and
 * nothing else: (1) confidently normalise `signer_phone` into a wa.me
 * target, refusing to guess, and (2) log the ONE true fact when an agent
 * opens the link — that they opened it, never that it was sent or
 * delivered. It never sends anything itself (nothing in CoreX does — see
 * the AT-323/investigation report: every WhatsApp path in this codebase is
 * a client-side wa.me deep link the agent opens in their own browser).
 *
 * Deliberately narrow: this normaliser is SA-specific by design, matching
 * Johan's own stated transformation rules exactly. `signature_requests.
 * signer_phone` carries no dial_code (unlike `contact_phones`), so unlike
 * the general-purpose `App\Support\WhatsAppNumberFormatter` (used for
 * contacts, which DO carry a dial_code and can be any country), this
 * normaliser is not reused there and does not touch it.
 */
class SigningWhatsAppLinkService
{
    /** Statuses where a recipient genuinely still holds the pen — a resend is meaningful. */
    private const ACTIONABLE_STATUSES = [
        SignatureRequest::STATUS_PENDING,
        SignatureRequest::STATUS_VIEWED,
        SignatureRequest::STATUS_PARTIALLY_SIGNED,
    ];

    /**
     * Confidently normalise a free-text SA phone number to wa.me digits
     * (27XXXXXXXXX), or null when NOT confident. Never guesses: an empty,
     * malformed, wrong-length, or non-mobile-looking number returns null
     * rather than producing a link to the wrong person.
     *
     * Handles: leading 0 ("082...")," +27...", "0027...", bare "27...",
     * plus spaces/dashes/brackets/dots anywhere in the input.
     */
    public function normalizePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Strip everything except digits and a leading '+' — spaces, dashes,
        // brackets, dots all fall out here regardless of position.
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // Reduce every recognised prefix form to a bare 9-digit SA subscriber
        // number (no leading 0, no country code) before re-adding '27' once,
        // consistently, rather than pattern-matching every prefix combination
        // separately.
        $subscriber = null;
        if ($hasPlus && str_starts_with($digits, '27') && strlen($digits) === 11) {
            // "+27 82 123 4567" -> digits "27821234567"
            $subscriber = substr($digits, 2);
        } elseif (str_starts_with($digits, '0027') && strlen($digits) === 13) {
            // "0027821234567"
            $subscriber = substr($digits, 4);
        } elseif (str_starts_with($digits, '27') && strlen($digits) === 11) {
            // "27821234567" (no plus, still unambiguous at this length)
            $subscriber = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            // "0821234567" — the common local form
            $subscriber = substr($digits, 1);
        } elseif (!str_starts_with($digits, '0') && !$hasPlus && strlen($digits) === 9) {
            // Bare 9-digit subscriber number, no leading 0 typed at all.
            $subscriber = $digits;
        }

        if ($subscriber === null || strlen($subscriber) !== 9) {
            return null; // unrecognised shape — refuse to guess
        }

        // Genuine SA mobile ranges only (06x/07x/08x). A landline (01x-05x)
        // is not WhatsApp-capable and offering the button would be wrong,
        // not just imprecise.
        if (!in_array($subscriber[0], ['6', '7', '8'], true)) {
            return null;
        }

        return '27' . $subscriber;
    }

    /**
     * Johan (2026-09-07): "I take it the number that the wa uses is the
     * contact wa number as set up on contacts?" It was not — this always
     * resolved straight to `signature_requests.signer_phone`, captured at
     * Fill & Review from the recipient's general 'cell' field, itself
     * sourced from `contacts.phone` (the general PRIMARY phone —
     * `ESignWizardController.php` ~line 4270 and its siblings) — never the
     * contact's dedicated WhatsApp designation.
     *
     * Fix: when this recipient is linked to a real Contact
     * (`signature_requests.contact_id`) AND that contact has an explicitly
     * flagged WhatsApp number (`contact_phones.is_primary_whatsapp` —
     * `Contact::primaryWhatsAppPhone()`), THAT number wins. Falls back to
     * `signer_phone` unchanged — same as before this fix — when there is
     * no linked contact, or the contact has no WhatsApp-specific
     * designation (the overwhelming majority today: the flag
     * (2026_08_16_000001_add_whatsapp_flags_to_contact_phones) is never
     * retroactively set on existing numbers).
     */
    private function resolvePhoneSource(SignatureRequest $request): string
    {
        if ($request->contact_id) {
            $contact = $request->contact ?? Contact::withoutGlobalScopes()->find($request->contact_id);
            $waPhone = trim((string) ($contact?->primaryWhatsAppPhone?->phone ?? ''));
            if ($waPhone !== '') {
                return $waPhone;
            }
        }

        return (string) $request->signer_phone;
    }

    /**
     * The pre-filled message. Deliberately minimal — signer name + the exact
     * same signing URL the email invitation carries — no new data pulled in
     * beyond what already lives on the request row.
     */
    public function buildMessage(SignatureRequest $request): string
    {
        $name = trim((string) $request->signer_name) ?: 'there';
        $url = route('signatures.external', $request->token);

        return "Hi {$name}, please sign your document here: {$url}";
    }

    public function buildWaLink(string $normalizedPhone, string $message): string
    {
        return 'https://wa.me/' . $normalizedPhone . '?text=' . rawurlencode($message);
    }

    /**
     * The single decision point the UI calls — never guess, never partially
     * decide in the blade. Returns everything a button needs to render
     * either an enabled link or a disabled state with an honest reason
     * (CoreX's "No Silent Locks" rule — a disabled control always says why).
     *
     * @return array{available: bool, reason: ?string, link: ?string, normalizedPhone: ?string}
     */
    public function resolveAvailability(SignatureRequest $request, int $agencyId): array
    {
        if (!EsignSettings::forAgency($agencyId)->whatsappResendEnabled()) {
            return ['available' => false, 'reason' => null, 'link' => null, 'normalizedPhone' => null];
        }

        if ($request->party_role === 'agent') {
            return ['available' => false, 'reason' => null, 'link' => null, 'normalizedPhone' => null];
        }

        if (!in_array($request->status, self::ACTIONABLE_STATUSES, true)) {
            // WAITING (invite not sent yet), COMPLETED, DECLINED, EXPIRED,
            // DEFERRED, NOT_REQUIRED — none are a live "resend the link"
            // moment. No reason shown: this is not a locked-but-recoverable
            // state, it is simply not applicable right now.
            return ['available' => false, 'reason' => null, 'link' => null, 'normalizedPhone' => null];
        }

        if ($request->isSigningBlocked()) {
            return ['available' => false, 'reason' => 'This signing link is no longer active.', 'link' => null, 'normalizedPhone' => null];
        }

        $phoneSource = $this->resolvePhoneSource($request);
        $normalized = $this->normalizePhone($phoneSource);
        if ($normalized === null) {
            $reason = trim($phoneSource) === ''
                ? 'No phone number on file for this recipient.'
                : "The phone number on file (\"{$phoneSource}\") doesn't look like a valid SA mobile number.";

            return ['available' => false, 'reason' => $reason, 'link' => null, 'normalizedPhone' => null];
        }

        $message = $this->buildMessage($request);

        return [
            'available' => true,
            'reason' => null,
            'link' => $this->buildWaLink($normalized, $message),
            'normalizedPhone' => $normalized,
        ];
    }

    /**
     * The e-sign audit fact: the agent opened the link. Never "sent", never
     * "delivered" — see SignatureAuditLog::ACTION_WHATSAPP_LINK_OPENED's own
     * docblock. Unchanged by the 2026-09-07 fixes below.
     *
     * Johan (2026-09-07): "contact comm for wa already exists. everywhere
     * else we use wa this counter works." He was right — this action never
     * called it. Copied identically from the OTHER WhatsApp sends already
     * doing this correctly (SellerOutreachSenderService::send() ~line
     * 136-160, mirrored by ContactController::incrementChannel ~line
     * 1705-1727): a provisional `Communication` row via
     * `OutboundProvisionalLogger::log()`, born `send_status=not_delivered`
     * (WhatsApp is client-side click-to-chat — CoreX cannot confirm
     * delivery, so it is never born counted), and it only becomes `sent`
     * (incrementing `Contact::outboundCommCount()`) once the agent answers
     * "Yes, I sent it" on the SAME shared confirm modal
     * (`partials.whatsapp-send-confirm-modal`) through the SAME endpoint
     * (`ContactController::markCommunicationSent`) every other WhatsApp
     * surface in CoreX already uses. No parallel counter, no parallel
     * modal, no parallel endpoint.
     *
     * Skipped gracefully (not an error) when this recipient isn't linked to
     * a real Contact — most commonly a supplier representative or a
     * manually-typed recipient with no Contact record on file. There is
     * nothing to mirror a communication onto in that case.
     *
     * @return array{auditLog: SignatureAuditLog, communicationId: ?int}
     */
    public function logOpened(SignatureRequest $request, User $actor, string $normalizedPhone): array
    {
        $auditLog = SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_WHATSAPP_LINK_OPENED,
            SignatureAuditLog::ACTOR_USER,
            $actor->name,
            $actor->email,
            $actor->id,
            $request->id,
            metadata: [
                'signer_name' => $request->signer_name,
                'phone_used' => $normalizedPhone,
                'opened_by' => $actor->name,
            ],
        );

        $communicationId = null;
        $contact = $request->contact_id
            ? ($request->contact ?? Contact::withoutGlobalScopes()->find($request->contact_id))
            : null;

        if ($contact !== null) {
            $communication = app(OutboundProvisionalLogger::class)->log(
                $contact,
                Communication::CHANNEL_WHATSAPP,
                null,
                $this->buildMessage($request),
                $actor->id,
            );

            $communication->forceFill([
                'send_status' => Communication::SEND_STATUS_NOT_DELIVERED,
            ])->save();

            $communicationId = $communication->id;
        }

        return ['auditLog' => $auditLog, 'communicationId' => $communicationId];
    }
}
