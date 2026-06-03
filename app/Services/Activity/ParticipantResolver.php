<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use Illuminate\Database\Eloquent\Model;

/**
 * SPINE-2.5 — resolves the set of (user_id, role_slug) pairs that should
 * be credited for a given multi-actor scoreable action.
 *
 * GOVERNING RULE (Johan's V1):
 *   A scoreable action credits ALL participants by ROLE, not just the
 *   trigger-er. Creator + listing side + selling side all score on a
 *   deal capture; both sides score on a deal registration; every
 *   attendee scores on a calendar event. System resolves the roles;
 *   agency configures per-role weights via value_per_event.
 *
 * Pair format (kept dumb on purpose — no DTOs, no value objects, just
 * arrays the listener loops on):
 *     ['user_id' => int, 'slug' => string]
 *
 * This service is invoked from CreditInstantActionListener BEFORE
 * InstantPointService::credit() — the listener loops the pairs and calls
 * credit() once per pair. Each credit call is independently try/catch'd
 * by InstantPointService itself (SPINE-1 safety) so one participant
 * failing never blocks the others.
 *
 * NULL-actor handling is per-participant: if a particular role slot is
 * NULL (e.g. a deal with no listing agent yet), THAT participant is
 * skipped — other roles still score. NEVER throws.
 *
 * Deduplication across roles on the same event: if the same user appears
 * in two roles for the same subject (e.g. creator is also the listing
 * agent), they receive both credit slugs separately. This is intentional
 * — the agency configured them as distinct roles with distinct weights;
 * the system honours both.
 */
final class ParticipantResolver
{
    /**
     * Resolve participants for the DealCreated event.
     *
     * Emits:
     *   - ('deal.created',         $creator)          single — existing slug, unchanged
     *   - ('deal.listing_side',    each listing agent) one per pivot row
     *   - ('deal.selling_side',    each selling agent) one per pivot row
     *
     * Handles both Deal V1 (deal_user pivot with `side` column) AND
     * DealV2 (deal_v2_agents pivot with `role` column, plus legacy
     * direct columns listing_agent_id / selling_agent_id which we
     * collapse so a deal that's only ever used the legacy columns
     * still credits both sides).
     *
     * @return list<array{user_id:int,slug:string}>
     */
    public function resolveForDealCreated(Model $deal, ?int $actorUserId): array
    {
        return $this->buildDealParticipants(
            deal: $deal,
            actorUserId: $actorUserId,
            creatorSlug: 'deal.created',
            listingSlug: 'deal.listing_side',
            sellingSlug: 'deal.selling_side',
        );
    }

    /**
     * Resolve participants for the DealClosed (outcome='won') = "deal
     * registered" event.
     *
     * Emits:
     *   - ('deal.registered',                  $creator)           single — existing slug
     *   - ('deal.registered.listing_side',     each listing agent) one per pivot row
     *   - ('deal.registered.selling_side',     each selling agent) one per pivot row
     *
     * @return list<array{user_id:int,slug:string}>
     */
    public function resolveForDealRegistered(Model $deal, ?int $actorUserId): array
    {
        return $this->buildDealParticipants(
            deal: $deal,
            actorUserId: $actorUserId,
            creatorSlug: 'deal.registered',
            listingSlug: 'deal.registered.listing_side',
            sellingSlug: 'deal.registered.selling_side',
        );
    }

    /**
     * Internal worker — shared by capture + registration. Reads pivot
     * agents off whichever Deal flavour was passed and emits one pair
     * per role.
     *
     * @return list<array{user_id:int,slug:string}>
     */
    private function buildDealParticipants(
        Model $deal,
        ?int $actorUserId,
        string $creatorSlug,
        string $listingSlug,
        string $sellingSlug,
    ): array {
        $pairs = [];

        // Creator credit — per Johan's V1, creator gets credit even if
        // they aren't an agent on either side. NULL actor → skip silently.
        if ($actorUserId !== null) {
            $pairs[] = ['user_id' => (int) $actorUserId, 'slug' => $creatorSlug];
        }

        [$listingIds, $sellingIds] = $this->dealSideUserIds($deal);

        foreach ($listingIds as $uid) {
            $pairs[] = ['user_id' => (int) $uid, 'slug' => $listingSlug];
        }
        foreach ($sellingIds as $uid) {
            $pairs[] = ['user_id' => (int) $uid, 'slug' => $sellingSlug];
        }

        return $pairs;
    }

    /**
     * Returns [listingUserIds[], sellingUserIds[]] for a Deal of either
     * flavour. Defensive: missing pivots / missing legacy columns yield
     * an empty list rather than an exception. Same user appearing in
     * multiple pivot rows on the same side is de-duped per side.
     *
     * @return array{0:list<int>,1:list<int>}
     */
    private function dealSideUserIds(Model $deal): array
    {
        $listingIds = [];
        $sellingIds = [];

        if ($deal instanceof DealV2) {
            // V2 pivot table (deal_v2_agents.role)
            try {
                $agents = $deal->agents()->get();
                foreach ($agents as $u) {
                    $role = (string) ($u->pivot->role ?? '');
                    if ($role === 'listing_agent') {
                        $listingIds[] = (int) $u->getKey();
                    } elseif ($role === 'selling_agent') {
                        $sellingIds[] = (int) $u->getKey();
                    }
                }
            } catch (\Throwable) {
                // Relation missing / DB error — fall back to legacy columns.
            }

            // Legacy direct columns: a deal that only ever used these
            // (and never wrote to the pivot) still credits both sides.
            // De-duped via the existence check below.
            if (! empty($deal->listing_agent_id)) {
                $listingIds[] = (int) $deal->listing_agent_id;
            }
            if (! empty($deal->selling_agent_id)) {
                $sellingIds[] = (int) $deal->selling_agent_id;
            }
        } elseif ($deal instanceof Deal) {
            // V1 pivot table (deal_user.side)
            try {
                $agents = $deal->agents()->get();
                foreach ($agents as $u) {
                    $side = (string) ($u->pivot->side ?? '');
                    if ($side === 'listing') {
                        $listingIds[] = (int) $u->getKey();
                    } elseif ($side === 'selling') {
                        $sellingIds[] = (int) $u->getKey();
                    }
                }
            } catch (\Throwable) {
                // Relation missing — nothing to credit on sides.
            }
        }

        return [
            array_values(array_unique($listingIds)),
            array_values(array_unique($sellingIds)),
        ];
    }
}
