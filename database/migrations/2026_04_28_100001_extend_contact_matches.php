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
            if (!Schema::hasColumn('contact_matches', 'agency_id')) {
                $table->foreignId('agency_id')->nullable()->after('id')->constrained('agencies')->nullOnDelete();
            }
            if (!Schema::hasColumn('contact_matches', 'name')) {
                $table->string('name')->nullable()->after('created_by_user_id');
            }
            if (!Schema::hasColumn('contact_matches', 'status')) {
                $table->string('status', 20)->default('active')->after('listing_type');
            }
            if (!Schema::hasColumn('contact_matches', 'suburbs')) {
                $table->json('suburbs')->nullable()->after('suburb');
            }
            if (!Schema::hasColumn('contact_matches', 'must_have_features')) {
                $table->json('must_have_features')->nullable()->after('suburbs');
            }
            if (!Schema::hasColumn('contact_matches', 'nice_to_have_features')) {
                $table->json('nice_to_have_features')->nullable()->after('must_have_features');
            }
            if (!Schema::hasColumn('contact_matches', 'last_engaged_at')) {
                $table->timestamp('last_engaged_at')->nullable()->after('property_view_counts');
            }
            if (!Schema::hasColumn('contact_matches', 'auto_archive_at')) {
                $table->date('auto_archive_at')->nullable()->after('last_engaged_at');
            }
        });

        // Backfill agency_id from contact (portable across MySQL & SQLite test DBs)
        DB::table('contact_matches')->whereNull('agency_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $agencyId = DB::table('contacts')->where('id', $row->contact_id)->value('agency_id');
                if ($agencyId) {
                    DB::table('contact_matches')->where('id', $row->id)->update(['agency_id' => $agencyId]);
                }
            }
        });

        Schema::table('contact_matches', function (Blueprint $table) {
            $table->index(['agency_id', 'status'], 'cm_agency_status_idx');
            $table->index(['contact_id', 'status'], 'cm_contact_status_idx');
            $table->index(['price_min', 'price_max'], 'cm_price_idx');
            $table->index('listing_type', 'cm_listing_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropIndex('cm_agency_status_idx');
            $table->dropIndex('cm_contact_status_idx');
            $table->dropIndex('cm_price_idx');
            $table->dropIndex('cm_listing_type_idx');
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn([
                'name', 'status', 'suburbs',
                'must_have_features', 'nice_to_have_features',
                'last_engaged_at', 'auto_archive_at',
            ]);
        });
    }
};
