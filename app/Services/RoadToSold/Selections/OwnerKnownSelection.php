<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;

/**
 * Selection 7 — owner already known. MIC properties whose owner is on file by ANY of the four paths:
 *   1. the tracked property's own owner_contact_id
 *   2. a tracked_property_owners row with a contact (CMA Info / deeds capture)
 *   3. a seller linked to the CoreX property a pitch created (contact_property role = seller)
 *   4. an owner on the deed the agent linked to the listing
 * Shown again up to the agency's daily quantity (default 3) — the point is a warm number to call.
 * Does NOT skip the agent's own held properties: re-showing known owners is the purpose.
 */
final class OwnerKnownSelection extends MicPoolSelection
{
    public function key(): string
    {
        return 'owner_known';
    }

    public function label(): string
    {
        return 'Owner already known';
    }

    protected function skipOwnHeld(): bool
    {
        return false;
    }

    protected function cap(SelectionContext $ctx, int $limit): int
    {
        return min($limit, max(0, $ctx->settings->ownerKnownQuantity));
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $q->whereRaw(
            "(EXISTS (SELECT 1 FROM contacts oc WHERE oc.id = tp.owner_contact_id AND oc.deleted_at IS NULL)
              OR EXISTS (SELECT 1 FROM tracked_property_owners o1 WHERE o1.tracked_property_id = tp.id AND o1.contact_id IS NOT NULL)
              OR EXISTS (SELECT 1 FROM contact_property cp WHERE cp.property_id = pl.matched_property_id AND cp.role = 'seller' AND cp.deleted_at IS NULL)
              OR EXISTS (SELECT 1 FROM tracked_property_owners o2 WHERE o2.tracked_property_id = pl.linked_deed_tracked_property_id AND o2.contact_id IS NOT NULL))"
        );
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('pl.last_seen_at');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        return 'We already have the owner on file';
    }
}
