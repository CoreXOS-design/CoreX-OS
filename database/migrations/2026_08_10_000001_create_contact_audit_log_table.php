<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-321-C — the contact audit trail table. Mirrors property_audit_log (created by
 * 2026_05_11_132238) plus the AT-321 actor columns, built in from day one:
 *  - actor_type / actor_label / source → every row is attributable even with no
 *    authenticated user (jobs, imports, console, raw writes); NEVER a blank System.
 *  - agency_id is NULLABLE so the unbypassable DB trigger (next migration) can
 *    ALWAYS insert a backstop row without a NOT-NULL/FK constraint ever rolling
 *    back a contact save — a bulletproof bare INSERT (spec §3.2/§3.5).
 *  - SoftDeletes (deleted_at) per Non-Negotiable #1 — the trail is append-only and
 *    never user-deletable, but the model complies with the no-hard-delete rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('actor_type', 24)->nullable();   // user|system|import|console|sync|portal|db-trigger|unknown
            $table->string('actor_label', 120)->nullable();
            $table->string('source', 60)->nullable();
            $table->foreignId('agency_id')->nullable()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('event_category', 40);
            $table->string('event_type', 80);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('human_summary', 255)->nullable();
            $table->timestamp('created_at');
            $table->softDeletes();

            $table->index(['contact_id', 'created_at']);
            $table->index(['contact_id', 'event_category']);
            $table->index(['agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_audit_log');
    }
};
