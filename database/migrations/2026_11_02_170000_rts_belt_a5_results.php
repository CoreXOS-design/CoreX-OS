<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, belt step A5 (road-to-sold.md 23.9 / 23.10): ONE result vocabulary.
 *   rts_result_options     NEW - the agency's result list (won / undecided / lost / not selling), seeded per agency on first use; full CRUD with archive / restore
 *   presentation_outcomes  result_key (nullable): the shared result behind the old fixed outcome enum, which stays filled by a fixed map
 * Additive; a re-run changes nothing. No data is moved here (the presentation back-fill is a command with a dry run).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rts_result_options')) {
            Schema::create('rts_result_options', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('track', 20)->default('sales');
                $t->string('key', 40);
                $t->string('label', 120);
                $t->string('class', 20);                       // won | pending | lost | not_selling
                $t->string('park_reason_key', 40)->nullable();
                $t->boolean('needs_date')->default(false);
                $t->unsignedSmallInteger('revisit_days')->nullable();
                $t->boolean('is_system')->default(false);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['agency_id', 'track', 'key'], 'rts_result_options_key_unique');
            });
        }
        if (! Schema::hasColumn('presentation_outcomes', 'result_key')) {
            Schema::table('presentation_outcomes', function (Blueprint $t) {
                $t->string('result_key', 40)->nullable()->after('outcome');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('presentation_outcomes', 'result_key')) {
            Schema::table('presentation_outcomes', function (Blueprint $t) {
                $t->dropColumn('result_key');
            });
        }
        Schema::dropIfExists('rts_result_options');
    }
};
