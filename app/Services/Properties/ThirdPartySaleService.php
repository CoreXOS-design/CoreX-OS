<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Events\Property\PropertySoldByThirdParty;
use App\Models\Property;
use App\Models\PropertyMarketingActivity;
use App\Models\PropertyThirdPartySale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AT-350 — THE single write path for "another agency sold this property".
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §7
 *
 * There are two ways an agent reaches this outcome and they MUST produce
 * identical system state, or we ship two behaviours for one action:
 *
 *   RICH  — Intelligence tab → "Sold by 3rd Party" → competitor, price, date,
 *           reason → Confirm.               (ThirdPartySaleController::store)
 *   LAZY  — Lifecycle → Status → "Sold by 3rd Party" → Save, nothing else.
 *                                            (PropertyObserver, via ensureOpenRecord)
 *
 * Both funnel through ensureOpenRecord(), whose duplicate guard makes the whole
 * thing idempotent: the rich path creates-then-sets-status, the observer then
 * finds the record already there and does nothing. Re-saving the property
 * changes nothing. BUILD_STANDARD §8 (idempotency).
 *
 * What this service deliberately does NOT do: delist from the portals. The
 * status it writes is in Property::OFF_MARKET_STATUSES, and PropertyObserver
 * already delists every property that turns off-market. Doing it here as well
 * would give one outcome two owners and two chances to drift.
 */
class ThirdPartySaleService
{
    /**
     * RICH path. Records the loss AND moves the property to the status.
     *
     * @param array{sold_by_agency?:string|null, sold_price?:mixed, sold_date?:string|null,
     *              loss_reason?:string|null, notes?:string|null} $data
     *
     * @throws RuntimeException when the property is already concluded by US, or
     *         no agency can be derived (both are user-fixable, and the caller
     *         turns them into a plain-language message — never a 500).
     */
    public function record(Property $property, array $data = [], ?User $actor = null): PropertyThirdPartySale
    {
        $this->guardNotAlreadyOurs($property);
        $this->guardHasAgency($property);

        return DB::transaction(function () use ($property, $data, $actor) {
            $record = $this->ensureOpenRecord($property, $data, $actor);

            // Enrich an existing open record (agent marked it via the dropdown
            // first, then came back to add the detail). ensureOpenRecord returns
            // the existing row untouched, so the data has to land here.
            if (! $record->wasRecentlyCreated && $this->hasAnyDetail($data)) {
                $this->applyDetail($record, $data);
                $record->save();
                $this->syncSoldRecord($property, $record, $actor);
            }

            if (! $property->isSoldByThirdParty()) {
                // A NORMAL save, not auditedQuietUpdate: we WANT the observer to
                // fire so the listing is delisted from P24 / PP / our website.
                $property->status = Property::STATUS_SOLD_BY_3RD_PARTY;
                // A sub-label banner ("Reduced Price", "Pending") on a listing that
                // has left the market is a stale on-market claim. The P24 mapper
                // resolves the terminal base first so it cannot resurrect the
                // listing there, but our own surfaces read the label directly.
                $property->status_label = null;
                $property->save();
            }

            return $record;
        });
    }

