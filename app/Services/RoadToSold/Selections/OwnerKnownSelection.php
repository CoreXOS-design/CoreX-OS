<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;

/**
 * Selection 7 — owner already known. MIC properties whose owner is on file by ANY of the four paths:
 *   1. the tracked property's own owner_contact_id
 *   2. a tracked_property_owners row with a contact (CMA Info / deeds capture)
 *   3. a seller linked to the CoreX property a pitch created (contact_property role = seller)
 *   4. an owner on the deed the agent linked to the listing
 * Shown again up to the agency's quantity PER AGENT PER DAY (default 3) - counted across the overnight build,
 * a top-up and every "add to my day", so it cannot be exceeded by asking again. The point is a warm number to call.
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
        return min($limit, max(0, $ctx->settings->ownerKnownQuantity - $ctx->countOnDay($this->key())));
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

    protected function explain(object $row, SelectionContext $ctx): array
    {
        $name = null;
        $tid = $row->tracked_property_id ? (int) $row->tracked_property_id : null;
        if ($tid) {
            $ownerId = \Illuminate\Support\Facades\DB::table('tracked_properties')->where('id', $tid)->value('owner_contact_id')
                ?: \Illuminate\Support\Facades\DB::table('tracked_property_owners')->where('tracked_property_id', $tid)->whereNotNull('contact_id')->orderBy('id')->value('contact_id');
            if ($ownerId) {
                $c = \Illuminate\Support\Facades\DB::table('contacts')->where('id', $ownerId)->whereNull('deleted_at')->first(['first_name', 'last_name']);
                $name = $c ? (trim($c->first_name . ' ' . $c->last_name) ?: null) : null;
            }
        }
        $last = $tid ? \Illuminate\Support\Facades\DB::table('rts_contact_outcomes')->where('agency_id', $ctx->agencyId)->where('tracked_property_id', $tid)->whereNull('deleted_at')->max('occurred_at') : null;

        return \App\Services\RoadToSold\Reasons\ReasonLine::ownerKnown($name, $last ? \Illuminate\Support\Carbon::parse($last) : null);
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        return 'We already have the owner on file';
    }
}
