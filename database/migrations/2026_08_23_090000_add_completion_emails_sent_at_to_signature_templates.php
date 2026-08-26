<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Async e-sign completion (config('docuperfect.async_completion')) — idempotency
 * claim for the completion-email step. sendCompletionEmails() has no persisted
 * duplicate guard; when it can run from a queued, retryable job, an atomic
 * claim ("UPDATE ... WHERE completion_emails_sent_at IS NULL", checking the
 * affected-row count) is what makes a retry or a duplicate dispatch send the
 * client's signed copy at most once instead of re-sending it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->timestamp('completion_emails_sent_at')->nullable()->after('signed_pdf_client_path');
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn('completion_emails_sent_at');
        });
    }
};
