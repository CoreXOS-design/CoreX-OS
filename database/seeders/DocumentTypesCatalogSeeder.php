<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * SUPERSEDED, 2026-09-02, same session — do not use.
 *
 * Written to backfill four missing FICA document types (bank_statement,
 * tax_clearance, company_registration, trust_deed), then discovered
 * `DocumentTypesCatalogueSeeder` (British spelling) already exists and
 * already covers the exact same four slugs at the exact same sort
 * positions (19-22) — it ran seconds later via the same
 * deploy:sync-reference-data auto-discovery and produced identical rows.
 * Two SyncableReferenceSeeder implementations racing over the same table
 * is exactly the failure class that interface exists to prevent (see its
 * own docblock). Deliberately NOT implementing SyncableReferenceSeeder
 * here any more, so this class is never auto-discovered or run again.
 * Left in place, inert, rather than deleted, because this environment
 * cannot delete files — kept here only as a record of the duplication for
 * whoever next cleans up database/seeders/.
 *
 * Use DocumentTypesCatalogueSeeder for anything document-type-catalogue
 * related.
 */
class DocumentTypesCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Intentionally does nothing. See class docblock.
    }
}
