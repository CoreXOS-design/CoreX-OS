<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-142 — agency policy toggle: restrict consent outreach to full-status
 * practitioners. Default OFF (any non-blank designation may send, message is
 * truthful via {agent_designation}). When ON, the composer blocks a consent
 * send unless the sending agent is a full-status practitioner or principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('restrict_consent_outreach_to_full_status')
                ->default(false)
                ->after('outreach_queue_daily_cap_per_agent')
                ->comment('AT-142 — when on, only full-status practitioners/principals may send consent-outreach templates');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('restrict_consent_outreach_to_full_status');
        });
    }
};
