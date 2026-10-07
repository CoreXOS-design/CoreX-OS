<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §18 — a paper-signed lease's tenants and landlords are emailed their copy (and
 * portal link) ONCE. This is the durable once-only claim, the same idea as signature_templates.completion_emails_sent_at
 * on the e-sign path: set atomically by the run that actually sends, so a double submit, a retry or a re-upload can
 * never mail everybody a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'signed_copy_emailed_at')) {
                $table->timestamp('signed_copy_emailed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (Schema::hasColumn('leases', 'signed_copy_emailed_at')) {
                $table->dropColumn('signed_copy_emailed_at');
            }
        });
    }
};
