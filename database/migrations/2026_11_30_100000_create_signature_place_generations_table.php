<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign evidence: every signature / initial written to a place on a web (CDS) document keeps its history.
 *
 * Johan's standing signature-evidence rule (9 Sep 2026): a re-signature must NEVER overwrite the original.
 * On the web path the per-place images live in documents.web_template_data (a JSON map keyed by place), which
 * a repeat completion array_merge()d over. This table is the append-only record of each version of each place:
 * generation 1 is the first signing, generation N+1 replaces N for rendering while N stays as evidence. The
 * JSON map keeps holding the LATEST generation, so nothing that renders changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signature_place_generations')) {
            return;
        }

        Schema::create('signature_place_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signature_template_id')->nullable();
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->string('place_key', 191);
            $table->string('kind', 12);                       // signature | initial
            $table->unsignedInteger('generation');
            $table->longText('image');                         // the data URI exactly as captured
            $table->char('image_hash', 64);
            $table->string('signer_name')->nullable();
            $table->string('signer_email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_at')->nullable();        // null only for a generation recovered from the JSON (time not on record)
            $table->string('source', 24)->default('web_complete'); // web_complete | legacy_json
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'place_key', 'generation'], 'sig_place_gen_unique');
            $table->index('signature_request_id', 'sig_place_gen_request_idx');
            $table->index('signature_template_id', 'sig_place_gen_template_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_place_generations');
    }
};