    /**
     * LAZY path + the idempotency guard for both paths.
     *
     * Creates the loss record only when the property has no OPEN one. Safe to
     * call on every save; safe to call twice.
     *
     * Snapshots our position at the moment of the loss (asking price, mandate
     * type, days on market, branch) because the property stays editable — it can
     * be re-priced and re-listed — and a join would silently rewrite history.
     */
    public function ensureOpenRecord(Property $property, array $data = [], ?User $actor = null): PropertyThirdPartySale
    {
        $existing = $property->thirdPartySales()->whereNull('reverted_at')->first();
        if ($existing) {
            return $existing;
        }

        $record = new PropertyThirdPartySale([
            'property_id'         => $property->id,
            // Derive the tenant from the PROPERTY, never from a hardcoded id and
            // never from a sentinel. For an ordinary authenticated user
            // BelongsToAgency force-stamps their effective agency over this (they
            // can only ever act inside their own tenant); for console / queue /
            // owner-unswitched contexts this explicit value is what is trusted.
            // STANDARDS Rule 17.
            'agency_id'           => $property->agency_id,
            'branch_id'           => $property->branch_id,
            'our_listing_price'   => $property->price,
            'our_mandate_type'    => $property->mandate_type,
            'days_on_market'      => $this->daysOnMarket($property),
            'recorded_by_user_id' => $actor?->id ?? auth()->id(),
            'recorded_at'         => now(),
        ]);

        $this->applyDetail($record, $data);
        $record->save();

        $this->syncSoldRecord($property, $record, $actor);
        $this->logActivity($property, $record, $actor);

        event(new PropertySoldByThirdParty(
            property:         $property,
            thirdPartySaleId: (int) $record->id,
            soldByAgency:     $record->sold_by_agency,
            soldPrice:        $record->sold_price === null ? null : (string) $record->sold_price,
            soldDate:         $record->sold_date?->toDateString(),
            lossReason:       $record->loss_reason,
            actorUserId:      $record->recorded_by_user_id,
        ));

        return $record;
    }

    /** Enrich a loss record after the fact ("Add details" on the banner). */
    public function updateRecord(PropertyThirdPartySale $record, array $data, ?User $actor = null): PropertyThirdPartySale
    {
        $this->applyDetail($record, $data);
        $record->save();

        if ($record->property) {
            $this->syncSoldRecord($record->property, $record, $actor);
        }

        return $record;
    }

