<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buyer "won" state (Johan 2026-08-13, final DR2 build item) — when a buyer is linked to a
 * property (buyer↔property link, incl. a DR2 deal's buyer party), the buyer is marked WON and
 * moved out of the active pipeline into the success section. `contacts.buyer_state` is a varchar
 * (no DB enum) so 'won' needs no column change; but `buyer_state_transitions.reason` IS a MySQL
 * ENUM, so the new transition reason 'property_linked' must be added before the audit row can save.
 */
return new class extends Migration
{
    private array $newReasons = ['auto_recompute', 'manual_override', 'first_activity', 'wishlist_created', 'auto_landed', 'property_linked'];
    private array $oldReasons = ['auto_recompute', 'manual_override', 'first_activity', 'wishlist_created', 'auto_landed'];

    public function up(): void
    {
        $enum = "'" . implode("','", $this->newReasons) . "'";
        DB::statement("ALTER TABLE buyer_state_transitions MODIFY reason ENUM($enum) NOT NULL");
    }

    public function down(): void
    {
        // Map the new reason onto a legacy value so narrowing the enum is safe.
        DB::table('buyer_state_transitions')->where('reason', 'property_linked')->update(['reason' => 'manual_override']);
        $enum = "'" . implode("','", $this->oldReasons) . "'";
        DB::statement("ALTER TABLE buyer_state_transitions MODIFY reason ENUM($enum) NOT NULL");
    }
};
