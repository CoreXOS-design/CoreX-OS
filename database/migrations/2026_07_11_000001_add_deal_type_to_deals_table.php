<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-217 (DR2) — add `deal_type` to the shared `deals` table.
 *
 * Johan's ruling: cash-vs-bond is DEAL truth, not pipeline metadata (one-truth
 * doctrine), and DR2 "expands safely on the same tables". So deal_type is a
 * nullable, additive column: DR1 ignores it gracefully (legacy rows stay NULL),
 * the DR2 capture's compulsory radios write it, and the pipeline layer DEFAULTS
 * its template from it (deal_type -> agency default template of that type).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deals', 'deal_type')) {
            Schema::table('deals', function (Blueprint $table) {
                // enum kept in sync with the DR2 capture radios + deals_v2 vocabulary.
                $table->enum('deal_type', ['bond', 'cash', 'sale_of_2nd'])
                    ->nullable()
                    ->after('deal_date')
                    ->comment('DR2 capture: cash/bond/sale-of-2nd. Nullable — legacy DR1 rows stay NULL.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deals', 'deal_type')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->dropColumn('deal_type');
            });
        }
    }
};
