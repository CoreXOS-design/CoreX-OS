<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            if (!Schema::hasColumn('agencies', 'pp_locations_synced_at')) {
                $t->timestamp('pp_locations_synced_at')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'pp_locations_last_error')) {
                $t->text('pp_locations_last_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            if (Schema::hasColumn('agencies', 'pp_locations_synced_at')) {
                $t->dropColumn('pp_locations_synced_at');
            }
            if (Schema::hasColumn('agencies', 'pp_locations_last_error')) {
                $t->dropColumn('pp_locations_last_error');
            }
        });
    }
};
