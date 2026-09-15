<?php

namespace App\Services\Matching;

use App\Models\AgencyContactSettings;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AT-Core-Matches, Johan's "why is this new" ruling — classifies each
 * property in the buyer's UNSEEN set (see CoreMatchShareHistoryService)
 * into the reason an agent (and, for the first three, the buyer) should be
 * told about it. Four reasons, each its own source of truth — collapsing
 * them into one generic "new" flag was explicitly ruled out:
 *
 *  - NEW: never existed as a match candidate before the buyer's last
 *    confirmed share (created/activated since).
 *  - REDUCED: the property's own price_changed audit trail shows a cut
 *    that (a) crossed the property from above this buyer's price_max to
 *    at/below it, and (b) the cut itself clears the agency's configured
 *    newsworthiness threshold (AgencyContactSettings::
 *    coreMatchesPriceDropThresholdFraction()) — Johan's own bar: a
 *    rounding-error drop is not news, even if it happens to cross.
 *  - BACK_ON_MARKET: the property's status audit shows a transition out of
 *    a non-matchable status (sold/withdrawn/archived/etc — the same list
 *    MatchingService itself excludes on) back into a matchable one.
 *  - CRITERIA_WIDENED, agent-only, NEVER surfaced to the buyer: there is
 *    no audit trail for a saved search's own criteria today (checked, not
 *    assumed — contact_matches has none), so this is a residual bucket by
 *    elimination — "unseen, and none of the first three explain it" — not
 *    a confirmed cause. It also silently catches any other reason a
 *    property could newly qualify (e.g. a scoring-logic change), which is
 *    an honest limitation of this bucket, not a claim of precision.
 *
 * Every property arriving here is ALREADY known to be unseen (never in any
 * confirmed share for this match) via the identity-based diff — a property
 * that only now qualifies BECAUSE of a price reduction was, by definition,
 * excluded before and so was never captured in an earlier snapshot either.
 * That means this classifier only ever runs over that already-small set,
 * never the whole candidate pool — the per-property audit-log lookups here
 * do not reopen the N+1 this board once had.
 */
class CoreMatchReasonClassifier
{
    public const REASON_NEW              = 'new';
    public const REASON_REDUCED          = 'reduced';
    public const REASON_BACK_ON_MARKET   = 'back_on_market';
    public const REASON_CRITERIA_WIDENED = 'criteria_widened';

    /** Reasons safe to show a buyer — criteria_widened is agent-only, always. */
    public const BUYER_VISIBLE_REASONS = [self::REASON_NEW, self::REASON_REDUCED, self::REASON_BACK_ON_MARKET];

    private const NON_MATCHABLE_STATUSES = [
        'sold', 'sold_by_3rd_party', 'transferred', 'rented', 'let_out',
        'withdrawn', 'expired', 'cancelled',
        'unavailable', 'archived', 'draft', 'pending',
    ];

    public function __construct(protected CoreMatchShareHistoryService $history)
    {
    }

    /**
     * @param  Collection<int, Property>  $unseenProperties  Already-filtered
     *         unseen set (CoreMatchShareHistoryService::neverSentProperties()).
     * @return Collection<int, array{property: Property, reason: string, meta: array}>
     */
    public function classify(ContactMatch $match, Collection $unseenProperties): Collection
    {
        $lastSharedAt = $this->history->lastSharedAt($match);
        $threshold = AgencyContactSettings::forAgency((int) $match->agency_id)->coreMatchesPriceDropThresholdFraction();

        return $unseenProperties->map(fn (Property $property) => [
            'property' => $property,
            ...$this->classifyOne($property, $match, $lastSharedAt, $threshold),
        ]);
    }

    private function classifyOne(Property $property, ContactMatch $match, ?Carbon $lastSharedAt, float $threshold): array
    {
        // >= not > : a property created in the SAME instant as the last share
        // (timestamp columns are second-precision — a fast backfill/import can
        // genuinely tie) could not have been captured in that share's snapshot,
        // so a tie must read as "not yet seen", not fall through to the
        // criteria-widened catch-all.
        if ($lastSharedAt === null || ($property->created_at && !$property->created_at->lessThan($lastSharedAt))) {
            return ['reason' => self::REASON_NEW, 'meta' => []];
        }

        $reduced = $this->reducedIntoMatch($property, $match, $threshold);
        if ($reduced !== null) {
            return ['reason' => self::REASON_REDUCED, 'meta' => $reduced];
        }

        if ($this->wentBackOnMarket($property, $lastSharedAt)) {
            return ['reason' => self::REASON_BACK_ON_MARKET, 'meta' => []];
        }

        return ['reason' => self::REASON_CRITERIA_WIDENED, 'meta' => []];
    }

    /**
     * Finds the price_changed event (app-layer OR the AT-321 trigger
     * backstop, which stores every field generically and needs its own
     * old/new diff) where old_price exceeded this buyer's ceiling and
     * new_price didn't, and the cut itself clears the agency threshold.
     * Returns null — never guesses — when price history doesn't reach far
     * enough back to answer this (the trigger backstop only exists from
     * 2026-07-20) or when the buyer has no price_max to cross at all.
     */
    private function reducedIntoMatch(Property $property, ContactMatch $match, float $threshold): ?array
    {
        if ($match->price_max === null) {
            return null;
        }

        $rows = PropertyAuditLog::forProperty($property->id)
            ->where(function ($q) {
                $q->where('event_type', 'price_changed')
                    ->orWhere('event_type', 'property_updated');
            })
            ->orderBy('created_at')
            ->get(['old_values', 'new_values', 'created_at']);

        foreach ($rows as $row) {
            $old = $row->old_values['price'] ?? null;
            $new = $row->new_values['price'] ?? null;
            if ($old === null || $new === null || (float) $old === (float) $new) {
                continue; // trigger backstop rows always carry price whether or not it moved
            }

            $droppedIntoRange = $old > $match->price_max && $new <= $match->price_max;
            $cutFraction = $old > 0 ? ($old - $new) / $old : 0;

            if ($droppedIntoRange && $cutFraction >= $threshold) {
                return ['old_price' => (float) $old, 'new_price' => (float) $new, 'changed_at' => $row->created_at];
            }
        }

        return null;
    }

    /** A status audit row showing a move OUT of a non-matchable status, at/after the last share. */
    private function wentBackOnMarket(Property $property, Carbon $lastSharedAt): bool
    {
        return PropertyAuditLog::forProperty($property->id)
            ->where('created_at', '>=', $lastSharedAt)
            ->where(function ($q) {
                $q->where('event_type', 'price_changed')->orWhere('event_type', 'property_updated');
            })
            ->get(['old_values', 'new_values'])
            ->contains(function ($row) {
                $old = strtolower(trim((string) ($row->old_values['status'] ?? '')));
                $new = strtolower(trim((string) ($row->new_values['status'] ?? '')));

                return $old !== '' && $new !== ''
                    && in_array($old, self::NON_MATCHABLE_STATUSES, true)
                    && !in_array($new, self::NON_MATCHABLE_STATUSES, true);
            });
    }
}
