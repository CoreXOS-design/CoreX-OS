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
            ['key' => 'not_now',             'label' => 'Not now',                     'days' => 7],
        ];
    }

    /**
     * Road to Sold v4 (road-to-sold.md 23.11): the off-ramp reasons by station. Station = a stage key (found, claimed, owner_found, contacted, appointment_set,
     * evaluation_done) or "result" for the Result step; blockers (kind "blocker") are the derived reasons a draft / active property is stuck - named by the system, never
     * chosen by an agent. Neutral wording; every agency edits its own. A reason with 0 days is "never" (do not contact): it closes the record instead of setting it aside.
     *
     * @return list<array{key:string,station:?string,kind:string,label:string,days:int}>
     */
    public static function stationDefaults(): array
    {
        $o = RtsParkReason::KIND_OFF_RAMP;
        $b = RtsParkReason::KIND_BLOCKER;

        return [
            ['key' => 'not_for_me_wrong_area',  'station' => 'found',           'kind' => $o, 'label' => 'Not for me - wrong area',          'days' => 7],
            ['key' => 'not_for_me_no_address',  'station' => 'found',           'kind' => $o, 'label' => 'Not for me - no address',          'days' => 7],
            ['key' => 'not_for_me_know_it',     'station' => 'found',           'kind' => $o, 'label' => 'Not for me - I already know it',   'days' => 7],
            ['key' => 'not_for_me_other',       'station' => 'found',           'kind' => $o, 'label' => 'Not for me - other',               'days' => 7],
            ['key' => 'not_taking_this',        'station' => 'claimed',         'kind' => $o, 'label' => "I'm not taking this",              'days' => 14],
            ['key' => 'wrong_number',           'station' => 'owner_found',     'kind' => $o, 'label' => 'Wrong number',                     'days' => 90],
            ['key' => 'do_not_contact',         'station' => 'owner_found',     'kind' => $o, 'label' => 'Do not contact',                   'days' => 0],
            ['key' => 'not_interested',         'station' => 'contacted',       'kind' => $o, 'label' => 'Not interested',                   'days' => 180],
            ['key' => 'already_listed_open',    'station' => 'contacted',       'kind' => $o, 'label' => 'Already listed - open mandate',   'days' => 90],
            ['key' => 'selling_privately',      'station' => 'contacted',       'kind' => $o, 'label' => 'Selling privately',                'days' => 30],
            ['key' => 'eval_no_show',           'station' => 'appointment_set', 'kind' => $o, 'label' => 'No-show',                          'days' => 14],
            ['key' => 'eval_cancelled',         'station' => 'appointment_set', 'kind' => $o, 'label' => 'Cancelled',                        'days' => 30],
            ['key' => 'seller_withdrew',        'station' => 'result',          'kind' => $o, 'label' => 'Not selling - seller withdrew',    'days' => 180],
            ['key' => 'family_decision',        'station' => 'result',          'kind' => $o, 'label' => 'Not selling - family decision',    'days' => 180],
            ['key' => 'blk_compliance',         'station' => 'draft',           'kind' => $b, 'label' => 'Compliance incomplete',            'days' => 0],
            ['key' => 'blk_mandate',            'station' => 'draft',           'kind' => $b, 'label' => 'Mandate not signed',               'days' => 0],
            ['key' => 'blk_photos',             'station' => 'draft',           'kind' => $b, 'label' => 'Photos missing',                   'days' => 0],
            ['key' => 'blk_not_published',      'station' => 'active',          'kind' => $b, 'label' => 'Not published',                    'days' => 0],
            ['key' => 'blk_portal_rejected',    'station' => 'active',          'kind' => $b, 'label' => 'Portal rejected',                  'days' => 0],
        ];
    }

    /** The station a reason is picked at for a record at this stage (the reason list shows that station's reasons first). */
    public static function stationOfStage(?string $stage): ?string
    {
        return match (true) {
            $stage === null => null,
            in_array($stage, ['found', 'claimed', 'owner_found', 'contacted', 'appointment_set', 'evaluation_done'], true) => $stage,
            $stage === 'presentation_prepared' => 'appointment_set',
            in_array($stage, ['mandate_signed', 'presentation_given', 'presentation_feedback'], true) => 'result',
            default => null,
        };
    }

    /** Add the station reasons an agency's list does not have yet (a list seeded before they existed). An archived one stays archived. */
    public function ensureStationReasons(int $agencyId, string $track = 'sales'): void
    {
        if (! RtsParkReason::hasStationColumns() || isset(self::$stationSeeded[$agencyId . '|' . $track])) {
            return;
        }
        $have = RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->pluck('key')->flip();
        $order = (int) RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order');
        RtsParkReason::withoutAgencyStamping(function () use ($agencyId, $track, $have, &$order) {
            foreach (self::stationDefaults() as $r) {
                if (isset($have[$r['key']])) {
                    continue;
                }
                RtsParkReason::create([
                    'agency_id' => $agencyId, 'track' => $track, 'station' => $r['station'], 'kind' => $r['kind'], 'key' => $r['key'], 'label' => $r['label'],
                    'default_revisit_days' => $r['days'], 'is_system' => true, 'is_active' => true, 'sort_order' => $order += 10,
                ]);
            }
        });
        self::$stationSeeded[$agencyId . '|' . $track] = true;
    }

    /** @var array<string,bool> */
    private static array $stationSeeded = [];

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

    /**
     * A reason by key, created if the agency does not have it yet (a list seeded before the key existed). Used by the result service for "Not now" (23.9);
     * an agency that archived it keeps it archived - byKey() then falls back to "other".
     */
    public function ensureKey(int $agencyId, string $track, string $key, string $label, int $days): void
    {
        $this->ensureDefaults($agencyId, $track);
        if (RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('key', $key)->exists()) {
            return;
        }
        RtsParkReason::withoutAgencyStamping(fn () => RtsParkReason::create([
            'agency_id' => $agencyId, 'track' => $track, 'key' => $key, 'label' => $label, 'default_revisit_days' => $days, 'is_system' => true, 'is_active' => true,
            'sort_order' => (int) RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order') + 10,
        ]));
    }

    public static function forgetMemo(): void
    {
        self::$seeded = [];
        self::$stationSeeded = [];
    }

    /**
     * @return Collection<int,RtsParkReason> the off-ramp reasons an agent may choose, in the agency's order. With a $station, that station's own reasons come first,
     * then the "any station" ones; another station's specific reasons are left out. Blockers are never choices.
     */
    public function choices(int $agencyId, string $track = 'sales', ?string $station = null): Collection
    {
        $this->ensureDefaults($agencyId, $track);
        $this->ensureStationReasons($agencyId, $track);

        $rows = RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)
            ->whereNull('deleted_at')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        if (RtsParkReason::hasStationColumns()) {
            $rows = $rows->filter(fn ($r) => ($r->kind ?? 'off_ramp') === 'off_ramp')->values();
            if ($station !== null) {
                $rows = $rows->filter(fn ($r) => $r->station === null || $r->station === $station)->values();
                $rows = $rows->sortBy(fn ($r) => $r->station === $station ? 0 : 1)->values();   // stable: the agency's order inside each part
            }
        }

        return $rows;
    }

    /** One reason by its stable key; an unknown or archived key falls back to "other", then to the first active reason. */
    public function byKey(int $agencyId, string $track, ?string $key): ?RtsParkReason
    {
        $choices = $this->choices($agencyId, $track);

        return $choices->firstWhere('key', (string) $key) ?? $choices->firstWhere('key', 'other') ?? $choices->first();
    }
}
