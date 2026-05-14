<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_type_options', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();

            $table->unique(['agency_id', 'slug'], 'prop_types_agency_slug_unique');
            $table->index(['agency_id', 'display_order', 'is_active'], 'prop_types_agency_order_active_idx');
            $table->index('deleted_at', 'prop_types_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('property_type_options', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropUnique('prop_types_agency_slug_unique');
            $table->dropIndex('prop_types_agency_order_active_idx');
            $table->dropIndex('prop_types_deleted_idx');
        });
        Schema::dropIfExists('property_type_options');
    }
};
