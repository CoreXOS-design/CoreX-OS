<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. Breach-notice /
 * notice-to-vacate templates, agency-owned, CRUD with archive/restore (no
 * hard delete — non-negotiable #1). Deliberately self-contained — NOT the
 * Docuperfect e-sign Template model: notices are sent, not counter-signed,
 * and the e-sign recipient-signing pipeline is pipeline-gated (CLAUDE.md)
 * — reusing it here would pull a one-way "send and log" feature through
 * multi-party signing machinery it doesn't need. `body_html` uses the same
 * {{token}} placeholder convention already established for fault-type
 * first-aid content (rental_fault_type_documents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_notice_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('notice_type', 30); // breach | notice_to_vacate
            $table->longText('body_html');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'notice_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_notice_templates');
    }
};
