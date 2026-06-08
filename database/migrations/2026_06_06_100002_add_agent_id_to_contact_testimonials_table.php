<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a testimonial to the agent it is about, so agency websites can show the
 * agent on the testimonial and list every testimonial (and listing) on that
 * agent's profile. Defaults to the capturing agent; selectable on capture.
 *
 * Spec: .ai/specs/testimonials.md §3.1, §6.1
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contact_testimonials') || Schema::hasColumn('contact_testimonials', 'agent_id')) {
            return;
        }

        Schema::table('contact_testimonials', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('user_id');
            $table->index(['agency_id', 'agent_id']);
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('contact_testimonials', 'agent_id')) {
            return;
        }

        Schema::table('contact_testimonials', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['agency_id', 'agent_id']);
            $table->dropColumn('agent_id');
        });
    }
};
