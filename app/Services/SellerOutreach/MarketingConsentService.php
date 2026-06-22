<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\MarketingSuppression;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\ContactDuplicateService;
use Illuminate\Support\Facades\DB;

/**
 * AT-49 — "one opt-out, suppressed everywhere."
 *
 * The single code path every marketing opt-out / opt-in converges on, so the
 * four stores can never drift apart:
 *   1. contact_consent_records — revoke / re-grant 'marketing_communications'
 *      and the channel_* consents (the canonical CPA/POPIA spine).
 *   2. contacts.messaging_opt_out_* triplet (+ source) — the outreach send gate.
 *   3. contacts.opt_out_email/sms/whatsapp/call — the per-channel booleans
 *      Contact::canSendVia() reads.
 *   4. marketing_suppressions — identifier-level (email / last-9 phone) so a
 *      re-imported contact carrying the same identifier stays blocked.
 *
 * Both the agent-marked opt-out (ContactTimeline → OptOutRecorded) and the
 * self-service link (PublicOptOutController → OptOutRecorded) reach this through
 * RecordOptOutOnContact, so there is exactly one convergence point.
 */
class MarketingConsentService
{
    public const CONSENT_MARKETING = 'marketing_communications';

    /** Channel consent types ⇄ the denormalised opt_out_* boolean columns. */
    private const CHANNEL_CONSENTS = [
        'channel_email'    => 'opt_out_email',
        'channel_sms'      => 'opt_out_sms',
        'channel_whatsapp' => 'opt_out_whatsapp',
        'channel_call'     => 'opt_out_call',
    ];

    public function __construct(
        private readonly ContactDuplicateService $duplicates,
    ) {}

    // ── Opt-out ──────────────────────────────────────────────────────────

    /**
     * Opt a known contact out of MARKETING, and — when $blockAll is true — out of
     * ALL messaging (transactional too). Idempotent: re-running keeps the original
     * opt-out record and does not duplicate suppression rows. A later $blockAll
     * call UPGRADES a marketing-only opt-out to a full stop (the flag is a latch,
     * raised here, lowered only by optInContact).
     *
     * @param bool $blockAll false = marketing-only (transactional channels stay
     *   sendable); true (default) = stop everything. The public opt-out page
     *   passes false for "Turn off marketing" and true for "Stop all messages";
     *   the agent-marked opt-out and the generic /unsubscribe page default to a
     *   full stop.
     */
    public function optOutContact(
        Contact $contact,
        string $reason,
        ?string $source = null,
        ?int $actorUserId = null,
        ?SellerOutreachSend $send = null,
        bool $blockAll = true,
        string $kind = Contact::OPT_OUT_KIND_DECLINED,
    ): void {
        DB::transaction(function () use ($contact, $reason, $source, $actorUserId, $send, $blockAll, $kind) {
            // (2) messaging opt-out triplet — set once, preserve the original.
            if ($contact->messaging_opt_out_at === null) {
                $contact->forceFill([
                    'messaging_opt_out_at'                  => now(),
                    'messaging_opt_out_reason'              => $reason,
                    'messaging_opt_out_recorded_by_user_id' => $actorUserId,
                    'messaging_opt_out_source'              => $source,
                    'messaging_opt_out_kind'                => $kind, // AT-81 sub-state
                ])->save();
            } elseif ($kind === Contact::OPT_OUT_KIND_DECLINED
                && $contact->messaging_opt_out_kind === Contact::OPT_OUT_KIND_NO_RESPONSE) {
                // AT-81 — an explicit decline always WINS over a silence-lapse:
                // a contact auto-lapsed to no_response who then taps "stop" is
                // upgraded to a permanent decline (the timestamp is preserved).
                $contact->forceFill(['messaging_opt_out_kind' => Contact::OPT_OUT_KIND_DECLINED])->save();
            }

            // AT-81 — opting out resolves any pending consent-request, so the
            // no-response timeout can never fire afterwards.
            $contact->clearOutreachPending();

            // AT-50 — the all-blocked latch. Raised by a stop-all (even when
            // marketing was already off); never lowered here (optInContact does).
            if ($blockAll && !$contact->messaging_all_blocked) {
                $contact->forceFill(['messaging_all_blocked' => true])->save();
            }

            // (1) consent spine — always revoke marketing consent. Channel
            // consents are revoked ONLY on a full stop, so a marketing-only
            // opt-out leaves the transactional channels granted.
            // revoked_by_user_id is nullable: a self-service opt-out has no user.
            $contact->revokeConsent(self::CONSENT_MARKETING, $actorUserId, $reason);
            if ($blockAll) {
                foreach (array_keys(self::CHANNEL_CONSENTS) as $consentType) {
                    $contact->revokeConsent($consentType, $actorUserId, $reason);
                }
                // (3) denormalised channel booleans — all four hard off.
                $contact->forceFill(array_fill_keys(array_values(self::CHANNEL_CONSENTS), true))->save();
            }

            // (4) identifier-level MARKETING suppression for every identifier this
            // contact has — written in BOTH modes so a re-import of the same
            // email/number stays marketing-blocked agency-wide (AT-49).
            $suppSource = $source ?: MarketingSuppression::SOURCE_AGENT;
            foreach ($this->contactIdentifiers($contact) as [$type, $value]) {
                $this->writeSuppression(
                    agencyId: (int) $contact->agency_id,
                    type: $type,
                    identifier: $value,
                    source: $suppSource,
                    reason: $reason,
                    contactId: (int) $contact->id,
                    sendId: $send?->id,
                    recordedBy: $actorUserId,
                );
            }
        });
    }

