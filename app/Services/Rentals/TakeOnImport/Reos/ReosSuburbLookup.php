<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\P24Suburb;

/**
 * AT-479 / rental-takeon-import.md R4.1 — the suburb picker's data, from the SAME tables the property form's cascading
 * P24 picker reads (p24_suburbs → p24_cities → p24_provinces, only rows Property24 confirmed). Picking a suburb fixes the
 * town (the P24 city) and the province, so the user types one thing.
 */
final class ReosSuburbLookup
{
    /** @return list<array{id:int,suburb:string,town:?string,province:?string,city_id:?int,province_id:?int,label:string}> */
    public static function search(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        $rows = P24Suburb::query()
            ->whereNotNull('p24_verified_at')
            ->where('name', 'like', $like)
            ->with('city.province')
            ->orderByRaw('name = ? desc', [$q])
            ->orderByRaw('name like ? desc', [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $rows->map(fn (P24Suburb $s) => self::shape($s))->values()->all();
    }

    /** @return array{id:int,suburb:string,town:?string,province:?string,city_id:?int,province_id:?int,label:string}|null */
    public static function find(int $id): ?array
    {
        $s = P24Suburb::query()->whereNotNull('p24_verified_at')->with('city.province')->find($id);

        return $s ? self::shape($s) : null;
    }

    private static function shape(P24Suburb $s): array
    {
        $city = $s->city;
        $province = $city?->province;

        return [
            'id' => (int) $s->id,
            'suburb' => (string) $s->name,
            'town' => $city?->name,
            'province' => $province?->name,
            'city_id' => $city ? (int) $city->id : null,
            'province_id' => $province ? (int) $province->id : null,
            'label' => trim($s->name . ($city ? ', ' . $city->name : '') . ($province ? ' (' . $province->name . ')' : '')),
        ];
    }
}
