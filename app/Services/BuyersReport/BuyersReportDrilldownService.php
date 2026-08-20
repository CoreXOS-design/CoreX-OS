<?php

namespace App\Services\BuyersReport;

use App\Models\Contact;
use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * Buyers Report — tile-click drill-down rows. Mirrors the ROI report's
 * {title,total,columns,rows} contract (AT-366 §B) but is a fresh
 * implementation, not a reuse of PerformanceDrilldownService: that service
 * shares the ROI report's confirmed agent()/branch() scoping gap (AT-366-D
 * audit), and its metric set doesn't cover buyers_won/comms_whatsapp/lost
 * split by channel. $userIds passed in here MUST already be the caller's
 * validated cohort (BuyersReportController resolves it via
 * BuyersReportScopeResolver + HierarchyResolver, exactly as
 * BuyersReportService does) — this class does no scoping of its own, only
 * shapes rows for a cohort it's handed.
 */
class BuyersReportDrilldownService
{
    private const APPOINTMENT_CATEGORIES = ['viewing', 'listing_presentation', 'property_evaluation', 'meeting'];

    public const METRICS = ['buyers', 'buyers_added', 'buyers_won', 'appointments', 'comms_email', 'comms_whatsapp', 'lost', 'lost_value'];

    /** @param int[] $userIds @return array{count:int, rows:array[]} */
    public function rows(string $metric, array $userIds, Period $period, int $agencyId): array
    {
        if (empty($userIds)) {
            return ['count' => 0, 'rows' => []];
        }

        $rows = match ($metric) {
            'buyers' => $this->buyersHeld($userIds, $agencyId),
            'buyers_added' => $this->buyersAdded($userIds, $period),
            'buyers_won' => $this->buyersWon($userIds, $period),
            'appointments' => $this->appointments($userIds, $period),
            'comms_email' => $this->comms($userIds, $period, 'email'),
            'comms_whatsapp' => $this->comms($userIds, $period, 'whatsapp'),
            'lost', 'lost_value' => $this->lost($userIds, $period),
            default => [],
        };

        return ['count' => count($rows), 'rows' => $rows];
    }

