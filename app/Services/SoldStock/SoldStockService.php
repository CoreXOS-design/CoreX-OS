<?php

declare(strict_types=1);

namespace App\Services\SoldStock;

use App\Models\Deal;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertyContactRoleHistory;
use App\Models\PropertyListingCycle;
use App\Models\Scopes\AgencyScope;
use App\Models\SoldStock;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sold-stock snapshots + listing cycles — .ai/specs/sold-stock.md (AT-512).
 *
 * The ONE place a sale is frozen (snapshot) and a re-prospecting starts a new cycle. Callers: the
 * PropertyObserver (status change), the deal-registered listener, the backfill command.
 */
class SoldStockService
{
    public const DEFAULT_SHOW_DAYS = 14;

    /** contact_property roles on the seller side of a listing. */
    public const SELLER_ROLES = ['owner', 'seller', 'co_seller', 'landlord', 'lessor'];

    /** Per-agency "Keep showing on portals as Sold for X days". 0 = take down at once. */
    public static function showDays(?int $agencyId): int
    {
        $days = (int) PerformanceSetting::get('sold_portal_show_days', self::DEFAULT_SHOW_DAYS, $agencyId);

        return max(0, min(365, $days));
    }

    /** Status-change hook (PropertyObserver::saved). Never throws into the save. */
    public function onStatusChanged(Property $property): void
    {
        // wasRecentlyCreated lingers on a reused instance, so "created" is read from the missing original status.
        $new = strtolower(trim((string) $property->status));
        $old = strtolower(trim((string) $property->getOriginal('status')));

        if ($new === 'sold' && $old !== 'sold') {
            $this->snapshot($property, null, 'status');

            return;
        }

        if ($old !== 'sold' || $new === 'sold') {
            return;
        }

        if ($new === Property::STATUS_PROSPECTING) {
            $this->startNewCycle($property, (string) ($property->cycleStartReason ?: 'manual'));

            return;
        }

        // Sold -> an on-market status: the sale fell through. The sale is not history.
        if (! in_array($new, Property::OFF_MARKET_STATUSES, true)) {
            $this->discardOpenSnapshot($property);
        }
    }

