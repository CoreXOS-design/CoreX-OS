<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * Loads and exposes the curated SA region → towns → suburbs library that
 * powers the Build-from-suggested-regions UI on the Prospecting Setup page.
 *
 * V1 implementation: file-backed library at
 * database/seeders/data/sa_region_suggestions.php. Adding new regions is a
 * code commit (the library is reference data, not user data).
 *
 * V2 candidates (out of scope for this prompt): live geocoding API,
 * municipal data feeds, or agency-defined region templates.
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S4, Section 12 Open Question #1.
 */
final class RegionSuggestionService
{
    /** @var array<string, array{name:string, towns:array<int, array{name:string, suburbs:array<int,string>}>}> */
    private array $library;

    public function __construct()
    {
        $this->library = require database_path('seeders/data/sa_region_suggestions.php');
    }

    /**
     * Display-name keyed by region key.
     *
     * @return array<string, string>
     */
    public function regions(): array
    {
        $out = [];
        foreach ($this->library as $key => $region) {
            $out[$key] = $region['name'];
        }
        return $out;
    }

    /**
     * Full suggestion data for one region, or null if the key is unknown.
     *
     * @return array{name:string, towns:array<int, array{name:string, suburbs:array<int,string>}>}|null
     */
    public function region(string $key): ?array
    {
        return $this->library[$key] ?? null;
    }
}
