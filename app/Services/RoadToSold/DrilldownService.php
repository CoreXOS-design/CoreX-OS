<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 - the rows behind a Road to Sold figure. One service for the summary bar's steps and the
 * report's columns, so a figure and its drill-down can never disagree. Returns the shared drill-down
 * envelope {title, total, columns[], rows[], truncated} (the modal in buyers-report/_drilldown-modal).
 *
 * The caller has already validated that $agentIds are agents the viewer may see; this service only
 * adds the agency wall. Rows are capped at MAX_ROWS (the true count is in `total`).
 *
 * Metrics: calls_made, attempts, conversations, evaluations_booked, dropped, outcome:<key>
 * (the call log); worked, added_worked, missed (the day's list: worked toward the target, worked on top
 * of it, missed or rolled over); target (one row per working day); evaluations_done (calendar appointments with feedback); mandates_signed (completed
 * mandate e-sign documents); listings_live (listings that went live in the window).
 */
class DrilldownService
{
    public const MAX_ROWS = 1000;
    public const ITEM_METRICS = ['worked', 'added_worked', 'missed'];

    public function __construct(private readonly WorkdayService $workdays)
    {
    }

    public const LOG_METRICS = ['calls_made', 'attempts', 'conversations', 'evaluations_booked', 'dropped'];

    /** Phase 5 funnel figures: a presentation first delivered, a viewing held, an offer to purchase signed, a deal registered. */
    public const SELL_METRICS = ['presentations_given', 'viewings_held', 'otp_signed', 'deals_registered'];

    /** The document type slug a signed offer to purchase carries (the CoreX `otp` type; an agency's own types come with the 13.1 setting). */
    public const OTP_TYPE = 'otp';

    public function supports(string $metric): bool
    {
        return in_array($metric, self::LOG_METRICS, true)
            || str_starts_with($metric, 'outcome:')
            || in_array($metric, self::ITEM_METRICS, true)
            || in_array($metric, ['target', 'evaluations_done', 'mandates_signed', 'listings_live'], true)
            || in_array($metric, self::SELL_METRICS, true);
    }

    /**
     * @param  int[]  $agentIds
     * @return array{title:string,total:int,columns:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>,truncated:bool}
     */
    public function rows(string $metric, array $agentIds, Period $period, int $agencyId, string $track = 'sales'): array
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        if ($agencyId <= 0 || $agentIds === [] || ! $this->supports($metric)) {
            return $this->envelope($this->title($metric, $period), [], [], 0, false);
        }

        $from = $period->start->toDateTimeString();
        $to = $period->end->toDateTimeString();

