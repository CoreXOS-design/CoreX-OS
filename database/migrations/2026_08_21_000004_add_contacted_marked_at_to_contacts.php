<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-372 — "Contacted" is an explicit signal, never a side effect of a pitch.
 *
 * last_contacted_at is now DERIVED = max(contacted_marked_at, latest sent-comm
 * occurred_at). This column holds the explicit "agent marked contacted" timestamp
 * (Mark as Now / Pick Date / Mark contacted + note), so an explicit mark is a
 * first-class signal that survives recomputeLastContacted() instead of being wiped
 * by the next send's recompute (which only ever looked at sent comms before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'contacted_marked_at')) {
                $table->timestamp('contacted_marked_at')->nullable()->after('last_contacted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'contacted_marked_at')) {
                $table->dropColumn('contacted_marked_at');
            }
        });
    }
};
