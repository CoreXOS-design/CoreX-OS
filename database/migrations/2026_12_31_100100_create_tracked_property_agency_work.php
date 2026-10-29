<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane B step 2 (property-identity.md section 12): the agency's own work record per tracked property.
 *
 * A tracked property is already one agency's row, so the private columns on it (owner, deeds, promotion) stay where they are - this record
 * holds what is NEW per agency: the prospecting status with its reason, the own-stock flag, the current claim at a glance, and a dated history
 * of changes to them. Unique per agency + tracked property.
 *
 * tracked_property_owners (names + ID numbers from the paid deeds capture) gains its own agency column so it is scoped like everything else
 * private, not only through its parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_property_agency_work', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('tracked_property_id');
            $t->string('prospecting_status', 30)->nullable();
            $t->string('prospecting_reason', 500)->nullable();
            $t->unsignedBigInteger('status_set_by_user_id')->nullable();
            $t->timestamp('status_set_at')->nullable();
            $t->boolean('is_own_stock')->default(false);
            $t->unsignedBigInteger('own_stock_property_id')->nullable();
            $t->string('own_stock_source', 20)->nullable();
            $t->timestamp('own_stock_synced_at')->nullable();
            $t->unsignedBigInteger('claimed_by_user_id')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['agency_id', 'tracked_property_id'], 'tpaw_agency_tp_unique');
            $t->index(['agency_id', 'prospecting_status'], 'tpaw_agency_status_idx');
            $t->index(['agency_id', 'is_own_stock'], 'tpaw_agency_own_idx');
            $t->index(['agency_id', 'claimed_by_user_id'], 'tpaw_agency_claimer_idx');
            $t->foreign('agency_id', 'tpaw_agency_fk')->references('id')->on('agencies')->restrictOnDelete();
            $t->foreign('tracked_property_id', 'tpaw_tp_fk')->references('id')->on('tracked_properties')->cascadeOnDelete();
        });

        Schema::create('tracked_property_agency_work_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('tracked_property_id');
            $t->string('kind', 20);              // status | own_stock | claim
            $t->string('from_value', 100)->nullable();
            $t->string('to_value', 100)->nullable();
            $t->string('reason', 500)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['agency_id', 'tracked_property_id', 'occurred_at'], 'tpawe_agency_tp_when_idx');
            $t->foreign('agency_id', 'tpawe_agency_fk')->references('id')->on('agencies')->restrictOnDelete();
            $t->foreign('tracked_property_id', 'tpawe_tp_fk')->references('id')->on('tracked_properties')->cascadeOnDelete();
        });

        if (! Schema::hasColumn('tracked_property_owners', 'agency_id')) {
            Schema::table('tracked_property_owners', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }
        DB::statement('UPDATE tracked_property_owners o JOIN tracked_properties tp ON tp.id = o.tracked_property_id SET o.agency_id = tp.agency_id WHERE o.agency_id IS NULL');
        DB::statement('ALTER TABLE tracked_property_owners MODIFY agency_id BIGINT UNSIGNED NOT NULL');
        Schema::table('tracked_property_owners', function (Blueprint $t) {
            $t->index('agency_id', 'tpo_agency_id_index');
            $t->foreign('agency_id', 'tpo_agency_id_foreign')->references('id')->on('agencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $t) {
            $t->dropForeign('tpo_agency_id_foreign');
            $t->dropIndex('tpo_agency_id_index');
            $t->dropColumn('agency_id');
        });
        Schema::dropIfExists('tracked_property_agency_work_events');
        Schema::dropIfExists('tracked_property_agency_work');
    }
};