        return match (true) {
            $metric === 'evaluations_done' => $this->evaluationsDone($agentIds, $from, $to, $agencyId, $period),
            $metric === 'mandates_signed' => $this->mandatesSigned($agentIds, $from, $to, $agencyId, $period),
            $metric === 'listings_live'   => $this->listingsLive($agentIds, $from, $to, $agencyId, $period, $track),
            $metric === 'presentations_given' => $this->presentationsGiven($agentIds, $from, $to, $agencyId, $period),
            $metric === 'viewings_held'   => $this->viewingsHeld($agentIds, $from, $to, $agencyId, $period),
            $metric === 'otp_signed'      => $this->otpSigned($agentIds, $from, $to, $agencyId, $period),
            $metric === 'deals_registered' => $this->dealsRegistered($agentIds, $period),
            $metric === 'target'          => $this->targetDays($agentIds, $agencyId, $period, $track),
            in_array($metric, self::ITEM_METRICS, true) => $this->listItems($metric, $agentIds, $agencyId, $period, $track),
            default                       => $this->callLog($metric, $agentIds, $from, $to, $agencyId, $period, $track),
        };
    }

    /**
     * The figure itself - the SAME query the rows come from, so the number and its drill-down cannot disagree.
     *
     * @param  int[]  $agentIds
     */
    public function count(string $metric, array $agentIds, Period $period, int $agencyId, string $track = 'sales'): int
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        if ($agencyId <= 0 || $agentIds === [] || ! $this->supports($metric)) {
            return 0;
        }
        $from = $period->start->toDateTimeString();
        $to = $period->end->toDateTimeString();

        return match (true) {
            $metric === 'evaluations_done' => $this->evaluationsDoneQuery($agentIds, $from, $to, $agencyId)->count(),
            $metric === 'mandates_signed' => $this->mandatesQuery($agentIds, $from, $to, $agencyId)->count(),
            $metric === 'listings_live'   => $this->listingsQuery($agentIds, $from, $to, $agencyId, $track)->count(),
            $metric === 'presentations_given' => $this->presentationsQuery($agentIds, $from, $to, $agencyId)->count(),
            $metric === 'viewings_held'   => $this->viewingsQuery($agentIds, $from, $to, $agencyId)->count(),
            $metric === 'otp_signed'      => $this->mandatesQuery($agentIds, $from, $to, $agencyId, self::OTP_TYPE)->count(),
            $metric === 'deals_registered' => array_sum($this->registeredByAgent($agentIds, $period)),
            $metric === 'target'          => $this->targetTotal($agentIds, $agencyId, $period, $track),
            in_array($metric, self::ITEM_METRICS, true) => $this->itemsQuery($metric, $agentIds, $period, $agencyId, $track)->count(),
            default                       => $this->callLogQuery($metric, $agentIds, $from, $to, $agencyId, $track)->count(),
        };
    }

    /**
     * The same figure for MANY agents at once, one query per figure: agent id => count. Built from the SAME query builders count() and
     * rows() use (grouped by the owner column of that source), so a grid cell and the figure behind it cannot disagree.
     *
     * Who a count belongs to (the funnel's population rule, road-to-sold.md 9.2) is the source's own owner column: a call, a booked
     * evaluation and a conversation by the list owner on the call log; an evaluation and a viewing by the calendar owner; a mandate and
     * an offer by the document owner; a listing by the property's agent; a presentation by who created it; a registered deal by the
     * agents on the deal. Every agent who is passed appears in the answer, with 0 when there is nothing.
     *
     * $doneBy narrows the call-log figures only ('agent' = made by the agent themselves, 'assistant' = made by an assistant on their
     * behalf, recorded_by_user_id differs): the other sources do not record who pressed the button, so for them 'agent' is the whole
     * figure and 'assistant' is 0 - the two always add up to the total.
     *
     * @param  int[]  $agentIds
     * @return array<int,int>
     */
    public function countsByAgent(string $metric, array $agentIds, Period $period, int $agencyId, string $track = 'sales', ?string $doneBy = null): array
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        $out = array_fill_keys($agentIds, 0);
        if ($agencyId <= 0 || $agentIds === [] || ! $this->supports($metric) || in_array($metric, ['target', ...self::ITEM_METRICS], true)) {
            return $out;
        }
        $from = $period->start->toDateTimeString();
        $to = $period->end->toDateTimeString();
        $isLog = in_array($metric, self::LOG_METRICS, true) || str_starts_with($metric, 'outcome:');

        if ($metric === 'deals_registered') {
            $counts = $this->registeredByAgent($agentIds, $period);

            return ($doneBy === 'assistant' ? [] : $counts) + $out;
        }
        if (! $isLog && $doneBy === 'assistant') {
            return $out;
        }

        [$query, $column] = match (true) {
            $metric === 'evaluations_done'     => [$this->evaluationsDoneQuery($agentIds, $from, $to, $agencyId), 'ce.user_id'],
            $metric === 'mandates_signed'      => [$this->mandatesQuery($agentIds, $from, $to, $agencyId), 'd.owner_id'],
            $metric === 'otp_signed'           => [$this->mandatesQuery($agentIds, $from, $to, $agencyId, self::OTP_TYPE), 'd.owner_id'],
            $metric === 'listings_live'        => [$this->listingsQuery($agentIds, $from, $to, $agencyId, $track), 'p.agent_id'],
            $metric === 'presentations_given'  => [$this->presentationsQuery($agentIds, $from, $to, $agencyId), 'pr.created_by_user_id'],
            $metric === 'viewings_held'        => [$this->viewingsQuery($agentIds, $from, $to, $agencyId), 'ce.user_id'],
            default                            => [$this->callLogQuery($metric, $agentIds, $from, $to, $agencyId, $track), 'o.user_id'],
        };
        if ($isLog && $doneBy === 'agent') {
            $query->whereColumn('o.recorded_by_user_id', 'o.user_id');
        } elseif ($isLog && $doneBy === 'assistant') {
            $query->whereColumn('o.recorded_by_user_id', '!=', 'o.user_id');
        }
        foreach ($query->selectRaw($column . ' as agent_id, COUNT(*) as n')->groupBy($column)->get() as $row) {
            $out[(int) $row->agent_id] = (int) $row->n;
        }

        return $out;
    }

    public function title(string $metric, Period $period): string
    {
        $label = match (true) {
            str_starts_with($metric, 'outcome:') => 'Outcome: ' . str_replace('_', ' ', substr($metric, 8)),
            default => match ($metric) {
                'calls_made'        => 'Calls made',
                'attempts'          => 'Attempts',
                'conversations'     => 'Conversations',
                'evaluations_booked' => 'Evaluations booked',
                'evaluations_done'   => 'Evaluations done',
                'mandates_signed'   => 'Mandates signed',
                'listings_live'     => 'Listings live',
                'presentations_given' => 'Presentations given',
                'viewings_held'     => 'Viewings held',
                'otp_signed'        => 'Offers to purchase signed',
                'deals_registered'  => 'Deals registered',
                'dropped'           => 'Dropped after repeated no-answers',
                'worked'            => 'Worked toward the target',
                'added_worked'      => 'Worked on top of the target',
                'missed'            => 'Missed or rolled over',
                'target'            => 'Target',
                default             => ucfirst(str_replace('_', ' ', $metric)),
            },
        };

        return $label . ' - ' . $period->label;
    }

    // ── Call log ────────────────────────────────────────────────────────────────────────────

    private function callLogQuery(string $metric, array $agentIds, string $from, string $to, int $agencyId, string $track)
    {
        $q = DB::table('rts_contact_outcomes as o')
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'o.tracked_property_id')   // left: a call on an archived property still counts, as the report counts it
            ->leftJoin('contacts as c', 'c.id', '=', 'o.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('users as rb', 'rb.id', '=', 'o.recorded_by_user_id')
            ->where('o.agency_id', $agencyId)->where('o.track', $track)->whereNull('o.deleted_at')
            ->whereIn('o.user_id', $agentIds)->whereBetween('o.occurred_at', [$from, $to]);

        match (true) {
            $metric === 'conversations'     => $q->where('o.kind', 'conversation'),
            $metric === 'attempts'          => $q->where('o.kind', 'attempt'),
            $metric === 'evaluations_booked' => $q->where('o.valuation_booked', true),
            $metric === 'dropped'           => $q->where('o.dropped', true),
            str_starts_with($metric, 'outcome:') => $q->where('o.outcome_key', substr($metric, 8)),
            default                         => null,
        };

        return $q;
    }

    private function callLog(string $metric, array $agentIds, string $from, string $to, int $agencyId, Period $period, string $track): array
    {
        $q = $this->callLogQuery($metric, $agentIds, $from, $to, $agencyId, $track);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('o.occurred_at')->limit(self::MAX_ROWS)->get([
            'o.occurred_at', 'o.outcome_label', 'o.kind', 'o.note', 'o.valuation_booked', 'o.no_answer_streak', 'o.tracked_property_id',
            'tp.id as tp_id', 'tp.deleted_at as tp_deleted_at', 'tp.street_number', 'tp.street_name', 'tp.suburb', 'u.name as agent', 'rb.name as made_by',
            'c.first_name', 'c.last_name', 'c.entity_name',
        ])->map(fn ($r) => [
            'when'     => Carbon::parse($r->occurred_at)->format('j M Y H:i'),
            'agent'    => $r->agent,
            'made_by'  => $r->made_by ?: '-',
            'property' => $this->address($r),
            'owner'    => $this->ownerName($r),
            'outcome'  => $r->outcome_label . ($r->valuation_booked ? ' (evaluation booked)' : ''),
            'note'     => $r->note,
            'href'     => route('market-intelligence.opportunities.show', $r->tracked_property_id),
        ])->all();

        return $this->envelope($this->title($metric, $period), [
            ['key' => 'when', 'label' => 'When', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'made_by', 'label' => 'Call made by', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
            ['key' => 'owner', 'label' => 'Owner', 'align' => 'left'],
            ['key' => 'outcome', 'label' => 'Outcome', 'align' => 'left'],
            ['key' => 'note', 'label' => 'Note', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── The day's list: worked / worked on top / missed or rolled over ─────────────────────

    private function itemsQuery(string $metric, array $agentIds, Period $period, int $agencyId, string $track)
    {
        $q = DB::table('rts_list_items as i')
            ->where('i.agency_id', $agencyId)->where('i.track', $track)->whereNull('i.deleted_at')
            ->whereIn('i.user_id', $agentIds)
            ->whereBetween('i.list_date', [$period->start->toDateString(), $period->end->toDateString()]);

        match ($metric) {
            'worked'       => $q->where('i.origin', '!=', 'added')->where('i.status', 'worked'),
            'added_worked' => $q->where('i.origin', 'added')->where('i.status', 'worked'),
            default        => $q->where('i.origin', '!=', 'added')->whereIn('i.status', ['missed', 'rolled']),
        };

        return $q;
    }

    private function listItems(string $metric, array $agentIds, int $agencyId, Period $period, string $track): array
    {
        $q = $this->itemsQuery($metric, $agentIds, $period, $agencyId, $track);
        $total = (clone $q)->count();
        $rows = $q->leftJoin('tracked_properties as tp', 'tp.id', '=', 'i.tracked_property_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->orderByDesc('i.list_date')->orderBy('i.position')->limit(self::MAX_ROWS)
            ->get(['i.list_date', 'i.status', 'i.reason', 'i.tracked_property_id', 'tp.id as tp_id', 'tp.deleted_at as tp_deleted_at', 'tp.street_number', 'tp.street_name', 'tp.suburb', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->list_date)->format('j M Y'), 'agent' => $r->agent, 'property' => $this->address($r),
                'why' => $r->reason, 'status' => ucfirst((string) $r->status),
                'href' => $r->tracked_property_id ? route('market-intelligence.opportunities.show', $r->tracked_property_id) : null,
            ])->all();

        return $this->envelope($this->title($metric, $period), [
            ['key' => 'when', 'label' => 'Day', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
            ['key' => 'why', 'label' => 'Why suggested', 'align' => 'left'],
            ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Target: one row per agent per day that carries a target ────────────────────────────

    /** @return array<int, array{day:string, agent:string, target:int}> */
    private function targetRows(array $agentIds, int $agencyId, Period $period, string $track): array
    {
        $agents = User::query()->where('agency_id', $agencyId)->whereIn('id', $agentIds)->orderBy('name')->get(['id', 'name', 'agency_id', 'branch_id', 'role']);
        $rows = [];
        foreach ($agents as $agent) {
            for ($d = $period->start->startOfDay(); $d->lte($period->end); $d = $d->addDay()) {
                $t = $this->workdays->targetFor($agent, $d, $track);
                if ($t > 0) {
                    $rows[] = ['day' => $d->format('D j M Y'), 'agent' => $agent->name, 'target' => $t, 'sort' => $d->toDateString()];
                }
            }
        }
        usort($rows, fn ($a, $b) => [$b['sort'], $a['agent']] <=> [$a['sort'], $b['agent']]);

        return $rows;
    }

    private function targetTotal(array $agentIds, int $agencyId, Period $period, string $track): int
    {
        return (int) array_sum(array_column($this->targetRows($agentIds, $agencyId, $period, $track), 'target'));
    }

    private function targetDays(array $agentIds, int $agencyId, Period $period, string $track): array
    {
        $all = $this->targetRows($agentIds, $agencyId, $period, $track);
        $rows = array_map(fn ($r) => ['when' => $r['day'], 'agent' => $r['agent'], 'target' => $r['target']], array_slice($all, 0, self::MAX_ROWS));

        return $this->envelope($this->title('target', $period), [
            ['key' => 'when', 'label' => 'Day', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'target', 'label' => 'Target', 'align' => 'right', 'format' => 'number'],
        ], $rows, (int) array_sum(array_column($all, 'target')), count($all) > self::MAX_ROWS);
    }

    // ── Evaluations done: evaluation appointments with feedback captured ─────────────────────

    private function evaluationsDoneQuery(array $agentIds, string $from, string $to, int $agencyId)
    {
        return DB::table('calendar_events as ce')
            ->leftJoin('properties as p', 'p.id', '=', 'ce.property_id')
            ->leftJoin('users as u', 'u.id', '=', 'ce.user_id')
            ->where('ce.agency_id', $agencyId)->whereNull('ce.deleted_at')
            ->where('ce.category', 'property_evaluation')
            ->whereIn('ce.user_id', $agentIds)->whereBetween('ce.event_date', [$from, $to])
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('calendar_event_feedback as f')
                ->whereColumn('f.calendar_event_id', 'ce.id')->whereNotNull('f.captured_at')->whereNull('f.deleted_at'));
    }

    private function evaluationsDone(array $agentIds, string $from, string $to, int $agencyId, Period $period): array
    {
        $q = $this->evaluationsDoneQuery($agentIds, $from, $to, $agencyId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('ce.event_date')->limit(self::MAX_ROWS)->get(['ce.id', 'ce.title', 'ce.event_date', 'p.title as property', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->event_date)->format('j M Y H:i'), 'agent' => $r->agent,
                'property' => $r->property ?: $r->title, 'href' => route('command-center.calendar.show', $r->id),
            ])->all();

        return $this->envelope($this->title('evaluations_done', $period), [
            ['key' => 'when', 'label' => 'When', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Appointment', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Mandates signed: a mandate e-sign document reaching COMPLETED ──────────────────────

    private function mandatesQuery(array $agentIds, string $from, string $to, int $agencyId, string $type = 'mandate')
    {
        return DB::table('signature_templates as st')
            ->join('docuperfect_documents as d', 'd.id', '=', 'st.document_id')
            ->leftJoin('docuperfect_templates as t', 't.id', '=', 'd.template_id')
            ->leftJoin('properties as p', 'p.id', '=', 'd.property_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.owner_id')
            ->where('d.agency_id', $agencyId)->whereNull('d.deleted_at')
            ->where('st.status', 'completed')
            ->whereRaw('COALESCE(t.template_type, d.document_type) = ?', [$type])
            ->whereIn('d.owner_id', $agentIds)->whereBetween('st.completed_at', [$from, $to]);
    }

    private function mandatesSigned(array $agentIds, string $from, string $to, int $agencyId, Period $period): array
    {
        $q = $this->mandatesQuery($agentIds, $from, $to, $agencyId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('st.completed_at')->limit(self::MAX_ROWS)->get(['st.completed_at', 'p.id as property_id', 'p.title as property', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->completed_at)->format('j M Y'), 'agent' => $r->agent, 'property' => $r->property ?: 'Mandate',
                'href' => $r->property_id ? route('corex.properties.show', $r->property_id) : null,
            ])->all();

        return $this->envelope($this->title('mandates_signed', $period), [
            ['key' => 'when', 'label' => 'Signed', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Listings live: gone live on a portal inside the window ─────────────────────────────

    private function listingsQuery(array $agentIds, string $from, string $to, int $agencyId, string $track)
    {
        return DB::table('properties as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.agent_id')
            ->where('p.agency_id', $agencyId)->whereNull('p.deleted_at')
            ->where('p.listing_type', $track === 'rentals' ? 'rental' : 'sale')
            ->whereIn('p.agent_id', $agentIds)
            ->whereBetween('p.listed_date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()]);
    }

    private function listingsLive(array $agentIds, string $from, string $to, int $agencyId, Period $period, string $track): array
    {
        $q = $this->listingsQuery($agentIds, $from, $to, $agencyId, $track);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('p.listed_date')->limit(self::MAX_ROWS)->get(['p.id', 'p.title', 'p.listed_date', 'p.price', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->listed_date)->format('j M Y'), 'agent' => $r->agent, 'property' => $r->title ?: 'Listing',
                'price' => $r->price ? (int) $r->price : null, 'href' => route('corex.properties.show', $r->id),
            ])->all();

        return $this->envelope($this->title('listings_live', $period), [
            ['key' => 'when', 'label' => 'Went live', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
            ['key' => 'price', 'label' => 'Price', 'align' => 'right', 'format' => 'currency'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Presentations given: the FIRST delivery that reached the seller (email sent, link copied, WhatsApp click-through) ──

    private function presentationsQuery(array $agentIds, string $from, string $to, int $agencyId)
    {
        $first = DB::table('presentation_deliveries')->whereNull('deleted_at')->whereNotNull('sent_at')
            ->selectRaw('presentation_id, MIN(sent_at) as first_sent_at')->groupBy('presentation_id');

        return DB::table('presentations as pr')
            ->joinSub($first, 'fd', 'fd.presentation_id', '=', 'pr.id')
            ->leftJoin('users as u', 'u.id', '=', 'pr.created_by_user_id')
            ->where('pr.agency_id', $agencyId)->whereNull('pr.deleted_at')
            ->whereIn('pr.created_by_user_id', $agentIds)->whereBetween('fd.first_sent_at', [$from, $to]);
    }

    private function presentationsGiven(array $agentIds, string $from, string $to, int $agencyId, Period $period): array
    {
        $q = $this->presentationsQuery($agentIds, $from, $to, $agencyId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('fd.first_sent_at')->limit(self::MAX_ROWS)->get(['pr.id', 'pr.title', 'pr.property_address', 'fd.first_sent_at', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->first_sent_at)->format('j M Y'), 'agent' => $r->agent,
                'property' => $r->property_address ?: ($r->title ?: 'Presentation'), 'href' => route('presentations.show', $r->id),
            ])->all();

        return $this->envelope($this->title('presentations_given', $period), [
            ['key' => 'when', 'label' => 'Given', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Viewings held: a viewing appointment with feedback captured as "viewed" ───────────────

    private function viewingsQuery(array $agentIds, string $from, string $to, int $agencyId)
    {
        return DB::table('calendar_events as ce')
            ->leftJoin('properties as p', 'p.id', '=', 'ce.property_id')
            ->leftJoin('users as u', 'u.id', '=', 'ce.user_id')
            ->where('ce.agency_id', $agencyId)->whereNull('ce.deleted_at')
            ->where('ce.category', 'viewing')
            ->whereIn('ce.user_id', $agentIds)->whereBetween('ce.event_date', [$from, $to])
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('calendar_event_feedback as f')
                ->whereColumn('f.calendar_event_id', 'ce.id')->where('f.viewing_status', 'viewed')->whereNotNull('f.captured_at')->whereNull('f.deleted_at'));
    }

    private function viewingsHeld(array $agentIds, string $from, string $to, int $agencyId, Period $period): array
    {
        $q = $this->viewingsQuery($agentIds, $from, $to, $agencyId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('ce.event_date')->limit(self::MAX_ROWS)->get(['ce.id', 'ce.title', 'ce.event_date', 'p.title as property', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->event_date)->format('j M Y H:i'), 'agent' => $r->agent,
                'property' => $r->property ?: $r->title, 'href' => route('command-center.calendar.show', $r->id),
            ])->all();

        return $this->envelope($this->title('viewings_held', $period), [
            ['key' => 'when', 'label' => 'When', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Viewing', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Offers to purchase signed: the same query as mandates, for the offer document type ───

    private function otpSigned(array $agentIds, string $from, string $to, int $agencyId, Period $period): array
    {
        $q = $this->mandatesQuery($agentIds, $from, $to, $agencyId, self::OTP_TYPE);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('st.completed_at')->limit(self::MAX_ROWS)->get(['st.completed_at', 'p.id as property_id', 'p.title as property', 'u.name as agent'])
            ->map(fn ($r) => [
                'when' => Carbon::parse($r->completed_at)->format('j M Y'), 'agent' => $r->agent, 'property' => $r->property ?: 'Offer to purchase',
                'href' => $r->property_id ? route('corex.properties.show', $r->property_id) : null,
            ])->all();

        return $this->envelope($this->title('otp_signed', $period), [
            ['key' => 'when', 'label' => 'Signed', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
        ], $rows, $total, $total > self::MAX_ROWS);
    }

    // ── Deals registered: the agency's own registered-deals figure (DR1 + DR2 deduplicated) ───

    /** @return array<int,int> agent id => deals registered in the window */
    private function registeredByAgent(array $agentIds, Period $period): array
    {
        return (new \App\Services\Performance\Providers\DealsRegisteredProvider())->forUsers($agentIds, $period);
    }

    private function dealsRegistered(array $agentIds, Period $period): array
    {
        $start = $period->start->toDateString();
        $end = $period->end->toDateString();
        $names = DB::table('users')->whereIn('id', $agentIds)->pluck('name', 'id');
        $rows = [];
        $seen = [];
        $mine = DB::table('deals as d')->leftJoin('deal_user as du', 'du.deal_id', '=', 'd.id')
            ->whereNull('d.deleted_at')->whereNotNull('d.registration_date')->whereBetween('d.registration_date', [$start, $end])
            ->where(fn ($w) => $w->whereIn('d.managed_by_user_id', $agentIds)->orWhereIn('du.user_id', $agentIds))
            ->orderByDesc('d.registration_date')->limit(self::MAX_ROWS)
            ->get(['d.id', 'd.property_address', 'd.registration_date', 'd.managed_by_user_id', 'du.user_id as du_user']);
        foreach ($mine as $r) {
            if (isset($seen[$r->id])) {
                continue;
            }
            $seen[$r->id] = true;
            $who = in_array((int) $r->managed_by_user_id, $agentIds, true) ? (int) $r->managed_by_user_id : (int) $r->du_user;
            $rows[] = ['when' => Carbon::parse($r->registration_date)->format('j M Y'), 'agent' => $names[$who] ?? '', 'property' => $r->property_address ?: 'Deal', 'href' => null];
        }
        $total = array_sum($this->registeredByAgent($agentIds, $period));

        return $this->envelope($this->title('deals_registered', $period), [
            ['key' => 'when', 'label' => 'Registered', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
        ], $rows, $total, $total > count($rows));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function address(object $r): string
    {
        $street = trim(trim((string) $r->street_number) . ' ' . trim((string) $r->street_name));
        $line = trim($street . ($r->suburb ? ($street !== '' ? ', ' : '') . $r->suburb : ''));

        $line = $line !== '' ? $line : '(no address)';
        if (property_exists($r, 'tp_id') && ($r->tp_id === null || ! empty($r->tp_deleted_at))) {
            return $r->tp_id === null ? '(property archived)' : $line . ' (property archived)';
        }

        return $line;
    }

    private function ownerName(object $r): ?string
    {
        $name = trim(($r->entity_name ?: '') !== '' ? (string) $r->entity_name : trim($r->first_name . ' ' . $r->last_name));

        return $name !== '' ? $name : null;
    }

    private function envelope(string $title, array $columns, array $rows, int $total, bool $truncated): array
    {
        return ['title' => $title, 'total' => $total, 'columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }
}
