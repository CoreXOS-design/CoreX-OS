<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §15.10 M2 (Build L1 — foundation) — a lease gets its own SIGNING state beside
 * `status` (which keeps its meaning: draft | active | expired | cancelled), plus the direct links to
 * the e-sign envelope and document that replace address-and-tenant guessing.
 *
 * `renewal_draft_flow_id` is KEPT and mirrored (written alongside `signing_flow_id` for renewals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'signing_status')) {
                // not_sent | prepared | out_for_signing | awaiting_agent_review | signed |
                // declined | voided | expired | signed_on_paper  (§15.5)
                $table->string('signing_status', 30)->default('not_sent');
            }
            if (! Schema::hasColumn('leases', 'signing_flow_id')) {
                $table->unsignedBigInteger('signing_flow_id')->nullable();
            }
            if (! Schema::hasColumn('leases', 'signature_template_id')) {
                // signature_templates.id — no FK across the Docuperfect module boundary (convention
                // already used by source_document_id / renewal_draft_flow_id).
                $table->unsignedBigInteger('signature_template_id')->nullable()->index();
            }
            if (! Schema::hasColumn('leases', 'agreement_document_id')) {
                // docuperfect_documents.id — kept apart from source_document_id so a lease can show the
                // in-flight document before completion.
                $table->unsignedBigInteger('agreement_document_id')->nullable();
            }
            if (! Schema::hasColumn('leases', 'agreement_template_id')) {
                // docuperfect_templates.id — no FK, convention.
                $table->unsignedBigInteger('agreement_template_id')->nullable();
            }
            if (! Schema::hasColumn('leases', 'signed_at')) {
                $table->timestamp('signed_at')->nullable();
            }
            if (! Schema::hasColumn('leases', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable();
            }
            if (! Schema::hasColumn('leases', 'accepted_by_user_id')) {
                $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leases', 'agreement_confirmed_fingerprint')) {
                $table->string('agreement_confirmed_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('leases', 'agreement_confirmed_at')) {
                $table->timestamp('agreement_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('leases', 'agreement_confirmed_by_user_id')) {
                $table->foreignId('agreement_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leases', 'capture_key')) {
                // Idempotency key of the capture screen — a double-click returns the same lease.
                $table->string('capture_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('leases', 'signing_failure_note')) {
                $table->string('signing_failure_note', 500)->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('leases'))->pluck('name');
        if (! $indexes->contains('leases_agency_id_signing_status_index')) {
            Schema::table('leases', function (Blueprint $table) {
                $table->index(['agency_id', 'signing_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropIndex(['agency_id', 'signing_status']);
            $table->dropForeign(['accepted_by_user_id']);
            $table->dropForeign(['agreement_confirmed_by_user_id']);
            $table->dropUnique(['capture_key']);
            $table->dropIndex(['signature_template_id']);
            $table->dropColumn([
                'signing_status', 'signing_flow_id', 'signature_template_id', 'agreement_document_id',
                'agreement_template_id', 'signed_at', 'accepted_at', 'accepted_by_user_id',
                'agreement_confirmed_fingerprint', 'agreement_confirmed_at', 'agreement_confirmed_by_user_id',
                'capture_key', 'signing_failure_note',
            ]);
        });
    }
};
