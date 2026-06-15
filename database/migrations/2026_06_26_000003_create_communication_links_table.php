<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication links (AT-32, spec §4.3) — the Intelligence layer, decoupled.
 * Links a communication to a contact/deal/property. The archive never depends
 * on a row here existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_link_agency_fk')->cascadeOnDelete();
            $table->foreignId('communication_id')->constrained('communications', 'id', 'comm_link_comm_fk')->cascadeOnDelete();

            $table->string('linkable_type', 191);
            $table->unsignedBigInteger('linkable_id');
            $table->enum('link_method', ['deterministic', 'attorney_ref', 'ellie_suggested', 'manual']);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users', 'id', 'comm_link_confby_fk')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['linkable_type', 'linkable_id'], 'comm_link_morph_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_links');
    }
};
