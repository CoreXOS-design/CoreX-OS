<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsWeekdayTarget;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 — an agent's weekday targets: their own numbers over the agency default.
 * Spec: .ai/specs/road-to-sold.md §3.3 / §5.3.
 *
 *  - No own row for a weekday = the agency default for that weekday.
 *  - An own row of 0 = this agent really owes nothing that day.
 *  - Every write goes through here so the "restore a trashed row, never a duplicate-key" rule
 *    (BUILD_STANDARD §5a) lives in exactly one place.
 */
class TargetService
{
    /** @var array<string,array<int,int>> per-request memo: "agency|track|user" => own weekday=>target */
    private array $ownMemo = [];

    /**
     * The monthly numbers an agent is working to: deals, sales value, listings and points
     * (agency-tracker-rehome.md section 4.3). Read only: reads the `targets` row for that month and
     * writes nothing (the old dashboard re-derived and saved missing targets as a side effect).
     * `set` is false when the month has no row, so a screen can say "no target set" instead of showing zeros.
     *
     * @return array{deals: int, value: float, listings: int, points: int, set: bool}
     */
    public function monthlyFor(User $u, string $period): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        $row = $agencyId > 0
            ? DB::table('targets')->where('agency_id', $agencyId)->where('user_id', $u->id)
                ->where('period', $period)->whereNull('deleted_at')->first()
            : null;

