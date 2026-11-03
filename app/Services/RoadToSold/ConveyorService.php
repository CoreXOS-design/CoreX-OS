<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 - the conveyor (.ai/specs/road-to-sold.md 23.24): for every station of the belt, not only what was done today but the backlog.
 *
 *   to do        the agent's records sitting at the station now
 *   moved on     the records that LEFT the station in the day - read from the SAME history the funnel counts (BeltFunnelService "reached the next
 *                station"), so a tile and the funnel can never disagree
 *   late         to-do records whose next-step date is before the day. The date is the record's own next-action date where one is set, otherwise
 *                the station's window from the agency settings (SettingsService::due*Days). No window on the last stations: Won, Draft, Active
 *                and Advertised show how many REACHED the station this month and today instead.
 *
 * Scoping: every query is bound to the agent AND the agent's agency (the caller has already limited the agent to the viewer's breadth); nobody
 * else's record is ever read. Read only.
 */
final class ConveyorService
{
    /** The stations whose tile reads "to do / moved on today / late" (Loaded has its own second line: n of N worked). */
    public const BACKLOG = ['loaded', 'claimed', 'owner', 'contacted', 'booked', 'prepared', 'held'];

    /** The stations whose tile reads "this month / today" (reached the station). */
    public const ARRIVAL = ['won', 'draft', 'active', 'advertised'];

    /** The stations that carry a next-step window (a record there can be late). */
    public const WINDOWED = ['claimed', 'owner', 'contacted', 'booked', 'prepared', 'held'];

    /** @var array<string,string> */
    private array $workdayCache = [];

    public function __construct(
        private readonly BeltFunnelService $funnel,
        private readonly WorkdayService $workdays,
    ) {
    }

    /** The record's next evaluation / presentation appointment (with its time), when the calendar carries the link (belt A4). Needs the alias `rp`. */
    public static function appointmentSql(): string
    {
        static $has = null;
        $has ??= Schema::hasColumn('calendar_events', 'rts_prospect_id');

        return $has
            ? ", (SELECT MIN(ce.event_date) FROM calendar_events ce WHERE ce.rts_prospect_id = rp.id AND ce.deleted_at IS NULL AND ce.status NOT IN ('dismissed', 'cancelled') AND ce.category IN ('property_evaluation', 'listing_presentation')) as appt_at"
            : ', NULL as appt_at';
    }

    /** The station a stored stage key belongs to (null for a stage that is not on the belt). */
    public static function stationOf(?string $stage): ?string
    {
        return BeltFunnelService::stationOfStage($stage);
    }

    /**
     * The agent's live records on the belt, each with the station it sits at and when its next step falls due.
     *
     * @return Collection<int,array<string,mixed>> id, tracked_property_id, title, suburb, stage, station, entered, next_at, next_label, appt_at, since, due (?Carbon), late (bool)
     */
    public function records(User $agent, string $track, CarbonInterface $day): Collection
    {
        return $this->load($agent, $track, $day, null);
    }

    /**
     * The same rows for particular prospects whatever their state now (the "moved on today" / "reached this month" lists: a record that moved on is no longer at the station,
     * and may have been set aside or closed since). Only this agent's own records are ever returned.
     *
     * @param  int[]  $ids
     * @return Collection<int,array<string,mixed>>
     */
    public function recordsByIds(User $agent, string $track, CarbonInterface $day, array $ids): Collection
    {
        return $ids === [] ? collect() : $this->load($agent, $track, $day, array_map('intval', $ids));
    }

