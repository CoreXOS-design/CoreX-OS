<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-177 / WS0 — the immutable, content-hashed, versioned Compiled Template (spec §2, §5,
 * §12 ruling 2). One table carries the immutable JSON `structure` (the CDS v2 tree) + a
 * `content_hash` per published version.
 *
 * IMMUTABILITY IS THE POINT: a PUBLISHED row is NEVER updated — editing produces a new
 * version row; the old version is retained and marked superseded (NN#1, no hard deletes).
 * A signing request pins (id, version, content_hash), so the freshness class is
 * unrepresentable — there is no snapshot to drift. The model enforces the no-mutate rule.
 *
 * agency_id NULL = CoreX-standard template (Door A, shipped pack); set = agency-owned
 * (Door B, white-glove onboarding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compiled_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = CoreX-standard template (Door A); set = agency-owned (Door B)');
            $table->foreignId('source_template_id')->nullable()
                ->constrained('docuperfect_templates')->nullOnDelete()
                ->comment('legacy docuperfect_templates row this was compiled from (reference-proof migration §8)');

            $table->string('family', 120)->comment('document family e.g. 116/117/119, otp_sale, mandate_sole');
            $table->unsignedInteger('version')->default(1)->comment('monotonic per (agency_id, family)');
            $table->char('content_hash', 64)->nullable()
                ->comment('sha256 of the structural CDS; set at publish; the §5 pin');
            $table->unsignedInteger('data_dictionary_version')->default(1)
                ->comment('pins the dictionary version bindings resolve against');
            $table->string('legal_class', 60)->default('general')
                ->comment('resolved from family; drives L7 e-sign legality');
            $table->json('delivery_modes')->comment('enabled modes: web_esign/pdf_wetink/download');
            $table->json('structure')->comment('the immutable CDS v2 tree — the SOLE runtime truth');
            $table->json('render_parity')->nullable()->comment('web/pdf parity hashes, written after L6');
            $table->json('lint_report')->nullable()->comment('auditable L1-L7 lint output attached to this version');
            $table->string('lint_status', 20)->default('pending')->comment('pending|passed|failed');
            $table->string('status', 20)->default('draft')->comment('draft|published|superseded');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('compiled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('compiled_templates')->nullOnDelete()
                ->comment('the version that superseded this one (immutability: never mutate, supersede)');

            $table->timestamps();
            $table->softDeletes();

            // content_hash is UNIQUE among published rows (nullable → draft rows exempt; MySQL
            // permits multiple NULLs in a unique index).
            $table->unique('content_hash', 'uq_compiled_templates_hash');
            $table->index(['agency_id', 'family', 'version'], 'idx_compiled_templates_afv');
            $table->index('status', 'idx_compiled_templates_status');
            $table->index('family', 'idx_compiled_templates_family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compiled_templates');
    }
};
