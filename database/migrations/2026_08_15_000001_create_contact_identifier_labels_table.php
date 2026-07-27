<?php

use App\Models\Agency;
use App\Models\ContactIdentifierLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-details Phase 2 — settings-managed label list for tel + email
 * entries. Same shape as ContactSource (the established admin-managed-list
 * template): agency_id, name, color, sort_order, is_active, soft-deletes.
 *
 * ONE shared list for both phones and emails (contact_phones.contact_
 * identifier_label_id / contact_emails.contact_identifier_label_id both point
 * here) — "Personal"/"Business" means the same thing for a number or an
 * address, so there is no reason to force two separate configurable lists.
 *
 * Existing contact_phones/contact_emails rows keep their free-text `label`
 * string untouched (nothing currently reads it besides the repeater, which
 * this feature repoints at the FK) — no backfill of the FK needed or
 * attempted; a historical free-text label simply has no managed-list link
 * until an agent re-picks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identifier_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7)->default('#6366f1');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active']);
        });

        Schema::table('contact_phones', function (Blueprint $table) {
            $table->foreignId('contact_identifier_label_id')->nullable()->after('label')
                ->constrained('contact_identifier_labels')->nullOnDelete();
        });

        Schema::table('contact_emails', function (Blueprint $table) {
            $table->foreignId('contact_identifier_label_id')->nullable()->after('label')
                ->constrained('contact_identifier_labels')->nullOnDelete();
        });

        // Give every EXISTING agency a usable starting list (matches the
        // AgencyObserver::created() pattern — firstOrCreate, idempotent,
        // withoutEvents so BelongsToAgency's creating() hook can't override
        // agency_id to whichever admin happens to run the migration).
        Model::withoutEvents(function () {
            foreach (Agency::withoutGlobalScopes()->pluck('id') as $agencyId) {
                ContactIdentifierLabel::seedDefaultsFor((int) $agencyId);
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_identifier_label_id');
        });
        Schema::table('contact_phones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_identifier_label_id');
        });
        Schema::dropIfExists('contact_identifier_labels');
    }
};
