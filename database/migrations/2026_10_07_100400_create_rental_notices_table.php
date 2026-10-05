<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. A sent notice —
 * append-only record of what was sent, to whom, when, with a link to the
 * rendered document. Not edited after sending (§10): "notices are sent,
 * not edited after sending — no update/archive beyond the normal
 * soft-delete floor for a sending error."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_notice_template_id')->nullable()->constrained('rental_notice_templates')->nullOnDelete();

            $table->string('notice_type', 30);
            $table->json('figures')->nullable(); // arrears amount, breach description, vacate-by date, etc.
            $table->boolean('sent_to_tenant')->default(false);
            $table->boolean('sent_to_landlord')->default(false);

            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'lease_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_notices');
    }
};
