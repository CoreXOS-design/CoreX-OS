<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('towns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->string('region', 100)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();

            $table->unique(['agency_id', 'slug'], 'towns_agency_slug_unique');
            $table->index(['agency_id', 'display_order'], 'towns_agency_order_idx');
            $table->index('deleted_at', 'towns_deleted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            // FK first, then indexes — MySQL requires this ordering.
            $table->dropForeign(['agency_id']);
            $table->dropUnique('towns_agency_slug_unique');
            $table->dropIndex('towns_agency_order_idx');
            $table->dropIndex('towns_deleted_at_idx');
        });
        Schema::dropIfExists('towns');
    }
};
