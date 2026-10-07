<?php

namespace App\Services\LeadResponse;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Services\Performance\Period;
use App\Support\LeadResponse\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lead response time — ONE calculation (Johan, 2026-10-07; .ai/specs/lead-response-time.md).
 *
 *   results()    the single source of truth: one LeadResponseResult per portal enquiry in the period for the
 *                given agents. Everything below is built on it; new summaries/reports add a method that
 *                reads results(), never a second query or a second formula.
 *   summarise()  counts + average + median for any slice of results (company, one agent, one source, …).
 *   report()     company + per-agent + per-source summaries in one pass (what both reports render).
 *   rows()       the drill-down list behind any figure (same results, same filters, so a figure and its list
 *                can never disagree).
 *
 * Definitions:
 *  - A lead is an enquiry row in portal_leads (Property24, Private Property, website, shared link).
 *  - WHOSE lead: received_by_user_id, else the listing's agent, else the contact's primary agent.
 *  - First response: portal_leads.first_response_* (LeadResponseRecorder) — a contacted action, a message
 *    sent, a shared link, or feedback on an appointment. A note never counts. If a MANAGER responds, the
 *    time counts on the agent's lead and the manager is shown as the responder.
 *  - Minutes counted: only the minutes inside the agency's counting hours (BusinessHours, agency timezone).
 *  - In target = counted minutes <= the agency's target. A lead not yet answered is "waiting" and is only
 *    "overdue" once its target has passed — a lead that arrived 5 minutes ago is never a miss.
 *  - Leads received before tracking began and not provable are NOT MEASURED: left out of every figure.
 */
class LeadResponseService
{
    public const SOURCES = [
        'p24' => 'Property24',
        'pp' => 'Private Property',
        'website' => 'Website',
        'shared_link' => 'Shared link',
    ];

    public const CHANNELS = [
        'contacted_action' => 'Contacted action',
        'message' => 'Message sent',
        'shared_link' => 'Link shared',
        'appointment_feedback' => 'Appointment feedback',
    ];

    /** Drill-down / figure keys. */
    public const SUBTYPES = ['received', 'in_target', 'late', 'waiting', 'overdue', 'responded'];

    private const MAX_ROWS = 1000;

    /** The agency's target + hours + timezone, read once per request per agency. */
    public function settings(int $agencyId): array
    {
        $s = AgencyContactSettings::forAgencyReadOnly($agencyId);

        return [
            'target' => $s->leadResponseTargetMinutes(),
            'hours' => $s->leadResponseHours(),
            'timezone' => Agency::withoutGlobalScopes()->find($agencyId)?->outreachTimezone() ?: config('app.timezone'),
        ];
    }

