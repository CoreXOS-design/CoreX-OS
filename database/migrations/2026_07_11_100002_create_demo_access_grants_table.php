<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — the grant. One row per prospect company.
 *
 * Spec: .ai/specs/demo-access-control.md §4.2
 *
 * THREE THINGS THIS SCHEMA ENCODES ON PURPOSE:
 *
 * 1. There is NO `status` column. Status is DERIVED (DemoAccessGrant::status()).
 *    A stored status goes stale the instant expires_at passes with nobody
 *    writing to the row, and a cron that "fixes" statuses is a cron that can
 *    fail silently and let an expired prospect back in.
 *
 * 2. `expires_at` is NULL until first login, and NULL IS NOT EXPIRED. The clock
 *    starts when the prospect actually logs in, not when we issue. Every
 *    predicate must read (expires_at IS NULL OR expires_at > NOW()) — the naive
 *    `expires_at > NOW()` is NULL (falsy) for a fresh grant and locks out every
 *    prospect we just emailed.
 *
 * 3. `expiry_hours` is COPIED onto the row at issue, never referenced from a
 *    setting. If the default later changes from 72 to 24, already-issued grants
 *    keep the length they were sold on. A grant that silently shortens because
 *    someone edited a setting is a broken promise.
 *
 * `archived_at`, not SoftDeletes: "delete" archives, and SELECT COUNT(*) on this
 * table must never decrease (non-negotiable #1). Grants are legal evidence, so
 * the row stays visible to queries unless a caller explicitly excludes it —
 * rather than being hidden from every default query by a global scope.
 *
 * The plaintext access code is NEVER stored. Only bcrypt(code) in credential_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_access_grants')) {
            return;
        }

        Schema::create('demo_access_grants', function (Blueprint $table) {
            $table->id();

            // The prospect company. This is the grant's OWN column — `contacts`
            // has no `company` column and does not gain one.
            $table->string('company_name');

            $table->string('contact_email');
            $table->string('contact_name')->nullable();

            // Optional CRM link (Contact pillar). Nullable: a grant can be
            // issued before the prospect is a contact.
            $table->unsignedBigInteger('contact_id')->nullable();

            // bcrypt() of the 16-char access code. Plaintext exists only in the
            // issue response and the email — never in the DB, never in a log.
            $table->string('credential_hash');

            // Copied at issue, not referenced. See docblock.
            $table->unsignedInteger('expiry_hours');

            // NULL until the first successful login. See docblock.
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();

            // "Delete" sets this. The row is NEVER removed.
            $table->timestamp('archived_at')->nullable();

            $table->unsignedBigInteger('issued_by_user_id');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('contact_email', 'demo_grants_email_idx');
            $table->index(['archived_at', 'revoked_at', 'expires_at'], 'demo_grants_lifecycle_idx');

            $table->foreign('contact_id', 'demo_grants_contact_fk')
                  ->references('id')->on('contacts')
                  ->nullOnDelete();

            $table->foreign('issued_by_user_id', 'demo_grants_issuer_fk')
                  ->references('id')->on('users')
                  ->cascadeOnUpdate();

            $table->foreign('revoked_by_user_id', 'demo_grants_revoker_fk')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_access_grants');
    }
};
