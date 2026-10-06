<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalJobCardLinkIssued;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. Mint/revoke the
 * contractor's no-login secure link. Only a hash is ever persisted or
 * returned from here as a "stored" value — the raw token is generated
 * here and handed straight back to the caller (the agent's own request),
 * never logged, never re-derivable from the database.
 *
 * .ai/specs/rental-work-orders.md §14.27.6 items 2-3 — generalised to the
 * crew's links: issue()/revokeAllFor()/revoke()/resolveLive() work on a work
 * order, a job card or a crew, chosen by the target's class and the link's
 * purpose. Doctrine (§14.27.5): 64-character random token shown once, only
 * its SHA-256 stored, one live link per target, and issuing again revokes
 * the previous one IN THE SAME TRANSACTION so the old link is dead on the
 * very next request.
 */
class RentalSecureAccessTokenService
{
    /** Revokes any existing live token for this work order first — at most one active link at a time. */
    public function issueFor(RentalWorkOrder $workOrder, User $createdBy): array
    {
        return $this->issue(
            $workOrder,
            RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER,
            $createdBy,
            RentalPortalSetting::contractorSecureLinkExpiryDaysFor($workOrder->agency_id),
        );
    }

    /**
     * §14.28 — mint the per-job crew link for a card (expiry from the
     * agency's `crew_job_link_expiry_days`), log it on the card's history and
     * announce it. Re-issuing replaces the old link.
     *
     * @return array{token: RentalSecureAccessToken, raw_token: string}
     */
    public function issueForJobCard(RentalJobCard $jobCard, User $by): array
    {
        $hadLive = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('rental_job_card_id', $jobCard->id)->whereNull('revoked_at')->exists();

        $issued = $this->issue(
            $jobCard,
            RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD,
            $by,
            RentalPortalSetting::crewJobLinkExpiryDaysFor($jobCard->agency_id),
        );

        $expires = $issued['token']->expires_at;
        $jobCard->logUpdate(
            'link_issued',
            $by,
            'Crew link created' . ($hadLive ? ' (replaces the earlier link)' : '') . ($expires ? ' — valid until ' . $expires->format('j M Y') : ''),
        );
        RentalJobCardLinkIssued::dispatch($jobCard, $issued['token']->id, $by->id);

        return $issued;
    }

    /**
     * §14.29 — mint (or regenerate) a crew's STANDING link, the crew page. Expiry
     * comes from the agency's `crew_standing_link_expiry_days`; blank (null) =
     * stands until revoked. Re-issuing replaces the old link in the same
     * transaction (the old link is dead on its very next request) and the
     * crew-level event log records `issued` or `regenerated`.
     *
     * @return array{token: RentalSecureAccessToken, raw_token: string}
     */
    public function issueForCrew(RentalCrew $crew, User $by): array
    {
        $hadLive = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('rental_crew_id', $crew->id)->whereNull('revoked_at')->exists();

        $issued = $this->issue(
            $crew,
            RentalSecureAccessToken::PURPOSE_CREW_STANDING,
            $by,
            RentalPortalSetting::crewStandingLinkExpiryDaysFor($crew->agency_id),
        );

        $expires = $issued['token']->expires_at;
        \App\Models\RentalCrewLinkEvent::record(
            $hadLive ? \App\Models\RentalCrewLinkEvent::EVENT_REGENERATED : \App\Models\RentalCrewLinkEvent::EVENT_ISSUED,
            (int) $crew->agency_id,
            (int) $crew->id,
            (int) $issued['token']->id,
            null,
            ($hadLive ? 'Crew link regenerated (the earlier link no longer works)' : 'Crew link created')
                . ($expires ? ' — valid until ' . $expires->format('j M Y') : ' — stands until revoked'),
            $by,
        );

        return $issued;
    }

    /**
     * Revokes any live token for the target first (same transaction), then
     * mints. $expiryDays null = no expiry (a crew-page link standing until
     * revoked).
     *
     * @return array{token: RentalSecureAccessToken, raw_token: string}
     */
    public function issue(Model $target, string $purpose, User $by, ?int $expiryDays): array
    {
        $attributes = $this->targetAttributes($target, $purpose);

        return DB::transaction(function () use ($target, $purpose, $by, $expiryDays, $attributes) {
            $this->revokeAllFor($target);

            $rawToken = RentalSecureAccessToken::generateRawToken();

            $token = RentalSecureAccessToken::create($attributes + [
                'agency_id' => $target->agency_id,
                'purpose' => $purpose,
                'token_hash' => RentalSecureAccessToken::hashToken($rawToken),
                'expires_at' => $expiryDays !== null ? now()->addDays($expiryDays) : null,
                'created_by_user_id' => $by->id,
            ]);

            return ['token' => $token, 'raw_token' => $rawToken];
        });
    }

    public function revokeAllFor(Model $target): void
    {
        RentalSecureAccessToken::withoutGlobalScopes()
            ->where($this->targetColumn($target), $target->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Archiving a crew kills every link it holds — its standing crew-page link
     * AND every still-open per-job link on the crew's cards — and each one is
     * audited (crew link log; the card's own history for a per-job link).
     * Restoring the crew deliberately does NOT undo this: `revoked_at` stays
     * set, so the office generates a fresh link. Returns how many links died.
     */
    public function revokeAllForArchivedCrew(RentalCrew $crew, ?User $by = null): int
    {
        return DB::transaction(function () use ($crew, $by) {
            $cardIds = RentalJobCard::withoutGlobalScopes()
                ->where('agency_id', $crew->agency_id)
                ->where('rental_crew_id', $crew->id)
                ->pluck('id')->all();

            $live = RentalSecureAccessToken::withoutGlobalScopes()
                ->where('agency_id', $crew->agency_id)
                ->whereNull('revoked_at')
                ->where(function ($q) use ($crew, $cardIds) {
                    $q->where('rental_crew_id', $crew->id);
                    if ($cardIds) {
                        $q->orWhereIn('rental_job_card_id', $cardIds);
                    }
                })
                ->get();

            foreach ($live as $token) {
                $token->forceFill(['revoked_at' => now()])->save();

                $jobCardId = $token->rental_job_card_id ? (int) $token->rental_job_card_id : null;
                \App\Models\RentalCrewLinkEvent::record(
                    \App\Models\RentalCrewLinkEvent::EVENT_REVOKED,
                    (int) $crew->agency_id,
                    (int) $crew->id,
                    (int) $token->id,
                    $jobCardId,
                    'Crew archived — link revoked',
                    $by,
                );
                if ($jobCardId) {
                    RentalJobCard::withoutGlobalScopes()->find($jobCardId)
                        ?->logUpdate('link_revoked', $by, "Crew link revoked — crew '{$crew->name}' was archived");
                }
            }

            return $live->count();
        });
    }

    public function revoke(RentalSecureAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Hash lookup + liveness, for a link of the given purpose only — a crew
     * token presented on the contractor URL (or the reverse) resolves to
     * nothing. Null for unknown, wrong-purpose, revoked, expired or closed:
     * the caller renders ONE identical "unavailable" page for all of them.
     */
    public function resolveLive(string $rawToken, string $purpose): ?RentalSecureAccessToken
    {
        $record = RentalSecureAccessToken::findLiveByRawToken($rawToken);
        if (!$record) {
            return null;
        }
        if (($record->purpose ?: RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER) !== $purpose) {
            return null;
        }

        return $record->isLive() ? $record : null;
    }

    private function targetColumn(Model $target): string
    {
        return match (true) {
            $target instanceof RentalWorkOrder => 'rental_work_order_id',
            $target instanceof RentalJobCard => 'rental_job_card_id',
            $target instanceof RentalCrew => 'rental_crew_id',
            default => throw new \InvalidArgumentException('A secure link can only target a work order, a job card or a crew.'),
        };
    }

    /** Exactly one target column is set, and it must match the purpose. */
    private function targetAttributes(Model $target, string $purpose): array
    {
        $column = $this->targetColumn($target);
        $expected = match ($purpose) {
            RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER => 'rental_work_order_id',
            RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD => 'rental_job_card_id',
            RentalSecureAccessToken::PURPOSE_CREW_STANDING => 'rental_crew_id',
            default => throw new \InvalidArgumentException("Unknown secure link purpose '{$purpose}'."),
        };
        if ($column !== $expected) {
            throw new \InvalidArgumentException("A '{$purpose}' link cannot target a " . class_basename($target) . '.');
        }

        $attributes = [$column => $target->getKey()];
        if ($target instanceof RentalWorkOrder) {
            $attributes['agency_service_provider_id'] = $target->agency_service_provider_id;
        }

        return $attributes;
    }
}
