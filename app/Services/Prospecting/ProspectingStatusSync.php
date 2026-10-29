<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAgencyWork as Work;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE prospecting status per agency per property (property-identity.md section 15.3, C3).
 *
 * The agency's work record (AgencyWorkService, lane B) is where the status lives. This class keeps the property side and the work record telling
 * the same story, in both directions, and only inside one agency:
 *
 *   property -> work record   (Property::saved, any path - the "Not selling" button, the edit form, an import):
 *       the property becomes Not selling      => the work record says "Owner not selling" (with the typed reason, who, when)
 *       the property leaves Not selling       => a "Not selling" on the work record is cleared (history line says why)
 *       a Prospecting property becomes ours   => "Not selling" / "Mandate with another agency" are cleared: it is listed with us now
 *                                                (our own listing is the own-stock flag, which stays lane B's)
 *   work record -> property   (the status form on the Market Intelligence property):
 *       "Owner not selling"                   => the agency's Prospecting property on it becomes Not selling and its open claims are closed
 *                                                (exactly what the Not selling button does)
 *       leaving "Owner not selling"           => that Not selling property goes back to Prospecting
 *       any other status                      => the property is left alone (it has no equivalent)
 *
 * Statuses with no property equivalent (Owner interested, Owner contacted, Mandate with another agency, Do not contact) live on the work record only.
 * Every write names the agency; every change leaves a dated line in the work record's history. Failure-isolated: a failure here never blocks
 * the save that triggered it.
 */
final class ProspectingStatusSync
{
    /** A reason typed on the property flow, carried to the history line of the status that flow is about to set. */
    private static ?string $pendingReason = null;

    /** Statuses of a property that is not (yet) on the market for us. */
    private const NOT_OURS_YET = ['prospecting', 'not_selling', 'archived', 'withdrawn', 'cancelled', 'expired', 'unavailable', 'sold', 'transferred'];

    public function __construct(private readonly AgencyWorkService $work)
    {
    }

    /** Run $fn (a property save) so the status line it causes carries $reason. Always restored. */
    public static function withReason(?string $reason, callable $fn): mixed
    {
        $before = self::$pendingReason;
        self::$pendingReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        try {
            return $fn();
        } finally {
            self::$pendingReason = $before;
        }
    }

    /** The agency's tracked property for a property: the one it was promoted from, else the one the door linked it to. */
    public function trackedPropertyIdOf(Property $property): ?int
    {
        $agencyId = (int) $property->agency_id;
        if ($agencyId <= 0) {
            return null;
        }
        $id = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->where('promoted_to_property_id', $property->id)->value('id');
        if (! $id && $property->tracked_property_id) {
            $id = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereKey($property->tracked_property_id)->value('id');
        }

        return $id ? (int) $id : null;
    }

    // ── property -> work record ──────────────────────────────────────────

    /** Call after a property was saved with a changed status. */
    public function fromProperty(Property $property, ?int $userId): ?string
    {
        try {
            if (! $property->wasChanged('status')) {
                return null;
            }
            $old = strtolower(trim((string) $property->getOriginal('status')));
            $new = strtolower(trim((string) $property->status));
            $tpId = $this->trackedPropertyIdOf($property);
            if (! $tpId) {
                return null;
            }
            $agencyId = (int) $property->agency_id;
            $current = $this->work->find($agencyId, $tpId)?->prospecting_status;

            if ($new === Property::STATUS_NOT_SELLING) {
                if ($current === Work::STATUS_NOT_SELLING) {
                    return null;   // already says so (the status form got here first)
                }
                $this->work->setStatus($agencyId, $tpId, Work::STATUS_NOT_SELLING, self::$pendingReason, $userId);

                return 'set not selling';
            }
            if ($old === Property::STATUS_NOT_SELLING && $current === Work::STATUS_NOT_SELLING) {
                $this->work->setStatus($agencyId, $tpId, null, 'The property is no longer marked Not selling', $userId);

                return 'cleared not selling';
            }
            if ($old === Property::STATUS_PROSPECTING && ! in_array($new, self::NOT_OURS_YET, true)
                && in_array($current, [Work::STATUS_NOT_SELLING, Work::STATUS_MANDATE_ELSEWHERE], true)) {
                $this->work->setStatus($agencyId, $tpId, null, 'Now listed with us', $userId);

                return 'cleared: listed with us';
            }
        } catch (\Throwable $e) {
            Log::warning('Prospecting status sync (property -> work record) failed; the save stands', ['property_id' => $property->id ?? null, 'error' => $e->getMessage()]);
        }

        return null;
    }

    // ── work record -> property ──────────────────────────────────────────

    /**
     * Call after the work record's status was set from the Market Intelligence form. $before = the status it had.
     *
     * @return list<int> ids of the properties that were changed
     */
    public function toProperty(int $agencyId, int $trackedPropertyId, ?string $before, ?string $after, ?int $userId): array
    {
        $changed = [];
        try {
            if ($before === $after) {
                return [];
            }
            $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->find($trackedPropertyId);
            if (! $tp) {
                return [];
            }
            $props = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where(function ($q) use ($tp) {
                    $q->where('tracked_property_id', $tp->id);
                    if ($tp->promoted_to_property_id) {
                        $q->orWhere('id', $tp->promoted_to_property_id);
                    }
                })->get();

            foreach ($props as $property) {
                $status = strtolower(trim((string) $property->status));
                if ($after === Work::STATUS_NOT_SELLING && $status === Property::STATUS_PROSPECTING) {
                    // the adverts matched to the property AND the adverts of the Market Intelligence property being marked
                    $listingIds = $this->matchedListingIds($property)->merge(
                        DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $tp->id)->whereNull('deleted_at')->pluck('id')
                    )->unique()->values();
                    $property->status = Property::STATUS_NOT_SELLING;
                    $property->save();
                    $this->closeOpenClaims($property, $listingIds);
                    $changed[] = (int) $property->id;
                } elseif ($before === Work::STATUS_NOT_SELLING && $status === Property::STATUS_NOT_SELLING) {
                    $property->status = Property::STATUS_PROSPECTING;
                    $property->save();
                    $changed[] = (int) $property->id;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Prospecting status sync (work record -> property) failed; the status stands', ['tracked_property_id' => $trackedPropertyId, 'error' => $e->getMessage()]);
        }

        return $changed;
    }

    // ── claims closed when a property becomes Not selling (one place; the button and the status form both use it) ──

    /** Adverts matched to the property. Read BEFORE the save: the save queues a job that clears these links. @return \Illuminate\Support\Collection<int,int> */
    public function matchedListingIds(Property $property)
    {
        return DB::table('prospecting_listings')->where('matched_property_id', $property->id)->whereNull('deleted_at')->pluck('id');
    }

    /**
     * "The claim has run its cycle" (Johan, 2026-08-27): every ACTIVE claim on the property - via an advert matched to it, or the claim's own
     * property id - is closed (not interested, released, noted), so it falls off My Claims. Bulk update: the claim observer never sees it, so the
     * Market Intelligence counts cache is bumped here.
     *
     * @param  \Illuminate\Support\Collection<int,int>|array<int,int>  $matchedListingIds
     */
    public function closeOpenClaims(Property $property, $matchedListingIds): void
    {
        ProspectingClaim::where('agency_id', $property->agency_id)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->where(function ($q) use ($matchedListingIds, $property) {
                $q->whereIn('prospecting_listing_id', $matchedListingIds)
                  ->orWhere('property_id', $property->id);
            })
            ->update([
                'status'          => ProspectingClaim::STATUS_NOT_INTERESTED,
                'is_active'       => false,
                'released_at'     => now(),
                'release_reason'  => 'not_selling',
                'feedback_at'     => now(),
                'notes'           => DB::raw("CONCAT(COALESCE(notes, ''), '\n[" . now()->format('Y-m-d H:i') . "] Marked Not selling — claim closed.')"),
                'last_updated_at' => now(),
            ]);
        ProspectingClaim::bumpCountsCacheVersion((int) $property->agency_id);
    }
}
