<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsParkReason;
use Illuminate\Support\Collection;

/**
 * Road to Sold v3 - the agency's list of reasons stock is set aside (road-to-sold.md section 22.4). Seeded neutral the first time an
 * agency + track is used (never on a read of another agency, never for agency_id <= 0); the agency edits, adds and archives them in Recipe.
 */
class ParkReasonService
{
    /** @var array<string,bool> */
    private static array $seeded = [];

    /** @return array<int,array{key:string,label:string,days:int}> stable keys, neutral wording */
    public static function defaults(): array
    {
        return [
            ['key' => 'chose_other_agency',  'label' => 'Seller chose another agency', 'days' => 90],
            ['key' => 'not_selling_now',     'label' => 'Not selling now',             'days' => 30],
            ['key' => 'sold_elsewhere',      'label' => 'Sold elsewhere',              'days' => 180],
            ['key' => 'mandate_elsewhere',   'label' => 'Mandate with another agency', 'days' => 90],
            ['key' => 'no_owner_found',      'label' => 'No owner found',              'days' => 90],
            ['key' => 'cannot_be_reached',   'label' => 'Cannot be reached',           'days' => 60],
            ['key' => 'price_unrealistic',   'label' => 'Price unrealistic',           'days' => 60],
            ['key' => 'other',               'label' => 'Other',                       'days' => 90],
        ];
    }

    public function ensureDefaults(int $agencyId, string $track = 'sales'): void
    {
        if ($agencyId <= 0 || isset(self::$seeded[$agencyId . '|' . $track])) {
            return;
        }
        $exists = RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->exists();
        if (! $exists) {
            RtsParkReason::withoutAgencyStamping(function () use ($agencyId, $track) {
                foreach (self::defaults() as $i => $r) {
                    RtsParkReason::create([
                        'agency_id' => $agencyId, 'track' => $track, 'key' => $r['key'], 'label' => $r['label'],
                        'default_revisit_days' => $r['days'], 'is_system' => true, 'is_active' => true, 'sort_order' => ($i + 1) * 10,
                    ]);
                }
            });
        }
        self::$seeded[$agencyId . '|' . $track] = true;
    }

    public static function forgetMemo(): void
    {
        self::$seeded = [];
    }

    /** @return Collection<int,RtsParkReason> the reasons an agent may choose, in the agency's order */
    public function choices(int $agencyId, string $track = 'sales'): Collection
    {
        $this->ensureDefaults($agencyId, $track);

        return RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)
            ->whereNull('deleted_at')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** One reason by its stable key; an unknown or archived key falls back to "other", then to the first active reason. */
    public function byKey(int $agencyId, string $track, ?string $key): ?RtsParkReason
    {
        $choices = $this->choices($agencyId, $track);

        return $choices->firstWhere('key', (string) $key) ?? $choices->firstWhere('key', 'other') ?? $choices->first();
    }
}
