<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Models\SuburbAlias;
use Illuminate\Database\Seeder;

/**
 * Structured address matching (.ai/specs/structured-address-matching.md §3) — the GLOBAL
 * suburb-alias reference rows, sourced from config/property-suburb-aliases.php (evidence-seeded
 * groups; the first entry of a group is the canonical form). Idempotent (updateOrCreate on the
 * alias) and a SyncableReferenceSeeder, so a `git pull` deploy carries it. Never touches a row an
 * admin added with a different `source`, and never deletes anything.
 */
class SuburbAliasSeeder extends Seeder implements SyncableReferenceSeeder
{
    public function run(): void
    {
        foreach ((array) config('property-suburb-aliases.groups', []) as $group) {
            $group = array_values((array) $group);
            if (count($group) < 2) {
                continue;
            }
            $canonical = (string) $group[0];
            foreach ($group as $alias) {
                $row = SuburbAlias::query()->where('alias_normalised', (string) $alias)->first();
                if ($row !== null && $row->source !== 'config') {
                    continue; // a hand-added alias is never overwritten by the config
                }
                SuburbAlias::query()->updateOrCreate(
                    ['alias_normalised' => (string) $alias],
                    ['canonical_normalised' => $canonical, 'source' => 'config']
                );
            }
        }
    }
}
