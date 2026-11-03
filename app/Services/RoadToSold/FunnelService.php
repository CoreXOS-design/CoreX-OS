<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * AT-454 v2 phase 5 - the funnel, its ratios and the training flags (road-to-sold.md section 9.2, 9.3).
 *
 * THE POPULATION RULE (9.2, "a ratio whose numerator and denominator come from different populations is not shown"). Every figure is
 * counted by DrilldownService - the same queries as the Road to Sold summary bar, report and drill-down - and belongs to ONE agent: the
 * source's own owner column (the list owner on the call log, the calendar owner for evaluations and viewings, the document owner for
 * mandates and offers, the property's agent for listings, the creator of a presentation, the agents on a registered deal). An assistant's
 * work counts under the agent they act for (Johan, 20 Oct 2026, answer 4). A figure is therefore always "this agent's own", and a ratio
 * is only ever between two figures of the SAME agent over the SAME window.
 *
 * What a ratio is: a flow inside the window, not a cohort - "of the calls made in the window, how many were conversations", "of the
 * mandates signed in the window, how many listings went live". It reads each step against the one before it.
 *
 * A flag (9.3) is derived on every view and stored nowhere: an agent's ratio is flagged when the denominator reaches the agency's minimum
 * sample (default 20) AND the ratio is below the agency's percentage (default 60%) of the AGENCY MEDIAN ratio for the same window and
 * track. The median is taken over the agency's agents who themselves reach the minimum sample, and only when at least three of them do
 * (with fewer, "not enough data yet" - never a guess). Using the agency's own median means no agency is held to another's conversion
 * expectations (non-negotiable 9).
 */
class FunnelService
{
    /** Figure => plain label, in funnel order. */
    public const METRICS = [
        'calls_made'          => 'Calls made',
        'conversations'       => 'Conversations',
        'evaluations_booked'  => 'Appointments booked',
        'evaluations_done'    => 'Appointments held',
        'presentations_given' => 'Presentations given',
        'mandates_signed'     => 'Mandates signed',
        'listings_live'       => 'Listings live',
        'viewings_held'       => 'Viewings held',
        'otp_signed'          => 'Offers signed',
        'deals_registered'    => 'Deals registered',
    ];

    /**
     * Ratio key => [label, the recipe step it belongs to, numerator figure, denominator figure, the skill a low result points at].
     * The skill is neutral wording; the training an agency attaches to it is its own (rts_training_pointers).
     */
    public const RATIOS = [
        'calls_to_conversations'          => ['Calls to conversations',          'contact_outcome',   'conversations',       'calls_made',          'getting owners talking'],
        'conversations_to_evaluations'    => ['Conversations to appointments',   'evaluation_booked', 'evaluations_booked',  'conversations',       'the appointment'],
        'evaluations_booked_to_done'      => ['Appointments booked to held',    'evaluation_booked', 'evaluations_done',    'evaluations_booked',  'keeping appointments'],
        'evaluations_to_presentations'    => ['Appointments to presentations',    'presentation',      'presentations_given', 'evaluations_done',    'presenting after an appointment'],
        'presentations_to_mandates'       => ['Presentations to mandates',       'mandate',           'mandates_signed',     'presentations_given', 'winning the mandate'],
        'mandates_to_listings'            => ['Mandates to listings live',       'listing_live',      'listings_live',       'mandates_signed',     'getting the listing live'],
        'listings_to_viewings'            => ['Listings live to viewings',       'viewings',          'viewings_held',       'listings_live',       'getting viewings on listings'],
        'viewings_to_offers'              => ['Viewings to offers',              'otp',               'otp_signed',          'viewings_held',       'turning viewings into offers'],
        'offers_to_registered'            => ['Offers to registered deals',      'deal',              'deals_registered',    'otp_signed',          'seeing offers through to registration'],
    ];

