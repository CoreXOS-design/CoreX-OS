<?php

namespace App\Services\Performance;

use Illuminate\Support\Facades\DB;

/**
 * AT-366 (9) — read-only DRILLDOWN: the underlying rows behind a clicked figure.
 *
 * Strictly agency-scoped (multi-tenant): the cohort is resolved from the actor's
 * agency only, and every query also filters agency_id where the column exists —
 * no cross-agency leak. Import-sensitive metrics reuse AgentActivityFilter so the
 * rows returned match the corrected counts exactly. Capped at self::LIMIT rows;
 * the true match count is returned alongside.
 */
class PerformanceDrilldownService
{
    public const LIMIT = 1000;
    public const METRICS = [
        'deals', 'contacts_created', 'properties_created', 'fica_submissions', 'buyers_added', 'viewings',
        'mic_claims', 'presentations_created', 'portal_views', 'appointments', 'outreach_messages', 'commission',
    ];

    private const APPOINTMENT_CATEGORIES = ['viewing', 'listing_presentation', 'property_evaluation', 'meeting'];

    /** Resolve the in-scope agent ids for this agency (company / one branch / one agent). */
    public function cohort(int $agencyId, ?int $branchId, ?int $agentId): array
    {
        $q = DB::table('users')->where('agency_id', $agencyId)->where('is_active', 1)->whereNull('deleted_at');
        if ($agentId !== null) $q->where('id', $agentId);
        elseif ($branchId !== null) $q->where('branch_id', $branchId);
        return $q->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * @return array{count:int, rows:array}
     */
    public function rows(string $metric, array $cohort, Period $period, int $agencyId, ?string $status = null): array
    {
        if (empty($cohort)) return ['count' => 0, 'rows' => []];
        $s = $period->start->toDateTimeString();
        $e = $period->end->toDateTimeString();

        return match ($metric) {
            'deals'              => $this->deals($cohort, $period, $agencyId, $status),
            'contacts_created'   => $this->contacts($cohort, $s, $e, $agencyId),
            'properties_created' => $this->properties($cohort, $s, $e, $agencyId),
            'fica_submissions'   => $this->fica($cohort, $s, $e, $agencyId),
            'buyers_added'       => $this->buyers($cohort, $s, $e, $agencyId),
            'viewings'           => $this->viewings($cohort, $s, $e, $agencyId),
            'mic_claims'         => $this->micClaims($cohort, $s, $e, $agencyId),
            'presentations_created' => $this->presentations($cohort, $s, $e, $agencyId),
            'portal_views'       => $this->portalViews($cohort, $period, $agencyId),
            'appointments'       => $this->appointments($cohort, $s, $e, $agencyId),
            'outreach_messages'  => $this->outreach($cohort, $s, $e, $agencyId),
            'commission'         => $this->commission($cohort, $period, $agencyId),
            default              => ['count' => 0, 'rows' => []],
        };
    }

    private function pack($q, callable $map): array
    {
        $count = (clone $q)->count();
        $rows  = $q->limit(self::LIMIT)->get()->map($map)->all();
        return ['count' => $count, 'rows' => $rows];
    }

    private function deals(array $cohort, Period $period, int $agencyId, ?string $status): array
    {
        $start = $period->start->toDateString(); $end = $period->end->toDateString();
        $out = [];
        // DR1 (canonical)
        foreach (DB::table('deals')->whereNull('deleted_at')->where('agency_id', $agencyId)
                     ->whereNotNull('deal_date')->whereBetween('deal_date', [$start, $end])
                     ->where(fn ($w) => $w->whereIn('managed_by_user_id', $cohort)
                         ->orWhereIn('id', DB::table('deal_user')->whereIn('user_id', $cohort)->select('deal_id')))
                     ->limit(self::LIMIT)
                     ->get(['id', 'deal_no', 'property_address', 'property_value', 'total_commission', 'accepted_status', 'registration_date', 'granted_at', 'deal_date']) as $d) {
            $bucket = DealStatusBreakdownService::dr1Bucket($d);
            if ($status && $status !== 'all' && $bucket !== $status) continue;
            $out[] = ['id' => (int) $d->id, 'register' => 'DR1', 'ref' => $d->deal_no,
                'address' => $d->property_address, 'price' => (float) $d->property_value,
                'commission' => (float) $d->total_commission, 'status' => $bucket,
                'date' => $d->deal_date, 'url' => '/admin/deals/' . $d->id];
        }
        // DR2 (unlinked only)
        foreach (DB::table('deals_v2')->whereNull('deleted_at')->where('agency_id', $agencyId)->whereNull('legacy_deal_id')
                     ->whereNotNull('offer_date')->whereBetween('offer_date', [$start, $end])
                     ->where(fn ($w) => $w->whereIn('listing_agent_id', $cohort)->orWhereIn('selling_agent_id', $cohort)
                         ->orWhereIn('id', DB::table('deal_v2_agents')->whereIn('user_id', $cohort)->select('deal_id')))
                     ->limit(self::LIMIT)
                     ->get(['id', 'reference', 'purchase_price', 'commission_amount', 'status', 'actual_registration', 'offer_date']) as $d) {
            $bucket = DealStatusBreakdownService::dr2Bucket($d);
            if ($status && $status !== 'all' && $bucket !== $status) continue;
            $out[] = ['id' => (int) $d->id, 'register' => 'DR2', 'ref' => $d->reference,
                'address' => null, 'price' => (float) $d->purchase_price,
                'commission' => (float) $d->commission_amount, 'status' => $bucket,
                'date' => $d->offer_date, 'url' => '/corex/deals-v2/' . $d->id];
        }
        return ['count' => count($out), 'rows' => array_slice($out, 0, self::LIMIT)];
    }

    private function contacts(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = AgentActivityFilter::contacts(
            DB::table('contacts')->whereNull('deleted_at')->where('contacts.agency_id', $agencyId)
                ->whereIn('created_by_user_id', $cohort)->whereBetween('created_at', [$s, $e])
        )->orderByDesc('created_at')
         ->select('id', 'first_name', 'last_name', 'created_at', 'contact_source_id');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id,
            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
            'created_at' => $r->created_at, 'url' => '/corex/contacts/' . $r->id]);
    }

    private function properties(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = AgentActivityFilter::properties(
            DB::table('properties')->whereNull('deleted_at')->where('properties.agency_id', $agencyId)
                ->whereIn('agent_id', $cohort)->whereBetween('created_at', [$s, $e])
        )->orderByDesc('created_at')
         ->select('id', 'title', 'status', 'listed_date', 'created_at');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'address' => $r->title,
            'status' => $r->status, 'listed_date' => $r->listed_date ?? $r->created_at,
            'url' => '/properties/' . $r->id]);
    }

    private function fica(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = AgentActivityFilter::ficaViaContact(
            DB::table('fica_submissions')->where('fica_submissions.agency_id', $agencyId)
                ->whereIn('requested_by', $cohort)->whereBetween('fica_submissions.created_at', [$s, $e])
                ->whereNull('fica_submissions.deleted_at')
        )->leftJoin('contacts as c', 'c.id', '=', 'fica_submissions.contact_id')
         ->orderByDesc('fica_submissions.created_at')
         ->select('fica_submissions.id', 'fica_submissions.status', 'fica_submissions.created_at',
             DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as contact_name"));
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'contact_name' => $r->contact_name,
            'status' => $r->status, 'created_at' => $r->created_at, 'url' => '/corex/fica/' . $r->id]);
    }

    private function buyers(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('contacts')->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->where('is_buyer', 1)->whereIn('agent_id', $cohort)
            ->whereBetween('buyer_pipeline_entered_at', [$s, $e])
            ->orderByDesc('buyer_pipeline_entered_at')
            ->select('id', 'first_name', 'last_name', 'buyer_pipeline_entered_at');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id,
            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
            'entered_at' => $r->buyer_pipeline_entered_at, 'url' => '/corex/contacts/' . $r->id]);
    }

    private function viewings(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('calendar_events')->where('category', 'viewing')
            ->whereIn('user_id', $cohort)->whereBetween('event_date', [$s, $e]);
        if (DB::getSchemaBuilder()->hasColumn('calendar_events', 'agency_id')) $q->where('agency_id', $agencyId);
        $q->orderByDesc('event_date')->select('id', 'title', 'event_date');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'title' => $r->title,
            'event_date' => $r->event_date, 'url' => '/calendar/event/' . $r->id]);
    }

    private function micClaims(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('prospecting_claims as pc')
            ->leftJoin('prospecting_listings as pl', 'pl.id', '=', 'pc.prospecting_listing_id')
            ->where('pc.agency_id', $agencyId)->whereIn('pc.user_id', $cohort)
            ->whereBetween('pc.claimed_at', [$s, $e])
            ->orderByDesc('pc.claimed_at')
            ->select('pc.id', 'pc.status', 'pc.claimed_at', 'pc.property_id',
                DB::raw('COALESCE(pl.address, pl.normalized_address) as address'));
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'address' => $r->address, 'status' => $r->status,
            'claimed_at' => $r->claimed_at, 'url' => $r->property_id ? '/corex/properties/' . $r->property_id : '/corex/prospecting']);
    }

    private function presentations(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('presentations')->where('agency_id', $agencyId)
            ->whereIn('created_by_user_id', $cohort)->whereBetween('created_at', [$s, $e])
            ->orderByDesc('created_at')->select('id', 'title', 'property_address', 'status', 'created_at');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id,
            'title' => $r->title ?: ($r->property_address ?: ('Presentation #' . $r->id)),
            'status' => $r->status, 'created_at' => $r->created_at, 'url' => '/corex/presentations/' . $r->id]);
    }

    /** Portal views drilldown = the LISTINGS viewed, with their summed view_count for the period. */
    private function portalViews(array $cohort, Period $period, int $agencyId): array
    {
        $q = DB::table('property_portal_metrics as ppm')->join('properties as p', 'p.id', '=', 'ppm.property_id')
            ->where('p.agency_id', $agencyId)->whereIn('p.agent_id', $cohort)->whereNull('p.deleted_at')
            ->whereBetween('ppm.metric_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('p.id', 'p.title')
            ->orderByDesc(DB::raw('SUM(ppm.view_count)'))
            ->select('p.id', 'p.title', DB::raw('SUM(ppm.view_count) as views'));
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'title' => $r->title ?: ('Listing #' . $r->id),
            'views' => (int) $r->views, 'url' => '/properties/' . $r->id]);
    }

    private function appointments(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('calendar_events')->whereIn('category', self::APPOINTMENT_CATEGORIES)
            ->whereIn('user_id', $cohort)->whereBetween('event_date', [$s, $e]);
        if (DB::getSchemaBuilder()->hasColumn('calendar_events', 'agency_id')) $q->where('agency_id', $agencyId);
        $q->orderByDesc('event_date')->select('id', 'title', 'category', 'event_date');
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id, 'title' => $r->title,
            'category' => $r->category, 'event_date' => $r->event_date, 'url' => '/calendar/event/' . $r->id]);
    }

    private function outreach(array $cohort, string $s, string $e, int $agencyId): array
    {
        $q = DB::table('seller_outreach_sends as so')->leftJoin('contacts as c', 'c.id', '=', 'so.contact_id')
            ->where('so.agency_id', $agencyId)->whereIn('so.agent_id', $cohort)->whereBetween('so.sent_at', [$s, $e])
            ->orderByDesc('so.sent_at')
            ->select('so.id', 'so.channel', 'so.sent_at', 'so.address_snapshot', 'so.contact_id',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as contact_name"));
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id,
            'contact' => trim((string) $r->contact_name) ?: ($r->address_snapshot ?: 'Contact'),
            'channel' => $r->channel, 'sent_at' => $r->sent_at,
            'url' => $r->contact_id ? '/corex/contacts/' . $r->contact_id : '#']);
    }

    /**
     * Commission drilldown = the money lines (per deal) contributing an agent's gross ex-VAT.
     *
     * 2026-08 (company-share refinement) — one row per money LINE (per agent per deal), not
     * per deal, so a joint deal legitimately appears more than once here. Without an agent
     * label that read as a duplicate; now every row carries its own agent name + that agent's
     * company_gross_ex_vat share alongside their agent_gross_ex_vat, so a 2-agent deal's two
     * rows are visibly two different people's cuts, not "the same entry twice".
     */
    private function commission(array $cohort, Period $period, int $agencyId): array
    {
        $q = DB::table('deal_money_lines as ml')->join('deals as d', 'd.id', '=', 'ml.deal_id')
            ->leftJoin('users as u', 'u.id', '=', 'ml.user_id')
            ->whereNull('ml.deleted_at')->whereNull('d.deleted_at')->where('d.agency_id', $agencyId)
            ->whereIn('ml.user_id', $cohort)->whereNotNull('d.deal_date')
            ->whereBetween('d.deal_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->orderByDesc('ml.agent_gross_ex_vat')
            ->select(
                'd.id', 'd.property_address', 'd.deal_no', 'u.name as agent_name',
                DB::raw('ml.agent_gross_ex_vat as commission'),
                DB::raw('ml.company_gross_ex_vat as company_commission'),
            );
        return $this->pack($q, fn ($r) => ['id' => (int) $r->id,
            'address' => $r->property_address ?: ('Deal ' . ($r->deal_no ?: $r->id)),
            'agent' => $r->agent_name ?: 'Unknown agent',
            'commission' => (float) $r->commission,
            'company_commission' => (float) $r->company_commission,
            'url' => '/admin/deals/' . $r->id]);
    }
}
