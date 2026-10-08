<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §51 — the rules the 8 Oct 2026 walk turned into agency settings. Every column is
 * nullable and read at run time with a default (RentalInspectionSetting::INSPECTION_RULES): null means "use the
 * recommended default", never a default baked into the schema. Plus the append-only ledger that makes the signing-window
 * reminder idempotent (one row per inspection and milestone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('signing_window_reminders_enabled')->nullable();
            $table->unsignedSmallInteger('signing_reminder_lead_days')->nullable();
            $table->boolean('calendar_include_lease_agents')->nullable();
            $table->boolean('cancel_signed_requires_edit')->nullable();
            $table->boolean('photos_required_to_sign')->nullable();
            $table->boolean('empty_checklist_blocks_signing')->nullable();
            $table->boolean('routine_follows_full_checks')->nullable();
            $table->boolean('hold_pdf_when_refused')->nullable();
            $table->boolean('report_shows_agency_branding')->nullable();
        });

        Schema::create('rental_inspection_signing_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('rental_inspection_id');
            $table->string('milestone', 20); // lead | passed
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('channel', 40)->nullable();
            $table->string('status', 20); // sent | skipped | failed
            $table->string('detail', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['rental_inspection_id', 'milestone'], 'rental_insp_signing_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_signing_notices');
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn([
                'signing_window_reminders_enabled', 'signing_reminder_lead_days', 'calendar_include_lease_agents',
                'cancel_signed_requires_edit', 'photos_required_to_sign', 'empty_checklist_blocks_signing',
                'routine_follows_full_checks', 'hold_pdf_when_refused', 'report_shows_agency_branding',
            ]);
        });
    }
};
