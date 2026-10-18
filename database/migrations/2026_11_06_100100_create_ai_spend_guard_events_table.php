<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part A — the AI Spend Safety history: every alert, cut-out, resume and
 * threshold change, with who and the spend at the time (spec §A.2 "audit entry",
 * §A.4 "every save writes an audit entry", §A.5 "alert/pause/resume history").
 *
 * agency_id NULL = the platform-wide "system" bucket (AI calls with no agency,
 * e.g. the Article Library) or a platform settings change. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_spend_guard_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('type', 20); // alert | pause | resume | settings
            $table->decimal('spend_zar', 10, 4)->default(0);
            $table->decimal('threshold_zar', 10, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['agency_id', 'occurred_at'], 'idx_asge_agency_occurred');
            $table->index(['type', 'occurred_at'], 'idx_asge_type_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_spend_guard_events');
    }
};
