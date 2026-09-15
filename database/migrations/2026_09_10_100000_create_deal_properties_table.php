<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-398 — DR2 multi-property. `deals.property_id` stays exactly what it is
 * today (the PRIMARY property — every existing commission/reporting/analytics
 * consumer keeps reading it unchanged). This table is the real link: EVERY
 * deal-property relationship, including the primary, gets a row here, so the
 * six status-sync listeners and the grant-exclusivity service have ONE place
 * to read regardless of whether a deal predates this feature or not.
 *
 * Deliberately NO unique(deal_id, property_id) index — combining a hard
 * unique constraint with SoftDeletes is the exact landmine BUILD_STANDARD.md
 * §5a warns about (a property removed-then-re-added would collide on the
 * trashed row instead of restoring it). Uniqueness among ACTIVE links is
 * enforced in the model/service layer, which restores a trashed row instead
 * of inserting a duplicate when a property is re-added.
 *
 * is_primary is not a preference — it is which row deals.property_id mirrors.
 * Exactly one non-trashed row per deal has is_primary=true; kept in application
 * code (DealPropertyService), not a DB constraint, for the same reason above.
 *
 * allocated_price / allocated_commission (§ two-way balancing entry) are
 * nullable per-property splits. deals.property_value / .total_commission
 * remain the whole-deal figures every existing reporting consumer reads.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('deal_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('allocated_price')->nullable();
            $table->decimal('allocated_commission', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deal_id']);
            $table->index(['property_id']);
        });

        // Backfill: every EXISTING deal with a property_id gets a mirrored row
        // here, is_primary=true, so old single-property deals and new
        // multi-property deals are queried identically from this point on.
        $now = now();
        DB::table('deals')
            ->whereNotNull('property_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select('id', 'property_id')
            ->chunkById(500, function ($deals) use ($now) {
                $rows = $deals->map(fn ($d) => [
                    'deal_id' => $d->id,
                    'property_id' => $d->property_id,
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                if ($rows) {
                    DB::table('deal_properties')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_properties');
    }
};
