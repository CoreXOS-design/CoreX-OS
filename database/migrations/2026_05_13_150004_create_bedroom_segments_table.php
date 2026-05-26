<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bedroom_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->string('name', 50);
            $table->unsignedTinyInteger('beds_min');
            // NULL = no upper bound (the 5+ case).
            $table->unsignedTinyInteger('beds_max')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();

            $table->index(['agency_id', 'display_order'], 'bed_seg_agency_order_idx');
            $table->index(['agency_id', 'beds_min', 'beds_max'], 'bed_seg_agency_range_idx');
            $table->index('deleted_at', 'bed_seg_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bedroom_segments', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('bed_seg_agency_order_idx');
            $table->dropIndex('bed_seg_agency_range_idx');
            $table->dropIndex('bed_seg_deleted_idx');
        });
        Schema::dropIfExists('bedroom_segments');
    }
};