    /**
     * @param  int[]  $agentIds  the responsible agents in scope (already scope-validated by the caller)
     * @return Collection<int,LeadResponseResult>
     */
    public function results(int $agencyId, Period $period, array $agentIds, ?CarbonInterface $now = null): Collection
    {
        if ($agentIds === []) {
            return collect();
        }

        $cfg = $this->settings($agencyId);
        $now = CarbonImmutable::instance($now ?? now());
        $responsible = 'COALESCE(pl.received_by_user_id, p.agent_id, c.agent_id)';

        $rows = DB::table('portal_leads as pl')
            ->leftJoin('properties as p', 'p.id', '=', 'pl.listing_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'pl.contact_id')
            ->leftJoin('users as u', 'u.id', '=', DB::raw($responsible))
            ->leftJoin('users as ru', 'ru.id', '=', 'pl.first_response_by_user_id')
            ->where('pl.agency_id', $agencyId)
            ->whereNull('pl.deleted_at')
            ->whereBetween('pl.received_at', [$period->start->toDateTimeString(), $period->end->toDateTimeString()])
            ->whereRaw($responsible . ' in (' . implode(',', array_fill(0, count($agentIds), '?')) . ')', array_values($agentIds))
            ->orderBy('pl.received_at')
            ->get([
                'pl.id', 'pl.portal', 'pl.contact_id', 'pl.listing_id', 'pl.name as lead_name', 'pl.received_at',
                'pl.first_response_at', 'pl.first_response_by_user_id', 'pl.first_response_channel', 'pl.response_tracked',
                'c.first_name', 'c.last_name', 'p.title as listing_title', 'p.suburb as listing_suburb',
                DB::raw($responsible . ' as agent_id'), 'u.name as agent_name', 'ru.name as responder_name',
            ]);

        return $rows->map(function ($r) use ($cfg, $now) {
            $received = CarbonImmutable::parse($r->received_at);
            $responded = $r->first_response_at ? CarbonImmutable::parse($r->first_response_at) : null;
            $minutes = null;
            $overdue = false;

            if ($responded) {
                $minutes = BusinessHours::minutesBetween($received, $responded, $cfg['hours'], $cfg['timezone']);
                $status = $minutes <= $cfg['target'] ? LeadResponseResult::IN_TARGET : LeadResponseResult::LATE;
            } elseif (! $r->response_tracked) {
                $status = LeadResponseResult::NOT_MEASURED;
            } else {
                $minutes = BusinessHours::minutesBetween($received, $now, $cfg['hours'], $cfg['timezone']);
                $status = LeadResponseResult::WAITING;
                $overdue = $minutes > $cfg['target'];
            }

            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));

            return new LeadResponseResult(
                (int) $r->id,
                (string) $r->portal,
                $r->contact_id ? (int) $r->contact_id : null,
                $name !== '' ? $name : ((string) ($r->lead_name ?: 'Unknown')),
                $r->listing_id ? (int) $r->listing_id : null,
                $r->listing_id ? ((string) ($r->listing_title ?: $r->listing_suburb ?: 'Listing #' . $r->listing_id)) : null,
                $r->agent_id ? (int) $r->agent_id : null,
                $r->agent_name,
                $received,
                $responded,
                $r->first_response_by_user_id ? (int) $r->first_response_by_user_id : null,
                $r->responder_name,
                $r->first_response_channel,
                $minutes,
                $status,
                $overdue,
            );
        });
    }

    /**
     * Counts + average + median for any slice. Not-measured leads are excluded from everything (and counted
     * separately). Average / median are over RESPONDED leads only, in counted minutes.
     *
     * @param  Collection<int,LeadResponseResult>  $results
     */
    public function summarise(Collection $results): array
    {
        $measured = $results->filter->isMeasured();
        $responded = $measured->filter->isResponded();
        $minutes = $responded->pluck('minutes')->map(fn ($m) => (int) $m)->sort()->values();
        $n = $minutes->count();

        $median = null;
        if ($n > 0) {
            $median = $n % 2 === 1
                ? $minutes[intdiv($n, 2)]
                : (int) round(($minutes[$n / 2 - 1] + $minutes[$n / 2]) / 2);
        }

        return [
            'received' => $measured->count(),
            'in_target' => $measured->where('status', LeadResponseResult::IN_TARGET)->count(),
            'late' => $measured->where('status', LeadResponseResult::LATE)->count(),
            'waiting' => $measured->where('status', LeadResponseResult::WAITING)->count(),
            'overdue' => $measured->filter(fn ($r) => $r->overdue)->count(),
            'responded' => $n,
            'avg' => $n > 0 ? (int) round($minutes->avg()) : null,
            'median' => $median,
            'not_measured' => $results->count() - $measured->count(),
        ];
    }

    /**
     * Company + per-agent + per-source summaries for both reports.
     *
     * @param  int[]  $agentIds
     * @return array{target:int,hours:array,timezone:string,company:array,agents:array<int,array>,sources:array<string,array>}
     */
    public function report(int $agencyId, Period $period, array $agentIds, ?CarbonInterface $now = null): array
    {
        $results = $this->results($agencyId, $period, $agentIds, $now);
        $cfg = $this->settings($agencyId);

        $sources = [];
        foreach ($results->groupBy('portal') as $portal => $group) {
            $sources[(string) $portal] = $this->summarise($group) + ['label' => self::SOURCES[$portal] ?? ucfirst((string) $portal)];
        }
        ksort($sources);

        return [
            'target' => $cfg['target'],
            'hours' => $cfg['hours'],
            'timezone' => $cfg['timezone'],
            'company' => $this->summarise($results),
            'agents' => $results->groupBy('agentId')->map(fn ($g) => $this->summarise($g))->all(),
            'sources' => $sources,
        ];
    }

    /**
     * The leads behind a figure: {count, rows, truncated}. Default order is the most urgent first
     * (slowest / longest-waiting).
     *
     * @param  int[]  $agentIds  the scope-validated cohort
     * @return array{count:int,rows:array<int,array>,truncated:bool}
     */
    public function rows(int $agencyId, Period $period, array $agentIds, string $subtype, ?int $agentId = null, ?string $source = null, ?CarbonInterface $now = null): array
    {
        $subtype = in_array($subtype, self::SUBTYPES, true) ? $subtype : 'received';
        if ($agentId !== null) {
            $agentIds = in_array($agentId, $agentIds, true) ? [$agentId] : [];
        }

        $tz = $this->settings($agencyId)['timezone'];
        $list = $this->results($agencyId, $period, $agentIds, $now)->filter->isMeasured()
            ->when($source !== null, fn ($c) => $c->where('portal', $source))
            ->filter(fn (LeadResponseResult $r) => match ($subtype) {
                'in_target' => $r->status === LeadResponseResult::IN_TARGET,
                'late' => $r->status === LeadResponseResult::LATE,
                'waiting' => $r->status === LeadResponseResult::WAITING,
                'overdue' => $r->overdue,
                'responded' => $r->isResponded(),
                default => true,
            })
            ->sortByDesc(fn (LeadResponseResult $r) => (int) $r->minutes)
            ->values();

        $total = $list->count();
        $fmt = fn (?CarbonImmutable $d) => $d?->setTimezone($tz)->format('d M Y H:i');

        return [
            'count' => $total,
            'rows' => $list->take(self::MAX_ROWS)->map(fn (LeadResponseResult $r) => [
                'lead' => $r->contactName,
                'source' => self::SOURCES[$r->portal] ?? ucfirst($r->portal),
                'property' => $r->listingTitle ?? '—',
                'agent' => $r->agentName ?? '—',
                'arrived' => $fmt($r->receivedAt),
                'first_contact' => $fmt($r->firstResponseAt) ?? 'Not yet',
                'responder' => $r->responderName ?? '—',
                'how' => $r->channel ? (self::CHANNELS[$r->channel] ?? $r->channel) : '—',
                'minutes' => $r->minutes === null ? '—' : $this->formatMinutes($r->minutes) . ($r->isResponded() ? '' : ' so far'),
                'result' => match (true) {
                    $r->status === LeadResponseResult::IN_TARGET => 'In target',
                    $r->status === LeadResponseResult::LATE => 'Late',
                    $r->overdue => 'Waiting — past target',
                    default => 'Waiting',
                },
                'href' => $r->contactId ? route('corex.contacts.show', $r->contactId) : null,
            ])->all(),
            'truncated' => $total > self::MAX_ROWS,
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'lead', 'label' => 'Lead', 'align' => 'left'],
            ['key' => 'source', 'label' => 'Source', 'align' => 'left'],
            ['key' => 'property', 'label' => 'Property', 'align' => 'left'],
            ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
            ['key' => 'arrived', 'label' => 'Arrived', 'align' => 'left'],
            ['key' => 'first_contact', 'label' => 'First contact', 'align' => 'left'],
            ['key' => 'responder', 'label' => 'By', 'align' => 'left'],
            ['key' => 'how', 'label' => 'How', 'align' => 'left'],
            ['key' => 'minutes', 'label' => 'Minutes counted', 'align' => 'right'],
            ['key' => 'result', 'label' => 'Result', 'align' => 'left', 'format' => 'badge'],
        ];
    }

    /** "45 min", "2 h 05 min", "1 d 3 h" — counted minutes, human. */
    public function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . ' h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . ' min';
        }

        return intdiv($minutes, 1440) . ' d ' . intdiv($minutes % 1440, 60) . ' h';
    }

    /** One-line wording of the counting hours for report headers/footnotes. */
    public function hoursSummary(array $hours): string
    {
        $names = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        $groups = [];
        foreach ($names as $key => $name) {
            $h = $hours[$key];
            $label = ($h['counted'] && $h['start'] < $h['end']) ? $h['start'] . '–' . $h['end'] : 'not counted';
            $groups[$label][] = $name;
        }

        return collect($groups)->map(fn ($days, $label) => implode(', ', $days) . ' ' . $label)->implode(' · ');
    }
}
