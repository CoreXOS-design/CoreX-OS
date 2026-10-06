<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — Platform E-Sign: CoreX's own, separate e-sign (spec §3A). Owns its data outright:
 * no agency scoping, no property / listing / deal / contact links. `agency_id` on a document is
 * only a reference to the agency the contract is ABOUT (merge fields + timeline link).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_esign_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind', 40)->default('other');           // subscription_agreement | debit_order | other
            $t->string('source', 10)->default('web');           // web (typed wording) | pdf (uploaded, fields placed)
            $t->longText('body')->nullable();                   // web: plain wording with {{ merge_fields }}
            $t->string('pdf_path')->nullable();                 // pdf: stored source file
            $t->unsignedSmallInteger('page_count')->default(0);
            $t->json('roles_json');                             // [{key,label,order}] — free-text signer roles
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['is_active', 'deleted_at']);
        });

        Schema::create('platform_esign_template_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('platform_esign_templates')->cascadeOnDelete();
            $t->unsignedSmallInteger('page_index');             // 0-based
            $t->decimal('x', 7, 4); $t->decimal('y', 7, 4);     // percent of page
            $t->decimal('w', 7, 4); $t->decimal('h', 7, 4);
            $t->string('type', 20);                             // signature | initial | date | text
            $t->string('role_key', 40);
            $t->string('label')->nullable();
            $t->boolean('required')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('platform_esign_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->nullable()->constrained('platform_esign_templates')->nullOnDelete();
            $t->unsignedInteger('template_version')->default(1);
            $t->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete(); // the agency it is ABOUT
            $t->string('title');
            $t->string('status', 20)->default('draft');        // draft|sent|in_progress|completed|declined|voided|expired
            $t->string('source', 10)->default('web');
            $t->longText('body_html_snapshot')->nullable();     // web: frozen merged wording
            $t->string('pdf_path')->nullable();                 // pdf: frozen copy of the source
            $t->unsignedSmallInteger('page_count')->default(0);
            $t->json('fields_json')->nullable();                // pdf: frozen field layout
            $t->boolean('sequential')->default(true);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('declined_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 500)->nullable();
            $t->string('decline_reason', 500)->nullable();
            $t->string('sealed_pdf_path')->nullable();
            $t->char('document_hash', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'deleted_at']);
            $t->index('agency_id');
        });

        Schema::create('platform_esign_signers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->string('role_key', 40);
            $t->string('role_label');
            $t->unsignedSmallInteger('sign_order')->default(1);
            $t->string('name');
            $t->string('email');
            $t->string('id_number', 40)->nullable();
            $t->string('token', 64)->unique();
            $t->string('status', 20)->default('pending');       // pending|sent|viewed|signed|declined
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('first_viewed_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('typed_name')->nullable();
            $t->mediumText('signature_image')->nullable();      // PNG data-uri (validated)
            $t->string('signed_ip', 45)->nullable();
            $t->string('signed_user_agent', 500)->nullable();
            $t->text('consent_text_snapshot')->nullable();
            $t->unsignedSmallInteger('reminders_sent')->default(0);
            $t->timestamp('last_reminded_at')->nullable();
            $t->timestamps();
            $t->index(['document_id', 'sign_order']);
        });

        Schema::create('platform_esign_field_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->foreignId('signer_id')->constrained('platform_esign_signers')->cascadeOnDelete();
            $t->unsignedBigInteger('field_id');                 // index into the frozen fields_json
            $t->string('value', 1000)->nullable();
            $t->timestamps();
            $t->unique(['signer_id', 'field_id']);
        });

        Schema::create('platform_esign_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->foreignId('signer_id')->nullable()->constrained('platform_esign_signers')->nullOnDelete();
            $t->string('event', 40);
            $t->string('detail', 500)->nullable();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['document_id', 'created_at']);
        });

        Schema::create('platform_esign_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->string('original_name');
            $t->string('stored_path');
            $t->char('sha256', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['attachments', 'events', 'field_values', 'signers', 'documents', 'template_fields', 'templates'] as $x) {
            Schema::dropIfExists('platform_esign_' . $x);
        }
    }
};
