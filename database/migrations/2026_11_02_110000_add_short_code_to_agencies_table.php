<?php

use App\Support\AgencyShortCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The letters that start an agency's own document numbers (payslips: "HFC-202610-001") - the agency's short code.
 * It used to be the literal "HFC-" in code. Every existing agency gets a code worked out from its own name, oldest first so the
 * first agency keeps the plain version ("Home Finders Coastal" -> HFC, so its numbering is exactly what it was); a clash with an
 * earlier agency gets a number added. Numbers already issued are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'short_code')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('short_code', 10)->nullable()->unique();
            });
        }

        foreach (DB::table('agencies')->whereNull('short_code')->orderBy('id')->get(['id', 'name']) as $agency) {
            DB::table('agencies')->where('id', $agency->id)->update([
                'short_code' => AgencyShortCode::unique(AgencyShortCode::fromName($agency->name), (int) $agency->id),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'short_code')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropUnique(['short_code']);
                $table->dropColumn('short_code');
            });
        }
    }
};
