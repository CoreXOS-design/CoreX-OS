<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P24 IMAP per-agency (#3) — until now P24 email-alert ingestion read a
 * single global mailbox from .env (services.p24_imap.*), shared by every
 * agency on the install. This table gives each agency its own IMAP mailbox
 * config, mirroring the agency-scoped credential pattern already proven by
 * communication_mailboxes (AT-32/AT-181): encrypted password, health
 * tracking fields so a broken agency mailbox surfaces honestly instead of
 * silently going stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_p24_imap_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('imap_host')->nullable();
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('imap_encryption', 20)->default('ssl');
            $table->string('imap_folder')->default('INBOX');
            $table->string('username')->nullable();
            $table->text('encrypted_password')->nullable();
            $table->boolean('active')->default(true);

            // Health tracking — mirrors communication_mailboxes' honest-health pattern.
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('agency_id');
            $table->index(['active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_p24_imap_settings');
    }
};
