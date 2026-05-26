<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->foreignId('tracked_property_id')
                  ->nullable()
                  ->after('matched_property_id')
                  ->constrained('tracked_properties')
                  ->nullOnDelete();
            $table->index('tracked_property_id', 'idx_prospecting_listings_tracked');
        });

        if (Schema::hasTable('portal_listings')) {
            Schema::table('portal_listings', function (Blueprint $table) {
                $table->foreignId('tracked_property_id')
                      ->nullable()
                      ->constrained('tracked_properties')
                      ->nullOnDelete();
                $table->index('tracked_property_id', 'idx_portal_listings_tracked');
            });
        }
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropForeign(['tracked_property_id']);
            $table->dropIndex('idx_prospecting_listings_tracked');
            $table->dropColumn('tracked_property_id');
        });
        if (Schema::hasTable('portal_listings')) {
            Schema::table('portal_listings', function (Blueprint $table) {
                $table->dropForeign(['tracked_property_id']);
                $table->dropIndex('idx_portal_listings_tracked');
                $table->dropColumn('tracked_property_id');
            });
        }
    }
};
