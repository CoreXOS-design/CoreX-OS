<?php

namespace App\Services\Matching;

use App\Jobs\RegenerateBuyerMatchesJob;
use App\Models\BuyerPropertyExclusion;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\Audit\ContactAuditService;
use Illuminate\Support\Facades\DB;

/**
 * "Not for my buyer" (.ai/specs/mic-work-tab.md 13.17): the ONE place a property is marked not for a buyer, from Market Intelligence or from the Core Match results page.
 *
 * The record (buyer_property_exclusions) is per BUYER and per property FILE. It reaches the other tables so every existing reader obeys it without change:
 *  - the MIC cache: prospecting_buyer_matches.dismissed_at on that buyer's rows for the file's adverts (already skipped by the banner count, the Buyers tile,
 *    the card's "Buyers N strong" line and the file stats); the recompute writers re-apply it, so it survives a rebuild;
 *  - Core Matches: the file's own-stock property (properties.tracked_property_id) goes into hidden_property_ids of EVERY wishlist of the buyer, with the reason.
 * Restore undoes both. Hide and restore are written to the buyer's contact history and the property file's history.
 */
class BuyerPropertyExclusions
{
    /**
     * @param  int|null  $trackedPropertyId  the property file (preferred)
     * @param  int|null  $listingId          a lone advert that is not linked to a file
     * @param  int|null  $propertyId         an own-stock property (Core Match side)
     */
    public function hide(Contact $buyer, ?int $trackedPropertyId, ?int $listingId, ?string $reason, ?User $by, string $source = 'mic', ?int $propertyId = null): BuyerPropertyExclusion
    {
        $agencyId = (int) $buyer->agency_id;
        [$tp, $listing, $property] = $this->resolve($agencyId, $trackedPropertyId, $listingId, $propertyId);
        abort_if($tp === null && $listing === null && $property === null, 404);

        $existing = $this->activeQuery($agencyId, (int) $buyer->id)
            ->where(fn ($q) => $this->matchesTarget($q, $tp, $listing, $property))->first();
        if ($existing) {
            return $existing;
        }

        $first = trim((string) $buyer->first_name) !== '' ? trim((string) $buyer->first_name) : 'the buyer';
        $reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 500) : 'Not for ' . $first;

