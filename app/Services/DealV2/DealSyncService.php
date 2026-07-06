<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use Illuminate\Support\Facades\DB;

/**
 * WS1 (AT-158 / DR2, decision D1) — the single-writer DR1↔DR2 mirror.
 *
 * During the parallel run a DR1 deal (`deals`) and its DR2 twin (`deals_v2`)
 * point at each other (deals.deal_v2_id ↔ deals_v2.legacy_deal_id). This one
 * service owns the invariant for the SHARED CORE FIELDS and mirrors them both
 * ways; thin observers on each model only CALL it (they never write the other
 * side). Follows the ContactIdentifierService pattern: quiet writes
 * (saveQuietly), a re-entrancy guard so a sync can't re-trigger itself,
 * DB::transaction, and idempotency (only writes when a value actually changes,
 * and the status map round-trips).
 *
 * SHARED (mirrored) fields:
 *   status              DR1 derived state ⇄ DR2 status enum (round-trip stable)
 *   commission_status   direct (Paid / Not Paid / Loss)
 *   price               DR1 sale_price ⇄ DR2 purchase_price
 *   commission total    DR1 total_commission (incl VAT) ⇄ DR2 commission_amount + commission_vat (15%)
 *   registration date   DR1 registration_date ⇄ DR2 actual_registration
 *   deal/offer date     DR1 deal_date ⇄ DR2 offer_date
 *   party names         DR2 contacts → DR1 seller_name/buyer_name/attorney_name (derive; one-way)
 *
 * NOT mirrored (by design): DR2 pipeline steps / RAG / distributions never touch
 * DR1 (D1). DR1's free-text party names are NOT pushed into DR2 (which needs real
 * contacts) — that direction, and anything the map can't express, is the
 * reconciliation's job (spec §13.3 / WS8).
 */
class DealSyncService
{
    /** Guards against a sync write re-entering the service via any non-quiet path. */
    private static bool $syncing = false;

    // ── DR1 → DR2 ────────────────────────────────────────────────────────

    public function syncFromV1(Deal $v1): void
    {
        if (self::$syncing || ! $v1->deal_v2_id) {
            return;
        }
        $v2 = DealV2::withoutGlobalScopes()->find($v1->deal_v2_id);
        if (! $v2) {
            return;
        }

        self::$syncing = true;
        try {
            DB::transaction(function () use ($v1, $v2) {
                $dirty = [];

                $status = $this->v1StateToV2Status($v1);
                if ($v2->status !== $status) {
                    $dirty['status'] = $status;
                }
                if ($status === 'completed' && $v1->registration_date) {
                    $dirty['actual_registration'] = $v1->registration_date;
                }

                $price = $v1->sale_price ?: ($v1->property_value ? (int) round((float) $v1->property_value) : null);
                if ($price && (int) $v2->purchase_price !== (int) $price) {
                    $dirty['purchase_price'] = $price;
                }

                if ($v1->total_commission !== null) {
                    [$amount, $vat] = $this->splitInclVat((float) $v1->total_commission);
                    if ((float) $v2->commission_amount !== $amount || (float) $v2->commission_vat !== $vat) {
                        $dirty['commission_amount'] = $amount;
                        $dirty['commission_vat'] = $vat;
                    }
                }

                if ($v1->deal_date && $this->dateChanged($v2->offer_date, $v1->deal_date)) {
                    $dirty['offer_date'] = $v1->deal_date;
                }

                if ($v1->commission_status && $v2->commission_status !== $v1->commission_status) {
                    $dirty['commission_status'] = $v1->commission_status;
                }

                if ($dirty) {
                    $v2->forceFill($dirty)->saveQuietly();
                }
            });
        } finally {
            self::$syncing = false;
        }
    }

    // ── DR2 → DR1 ────────────────────────────────────────────────────────

