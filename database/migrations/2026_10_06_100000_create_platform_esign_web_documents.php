<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 follow-up — Platform E-Sign "web documents" (CoreX Subscription Agreement).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §11. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_esign_wording_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('platform_esign_templates')->cascadeOnDelete();
            $t->string('version', 20);
            $t->date('version_date');
            $t->longText('content_json');                 // {intro, part_a, part_b, part_c, part_d, mandate} markdown + tokens
            $t->json('rates_json');                       // pricing — editable with the wording, never hardcoded in views
            $t->json('layout_json')->nullable();          // calibrated pagination (AgreementLayout)
            $t->boolean('is_published')->default(true);
            $t->timestamp('published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['template_id', 'version']);
        });

        Schema::table('platform_esign_documents', function (Blueprint $t) {
            $t->foreignId('wording_version_id')->nullable()->after('template_version')->constrained('platform_esign_wording_versions')->restrictOnDelete();
            $t->string('contract_ref', 30)->nullable()->unique()->after('title');
            $t->longText('form_data')->nullable();       // encrypted:array — recipient entries
            $t->longText('rr_data')->nullable();         // encrypted:array — RR-side entries
            $t->unsignedInteger('form_rev')->default(0); // optimistic counter (two tabs)
            $t->string('recipient_note', 500)->nullable();
        });

        Schema::table('platform_esign_signers', function (Blueprint $t) {
            $t->string('initials', 10)->nullable()->after('typed_name');
            $t->mediumText('signature2_image')->nullable()->after('signature_image'); // mandate signature
        });

        Schema::create('platform_esign_initials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->foreignId('signer_id')->constrained('platform_esign_signers')->cascadeOnDelete();
            $t->unsignedSmallInteger('page_no');         // 1-based
            $t->string('initials', 10);
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['signer_id', 'page_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_esign_initials');
        Schema::table('platform_esign_signers', fn (Blueprint $t) => $t->dropColumn(['initials', 'signature2_image']));
        Schema::table('platform_esign_documents', function (Blueprint $t) {
            $t->dropForeign(['wording_version_id']);
            $t->dropColumn(['wording_version_id', 'contract_ref', 'form_data', 'rr_data', 'form_rev', 'recipient_note']);
        });
        Schema::dropIfExists('platform_esign_wording_versions');
    }
};
