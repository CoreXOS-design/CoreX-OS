<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\Deal;
use App\Models\DealProperty;

/**
 * AT-398 split-pricing entry — Johan's ruling, verbatim (via AskUserQuestion,
 * both Recommended options chosen):
 *
 *  - "Keep it, add the new one on top" — when a property is added to a deal
 *    that already has a price, the existing price is NEVER redistributed or
 *    silently touched. The new property gets its OWN price entered separately.
 *  - "Price each property, total adds up automatically" — there is no
 *    independent "deal total" field to keep in sync. deals.property_value /
 *    total_commission are always exactly the SUM of every linked property's
 *    own allocated_price / allocated_commission. A "the numbers don't
 *    balance" state is structurally impossible: the total is derived, never
 *    entered.
 *
 * Direction of truth:
 *  - While a deal has 0 or 1 linked properties, deals.property_value /
 *    total_commission are the historical, manually-entered source of truth
 *    (the existing single-property capture form, completely unchanged) and
 *    are mirrored ONTO that one property's allocation automatically —
 *    Deal::syncPrimaryPropertyPivot() does this, an agent never sees it.
 *  - The moment a SECOND property is linked, the direction reverses: each
 *    property's own allocated_price/allocated_commission becomes the
 *    source of truth (entered via addProperty()/updatePropertyPrice()), and
 *    recalculateTotals() below keeps deals.property_value/total_commission
 *    as their sum. The main capture form's price fields become read-only at
 *    that point (resources/views/dr2/create.blade.php) — editing an
 *    individual property's price is the only path once multi-property.
 */
class DealPropertyPricingService
{
    /**
     * Re-sum every active (non-trashed) linked property's allocation onto
     * the deal's own property_value/total_commission. No-op on a deal with
     * zero linked properties (nothing to sum; the manually-entered totals
     * from a genuinely propertyless capture stay exactly as entered).
     * saveQuietly() deliberately — this is a DERIVED write, not a fresh
     * user action, so it must not re-trigger Deal::booted()'s hooks (which
     * would try to re-mirror this very total back onto a property and
     * create a feedback loop).
     */
    public function recalculateTotals(Deal $deal): void
    {
        $rows = DealProperty::where('deal_id', $deal->id)->whereNull('deleted_at')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $priceSum = $rows->sum(fn (DealProperty $row) => (float) ($row->allocated_price ?? 0));
        $commissionSum = $rows->sum(fn (DealProperty $row) => (float) ($row->allocated_commission ?? 0));

        $deal->forceFill([
            'property_value' => $priceSum,
            'total_commission' => $commissionSum,
        ])->saveQuietly();
    }
}
