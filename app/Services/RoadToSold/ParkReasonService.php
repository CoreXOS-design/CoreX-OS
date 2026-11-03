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

    /** The nine popup steps (road-to-sold.md 23.25.2), for the list screen and the popup. */
    public const STEPS = [1 => 'Property', 2 => 'Owner', 3 => 'Pitch', 4 => 'Contact', 5 => 'Appointment', 6 => 'Presentation', 7 => 'Presentation results', 8 => 'Draft', 9 => 'Active'];

    /** A step with no list of its own shows another step's: Pitch is not a station (Owner's list), Presentation's reasons live at Presentation results. */
    public const STEP_BORROWS = [3 => 2, 6 => 7];

    /** The popup step a record at this stage is working on (the step whose screen completes into the NEXT station), so an off-ramp there is counted where the record sat. */
    public static function stepOfStage(?string $stage): int
    {
        return match (true) {
            $stage === null, $stage === 'found', $stage === 'claimed' => 2,
            $stage === 'owner_found' => 3,
            $stage === 'contacted' => 5,
            in_array($stage, ['appointment_set'], true) => 6,
            in_array($stage, ['presentation_prepared'], true) => 7,
            in_array($stage, ['evaluation_done', 'mandate_signed', 'presentation_given', 'presentation_feedback'], true) => 8,
            $stage === 'draft' => 9,
            $stage === 'active' => 9,
            default => 1,
        };
    }

    /**
     * road-to-sold.md 23.25 / 23.11 (Johan 3 Nov): the off-ramp reasons of each popup step, seeded as DATA - the agency edits them on its own list screen. Existing reason keys keep
     * their codes and are only given a step (and what they count as); new ones are popup-only (station "popup": never in a station's own list). Every step ends with "Other" (cannot be
     * removed: is_other). counts_as: "not_selling" = leaves the belt like the property page's Not selling; "lost" = the claim is closed as lost to another agency.
     *
     * @return list<array{key:string,step:int,label:string,counts_as:string,days:int,other?:bool,station?:?string}> station only for a NEW key
     */
    public static function stepDefaults(): array
    {
        $n = 'not_selling';
        $l = 'lost';

        return [
            ['key' => 'not_for_me_wrong_area', 'step' => 1, 'label' => 'Not for me - wrong area',        'counts_as' => $n, 'days' => 7],
            ['key' => 'not_for_me_no_address', 'step' => 1, 'label' => 'Not for me - no address',        'counts_as' => $n, 'days' => 7],
            ['key' => 'not_for_me_know_it',    'step' => 1, 'label' => 'Not for me - I already know it', 'counts_as' => $n, 'days' => 7],
            ['key' => 'not_taking_this',       'step' => 1, 'label' => "I'm not taking this",            'counts_as' => $n, 'days' => 14],
            ['key' => 'not_for_me_other',      'step' => 1, 'label' => 'Not for me - other',             'counts_as' => $n, 'days' => 7, 'other' => true],

            ['key' => 'no_owner_found',        'step' => 2, 'label' => 'No owner found',                 'counts_as' => $n, 'days' => 90],
            ['key' => 'no_contact_details',    'step' => 2, 'label' => 'No contact details',             'counts_as' => $n, 'days' => 90, 'station' => 'popup'],
            ['key' => 'other_step_2',          'step' => 2, 'label' => 'Other',                          'counts_as' => $n, 'days' => 90, 'other' => true, 'station' => 'popup'],

            ['key' => 'opted_out',             'step' => 4, 'label' => 'Opted out',                      'counts_as' => $n, 'days' => 0,  'station' => 'popup'],
            ['key' => 'cannot_be_reached',     'step' => 4, 'label' => 'Cannot make contact',           'counts_as' => $n, 'days' => 60],
            ['key' => 'wrong_number',          'step' => 4, 'label' => 'Wrong number',                   'counts_as' => $n, 'days' => 90],
            ['key' => 'not_selling_now',       'step' => 4, 'label' => 'Spoke - not selling',            'counts_as' => $n, 'days' => 30],
            ['key' => 'mandate_elsewhere',     'step' => 4, 'label' => 'Sole mandate elsewhere',         'counts_as' => $n, 'days' => 90],
            ['key' => 'sold_elsewhere',        'step' => 4, 'label' => 'Already sold',                   'counts_as' => $n, 'days' => 180],
            ['key' => 'off_market',            'step' => 4, 'label' => 'Not on the market any more',     'counts_as' => $n, 'days' => 180, 'station' => 'popup'],
            ['key' => 'not_interested',        'step' => 4, 'label' => 'Not interested',                 'counts_as' => $n, 'days' => 180],
            ['key' => 'already_listed_open',    'step' => 4, 'label' => 'Already listed - open mandate',  'counts_as' => $n, 'days' => 90],
            ['key' => 'selling_privately',     'step' => 4, 'label' => 'Selling privately',              'counts_as' => $n, 'days' => 30],
            ['key' => 'do_not_contact',        'step' => 4, 'label' => 'Do not contact',                 'counts_as' => $n, 'days' => 0],
            ['key' => 'not_now',               'step' => 4, 'label' => 'Not now',                        'counts_as' => $n, 'days' => 7],
            ['key' => 'other_step_4',          'step' => 4, 'label' => 'Other',                          'counts_as' => $n, 'days' => 90, 'other' => true, 'station' => 'popup'],

            ['key' => 'seller_cannot_be_met',  'step' => 5, 'label' => 'Seller cannot be met',           'counts_as' => $n, 'days' => 30, 'station' => 'popup'],
            ['key' => 'eval_no_show',          'step' => 5, 'label' => 'No-show',                        'counts_as' => $n, 'days' => 14],
            ['key' => 'eval_cancelled',        'step' => 5, 'label' => 'Cancelled',                      'counts_as' => $n, 'days' => 30],
            ['key' => 'other_step_5',          'step' => 5, 'label' => 'Other',                          'counts_as' => $n, 'days' => 30, 'other' => true, 'station' => 'popup'],

            ['key' => 'price_unrealistic',     'step' => 7, 'label' => 'Overpriced',                     'counts_as' => $n, 'days' => 60],
            ['key' => 'wont_sign_documents',   'step' => 7, 'label' => "Seller won't sign the documents", 'counts_as' => $n, 'days' => 60, 'station' => 'popup'],
            ['key' => 'pres_not_selling',      'step' => 7, 'label' => 'Not selling',                    'counts_as' => $n, 'days' => 180, 'station' => 'popup'],
            ['key' => 'chose_other_agency',    'step' => 7, 'label' => 'Lost to another agency',         'counts_as' => $l, 'days' => 90],
            ['key' => 'seller_withdrew',       'step' => 7, 'label' => 'Not selling - seller withdrew',  'counts_as' => $n, 'days' => 180],
            ['key' => 'family_decision',       'step' => 7, 'label' => 'Not selling - family decision',  'counts_as' => $n, 'days' => 180],
            ['key' => 'other_step_7',          'step' => 7, 'label' => 'Other',                          'counts_as' => $n, 'days' => 60, 'other' => true, 'station' => 'popup'],

            ['key' => 'paperwork_not_returned', 'step' => 8, 'label' => 'Paperwork not returned',        'counts_as' => $n, 'days' => 30, 'station' => 'popup'],
            ['key' => 'seller_no_longer_selling', 'step' => 8, 'label' => 'Seller no longer wants to sell', 'counts_as' => $n, 'days' => 90, 'station' => 'popup'],
            ['key' => 'other_step_8',          'step' => 8, 'label' => 'Other',                          'counts_as' => $n, 'days' => 30, 'other' => true, 'station' => 'popup'],

            ['key' => 'active_paperwork_not_returned', 'step' => 9, 'label' => 'Paperwork not returned', 'counts_as' => $n, 'days' => 30, 'station' => 'popup'],
            ['key' => 'active_no_longer_selling', 'step' => 9, 'label' => 'Seller no longer wants to sell', 'counts_as' => $n, 'days' => 90, 'station' => 'popup'],
            ['key' => 'other_step_9',          'step' => 9, 'label' => 'Other',                          'counts_as' => $n, 'days' => 30, 'other' => true, 'station' => 'popup'],
        ];
    }

    /** A reason that is offered at a second step as well (the revisit / park result "Not now" is a Contact AND a Presentation results reason). */
    public const STEP_ALSO = [7 => ['not_now']];

    /** Johan's words for the reasons that existed before (3 Nov): a row still carrying the OLD default wording is reworded once; an agency's own wording is never touched. */
    public const LABEL_UPGRADES = [
        'not_selling_now'    => ['Not selling now' => 'Spoke - not selling'],
        'mandate_elsewhere'  => ['Mandate with another agency' => 'Sole mandate elsewhere'],
        'sold_elsewhere'     => ['Sold elsewhere' => 'Already sold'],
        'price_unrealistic'  => ['Price unrealistic' => 'Overpriced'],
        'chose_other_agency' => ['Seller chose another agency' => 'Lost to another agency'],
        'cannot_be_reached'  => ['Cannot be reached' => 'Cannot make contact'],
    ];

    /** @var array<string,bool> */
    private static array $stepSeeded = [];

    /**
     * Give the agency's reasons their step, and add the popup-only ones it does not have yet. Idempotent: a row that already has a step is left exactly as the agency edited
     * it (words, days, what it counts as, switched off); an archived default stays archived; nothing is created twice (the key is unique per agency and track).
     */
    public function ensureStepReasons(int $agencyId, string $track = 'sales'): void
    {
        if ($agencyId <= 0 || ! RtsParkReason::hasStepColumns() || isset(self::$stepSeeded[$agencyId . '|' . $track])) {
            return;
        }
        $this->ensureDefaults($agencyId, $track);
        $this->ensureStationReasons($agencyId, $track);
        $have = RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->get()->keyBy('key');
        $order = (int) RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order');
        RtsParkReason::withoutAgencyStamping(function () use ($agencyId, $track, $have, &$order) {
            foreach (self::stepDefaults() as $r) {
                $row = $have[$r['key']] ?? null;
                if ($row) {
                    if ($row->step === null) {
                        $row->forceFill(['step' => $r['step'], 'counts_as' => $r['counts_as'], 'is_other' => (bool) ($r['other'] ?? false)])->saveQuietly();
                    }
                    $up = self::LABEL_UPGRADES[$r['key']][(string) $row->label] ?? null;
                    if ($up !== null) {
                        $row->forceFill(['label' => $up])->saveQuietly();
                    }

                    continue;
                }
                RtsParkReason::create([
                    'agency_id' => $agencyId, 'track' => $track, 'station' => $r['station'] ?? null, 'step' => $r['step'], 'kind' => RtsParkReason::KIND_OFF_RAMP, 'counts_as' => $r['counts_as'],
                    'is_other' => (bool) ($r['other'] ?? false), 'key' => $r['key'], 'label' => $r['label'], 'default_revisit_days' => $r['days'], 'is_system' => true, 'is_active' => true,
                    'sort_order' => $order += 10,
                ]);
            }
        });
        // the old any-station "Other" is retired: each step's own Other replaces it (records that used it keep their wording; byKey still resolves the code). Once - an agency
        // that switches it back on (the list screen records who) keeps it.
        RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->where('key', 'other')->whereNull('step')->where('is_active', true)->whereNull('updated_by_user_id')
            ->update(['is_active' => false]);
        self::$stepSeeded[$agencyId . '|' . $track] = true;
    }

    /** The reasons offered at one popup step (borrowing where a step has no list of its own), "Other" always last. @return Collection<int,RtsParkReason> */
    public function forStep(int $agencyId, string $track, int $step): Collection
    {
        $this->ensureStepReasons($agencyId, $track);
        $eff = self::STEP_BORROWS[$step] ?? $step;

        return RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')->where('is_active', true)
            ->where(fn ($w) => $w->where('step', $eff)->orWhereIn('key', self::STEP_ALSO[$eff] ?? []))->orderBy('is_other')->orderBy('sort_order')->orderBy('id')->get();
    }

    public static function forgetMemo(): void
    {
        self::$seeded = [];
        self::$stationSeeded = [];
        self::$stepSeeded = [];
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
        $exact = $choices->firstWhere('key', (string) $key)
            ?? ($key ? RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('key', (string) $key)->first() : null);

        return $exact ?? $choices->firstWhere('key', 'other') ?? $choices->first();
    }
}
