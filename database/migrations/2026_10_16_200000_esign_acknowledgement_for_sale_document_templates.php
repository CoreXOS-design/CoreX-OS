<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign eligibility is the template's own setting (Johan, 8 Oct 2026). The hard-coded
 * "sale agreements / OTPs / deeds can never be e-signed" floor is replaced by a data flag on
 * the document type plus a recorded acknowledgement on the template.
 *
 *  - document_types.esign_warning_required — which types trigger the legal warning. Data, so
 *    that when the law changes it is switched off with a tick, not recoded.
 *  - docuperfect_templates.esign_acknowledged_* — who switched e-signing on for a flagged
 *    template, and when (drives the one-line "agency decision" note).
 *  - template_esign_acknowledgements — insert-only audit trail of every acknowledgement.
 *
 * Nothing is flipped: no existing template's is_esign is touched. The five types that were
 * wet-ink-only are flagged here so they keep defaulting to wet ink for every agency.
 */
return new class extends Migration
{
    /** The five document types that carried the old hard-coded floor. */
    private const FLAGGED_SLUGS = ['otp', 'offer_to_purchase', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation'];

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'esign_warning_required')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->boolean('esign_warning_required')->default(false)->after('buyer_pack_eligible');
            });
        }

        // Existing rows only — a type that does not exist yet is flagged when it is created
        // (DocumentTypesCatalogueSeeder / the 2026_07_12 classify migration's rows are
        // re-flagged by the seeder's create branch). Never re-asserted on later deploys, so a
        // switched-off flag stays off.
        DB::table('document_types')
            ->whereIn('slug', self::FLAGGED_SLUGS)
            ->update(['esign_warning_required' => true]);

        if (! Schema::hasColumn('docuperfect_templates', 'esign_acknowledged_at')) {
            Schema::table('docuperfect_templates', function (Blueprint $table) {
                $table->unsignedBigInteger('esign_acknowledged_by_user_id')->nullable()->after('is_esign');
                $table->string('esign_acknowledged_by_name')->nullable()->after('esign_acknowledged_by_user_id');
                $table->timestamp('esign_acknowledged_at')->nullable()->after('esign_acknowledged_by_name');
            });
        }

        if (! Schema::hasTable('template_esign_acknowledgements')) {
            Schema::create('template_esign_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->nullable();
                $table->unsignedBigInteger('template_id');
                $table->string('template_name');
                $table->string('document_type_slug')->nullable();
                $table->string('action', 20); // enabled | disabled
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->unsignedBigInteger('on_behalf_of_user_id')->nullable();
                $table->unsignedSmallInteger('wording_version')->nullable();
                $table->text('wording_snapshot')->nullable();
                $table->json('request_context')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['agency_id', 'created_at']);
                $table->index('template_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('template_esign_acknowledgements');

        if (Schema::hasColumn('docuperfect_templates', 'esign_acknowledged_at')) {
            Schema::table('docuperfect_templates', function (Blueprint $table) {
                $table->dropColumn(['esign_acknowledged_by_user_id', 'esign_acknowledged_by_name', 'esign_acknowledged_at']);
            });
        }

        if (Schema::hasColumn('document_types', 'esign_warning_required')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->dropColumn('esign_warning_required');
            });
        }
    }
};
