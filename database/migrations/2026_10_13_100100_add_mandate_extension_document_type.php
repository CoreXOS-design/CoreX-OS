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

        // An existing row (a re-run, a partial earlier insert, a hand-made row, or one
        // archived / deactivated since) must still end as a REAL, usable Drive folder:
        // not archived, active, and assigned to listing types. The label is left alone.
        $repairs = [];
        if ($existing->deleted_at !== null) {
            $repairs['deleted_at'] = null;
        }
        if (! $existing->is_active) {
            $repairs['is_active'] = true;
        }
        $types = json_decode((string) ($existing->listing_types ?? ''), true);
        if (empty($types)) {
            $repairs['listing_types'] = json_encode(['sale', 'rental']);
        }
        if ($repairs !== []) {
            $repairs['updated_at'] = now();
            DB::table('document_types')->where('id', $existing->id)->update($repairs);
        }
    }

    public function down(): void
    {
        // Never a hard delete: on installs where this row pre-existed (the local dev DB
        // carried a hand-made "Mandate Extension"), up() did not create it, and filed
        // documents point at it. Archive it instead (the table uses SoftDeletes) -
        // documents keep their type, and up() restores it on the next run.
        DB::table('document_types')
            ->where('slug', self::SLUG)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
