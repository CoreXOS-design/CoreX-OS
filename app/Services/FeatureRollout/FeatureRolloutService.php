<?php

namespace App\Services\FeatureRollout;

use App\Events\AgencyFeatureToggled;
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
    ) {
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

        return DB::transaction(function () use ($agency, $settings, $sideBySide, $type, $userId) {
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

            $current = $this->features->all($agency);
            $waveable = $this->waveableFeatures();
            $taken = [];
            $held = [];

            foreach ($this->lanesFor($type, $sideBySide) as $lane => $defaults) {
                $position = 0;
                foreach ($defaults as $default) {
                    $keys = [];
                    foreach ((array) $default->feature_keys as $key) {
                        if (isset($waveable[$key]) && ! isset($taken[$key]) && ($current[$key] ?? false)) {
                            $keys[] = $key;
                            $taken[$key] = true;
                        }
                    }
                    if ($keys === []) {
                        continue; // nothing left for this agency in this wave (type filtered it, or already on/off)
                    }

                    $wave = AgencyRolloutWave::withoutAgencyStamping(fn () => AgencyRolloutWave::create([
                        'agency_id'  => $agency->id,
                        'rollout_id' => $plan->id,
                        'lane'       => $lane,
                        'track'      => $default->track,
                        'position'   => $position += 10,
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
            }

            $this->apply($agency, $held, $userId);
            $this->schedule($plan, $this->calendar->today());

            if ($held === []) {
                $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_COMPLETED, 'completed_at' => now()])->save();
            }

            return $plan->fresh();
        });
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

        return $released;
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
        }
    }

    public function resume(AgencyFeatureRollout $plan): void
    {
        if ($plan->status === AgencyFeatureRollout::STATUS_PAUSED) {
            $plan->forceFill(['status' => AgencyFeatureRollout::STATUS_ACTIVE, 'paused_at' => null])->save();
            $this->schedule($plan, $this->calendar->today());
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
                ->whereIn('state', [AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_AWAITING_ADMIN])
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
        });
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
            ->whereIn('state', [AgencyRolloutItem::STATE_HELD, AgencyRolloutItem::STATE_AWAITING_ADMIN])
            ->first();

        if ($item) {
            $item->forceFill(['state' => AgencyRolloutItem::STATE_RELEASED_EARLY, 'released_at' => now()])->save();
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
