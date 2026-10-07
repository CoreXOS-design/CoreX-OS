<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-448 — the Drive "Extension" folder (signed mandate extensions).
 *
 * Spec: .ai/specs/at448-property-expiry.md §4.3
 *
 * `document_types` is a GLOBAL catalogue (no agency_id) — every agency sees
 * the folder; agencies rename/deactivate it under Settings → Document Types.
 * Same idempotent insert-if-absent pattern as 2026_10_03_200600 (TPN), PLUS
 * `listing_types`: DocumentType::appliesToListingType() returns false when it
 * is empty, in which case the type shows in the upload dropdown but its files
 * land in "Other Documents" instead of their own Drive folder. Sale AND rental
 * — both carry mandates that get extended.
 *
 * The slug is what MandateExpiryPolicy keys the expiry-lock unlock on — a
 * rename of the label is fine; the slug must stay `mandate_extension`.
 */
return new class extends Migration
{
    public const SLUG = 'mandate_extension';

    public function up(): void
    {
        $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

        $existing = DB::table('document_types')->where('slug', self::SLUG)->first();

        if (! $existing) {
            DB::table('document_types')->insert([
                'slug'          => self::SLUG,
                'label'         => 'Extension',
                'grouping'      => 'property',
                'listing_types' => json_encode(['sale', 'rental']),
                'sort_order'    => $maxSort + 1,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            return;
        }

        // A re-run after a partial earlier insert (or a hand-made row without
        // listing types) must still end with a real Drive folder.
        $types = json_decode((string) ($existing->listing_types ?? ''), true);
        if (empty($types)) {
            DB::table('document_types')->where('id', $existing->id)->update([
                'listing_types' => json_encode(['sale', 'rental']),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_types')->where('slug', self::SLUG)->delete();
    }
};
