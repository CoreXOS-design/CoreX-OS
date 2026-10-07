<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Find-or-create a `document_types` row by slug.
 *
 * The committed schema snapshot already carries the shared document-type catalogue
 * (mandate, fica, disclosure, otp, inspection_report, …), and `slug` is UNIQUE — so a test
 * that inserts "its own" copy of one of those slugs dies with a duplicate-key error. This
 * reuses the existing row (restoring it if it was soft-deleted) and sets the attributes the
 * test depends on (label / grouping / …), inside the test's own transaction, so the rest of
 * the file still sees exactly the catalogue row it asked for.
 */
trait FindsOrCreatesDocumentTypes
{
    /**
     * @param  array<string,mixed>  $attributes  columns the test needs (label, grouping, sort_order, is_active, …)
     */
    protected function documentTypeId(string $slug, string $label, array $attributes = []): int
    {
        DB::table('document_types')->updateOrInsert(
            ['slug' => $slug],
            array_merge([
                'label'      => $label,
                'sort_order' => 0,
                'is_active'  => true,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes),
        );

        return (int) DB::table('document_types')->where('slug', $slug)->value('id');
    }
}
