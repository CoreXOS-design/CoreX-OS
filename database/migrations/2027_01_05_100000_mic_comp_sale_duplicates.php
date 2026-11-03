<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.3 (.ai/specs/mic-work-tab.md sections 7, 8 and 11.1, AT-524): CMA duplicate sales are FLAGGED, never deleted.
 *
 * Overlapping CMA reports repeat the same sale. `duplicate_of_id` points a repeat at the ONE canonical row of that sale
 * (NULL = this row is canonical, or is not a sale row); `dup_checked_at` says the row has been looked at, so the flagger only
 * ever processes new rows. `presentation_sold_comps.copy_of_comp_row_id` marks a presentation comp that is a copy of a CMA comp row
 * (the snapshot hydrator and the agent browse already store the source row id in raw_row_json.mic_comp_row_id) so it is never
 * counted a second time. The (agency, sale_date, sale_price) index is the flagger's bucket lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('p24_suburb_id');
            $table->timestamp('dup_checked_at')->nullable()->after('duplicate_of_id');
            $table->index(['agency_id', 'sale_date', 'sale_price'], 'mrcr_agency_sale_idx');
            $table->index('duplicate_of_id', 'mrcr_duplicate_of_idx');
        });

        Schema::table('presentation_sold_comps', function (Blueprint $table) {
            $table->unsignedBigInteger('copy_of_comp_row_id')->nullable()->after('mic_comp_row_id');
            $table->index('copy_of_comp_row_id', 'psc_copy_of_comp_idx');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_sold_comps', function (Blueprint $table) {
            $table->dropIndex('psc_copy_of_comp_idx');
            $table->dropColumn('copy_of_comp_row_id');
        });

        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->dropIndex('mrcr_duplicate_of_idx');
            $table->dropIndex('mrcr_agency_sale_idx');
            $table->dropColumn(['duplicate_of_id', 'dup_checked_at']);
        });
    }
};