    /**
     * Opt-out by a raw identifier (the generic /unsubscribe page). Resolves the
     * identifier to a contact and fully opts it out; ALWAYS records a
     * suppression row even when nothing matches, so a future import of that
     * identifier is blocked. Idempotent.
     *
     * @return bool whether a contact was matched (for messaging only)
     */
    public function optOutByIdentifier(
        string $rawIdentifier,
        int $agencyId,
        string $reason,
        string $source,
        ?int $actorUserId = null,
    ): bool {
        $norm = $this->normalizeIdentifier($rawIdentifier);
        if ($norm === null) {
            return false; // unparseable — caller shows a validation message
        }
        [$type, $value] = $norm;

        // Resolve a contact the same way WhatsApp/email matching does.
        $contact = app(\App\Services\Communications\ContactIdentifierResolver::class)
            ->resolve($rawIdentifier, $agencyId);

        if ($contact instanceof Contact) {
            $this->optOutContact($contact, $reason, $source, $actorUserId);
            return true;
        }

        // No match — still suppress the identifier so a re-import stays blocked.
        $this->writeSuppression(
            agencyId: $agencyId,
            type: $type,
            identifier: $value,
            source: $source,
            reason: $reason,
            contactId: null,
            sendId: null,
            recordedBy: $actorUserId,
        );
        return false;
    }

    // ── Opt-in (reverse all four stores) ─────────────────────────────────

    public function optInContact(
        Contact $contact,
        ?string $reason = null,
        ?int $actorUserId = null,
        ?SellerOutreachSend $send = null,
    ): void {
        $actor = $this->resolveActorId((int) $contact->agency_id, $actorUserId ?? $send?->agent_id);

        DB::transaction(function () use ($contact, $reason, $actor) {
            // (1) re-grant marketing + channel consents.
            $contact->recordConsent(self::CONSENT_MARKETING, 'electronic', $actor);
            foreach (array_keys(self::CHANNEL_CONSENTS) as $consentType) {
                $contact->recordConsent($consentType, 'electronic', $actor);
            }

            // (3) denormalised channel booleans — all four back on.
            $contact->forceFill(array_fill_keys(array_values(self::CHANNEL_CONSENTS), false))->save();

            // (2) clear the opt-out triplet + the all-blocked latch AND stamp the
            // opt-in marker (AT-45/AT-50).
            $contact->forceFill([
                'messaging_opt_out_at'                  => null,
                'messaging_opt_out_reason'              => null,
                'messaging_opt_out_recorded_by_user_id' => null,
                'messaging_opt_out_source'              => null,
                'messaging_opt_out_kind'                => null, // AT-81 — clear sub-state
                'messaging_all_blocked'                 => false,
            ])->save();
            $contact->recordOptIn($reason, $actor);

            // AT-81 — a confirmed opt-in resolves any pending consent-request
            // (PENDING → CONFIRMED) so the no-response timeout never fires.
            $contact->clearOutreachPending();

            // (4) lift every active suppression for this contact's identifiers.
            foreach ($this->contactIdentifiers($contact) as [$type, $value]) {
                MarketingSuppression::withoutGlobalScopes()
                    ->where('agency_id', $contact->agency_id)
                    ->where('identifier', $value)
                    ->whereNull('lifted_at')
                    ->update(['lifted_at' => now(), 'lifted_by_user_id' => $actor]);
            }
        });
    }

