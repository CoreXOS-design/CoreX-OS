<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TFS (Targeted Financial Sanctions) list — GLOBAL reference data, multi-source.
 *
 * The FIC TFS portal today publishes ONE list (the UN Security Council Consolidated
 * list) in PDF/XML/EXCEL. There is no separate downloadable SA-domestic feed. This
 * schema is deliberately MULTI-SOURCE: every row carries `source_feed`, and every
 * import records its own feed + fetch time + content SHA. Adding a second feed (e.g.
 * a future SA-domestic/POCDATARA list) is just another import source — no schema change.
 *
 * Not agency-scoped: the sanctions list is universal reference data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Import runs (freshness + version, since the XML carries no version stamp) ──
        Schema::create('sanctions_list_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source_feed', 60);          // e.g. 'fic_un_consolidated'
            $table->string('source_label')->nullable(); // human label
            $table->string('source_url')->nullable();
            $table->string('fetch_method', 20)->default('http_post'); // http_post | file
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('file_bytes')->nullable();
            $table->string('source_filename')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('individual_count')->default(0);
            $table->unsignedInteger('entity_count')->default(0);
            // success = fetched + parsed + stored; unchanged = SHA identical, skipped;
            // failed = fetch/parse error (FAIL LOUD — never silently treated as fresh).
            $table->enum('status', ['success', 'unchanged', 'failed'])->default('failed');
            $table->text('error')->nullable();
            $table->date('list_published_at')->nullable(); // if a feed ever exposes one
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_feed', 'status']);
            $table->index('content_sha256');
            $table->index('finished_at');
        });

        // ── List entries (individuals + entities) ──
        Schema::create('sanctions_list_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source_feed', 60);
            $table->foreignId('import_id')->nullable()->constrained('sanctions_list_imports')->nullOnDelete();
            $table->string('external_ref', 120)->nullable();   // ReferenceNumber e.g. QDi.430
            $table->enum('record_kind', ['individual', 'entity']);
            $table->string('primary_name', 500);
            $table->string('normalised_name', 500);            // uppercased, de-noised — match key
            $table->date('date_of_birth')->nullable();
            $table->string('dob_raw', 120)->nullable();        // formats vary; keep the source string
            $table->string('place_of_birth', 500)->nullable();
            $table->string('nationality', 255)->nullable();
            $table->text('designation')->nullable();
            $table->text('address')->nullable();
            $table->text('comments')->nullable();
            $table->date('listed_on')->nullable();
            $table->json('raw')->nullable();                   // source tag/value map (audit)
            $table->timestamps();

            $table->index(['source_feed', 'record_kind']);
            $table->index(['source_feed', 'normalised_name'], 'sle_feed_name_idx');
            $table->index('external_ref');
        });

        // ── Aliases / AKAs (repeat per entry) ──
        Schema::create('sanctions_list_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('sanctions_list_entries')->cascadeOnDelete();
            $table->string('source_feed', 60);
            $table->string('alias', 500);
            $table->string('normalised_alias', 500);
            $table->timestamps();

            $table->index('normalised_alias');
        });

        // ── Identifiers (parsed from the comma-joined document string) — exact-match spine ──
        Schema::create('sanctions_list_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('sanctions_list_entries')->cascadeOnDelete();
            $table->string('source_feed', 60);
            $table->string('id_type', 40)->default('other');   // passport | national_id | other
            $table->string('id_value', 160);
            $table->string('normalised_value', 160);           // spaces stripped, uppercased
            $table->string('country', 120)->nullable();
            $table->timestamps();

            $table->index('normalised_value');
            $table->index(['id_type', 'normalised_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions_list_identifiers');
        Schema::dropIfExists('sanctions_list_aliases');
        Schema::dropIfExists('sanctions_list_entries');
        Schema::dropIfExists('sanctions_list_imports');
    }
};