    public function syncFromV2(DealV2 $v2): void
    {
        if (self::$syncing || ! $v2->legacy_deal_id) {
            return;
        }
        $v1 = Deal::withoutGlobalScopes()->find($v2->legacy_deal_id);
        if (! $v1) {
            return;
        }

        self::$syncing = true;
        try {
            DB::transaction(function () use ($v1, $v2) {
                $dirty = [];

                [$accepted, $regDate, $grantedAt] = $this->v2StatusToV1($v2, $v1);
                if ($v1->accepted_status !== $accepted) {
                    $dirty['accepted_status'] = $accepted;
                }
                if ($regDate !== null && $this->dateChanged($v1->registration_date, $regDate)) {
                    $dirty['registration_date'] = $regDate;
                }
                if ($grantedAt !== null && ! $v1->granted_at) {
                    $dirty['granted_at'] = $grantedAt;
                }

                if ($v2->purchase_price && (int) $v1->sale_price !== (int) $v2->purchase_price) {
                    $dirty['sale_price'] = (int) $v2->purchase_price;
                }

                $incl = round((float) $v2->commission_amount + (float) $v2->commission_vat, 2);
                if ((float) $v1->total_commission !== $incl) {
                    $dirty['total_commission'] = $incl;
                }

                if ($v2->offer_date && $this->dateChanged($v1->deal_date, $v2->offer_date)) {
                    $dirty['deal_date'] = $v2->offer_date;
                }

                if ($v2->commission_status && $v1->commission_status !== $v2->commission_status) {
                    $dirty['commission_status'] = $v2->commission_status;
                }

                foreach ($this->v2PartyNames($v2) as $col => $val) {
                    if ($val !== null && $val !== '' && $v1->{$col} !== $val) {
                        $dirty[$col] = $val;
                    }
                }

                if ($dirty) {
                    $v1->forceFill($dirty)->saveQuietly();
                }
            });
        } finally {
            self::$syncing = false;
        }
    }

    // ── status maps (round-trip stable) ──────────────────────────────────

    /** DR1 derived state → DR2 status. Mirrors Deal::statusSummary derivation. */
    public function v1StateToV2Status(Deal $v1): string
    {
        if (! empty($v1->registration_date) || $v1->accepted_status === 'R') {
            return 'completed';
        }
        if ($v1->accepted_status === 'D') {
            return 'declined'; // WS-V2 — DR1 'D' ↔ DR2 'declined' (distinct terminal state)
        }
        if (! empty($v1->granted_at) || $v1->accepted_status === 'G') {
            return 'granted';
        }
        return 'active';
    }

    /** DR2 status → [accepted_status, registration_date|null, granted_at|null]. */
    public function v2StatusToV1(DealV2 $v2, Deal $v1): array
    {
        return match ($v2->status) {
            'completed' => ['R', $v2->actual_registration?->toDateString() ?? now()->toDateString(), $v1->granted_at ?? now()],
            'granted'   => ['G', null, $v1->granted_at ?? now()],
            'declined'  => ['D', null, null], // WS-V2 — suspensive condition failed
            'cancelled' => ['D', null, null], // withdrawn/cancelled → DR1 has only 'D' for not-proceeding
            default     => ['P', null, null], // active + on_hold (DR1 has no on_hold → pending)
        };
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Split an incl-VAT total into [ex-VAT amount, VAT] at 15%. */
    private function splitInclVat(float $incl): array
    {
        $amount = round($incl / 1.15, 2);
        return [$amount, round($incl - $amount, 2)];
    }

    private function dateChanged($current, $new): bool
    {
        $cur = $current ? \Illuminate\Support\Carbon::parse($current)->toDateString() : null;
        $nw = $new ? \Illuminate\Support\Carbon::parse($new)->toDateString() : null;
        return $cur !== $nw;
    }

    /** Derive DR1 free-text party names from the DR2 contact roles. */
    private function v2PartyNames(DealV2 $v2): array
    {
        $names = fn (array $roles) => $v2->contacts()
            ->wherePivotIn('role', $roles)->get()
            ->map(fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ($c->name ?? null))
            ->filter()->implode(' & ');

        return [
            'seller_name'   => $names(['seller', 'co_seller']) ?: null,
            'buyer_name'    => $names(['buyer', 'co_buyer']) ?: null,
            'attorney_name' => $names(['conveyancer', 'transfer_attorney', 'bond_attorney']) ?: null,
        ];
    }
}
