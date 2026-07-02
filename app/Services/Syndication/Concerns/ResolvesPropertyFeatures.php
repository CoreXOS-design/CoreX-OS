<?php

namespace App\Services\Syndication\Concerns;

use App\Models\Property;

/**
 * Shared property-feature resolution for portal mappers (Property24, Private
 * Property, …). Extracted so every portal derives the SAME feature set from a
 * property, instead of each mapper inventing its own read path and drifting.
 *
 * The logic mirrors the P24 mapper's AT-102/AT-103 rules verbatim (that mapper
 * is the proven source pattern; it keeps its own private copy for now and is
 * scheduled to adopt this trait — see .ai/specs/private-property.md §Attributes):
 *
 *  - "global" features = the flat features_json MINUS features that are ONLY
 *    attributable to a specific room (spaces[].featuresAll / spaces[].units[].features)
 *    and NOT in the explicit property-screen selection (spaces['features']). A
 *    feature set BOTH globally and on a room stays global.
 *  - property-level booleans/attributes source from the GLOBAL set only, so a
 *    feature that exists only inside one room never flips a property-level flag.
 *  - counts source from the structured spaces list (spaces[].type + count).
 */
trait ResolvesPropertyFeatures
{
    /**
     * The structured spaces list from spaces_json, tolerant of both the
     * wrapped ({spaces:[…]}) and bare ([…]) shapes.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function spacesList(Property $property): array
    {
        $sj = $property->spaces_json;
        if (!is_array($sj)) {
            return [];
        }

        return $sj['spaces'] ?? (isset($sj[0]) ? $sj : []);
    }

    /**
     * Summed `count` of every space of the given type (default 1 per space when
     * no explicit count). Returns a float so half-bath style counts survive.
     */
    protected function countSpaces(Property $property, string $type): float
    {
        return collect($this->spacesList($property))
            ->where('type', $type)
            ->sum(fn ($s) => (float) ($s['count'] ?? 1));
    }

    /**
     * The GLOBAL-ONLY feature set — features_json with room-ONLY features
     * removed. See the class docblock for the exact rule (mirrors P24
     * globalFeatures()).
     *
     * @return string[]
     */
    protected function globalFeatures(Property $property): array
    {
        $flat = array_values(array_unique(array_filter(
            array_map('strval', (array) ($property->features_json ?? [])),
            fn ($v) => trim($v) !== ''
        )));

        $spaces = $this->spacesList($property);
        if (empty($spaces)) {
            return $flat; // legacy / no structured rooms — features_json is the global set
        }

        // Explicit property-screen global selection (may be empty for imports/legacy).
        $sj = $property->spaces_json;
        $explicitGlobal = [];
        foreach ((is_array($sj) ? ($sj['features'] ?? []) : []) as $catArr) {
            $vals = is_array($catArr) ? $catArr : [$catArr];
            foreach ($vals as $f) {
                if (filled($f)) {
                    $explicitGlobal[strtolower(trim((string) $f))] = true;
                }
            }
        }

        // Features attributable to a room (space-level + per-unit).
        $roomFeatures = [];
        foreach ($spaces as $sp) {
            foreach (($sp['featuresAll'] ?? []) as $f) {
                if (filled($f)) {
                    $roomFeatures[strtolower(trim((string) $f))] = true;
                }
            }
            foreach (($sp['units'] ?? []) as $u) {
                foreach (($u['features'] ?? []) as $f) {
                    if (filled($f)) {
                        $roomFeatures[strtolower(trim((string) $f))] = true;
                    }
                }
            }
        }

        return array_values(array_filter($flat, function ($f) use ($roomFeatures, $explicitGlobal) {
            $k = strtolower(trim((string) $f));
            $roomOnly = isset($roomFeatures[$k]) && !isset($explicitGlobal[$k]);
            return !$roomOnly; // keep global + both-set; drop room-only
        }));
    }

    /**
     * The COMPLETE feature set a property carries: flat features_json UNION every
     * space-level featuresAll UNION every per-unit feature — nothing stripped.
     *
     * This is the correct source for "does the property have amenity X anywhere"
     * presence flags/attributes. globalFeatures() deliberately strips features that
     * live only on a room/space (to keep phantom room-fabric out of listing-level
     * tags), but that same stripping hides genuine amenities entered in the room
     * editor (Fireplace, Built-in Braai, Built-in Cupboards, Walk-in Closet, TV,
     * En-suite, …) from every portal that keys amenities off features — so those
     * amenity flags must source from here, not globalFeatures().
     *
     * @return string[]
     */
    protected function allFeatures(Property $property): array
    {
        $all = array_map('strval', (array) ($property->features_json ?? []));

        foreach ($this->spacesList($property) as $sp) {
            foreach (($sp['featuresAll'] ?? []) as $f) {
                $all[] = (string) $f;
            }
            foreach (($sp['units'] ?? []) as $u) {
                foreach (($u['features'] ?? []) as $f) {
                    $all[] = (string) $f;
                }
            }
        }

        return array_values(array_unique(array_filter($all, fn ($v) => trim($v) !== '')));
    }
}
