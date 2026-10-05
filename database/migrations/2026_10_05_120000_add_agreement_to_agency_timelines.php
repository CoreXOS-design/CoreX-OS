<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — the linked CoreX contract (a platform e-sign document, signature_templates.id)
 * whose completion ticks the timeline's "contract signed" step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_timelines', function (Blueprint $table) {
            $table->unsignedBigInteger('agreement_template_id')->nullable()->after('status');
            $table->index('agreement_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('agency_timelines', function (Blueprint $table) {
            $table->dropIndex(['agreement_template_id']);
            $table->dropColumn('agreement_template_id');
        });
    }
};
