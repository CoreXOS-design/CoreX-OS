<?php

namespace App\Support\Presentations;

use App\Models\PresentationSoldComp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the comp whitelist (`included_comp_ids_json`) the engine and the
 * Review screen must BOTH use, so a stored selection can never leave a
 * presentation without a price (Johan, 2026-10-07: "there should always be a
 * price, that's the whole point").
 *
 * Whitelist meaning, after this class:
 *   null   → no opinion — every live comp is in the pool.
 *   [ids]  → only those live comps.
 *   []     → NOT a valid agent decision any more (Review refuses an empty
 *            selection), so a stored [] is treated exactly like null.
 *
 * A non-empty selection that no longer reaches a single priced live comp
 * (every pick was retired by a re-hydration, or only unpriced comps are
 * ticked) is first carried onto the fresh copy of the same sales
 * (CompSelectionCarryForward); if nothing survives it falls back to null —
 * all live comps. Every repair is logged `[PRES-WARN]`. The caller gets the
 * resolved whitelist plus the reason, so the screen can say so.
 *
 * Nothing is written here. This is a pure read-time resolution; the stored
 * column is left alone (existing data is never touched by a read).
 */
final class CompSelectionRepair
{
    public const REPAIRED_EMPTY    = 'empty';     // stored [] → all comps
    public const REPAIRED_RETIRED  = 'retired';   // every pick retired → carried over or all comps
    public const REPAIRED_UNPRICED = 'unpriced';  // picks exist but none has a sold price → all comps

    /**
     * @param  array<int|string>|null                $whitelist  version->included_comp_ids_json
     * @param  Collection<int, PresentationSoldComp>  $liveComps  the presentation's live (non-deleted) comps
     * @return array{whitelist: array<int>|null, repaired: string|null}
     */
    public static function resolve(int $presentationId, ?array $whitelist, Collection $liveComps): array
    {
        if ($whitelist === null) {
            return ['whitelist' => null, 'repaired' => null];
        }
        $ids = array_values(array_unique(array_map('intval', $whitelist)));

        $priced = [];
        $liveSet = [];
        foreach ($liveComps as $c) {
            $liveSet[(int) $c->id] = true;
            if ((int) $c->sold_price_inc > 0) {
                $priced[(int) $c->id] = true;
            }
        }
        // Nothing priced to fall back to — leave the selection alone; the
        // readiness check explains "no priced comparable sales".
        if ($priced === []) {
            return ['whitelist' => $ids, 'repaired' => null];
        }

        if ($ids === []) {
            self::log('empty', $presentationId, $ids);
            return ['whitelist' => null, 'repaired' => self::REPAIRED_EMPTY];
        }

        foreach ($ids as $id) {
            if (isset($priced[$id])) {
                return ['whitelist' => $ids, 'repaired' => null];
            }
        }

        // No pick reaches a priced live comp. Picks that point at LIVE but
        // unpriced rows cannot be remapped; picks at retired rows can.
        $anyLive = false;
        foreach ($ids as $id) {
            if (isset($liveSet[$id])) {
                $anyLive = true;
                break;
            }
        }
        if (!$anyLive) {
            $remap = CompSelectionCarryForward::remap($presentationId, $ids);
            $carried = array_values(array_filter(
                (array) ($remap['ids'] ?? []),
                fn ($id) => isset($priced[(int) $id]),
            ));
            if ($carried !== []) {
                self::log('retired_carried', $presentationId, $ids);
                return ['whitelist' => $carried, 'repaired' => self::REPAIRED_RETIRED];
            }
            self::log('retired', $presentationId, $ids);
            return ['whitelist' => null, 'repaired' => self::REPAIRED_RETIRED];
        }

        self::log('unpriced', $presentationId, $ids);
        return ['whitelist' => null, 'repaired' => self::REPAIRED_UNPRICED];
    }

    /** @param array<int> $ids */
    private static function log(string $why, int $presentationId, array $ids): void
    {
        Log::warning('[PRES-WARN] comp selection could not produce a price — repaired to the usable comps', [
            'presentation_id' => $presentationId,
            'why'             => $why,
            'stored_ids'      => $ids,
        ]);
    }
}
