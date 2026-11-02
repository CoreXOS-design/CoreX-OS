<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\ActivityDefinitionCalendarClass;
use App\Models\DailyActivityEntry;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\Activity\InstantPointService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v4 - the belt points (.ai/specs/road-to-sold.md 23.16).
 *
 * The credits the belt adds (deed linked, owner linked, the outcome by channel, evaluation feedback, a logged off-ramp, advertised) are
 * ordinary auto-instant activity credits, so every screen that reads points reads them through the one rule (ActivityPointsService:
 * count x the agency's weight). What this service adds on top of InstantPointService:
 *
 *   - the amount is the agency's own: each agency has its own definition per credit whose WEIGHT is the points, seeded with the spec default
 *     and edited on Road to Sold > Settings > Points (setAmount). 0 switches the credit off for that agency.
 *   - one credit per event per subject, whatever the day: a re-save, a re-fire or a listener run twice never pays twice (InstantPointService
 *     keys on the day as well, which is right for a daily action and wrong for "this prospect's deed was linked").
 *   - a credit that was reversed is paid again when the agent genuinely does the thing again (a new outcome is a new subject anyway).
 *   - the test environment is NOT skipped, so the rules can be proved; an agency with no row for a credit simply earns nothing.
 *
 * credit() / revoke() never throw: the agent's save always completes. Auto and manual credits stay separate (the 21 Oct ruling): these slugs
 * are only ever written by an event, never by a manual capture.
 */
class BeltPoints
{
    /** slug => [label, default points, group, what raises it (shown on the settings tab), live = an event already fires it on QA1] */
    public const CREDITS = [
        'rts.deed_linked'         => ['Deed linked', 20, 'Finding the owner', 'The deed record arrives on a property you are working', true],
        'rts.owner_linked'        => ['Owner linked', 30, 'Finding the owner', 'The owner contact is linked to a property you are working', true],
        'rts.outcome.phone'       => ['Outcome captured - phone', 20, 'Contacting the owner', 'You record the result of a phone call', true],
        'rts.outcome.whatsapp'    => ['Outcome captured - WhatsApp', 10, 'Contacting the owner', 'You record the result of a WhatsApp message', true],
        'rts.outcome.email'       => ['Outcome captured - email', 10, 'Contacting the owner', 'You record the result of an email', true],
        'rts.outcome.in_person'   => ['Outcome captured - in person', 40, 'Contacting the owner', 'You record the result of a visit', true],
        'rts.offramp_logged'      => ['Dead end logged with a reason', 20, 'Contacting the owner', 'You log not now, not selling or lost, with the reason (work done, so no penalty)', true],
        'rts.evaluation_feedback' => ['Evaluation feedback', 50, 'Evaluating', 'Feedback is saved on an evaluation that was held', false],
        'rts.advertised'          => ['Advertised', 50, 'Listing', 'The property goes live on a portal (once per property)', false],
    ];

    public const SORT_BASE = 20000;

    // ── Catalogue ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The spec default for a credit (0 for an unknown slug). */
    public static function defaultPoints(string $slug): int
    {
        return (int) (self::CREDITS[$slug][1] ?? 0);
    }

    public static function label(string $slug): string
    {
        return (string) (self::CREDITS[$slug][0] ?? $slug);
    }

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::CREDITS);
    }

    public static function channelSlug(?string $channel): ?string
    {
        $slug = 'rts.outcome.' . strtolower(trim((string) $channel));

        return isset(self::CREDITS[$slug]) ? $slug : null;
    }

    // ── Per-agency provisioning and amounts ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Give an agency its own definition + mapping for every belt credit it does not have yet, at the spec default. A mapping the agency
     * removed (soft deleted) is never brought back. Idempotent; returns how many credits it created.
     */
    public function ensureAgency(int $agencyId): int
    {
        if ($agencyId <= 0) {
            return 0;
        }
        $made = 0;
        $order = 0;
        foreach (self::CREDITS as $slug => [$label, $default]) {
            $order += 10;
            $has = ActivityDefinitionCalendarClass::query()->withoutGlobalScopes()->withTrashed()
                ->where('agency_id', $agencyId)->where('trigger_kind', 'instant')->where('slug', $slug)->exists();
            if ($has) {
                continue;
            }
            DB::transaction(function () use ($agencyId, $slug, $default, $order, &$made) {
                $defId = (int) DB::table('activity_definitions')->insertGetId([
                    'name' => '[Auto] ' . $slug, 'scope' => 'agency', 'agency_id' => $agencyId, 'branch_id' => null, 'weight' => $default,
                    'sort_order' => self::SORT_BASE + $order, 'scoring_mode' => 'count', 'is_enabled' => false,   // hidden from the capture pickers: an auto credit
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('activity_definition_calendar_classes')->insert([
                    'agency_id' => $agencyId, 'event_class' => null, 'trigger_kind' => 'instant', 'slug' => $slug, 'subject_type' => null,
                    'activity_definition_id' => $defId, 'value_per_event' => 1, 'requires_feedback' => false, 'auto_revoke_after_hours' => null,
                    'daily_cap' => null, 'back_date_limit_hours' => 0, 'is_active' => $default > 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $made++;
            });
        }

        return $made;
    }

    /** The agency's own mapping for a credit (never the shared system one), or null when it has none. */
    private function mapping(int $agencyId, string $slug): ?ActivityDefinitionCalendarClass
    {
        return ActivityDefinitionCalendarClass::query()->withoutGlobalScopes()->with('activityDefinition')
            ->where('agency_id', $agencyId)->where('trigger_kind', 'instant')->where('slug', $slug)->first();
    }

    /** Points the agency pays for a credit today: 0 when it is switched off or has no row. */
    public function amount(int $agencyId, string $slug): int
    {
        $m = $this->mapping($agencyId, $slug);
        if (! $m || ! $m->is_active || ! $m->activityDefinition) {
            return 0;
        }

        return max(0, (int) round((float) $m->activityDefinition->weight * max(0, (int) $m->value_per_event)));
    }

    /** @return array<string,int> slug => points, for the settings tab (switched-off credits read 0) */
    public function amounts(int $agencyId): array
    {
        $this->ensureAgency($agencyId);
        $out = [];
        foreach (self::slugs() as $slug) {
            $out[$slug] = $this->amount($agencyId, $slug);
        }

        return $out;
    }

    /**
     * Set what an agency pays for a credit. 0 switches it off (nothing new is credited; what was already earned stays earned);
     * anything above 0 switches it on at that amount. Changing the amount changes FUTURE credits only: an earned row keeps its
     * count, and the completions the Road to Sold bridge wrote keep their own snapshot.
     */
    public function setAmount(int $agencyId, string $slug, int $points): bool
    {
        if (! isset(self::CREDITS[$slug]) || $points < 0 || $points > 10000) {
            return false;
        }
        $this->ensureAgency($agencyId);
        $m = $this->mapping($agencyId, $slug);
        if (! $m || ! $m->activityDefinition) {
            return false;   // the agency removed this credit on purpose
        }
        DB::transaction(function () use ($m, $points) {
            if ($points > 0) {
                $m->activityDefinition->forceFill(['weight' => $points])->save();
            }
            $m->forceFill(['is_active' => $points > 0, 'value_per_event' => 1])->save();
        });

        return true;
    }

    // ── Crediting ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Credit one event to an agent, once per subject. Returns true when a NEW credit was written (or a reversed one was paid again).
     * $context['date'] = the day the thing happened (default today); $context['demo'] = true writes nothing.
     *
     * @param array<string,mixed> $context
     */
    public function credit(string $slug, ?User $agent, ?Model $subject, array $context = []): bool
    {
        try {
            if ($agent === null || $subject === null || ! isset(self::CREDITS[$slug]) || ($context['demo'] ?? false) === true) {
                return false;
            }
            $agencyId = (int) ($agent->agency_id ?: $agent->effectiveAgencyId() ?: 0);
            if ($agencyId <= 0) {
                return false;
            }
            $this->ensureAgency($agencyId);
            $m = $this->mapping($agencyId, $slug);
            if (! $m || ! $m->is_active || ! $m->activityDefinition || (float) $m->activityDefinition->weight <= 0) {
                return false;   // switched off (or removed) for this agency
            }
            $date = ($context['date'] ?? null) instanceof CarbonInterface ? $context['date']->toDateString() : (string) ($context['date'] ?? now()->toDateString());

            $existing = DailyActivityEntry::query()->withoutGlobalScopes()
                ->where('user_id', $agent->id)->where('activity_definition_id', $m->activity_definition_id)
                ->where('subject_type', $subject::class)->where('subject_id', (int) $subject->getKey())->first();
            if ($existing) {
                if ($existing->point_state !== DailyActivityEntry::STATE_REVOKED) {
                    return false;   // already paid: a re-save pays nothing more
                }
                $existing->forceFill(['point_state' => DailyActivityEntry::STATE_CONFIRMED, 'revoked_at' => null, 'revoke_reason' => null,
                    'confirmed_at' => now(), 'activity_date' => $date, 'period' => substr($date, 0, 7), 'value' => max(1, (int) $m->value_per_event)])->save();

                return true;
            }
            DailyActivityEntry::query()->withoutGlobalScopes()->create([
                'user_id' => $agent->id, 'activity_date' => $date, 'period' => substr($date, 0, 7), 'activity_definition_id' => $m->activity_definition_id,
                'subject_type' => $subject::class, 'subject_id' => (int) $subject->getKey(), 'agency_id' => $agencyId, 'branch_id' => $agent->branch_id,
                'value' => max(1, (int) $m->value_per_event), 'point_state' => DailyActivityEntry::STATE_CONFIRMED, 'source' => DailyActivityEntry::SOURCE_AUTO_INSTANT,
                'confirmed_at' => now(), 'created_by' => $agent->id, 'updated_by' => $agent->id,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Belt points: a credit could not be written (the save stands)', ['slug' => $slug, 'agent_id' => $agent?->id, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** Take back what a subject earned for one credit (an outcome deleted or undone). Idempotent; never throws. */
    public function revoke(string $slug, Model $subject, string $reason): void
    {
        app(InstantPointService::class)->revoke($slug, $subject, $reason);
    }

    // ── Reading ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The one method the sticky summary reads: this agent's points for a day and for that day's month, by the same rule as every other
     * points screen (ActivityPointsService: confirmed or overridden, whichever store the agency is read from).
     *
     * @return array{date:string,period:string,day:float,month:float,belt_day:float,belt_month:float}
     */
    public function totals(User $agent, CarbonInterface|string|null $date = null): array
    {
        $day = ($date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date ?? now()))->startOfDay();
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $userId = (int) $agent->ownershipUserId();
        $base = fn () => ActivityPointsService::counted($agencyId)->where('e.user_id', $userId);
        $monthStart = $day->copy()->startOfMonth()->toDateString();
        $monthEnd = $day->copy()->endOfMonth()->toDateString();

        $dayTotal = ActivityPointsService::sum($base()->whereDate('e.activity_date', $day->toDateString()));
        $monthTotal = ActivityPointsService::sum($base()->whereBetween('e.activity_date', [$monthStart, $monthEnd]));
        [$beltDay, $beltMonth] = $this->beltTotals($agencyId, $userId, $day->toDateString(), $monthStart, $monthEnd);

        return ['date' => $day->toDateString(), 'period' => $day->format('Y-m'), 'day' => $dayTotal, 'month' => $monthTotal, 'belt_day' => $beltDay, 'belt_month' => $beltMonth];
    }

    /** @return array{0:float,1:float} points from the belt credits alone (the entries are written for every agency, cut over or not) */
    private function beltTotals(int $agencyId, int $userId, string $day, string $monthStart, string $monthEnd): array
    {
        $q = fn () => DB::table('daily_activity_entries as e')->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->join('activity_definition_calendar_classes as m', fn ($j) => $j->on('m.activity_definition_id', '=', 'd.id')->where('m.trigger_kind', 'instant')->whereNull('m.deleted_at'))
            ->where('e.agency_id', $agencyId)->where('e.user_id', $userId)->whereIn('m.slug', self::slugs())
            ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)->whereIn('e.source', DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES);
        $expr = DB::raw(ActivityPointsService::sqlPoints('e', 'd'));

        return [
            round((float) $q()->whereDate('e.activity_date', $day)->sum($expr), 2),
            round((float) $q()->whereBetween('e.activity_date', [$monthStart, $monthEnd])->sum($expr), 2),
        ];
    }

    /**
     * "Why did I get these points": the agent's own credits, newest first - date, property, what happened, points and the running total after that line.
     * Every credit an event wrote is a "+" line on the day it was earned; one that was TAKEN BACK also has a "-" line on the day it was taken back, so
     * the running total always adds up (+20, then -20 = 0). A credit still waiting to be confirmed shows its points but is not in the total yet.
     * The total is the agent's whole history up to that line, whatever filter is on.
     *
     * @param array{from?:?string,to?:?string,event?:?string,q?:?string} $filters
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function log(User $agent, array $filters = [], int $perPage = 25)
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $userId = (int) $agent->ownershipUserId();
        $addr = "COALESCE(NULLIF(TRIM(CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', tp.street_number, tp.street_name)), ''), NULLIF(tp.suburb, ''))), ''), NULLIF(tp.address_raw, ''), pl.address, NULLIF(pp.address, ''), NULLIF(pp.title, ''))";
        $pts = ActivityPointsService::sqlPoints('e', 'd');
        $counting = "e.point_state IN ('" . implode("','", DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES) . "','" . DailyActivityEntry::STATE_REVOKED . "')";
        $base = fn () => DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->leftJoin('activity_definition_calendar_classes as m', fn ($j) => $j->on('m.activity_definition_id', '=', 'd.id')->where('m.trigger_kind', 'instant')->where('m.agency_id', $agencyId)->whereNull('m.deleted_at'))
            ->leftJoin('rts_prospects as rp', fn ($j) => $j->on('rp.id', '=', 'e.subject_id')->where('e.subject_type', \App\Models\RoadToSold\RtsProspect::class))
            ->leftJoin('rts_contact_outcomes as ro', fn ($j) => $j->on('ro.id', '=', 'e.subject_id')->where('e.subject_type', \App\Models\RoadToSold\RtsContactOutcome::class))
            ->leftJoin('prospecting_claims as pc', fn ($j) => $j->on('pc.id', '=', 'e.subject_id')->where('e.subject_type', \App\Models\ProspectingClaim::class))
            ->leftJoin('properties as pp', fn ($j) => $j->on('pp.id', '=', 'e.subject_id')->where('e.subject_type', \App\Models\Property::class))
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', DB::raw('COALESCE(rp.tracked_property_id, ro.tracked_property_id)'))
            ->leftJoin('prospecting_listings as pl', 'pl.id', '=', DB::raw('COALESCE(pc.prospecting_listing_id, rp.prospecting_listing_id, ro.prospecting_listing_id)'))
            ->where('e.agency_id', $agencyId)->where('e.user_id', $userId)
            ->whereIn('e.source', [DailyActivityEntry::SOURCE_AUTO_INSTANT, DailyActivityEntry::SOURCE_AUTO_CALENDAR, DailyActivityEntry::SOURCE_MANUAL]);
        $cols = fn (string $date, string $kind, int $rank, string $points, string $delta) => [
            DB::raw("{$date} as line_date"), 'e.id as entry_id', DB::raw("{$rank} as line_rank"), DB::raw("'{$kind}' as line_kind"), 'e.point_state', 'e.revoke_reason',
            'm.slug', 'd.name as definition_name', DB::raw($addr . ' as property'), DB::raw($points . ' as points'), DB::raw($delta . ' as delta'),
        ];

        $credit = $base()->select($cols('e.activity_date', 'credit', 0, $pts, "(CASE WHEN {$counting} THEN {$pts} ELSE 0 END)"));
        $reversal = $base()->where('e.point_state', DailyActivityEntry::STATE_REVOKED)
            ->select($cols('DATE(COALESCE(e.revoked_at, e.updated_at))', 'reversal', 1, "-({$pts})", "-({$pts})"));
        $lines = DB::query()->fromSub($credit->unionAll($reversal), 'u')
            ->select('u.*', DB::raw('SUM(u.delta) OVER (ORDER BY u.line_date, u.entry_id, u.line_rank ROWS UNBOUNDED PRECEDING) as running'));

        $q = DB::query()->fromSub($lines, 'l')->select('l.*');
        if (! empty($filters['from'])) {
            $q->whereDate('l.line_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('l.line_date', '<=', $filters['to']);
        }
        if (! empty($filters['event'])) {
            $q->where('l.slug', $filters['event']);
        }
        if (! empty($filters['q'])) {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
            $q->where(fn ($w) => $w->where('l.property', 'like', $needle)->orWhere('l.definition_name', 'like', $needle));
        }

        return $q->orderByDesc('l.line_date')->orderByDesc('l.entry_id')->orderByDesc('l.line_rank')->paginate($perPage)->withQueryString()->through(function ($r) {
            $slug = (string) ($r->slug ?? '');
            $r->event = $slug !== '' ? $this->eventLabel($slug) : preg_replace('/^\[Auto\]\s*/', '', (string) $r->definition_name);
            $r->activity_date = $r->line_date;
            $r->reversed = $r->line_kind === 'reversal';
            $r->waiting = $r->point_state === DailyActivityEntry::STATE_PROVISIONAL;
            $r->running = round((float) $r->running, 2);

            return $r;
        });
    }

    private function eventLabel(string $slug): string
    {
        return isset(self::CREDITS[$slug]) ? self::CREDITS[$slug][0] : (\App\Support\Activity\ActivityLabelResolver::SLUG_LABEL[$slug] ?? ucfirst(str_replace(['.', '_'], ' ', $slug)));
    }
}
