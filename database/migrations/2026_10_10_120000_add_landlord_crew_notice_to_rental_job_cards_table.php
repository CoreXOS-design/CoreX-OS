<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.28.4 — the landlord's "work completed"
 * email goes out ONCE per job card, on the FIRST crew completion by any route
 * (crew link, crew page or signed copy). This stamp is what makes "once"
 * true: the listener claims it with a single conditional UPDATE, so a
 * corrected signed-copy re-upload — or two completions racing — can never
 * email the landlord a second time.
 *
 * Cards whose crew had already completed (by link, page or signed copy)
 * before this column existed have already had their one landlord email, so
 * they are stamped with that completion time. A card whose only sign-off was
 * the office "Worker — done" button never emailed anyone and is left empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_job_cards', 'landlord_crew_notice_at')) {
                $table->timestamp('landlord_crew_notice_at')->nullable()->after('worker_sign_off_device');
            }
        });

        DB::table('rental_job_cards')
            ->whereNotNull('worker_signed_off_at')
            ->whereIn('worker_sign_off_via', ['crew_link', 'crew_page', 'signed_copy'])
            ->whereNull('landlord_crew_notice_at')
            ->update(['landlord_crew_notice_at' => DB::raw('worker_signed_off_at')]);
    }

    public function down(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropColumn('landlord_crew_notice_at');
        });
    }
};
