<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attorney portal (.ai/specs/attorney-portal.md). One access = one supplier contact at one firm.
 * The link token and the session token are stored ONLY as SHA-256 hashes. The log is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attorney_portal_settings')) {
            Schema::create('attorney_portal_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->unique();
                // Null = the read-time default (AttorneyPortalSetting::DEFAULT_*); nothing is written until an agency changes a value.
                $table->boolean('attorney_portal_enabled')->nullable();
                $table->unsignedSmallInteger('access_grace_days')->nullable();
                $table->unsignedSmallInteger('link_valid_days')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attorney_portal_accesses')) {
            Schema::create('attorney_portal_accesses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('service_provider_id');
                $table->unsignedBigInteger('service_provider_contact_id');
                $table->string('email');
                $table->char('token_hash', 64)->unique();
                $table->unsignedBigInteger('invited_by_id')->nullable();
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by_id')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 64)->nullable();
                $table->unsignedInteger('login_count')->default(0);
                $table->timestamps();

                // One row per supplier contact: a resend rotates the token on the same row.
                $table->unique(['agency_id', 'service_provider_contact_id'], 'atty_access_agency_contact_unique');
                $table->index('service_provider_id');
            });
        }

        if (! Schema::hasTable('attorney_portal_sessions')) {
            Schema::create('attorney_portal_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('access_id');
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('access_id');
            });
        }

        if (! Schema::hasTable('attorney_portal_logs')) {
            Schema::create('attorney_portal_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('access_id')->nullable();
                $table->string('event', 40);
                $table->unsignedBigInteger('deal_id')->nullable();
                $table->unsignedBigInteger('document_id')->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['agency_id', 'access_id', 'created_at'], 'atty_log_access_idx');
                $table->index(['deal_id', 'event']);
                $table->index('document_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['attorney_portal_logs', 'attorney_portal_sessions', 'attorney_portal_accesses', 'attorney_portal_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
