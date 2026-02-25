<?php

namespace App\Support;

/**
 * Maps suburbs to their parent town and vice versa.
 *
 * Uses config/south_coast_areas.php as the source of truth.
 * Falls back gracefully when a suburb is not mapped.
 */
class SuburbMapper
{
    private static ?array $townMap = null;
    private static ?array $suburbIndex = null;

    /**
     * Get the parent town for a suburb.
     * Returns null if not found in the mapping.
     */
    public static function townFor(string $suburb): ?string
    {
        self::boot();

        $lower = mb_strtolower(trim($suburb));

        return self::$suburbIndex[$lower] ?? null;
    }

    /**
     * Get all suburbs belonging to a town.
     * Returns the town name itself as a single-element array if not found.
     */
    public static function suburbsInTown(string $town): array
    {
        self::boot();

        $lower = mb_strtolower(trim($town));

        foreach (self::$townMap as $townName => $suburbs) {
            if (mb_strtolower($townName) === $lower) {
                return $suburbs;
            }
        }

        // If the input is itself a suburb, find its town and return all siblings
        $parentTown = self::townFor($town);
        if ($parentTown !== null) {
            foreach (self::$townMap as $townName => $suburbs) {
                if (mb_strtolower($townName) === mb_strtolower($parentTown)) {
                    return $suburbs;
                }
            }
        }

        return [$town];
    }

    /**
     * Given a suburb, return ALL suburbs in the same town area.
     * If the suburb is not mapped, returns just the suburb itself.
     */
    public static function expandToTownArea(string $suburb): array
    {
        $town = self::townFor($suburb);

        if ($town === null) {
            return [$suburb];
        }

        return self::suburbsInTown($town);
    }

    /**
     * Get the town label for display (e.g., "Greater Margate Area").
     * Falls back to the suburb name if not mapped.
     */
    public static function townLabel(string $suburb): string
    {
        $town = self::townFor($suburb);

        if ($town === null) {
            return $suburb;
        }

        return "Greater {$town} Area";
    }

    /**
     * Get all town names from the config.
     */
    public static function allTowns(): array
    {
        self::boot();

        return array_keys(self::$townMap);
    }

    /**
     * Get all suburbs from the config.
     */
    public static function allSuburbs(): array
    {
        self::boot();

        return array_keys(self::$suburbIndex);
    }

    /**
     * Get the region keywords for article matching.
     */
    public static function regionKeywords(): array
    {
        return config('south_coast_areas.regions', []);
    }

    /**
     * Boot the mapping from config if not already loaded.
     */
    private static function boot(): void
    {
        if (self::$townMap !== null) {
            return;
        }

        self::$townMap = config('south_coast_areas.towns', []);
        self::$suburbIndex = [];

        foreach (self::$townMap as $town => $suburbs) {
            foreach ($suburbs as $suburb) {
                self::$suburbIndex[mb_strtolower($suburb)] = $town;
            }
            // Also index the town name itself
            self::$suburbIndex[mb_strtolower($town)] = $town;
        }
    }
}
