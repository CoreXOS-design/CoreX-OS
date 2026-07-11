<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — the UNIVERSAL demo connector credential.
 *
 * Spec: .ai/specs/demo-access-control.md §5.1
 *
 * ONE credential, platform-wide. Deliberately NOT an AgencyApiKey:
 *
 *   - AgencyApiKey is AGENCY-SCOPED. It exists to authenticate one agency's public
 *     website and to resolve that agency as the tenant for AgencyScope. Demo access
 *     grants are NOT tenant data — they belong to RR Technologies' sales process.
 *     Hanging the demo's credential off an arbitrary agency would be semantically
 *     wrong, and it would put a "demo" scope in the Role-Manager-adjacent surface
 *     where an agency admin could be handed it.
 *   - There will only ever be ONE demo instance, so a per-agency key is a
 *     one-to-many answer to a one-to-one question.
 *
 * Rotation is INSERT + revoke, never UPDATE — so `demo_connectors` is also the audit
 * trail of every credential the demo has ever held, and who minted it. At most one
 * row is un-revoked at a time; that row is the active connector.
 *
 * The plaintext token is NEVER stored — only sha256(secret), the same construction
 * AgencyApiKey uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_connectors')) {
            return;
        }

        Schema::create('demo_connectors', function (Blueprint $table) {
            $table->id();

            // Human label, e.g. "CoreX Demo Host". Purely for the admin list.
            $table->string('name')->default('Demo connector');

            // Public half of the token — safe to display. Format: cx_demo_xxxxxxxx
            $table->string('key_prefix')->unique();

            // sha256 of the secret half. The plaintext is shown exactly once.
            $table->string('secret_hash');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('revoked_at', 'demo_connectors_revoked_idx');

            $table->foreign('created_by', 'demo_connectors_creator_fk')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_connectors');
    }
};
