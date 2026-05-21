<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Sign V3 — Phase 1B (ES-9).
 *
 * Creates three new tables for the Other Conditions / clause library /
 * strikethrough flow, and adds the per-template insertable_blocks metadata
 * column.
 *
 *   document_conditions              numbered list items for non-other-conditions blocks
 *   document_clause_strikethroughs   audit of clause overrides auto-routed to other_conditions_text
 *   condition_initials               append-only per-party initials on amended regions
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signature_template_id');
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('block_id');
            $table->enum('block_purpose', ['other_conditions', 'included_items', 'excluded_items', 'custom_named']);
            $table->string('custom_label')->nullable();
            $table->unsignedInteger('condition_number');
            $table->text('content');
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_override')->default(false);
            $table->string('overrides_clause_ref')->nullable();
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->unsignedBigInteger('added_by_party_id')->nullable();
            $table->enum('added_via', [
                'agent_preparation', 'agent_signing', 'recipient_signing', 'system_default',
            ]);
            $table->enum('source', ['library', 'custom']);
            $table->unsignedBigInteger('library_clause_id')->nullable();
            $table->unsignedBigInteger('amendment_id')->nullable();
            $table->timestamp('approved_by_agent_at')->nullable();
            $table->unsignedBigInteger('approved_by_agent_user_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_condition_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['signature_template_id', 'block_id'], 'doc_cond_tpl_block_idx');
            $table->index(['agency_id', 'signature_template_id'], 'doc_cond_agency_tpl_idx');
        });

        Schema::create('document_clause_strikethroughs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signature_template_id');
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('clause_ref');
            $table->text('clause_original_text');
            $table->unsignedBigInteger('replacement_condition_id')->nullable();
            $table->unsignedBigInteger('proposed_by_user_id')->nullable();
            $table->unsignedBigInteger('proposed_by_party_id')->nullable();
            $table->unsignedBigInteger('amendment_id');
            $table->enum('status', ['proposed', 'approved', 'rejected', 'superseded']);
            $table->timestamp('approved_by_agent_at')->nullable();
            $table->timestamp('rejected_by_agent_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['signature_template_id'], 'doc_strk_tpl_idx');
            $table->index(['agency_id', 'signature_template_id'], 'doc_strk_agency_tpl_idx');
        });

        Schema::create('condition_initials', function (Blueprint $table) {
            $table->id();
            // Polymorphic — initialable_type / initialable_id columns
            $table->string('initialable_type');
            $table->unsignedBigInteger('initialable_id');
            $table->string('party_key', 50);
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->unsignedBigInteger('amendment_id')->nullable();
            $table->timestamp('initialed_at')->useCurrent();
            $table->string('initial_image_path')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            // INSERT-ONLY — no updated_at, no soft delete

            $table->index(['initialable_type', 'initialable_id'], 'cond_init_morph_idx');
            $table->index(['party_key', 'initialed_at'], 'cond_init_party_idx');
        });

        // Per-template insertable_blocks metadata. Lives on docuperfect_templates
        // (the template definition) rather than signature_templates (the
        // per-document instance) — the marker positions are fixed at template
        // authoring time.
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->json('insertable_blocks')->nullable()->after('signing_parties');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn('insertable_blocks');
        });
        Schema::dropIfExists('condition_initials');
        Schema::dropIfExists('document_clause_strikethroughs');
        Schema::dropIfExists('document_conditions');
    }
};
