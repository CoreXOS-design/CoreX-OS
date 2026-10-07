<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\AgencyContactSettings;
use App\Models\CoreMatchBuyerStateAuditEntry;
use App\Services\BuyerStateService;
use Illuminate\Support\Facades\DB;

/**
 * THE one rule for "does this buyer's Buyer Pipeline status take them off Core Matches?"
 * (Johan, 2026-10-07: a buyer marked Won or Lost has no Core Matches; which statuses count
 * is an AGENCY setting, default Won + Lost — AgencyContactSettings::coreMatchesExcludedBuyerStates()).
 *
 * Every Core Matches surface asks THIS class and nothing else — the board, the mobile list,
 * the property-page tab and new-listing alerts (via ContactMatch::scopeBuyerInPlay()), the
 * pipeline's own match counts, and the daily digest. The rule is read LIVE from the buyer's
 * current status, never from a flag set when the status changed, so a buyer moved back to an
 * active status returns everywhere at once with nothing to restore.
 *
 * A buyer with NO pipeline status (NULL) is never excluded.
 */
class CoreMatchBuyerGate
{
    /** @return string[] pipeline status slugs that exclude a buyer, for this agency */
    public static function excludedStates(?int $agencyId): array
    {
        return AgencyContactSettings::coreMatchExcludedBuyerStatesFor((int) ($agencyId ?: 0));
    }

    /** Is a buyer in this pipeline status off Core Matches for this agency? */
    public static function isExcluded(?string $buyerState, ?int $agencyId): bool
    {
        if ($buyerState === null || $buyerState === '') {
            return false;
        }

        return in_array(strtolower($buyerState), self::excludedStates($agencyId), true);
    }

    /**
     * Narrow a ContactMatch query to wishlists whose buyer is NOT in an excluded status.
     *
     * A raw DB subquery on purpose (never a whereHas('contact')): it must not re-apply
     * Contact's own ContactScope/BranchScope, and a global-scope bypass inside a relation
     * closure does not reliably propagate (see .ai/specs/core-matches.md, "The ContactScope
     * trap"). A NULL buyer_state never matches the IN list, so un-piped buyers are kept.
     */
    public static function applyToMatchQuery($query, ?int $agencyId, string $contactIdColumn = 'contact_matches.contact_id')
    {
        $excluded = self::excludedStates($agencyId);
        if ($excluded === []) {
            return $query;
        }

        return $query->whereNotIn($contactIdColumn, DB::table('contacts')->select('id')->whereIn('buyer_state', $excluded));
    }

    /**
     * Narrow a plain contact-id list (e.g. the pipeline's match-count query) the same way.
     *
     * @param  \Illuminate\Support\Collection<int,int>|array<int,int>  $contactIds
     * @return array<int,int>
     */
    public static function filterContactIds($contactIds, ?int $agencyId): array
    {
        $ids = collect($contactIds)->filter()->values();
        $excluded = self::excludedStates($agencyId);
        if ($ids->isEmpty() || $excluded === []) {
            return $ids->all();
        }

        $drop = DB::table('contacts')->whereIn('id', $ids)->whereIn('buyer_state', $excluded)->pluck('id');

        return $ids->reject(fn ($id) => $drop->contains($id))->values()->all();
    }

    /**
     * Save the agency's excluded-status choice (settings page AND setup wizard both call this —
     * one write path). Unknown slugs are dropped; an empty list is a real choice ("exclude no
     * one"). Writes ONE append-only audit row, and only when the effective list actually changed.
     *
     * @param  string[]  $states
     * @return bool  true when the effective list changed
     */
    public static function saveExcludedStates(int $agencyId, array $states, ?int $userId): bool
    {
        $new = array_values(array_intersect(
            BuyerStateService::PIPELINE_STATES,
            array_map(fn ($s) => strtolower(trim((string) $s)), $states)
        ));

        $settings = AgencyContactSettings::forAgency($agencyId);
        $old = $settings->coreMatchesExcludedBuyerStates();

        $settings->update(['core_matches_excluded_buyer_states' => $new]);
        AgencyContactSettings::clearCoreMatchExcludedBuyerStatesCache();

        if ($old === $new) {
            return false;
        }

        CoreMatchBuyerStateAuditEntry::create([
            'agency_id'          => $agencyId,
            'changed_by_user_id' => $userId,
            'old_values'         => $old,
            'new_values'         => $new,
            'changed_at'         => now(),
        ]);

        return true;
    }
}
