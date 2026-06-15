<?php

namespace App\Services\Communications;

use App\Models\Communications\CommunicationFlag;
use App\Models\Communications\CommunicationFlagAlert;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pending triage logic (AT-36, addendum §6–§7). Per-agent flag isolation +
 * agent_vs_agent contradiction detection. Phase A only — no AI (agent_vs_ai is
 * Phase B). Records decisions in communication_flags; raises BM alerts in
 * communication_flag_alerts.
 */
class CommunicationTriageService
{
    public function __construct(private PendingAttachmentService $attachments)
    {
    }

    /**
     * The triage list for one agent: live pending items within the grace window,
     * minus identifiers this agent has personally flagged not_real_estate
     * (per-agent suppression — never global).
     *
     * @return Collection<int, CommunicationPending>
     */
    public function pendingForAgent(int $agencyId, int $userId): Collection
    {
        $suppressed = CommunicationFlag::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->where('flag', CommunicationFlag::FLAG_NOT_REAL_ESTATE)
            ->pluck('identifier')
            ->unique()
            ->flip();

        return CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('purged_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('occurred_at')
            ->get()
            ->reject(fn (CommunicationPending $p) => $suppressed->has(IdentifierNormalizer::normalize((string) $p->from_identifier)))
            ->values();
    }

    /**
     * Agent flags a pending item "not real estate" — discard, per-agent. The
     * agent's call stands (not blocked). Phase B will raise agent_vs_ai here when
     * the stored AI verdict was real_estate.
     */
    public function flagNotRealEstate(int $agencyId, User $user, string $identifier, ?string $identifierName, ?string $messageExternalId): CommunicationFlag
    {
        return CommunicationFlag::create([
            'agency_id'           => $agencyId,
            'identifier'          => IdentifierNormalizer::normalize($identifier),
            'identifier_name'     => $identifierName,
            'user_id'             => $user->id,
            'flag'                => CommunicationFlag::FLAG_NOT_REAL_ESTATE,
            'message_external_id' => $messageExternalId,
            'flagged_at'          => now(),
            'review_status'       => CommunicationFlag::REVIEW_OPEN,
        ]);
    }

    /**
     * Agent confirms a pending item IS real estate (added the contact): record a
     * real_estate flag, attach the identifier's pending items to the archive, and
     * raise an agent_vs_agent alert against any prior not_real_estate flag from a
     * different agent. Returns [flag, attachedCount, alertsRaised].
     */
    public function flagRealEstateAndAttach(int $agencyId, User $user, Contact $contact, string $identifier, ?string $identifierName, ?string $messageExternalId): array
    {
        $normalized = IdentifierNormalizer::normalize($identifier);

        $flag = CommunicationFlag::create([
            'agency_id'           => $agencyId,
            'identifier'          => $normalized,
            'identifier_name'     => $identifierName,
            'user_id'             => $user->id,
            'flag'                => CommunicationFlag::FLAG_REAL_ESTATE,
            'message_external_id' => $messageExternalId,
            'flagged_at'          => now(),
            'review_status'       => CommunicationFlag::REVIEW_OPEN,
        ]);

        $attached = $this->attachments->attachAllForIdentifier($agencyId, $identifier, $contact);

        $alerts = $this->raiseAgentVsAgent($agencyId, $normalized, $user, $flag);

        return ['flag' => $flag, 'attached' => $attached, 'alerts' => $alerts];
    }

    /**
     * Stamp + alert any open not_real_estate flag from a DIFFERENT user for this
     * identifier (addendum §7). Archive proceeds (Agent 2 wins); the alert is
     * about Agent 1's earlier judgement.
     */
    private function raiseAgentVsAgent(int $agencyId, string $normalizedIdentifier, User $contradictingUser, CommunicationFlag $newFlag): int
    {
        $priorFlags = CommunicationFlag::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('identifier', $normalizedIdentifier)
            ->where('flag', CommunicationFlag::FLAG_NOT_REAL_ESTATE)
            ->where('user_id', '!=', $contradictingUser->id)
            ->whereNull('contradicted_at')
            ->get();

        foreach ($priorFlags as $prior) {
            $prior->update([
                'contradicted_at'         => now(),
                'contradicted_by_user_id' => $contradictingUser->id,
            ]);

            CommunicationFlagAlert::create([
                'agency_id'             => $agencyId,
                'identifier'            => $normalizedIdentifier,
                'original_flag_id'      => $prior->id,
                'contradicting_flag_id' => $newFlag->id,
                'alert_type'            => CommunicationFlagAlert::TYPE_AGENT_VS_AGENT,
                'status'                => CommunicationFlagAlert::STATUS_OPEN,
            ]);
        }

        return $priorFlags->count();
    }
}
