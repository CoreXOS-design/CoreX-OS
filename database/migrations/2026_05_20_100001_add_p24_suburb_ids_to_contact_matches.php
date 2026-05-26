<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            if (!Schema::hasColumn('contact_matches', 'p24_suburb_ids')) {
                $table->json('p24_suburb_ids')->nullable()->after('suburbs');
            }
        });

        // Hard cutover: drop the legacy single-string suburb column. The
        // `suburbs` JSON column stays, but is now an auto-synced derivation of
        // p24_suburb_ids → name lookup (handled in the ContactMatch model).
        if (Schema::hasColumn('contact_matches', 'suburb')) {
            Schema::table('contact_matches', function (Blueprint $table) {
                $table->dropColumn('suburb');
            });
        }
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            if (Schema::hasColumn('contact_matches', 'p24_suburb_ids')) {
                $table->dropColumn('p24_suburb_ids');
            }
            if (!Schema::hasColumn('contact_matches', 'suburb')) {
                $table->string('suburb', 150)->nullable()->after('erf_size_max');
            }
        });
    }
};