        return DB::transaction(function () use ($buyer, $agencyId, $tp, $listing, $property, $reason, $by, $source) {
            $row = BuyerPropertyExclusion::create([
                'agency_id' => $agencyId, 'contact_id' => $buyer->id, 'tracked_property_id' => $tp, 'prospecting_listing_id' => $tp === null ? $listing : null,
                'property_id' => $property, 'reason' => $reason, 'source' => $source, 'hidden_by_user_id' => $by?->id, 'hidden_at' => now(),
            ]);
            $this->applyToTables($row, true, $by);
            $this->audit($buyer, $row, 'buyer_hidden', 'property_not_for_buyer', 'marked not for', $by);

            return $row;
        });
    }

    public function restore(BuyerPropertyExclusion $row, ?User $by): BuyerPropertyExclusion
    {
        if ($row->restored_at !== null) {
            return $row;
        }
        $buyer = Contact::withoutGlobalScopes()->findOrFail($row->contact_id);

        return DB::transaction(function () use ($row, $buyer, $by) {
            $row->update(['restored_at' => now(), 'restored_by_user_id' => $by?->id]);
            $this->applyToTables($row, false, $by);
            $this->audit($buyer, $row, 'buyer_restored', 'property_restored_for_buyer', 'restored for', $by);

            return $row;
        });
    }

    /** The Core Match page's "un-hide" of an own-stock property: restores the buyer-level record when there is one. Returns false when the hide was an old per-wishlist one. */
    public function restoreForProperty(Contact $buyer, int $propertyId, ?User $by): bool
    {
        $agencyId = (int) $buyer->agency_id;
        $tp = $this->propertyFileId($agencyId, $propertyId);
        $rows = $this->activeQuery($agencyId, (int) $buyer->id)
            ->where(fn ($q) => $this->matchesTarget($q, $tp, null, $propertyId))->get();
        foreach ($rows as $row) {
            $this->restore($row, $by);
        }

        return $rows->isNotEmpty();
    }

    /** Active exclusions of a buyer (newest first). */
    public function activeFor(int $agencyId, int $contactId)
    {
        return $this->activeQuery($agencyId, $contactId)->orderByDesc('hidden_at')->get();
    }

    /**
     * What the recompute writers need: for each buyer, the property files and lone adverts hidden for him.
     *
     * @param  list<int>  $contactIds
     * @return array<int, array{tp: array<int,true>, listing: array<int,true>}>
     */
    public function activeMap(int $agencyId, array $contactIds): array
    {
        $out = [];
        if ($contactIds === []) {
            return $out;
        }
        BuyerPropertyExclusion::query()->where('agency_id', $agencyId)->whereIn('contact_id', $contactIds)->whereNull('restored_at')
            ->get(['contact_id', 'tracked_property_id', 'prospecting_listing_id'])->each(function ($r) use (&$out) {
                $out[(int) $r->contact_id] ??= ['tp' => [], 'listing' => []];
                if ($r->tracked_property_id) {
                    $out[(int) $r->contact_id]['tp'][(int) $r->tracked_property_id] = true;
                }
                if ($r->prospecting_listing_id) {
                    $out[(int) $r->contact_id]['listing'][(int) $r->prospecting_listing_id] = true;
                }
            });

        return $out;
    }

    /**
     * Mark cache rows hidden for the buyers who have an exclusion (the recompute writers call this before they upsert). Every row gets both keys so the batch stays uniform.
     *
     * @param  list<array<string,mixed>>  $rows  rows with prospecting_listing_id and contact_id
     * @return list<array<string,mixed>>
     */
    public function markDismissed(array $rows, int $agencyId): array
    {
        if ($rows === []) {
            return $rows;
        }
        $map = $this->activeMap($agencyId, array_values(array_unique(array_map(fn ($r) => (int) $r['contact_id'], $rows))));
        $tpOf = [];
        if ($map !== []) {
            $tpOf = DB::table('prospecting_listings')->whereIn('id', array_values(array_unique(array_column($rows, 'prospecting_listing_id'))))->pluck('tracked_property_id', 'id')->all();
        }
        foreach ($rows as &$r) {
            $m = $map[(int) $r['contact_id']] ?? null;
            $hidden = $m && (isset($m['listing'][(int) $r['prospecting_listing_id']]) || (($tp = (int) ($tpOf[$r['prospecting_listing_id']] ?? 0)) > 0 && isset($m['tp'][$tp])));
            $r['dismissed_at'] = $hidden ? now() : null;
            $r['dismissed_by_user_id'] = null;
        }
        unset($r);

        return $rows;
    }

    // ---------------------------------------------------------------------------------------------------------------------------------

    private function activeQuery(int $agencyId, int $contactId)
    {
        return BuyerPropertyExclusion::query()->where('agency_id', $agencyId)->where('contact_id', $contactId)->whereNull('restored_at');
    }

    private function matchesTarget($q, ?int $tp, ?int $listing, ?int $property): void
    {
        $q->where(function ($w) use ($tp, $listing, $property) {
            if ($tp) {
                $w->orWhere('tracked_property_id', $tp);
            }
            if ($listing) {
                $w->orWhere('prospecting_listing_id', $listing);
            }
            if ($property) {
                $w->orWhere('property_id', $property);
            }
            if (! $tp && ! $listing && ! $property) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    /** Resolve and agency-check the target; a file or advert of another agency is simply not found. @return array{0:?int,1:?int,2:?int} */
    private function resolve(int $agencyId, ?int $tp, ?int $listing, ?int $property): array
    {
        if ($listing) {
            $row = DB::table('prospecting_listings')->where('id', $listing)->where('agency_id', $agencyId)->first(['id', 'tracked_property_id']);
            abort_if($row === null, 404);
            $tp ??= $row->tracked_property_id ? (int) $row->tracked_property_id : null;
            $listing = $tp ? null : (int) $row->id;
        }
        if ($tp) {
            abort_unless(DB::table('tracked_properties')->where('id', $tp)->where('agency_id', $agencyId)->exists(), 404);
        }
        if ($property) {
            abort_unless(DB::table('properties')->where('id', $property)->where('agency_id', $agencyId)->exists(), 404);
            $tp ??= $this->propertyFileId($agencyId, $property);
        }

        return [$tp ?: null, $listing ?: null, $property ?: null];
    }

    private function propertyFileId(int $agencyId, int $propertyId): ?int
    {
        $v = DB::table('properties')->where('id', $propertyId)->where('agency_id', $agencyId)->value('tracked_property_id');

        return $v ? (int) $v : null;
    }

    /** Push the record into the cache rows and the buyer's wishlists (hide = true) or take it out again. */
    private function applyToTables(BuyerPropertyExclusion $row, bool $hide, ?User $by): void
    {
        $agencyId = (int) $row->agency_id;
        $contactId = (int) $row->contact_id;

        $listingIds = $row->tracked_property_id
            ? DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $row->tracked_property_id)->pluck('id')->all()
            : array_filter([(int) $row->prospecting_listing_id]);
        $touched = 0;
        if ($listingIds) {
            $touched = DB::table('prospecting_buyer_matches')->where('agency_id', $agencyId)->where('contact_id', $contactId)->whereIn('prospecting_listing_id', $listingIds)
                ->update($hide ? ['dismissed_at' => now(), 'dismissed_by_user_id' => $by?->id] : ['dismissed_at' => null, 'dismissed_by_user_id' => null]);
        }
        if (! $hide && $touched < count($listingIds)) {
            // some of the file's adverts have no cache row for him (it was evicted while hidden): rebuild his matches in the background
            try {
                dispatch(new RegenerateBuyerMatchesJob(agencyId: $agencyId, contactId: $contactId, truncate: false));
            } catch (\Throwable $e) {
                \Log::warning('buyer match rebuild after restore not queued', ['contact_id' => $contactId, 'message' => $e->getMessage()]);
            }
        }

        // Core Matches: the own-stock property of this file, hidden on every wishlist the buyer has
        $propertyIds = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($row->tracked_property_id, fn ($q) => $q->where('tracked_property_id', $row->tracked_property_id))
            ->when(! $row->tracked_property_id, fn ($q) => $q->whereRaw('1 = 0'))->pluck('id')->all();
        if ($row->property_id) {
            $propertyIds[] = (int) $row->property_id;
        }
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));
        if ($propertyIds) {
            foreach (ContactMatch::withoutGlobalScopes()->where('agency_id', $agencyId)->where('contact_id', $contactId)->whereNull('deleted_at')->get() as $wishlist) {
                foreach ($propertyIds as $pid) {
                    if ($hide && ! $wishlist->isPropertyHidden($pid)) {
                        $wishlist->hidePropertyWithReason($pid, (string) $row->reason);
                    } elseif (! $hide && $wishlist->isPropertyHidden($pid)) {
                        $wishlist->unhideProperty($pid);
                    }
                }
            }
        }
    }

    /** "522 Bidstone Rd marked not for Alex by Johan Reichel on 2 Nov" on the buyer's contact history and (when there is a file) the file's history. */
    private function audit(Contact $buyer, BuyerPropertyExclusion $row, string $workKind, string $contactEvent, string $verb, ?User $by): void
    {
        $address = $this->addressOf($row);
        $first = trim((string) $buyer->first_name) !== '' ? trim((string) $buyer->first_name) : trim((string) $buyer->last_name);
        $who = $by?->name ?: 'the system';
        $text = $address . ' ' . $verb . ' ' . ($first !== '' ? $first : 'the buyer') . ' by ' . $who . ' on ' . now()->format('j M');

        app(ContactAuditService::class)->log($buyer, 'matching', $contactEvent, $by, null, null,
            ['exclusion_id' => $row->id, 'tracked_property_id' => $row->tracked_property_id, 'prospecting_listing_id' => $row->prospecting_listing_id, 'property_id' => $row->property_id, 'reason' => $row->reason, 'source' => $row->source],
            $text);

        if ($row->tracked_property_id) {
            DB::table('tracked_property_agency_work_events')->insert([
                'agency_id' => $row->agency_id, 'tracked_property_id' => $row->tracked_property_id, 'kind' => $workKind, 'from_value' => null, 'to_value' => null,
                'reason' => mb_substr($text, 0, 500), 'user_id' => $by?->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function addressOf(BuyerPropertyExclusion $row): string
    {
        $a = null;
        if ($row->tracked_property_id) {
            $tp = DB::table('tracked_properties')->where('id', $row->tracked_property_id)->first(['street_number', 'street_name', 'suburb']);
            $a = $tp ? trim(trim((string) $tp->street_number . ' ' . (string) $tp->street_name)) : null;
            if (($a === null || $a === '') && ($ad = DB::table('prospecting_listings')->where('tracked_property_id', $row->tracked_property_id)->whereNotNull('address')->value('address'))) {
                $a = $ad;
            }
        } elseif ($row->prospecting_listing_id) {
            $a = DB::table('prospecting_listings')->where('id', $row->prospecting_listing_id)->value('address');
        } elseif ($row->property_id) {
            $a = DB::table('properties')->where('id', $row->property_id)->value('address');
        }

        return trim((string) $a) !== '' ? trim((string) $a) : 'A property';
    }
}