    public function columns(string $metric): array
    {
        return match ($metric) {
            'buyers' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'state', 'label' => 'State', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'days_in_state', 'label' => 'Days stuck', 'align' => 'right'],
                ['key' => 'last_worked', 'label' => 'Last worked', 'align' => 'left', 'format' => 'date'],
            ],
            'buyers_added' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'entered', 'label' => 'Added', 'align' => 'left', 'format' => 'date'],
            ],
            'buyers_won' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'won_at', 'label' => 'Won', 'align' => 'left', 'format' => 'date'],
            ],
            'appointments' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'title', 'label' => 'Appointment', 'align' => 'left'],
                ['key' => 'category', 'label' => 'Type', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'date', 'label' => 'Date', 'align' => 'left', 'format' => 'date'],
            ],
            'comms_email', 'comms_whatsapp' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'sent_at', 'label' => 'Sent', 'align' => 'left', 'format' => 'date'],
            ],
            'lost', 'lost_value' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'reason', 'label' => 'Reason', 'align' => 'left'],
                ['key' => 'value', 'label' => 'Value lost', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'recorded_at', 'label' => 'Lost on', 'align' => 'left', 'format' => 'date'],
            ],
            default => [],
        };
    }

    /** @param int[] $userIds */
    private function buyersHeld(array $userIds, int $agencyId): array
    {
        $buyers = DB::table('contacts as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->select(['c.id', 'c.first_name', 'c.last_name', 'c.agent_id', 'c.buyer_state', 'c.last_activity_at', 'c.last_contacted_at', 'u.name as agent_name'])
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereIn('c.agent_id', $userIds)
            ->orderBy('c.first_name')
            ->get();

        if ($buyers->isEmpty()) {
            return [];
        }

        $buyerIds = $buyers->pluck('id')->map(fn ($i) => (int) $i)->all();
        $latest = DB::table('buyer_state_transitions')
            ->select('contact_id', 'to_state', DB::raw('MAX(occurred_at) as entered_at'))
            ->whereIn('contact_id', $buyerIds)
            ->groupBy('contact_id', 'to_state')
            ->get()
            ->groupBy('contact_id');

        $now = now();

        return $buyers->map(function ($b) use ($latest, $now) {
            $enteredAt = $latest->get((int) $b->id, collect())->firstWhere('to_state', $b->buyer_state)?->entered_at;
            $lastWorked = $this->greatest($b->last_contacted_at, $b->last_activity_at);

            return [
                'name' => trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $b->agent_name ?? 'Unassigned',
                'state' => $b->buyer_state === 'warm' ? 'Hot' : ucfirst((string) $b->buyer_state),
                'days_in_state' => $enteredAt ? (int) abs(\Carbon\CarbonImmutable::parse($enteredAt)->diffInDays($now)) : null,
                'last_worked' => $lastWorked,
            ];
        })->values()->all();
    }

    /** @param int[] $userIds */
    private function buyersAdded(array $userIds, Period $period): array
    {
        return DB::table('contacts as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 'c.buyer_pipeline_entered_at'])
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereIn('c.agent_id', $userIds)
            ->whereBetween('c.buyer_pipeline_entered_at', [$period->start, $period->end])
            ->orderByDesc('c.buyer_pipeline_entered_at')
            ->get()
            ->map(fn ($r) => [
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'entered' => $r->buyer_pipeline_entered_at,
            ])->all();
    }

    /** @param int[] $userIds */
    private function buyersWon(array $userIds, Period $period): array
    {
        return DB::table('buyer_state_transitions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 't.occurred_at'])
            ->where('t.to_state', \App\Services\BuyerStateService::WON)
            ->whereIn('c.agent_id', $userIds)
            ->whereNull('c.deleted_at')
            ->whereBetween('t.occurred_at', [$period->start, $period->end])
            ->orderByDesc('t.occurred_at')
            ->get()
            ->map(fn ($r) => [
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'won_at' => $r->occurred_at,
            ])->all();
    }

    /** @param int[] $userIds */
    private function appointments(array $userIds, Period $period): array
    {
        return DB::table('calendar_events as ce')
            ->join('contacts as c', 'c.id', '=', 'ce.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'ce.user_id')
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 'ce.title', 'ce.category', 'ce.event_date'])
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereColumn('c.agent_id', 'ce.user_id')
            ->whereIn('ce.category', self::APPOINTMENT_CATEGORIES)
            ->whereIn('ce.user_id', $userIds)
            ->whereBetween('ce.event_date', [$period->start, $period->end])
            ->orderByDesc('ce.event_date')
            ->get()
            ->map(fn ($r) => [
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'title' => $r->title,
                'category' => ucfirst(str_replace('_', ' ', (string) $r->category)),
                'date' => $r->event_date,
            ])->all();
    }

    /** @param int[] $userIds */
    private function comms(array $userIds, Period $period, string $channel): array
    {
        return DB::table('communications as m')
            ->join('communication_links as cl', function ($j) {
                $j->on('cl.communication_id', '=', 'm.id')
                    ->where('cl.linkable_type', '=', Contact::class)
                    ->whereNull('cl.deleted_at');
            })
            ->join('contacts as c', 'c.id', '=', 'cl.linkable_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.owner_user_id')
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 'm.occurred_at'])
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->whereColumn('c.agent_id', 'm.owner_user_id')
            ->whereIn('m.owner_user_id', $userIds)
            ->where('m.channel', $channel)
            ->whereBetween('m.occurred_at', [$period->start, $period->end])
            ->orderByDesc('m.occurred_at')
            ->distinct()
            ->get()
            ->map(fn ($r) => [
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'sent_at' => $r->occurred_at,
            ])->all();
    }

    /** @param int[] $userIds */
    private function lost(array $userIds, Period $period): array
    {
        return DB::table('buyer_lost_records as blr')
            ->leftJoin('contacts as c', 'c.id', '=', 'blr.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'blr.agent_owner_user_id_at_loss')
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 'blr.reason_label', 'blr.reason_code', 'blr.preapproval_amount_at_loss', 'blr.recorded_at'])
            ->whereIn('blr.agent_owner_user_id_at_loss', $userIds)
            ->whereNull('blr.recovered_at')
            ->whereBetween('blr.recorded_at', [$period->start, $period->end])
            ->orderByDesc('blr.recorded_at')
            ->get()
            ->map(fn ($r) => [
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'reason' => $r->reason_label ?: ($r->reason_code ?: 'Unspecified'),
                'value' => (float) $r->preapproval_amount_at_loss,
                'recorded_at' => $r->recorded_at,
            ])->all();
    }

    private function greatest(?string $a, ?string $b): ?string
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
