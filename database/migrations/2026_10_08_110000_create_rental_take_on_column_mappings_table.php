<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §11 (Landing 2) — a saved, reusable
 * column mapping for an agency's own CRM export shape, named by the agent
 * who confirmed it ("PropertyFile export", "Payprop export"). Full CRUD:
 * create happens implicitly when confirming a map-columns screen with a
 * name given; update renames or re-maps; archive/restore via SoftDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_take_on_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('name');
            // field_key => the SOURCE FILE's own header TEXT (never a raw
            // column index — a later export from the same CRM can easily
            // reorder or insert a column; re-resolving by header text
            // against whatever file is uploaded next is what makes a
            // saved mapping actually reusable, not just a one-time record).
            $table->json('mapping_json');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_take_on_column_mappings');
    }
};
