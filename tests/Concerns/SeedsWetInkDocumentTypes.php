<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Makes the five wet-ink (deed-of-alienation) document types exist in the test database
 * by running the REAL migration that guarantees them in production.
 *
 * `RefreshDatabase` loads the committed schema SNAPSHOT, which carries tables, not the rows
 * that older migrations inserted — so `otp`, `offer_to_purchase`, `sale_agreement`,
 * `deed_of_sale` and `deed_of_alienation` are absent unless something creates them. The
 * migration below is the single place that defines them (AT-162: seeders do not run on a
 * `git pull` deploy), so tests run IT rather than re-declaring the rows by hand — a test
 * that typed its own copy would stop proving that the migration still guarantees them.
 *
 * Idempotent: the migration skips any slug that already has a row.
 */
trait SeedsWetInkDocumentTypes
{
    protected function seedWetInkDocumentTypes(): void
    {
        $migration = require base_path('database/migrations/2026_07_12_090000_classify_unclassified_docuperfect_templates.php');
        $migration->up();
    }
}
