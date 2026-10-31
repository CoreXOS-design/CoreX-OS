<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\PropertyMatchDecision;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\PropertyMatchDecisionService;

/**
 * AT-520 4.4: the one way a matching rule puts a pair of property files on the Possible matches list. The kind (`portal_group`, `twin`, `complex`,
 * `map_point`, `same_address`) is stored in the decision's incoming_facts so the review screen can filter by it. A pair an agent already answered
 * "not the same" (under any kind) is never queued again; a pair already waiting is not queued twice.
 */
final class ReviewPairs
{
    public function __construct(private readonly PropertyMatchDecisionService $decisions)
    {
    }

    public function vetoed(int $agencyId, int $a, int $b): bool
    {
        return PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNotNull('rejected_at')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('matched_id', $b)->where('incoming_facts->incoming_tracked_property_id', $a))
                ->orWhere(fn ($w) => $w->where('matched_id', $a)->where('incoming_facts->incoming_tracked_property_id', $b)))
            ->exists();
    }

    /** @param array<string,mixed> $extra @return bool true when a NEW pair was queued (or would be, on a dry run) */
    public function queue(int $agencyId, int $first, int $other, string $kind, string $reason, ?int $score, bool $apply, array $extra = []): bool
    {
        if ($first === $other || $this->vetoed($agencyId, $first, $other)) {
            return false;
        }
        $waiting = PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('matched_id', $other)->where('incoming_facts->incoming_tracked_property_id', $first))
                ->orWhere(fn ($w) => $w->where('matched_id', $first)->where('incoming_facts->incoming_tracked_property_id', $other)))
            ->whereNull('rejected_at')->exists();
        if ($waiting) {
            return false;
        }
        if ($apply) {
            $d = $this->decisions->record($agencyId, PropertyIdentityResolver::DECISION_SUBJECT, $kind . ':' . $first, 'tracked_property', $other, $kind,
                mb_substr($reason, 0, 480), null, ['kind' => $kind, 'incoming_tracked_property_id' => $first] + $extra, $score);
            $this->decisions->recordOutcome($d, 'pending');
        }

        return true;
    }
}
