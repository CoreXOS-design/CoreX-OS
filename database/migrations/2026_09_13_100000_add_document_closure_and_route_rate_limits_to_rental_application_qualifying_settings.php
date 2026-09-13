<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-13 (round 2) — two conductor rulings from the same
 * document-throttle incident:
 *
 * 1. cc3's finding while investigating withdraw: an applicant can upload
 *    documents forever, including after withdrawing or being declined —
 *    a public, unauthenticated write into agency storage that never
 *    closes. Withdrawn/declined close uploads unconditionally; approved
 *    stays open by default but is now an agency setting
 *    (document_uploads_open_after_approval) since an agency may
 *    legitimately want one more document from an approved tenant.
 *
 * 2. The remaining five public routes still carried Laravel's stock
 *    per-IP throttle (the exact defect the document-upload fix closed)
 *    — moved to the same per-application-token key, each with its own
 *    agency-configurable max/window, same pattern as
 *    document_rate_limit_max/window_minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('document_uploads_open_after_approval')->nullable()->after('document_rate_limit_window_minutes');

            $table->unsignedInteger('show_rate_limit_max')->nullable()->after('document_uploads_open_after_approval');
            $table->unsignedSmallInteger('show_rate_limit_window_minutes')->nullable()->after('show_rate_limit_max');

            $table->unsignedInteger('submit_rate_limit_max')->nullable()->after('show_rate_limit_window_minutes');
            $table->unsignedSmallInteger('submit_rate_limit_window_minutes')->nullable()->after('submit_rate_limit_max');

            $table->unsignedInteger('pdf_rate_limit_max')->nullable()->after('submit_rate_limit_window_minutes');
            $table->unsignedSmallInteger('pdf_rate_limit_window_minutes')->nullable()->after('pdf_rate_limit_max');

            $table->unsignedInteger('document_view_rate_limit_max')->nullable()->after('pdf_rate_limit_window_minutes');
            $table->unsignedSmallInteger('document_view_rate_limit_window_minutes')->nullable()->after('document_view_rate_limit_max');

            $table->unsignedInteger('autosave_request_rate_limit_max')->nullable()->after('document_view_rate_limit_window_minutes');
            $table->unsignedSmallInteger('autosave_request_rate_limit_window_minutes')->nullable()->after('autosave_request_rate_limit_max');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn([
                'document_uploads_open_after_approval',
                'show_rate_limit_max', 'show_rate_limit_window_minutes',
                'submit_rate_limit_max', 'submit_rate_limit_window_minutes',
                'pdf_rate_limit_max', 'pdf_rate_limit_window_minutes',
                'document_view_rate_limit_max', 'document_view_rate_limit_window_minutes',
                'autosave_request_rate_limit_max', 'autosave_request_rate_limit_window_minutes',
            ]);
        });
    }
};
