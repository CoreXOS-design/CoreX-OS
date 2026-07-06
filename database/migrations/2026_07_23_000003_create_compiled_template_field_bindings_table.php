<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-177 / WS0 — the thin field-binding index (spec §12 ruling 2).
 *
 * This is a DERIVED, REBUILDABLE mirror of each compiled version's field→dictionary
 * bindings, existing only to make "which templates bind purchase_price?" queryable. It is
 * NEVER the source of truth — that is the CDS `structure`. It is rebuilt from the structure
 * whenever a version publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compiled_template_field_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compiled_template_id')->constrained('compiled_templates')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete()
                ->comment('denormalised from the compiled template for querying/scoping');
            $table->string('block_id', 120);
            $table->string('field_id', 120);
            $table->string('field_label', 200)->nullable();
            $table->string('dictionary_key', 120)->comment('the bound Data Dictionary entry key');
            $table->unsignedInteger('dictionary_version')->default(1);
            $table->string('source', 20)->default('agent_input')->comment('auto|party_input|agent_input');
            $table->timestamps();

            $table->index('compiled_template_id', 'idx_ctfb_template');
            $table->index('dictionary_key', 'idx_ctfb_dict_key');
            $table->index(['agency_id', 'dictionary_key'], 'idx_ctfb_agency_dict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compiled_template_field_bindings');
    }
};
