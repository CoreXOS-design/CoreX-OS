<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-24 — the recipient template library. Authored like the
 * existing clause library (docuperfect_clauses / Clause) in UX terms —
 * freeform text with insertable fields, a named settings-screen item — but
 * DELIBERATELY DOES NOT copy Clause's scoping mechanism (BelongsToAgency +
 * is_global). That exact combination was the bug fixed in
 * 2026_08_20_000001_add_agency_id_to_clauses_packs_knowledge_tables.php:
 * BelongsToAgency's global scope ANDs `agency_id = <acting agency>` onto
 * every query, which makes an `is_global=1` row from a NULL-agency system
 * default invisible regardless of the flag — that migration's own docblock
 * says so outright. This table NEEDS NULL-agency CoreX defaults visible to
 * every agency (Elize's seeded starter list) while an agency's own override
 * stays private — the opposite of Clause's fully-private-per-agency content.
 * So: no BelongsToAgency trait. Resolution is explicit
 * (RecipientTemplate::resolveFor()), mirroring
 * App\Models\Docuperfect\DataDictionaryEntry, NOT Clause.
 *
 * role_token: which party field this template can be picked for on the
 * recipient screen — 'seller' | 'buyer' | 'lessor' | 'lessee' | 'any'.
 *
 * party_slots (json): the template's declared slots, e.g. for a deceased
 * estate — [{"key":"deceased","label":"Deceased","kind":"named"},
 * {"key":"executor","label":"Executor","kind":"signing"}]. kind is 'named'
 * (rendered as text; never a recipient, never signs — the deceased, the
 * company itself) or 'signing' (binds to an actual recipient row on the
 * recipient screen and signs exactly as any recipient does today — no new
 * signing mechanism, no signature-block generation change). Declared by the
 * template author up front, per Johan: retrofitting kind later means
 * auditing every template an agency has already written.
 *
 * text_template uses named placeholders resolved once at generation time
 * and snapshotted onto signature_requests.party_clause_text — this table is
 * never read at render time for an already-generated document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->cascadeOnDelete();
            $table->string('role_token', 20); // seller | buyer | lessor | lessee | any
            $table->string('key', 60); // e.g. 'deceased_estate_executor', 'company_directors'
            $table->string('name', 120); // human label, shown in the settings UI
            $table->text('text_template');
            $table->json('party_slots');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // NULL-agency uniqueness is enforced by the seeder's own
            // idempotency (updateOrCreate), not this index — MySQL does not
            // treat repeated NULLs as a uniqueness violation. This index
            // correctly enforces uniqueness for real (non-null) agency_id rows.
            $table->unique(['agency_id', 'role_token', 'key'], 'recipient_templates_agency_role_key_unique');
            $table->index(['role_token', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_templates');
    }
};
