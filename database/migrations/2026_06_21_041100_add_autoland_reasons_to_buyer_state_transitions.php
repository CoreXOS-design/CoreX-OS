<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-72 — extend buyer_state_transitions.reason ENUM with the auto-land reasons.
 *
 * The original ENUM (2026_05_05_000020_buyer_crm_foundation) only allowed
 * 'auto_recompute', 'manual_override', 'first_activity'. AT-72 lands buyers on
 * the pipeline from a wishlist (ContactMatchObserver::created → 'wishlist_created')
 * and from the backfill command ('auto_landed'); both must be storable so the
 * audit trail records the auto-land transition.
 *
 * Raw ALTER (no doctrine/dbal dependency). Down() narrows the enum back, after
 * normalising any rows already written with the new reasons so the rollback
 * cannot truncate-fail.
 */
return new class extends Migration
{
    private array $newReasons = ['auto_recompute', 'manual_override', 'first_activity', 'wishlist_created', 'auto_landed'];
    private array $oldReasons = ['auto_recompute', 'manual_override', 'first_activity'];

    public function up(): void
    {
        $enum = $this->enumList($this->newReasons);
        DB::statement("ALTER TABLE buyer_state_transitions MODIFY reason ENUM($enum) NOT NULL");
    }

    public function down(): void
    {
        // Map AT-72 reasons onto a legacy value so narrowing the enum is safe.
        DB::table('buyer_state_transitions')
            ->whereIn('reason', ['wishlist_created', 'auto_landed'])
            ->update(['reason' => 'first_activity']);

        $enum = $this->enumList($this->oldReasons);
        DB::statement("ALTER TABLE buyer_state_transitions MODIFY reason ENUM($enum) NOT NULL");
    }

    private function enumList(array $values): string
    {
        return implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
    }
};
