<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — Platform Contracts (dev-side e-sign of CoreX's own agreement).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §6.2
 *
 * Platform-owned, NOT tenant-scoped. `agency_id` names the agency the contract
 * is for; no agency user can reach these tables through any route.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('platform_contract_templates')) {
            Schema::create('platform_contract_templates', function (Blueprint $t) {
                $t->id();
                $t->string('name', 255);
                $t->string('kind', 30)->default('subscription_agreement');
                $t->longText('body');                              // plain text + light markup, see PlainDocRenderer
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (!Schema::hasTable('platform_contract_envelopes')) {
            Schema::create('platform_contract_envelopes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('template_id')->nullable();
                $t->unsignedInteger('template_version')->nullable();
                $t->string('title', 255);
                $t->longText('body_html_snapshot');                // merged + frozen at send
                $t->string('signatory_name', 255);
                $t->string('signatory_email', 255);
                $t->string('signatory_role', 100)->default('Principal');
                $t->string('token', 64)->unique();
                $t->timestamp('token_expires_at')->nullable();
                $t->string('status', 12)->default('draft');        // draft|sent|viewed|signed|declined|expired|voided
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('first_viewed_at')->nullable();
                $t->timestamp('signed_at')->nullable();
                $t->timestamp('declined_at')->nullable();
                $t->text('decline_reason')->nullable();
                $t->string('signed_typed_name', 255)->nullable();
                $t->longText('signature_image')->nullable();       // data-URI of the drawn signature, if drawn
                $t->string('signed_ip', 64)->nullable();
                $t->text('signed_user_agent')->nullable();
                $t->text('consent_text_snapshot')->nullable();
                $t->string('document_hash', 64)->nullable();
                $t->string('sealed_pdf_path', 500)->nullable();
                $t->timestamp('voided_at')->nullable();
                $t->unsignedBigInteger('voided_by')->nullable();
                $t->text('void_reason')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['agency_id', 'status']);
            });
        }

        if (!Schema::hasTable('platform_contract_events')) {
            Schema::create('platform_contract_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('envelope_id');
                $t->string('event', 30);
                $t->string('detail', 500)->nullable();
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('ip', 64)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['envelope_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('platform_contract_attachments')) {
            Schema::create('platform_contract_attachments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('envelope_id');
                $t->string('original_name', 255);
                $t->string('stored_path', 500);
                $t->string('sha256', 64);
                $t->timestamps();
                $t->index('envelope_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_contract_attachments');
        Schema::dropIfExists('platform_contract_events');
        Schema::dropIfExists('platform_contract_envelopes');
        Schema::dropIfExists('platform_contract_templates');
    }
};
