<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.1 (.ai/specs/mic-work-tab.md section 8, AT-519): ONE suburb list.
 *
 * The suburb list is the existing `p24_suburbs` table and the id is OUR `p24_suburbs.id` - the same id space
 * properties.p24_suburb_id, tracked_properties.p24_suburb_id and contact_matches.p24_suburb_ids already use. This adds the
 * missing column to the two MIC tables that only carried suburb TEXT (prospecting_listings, market_report_comp_rows) and a
 * generic row journal that every data-changing MIC step (5.1 - 5.4) writes, so a run can be rolled back row by row.
 * No foreign key: the P24 suburb list is reseeded by sync jobs, and a NULL means "not resolvable", never "guess".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('p24_suburb_id')->nullable()->after('suburb');
            $table->index(['agency_id', 'p24_suburb_id'], 'pl_agency_p24_suburb_idx');
        });

        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('p24_suburb_id')->nullable()->after('suburb_normalised');
            $table->index(['agency_id', 'p24_suburb_id'], 'mrcr_agency_p24_suburb_idx');
        });

        Schema::create('mic_data_journals', function (Blueprint $table) {
            $table->id();
            $table->char('run_id', 36);
            $table->string('step', 16);
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->string('column_name', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
            $table->index(['table_name', 'row_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_data_journals');

        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->dropIndex('mrcr_agency_p24_suburb_idx');
            $table->dropColumn('p24_suburb_id');
        });

        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('pl_agency_p24_suburb_idx');
            $table->dropColumn('p24_suburb_id');
        });
    }
};