    /**
     * The property is going back on the market. Close the open loss record — but
     * NEVER delete it. Losing the loss history is precisely the failure this
     * feature exists to fix.
     *
     * The comp stays too: the sale genuinely happened, so it remains valid market
     * data for CMA regardless of what we do with the listing afterwards.
     */
    public function revertOpenRecord(Property $property): ?PropertyThirdPartySale
    {
        $record = $property->thirdPartySales()->whereNull('reverted_at')->first();
        if (! $record) {
            return null;
        }

        $record->reverted_at = now();
        $record->save();

        return $record;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Every capture field is optional (spec D4) — we frequently only hear THAT it
     * sold. Blank and whitespace-only inputs normalise to NULL so the Loss
     * Analysis report never grows a competitor called "   ".
     */
    private function applyDetail(PropertyThirdPartySale $record, array $data): void
    {
        // Only keys actually PRESENT are written. A form that posts a subset can
        // therefore never blank a field it did not render — the silent-wipe class
        // .ai/specs/agency-onboarding-setup.md §6.1 documents for wizard savers.
        $set = function (string $key, callable $cast) use ($record, $data) {
            if (! array_key_exists($key, $data)) {
                return;
            }
            $record->{$key} = $cast($data[$key]);
        };

        $set('sold_by_agency', fn ($v) => $this->nullIfBlank($v));
        $set('loss_reason', fn ($v) => $this->nullIfBlank($v));
        $set('notes', fn ($v) => $this->nullIfBlank($v));
        $set('sold_price', fn ($v) => ($v === null || $v === '') ? null : (float) $v);
        $set('sold_date', fn ($v) => $this->nullIfBlank($v));
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasAnyDetail(array $data): bool
    {
        foreach (['sold_by_agency', 'sold_price', 'sold_date', 'loss_reason', 'notes'] as $key) {
            if ($this->nullIfBlank($data[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write (or refresh) the CMA comp — but ONLY when price AND date are both
     * known. A comp with no price or no date is not a comp, and a half-row in
     * property_sold_records would pollute every suburb median that reads it.
     *
     * Flagged sold_by_third_party = 1, which is the exclusion boundary every
     * HFC-performance surface filters on. The `source` enum stays 'manual' — it
     * records HOW the record reached CoreX, not WHO sold it.
     */
    private function syncSoldRecord(Property $property, PropertyThirdPartySale $record, ?User $actor = null): void
    {
        if (! $record->isComparable()) {
            return;
        }

        $payload = [
            'property_id'           => $property->id,
            'address'               => $property->title,
            'suburb'                => $property->suburb,
            'sold_price'            => $record->sold_price,
            'sold_date'             => $record->sold_date?->toDateString(),
            'listing_price_at_sale' => $record->our_listing_price ?? $property->price,
            'days_on_market'        => $record->days_on_market,
            'property_type'         => $property->property_type,
            'source'                => 'manual',
            'sold_by_third_party'   => 1,
            'sold_by_agency'        => $record->sold_by_agency,
            'captured_by_user_id'   => $actor?->id ?? $record->recorded_by_user_id,
            'captured_at'           => now(),
            'agency_id'             => $property->agency_id,
            'updated_at'            => now(),
        ];

        try {
            if ($record->sold_record_id
                && DB::table('property_sold_records')->where('id', $record->sold_record_id)->exists()) {
                DB::table('property_sold_records')->where('id', $record->sold_record_id)->update($payload);

                return;
            }

            $payload['created_at'] = now();
            $soldRecordId = DB::table('property_sold_records')->insertGetId($payload);

            $record->sold_record_id = $soldRecordId;
            $record->save();
        } catch (\Throwable $e) {
            // A comp is enrichment, not the point of the action. If it fails, the
            // loss is still recorded and the listing still leaves the market —
            // the agent's work is never lost to a secondary write.
            Log::warning("AT-350 comp write failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    private function logActivity(Property $property, PropertyThirdPartySale $record, ?User $actor = null): void
    {
        try {
            PropertyMarketingActivity::create([
                'agency_id'        => $property->agency_id,
                'property_id'      => $property->id,
                'activity_type'    => 'other',
                'activity_data'    => [
                    'action'         => 'sold_by_third_party',
                    'sold_by_agency' => $record->sold_by_agency,
                    'sold_price'     => $record->sold_price,
                    'loss_reason'    => $record->loss_reason,
                ],
                'occurred_at'      => now(),
                'logged_by_user_id' => $actor?->id ?? $record->recorded_by_user_id,
                // Internal: the seller does not need our loss post-mortem on their
                // activity feed.
                'internal_only'    => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning("AT-350 activity log failed for property #{$property->id}: {$e->getMessage()}");
        }
    }

    /**
     * Prefer published_at (when it actually went live); fall back to listed_date,
     * then created_at. Null when we have none of them — an unknown DOM is honest,
     * a zero is a lie that skews the Loss Analysis average.
     */
    private function daysOnMarket(Property $property): ?int
    {
        $start = $property->published_at ?? $property->listed_date ?? $property->created_at;
        if (! $start) {
            return null;
        }

        return max(0, (int) $start->diffInDays(now()));
    }

    /**
     * A property cannot be sold by us AND by them. If it is already concluded on
     * our side, the agent is looking at the wrong listing (or the wrong button) —
     * say so plainly rather than silently overwriting a real HFC sale, which
     * would erase a commission record's basis.
     */
    private function guardNotAlreadyOurs(Property $property): void
    {
        if ($property->isConcluded() && ! $property->isSoldByThirdParty()) {
            throw new RuntimeException(
                'This listing is already recorded as ' . $property->statusBadge()
                . '. Change its status first if another agency sold it.'
            );
        }
    }

    /**
     * Never invent a tenant. An owner who has not switched into an agency, acting
     * on a property with no agency_id, gets a clear instruction instead of an FK
     * 1452 or a row stamped into the wrong agency. STANDARDS Rule 17.
     */
    private function guardHasAgency(Property $property): void
    {
        if (empty($property->agency_id)) {
            throw new RuntimeException(
                'This property is not linked to an agency yet. Switch into an agency before recording a 3rd-party sale.'
            );
        }
    }
}
