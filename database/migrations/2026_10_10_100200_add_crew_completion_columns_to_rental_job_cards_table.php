<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.27.6 item 5 — how, from
 * where and on what device the worker sign-off ("crew completed") was
 * recorded: `crew_link` / `crew_page` / `signed_copy` / `office`. A crew
 * completion never closes a card; the agent sign-off still does. Sign-offs
 * recorded before this column existed were all made by an office user, so
 * they are back-filled `office`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_job_cards', 'worker_sign_off_via')) {
                $table->string('worker_sign_off_via', 20)->nullable()->after('worker_sign_off_name');
            }
            if (! Schema::hasColumn('rental_job_cards', 'worker_sign_off_ip')) {
                $table->string('worker_sign_off_ip', 45)->nullable()->after('worker_sign_off_via');
            }
            if (! Schema::hasColumn('rental_job_cards', 'worker_sign_off_device')) {
                $table->string('worker_sign_off_device', 255)->nullable()->after('worker_sign_off_ip');
            }
        });

        DB::table('rental_job_cards')
            ->whereNotNull('worker_signed_off_at')
            ->whereNull('worker_sign_off_via')
            ->update(['worker_sign_off_via' => 'office']);
    }

    public function down(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropColumn(['worker_sign_off_via', 'worker_sign_off_ip', 'worker_sign_off_device']);
        });
    }
};
