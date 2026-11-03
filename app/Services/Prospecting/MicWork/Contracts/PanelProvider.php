<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork\Contracts;

use App\Models\User;

/**
 * Data for the Work tab panels that open from tile 10 (neighbours of a sale) and tile 11 (market data last refreshed). Tagged `mic.work.panels`.
 * Each method returns plain rows the panel prints one per line; empty list = real empty state. Agency-bound.
 */
interface PanelProvider
{
    /** Tile number this panel belongs to (10 or 11). */
    public function tile(): int;

    /**
     * Tile 10: trigger properties newest first. $filters = ['months' => 0 (this month) | 1..6, 'type' => registered|sold|under_offer|our_sale|null, 'suburb' => ?string].
     * Tile 11: one row per suburb the agency works, oldest first; $filters = ['scope' => 'mine'|'all'].
     *
     * @return list<array<string, mixed>>
     *   tile 10 row: id, type, address, suburb, property_type, price, date
     *   tile 11 row: suburb, p24_read_at (ISO|null), pp_read_at (ISO|null), age_days (int|null), overdue (bool), p24_url, pp_url
     */
    public function rows(int $agencyId, User $user, array $filters): array;

    /**
     * Tile 10 only: the homes around trigger $triggerId. Row: address, owner (own agency's deeds only), on_market (bool), last_contact (?string), property_id (tracked property id).
     *
     * @return list<array<string, mixed>>
     */
    public function around(int $agencyId, User $user, string $triggerId): array;
}
