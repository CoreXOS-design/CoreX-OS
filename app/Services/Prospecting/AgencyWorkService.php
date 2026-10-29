<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAgencyWork;
use App\Models\Prospecting\TrackedPropertyAgencyWorkEvent as Event;
use App\Models\Scopes\AgencyScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY reader/writer of an agency's own work record on a tracked property (property-identity.md section 12).
 *
 * Every method takes the agency explicitly: this runs from observers, jobs and commands where nobody is signed in and the agency scope
 * cannot be relied on, and a work record of one agency must never be found or changed through another agency's id.
 *
 * What it holds (and nothing else): prospecting status + reason, the own-stock flag, the current claim at a glance - each change leaves a dated
 * line in tracked_property_agency_work_events. Comments stay in tracked_property_comments and claims in prospecting_claims (both already per
 * agency); this record points at them, it does not copy them.
 */
final class AgencyWorkService
{
    public function __construct(private readonly OnMarketStockService $stock = new OnMarketStockService())
    {
    }

    /** The agency's work record for a tracked property (restored if archived, made if new). The tracked property must belong to the agency. */
    public function workFor(int $agencyId, int $trackedPropertyId): TrackedPropertyAgencyWork
    {
        $this->assertOwn($agencyId, $trackedPropertyId);

        $work = TrackedPropertyAgencyWork::withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->firstOrNew(['agency_id' => $agencyId, 'tracked_property_id' => $trackedPropertyId]);
        if ($work->trashed()) {
            $work->restore();
        }
        if (! $work->exists) {
            $work->save();
        }

        return $work;
    }

    /** The record if one exists - never creates (for read-only screens). */
    public function find(int $agencyId, int $trackedPropertyId): ?TrackedPropertyAgencyWork
    {
        return TrackedPropertyAgencyWork::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->first();
    }

    /** Set (or clear, with null) the prospecting status. A reason is optional; the change is always written to the history. */
    public function setStatus(int $agencyId, int $trackedPropertyId, ?string $status, ?string $reason = null, ?int $userId = null): TrackedPropertyAgencyWork
    {
        if ($status !== null && ! array_key_exists($status, TrackedPropertyAgencyWork::STATUSES)) {
            throw new \InvalidArgumentException("Unknown prospecting status '{$status}'.");
        }
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
        $reason = $reason === '' ? null : $reason;

        return DB::transaction(function () use ($agencyId, $trackedPropertyId, $status, $reason, $userId) {
            $work = $this->workFor($agencyId, $trackedPropertyId);
            $before = $work->prospecting_status;
            if ($before === $status && $work->prospecting_reason === $reason) {
                return $work;
            }
            $work->forceFill([
                'prospecting_status' => $status, 'prospecting_reason' => $status === null ? null : $reason,
                'status_set_by_user_id' => $userId, 'status_set_at' => now(),
            ])->save();
            $this->log($agencyId, $trackedPropertyId, Event::KIND_STATUS, $before, $status, $reason, $userId);

            return $work;
        });
    }

    /**
     * Re-derive the own-stock flag from the agency's own stock: the tracked property was promoted to one of the agency's live properties, or one
     * of its adverts IS one of the agency's on-market properties (the same rule the "our stock" tick and the IN STOCK badge use).
     */
    public function syncOwnStock(int $agencyId, int $trackedPropertyId): TrackedPropertyAgencyWork
    {
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->findOrFail($trackedPropertyId);

        $propertyId = null;
        $source = null;
        if ($tp->promoted_to_property_id) {
            $own = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereKey($tp->promoted_to_property_id)->whereNull('deleted_at')->first();
            if ($own && ! $own->isProspecting() && $own->status !== Property::STATUS_NOT_SELLING) {
                $propertyId = (int) $own->id;
                $source = 'promoted';
            }
        }
        if ($propertyId === null) {
            $listings = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->whereNull('deleted_at')->get();
            $map = $listings->isEmpty() ? [] : $this->stock->stockMapForListings($listings, $agencyId);
            if ($map !== []) {
                $propertyId = (int) reset($map);
                $source = 'advert';
            }
        }

        $isOwn = $propertyId !== null;
        $work = $this->find($agencyId, $trackedPropertyId);
        if (! $work && ! $isOwn) {
            return $this->workFor($agencyId, $trackedPropertyId);   // nothing to record; still hand back a record
        }
        $work ??= $this->workFor($agencyId, $trackedPropertyId);

        $changed = (bool) $work->is_own_stock !== $isOwn || (int) $work->own_stock_property_id !== (int) $propertyId;
        $work->forceFill(['is_own_stock' => $isOwn, 'own_stock_property_id' => $propertyId, 'own_stock_source' => $source, 'own_stock_synced_at' => now()])->save();
        if ($changed) {
            $this->log($agencyId, $trackedPropertyId, Event::KIND_OWN_STOCK, $isOwn ? null : 'own stock', $isOwn ? 'own stock' : null, $source, null);
        }

        return $work;
    }

    /** Re-derive the current claim from the agency's claims on this tracked property's adverts (or its own claim row). */
    public function syncClaim(int $agencyId, int $trackedPropertyId): TrackedPropertyAgencyWork
    {
        $this->assertOwn($agencyId, $trackedPropertyId);
        $listingIds = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->pluck('id');
        $claim = $listingIds->isEmpty() ? null : ProspectingClaim::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->whereIn('prospecting_listing_id', $listingIds)
            ->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')
            ->orderByDesc('claimed_at')->first();

        $work = $this->find($agencyId, $trackedPropertyId);
        if (! $work && ! $claim) {
            return $this->workFor($agencyId, $trackedPropertyId);
        }
        $work ??= $this->workFor($agencyId, $trackedPropertyId);

        $newUser = $claim ? (int) $claim->user_id : null;
        $oldUser = $work->claimed_by_user_id ? (int) $work->claimed_by_user_id : null;
        $work->forceFill(['claimed_by_user_id' => $newUser, 'claimed_at' => $claim?->claimed_at])->save();
        if ($newUser !== $oldUser) {
            $this->log($agencyId, $trackedPropertyId, Event::KIND_CLAIM, $oldUser ? (string) $oldUser : null, $newUser ? (string) $newUser : null, null, $newUser ?? $oldUser);
        }

        return $work;
    }

    /** Dated history, newest first. */
    public function history(int $agencyId, int $trackedPropertyId, int $limit = 50)
    {
        return Event::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function log(int $agencyId, int $trackedPropertyId, string $kind, ?string $from, ?string $to, ?string $reason, ?int $userId, ?CarbonInterface $at = null): void
    {
        Event::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyId, 'tracked_property_id' => $trackedPropertyId, 'kind' => $kind, 'from_value' => $from, 'to_value' => $to,
            'reason' => $reason, 'user_id' => $userId, 'occurred_at' => $at ?? now(),
        ]);
    }

    private function assertOwn(int $agencyId, int $trackedPropertyId): void
    {
        $ok = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereKey($trackedPropertyId)->exists();
        if (! $ok) {
            throw new \DomainException("Tracked property {$trackedPropertyId} does not belong to agency {$agencyId}.");
        }
    }
}