    /** Lift a single suppression row (admin screen). */
    public function liftSuppression(MarketingSuppression $suppression, ?int $actorUserId = null): void
    {
        if ($suppression->lifted_at !== null) {
            return; // already lifted — idempotent
        }
        $suppression->forceFill(['lifted_at' => now(), 'lifted_by_user_id' => $actorUserId])->save();
    }

    // ── Suppression reads (pre-send guard + canSendVia) ──────────────────

    public function isContactSuppressed(Contact $contact): bool
    {
        $identifiers = array_map(fn ($pair) => $pair[1], $this->contactIdentifiers($contact));
        if ($identifiers === []) {
            return false;
        }
        return MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('identifier', $identifiers)
            ->whereNull('lifted_at')
            ->exists();
    }

    public function isIdentifierSuppressed(string $rawIdentifier, int $agencyId): bool
    {
        $norm = $this->normalizeIdentifier($rawIdentifier);
        if ($norm === null) {
            return false;
        }
        return MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('identifier', $norm[1])
            ->whereNull('lifted_at')
            ->exists();
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function writeSuppression(
        int $agencyId,
        string $type,
        string $identifier,
        string $source,
        ?string $reason,
        ?int $contactId,
        ?int $sendId,
        ?int $recordedBy,
    ): void {
        $alreadyActive = MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('identifier', $identifier)
            ->whereNull('lifted_at')
            ->exists();
        if ($alreadyActive) {
            return; // idempotent — one active suppression per (agency, identifier)
        }

        MarketingSuppression::withoutGlobalScopes()->create([
            'agency_id'           => $agencyId,
            'identifier'          => $identifier,
            'identifier_type'     => $type,
            'contact_id'          => $contactId,
            'source'              => $source,
            'reason'              => $reason,
            'send_id'             => $sendId,
            'suppressed_at'       => now(),
            'recorded_by_user_id' => $recordedBy,
        ]);
    }

    /**
     * @return array<int, array{0:string,1:string}> [type, normalised] pairs for
     *   the contact's email + phone (whichever are present and valid).
     */
    private function contactIdentifiers(Contact $contact): array
    {
        $out = [];
        if (!empty($contact->email)) {
            $out[] = [MarketingSuppression::TYPE_EMAIL, strtolower(trim((string) $contact->email))];
        }
        $phone = $contact->phone ?? $contact->cell_number ?? $contact->mobile ?? null;
        if (!empty($phone) && ($n = $this->duplicates->normalizePhone((string) $phone)) !== null) {
            $out[] = [MarketingSuppression::TYPE_PHONE, $n];
        }
        return $out;
    }

    /** @return array{0:string,1:string}|null [type, normalised] */
    private function normalizeIdentifier(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, '@')) {
            return [MarketingSuppression::TYPE_EMAIL, strtolower($raw)];
        }
        $n = $this->duplicates->normalizePhone($raw);
        return $n === null ? null : [MarketingSuppression::TYPE_PHONE, $n];
    }

    /** Resolve a non-null user id for consent attribution (given_by is NOT NULL). */
    private function resolveActorId(int $agencyId, ?int $preferred): int
    {
        if ($preferred) {
            return $preferred;
        }
        $owner = User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['owner', 'super_admin'])
            ->orderBy('id')
            ->value('id');
        return (int) ($owner ?: User::withoutGlobalScopes()->where('agency_id', $agencyId)->orderBy('id')->value('id'));
    }
}
