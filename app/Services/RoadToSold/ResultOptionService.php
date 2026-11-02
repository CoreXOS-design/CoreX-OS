<?php

namespace App\Services\RoadToSold;

use App\Models\PresentationOutcome as PO;
use App\Models\RoadToSold\RtsResultOption;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 (road-to-sold.md 23.10): the ONE result list. Reads the agency's own rows (rts_result_options) once the table is there and seeded;
 * until then - and for an agency that has not been seeded yet - the neutral defaults below, so every screen shows the same list from the first day.
 * Also owns the fixed maps between a result and the old presentation outcome enum, and the legacy calendar wording, so no reader of either breaks.
 */
class ResultOptionService
{
    /** @var array<string,array<int,array>> */
    private static array $memo = [];

    public static function tableReady(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('rts_result_options');
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** @return list<array{key:string,label:string,class:string,park_reason_key:?string,needs_date:bool,revisit_days:?int}> neutral wording, stable keys */
    public static function defaults(): array
    {
        return [
            ['key' => 'won_mandate',       'label' => 'Won - mandate agreed',    'class' => 'won',         'park_reason_key' => null,                 'needs_date' => false, 'revisit_days' => null],
            ['key' => 'undecided',         'label' => 'Undecided - follow up',   'class' => 'pending',     'park_reason_key' => 'not_now',            'needs_date' => true,  'revisit_days' => 7],
            ['key' => 'lost_other_agency', 'label' => 'Lost to another agency',  'class' => 'lost',        'park_reason_key' => 'chose_other_agency', 'needs_date' => false, 'revisit_days' => 90],
            ['key' => 'lost_price',        'label' => 'Price too far apart',     'class' => 'lost',        'park_reason_key' => 'price_unrealistic',  'needs_date' => false, 'revisit_days' => 60],
            ['key' => 'lost_not_ready',    'label' => 'Not ready to sell yet',   'class' => 'lost',        'park_reason_key' => 'not_selling_now',    'needs_date' => false, 'revisit_days' => 90],
            ['key' => 'not_selling',       'label' => 'Not selling',             'class' => 'not_selling', 'park_reason_key' => 'not_selling_now',    'needs_date' => false, 'revisit_days' => 180],
        ];
    }

    /** The result key behind each old presentation outcome (read back from existing rows) and the enum written for each result (what every old reader still sees). */
    private const ENUM_FROM_KEY = [
        'won_mandate' => PO::OUTCOME_WON_MANDATE, 'lost_other_agency' => PO::OUTCOME_LOST_TO_COMPETITOR, 'lost_price' => PO::OUTCOME_LOST_TO_PRICE_DISPUTE,
        'lost_not_ready' => PO::OUTCOME_LOST_TO_NO_DECISION, 'undecided' => PO::OUTCOME_STILL_PENDING, 'not_selling' => PO::OUTCOME_OTHER,
    ];
    private const KEY_FROM_ENUM = [
        PO::OUTCOME_WON_MANDATE => 'won_mandate', PO::OUTCOME_WON_SALE => 'won_mandate', PO::OUTCOME_LOST_TO_COMPETITOR => 'lost_other_agency',
        PO::OUTCOME_LOST_TO_PRICE_DISPUTE => 'lost_price', PO::OUTCOME_LOST_TO_NO_DECISION => 'lost_not_ready', PO::OUTCOME_LOST_TO_NO_RESPONSE => 'lost_not_ready',
        PO::OUTCOME_STILL_PENDING => 'undecided', PO::OUTCOME_OTHER => 'not_selling',
    ];
    /** The calendar's old seller-facing wording (agency_feedback_options lp_outcome) -> result key. "Reschedule" is a status now, not a result. */
    private const KEY_FROM_LEGACY_LABEL = [
        'mandate signed' => 'won_mandate', 'considering' => 'undecided', 'lost' => 'lost_other_agency', 'other agent' => 'lost_other_agency',
        'pricing too far' => 'lost_price', 'not ready' => 'lost_not_ready',
    ];

    public static function enumForKey(string $key): string
    {
        return self::ENUM_FROM_KEY[$key] ?? PO::OUTCOME_OTHER;
    }

    public static function keyForEnum(?string $enum): ?string
    {
        return $enum !== null ? (self::KEY_FROM_ENUM[$enum] ?? null) : null;
    }

    /** @return list<array> the agency's active options in order (rows when seeded, else the defaults) */
    public function list(int $agencyId, string $track = 'sales', bool $includeArchived = false): array
    {
        $memoKey = $agencyId . '|' . $track . '|' . (int) $includeArchived;
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $out = null;
        if (self::tableReady()) {
            $this->seed($agencyId, $track);
            $q = RtsResultOption::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->orderBy('sort_order')->orderBy('id');
            if (! $includeArchived) {
                $q->whereNull('deleted_at')->where('is_active', true);
            } else {
                $q->withTrashed();
            }
            $rows = $q->get();
            $out = $rows->isEmpty() ? null : $rows->map(fn ($r) => [
                'key' => $r->key, 'label' => $r->label, 'class' => $r->class, 'park_reason_key' => $r->park_reason_key,
                'needs_date' => (bool) $r->needs_date, 'revisit_days' => $r->revisit_days,
            ])->all();
        }

        return self::$memo[$memoKey] = $out ?? self::defaults();
    }

    /** Seed the neutral list once for an agency that has none (rows only; never overwrites an edit). */
    public function seed(int $agencyId, string $track = 'sales'): void
    {
        if (! self::tableReady() || RtsResultOption::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->exists()) {
            return;
        }
        RtsResultOption::withoutAgencyStamping(function () use ($agencyId, $track) {
            foreach (self::defaults() as $i => $d) {
                RtsResultOption::create($d + ['agency_id' => $agencyId, 'track' => $track, 'is_system' => true, 'is_active' => true, 'sort_order' => ($i + 1) * 10]);
            }
        });
    }

    /** @return array|null one option by stable key (archived ones included, so an old row keeps its wording) */
    public function byKey(int $agencyId, string $key, string $track = 'sales'): ?array
    {
        foreach ($this->list($agencyId, $track, true) as $o) {
            if ($o['key'] === $key) {
                return $o;
            }
        }

        return null;
    }

    /** The result a calendar feedback answer names: its own key, else a label of the agency's list, else the old wording. Null when it names none (or is "Reschedule"). */
    public function keyForLabel(int $agencyId, ?string $label, string $track = 'sales'): ?string
    {
        $l = mb_strtolower(trim((string) $label));
        if ($l === '') {
            return null;
        }
        foreach ($this->list($agencyId, $track, true) as $o) {
            if (mb_strtolower($o['label']) === $l || $o['key'] === $l) {
                return $o['key'];
            }
        }

        return self::KEY_FROM_LEGACY_LABEL[$l] ?? null;
    }
}
