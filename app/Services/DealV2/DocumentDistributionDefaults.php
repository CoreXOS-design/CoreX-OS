<?php

namespace App\Services\DealV2;

use Database\Seeders\AttorneyDistributionDefaultsSeeder;
use Database\Seeders\DocumentDistributionMatrixSeeder;

/**
 * The shipped "who receives which document" defaults, in ONE place: the two seeders' tables merged. The seeders add them to
 * agencies that never had a rule; the matrix page's "Restore defaults" puts the same ticks back on screen.
 */
class DocumentDistributionDefaults
{
    /** @return array<string,string[]> document type slug => party roles */
    public static function bySlug(): array
    {
        $map = DocumentDistributionMatrixSeeder::DEFAULTS;
        foreach (AttorneyDistributionDefaultsSeeder::DEFAULTS as $role => $slugs) {
            foreach ($slugs as $slug) {
                $map[$slug] = array_values(array_unique(array_merge($map[$slug] ?? [], [$role])));
            }
        }

        return $map;
    }
}
