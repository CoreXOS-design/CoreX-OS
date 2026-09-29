<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack — .ai/specs/ppra-inspection-pack.md §4.5.
 *
 * Created in PHASE F (not Phase J as originally sequenced in §8) because
 * Phase F's own deliverable — the shared sample picker (§6.8a) — persists
 * selected sample ids onto this table. Phase J adds the queued
 * GeneratePpraInspectionPackJob and download route on top of the table
 * created here; it does not create the table itself. See the spec's
 * "v3 amendment" note added alongside this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppra_inspection_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');

            $table->enum('status', ['queued', 'generating', 'ready', 'failed'])->default('queued');
            $table->json('sample_deal_ids')->nullable();      // item k
            $table->json('sample_rental_ids')->nullable();    // item l
            $table->json('sample_listing_ids')->nullable();   // item m

            $table->string('zip_path')->nullable();
            $table->string('report_pdf_path')->nullable();
            $table->unsignedBigInteger('zip_size_bytes')->nullable();
            $table->json('gaps_summary')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppra_inspection_packs');
    }
};
