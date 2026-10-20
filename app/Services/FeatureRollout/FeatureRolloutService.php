<?php

namespace App\Services\FeatureRollout;

use App\Events\AgencyFeatureToggled;
use App\Events\FeatureRollout\FeatureRolloutChanged;
use App\Events\FeatureRollout\FeatureWaveReleased;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyFeatureWriter;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Gradual feature rollout for new agencies (AT-478). Spec: .ai/specs/agency-feature-rollout.md.
 *
 * A SCHEDULE on top of the existing per-agency feature switches — never a second switch system:
 * every on/off goes through AgencyFeatureWriter (the agency-type seeder's write path), so the
 * Features page, the wizard and the gate always agree.
 *
 *  start()      copy the Dev Settings default waves into the agency's own plan, switch the held
 *               features off, and date every wave (RolloutCalendar).
 *  releaseDue() the scheduler: release each lane's next wave once its day + release time has come.
 *  releaseNext / pause / resume / finish — admin and owner controls.
 *  onFeatureToggled() — an admin switching a held feature on early marks it released_early.
 *
 * Plans are read and written by EXPLICIT agency id through queryWithoutAgencyScope(): the scheduler
 * runs with no signed-in user and the owner tools act on other agencies (multi-tenancy rule #5).
 * Request code for an agency's own admin goes through forAgency(), which is the same explicit filter.
 */
class FeatureRolloutService
{
    /** True while this service is writing switches, so its own AgencyFeatureToggled events are ignored. */
    private static bool $applying = false;

    public function __construct(
        private readonly AgencyFeatureService $features,
        private readonly AgencyFeatureWriter $writer,
        private readonly RolloutCalendar $calendar,
        private readonly FeatureReadiness $readiness,
    ) {
    }

    /**
     * The rollout's history (spec §12.10): one FeatureRolloutChanged per change, written to domain_event_log
     * by the wildcard audit listener. The actor is whoever is signed in (null = the scheduler).
     *
     * @param array<string,mixed> $details
     */
    private function record(int $agencyId, string $action, array $details = []): void
    {
        event(new FeatureRolloutChanged($agencyId, $action, $details, Auth::id()));
    }

    // ── Pacing defaults (Dev Settings) ─────────────────────────────────────────

    /** @return array{interval_days:int, weekdays_only:bool, skip_holidays:bool, holiday_country:string, release_time:string} */
    public function defaults(): array
    {
        $d = (array) config('feature-rollout.defaults', []);

        return [
            'interval_days'   => max(1, min($this->intervalMax(), (int) DevSetting::get('feature_rollout.interval_days', $d['interval_days'] ?? 2))),
            'weekdays_only'   => DevSetting::bool('feature_rollout.weekdays_only', (bool) ($d['weekdays_only'] ?? true)),
            'skip_holidays'   => DevSetting::bool('feature_rollout.skip_holidays', (bool) ($d['skip_holidays'] ?? true)),
            'holiday_country' => strtoupper((string) DevSetting::get('feature_rollout.holiday_country', $d['holiday_country'] ?? 'ZA')),
            'release_time'    => (string) DevSetting::get('feature_rollout.release_time', $d['release_time'] ?? '07:00'),
        ];
    }

    public function saveDefaults(array $values): void
    {
        DevSetting::set('feature_rollout.interval_days', (string) max(1, min($this->intervalMax(), (int) $values['interval_days'])));
        DevSetting::set('feature_rollout.weekdays_only', ! empty($values['weekdays_only']) ? '1' : '0');
        DevSetting::set('feature_rollout.skip_holidays', ! empty($values['skip_holidays']) ? '1' : '0');
        DevSetting::set('feature_rollout.holiday_country', strtoupper((string) $values['holiday_country']));
        DevSetting::set('feature_rollout.release_time', substr((string) $values['release_time'], 0, 5));
    }

    public function intervalMax(): int
    {
        return (int) config('feature-rollout.interval_max', 10);
    }

    /**
     * Feature keys a wave may contain: switchable features only. Core is always on; the opt-in list
     * (syndication, payroll) starts off for every new agency, so a rollout never holds or releases it.
     *
     * @return array<string,string> key => label
     */
    public function waveableFeatures(): array
    {
        $optIn = (array) config('agency-types.opt_in_features', []);
        $out = [];
        foreach ($this->features->catalogue() as $key => $def) {
            if (! empty($def['core']) || in_array($key, $optIn, true)) {
                continue;
            }
            $out[$key] = $def['label'] ?? $key;
        }
        asort($out);

        return $out;
    }

    // ── Reading a plan ─────────────────────────────────────────────────────────

    public function forAgency(int $agencyId): ?AgencyFeatureRollout
    {
        return AgencyFeatureRollout::queryWithoutAgencyScope()->where('agency_id', $agencyId)->first();
    }

    /** @return Collection<int,AgencyRolloutWave> */
    public function wavesOf(AgencyFeatureRollout $plan): Collection
    {
        return AgencyRolloutWave::queryWithoutAgencyScope()
            ->where('rollout_id', $plan->id)
            ->orderBy('lane')->orderBy('position')->orderBy('id')
            ->get();
    }

    /** @return Collection<int,AgencyRolloutItem> */
    public function itemsOf(AgencyRolloutWave $wave): Collection
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()->where('wave_id', $wave->id)->orderBy('id')->get();
    }

    // ── Starting a plan ────────────────────────────────────────────────────────

    /**
     * Put an agency on gradual rollout. Holds every switchable feature that is currently ON and appears
     * in a default wave; features in no wave are left on (spec §3.2, Dev Settings flags them).
     *
     * @param array{interval_days?:int, weekdays_only?:bool, skip_holidays?:bool, holiday_country?:string, release_time?:string, side_by_side?:bool} $options
     */
    public function start(Agency $agency, array $options = [], ?int $userId = null): AgencyFeatureRollout
    {
        // One plan per agency, ever: gradual rollout is a first-start choice, and a finished plan is the
        // record of how the agency was introduced (never deleted — non-negotiable #1).
        $existing = $this->forAgency((int) $agency->id);
        if ($existing) {
            return $existing;
        }

        $type = AgencyTypeFeatureSeeder::normalise($agency->agency_type);
        $settings = array_merge($this->defaults(), array_intersect_key($options, array_flip([
            'interval_days', 'weekdays_only', 'skip_holidays', 'holiday_country', 'release_time',
        ])));
        $sideBySide = $type === 'both' && ! empty($options['side_by_side']);

        return DB::transaction(function () use ($agency, $settings, $sideBySide, $userId) {
            $plan = AgencyFeatureRollout::withoutAgencyStamping(fn () => AgencyFeatureRollout::create([
                'agency_id'       => $agency->id,
                'status'          => AgencyFeatureRollout::STATUS_ACTIVE,
                'interval_days'   => max(1, min($this->intervalMax(), (int) $settings['interval_days'])),
                'weekdays_only'   => (bool) $settings['weekdays_only'],
                'skip_holidays'   => (bool) $settings['skip_holidays'],
                'holiday_country' => strtoupper((string) $settings['holiday_country']),
                'release_time'    => substr((string) $settings['release_time'], 0, 5),
                'side_by_side'    => $sideBySide,
                'started_on'      => $this->calendar->today()->toDateString(),
                'created_by'      => $userId,
            ]));

            $held = [];

            // Waves are created in plan order. For a sales-and-rentals agency that is the default order
            // across BOTH lanes, so a later "side by side off" (relane) can merge lanes back by creation order.
            $rows = [];
            foreach ($this->planFor($agency, $sideBySide) as $lane => $waves) {
                foreach ($waves as $w) {
                    $rows[] = ['lane' => $lane] + $w;
                }
            }
            if ($sideBySide) {
                usort($rows, fn ($a, $b) => [$a['default']->position, $a['default']->id] <=> [$b['default']->position, $b['default']->id]);
            }

            $positions = [];
            foreach ($rows as ['lane' => $lane, 'default' => $default, 'keys' => $keys]) {
                $positions[$lane] = ($positions[$lane] ?? 0) + 10;
                $wave = AgencyRolloutWave::withoutAgencyStamping(fn () => AgencyRolloutWave::create([
                    'agency_id'  => $agency->id,
                    'rollout_id' => $plan->id,
                    'source_wave_id' => $default->id,
                    'lane'       => $lane,
                    'track'      => $default->track,
                    'position'   => $positions[$lane],
                    'title'      => $default->title,
                    'intro_text' => $default->intro_text,
                ]));

                foreach ($keys as $key) {
                    AgencyRolloutItem::withoutAgencyStamping(fn () => AgencyRolloutItem::create([
                        'agency_id'   => $agency->id,
                        'rollout_id'  => $plan->id,
                        'wave_id'     => $wave->id,
                        'feature_key' => $key,
                        'state'       => AgencyRolloutItem::STATE_HELD,
                    ]));
                    $held[$key] = false;
                }
            }

            $this->apply($agency, $held, $userId);
            $this->schedule($plan, $this->calendar->today());

            if ($held === []) {
                $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_COMPLETED, 'completed_at' => now()])->save();
            }
            $this->record((int) $agency->id, 'started', [
                'held' => array_keys($held), 'interval_days' => (int) $plan->interval_days,
                'weekdays_only' => (bool) $plan->weekdays_only, 'side_by_side' => (bool) $plan->side_by_side,
            ]);

            return $plan->fresh();
        });
    }

    /**
     * What start() would do for this agency right now: per lane, each default wave that still has
     * something to hold, with the features it holds (switchable, currently ON, not taken by an
     * earlier wave). Shared by start() and the "this will switch off N features" preview.
     *
     * @return array<int, list<array{default: FeatureRolloutWave, keys: list<string>}>>
     */
    public function planFor(Agency $agency, bool $sideBySide = false): array
    {
        $type = AgencyTypeFeatureSeeder::normalise($agency->agency_type);
        $sideBySide = $type === 'both' && $sideBySide;
        $current = $this->features->all($agency);
        $waveable = $this->waveableFeatures();
        $taken = [];
        $out = [];

        foreach ($this->lanesFor($type, $sideBySide) as $lane => $defaults) {
            foreach ($defaults as $default) {
                $keys = [];
                foreach ((array) $default->feature_keys as $key) {
                    if (isset($waveable[$key]) && ! isset($taken[$key]) && ($current[$key] ?? false)) {
                        $keys[] = $key;
                        $taken[$key] = true;
                    }
                }
                if ($keys !== []) {
                    $out[$lane][] = ['default' => $default, 'keys' => $keys];
                }
            }
        }

        return $out;
    }

    /** @return list<string> the feature keys start() would switch off right now */
    public function holdPreview(Agency $agency, bool $sideBySide = false): array
    {
        $keys = [];
        foreach ($this->planFor($agency, $sideBySide) as $waves) {
            foreach ($waves as $w) {
                array_push($keys, ...$w['keys']);
            }
        }

        return $keys;
    }

    /**
     * Default waves per lane for an agency type (spec §2.4).
     *
     * @return array<int, Collection<int,FeatureRolloutWave>>
     */
    private function lanesFor(string $type, bool $sideBySide): array
    {
        $all = FeatureRolloutWave::orderBy('position')->orderBy('id')->get();
        $of = fn (array $tracks) => $all->filter(fn ($w) => in_array($w->track, $tracks, true))->values();

        return match (true) {
            $type === 'rentals' => [1 => $of(['rentals'])->concat($of(['general']))->values()],
            $type === 'sales'   => [1 => $of(['general', 'sales'])],
            $sideBySide         => [1 => $of(['general', 'sales']), 2 => $of(['rentals'])],
            default             => [1 => $all->values()],
        };
    }

    // ── Scheduling ─────────────────────────────────────────────────────────────

    /**
     * Date every unreleased wave from $from: in each lane, the k-th unreleased wave is k × interval
     * release days after $from. Used by start, resume, release-now and interval changes alike.
     */
    public function schedule(AgencyFeatureRollout $plan, Carbon $from): void
    {
        foreach ($this->wavesOf($plan)->whereNull('released_at')->groupBy('lane') as $waves) {
            $k = 0;
            foreach ($waves->sortBy('position') as $wave) {
                $k++;
                $wave->forceFill([
                    'scheduled_for' => $this->calendar->addReleaseDays($from, $k * $plan->interval_days, $plan)->toDateString(),
                ])->save();
            }
        }
    }

    // ── Releasing ──────────────────────────────────────────────────────────────

    /**
     * The scheduler. For every ACTIVE plan, release each lane's next wave once its scheduled day has
     * arrived and the release time has passed — and only on a release day, so nothing goes out on a
     * weekend or holiday even if a date slipped. One wave per lane per run, so a missed day catches up
     * gradually instead of dumping several waves at once. Idempotent.
     *
     * @return int waves released
     */
    public function releaseDue(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone($this->calendar->timezone());
        $released = 0;

        $plans = AgencyFeatureRollout::queryWithoutAgencyScope()
            ->where('status', AgencyFeatureRollout::STATUS_ACTIVE)
            ->get();

        foreach ($plans as $plan) {
            if (! $this->calendar->isReleaseDay($now, $plan)) {
                continue;
            }

            foreach ($this->nextWavePerLane($plan) as $wave) {
                if ($wave->scheduled_for === null || $wave->scheduled_for->toDateString() > $now->toDateString()) {
                    continue;
                }
                if ($now->lt($this->calendar->releaseMoment($now, $plan))) {
                    continue;
                }
                $this->release($wave, null);
                $released++;
            }
        }
        $this->releaseReadyItems($now);

        return $released;
    }

    /**
     * Features waiting as "Set up first" whose basics are now filled in are switched on (spec §12.1).
     * Not for a paused or stopped plan — paused means nothing new switches on.
     *
     * @return int features switched on
     */
    public function releaseReadyItems(?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone($this->calendar->timezone());
        $count = 0;
        $items = AgencyRolloutItem::queryWithoutAgencyScope()->where('state', AgencyRolloutItem::STATE_AWAITING_SETUP)->get();
        foreach ($items->groupBy('rollout_id') as $rolloutId => $planItems) {
            $plan = AgencyFeatureRollout::queryWithoutAgencyScope()->find($rolloutId);
            if (! $plan || in_array($plan->status, [AgencyFeatureRollout::STATUS_PAUSED, AgencyFeatureRollout::STATUS_CANCELLED], true)) {
                continue;
            }
            // Same calendar as a wave: only on a release day, once the release time has come.
            if (! $this->calendar->isReleaseDay($now, $plan) || $now->lt($this->calendar->releaseMoment($now, $plan))) {
                continue;
            }
            $agency = Agency::withoutGlobalScopes()->find($plan->agency_id);
            foreach ($planItems as $item) {
                if ($agency && $this->readiness->ready($agency, $item->feature_key)) {
                    DB::transaction(function () use ($item, $agency) {
                        $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED, 'released_at' => now()])->save();
                        $this->apply($agency, [$item->feature_key => true], null);
                    });
                    $this->record((int) $agency->id, 'ready_switched_on', ['feature' => $item->feature_key]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return Collection<int,AgencyRolloutWave> the first unreleased wave of each lane */
    public function nextWavePerLane(AgencyFeatureRollout $plan): Collection
    {
        return $this->wavesOf($plan)
            ->whereNull('released_at')
            ->groupBy('lane')
            ->map(fn (Collection $w) => $w->sortBy('position')->first())
            ->values();
    }

    /** Release one wave now: switch its held features on (outward-facing ones wait for the admin). */
    public function release(AgencyRolloutWave $wave, ?int $userId): void
    {
        if ($wave->released_at !== null) {
            return;
        }

        $agency = Agency::withoutGlobalScopes()->findOrFail($wave->agency_id);
        $adminOnly = (array) config('feature-rollout.admin_switch_on', []);
        $on = [];
        $awaiting = [];

        DB::transaction(function () use ($wave, $agency, $adminOnly, $userId, &$on, &$awaiting) {
            foreach ($this->itemsOf($wave)->where('state', AgencyRolloutItem::STATE_HELD) as $item) {
                if (in_array($item->feature_key, $adminOnly, true)) {
                    $item->forceFill(['state' => AgencyRolloutItem::STATE_AWAITING_ADMIN])->save();
                    $awaiting[] = $item->feature_key;
                    continue;
                }
                // §12.1 — the basics are not set up yet: wait, and switch on once they are (releaseReadyItems).
                if (! $this->readiness->ready($agency, $item->feature_key)) {
                    $item->forceFill(['state' => AgencyRolloutItem::STATE_AWAITING_SETUP])->save();
                    $awaiting[] = $item->feature_key;
                    continue;
                }
                $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED, 'released_at' => now()])->save();
                $on[$item->feature_key] = true;
            }

            $this->apply($agency, $on, $userId);
            $wave->forceFill(['released_at' => now()])->save();
            $this->completeIfDone($wave->rollout_id);
        });

        event(new FeatureWaveReleased((int) $agency->id, (int) $wave->id, array_keys($on), $awaiting, $userId));
    }

    /** "Release the next wave now" — each lane's next wave, then the rest re-dated from today. */
    public function releaseNext(AgencyFeatureRollout $plan, ?int $userId): int
    {
        $count = 0;
        foreach ($this->nextWavePerLane($plan) as $wave) {
            $this->release($wave, $userId);
            $count++;
        }
        $plan->refresh();
        if ($plan->status !== AgencyFeatureRollout::STATUS_COMPLETED) {
            $this->schedule($plan, $this->calendar->today());
        }

        return $count;
    }

    public function pause(AgencyFeatureRollout $plan): void
    {
        if ($plan->status === AgencyFeatureRollout::STATUS_ACTIVE) {
            $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_PAUSED, 'paused_at' => now()])->save();
            $this->record((int) $plan->agency_id, 'paused');
        }
    }

    public function resume(AgencyFeatureRollout $plan): void
    {
        if ($plan->status === AgencyFeatureRollout::STATUS_PAUSED) {
            $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_ACTIVE, 'paused_at' => null])->save();
            $this->schedule($plan, $this->calendar->today());
            $this->record((int) $plan->agency_id, 'resumed');
        }
    }

    /** "Turn everything on" — an explicit admin action, so outward-facing features come on too. */
    public function finish(AgencyFeatureRollout $plan, ?int $userId): void
    {
        $agency = Agency::withoutGlobalScopes()->findOrFail($plan->agency_id);

        DB::transaction(function () use ($plan, $agency, $userId) {
            $on = [];
            $items = AgencyRolloutItem::queryWithoutAgencyScope()
                ->where('rollout_id', $plan->id)
                ->whereIn('state', [AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_AWAITING_ADMIN, AgencyRolloutItem::STATE_AWAITING_SETUP])
                ->get();
            foreach ($items as $item) {
                $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED, 'released_at' => now()])->save();
                $on[$item->feature_key] = true;
            }
            $this->apply($agency, $on, $userId);

            AgencyRolloutWave::queryWithoutAgencyScope()
                ->where('rollout_id', $plan->id)->whereNull('released_at')
                ->update(['released_at' => now()]);

            $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_COMPLETED, 'completed_at' => now(), 'paused_at' => null])->save();
            $this->record((int) $plan->agency_id, 'finished', ['switched_on' => array_keys($on)]);
        });
    }

    /**
     * The agency admin changes the pace of a running plan (spec §5.2). Every unreleased wave is re-dated
     * from today through RolloutCalendar. Side by side applies only to a sales-and-rentals agency: it moves
     * the unreleased Rentals waves into lane 2 (on) or back into the one ordered list (off).
     *
     * @param array{interval_days?:int, weekdays_only?:bool, skip_holidays?:bool, release_time?:string, side_by_side?:bool} $values
     */
    public function updateSettings(AgencyFeatureRollout $plan, array $values): void
    {
        if (! in_array($plan->status, [AgencyFeatureRollout::STATUS_ACTIVE, AgencyFeatureRollout::STATUS_PAUSED], true)) {
            return;
        }

        $agency = Agency::withoutGlobalScopes()->findOrFail($plan->agency_id);
        $both = AgencyTypeFeatureSeeder::normalise($agency->agency_type) === 'both';

        DB::transaction(function () use ($plan, $values, $both) {
            if (array_key_exists('interval_days', $values)) {
                $plan->interval_days = max(1, min($this->intervalMax(), (int) $values['interval_days']));
            }
            if (array_key_exists('weekdays_only', $values)) {
                $plan->weekdays_only = (bool) $values['weekdays_only'];
            }
            if (array_key_exists('skip_holidays', $values)) {
                $plan->skip_holidays = (bool) $values['skip_holidays'];
            }
            if (array_key_exists('release_time', $values) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $values['release_time'])) {
                $plan->release_time = (string) $values['release_time'];
            }
            if (array_key_exists('side_by_side', $values)) {
                $want = $both && (bool) $values['side_by_side'];
                if ($want !== (bool) $plan->side_by_side) {
                    $plan->side_by_side = $want;
                    $this->relane($plan, $want);
                }
            }
            $changed = array_keys($plan->getDirty());
            $plan->save();
            $this->schedule($plan, $this->calendar->today());
            if ($changed !== []) {
                $this->record((int) $plan->agency_id, 'pace_changed', array_intersect_key($plan->only([
                    'interval_days', 'weekdays_only', 'skip_holidays', 'release_time', 'side_by_side',
                ]), array_flip($changed)));
            }
        });
    }

    /** Move unreleased Rentals waves to lane 2 (side by side) or back to lane 1, keeping their order. */
    private function relane(AgencyFeatureRollout $plan, bool $sideBySide): void
    {
        $waves = $this->wavesOf($plan)->whereNull('released_at');
        foreach ($waves as $wave) {
            $lane = ($sideBySide && $wave->track === 'rentals') ? 2 : 1;
            if ($wave->lane !== $lane) {
                $wave->forceFill(['lane' => $lane])->save();
            }
        }
        // Side by side only exists for sales-and-rentals agencies, whose waves follow the ONE default order
        // and were copied in that order — so creation order (id) is the default order. Re-number each lane
        // by it, so waves merged back into lane 1 slot in where the default list puts them.
        foreach ($this->wavesOf($plan)->whereNull('released_at')->groupBy('lane') as $laneWaves) {
            $n = 0;
            foreach ($laneWaves->sortBy('id') as $wave) {
                $wave->forceFill(['position' => $n += 10])->save();
            }
        }
    }

    /**
     * "Skip this feature" — it is taken out of the plan and stays off; the admin can still switch it on
     * from the Features page at any time (spec §2.7, §2.8: a dropped feature is never switched on by the plan).
     */
    public function skipItem(AgencyRolloutItem $item): void
    {
        if (in_array($item->state, [AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_AWAITING_ADMIN, AgencyRolloutItem::STATE_AWAITING_SETUP], true)) {
            $item->forceFill(['state' => AgencyRolloutItem::STATE_DROPPED])->save();
            $this->record((int) $item->agency_id, 'feature_skipped', ['feature' => $item->feature_key]);
        }
    }

    /**
     * Switch on a feature that is waiting for the admin (an outward-facing one, spec §2.9).
     */
    public function switchOnAwaiting(AgencyRolloutItem $item, ?int $userId): void
    {
        if (! in_array($item->state, [AgencyRolloutItem::STATE_AWAITING_ADMIN, AgencyRolloutItem::STATE_AWAITING_SETUP], true)) {
            return;
        }
        $agency = Agency::withoutGlobalScopes()->findOrFail($item->agency_id);
        DB::transaction(function () use ($item, $agency, $userId) {
            $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED, 'released_at' => now()])->save();
            $this->apply($agency, [$item->feature_key => true], $userId);
        });
        $this->record((int) $agency->id, 'feature_switched_on', ['feature' => $item->feature_key]);
    }

    // ── Listing a plan's waves (agency panel + owner page) ─────────────────────

    /**
     * A plan's waves with their items, filtered and sorted for a list screen, plus feature totals.
     * status: all | upcoming | released | archived (archived = soft-deleted waves, owner page only).
     *
     * @param  array{q?:?string, status?:?string, from?:?string, to?:?string, sort?:?string} $filters
     * @param  array<string,string> $labels feature key => label (search by feature name)
     * @return array{waves: Collection<int,AgencyRolloutWave>, totals: array{features:int, released:int}}
     */
    public function listWaves(AgencyFeatureRollout $plan, array $filters, array $labels): array
    {
        $status = $filters['status'] ?? 'all';
        $withItems = function (AgencyRolloutWave $w) {
            $w->setRelation('items', $this->itemsOf($w));
            return $w;
        };

        $live = $this->wavesOf($plan)->map($withItems);
        $totals = ['features' => 0, 'released' => 0];
        foreach ($live as $w) {
            foreach ($w->items as $item) {
                $totals['features']++;
                if (in_array($item->state, [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY], true)) {
                    $totals['released']++;
                }
            }
        }

        $waves = $status === 'archived'
            ? AgencyRolloutWave::queryWithoutAgencyScope()->onlyTrashed()->where('rollout_id', $plan->id)->get()->map($withItems)
            : $live;

        $q = mb_strtolower((string) ($filters['q'] ?? ''));
        $waves = $waves
            ->when($status === 'upcoming', fn ($c) => $c->whereNull('released_at'))
            ->when($status === 'released', fn ($c) => $c->whereNotNull('released_at'))
            ->when(! empty($filters['from']), fn ($c) => $c->filter(fn ($w) => $w->scheduled_for && $w->scheduled_for->toDateString() >= $filters['from']))
            ->when(! empty($filters['to']), fn ($c) => $c->filter(fn ($w) => $w->scheduled_for && $w->scheduled_for->toDateString() <= $filters['to']))
            ->when($q !== '', fn ($c) => $c->filter(fn ($w) => str_contains(mb_strtolower($w->title), $q)
                || $w->items->contains(fn ($i) => str_contains(mb_strtolower($labels[$i->feature_key] ?? $i->feature_key), $q))))
            ->sortBy(($filters['sort'] ?? 'date') === 'name'
                ? [['title', 'asc']]
                : [fn ($a, $b) => [$a->scheduled_for?->timestamp ?? PHP_INT_MAX, $a->lane, $a->position] <=> [$b->scheduled_for?->timestamp ?? PHP_INT_MAX, $b->lane, $b->position]])
            ->values();

        return ['waves' => $waves, 'totals' => $totals];
    }

    /**
     * One line per agency for the owner's Agency Setup Progress list (spec §5.5). Two queries for any
     * number of agencies. key: on | paused | completed | none; rank orders the Rollout sort.
     *
     * @param  list<int> $agencyIds
     * @return array<int, array{key:string, label:string, rank:int, next:?string}>
     */
    public function summaries(array $agencyIds): array
    {
        $plans = AgencyFeatureRollout::queryWithoutAgencyScope()->whereIn('agency_id', $agencyIds)->get()->keyBy('agency_id');
        $waves = AgencyRolloutWave::queryWithoutAgencyScope()->whereIn('rollout_id', $plans->pluck('id'))
            ->get(['id', 'rollout_id', 'scheduled_for', 'released_at'])->groupBy('rollout_id');

        $out = [];
        foreach ($agencyIds as $agencyId) {
            $plan = $plans->get($agencyId);
            if (! $plan) {
                $out[$agencyId] = ['key' => 'none', 'label' => 'Not on gradual introduction', 'rank' => 4, 'next' => null];
                continue;
            }
            $mine = $waves->get($plan->id, collect());
            $done = $mine->whereNotNull('released_at')->count();
            $next = $mine->whereNull('released_at')->whereNotNull('scheduled_for')->sortBy('scheduled_for')->first()?->scheduled_for;
            $of = $done . ' of ' . $mine->count() . ' groups on';

            $out[$agencyId] = match ($plan->status) {
                AgencyFeatureRollout::STATUS_PAUSED    => ['key' => 'paused', 'label' => 'Paused · ' . $of, 'rank' => 2, 'next' => null],
                AgencyFeatureRollout::STATUS_ACTIVE    => ['key' => 'on', 'label' => $of . ($next ? ' · next ' . $next->format('D j M') : ''), 'rank' => 1, 'next' => $next?->toDateString()],
                default                                => ['key' => 'completed', 'label' => 'Finished', 'rank' => 3, 'next' => null],
            };
        }

        return $out;
    }

    // ── Changing a running plan (CoreX team, spec §5.5) ────────────────────────
    // Released waves are history and never change. Every change marks the wave customised_at (the
    // "changed for this agency" badge) and re-dates the unreleased waves from today. A refused change
    // throws \DomainException with a sentence the page shows as it is.

    public function isOpen(AgencyFeatureRollout $plan): bool
    {
        return in_array($plan->status, [AgencyFeatureRollout::STATUS_ACTIVE, AgencyFeatureRollout::STATUS_PAUSED], true);
    }

    private function assertChangeable(AgencyRolloutWave $wave): AgencyFeatureRollout
    {
        $plan = AgencyFeatureRollout::queryWithoutAgencyScope()->findOrFail($wave->rollout_id);
        if (! $this->isOpen($plan)) {
            throw new \DomainException('This introduction has finished, so its groups can no longer be changed.');
        }
        if ($wave->released_at !== null) {
            throw new \DomainException("\"{$wave->title}\" has already switched on, so it can no longer be changed.");
        }

        return $plan;
    }

    private function customised(AgencyRolloutWave ...$waves): void
    {
        foreach ($waves as $w) {
            $w->forceFill(['customised_at' => now()])->save();
        }
    }

    private function nextPosition(AgencyFeatureRollout $plan, int $lane): int
    {
        return (int) AgencyRolloutWave::queryWithoutAgencyScope()->withTrashed()
            ->where('rollout_id', $plan->id)->where('lane', $lane)->max('position') + 10;
    }

    /** A new, empty group at the end of a lane (lane 2 only exists while sales and rentals run side by side). */
    public function addWave(AgencyFeatureRollout $plan, string $title, ?string $intro, int $lane = 1): AgencyRolloutWave
    {
        if (! $this->isOpen($plan)) {
            throw new \DomainException('This introduction has finished, so no groups can be added.');
        }
        $lane = ($lane === 2 && $plan->side_by_side) ? 2 : 1;

        $wave = AgencyRolloutWave::withoutAgencyStamping(fn () => AgencyRolloutWave::create([
            'agency_id'     => $plan->agency_id,
            'rollout_id'    => $plan->id,
            'lane'          => $lane,
            'track'         => $lane === 2 ? 'rentals' : 'general',
            'position'      => $this->nextPosition($plan, $lane),
            'title'         => $title,
            'intro_text'    => $intro,
            'customised_at' => now(),
        ]));
        $this->schedule($plan, $this->calendar->today());
        $this->record((int) $plan->agency_id, 'group_added', ['group' => $title]);

        return $wave;
    }

    public function updateWave(AgencyRolloutWave $wave, string $title, ?string $intro): void
    {
        $this->assertChangeable($wave);
        $was = $wave->title;
        $wave->forceFill(['title' => $title, 'intro_text' => $intro, 'customised_at' => now()])->save();
        $this->record((int) $wave->agency_id, 'group_edited', ['group' => $title, 'was' => $was]);
    }

    /** Swap a group with its neighbour in its lane (-1 earlier, +1 later), among the groups still to come. */
    public function moveWave(AgencyRolloutWave $wave, int $direction): void
    {
        $plan = $this->assertChangeable($wave);
        $lane = $this->wavesOf($plan)->whereNull('released_at')->where('lane', $wave->lane)->sortBy('position')->values();
        $i = $lane->search(fn ($w) => $w->id === $wave->id);
        $j = $i + ($direction < 0 ? -1 : 1);
        if ($i === false || ! isset($lane[$j])) {
            return; // already first / last
        }
        $other = $lane[$j];
        DB::transaction(function () use ($wave, $other, $plan) {
            [$a, $b] = [$wave->position, $other->position];
            $wave->forceFill(['position' => $b, 'customised_at' => now()])->save();
            $other->forceFill(['position' => $a])->save();
            $this->schedule($plan, $this->calendar->today());
        });
        $this->record((int) $plan->agency_id, 'group_moved', ['group' => $wave->title, 'direction' => $direction < 0 ? 'earlier' : 'later']);
    }

    /** Archive an EMPTY group still to come (its features must be moved, switched on or skipped first). */
    public function archiveWave(AgencyRolloutWave $wave): void
    {
        $plan = $this->assertChangeable($wave);
        if ($this->itemsOf($wave)->isNotEmpty()) {
            throw new \DomainException("Move, switch on or skip the features in \"{$wave->title}\" before archiving it.");
        }
        DB::transaction(function () use ($wave, $plan) {
            $wave->delete();
            $this->schedule($plan, $this->calendar->today());
            $this->completeIfDone((int) $plan->id);
        });
        $this->record((int) $plan->agency_id, 'group_archived', ['group' => $wave->title]);
    }

    public function restoreWave(AgencyRolloutWave $wave): void
    {
        $plan = AgencyFeatureRollout::queryWithoutAgencyScope()->findOrFail($wave->rollout_id);
        if (! $this->isOpen($plan)) {
            throw new \DomainException('This introduction has finished, so groups can no longer be restored.');
        }
        DB::transaction(function () use ($wave, $plan) {
            $wave->restore();
            $wave->forceFill(['position' => $this->nextPosition($plan, (int) $wave->lane), 'customised_at' => now()])->save();
            $this->schedule($plan, $this->calendar->today());
        });
        $this->record((int) $plan->agency_id, 'group_restored', ['group' => $wave->title]);
    }

    /**
     * Add a feature to a group still to come. Only a feature that is switched ON for the agency now and is
     * not already in its plan can be added — it is switched off now and comes back with this group. A
     * feature the agency has off is never added, so the plan can never switch on something they turned off.
     */
    public function addFeature(AgencyRolloutWave $wave, string $key, ?int $userId): void
    {
        $plan = $this->assertChangeable($wave);
        $agency = Agency::withoutGlobalScopes()->findOrFail($plan->agency_id);
        $label = $this->features->catalogue()[$key]['label'] ?? $key;

        if (! isset($this->waveableFeatures()[$key])) {
            throw new \DomainException("{$label} cannot be part of a gradual introduction.");
        }
        if (AgencyRolloutItem::queryWithoutAgencyScope()->withTrashed()->where('rollout_id', $plan->id)->where('feature_key', $key)->exists()) {
            throw new \DomainException("{$label} is already part of this agency's introduction — move it instead.");
        }
        if (! $this->features->enabled($key, $agency)) {
            throw new \DomainException("{$label} is switched off for this agency, so there is nothing to hold back.");
        }

        DB::transaction(function () use ($wave, $plan, $agency, $key, $userId) {
            AgencyRolloutItem::withoutAgencyStamping(fn () => AgencyRolloutItem::create([
                'agency_id' => $plan->agency_id, 'rollout_id' => $plan->id, 'wave_id' => $wave->id,
                'feature_key' => $key, 'state' => AgencyRolloutItem::STATE_HELD,
            ]));
            $this->apply($agency, [$key => false], $userId);
            $this->customised($wave);
        });
        $this->record((int) $plan->agency_id, 'feature_added', ['feature' => $key, 'group' => $wave->title]);
    }

    /** Move a held feature to another group still to come in the same plan. */
    public function moveFeature(AgencyRolloutItem $item, AgencyRolloutWave $to): void
    {
        $from = AgencyRolloutWave::queryWithoutAgencyScope()->findOrFail($item->wave_id);
        $this->assertChangeable($from);
        $this->assertChangeable($to);
        if ((int) $to->rollout_id !== (int) $item->rollout_id) {
            throw new \DomainException('That group belongs to a different agency.');
        }
        if ($item->state !== AgencyRolloutItem::STATE_HELD || $from->id === $to->id) {
            return;
        }
        DB::transaction(function () use ($item, $from, $to) {
            $item->forceFill(['wave_id' => $to->id])->save();
            $this->customised($from, $to);
        });
        $this->record((int) $item->agency_id, 'feature_moved', ['feature' => $item->feature_key, 'from' => $from->title, 'group' => $to->title]);
    }

    /** Switch one held feature on now, ahead of its group (it counts as released early). */
    public function switchOnNow(AgencyRolloutItem $item, ?int $userId): void
    {
        $wave = AgencyRolloutWave::queryWithoutAgencyScope()->findOrFail($item->wave_id);
        $this->assertChangeable($wave);
        if ($item->state !== AgencyRolloutItem::STATE_HELD) {
            return;
        }
        $agency = Agency::withoutGlobalScopes()->findOrFail($item->agency_id);
        DB::transaction(function () use ($item, $wave, $agency, $userId) {
            $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED_EARLY, 'released_at' => now()])->save();
            $this->apply($agency, [$item->feature_key => true], $userId);
            $this->customised($wave);
        });
        $this->record((int) $agency->id, 'feature_switched_on_early', ['feature' => $item->feature_key]);
    }

    /**
     * "Reset to default list": the groups still to come are rebuilt from TODAY's Dev Settings order, for the
     * features those groups hold now (released groups are history and stay). A feature the default no longer
     * places goes into a final "Other features" group, so a reset never switches anything on by itself.
     * The old groups are archived (soft), never deleted.
     */
    public function resetToDefault(AgencyFeatureRollout $plan): void
    {
        if (! $this->isOpen($plan)) {
            throw new \DomainException('This introduction has finished, so there is nothing to reset.');
        }
        $agency = Agency::withoutGlobalScopes()->findOrFail($plan->agency_id);
        $old = $this->wavesOf($plan)->whereNull('released_at');
        $items = $old->flatMap(fn ($w) => $this->itemsOf($w))->keyBy('feature_key');

        $rows = [];
        $taken = [];
        $type = AgencyTypeFeatureSeeder::normalise($agency->agency_type);
        foreach ($this->lanesFor($type, (bool) $plan->side_by_side) as $lane => $defaults) {
            foreach ($defaults as $default) {
                $keys = array_values(array_filter((array) $default->feature_keys, fn ($k) => $items->has($k) && ! isset($taken[$k])));
                foreach ($keys as $k) {
                    $taken[$k] = true;
                }
                if ($keys !== []) {
                    $rows[] = ['lane' => $lane, 'default' => $default, 'keys' => $keys];
                }
            }
        }
        if ($plan->side_by_side) {
            usort($rows, fn ($a, $b) => [$a['default']->position, $a['default']->id] <=> [$b['default']->position, $b['default']->id]);
        }
        $leftover = $items->keys()->reject(fn ($k) => isset($taken[$k]))->values()->all();

        DB::transaction(function () use ($plan, $old, $items, $rows, $leftover) {
            foreach ($old as $w) {
                $w->delete();
            }
            $create = function (int $lane, ?FeatureRolloutWave $default, string $title, ?string $intro, array $keys) use ($plan, $items) {
                $wave = AgencyRolloutWave::withoutAgencyStamping(fn () => AgencyRolloutWave::create([
                    'agency_id' => $plan->agency_id, 'rollout_id' => $plan->id, 'source_wave_id' => $default?->id,
                    'lane' => $lane, 'track' => $default?->track ?? 'general',
                    'position' => $this->nextPosition($plan, $lane), 'title' => $title, 'intro_text' => $intro,
                ]));
                foreach ($keys as $k) {
                    $items[$k]->forceFill(['wave_id' => $wave->id])->save();
                }
            };
            foreach ($rows as $r) {
                $create($r['lane'], $r['default'], $r['default']->title, $r['default']->intro_text, $r['keys']);
            }
            if ($leftover !== []) {
                $create(1, null, 'Other features', null, $leftover);
            }
            $this->schedule($plan, $this->calendar->today());
            $this->completeIfDone((int) $plan->id);
        });
        $this->record((int) $plan->agency_id, 'reset_to_default');
    }

    /**
     * How a group differs from the Dev Settings default it was copied from (today's version of it).
     * null = the group was added for this agency (no default).
     *
     * @return array{added: list<string>, missing: list<string>}|null
     */
    public function differences(AgencyRolloutWave $wave): ?array
    {
        $source = $wave->source_wave_id ? FeatureRolloutWave::withTrashed()->find($wave->source_wave_id) : null;
        if (! $source) {
            return null;
        }
        $default = array_values(array_intersect((array) $source->feature_keys, array_keys($this->waveableFeatures())));
        $mine = $this->itemsOf($wave)->where('state', '!=', AgencyRolloutItem::STATE_DROPPED)->pluck('feature_key')->all();

        return [
            'added'   => array_values(array_diff($mine, $default)),
            'missing' => array_values(array_diff($default, $mine)),
        ];
    }

    // ── Reacting to the Features page ──────────────────────────────────────────

    /** An admin switched a held feature on themselves: it counts as released early (spec §2.8). */
    public function onFeatureToggled(AgencyFeatureToggled $event): void
    {
        if (self::$applying || ! $event->enabled) {
            return;
        }

        $item = AgencyRolloutItem::queryWithoutAgencyScope()
            ->where('agency_id', $event->agencyId)
            ->where('feature_key', $event->featureKey)
            ->whereIn('state', [AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_AWAITING_ADMIN, AgencyRolloutItem::STATE_AWAITING_SETUP])
            ->first();

        if ($item) {
            $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED_EARLY, 'released_at' => now()])->save();
            $this->record((int) $event->agencyId, 'feature_switched_on_features_page', ['feature' => $item->feature_key]);
        }
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /** @param array<string,bool> $want */
    private function apply(Agency $agency, array $want, ?int $userId): void
    {
        if ($want === []) {
            return;
        }

        self::$applying = true;
        try {
            $this->writer->write($agency, $want, $userId, true);
        } finally {
            self::$applying = false;
        }
        FeatureRolloutUsage::forget((int) $agency->id); // what is tracked for usage just changed
    }

    private function completeIfDone(int $rolloutId): void
    {
        $open = AgencyRolloutWave::queryWithoutAgencyScope()
            ->where('rollout_id', $rolloutId)->whereNull('released_at')->exists();

        if (! $open) {
            AgencyFeatureRollout::queryWithoutAgencyScope()->whereKey($rolloutId)
                ->update(['status' => AgencyFeatureRollout::STATUS_COMPLETED, 'completed_at' => now()]);
        }
    }
}
