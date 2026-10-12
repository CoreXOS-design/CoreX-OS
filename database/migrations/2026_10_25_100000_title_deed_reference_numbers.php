<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-450 round 4 - a unique reference number on every title deed (spec section 18). Additive only: two columns, a per-agency counter
 * table, the prefix setting, and a backfill that numbers every EXISTING deed (archived ones too) per agency in the order it was logged.
 *
 *   title_deeds.reference_no   the agency's sequence: 1, 2, 3 ... (never reused)
 *   title_deeds.reference      the printed text as it was issued: "TD-00042" (prefix at the time + "-" + the sequence padded to 5 digits)
 *   title_deed_sequences       one locked counter row per agency (SELECT ... FOR UPDATE inside the creating transaction)
 *   title_deed_settings.reference_prefix   default "TD" (NULL = default)
 *
 * Unique (agency_id, reference_no) and (agency_id, reference) are the DB-level backstop behind the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('title_deeds', function (Blueprint $table) {
            $table->unsignedInteger('reference_no')->nullable()->after('agency_id');
            $table->string('reference', 30)->nullable()->after('reference_no');
        });

        Schema::create('title_deed_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });

        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->string('reference_prefix', 10)->nullable();
        });

        // Backfill: per agency, in the order the deeds were logged (created_at, then id) - archived deeds included, so no number is ever handed out
        // twice. Nothing is deleted or changed except the two new columns.
        app(\App\Services\TitleDeeds\TitleDeedReferenceService::class)->backfillExisting('TD');

        Schema::table('title_deeds', function (Blueprint $table) {
            $table->unique(['agency_id', 'reference_no'], 'td_agency_reference_no_unique');
            $table->unique(['agency_id', 'reference'], 'td_agency_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('title_deeds', function (Blueprint $table) {
            $table->dropUnique('td_agency_reference_no_unique');
            $table->dropUnique('td_agency_reference_unique');
            $table->dropColumn(['reference_no', 'reference']);
        });
        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->dropColumn('reference_prefix');
        });
        Schema::dropIfExists('title_deed_sequences');
    }
};
