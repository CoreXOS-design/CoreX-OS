<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('status');
            $table->json('property_types')->nullable()->after('property_type');
            $table->unsignedTinyInteger('bedrooms_max')->nullable()->after('beds_min');
            $table->json('deal_breakers')->nullable()->after('nice_to_have_features');
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['contact_id', 'is_primary'], 'cm_contact_primary_idx');
        });

        // Backfill 1: For every contact_id, set is_primary=true on the most-recently-updated
        // non-soft-deleted row. With 2 existing rows on 2 distinct contacts, both become primary.
        DB::statement(<<<'SQL'
            UPDATE contact_matches cm
            INNER JOIN (
                SELECT contact_id, MAX(updated_at) AS max_updated
                FROM contact_matches
                WHERE deleted_at IS NULL
                GROUP BY contact_id
            ) latest
                ON latest.contact_id = cm.contact_id
                AND latest.max_updated = cm.updated_at
            SET cm.is_primary = TRUE
            WHERE cm.deleted_at IS NULL
        SQL);

        // Backfill 2: For every row with non-null property_type, mirror it into property_types
        // as a single-element JSON array. With audit data this affects zero rows.
        DB::statement(<<<'SQL'
            UPDATE contact_matches
            SET property_types = JSON_ARRAY(property_type)
            WHERE property_type IS NOT NULL
              AND property_types IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropForeign(['updated_by_user_id']);
            $table->dropIndex('cm_contact_primary_idx');
            $table->dropColumn([
                'updated_by_user_id',
                'deal_breakers',
                'bedrooms_max',
                'property_types',
                'is_primary',
            ]);
        });
    }
};
