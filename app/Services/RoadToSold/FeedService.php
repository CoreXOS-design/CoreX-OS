<?php

namespace App\Services\RoadToSold;

use App\Models\AgencyContactSettings;
use App\Models\Property;
use App\Models\User;
use App\Services\LeadResponse\LeadResponseResult;
use App\Services\LeadResponse\LeadResponseService;
use App\Services\Performance\Period;
use App\Services\Properties\MandateExpiryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 - the three blocks under the call list. They are NOT counted against the call target.
 * Spec: .ai/specs/road-to-sold.md section 10.5.
 *
 * Written for this page rather than re-using the Today-page queries, which carry three faults
 * (reported, not fixed): buyers matched on created_by_user_id instead of the buyer's agent, a
 * hard-coded 14 days, and risk scores joined without keeping only the newest. Here: the buyer's
 * agent (either of the two), the agency's own buyer_warm_days, the newest risk score only.
 *
 * Every feed is for ONE agent (the page's agent) - the caller has already checked the viewer may
 * look at that agent.
 */
class FeedService
{
    public const LIMIT = 8;
    public const LEAD_LOOKBACK_DAYS = 30;
    public const RISK_FLOOR = 60;

    public function __construct(private readonly LeadResponseService $leads)
    {
    }

    /**
     * Buyers this agent has not been in touch with for longer than the agency's warm window (or never),
     * still live in the pipeline (new / warm / cold). Worst first.
     *
     * @return array{count:int, items:array<int,array<string,mixed>>}
     */
    public function buyerFollowUps(User $agent, int $limit = self::LIMIT): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['count' => 0, 'items' => []];
        }

        $warmDays = max(1, (int) AgencyContactSettings::forAgencyReadOnly($agencyId)->buyer_warm_days);
        $ids = $agent->dataIdentityIds();
        $cutoff = now()->subDays($warmDays);
        $latest = 'GREATEST(COALESCE(c.last_contacted_at, c.last_activity_at, \'1970-01-01\'), COALESCE(c.last_activity_at, c.last_contacted_at, \'1970-01-01\'))';

        $q = DB::table('contacts as c')
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', true)
            ->whereNull('c.deleted_at')
            ->whereIn('c.buyer_state', ['new', 'warm', 'cold'])
            ->where(fn ($w) => $w->whereIn('c.agent_id', $ids)->orWhereIn('c.second_agent_id', $ids))
            ->whereRaw("{$latest} < ?", [$cutoff->toDateTimeString()]);

        $count = (clone $q)->count();
        $rows = $q->orderByRaw("{$latest} asc")->limit($limit)
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.entity_name', 'c.last_contacted_at', 'c.last_activity_at', 'c.buyer_state']);

        // Newest risk score only, for the rows shown.
        $risk = [];
        if ($rows->isNotEmpty()) {
            $risk = DB::table('buyer_lost_risk_scores as r')
                ->whereIn('r.contact_id', $rows->pluck('id'))
                ->whereRaw('r.id = (select max(r2.id) from buyer_lost_risk_scores r2 where r2.contact_id = r.contact_id)')
                ->pluck('r.score', 'r.contact_id')->all();
        }

        $items = $rows->map(function ($b) use ($risk) {
            $last = collect([$b->last_contacted_at, $b->last_activity_at])->filter()->map(fn ($d) => CarbonImmutable::parse($d))->max();
            $name = trim(($b->entity_name ?: '') !== '' ? $b->entity_name : trim($b->first_name . ' ' . $b->last_name));
            $reason = $last ? 'No contact for ' . (int) $last->diffInDays(now()) . ' days' : 'Not contacted yet';
            if (isset($risk[$b->id]) && (int) $risk[$b->id] > self::RISK_FLOOR) {
                $reason .= ' · risk of losing them ' . (int) $risk[$b->id] . '%';
            }

            return ['title' => $name !== '' ? $name : 'Buyer', 'sub' => $reason, 'url' => route('corex.contacts.show', $b->id)];
        })->all();

        return ['count' => $count, 'items' => $items];
    }

    /**
     * Portal enquiries still waiting for a first genuine contact from this agent, worst first.
     *
     * @return array{count:int, items:array<int,array<string,mixed>>}
     */
    public function leadFollowUps(User $agent, int $limit = self::LIMIT): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['count' => 0, 'items' => []];
        }

        $end = CarbonImmutable::now();
        $period = new Period($end->subDays(self::LEAD_LOOKBACK_DAYS)->startOfDay(), $end, 'Last ' . self::LEAD_LOOKBACK_DAYS . ' days', 'last_30_days');
        $waiting = $this->leads->results($agencyId, $period, $agent->dataIdentityIds(), $end)
            ->filter(fn (LeadResponseResult $r) => $r->status === LeadResponseResult::WAITING)
            ->sortBy([fn ($a, $b) => (int) $b->overdue <=> (int) $a->overdue, fn ($a, $b) => $a->receivedAt <=> $b->receivedAt])
            ->values();

        $items = $waiting->take($limit)->map(fn (LeadResponseResult $r) => [
            'title' => $r->contactName !== '' ? $r->contactName : 'Enquiry',
            'sub'   => ($r->overdue ? 'Overdue · ' : '') . ucfirst((string) $r->portal) . ' · ' . $r->receivedAt->format('j M H:i')
                . ($r->listingTitle ? ' · ' . $r->listingTitle : ''),
            'url'   => $r->contactId ? route('corex.contacts.show', $r->contactId) : null,
        ])->all();

        return ['count' => $waiting->count(), 'items' => $items];
    }

    /**
     * This agent's own listings whose mandate ends inside the agency's warning window, soonest first.
     *
     * @return array{count:int, items:array<int,array<string,mixed>>}
     */
    public function expiringMandates(User $agent, int $limit = self::LIMIT): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['count' => 0, 'items' => []];
        }

        [$from, $to] = MandateExpiryPolicy::expiringWindow($agencyId);
        $base = Property::query()->where('agency_id', $agencyId)->ownListingsFor($agent)->expiringSoon($from, $to);
        $count = (clone $base)->count();

        $items = $base->orderBy('expiry_date')->limit($limit)->get(['id', 'title', 'expiry_date', 'mandate_type'])
            ->map(function ($p) {
                $ends = Carbon::parse($p->expiry_date)->startOfDay();
                $days = (int) now()->startOfDay()->diffInDays($ends);

                return [
                    'title' => $p->title ?: 'Listing',
                    'sub'   => 'Mandate ends ' . $ends->format('j M Y') . ($days === 0 ? ' (today)' : ' (' . $days . ' day' . ($days === 1 ? '' : 's') . ')')
                        . ($p->mandate_type ? ' · ' . ucfirst((string) $p->mandate_type) : ''),
                    'url'   => route('corex.properties.show', $p->id),
                ];
            })->all();

        return ['count' => $count, 'items' => $items];
    }
}
