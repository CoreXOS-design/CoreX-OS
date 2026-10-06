<?php

namespace App\Services\Rentals;

use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.10 — "work reported done" rounds and the
 * tenant check that follows (internal AND external work).
 *
 * FOUNDATION SHELL (§17.21.1): the signatures are final; BUILD 3 fills the
 * bodies. openRound() is INERT until then (it opens nothing and returns null —
 * Build 3 narrows the return type to a non-null round). The write methods
 * refuse loudly rather than silently do nothing.
 */
class RentalCompletionService
{
    /**
     * A round starts when work is reported done. $report: reported_by_label, reported_via (a
     * RentalWorkCompletionRound::VIA_* value), reported_note?, reported_by_user_id?, photos?. INERT until Build 3.
     *
     * @param array<string, mixed> $report
     */
    public function openRound(RentalWorkOrder $workOrder, array $report): ?RentalWorkCompletionRound
    {
        return null;
    }

    /**
     * The tenant (link / portal) or the office on their behalf answers a round. $actor is `['contact' => Contact]`
     * or `['user' => User]`; $photos are uploaded files (dispute photos). Build 3.
     *
     * @param array<int, mixed> $photos
     * @param array<string, mixed> $actor
     */
    public function respond(RentalWorkCompletionRound $round, bool $fixed, ?string $note, array $photos, array $actor): void
    {
        throw new \LogicException('RentalCompletionService::respond() lands in Build 3 (§17.21.4).');
    }

    /** The office sends a disputed job back to the crew / contractor (fresh link + the tenant's note and photos). Build 3. */
    public function sendBack(RentalWorkOrder $workOrder, User $by): void
    {
        throw new \LogicException('RentalCompletionService::sendBack() lands in Build 3 (§17.21.4).');
    }

    /** Daily: every `awaiting_tenant` round past its window becomes `accepted_by_silence`; returns how many. Build 3. */
    public function settleSilent(): int
    {
        return 0;
    }
}
