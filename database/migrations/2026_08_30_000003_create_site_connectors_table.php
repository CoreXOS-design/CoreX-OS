<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site connectors (AT-383) — the ONE credential the CoreX marketing website uses to
 * talk to CoreX OS.
 *
 * Spec: .ai/specs/webinar-registration.md §3.3
 *
 * A structural twin of `demo_connectors`, and deliberately a SEPARATE TABLE rather
 * than a reused row or a shared "connector" table:
 *
 *   - NOT an AgencyApiKey. That guard resolves an AGENCY from the key and hands it
 *     to AgencyScope as the tenant. Webinar registrations are RR Technologies' sales
 *     data, not an agency's — pinning them to an arbitrary agency would be a lie in
 *     the data model, and it would put a grantable webinar scope in the per-agency
 *     key UI where an agency admin could be handed it.
 *
 *   - NOT the demo connector. Reusing that token would let the marketing website
 *     call the demo CONTROL API (verify / session / page-view) — a public-facing
 *     brochure site holding the credential that opens demo sessions. Two audiences,
 *     two credentials, two blast radii. Rotating one must never disturb the other.
 *
 * Rotation is INSERT + revoke-the-old, never UPDATE, so this table is also the audit
 * trail of every token the website has ever held and who minted it. At most one row
 * is un-revoked; that row is the active connector.
 *
 * The plaintext is NEVER stored — only sha256(secret), the same construction
 * DemoConnector and AgencyApiKey use. It is shown exactly once, at mint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_connectors')) {
            return;
        }

        Schema::create('site_connectors', function (Blueprint $table) {
            $table->id();

            // Human label, e.g. "CoreX Website". Purely for the admin list.
            $table->string('name')->default('Site connector');

            // Public half of the token — safe to display. Format: cx_site_xxxxxxxx
            $table->string('key_prefix')->unique();

            // sha256 of the secret half. The plaintext is shown exactly once.
            $table->string('secret_hash');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('revoked_at', 'site_connectors_revoked_idx');

            $table->foreign('created_by', 'site_connectors_creator_fk')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connectors');
    }
};
