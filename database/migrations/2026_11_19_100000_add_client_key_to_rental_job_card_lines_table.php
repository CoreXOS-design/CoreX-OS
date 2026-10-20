<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.43 (19 Oct 2026, audit C-E4/C-E5): a line add carries a retry key, so a double tap, a retried
 * request or a crew add queued on a phone and replayed hours later finds the line it already created instead of making a second one.
 *
 * The key used to live in the cache for 60 minutes (and was lost on every cache:clear / deploy); it is now stored ON the line, scoped to
 * its job card. Nullable, so every existing line and every caller without a key is untouched; the unique index lets many NULLs coexist
 * and is the last line of defence if two identical adds ever reach the database at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->string('client_key', 64)->nullable()->after('rental_work_order_variation_id');
            $table->unique(['rental_job_card_id', 'client_key'], 'rjcl_card_client_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->dropUnique('rjcl_card_client_key_unique');
            $table->dropColumn('client_key');
        });
    }
};