    /**
     * v4 (road-to-sold.md 23.12): the OFF-RAMP flags. Same tuple shape as RATIOS: [label, recipe step, null, null, the skill a high loss rate points at]. They are
     * measured by BeltFunnelService (an agent's loss rate at a station against their branch's / the agency's), not by a numerator / denominator pair, so they are
     * never in RATIOS - every place that reads a flag's meaning asks meta().
     */
    public const OFFRAMPS = [
        'offramp_claimed'   => ['Loses properties after claiming',        'find',              null, null, 'working a claim through to the owner'],
        'offramp_owner'     => ['Loses properties at Owner found',        'find_owner',        null, null, 'finding and linking the owner'],
        'offramp_contacted' => ['Loses properties at Contacted',          'contact_outcome',   null, null, 'turning a first contact into an appointment'],
        'offramp_booked'    => ['Loses properties at Booked        ',  'evaluation_booked', null, null, 'keeping appointments'],
        'offramp_held'      => ['Loses properties after the appointment',  'presentation',      null, null, 'winning the seller after the appointment'],
    ];

    /** A flag key's meaning: a funnel ratio or an off-ramp flag. @return array{0:string,1:string,2:?string,3:?string,4:string}|null */
    public static function meta(string $key): ?array
    {
        return self::RATIOS[$key] ?? self::OFFRAMPS[$key] ?? null;
    }

    /** The fewest agents that must reach the minimum sample before an agency median means anything. */
    public const MIN_AGENTS_FOR_MEDIAN = 3;

    /** The windows the screens offer, in days. */
    public const WINDOWS = [7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 60 => 'Last 60 days', 90 => 'Last 90 days'];

    public function __construct(private readonly DrilldownService $drill)
    {
    }

    /** The trailing window of $days ending at the end of today. */
    public function windowPeriod(int $days, ?CarbonInterface $now = null): Period
    {
        $days  = max(1, min(366, $days));
        $end   = CarbonImmutable::instance($now ?? now())->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();

        return new Period($start, $end, 'Last ' . $days . ' days', 'custom');
    }

    /** The agency's agents whose work the funnel counts: active, not assistants, not platform owners, allowed into Road to Sold. @return int[] */
    public function agencyAgentIds(int $agencyId): array
    {
        return User::query()->withoutGlobalScopes()->agencyMembers()
            ->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', false))
            ->orderBy('id')->get()
            ->filter(fn (User $u) => $u->hasPermission('access_road_to_sold'))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * The funnel for a set of agents.
     *
     * @param  int[]  $agentIds
     * @return array{counts:array<int,array<string,int>>, ratios:array<int,array<string,array{num:int,den:int,pct:?float}>>}
     */
    public function compute(int $agencyId, array $agentIds, Period $period, string $track = 'sales', ?string $doneBy = null): array
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        $counts = array_fill_keys($agentIds, []);
        foreach (array_keys(self::METRICS) as $metric) {
            $by = $this->drill->countsByAgent($metric, $agentIds, $period, $agencyId, $track, $doneBy);
            foreach ($agentIds as $id) {
                $counts[$id][$metric] = (int) ($by[$id] ?? 0);
            }
        }

        $ratios = [];
        foreach ($counts as $id => $c) {
            $ratios[$id] = $this->ratiosOf($c);
        }

        return ['counts' => $counts, 'ratios' => $ratios];
    }

