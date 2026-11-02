<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Events\RoadToSold\RoadToSoldPaused;
use App\Events\RoadToSold\RoadToSoldResumed;
use App\Events\RoadToSold\RoadToSoldStarted;
use App\Models\RoadToSold\RtsAgentStart;
use App\Models\RoadToSold\RtsAgentStartEvent;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Support\Events\SafeRaise;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 - activation and pause (.ai/specs/road-to-sold.md 23.13).
 *
 * The agency switches Road to Sold on and NOTHING loads. Each agent presses "Start my Road to Sold"; their first list loads from the next working
 * day. An agent or a manager can pause an agent until a date: paused days are not working days (targets, month figures, the claim hold) and no list
 * is built for them.
 *
 * Safe before the migration: with no `rts_agent_starts` table (or the setting off) every agent counts as started and nobody is paused, so the code
 * behaves exactly as it did. Agents who already work Road to Sold (any list item, prospect or outcome) count as started without a row, so turning
 * the setting on never strands them. Authorisation (who may act on whom) is the controller's job; this class only records and reads.
 */
final class ActivationService
{
    public const STATE_ACTIVE = 'active';
    public const STATE_NOT_STARTED = 'not_started';
    public const STATE_PAUSED = 'paused';

    /** @var array<string,?RtsAgentStart> per-instance memo (one instance per batch) */
    private array $rows = [];

    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('rts_agent_starts');
    }

    /** The history table is its own migration; until it exists the presses are still honoured, just not written down. */
    public static function historyReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('rts_agent_start_events');
    }

    public function required(User $agent, string $track = RtsSetting::TRACK_SALES): bool
    {
        return (bool) config('road-to-sold-belt.enforce_start', true)
            && RtsSetting::hasAgentStartColumn() && SettingsService::forUser($agent, $track)->agentStartRequired();
    }

    public function row(User $agent, string $track = RtsSetting::TRACK_SALES): ?RtsAgentStart
    {
        if (! self::tableReady()) {
            return null;
        }
        $key = $agent->id . '|' . $track;
        if (! array_key_exists($key, $this->rows)) {
            $this->rows[$key] = RtsAgentStart::query()->withoutGlobalScopes()->where('agency_id', (int) ($agent->effectiveAgencyId() ?: 0))
                ->where('user_id', $agent->id)->where('track', $track)->whereNull('deleted_at')->first();
        }

        return $this->rows[$key];
    }

    public function isStarted(User $agent, string $track = RtsSetting::TRACK_SALES): bool
    {
        if (! self::tableReady() || ! $this->required($agent, $track)) {
            return true;
        }
        if ($this->row($agent, $track)?->started_at !== null) {
            return true;
        }

        // already working Road to Sold: counts as started (no row is written for them)
        return DB::table('rts_list_items')->where('user_id', $agent->id)->where('track', $track)->exists()
            || DB::table('rts_prospects')->where('user_id', $agent->id)->where('track', $track)->exists()
            || DB::table('rts_contact_outcomes')->where('user_id', $agent->id)->where('track', $track)->exists();
    }

    public function pausedOn(User $agent, CarbonInterface|string $day, string $track = RtsSetting::TRACK_SALES): bool
    {
        $r = $this->row($agent, $track);
        if ($r === null || $r->paused_until === null) {
            return false;
        }
        $d = Carbon::parse($day)->startOfDay();
        $from = $r->paused_from ? $r->paused_from->copy()->startOfDay() : $d;

        return $d->gte($from) && $d->lte($r->paused_until->copy()->startOfDay());
    }

    /** May a list be built for this agent on this day (started, not paused, not before the first list date)? */
    public function mayBuild(User $agent, CarbonInterface|string $day, string $track = RtsSetting::TRACK_SALES): bool
    {
        if (! $this->isStarted($agent, $track) || $this->pausedOn($agent, $day, $track)) {
            return false;
        }
        $first = $this->row($agent, $track)?->first_list_date;

        return $first === null || Carbon::parse($day)->startOfDay()->gte($first->copy()->startOfDay());
    }

    /**
     * @return array{state:string,started_at:?Carbon,first_list_date:?Carbon,paused_from:?Carbon,paused_until:?Carbon,pause_reason:?string,paused_by:?int}
     */
    public function state(User $agent, string $track = RtsSetting::TRACK_SALES, ?CarbonInterface $today = null): array
    {
        $today = Carbon::instance($today ?? now())->startOfDay();
        $r = $this->row($agent, $track);
        $state = ! $this->isStarted($agent, $track) ? self::STATE_NOT_STARTED
            : ($this->pausedOn($agent, $today, $track) ? self::STATE_PAUSED : self::STATE_ACTIVE);

        return [
            'state' => $state,
            'started_at' => $r?->started_at,
            'first_list_date' => $r?->first_list_date,
            'paused_from' => $r?->paused_from,
            'paused_until' => $r?->paused_until,
            'pause_reason' => $r?->pause_reason,
            'paused_by' => $r?->paused_by_user_id ? (int) $r->paused_by_user_id : null,
        ];
    }

    /** "Start my Road to Sold": the first list loads from the next working day. Idempotent. */
    public function start(User $by, User $agent, string $track = RtsSetting::TRACK_SALES): RtsAgentStart
    {
        $row = $this->rowForWrite($agent, $track);
        if ($row->started_at === null) {
            $row->started_at = now();
            $row->started_by_user_id = $by->id;
            $row->first_list_date = WorkdayService::make()->nextWorkday($agent, Carbon::today(), $track)?->toDateString() ?: Carbon::tomorrow()->toDateString();
            $row->save();
            $first = $row->first_list_date?->toDateString();
            $this->note('started', $by, $agent, $track, ['first_list_date' => $first]);
            SafeRaise::dispatch('RoadToSoldStarted', fn () => new RoadToSoldStarted((int) $agent->effectiveAgencyId(), $track, (int) $agent->id, $first, (int) $by->id));
        }
        $this->forget($agent, $track);

        return $row;
    }

    /** "Pause until <date>": from today to the date (the day after is the first working day again). */
    public function pause(User $by, User $agent, CarbonInterface $until, ?string $reason, string $track = RtsSetting::TRACK_SALES): RtsAgentStart
    {
        $row = $this->rowForWrite($agent, $track);
        $row->paused_from = Carbon::today();
        $row->paused_until = Carbon::instance($until)->startOfDay();
        $row->pause_reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 120) : null;
        $row->paused_by_user_id = $by->id;
        $row->save();
        $this->forget($agent, $track);
        $from = $row->paused_from->toDateString();
        $to = $row->paused_until->toDateString();
        $why = $row->pause_reason;
        $this->note('paused', $by, $agent, $track, ['paused_from' => $from, 'paused_until' => $to, 'reason' => $why]);
        SafeRaise::dispatch('RoadToSoldPaused', fn () => new RoadToSoldPaused((int) $agent->effectiveAgencyId(), $track, (int) $agent->id, $from, $to, $why, (int) $by->id));

        return $row;
    }

    public function resume(User $by, User $agent, string $track = RtsSetting::TRACK_SALES): RtsAgentStart
    {
        $row = $this->rowForWrite($agent, $track);
        $was = $row->paused_until?->toDateString();
        $row->forceFill(['paused_from' => null, 'paused_until' => null, 'pause_reason' => null, 'paused_by_user_id' => null])->save();
        $this->forget($agent, $track);
        if ($was !== null) {   // resuming something that was not paused is not an event
            $this->note('resumed', $by, $agent, $track, ['paused_until' => $was]);
            SafeRaise::dispatch('RoadToSoldResumed', fn () => new RoadToSoldResumed((int) $agent->effectiveAgencyId(), $track, (int) $agent->id, $was, (int) $by->id));
        }

        return $row;
    }

    /**
     * The manager's "Not started / Paused" panel: one row per agent in $agents who has not started or is paused.
     *
     * @param  Collection<int,User>  $agents  already limited to the viewer's breadth
     * @return list<array{id:int,name:string,branch:?int,state:string,since:?string,until:?string,reason:?string,by:?string}>
     */
    public function roster(Collection $agents, string $track = RtsSetting::TRACK_SALES, ?CarbonInterface $today = null): array
    {
        $out = [];
        // the viewable-agents list carries only id / name / branch: load what the agency lookup needs
        $people = User::query()->withoutGlobalScopes()->whereIn('id', $agents->pluck('id'))->get(['id', 'name', 'agency_id', 'branch_id']);
        $names = $people->pluck('name', 'id');
        foreach ($people as $a) {
            $s = $this->state($a, $track, $today);
            if ($s['state'] === self::STATE_ACTIVE) {
                continue;
            }
            $out[] = [
                'id' => (int) $a->id, 'name' => (string) ($names[$a->id] ?? $a->name), 'branch' => $a->branch_id ? (int) $a->branch_id : null, 'state' => $s['state'],
                'since' => $s['state'] === self::STATE_PAUSED ? $s['paused_from']?->format('j M') : null,
                'until' => $s['paused_until']?->format('j M'),
                'reason' => $s['pause_reason'],
                'by' => $s['paused_by'] ? (string) ($names[$s['paused_by']] ?? User::query()->withoutGlobalScopes()->whereKey($s['paused_by'])->value('name')) : null,
            ];
        }
        usort($out, fn ($x, $y) => [$x['state'] === self::STATE_PAUSED ? 1 : 0, $x['name']] <=> [$y['state'] === self::STATE_PAUSED ? 1 : 0, $y['name']]);

        return $out;
    }

    /**
     * The last presses for an agent, newest first (the "History" popover): who pressed what, and when.
     *
     * @return list<array{kind:string,when:string,by:?string,detail:string}>
     */
    public function history(User $agent, string $track = RtsSetting::TRACK_SALES, int $limit = 12): array
    {
        if (! self::historyReady()) {
            return [];
        }
        $rows = RtsAgentStartEvent::query()->withoutGlobalScopes()->where('agency_id', (int) ($agent->effectiveAgencyId() ?: 0))
            ->where('user_id', $agent->id)->where('track', $track)->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
        $names = User::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('actor_user_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'kind' => (string) $r->kind,
            'when' => $r->occurred_at->format('j M Y H:i'),
            'by' => $r->actor_user_id ? (string) ($names[$r->actor_user_id] ?? null) : null,
            'detail' => match ($r->kind) {
                'started' => 'Started' . ($r->first_list_date ? ' - first list ' . $r->first_list_date->format('D j M') : ''),
                'paused' => 'Paused until ' . $r->paused_until?->format('j M Y') . ($r->reason ? ' (' . $r->reason . ')' : ''),
                'resumed' => 'Resumed' . ($r->paused_until ? ' (the pause had run to ' . $r->paused_until->format('j M') . ')' : ''),
                default => ucfirst((string) $r->kind),
            },
        ])->all();
    }

    /** @param array<string,mixed> $fields */
    private function note(string $kind, User $by, User $agent, string $track, array $fields): void
    {
        if (! self::historyReady()) {
            return;
        }
        try {
            RtsAgentStartEvent::query()->withoutGlobalScopes()->create([
                'agency_id' => (int) ($agent->effectiveAgencyId() ?: 0), 'branch_id' => $agent->branch_id, 'track' => $track, 'user_id' => $agent->id,
                'actor_user_id' => $by->id, 'kind' => $kind, 'occurred_at' => now(),
            ] + $fields);
        } catch (\Throwable $e) {
            report($e);   // the press itself stands; only the history line is lost
        }
    }

    private function rowForWrite(User $agent, string $track): RtsAgentStart
    {
        return RtsAgentStart::query()->withoutGlobalScopes()->withTrashed()->firstOrNew(
            ['agency_id' => (int) ($agent->effectiveAgencyId() ?: 0), 'track' => $track, 'user_id' => $agent->id]
        )->forceFill(['deleted_at' => null, 'branch_id' => $agent->branch_id]);
    }

    private function forget(User $agent, string $track): void
    {
        unset($this->rows[$agent->id . '|' . $track]);
    }
}
