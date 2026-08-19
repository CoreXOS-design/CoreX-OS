<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Sign P1 (ESIGN-WETINK phase 1e) — the SEALED, append-only, tamper-evident
 * per-version audit trail. "Save each copy as it got signed — not one copy that
 * floats around."
 *
 * Every time a party signs/approves and the document content changes, a row is
 * SEALED here: an immutable snapshot of the canonical (baked-ink) HTML exactly as
 * it stood at that hop, plus a hash chain (content_hash = sha256(prev_hash .
 * sealed_html)) so the whole trail is verifiable and tamper-evident.
 *
 * PURELY ADDITIVE + PASSIVE. Nothing existing is altered — this is a recording
 * layer written alongside the signing pipeline. Distinct from `signed_document_versions`
 * (wet-ink FILE uploads) and `signature_audit_log` (the event log, which now links
 * to a sealed version via metadata_json.sealed_version_id). Write-once: no updated_at,
 * and the model refuses updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sealed_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signature_template_id')->nullable();

            // Per-document monotonic sequence (1,2,3…) — the "copy number".
            $table->unsignedInteger('version');
            // What signing/approval transition sealed this copy.
            $table->string('event_type', 64);
            // Who caused the seal: the party identity/role (e.g. seller_1, supervisor, agent).
            $table->string('signer_identity', 190)->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->string('actor_type', 32)->nullable();   // system | user | signer
            $table->string('actor_name', 190)->nullable();
            $table->string('actor_role', 190)->nullable();

            // The sealed copy — the canonical (baked) HTML exactly as it stood here.
            $table->longText('sealed_html');

            // Tamper-evidence: content_hash = sha256((prev_hash ?? '') . sealed_html).
            $table->char('content_hash', 64);
            $table->char('prev_hash', 64)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable();

            // Write-once — created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'version'], 'doc_sealed_version_unique');
            $table->index(['document_id', 'created_at']);
            $table->index('signature_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sealed_versions');
    }
};
