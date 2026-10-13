<?php

namespace App\Services\RoadToSold\Support;

/**
 * Which suburb a row belongs to, for hot pockets - by suburb ID where the row has one, else by name AND
 * town (the same rule lane 1 uses for areas, spec 5.7): two suburbs of one name in two towns are two
 * pockets, never one.
 *
 * A pocket key is "s<suburb id>" when the suburb is a known pool suburb, otherwise "<lower name>" (or
 * "<lower name>|<lower town>" when the town is known and the name alone would be ambiguous).
 */
final class PocketResolver
{
    /** @var array<int,array{id:int,name:string,city:?int,town:?string}> */
    private array $byId = [];

    /** @var array<string,int[]> lower name => pool suburb ids */
    private array $byName = [];

    /** @param iterable<object{id:int,name:string,p24_city_id:?int,town_name:?string}> $pool */
    public function __construct(iterable $pool = [])
    {
        foreach ($pool as $r) {
            $id = (int) $r->id;
            $this->byId[$id] = ['id' => $id, 'name' => trim((string) $r->name), 'city' => $r->p24_city_id !== null ? (int) $r->p24_city_id : null, 'town' => $r->town_name !== null ? (string) $r->town_name : null];
            $this->byName[mb_strtolower(trim((string) $r->name))][] = $id;
        }
    }

    /** True when exactly one pool suburb carries this (lower-case) name, or none does. */
    public function nameIsUnique(string $lowerName): bool
    {
        return count($this->byName[$lowerName] ?? []) <= 1;
    }

    /**
     * @return array{key:string,name:string,suburb_id:?int,city_id:?int,town:?string}|null null when the row names no suburb
     */
    public function resolve(?int $suburbId, ?int $cityId, ?string $town, ?string $name): ?array
    {
        $lower = mb_strtolower(trim((string) $name));
        $town = $town !== null && trim($town) !== '' ? trim($town) : null;

        if ($suburbId && isset($this->byId[$suburbId])) {
            return $this->pool($suburbId);
        }
        if ($lower === '') {
            return $suburbId ? ['key' => 's' . $suburbId, 'name' => 'Suburb ' . $suburbId, 'suburb_id' => $suburbId, 'city_id' => $cityId, 'town' => $town] : null;
        }

        // no (known) suburb id: by name, and the name must agree with the town where the row has one
        $candidates = $this->byName[$lower] ?? [];
        if (count($candidates) === 1) {
            $only = $this->byId[$candidates[0]];
            $agrees = ($cityId === null && $town === null)
                || ($cityId !== null && $only['city'] === $cityId)
                || ($town !== null && $only['town'] !== null && mb_strtolower($town) === mb_strtolower($only['town']));
            if ($agrees) {
                return $this->pool($only['id']);
            }
        } elseif (count($candidates) > 1) {
            foreach ($candidates as $id) {
                $c = $this->byId[$id];
                if (($cityId !== null && $c['city'] === $cityId) || ($town !== null && $c['town'] !== null && mb_strtolower($town) === mb_strtolower($c['town']))) {
                    return $this->pool($id);
                }
            }
            // ambiguous name, town unknown or not matching any: its own pocket, tied to the town it states
            return ['key' => $lower . '|' . mb_strtolower($town ?? ''), 'name' => trim((string) $name), 'suburb_id' => null, 'city_id' => $cityId, 'town' => $town];
        }

        return ['key' => $lower, 'name' => trim((string) $name), 'suburb_id' => null, 'city_id' => $cityId, 'town' => $town];
    }

    private function pool(int $id): array
    {
        $s = $this->byId[$id];

        return ['key' => 's' . $id, 'name' => $s['name'], 'suburb_id' => $id, 'city_id' => $s['city'], 'town' => $s['town']];
    }
}