    /**
     * Freeze the sale of the property's CURRENT cycle. Idempotent: one row per property + cycle; a
     * second trigger (deal registration after the status change, or vice versa) only fills fields the
     * first could not know and never overwrites a filled one.
     */
    public function snapshot(Property $property, ?Deal $deal = null, string $source = 'status', ?int $userId = null): ?SoldStock
    {
        if (Property::isSoldByThirdPartyStatus((string) $property->status)) {
            return null;
        }

        $cycle = (int) ($property->listing_cycle ?: 1);
        $deal ??= $this->resolveDeal($property);
        $facts = $this->saleFacts($property, $deal);

        $existing = SoldStock::withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('property_id', $property->id)->where('cycle_no', $cycle)->first();

        if ($existing) {
            $fill = [];
            foreach (['deal_id', 'sale_price', 'sale_date', 'days_on_market'] as $col) {
                if ($existing->{$col} === null && ($facts[$col] ?? null) !== null) {
                    $fill[$col] = $facts[$col];
                }
            }
            foreach (['seller_contact_ids', 'buyer_contact_ids'] as $col) {
                if (empty($existing->{$col}) && ! empty($facts[$col])) {
                    $fill[$col] = $facts[$col];
                }
            }
            if ($fill) {
                $existing->forceFill($fill)->saveQuietly();
            }
            $this->applyGrace($property, $existing->sale_date);

            return $existing;
        }

        $row = SoldStock::withoutAgencyStamping(fn () => SoldStock::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'              => $property->agency_id,
            'branch_id'              => $property->branch_id,
            'property_id'            => $property->id,
            'cycle_no'               => $cycle,
            'title'                  => $property->title,
            'description'            => $property->description,
            'features'               => $property->features_json,
            'beds'                   => $property->beds,
            'baths'                  => $property->baths,
            'size_m2'                => $property->size_m2,
            'address'                => $property->address,
            'suburb'                 => $property->suburb,
            'city'                   => $property->city,
            'property_type'          => $property->property_type,
            'listing_type'           => $property->listing_type,
            'asking_price'           => $property->price,
            'listed_date'            => $property->listed_date,
            'agent_id'               => $property->getOriginal('agent_id', $property->agent_id),
            'second_agent_id'        => $property->pp_second_agent_id,
            'photos'                 => $this->photoReferences($property),
            'source'                 => $source,
            'snapshotted_by_user_id' => $userId,
        ] + $facts));

        $this->ensureCycle($property, $cycle)->forceFill(['sold_stock_id' => $row->id])->save();
        $this->applyGrace($property, $row->sale_date);

        return $row;
    }

    /**
     * The sold record is the authoritative sale fact when it arrives AFTER the status change (the sold-sheet importer):
     * correct the current cycle's snapshot to it and re-count the portal grace window from the real sale date.
     */
    public function syncFromSoldRecord(Property $property): void
    {
        $snap = SoldStock::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)->where('cycle_no', (int) ($property->listing_cycle ?: 1))->first();
        $record = DB::table('property_sold_records')->where('property_id', $property->id)->orderByDesc('sold_date')->orderByDesc('id')->first();
        if (! $snap || ! $record) {
            return;
        }
        $days = $record->days_on_market;
        if ($days === null && $snap->listed_date) {
            $days = max(0, Carbon::parse($snap->listed_date)->diffInDays(Carbon::parse($record->sold_date)));
        }
        $snap->forceFill([
            'sale_price' => $record->sold_price, 'sale_date' => $record->sold_date, 'days_on_market' => $days, 'source' => 'import',
        ])->saveQuietly();
        $this->applyGrace($property, $record->sold_date);
    }

    /** Start cycle N+1 on the same record (Sold -> Prospecting). */
    public function startNewCycle(Property $property, string $reason = 'manual', ?int $userId = null): PropertyListingCycle
    {
        $oldCycle = (int) ($property->listing_cycle ?: 1);
        $snap = $this->snapshot($property, null, 'status', $userId);
        $cycleRow = $this->ensureCycle($property, $oldCycle);

        // Freeze the finished cycle's contact roles, split sellers / buyers.
        $links = DB::table('contact_property')->where('property_id', $property->id)->whereNull('deleted_at')->get();
        $owners = [];
        $buyers = [];
        foreach ($links as $l) {
            PropertyContactRoleHistory::withoutAgencyStamping(fn () => PropertyContactRoleHistory::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $property->agency_id, 'property_id' => $property->id, 'contact_id' => $l->contact_id,
                'role' => $l->role, 'cycle_no' => $oldCycle, 'ended_reason' => 'reprospected', 'ended_at' => now(),
            ]));
            if (in_array($l->role, self::SELLER_ROLES, true)) {
                $owners[] = (int) $l->contact_id;
            } elseif ($l->role === 'buyer') {
                $buyers[] = (int) $l->contact_id;
            }
        }
        $buyers = array_values(array_unique(array_merge($buyers, (array) ($snap?->buyer_contact_ids ?? []))));

        // The prior seller-side links leave the live property (history is above and on the snapshot);
        // the buyer stays linked and is offered as the new seller.
        foreach ($owners as $cid) {
            ContactPropertyLinker::unlink($cid, $property->id);
        }

        $cycleRow->forceFill([
            'ended_at'                   => now(),
            'end_reason'                 => 'reprospected',
            'previous_owner_contact_ids' => $owners ?: ($snap?->seller_contact_ids ?? []),
            'previous_buyer_contact_ids' => $buyers,
            'previous_agent_id'          => $property->getOriginal('agent_id', $property->agent_id),
        ])->save();

        $next = $oldCycle + 1;
        $suggested = $buyers[0] ?? null;
        $new = PropertyListingCycle::withoutAgencyStamping(fn () => PropertyListingCycle::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'                   => $property->agency_id,
            'property_id'                 => $property->id,
            'cycle_no'                    => $next,
            'started_at'                  => now(),
            'start_reason'                => $reason,
            'started_by_user_id'          => $userId ?? auth()->id(),
            'status_at_start'             => Property::STATUS_PROSPECTING,
            'suggested_seller_contact_id' => $suggested,
            'suggestion_state'            => $suggested ? 'pending' : null,
        ]));

        // Cycle counter + clear the sold grace; query update = no observer recursion.
        Property::withoutGlobalScopes()->whereKey($property->id)->update(['listing_cycle' => $next, 'portal_sold_until' => null]);
        $property->setAttribute('listing_cycle', $next);
        $property->setAttribute('portal_sold_until', null);
        $property->syncOriginalAttributes(['listing_cycle', 'portal_sold_until']);

        try {
            app(\App\Services\Audit\PropertyAuditService::class)->log($property, 'property', 'listing_cycle_started',
                oldValues: ['listing_cycle' => $oldCycle],
                newValues: ['listing_cycle' => $next, 'reason' => $reason],
                humanSummary: "Listing cycle {$next} started ({$reason}) — previous sale kept as history");
        } catch (\Throwable $e) {
            Log::warning("Listing-cycle audit failed for property #{$property->id}: {$e->getMessage()}");
        }

        return $new;
    }

    /** Agent confirms the previous buyer as the new seller (buyer -> owner; role changes, history kept). */
    public function confirmSuggestion(Property $property, ?int $userId = null): bool
    {
        $cycle = $this->openSuggestion($property);
        if (! $cycle) {
            return false;
        }
        $cid = (int) $cycle->suggested_seller_contact_id;
        PropertyContactRoleHistory::withoutAgencyStamping(fn () => PropertyContactRoleHistory::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id, 'contact_id' => $cid,
            'role' => 'buyer', 'cycle_no' => $cycle->cycle_no - 1, 'ended_reason' => 'became_owner', 'ended_at' => now(),
        ]));
        ContactPropertyLinker::link($cid, $property->id, 'owner', ['source' => 'manual']);
        $cycle->forceFill(['suggestion_state' => 'confirmed'])->save();

        return true;
    }

    public function dismissSuggestion(Property $property): bool
    {
        $cycle = $this->openSuggestion($property);

        return $cycle ? $cycle->forceFill(['suggestion_state' => 'dismissed'])->save() : false;
    }

    public function openSuggestion(Property $property): ?PropertyListingCycle
    {
        return PropertyListingCycle::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)->where('cycle_no', (int) ($property->listing_cycle ?: 1))
            ->where('suggestion_state', 'pending')->whereNotNull('suggested_seller_contact_id')->first();
    }

    public function ensureCycle(Property $property, int $cycleNo): PropertyListingCycle
    {
        $row = PropertyListingCycle::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)->where('cycle_no', $cycleNo)->first();

        return $row ?: PropertyListingCycle::withoutAgencyStamping(fn () => PropertyListingCycle::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'    => $property->agency_id,
            'property_id'  => $property->id,
            'cycle_no'     => $cycleNo,
            'started_at'   => $property->created_at,
            'start_reason' => $cycleNo === 1 ? 'initial' : 'backfill',
            'status_at_start' => $cycleNo === 1 ? 'draft' : null,
        ]));
    }

    /** portal_sold_until = sale date + the agency's grace days — only while the property is actually Sold. */
    public function applyGrace(Property $property, $saleDate): void
    {
        if (strtolower((string) $property->status) !== 'sold') {
            return;
        }
        $until = Carbon::parse($saleDate ?? now())->startOfDay()->addDays(self::showDays($property->agency_id))->toDateString();
        Property::withoutGlobalScopes()->whereKey($property->id)->update(['portal_sold_until' => $until]);
        $property->setAttribute('portal_sold_until', $until);
        $property->syncOriginalAttributes(['portal_sold_until']);
    }

    private function discardOpenSnapshot(Property $property): void
    {
        $row = SoldStock::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)->where('cycle_no', (int) ($property->listing_cycle ?: 1))->first();
        $row?->delete();
        Property::withoutGlobalScopes()->whereKey($property->id)->update(['portal_sold_until' => null]);
    }

    /** The deal that sold this property: a registered one first, else the latest granted. */
    public function resolveDeal(Property $property): ?Deal
    {
        $ids = DB::table('deal_properties')->where('property_id', $property->id)->whereNull('deleted_at')->pluck('deal_id')->all();

        return Deal::withoutGlobalScope(AgencyScope::class)
            ->where(fn ($q) => $q->where('property_id', $property->id)->orWhereIn('id', $ids ?: [0]))
            ->whereIn('accepted_status', ['G', 'R'])
            ->orderByRaw("accepted_status = 'R' desc")->orderByDesc('registration_date')->orderByDesc('id')->first();
    }

    /** @return array<string,mixed> sale columns derived from the deal, else the sold record */
    private function saleFacts(Property $property, ?Deal $deal): array
    {
        $record = DB::table('property_sold_records')->where('property_id', $property->id)
            ->orderByDesc('sold_date')->orderByDesc('id')->first();

        $price = null;
        $date  = null;
        if ($deal) {
            $alloc = DB::table('deal_properties')->where('deal_id', $deal->id)->where('property_id', $property->id)->whereNull('deleted_at')->value('allocated_price');
            $price = $alloc ?: ($deal->sale_price ?: null);
            $date  = $deal->registration_date ?: $deal->sale_date;
        }
        $price ??= $record?->sold_price;
        $date  ??= $record?->sold_date;
        $date = $date ? Carbon::parse($date) : (strtolower((string) $property->status) === 'sold' ? Carbon::today() : null);

        $days = $record?->days_on_market;
        if ($days === null && $property->listed_date && $date) {
            $days = max(0, Carbon::parse($property->listed_date)->diffInDays($date));
        }

        $sellers = $deal ? DB::table('deal_contacts')->where('deal_id', $deal->id)->where('role', 'seller')->pluck('contact_id')->all() : [];
        $buyers  = $deal ? DB::table('deal_contacts')->where('deal_id', $deal->id)->where('role', 'buyer')->pluck('contact_id')->all() : [];
        if (! $sellers) {
            $sellers = DB::table('contact_property')->where('property_id', $property->id)->whereNull('deleted_at')
                ->whereIn('role', self::SELLER_ROLES)->pluck('contact_id')->all();
        }
        if (! $buyers) {
            $buyers = DB::table('contact_property')->where('property_id', $property->id)->whereNull('deleted_at')
                ->where('role', 'buyer')->pluck('contact_id')->all();
        }
        $agents = $deal ? DB::table('deal_user')->where('deal_id', $deal->id)->pluck('user_id')->all() : [];
        $agents = array_values(array_unique(array_filter(array_merge(
            [$property->getOriginal('agent_id', $property->agent_id), $property->pp_second_agent_id], $agents
        ))));

        return [
            'deal_id'            => $deal?->id,
            'sale_price'         => $price !== null ? (float) $price : null,
            'sale_date'          => $date?->toDateString(),
            'days_on_market'     => $days !== null ? (int) $days : null,
            'seller_contact_ids' => array_values(array_map('intval', $sellers)),
            'buyer_contact_ids'  => array_values(array_map('intval', $buyers)),
            'agent_ids'          => array_map('intval', $agents),
        ];
    }

    /** @return string[] references only (paths/URLs) — nothing is copied or re-uploaded */
    private function photoReferences(Property $property): array
    {
        return array_values(array_filter(array_map(
            static fn ($i) => is_array($i) ? ($i['url'] ?? $i['path'] ?? null) : $i,
            $property->syndicationImages()
        )));
    }
}