        return [
            'deals'    => (int) ($row->deals_target ?? 0),
            'value'    => (float) ($row->value_target ?? 0),
            'listings' => (int) ($row->listings_target ?? 0),
            'points'   => (int) ($row->points_target ?? 0),
            'set'      => $row !== null,
        ];
    }

    // ── the monthly targets (points / deals / value / listings) - one row per agent per month in `targets` ──────────

    /** The four numbers and the form field each one comes from. */
    public const MONTHLY_FIELDS = ['points_target', 'deals_target', 'value_target', 'listings_target'];

    /**
     * Save an agent's monthly targets. ONLY the numbers in `$values` are written: a number the caller did not send
     * is left exactly as it is, and a blank is the same as not sent (type 0 to set zero). This is the fix for the
     * Targets-screen bug where saving "points" zeroed deals, value and listings for every agent on the page
     * (the old handler wrote all four from a form that posted one, and overwrote created_by every time).
     *
     * An archived row for the same agent and month is brought back, never duplicated (the unique index ignores
     * soft deletes). created_by is set once; only updated_by moves after that.
     *
     * @param  array<string, int|float|string|null> $values  any of MONTHLY_FIELDS
     */
    public function saveMonthly(User $actor, User $agent, string $period, array $values): Target
    {
        $agencyId = (int) ($agent->agency_id ?: 0);
        abort_unless($agencyId > 0 && $agencyId === (int) ($actor->effectiveAgencyId() ?: 0), 404);

        return DB::transaction(function () use ($actor, $agent, $period, $values, $agencyId) {
            // An agent belongs to one agency, so (period, agent) can only ever be this agency's row.
            $row = Target::withTrashed()->where('period', $period)->where('user_id', $agent->id)->lockForUpdate()->first();
            if (! $row) {
                $row = new Target();
                $row->forceFill(['agency_id' => $agencyId, 'period' => $period, 'user_id' => $agent->id, 'created_by' => $actor->id]);
            } elseif ($row->trashed()) {
                $row->restore();
            }
            $row->branch_id = $agent->branch_id;

            foreach (self::MONTHLY_FIELDS as $field) {
                if (! array_key_exists($field, $values) || $values[$field] === null || (is_string($values[$field]) && trim($values[$field]) === '')) {
                    continue;
                }
                $v = $values[$field];
                $row->{$field} = $field === 'value_target'
                    ? round(max(0.0, min((float) $v, (float) RtsSetting::MAX_MONTHLY_VALUE)), 2)
                    : max(0, min((int) $v, RtsSetting::MAX_MONTHLY_COUNT));
            }
            $row->updated_by = $actor->id;
            $row->save();

            return $row;
        });
    }

    /** Archive an agent's targets for a month (soft). False when there was nothing to archive. */
    public function archiveMonthly(User $actor, User $agent, string $period): bool
    {
        $row = Target::query()->where('agency_id', (int) ($actor->effectiveAgencyId() ?: 0))
            ->where('period', $period)->where('user_id', $agent->id)->first();
        if (! $row) {
            return false;
        }
        $row->updated_by = $actor->id;
        $row->save();

        return (bool) $row->delete();
    }

    public function restoreMonthly(User $actor, User $agent, string $period): bool
    {
        $row = Target::onlyTrashed()->where('agency_id', (int) ($actor->effectiveAgencyId() ?: 0))
            ->where('period', $period)->where('user_id', $agent->id)->first();
        if (! $row) {
            return false;
        }
        $row->updated_by = $actor->id;

        return (bool) $row->restore();
    }

    /**
     * Give the agency default to every one of these agents who has no live target row for the month. An agent who
     * already has a row - even one of zeros - is left alone (a manager's number is never overwritten). Does nothing
     * when the agency has not set a default (all zero). Returns how many agents were given the default.
     *
     * @param iterable<User> $agents
     * @param array{points:int,deals:int,listings:int,value:float} $default
     */
    public function applyMonthlyDefault(User $actor, iterable $agents, string $period, array $default): int
    {
        if (! array_filter($default)) {
            return 0;
        }
        $n = 0;
        foreach ($agents as $agent) {
            $has = Target::query()->where('period', $period)->where('user_id', $agent->id)->exists();
            if ($has) {
                continue;
            }
            $this->saveMonthly($actor, $agent, $period, [
                'points_target' => $default['points'], 'deals_target' => $default['deals'],
                'listings_target' => $default['listings'], 'value_target' => $default['value'],
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * The week that applies to an agent: weekday (1..7) => target, own numbers over the default.
     *
     * @return array<int,int>
     */
    public function weekFor(User $agent, string $track = RtsSetting::TRACK_SALES): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $default  = SettingsService::forAgency($agencyId, $track)->defaultWeekdayTargets();
        $own      = $agencyId > 0 ? $this->ownFor($agencyId, $track, (int) $agent->id) : [];

        return self::merge($own, $default);
    }

    /**
     * @param  array<int,int> $own     weekday => target (only the days the agent has set)
     * @param  array<int,int> $default weekday => target
     * @return array<int,int>
     */
    public static function merge(array $own, array $default): array
    {
        $week = [];
        foreach (array_keys(RtsWeekdayTarget::WEEKDAYS) as $day) {
            $week[$day] = array_key_exists($day, $own) ? (int) $own[$day] : (int) ($default[$day] ?? 0);
        }

        return $week;
    }

    /** @return array<int,int> the agent's OWN active numbers (weekday => target). */
    public function ownFor(int $agencyId, string $track, int $userId): array
    {
        $key = "{$agencyId}|{$track}|{$userId}";

        return $this->ownMemo[$key] ??= RtsWeekdayTarget::query()
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $userId)
            ->pluck('target', 'weekday')->map(fn ($t) => (int) $t)->all();
    }

    /**
     * Own active numbers for many agents at once.
     *
     * @param  int[] $userIds
     * @return array<int,array<int,int>> userId => [weekday => target]
     */
    public function ownForMany(int $agencyId, string $track, array $userIds): array
    {
        $out = [];
        RtsWeekdayTarget::query()
            ->where('agency_id', $agencyId)->where('track', $track)->whereIn('user_id', $userIds)
            ->get(['user_id', 'weekday', 'target'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->user_id][(int) $r->weekday] = (int) $r->target;
            });

        return $out;
    }

    /** @param int[] $userIds @return int[] ids of agents who have cleared (archived) numbers and none active */
    public function clearedAgentIds(int $agencyId, string $track, array $userIds): array
    {
        $hasActive = RtsWeekdayTarget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('user_id', $userIds)->distinct()->pluck('user_id')->all();

        return RtsWeekdayTarget::onlyTrashed()->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('user_id', $userIds)->whereNotIn('user_id', $hasActive)
            ->distinct()->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Save a whole week for one agent. A null/blank day = "no own number" (archives any own row);
     * an int writes (or restores) the row. Returns how many weekdays changed.
     *
     * @param array<int,int|null> $week weekday (1..7) => target|null; days not present are left alone
     */
    public function saveWeek(User $agent, array $week, int $setByUserId, string $track = RtsSetting::TRACK_SALES): int
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $changed  = 0;

        DB::transaction(function () use ($agent, $week, $setByUserId, $track, $agencyId, &$changed) {
            foreach (RtsWeekdayTarget::WEEKDAYS as $day => $_label) {
                if (! array_key_exists($day, $week)) {
                    continue;
                }
                $value = $week[$day];
                $row   = RtsWeekdayTarget::withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $track)
                    ->where('user_id', $agent->id)->where('weekday', $day)->first();

                if ($value === null) {
                    if ($row && ! $row->trashed()) {
                        $row->delete();
                        $changed++;
                    }
                    continue;
                }

                $value = (int) $value;
                if ($row && ! $row->trashed()) {
                    if ((int) $row->target !== $value) {
                        $row->forceFill(['target' => $value, 'set_by_user_id' => $setByUserId, 'branch_id' => $agent->branch_id])->save();
                        $changed++;
                    }
                    continue;
                }
                if ($row) {
                    $row->restore();
                    $row->forceFill(['target' => $value, 'set_by_user_id' => $setByUserId, 'branch_id' => $agent->branch_id])->save();
                } else {
                    RtsWeekdayTarget::create([
                        'agency_id' => $agencyId, 'branch_id' => $agent->branch_id, 'track' => $track,
                        'user_id' => $agent->id, 'weekday' => $day, 'target' => $value, 'set_by_user_id' => $setByUserId,
                    ]);
                }
                $changed++;
            }
        });

        unset($this->ownMemo["{$agencyId}|{$track}|{$agent->id}"]);

        return $changed;
    }

    /** Archive all of an agent's own numbers (back to the agency default). Returns rows archived. */
    public function clear(User $agent, string $track = RtsSetting::TRACK_SALES): int
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $n = RtsWeekdayTarget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('user_id', $agent->id)->get()->each->delete()->count();
        unset($this->ownMemo["{$agencyId}|{$track}|{$agent->id}"]);

        return $n;
    }

    /** Bring back numbers cleared earlier (only weekdays with no active row). Returns rows restored. */
    public function restore(User $agent, string $track = RtsSetting::TRACK_SALES): int
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $active   = array_keys($this->ownFor($agencyId, $track, (int) $agent->id));

        $n = RtsWeekdayTarget::onlyTrashed()->where('agency_id', $agencyId)->where('track', $track)
            ->where('user_id', $agent->id)->whereNotIn('weekday', $active)->get()->each->restore()->count();
        unset($this->ownMemo["{$agencyId}|{$track}|{$agent->id}"]);

        return $n;
    }

    /**
     * Normalise a posted week: only weekdays 1..7, blank -> null, ints clamped by validation upstream.
     *
     * @param  mixed $raw
     * @return array<int,int|null>
     */
    public static function normalisePosted(mixed $raw): array
    {
        $out = [];
        foreach (array_keys(RtsWeekdayTarget::WEEKDAYS) as $day) {
            if (! is_array($raw) || ! array_key_exists($day, $raw)) {
                continue;
            }
            $v = $raw[$day];
            $out[$day] = ($v === null || trim((string) $v) === '') ? null : (int) trim((string) $v);
        }

        return $out;
    }
}