    /** @param int[]|null $ids null = the agent's live records */
    private function load(User $agent, string $track, CarbonInterface $day, ?array $ids): Collection
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $settings = SettingsService::forUser($agent);
        $q = DB::table('rts_prospects as rp')->join('tracked_properties as tp', 'tp.id', '=', 'rp.tracked_property_id')
            ->where('rp.agency_id', $agencyId)->where('rp.track', $track)->where('rp.user_id', (int) $agent->id)->whereNull('rp.deleted_at')
            ->selectRaw("rp.id, rp.tracked_property_id, rp.stage, rp.stage_entered_at, rp.next_action_at, rp.next_action_label, rp.reason, rp.closed_at, rp.parked_at, rp.released_at, "
                . "TRIM(COALESCE(NULLIF(tp.address_raw, ''), CONCAT_WS(' ', tp.street_number, tp.street_name))) as title, tp.suburb as suburb" . self::appointmentSql());
        if ($ids === null) {
            $q->whereNull('rp.closed_at')->whereNull('rp.released_at')->whereNull('rp.parked_at');
        } else {
            $q->whereIn('rp.id', $ids);
        }
        $today = Carbon::instance($day)->startOfDay();

        return collect($q->get())->map(function ($r) use ($settings, $agent, $today) {
            $station = self::stationOf($r->stage);
            $live = $r->closed_at === null && $r->released_at === null && $r->parked_at === null;
            $due = $live && $station !== null ? $this->dueOf((array) $r, $station, $settings, $agent) : null;

            return [
                'id' => (int) $r->id, 'tracked_property_id' => (int) $r->tracked_property_id, 'title' => (string) $r->title, 'suburb' => $r->suburb,
                'stage' => (string) $r->stage, 'station' => $station, 'live' => $live,
                'entered' => $r->stage_entered_at, 'since' => $r->stage_entered_at, 'next_at' => $r->next_action_at, 'next_label' => $r->next_action_label,
                'appt_at' => $r->appt_at, 'reason' => $r->reason,
                'due' => $due, 'late' => $due !== null && $due->lt($today),
            ];
        })->values();
    }

    /**
     * When the record's next step falls due (a date, no time). The record's own next-action date where one is set; otherwise the station's window from the agency's
     * settings (23.24): Claimed - find the owner within N working days of the claim; Owner found - first contact within N working days; Contacted - evaluation
     * booked within N days; Booked / Prepared - the appointment date; Held - the result within N working days. Null where the station has no window.
     *
     * @param array<string,mixed> $r rts_prospects columns (stage_entered_at, next_action_at, appt_at)
     */
    public function dueOf(array $r, string $station, SettingsService $settings, ?User $agent = null): ?Carbon
    {
        $next = ! empty($r['next_action_at']) ? Carbon::parse($r['next_action_at'])->startOfDay() : null;
        $entered = ! empty($r['stage_entered_at']) ? Carbon::parse($r['stage_entered_at'])->startOfDay() : null;
        $appt = ! empty($r['appt_at']) ? Carbon::parse($r['appt_at'])->startOfDay() : null;
        $country = $settings->holidayCountry();

        return match ($station) {
            'claimed' => $next ?? ($entered ? $this->plusWorkdays($entered, $settings->dueClaimedDays(), $country, $agent) : null),
            'owner' => $next ?? ($entered ? $this->plusWorkdays($entered, $settings->dueOwnerDays(), $country, $agent) : null),
            'contacted' => $next ?? $entered?->copy()->addDays($settings->dueContactedDays()),
            'booked', 'prepared' => $appt ?? $next,
            'held' => $next ?? ($entered ? $this->plusWorkdays($entered, $settings->dueHeldDays(), $country, $agent) : null),
            default => null,
        };
    }

    /** One working-day sum per (date, days, agent) in a request: a list of 300 records shares a handful of dates. */
    private function plusWorkdays(Carbon $from, int $days, string $country, ?User $agent): Carbon
    {
        $k = $from->toDateString() . '|' . $days . '|' . $country . '|' . ($agent?->id ?? 0);

        return Carbon::parse($this->workdayCache[$k] ??= $this->workdays->addWorkdays($from, $days, $country, $agent)->toDateString());
    }

    /**
     * The conveyor figures of one agent for a day: station => to_do, late, moved (left the station in the day), today / month (reached the station).
     *
     * @return array<string,array{todo:int,late:int,moved:int,today:int,month:int}>
     */
    public function figures(User $agent, string $track, CarbonInterface $day): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $uid = (int) $agent->id;
        $recs = $this->records($agent, $track, $day);
        $dayStart = CarbonImmutable::instance($day)->startOfDay();
        $reachedDay = $this->funnel->figures($agencyId, new Period($dayStart, $dayStart->endOfDay(), 'day', 'day'), $track, [$uid])[$uid]['reached'] ?? [];
        $reachedMonth = $this->funnel->figures($agencyId, new Period($dayStart->startOfMonth(), $dayStart->endOfDay(), 'month', 'month'), $track, [$uid])[$uid]['reached'] ?? [];

        $out = [];
        foreach (array_keys(BeltFunnelService::STATIONS) as $station) {
            $here = $recs->where('station', $station);
            $next = BeltFunnelService::nextStation($station);
            $out[$station] = [
                'todo' => $here->count(),
                'late' => in_array($station, self::WINDOWED, true) ? $here->where('late', true)->count() : 0,
                'moved' => $next !== null ? (int) ($reachedDay[$next] ?? 0) : 0,
                'today' => (int) ($reachedDay[$station] ?? 0),
                'month' => (int) ($reachedMonth[$station] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * "Your claim · 12 days left on hold": the WHY of a record the agent has claimed. The days come from the claim hold's own accessor (ClaimHoldService::daysLeft), so the
     * line follows the hold rules (23.5) wherever they are changed; a claim not on a clock reads just "Your claim".
     */
    public function claimLine(int $prospectId): string
    {
        $claim = \App\Models\ProspectingClaim::withoutGlobalScopes()->where('rts_prospect_id', $prospectId)->whereNull('deleted_at')
            ->where('is_active', true)->whereNull('released_at')->orderByDesc('id')->first();
        $left = $claim ? app(ClaimHoldService::class)->daysLeft($claim) : null;

        return 'Your claim' . match (true) {
            $left === null => '',
            $left < 0 => ' · hold overdue',
            $left === 0 => ' · last day of the hold',
            default => ' · ' . $left . ($left === 1 ? ' day' : ' days') . ' left on hold',
        };
    }

    /**
     * "Moved along" in the header line: how many DISTINCT records moved on today. A record counts once however many stations it passed (a record that goes Held -> Won
     * is one move, not the two the tiles' lines add up to). A record moves along when it reaches any station from Owner found onwards today - which is leaving
     * Claimed, Owner found, ... Held, or arriving at Won, Draft, Active or Advertised. The per-tile lines are unchanged.
     */
    public function movedAlong(User $agent, string $track, CarbonInterface $day): int
    {
        $dayStart = CarbonImmutable::instance($day)->startOfDay();
        $after = array_slice(array_keys(BeltFunnelService::STATIONS), 2);   // everything after Loaded and Claimed

        return count($this->funnel->reachedProspects((int) ($agent->effectiveAgencyId() ?: 0), new Period($dayStart, $dayStart->endOfDay(), 'day', 'day'), $track, [(int) $agent->id], $after));
    }

    /**
     * The ids behind a figure: `moved` = the properties that left the station in the day; `today` / `month` = the ones that reached it.
     *
     * @return int[]
     */
    public function idsFor(User $agent, string $track, CarbonInterface $day, string $station, string $kind): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $dayStart = CarbonImmutable::instance($day)->startOfDay();
        $target = $kind === 'moved' ? BeltFunnelService::nextStation($station) : $station;
        if ($target === null) {
            return [];
        }
        $period = $kind === 'month'
            ? new Period($dayStart->startOfMonth(), $dayStart->endOfDay(), 'month', 'month')
            : new Period($dayStart, $dayStart->endOfDay(), 'day', 'day');

        return array_keys($this->funnel->reachedProspects($agencyId, $period, $track, [(int) $agent->id], $target));
    }
}
