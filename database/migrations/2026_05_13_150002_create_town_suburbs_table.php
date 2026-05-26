<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('town_suburbs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('town_id');
            $table->string('suburb_name', 150);
            // Lowercase + trimmed for fast lookup against wishlist json (spec §4.2).
            $table->string('suburb_normalised', 150);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->foreign('town_id')
                ->references('id')->on('towns')
                ->cascadeOnDelete();

            // UNIQUE on (agency_id, suburb_normalised) prevents an agency from
            // mapping the same suburb to two different towns. The agency-town
            // covering index sits alongside it.
            $table->unique(['agency_id', 'suburb_normalised'], 'town_suburbs_agency_norm_unique');
            $table->index(['agency_id', 'town_id'], 'town_suburbs_agency_town_idx');
            $table->index('deleted_at', 'town_suburbs_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('town_suburbs', function (Blueprint $table) {
            // FKs first, then indexes — MySQL ordering requirement.
            $table->dropForeign(['town_id']);
            $table->dropForeign(['agency_id']);
            $table->dropUnique('town_suburbs_agency_norm_unique');
            $table->dropIndex('town_suburbs_agency_town_idx');
            $table->dropIndex('town_suburbs_deleted_idx');
        });
        Schema::dropIfExists('town_suburbs');
    }
};