    /**
     * The whole agency's funnel for a window, its medians and the flags - everything a screen needs, computed once. The median is always
     * the whole agency's, so a branch manager and an admin see the same flag for the same agent.
     *
     * @return array{computed:array{counts:array,ratios:array},medians:array<string,?float>,flags:array<int,array<string,array<string,mixed>>>}
     */
    public function snapshot(int $agencyId, Period $period, string $track, SettingsService $settings, ?string $doneBy = null): array
    {
        $computed = $this->compute($agencyId, $this->agencyAgentIds($agencyId), $period, $track, $doneBy);
        $medians = $this->medians($computed['ratios'], $settings->flagMinSample());

        $flags = $this->flags($computed['ratios'], $medians, $settings->flagMinSample(), $settings->flagBelowPct());
        // v4 (23.12): where properties are lost on the belt - an agent's loss rate at a station against the branch's / agency's (BeltFunnelService)
        try {
            $belt = app(BeltFunnelService::class);
            foreach ($belt->flags($agencyId, $belt->figures($agencyId, $period, $track), $this->agencyAgentIds($agencyId), $settings) as $agentId => $byKey) {
                foreach ($byKey as $key => $flag) {
                    $flags[$agentId][$key] = $flag;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Belt off-ramp flags could not be worked out (the ratio flags stand)', ['message' => $e->getMessage()]);
        }

        return ['computed' => $computed, 'medians' => $medians, 'flags' => $flags];
    }

    /**
     * The ratios of one set of figures. A ratio with nothing in its denominator has no percentage (null) - it is never shown as 0%.
     *
     * @param  array<string,int>  $c
     * @return array<string,array{num:int,den:int,pct:?float}>
     */
    public function ratiosOf(array $c): array
    {
        $out = [];
        foreach (self::RATIOS as $key => [, , $num, $den]) {
            $n = (int) ($c[$num] ?? 0);
            $d = (int) ($c[$den] ?? 0);
            $out[$key] = ['num' => $n, 'den' => $d, 'pct' => $d > 0 ? round($n / $d * 100, 1) : null];
        }

        return $out;
    }

    /** Add several agents' figures together (a team, a branch). @param array<int,array<string,int>> $counts @return array<string,int> */
    public function sum(array $counts): array
    {
        $out = array_fill_keys(array_keys(self::METRICS), 0);
        foreach ($counts as $c) {
            foreach ($out as $metric => $_) {
                $out[$metric] += (int) ($c[$metric] ?? 0);
            }
        }

        return $out;
    }

    /**
     * The agency median of each ratio over the agents who reach the minimum sample; null when fewer than three do ("not enough data yet").
     *
     * @param  array<int,array<string,array{num:int,den:int,pct:?float}>>  $ratios  every agent of the agency
     * @return array<string,?float>
     */
    public function medians(array $ratios, int $minSample): array
    {
        $out = [];
        foreach (array_keys(self::RATIOS) as $key) {
            $values = [];
            foreach ($ratios as $r) {
                if (($r[$key]['pct'] ?? null) !== null && $r[$key]['den'] >= $minSample) {
                    $values[] = (float) $r[$key]['pct'];
                }
            }
            if (count($values) < self::MIN_AGENTS_FOR_MEDIAN) {
                $out[$key] = null;
                continue;
            }
            sort($values);
            $n = count($values);
            $out[$key] = round($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2, 1);
        }

        return $out;
    }

    /**
     * The flags for the agents in $ratios: agent id => ratio key => {pct, median, num, den, severity}. Severity is how far below the
     * median the agent is (0 to 1), so the worst gap sorts first. An agent with no data never has a flag.
     *
     * @param  array<int,array<string,array{num:int,den:int,pct:?float}>>  $ratios
     * @param  array<string,?float>  $medians
     * @return array<int,array<string,array<string,mixed>>>
     */
    public function flags(array $ratios, array $medians, int $minSample, int $belowPct): array
    {
        $out = [];
        foreach ($ratios as $agentId => $r) {
            foreach (self::RATIOS as $key => $_) {
                $pct = $r[$key]['pct'] ?? null;
                $median = $medians[$key] ?? null;
                if ($pct === null || $median === null || $median <= 0 || $r[$key]['den'] < $minSample) {
                    continue;
                }
                if ($pct < $median * $belowPct / 100) {
                    $out[(int) $agentId][$key] = [
                        'pct' => $pct, 'median' => $median, 'num' => $r[$key]['num'], 'den' => $r[$key]['den'],
                        'severity' => round(1 - $pct / $median, 4),
                    ];
                }
            }
        }

        return $out;
    }

    /** The sentence for a flag: "Needs training: the evaluation pitch". An off-ramp flag names the station and the top reason ("Retha loses 38% of properties at Contacted (branch 22%)"). */
    public static function flagText(string $ratioKey, ?array $flag = null): string
    {
        if (isset(self::OFFRAMPS[$ratioKey]) && ! empty($flag['text'])) {
            return $flag['text'];
        }

        return 'Needs training: ' . (self::meta($ratioKey)[4] ?? $ratioKey);
    }
}
