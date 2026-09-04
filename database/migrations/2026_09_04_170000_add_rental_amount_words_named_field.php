<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rental amount in words — the sale side already has a "Price[words]" catalogue
 * row (id 23: source_type=computed, source_column=price_in_words) that resolves
 * via WebTemplateDataService::resolveComputedFromKey(). The rental side had no
 * equivalent, so a "…in words" clause could not be built onto a rental CDS
 * template (mandate, lease, etc.) — the amount had to be typed manually, and no
 * automated document ever showed the rental figure spelled out.
 *
 * source_column is 'rental_amount_words' — the SAME canonical key already used
 * by WebTemplateDataService::resolve() (the non-CDS/flat-array path) and already
 * registered in WebTemplateFieldPartyMap::PARTY_MAP['system'] as a computed,
 * non-editable field. One name, both resolver paths, no new synonym introduced.
 *
 * Idempotent, matching 2026_05_19_120000_seed_esign_deal_named_fields.php's
 * pattern exactly — existence-checked on (source_type, source_column,
 * source_contact_type IS NULL) before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('docuperfect_named_fields')
            ->where('source_type', 'computed')
            ->where('source_column', 'rental_amount_words')
            ->whereNull('source_contact_type')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('docuperfect_named_fields')->insert([
            'name'                => 'Rental Amount[words]',
            'field_type'          => 'text',
            'source_type'         => 'computed',
            'source_column'       => 'rental_amount_words',
            'source_contact_type' => null,
            // Immediately after "Rental Amount" (id 9, sort_order 90) — the
            // words-variant sits next to the plain figure it's derived from,
            // mirroring how "Price[words]" (sort_order 230) sits in the same
            // catalogue block as sale-price fields.
            'sort_order'          => 91,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        // No-op: reference catalogue row. Removing it would break any template
        // field already mapped to it. Reversal is intentionally not destructive.
    }
};
