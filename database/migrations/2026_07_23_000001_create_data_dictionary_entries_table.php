<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-177 / WS0 — the versioned CoreX real-estate Data Dictionary (spec §2.1, §12 ruling 1).
 *
 * Validation lives on the ENTRY, so it is enforced identically at compile, at fill, and at
 * sign. Entries are versioned; a compiled template pins the dictionary version it bound
 * against, so a later dictionary change can never silently alter a published template.
 *
 * agency_id NULL = CoreX-standard entry (shipped seed). A row with agency_id set is an
 * agency OVERRIDE of that key. Global uniqueness of (key, version) among CoreX-standard
 * rows is enforced by the seeder's updateOrCreate + the model resolver (MySQL treats NULL
 * as distinct in a composite unique, so the DB unique below only guards agency-scoped rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_dictionary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = CoreX-standard entry; set = agency override of this key');
            $table->string('key', 120)->comment('stable entry key e.g. purchase_price, seller_id_number');
            $table->unsignedInteger('version')->default(1);
            $table->string('category', 40)->comment('money|identity|property|practitioner|date|party');
            $table->string('label', 200);
            $table->string('data_type', 40)
                ->comment('drives validation: zar_money|sa_id|ppra_no|ffc_no|date|erf_number|title_deed|scheme_name|unit_no|gps|full_name|marital_status|text');
            $table->json('validation')->nullable()->comment('validator params; may tighten never loosen (L5)');
            $table->json('format')->nullable()->comment('display formatting hints');
            $table->string('default_source', 20)->default('agent_input')->comment('auto|party_input|agent_input');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('data_dictionary_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'key', 'version'], 'uq_dd_entries_akv');
            $table->index('key', 'idx_dd_entries_key');
            $table->index('category', 'idx_dd_entries_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_dictionary_entries');
    }
};
