<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT type per job-card line (Pastel-style). `rental_vat_type_id` is the
 * line's chosen type — inherited from the catalogue item's own default when
 * one is picked, free-text lines inherit the agency's default type, always
 * editable per line. `custom_vat_rate` is only used when that type's
 * rate_mode is custom_per_line (the agent types a % on THIS line).
 *
 * The four `*_snapshot` columns are null on every draft line and frozen
 * once — at "send to owner as quote" or at job-card completion, whichever
 * happens first — so a later change to the agency's VAT registration, rate,
 * capture mode, or VAT type list never alters an issued quote or a closed
 * job card (CLAUDE.md non-negotiable: a setting change must never rewrite
 * history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->foreignId('rental_vat_type_id')->nullable()->after('rental_catalogue_item_id')
                ->constrained(indexName: 'rjcl_vat_type_fk')->nullOnDelete();
            $table->decimal('custom_vat_rate', 5, 2)->nullable()->after('rental_vat_type_id');

            $table->string('vat_type_name_snapshot', 100)->nullable()->after('line_total');
            $table->decimal('vat_rate_snapshot', 5, 2)->nullable()->after('vat_type_name_snapshot');
            $table->decimal('vat_excl_snapshot', 10, 2)->nullable()->after('vat_rate_snapshot');
            $table->decimal('vat_amount_snapshot', 10, 2)->nullable()->after('vat_excl_snapshot');
            $table->decimal('vat_incl_snapshot', 10, 2)->nullable()->after('vat_amount_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_vat_type_id');
            $table->dropColumn([
                'custom_vat_rate', 'vat_type_name_snapshot', 'vat_rate_snapshot',
                'vat_excl_snapshot', 'vat_amount_snapshot', 'vat_incl_snapshot',
            ]);
        });
    }
};
