<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 2 deal-domain event classes:
 *   deal_step_deadline       — per active V2 pipeline step with a due_date
 *   deal_registration_target — per V2 deal's expected_registration
 *
 * V1 deals deliberately excluded — V1 has no forward-looking deadline columns.
 *
 * Note: deals_v2 has no agency_id column. Agency is resolved via branch_id
 * → branches.agency_id.
 */
class DealCalendarSource implements CalendarSourceContract
{
    /** Step statuses that count as "still pending action". */
    private const ACTIVE_STEP_STATUSES = ['active', 'not_started'];

    /** Deal statuses that count as "deal still in flight". */
    private const ACTIVE_DEAL_STATUSES = ['active', 'draft', 'accepted', 'in_progress', 'pending'];

    public function name(): string
    {
        return 'DealCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->dealStepDeadline())
            ->merge($this->dr1DealStepDeadline())
            ->merge($this->dealRegistrationTarget());
    }

    /**
     * AT-216 V1.1 — DR2 pipeline step deadlines anchored to a DR1 `deals` row (dr1_deal_id).
     * FEEDS THE EXISTING deal-step calendar layer (same source_type + category as the
     * deals_v2 steps above) — no second event system. DR1 `deals` carries agency_id +
     * property_address directly (no branches join); the representative agent comes from the
     * deal_user pivot so the step lands on that agent's calendar + My Deals tile.
     */
    private function dr1DealStepDeadline(): Collection
    {
        return DB::table('deal_step_instances as dsi')
            ->whereNull('dsi.deleted_at')
            ->whereNotNull('dsi.due_date')
            ->whereNotNull('dsi.dr1_deal_id')
            ->whereIn('dsi.status', self::ACTIVE_STEP_STATUSES)
            ->join('deals as d', 'd.id', '=', 'dsi.dr1_deal_id')
            ->whereNull('d.deleted_at')
            ->select(
                'dsi.id',
                'dsi.due_date',
                'dsi.dr1_deal_id as deal_id',
                'dsi.name as step_name',
                'd.deal_no as reference',
                'd.branch_id',
                'd.agency_id',
                'd.property_id',
                'd.property_address',
                DB::raw('(select du.user_id from deal_user du where du.deal_id = d.id order by du.id limit 1) as agent_id'),
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'deal',
                'category'    => 'deal_step_deadline',
                'title'       => ($r->step_name ?? 'Step') . ' Due — ' . ($r->reference ?? ('Deal #' . $r->deal_id)) . ($r->property_address ? ' — ' . $r->property_address : ''),
                'event_date'  => Carbon::parse($r->due_date)->startOfDay(),
                'source_type' => \App\Models\DealV2\DealStepInstance::class,
                'source_id'   => $r->id,
                'user_id'     => $r->agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
                'metadata'    => [
                    'deal_id'   => $r->deal_id,
                    'deal_ref'  => $r->reference,
                    'step_name' => $r->step_name,
                    'dr1'       => true,
                ],
            ]);
    }

    private function dealStepDeadline(): Collection
    {
        return DB::table('deal_step_instances as dsi')
            ->whereNull('dsi.deleted_at')
            ->whereNotNull('dsi.due_date')
            ->whereIn('dsi.status', self::ACTIVE_STEP_STATUSES)
            ->join('deals_v2 as d', 'd.id', '=', 'dsi.deal_id')
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', self::ACTIVE_DEAL_STATUSES)
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('properties as p', 'p.id', '=', 'd.property_id')
            ->select(
                'dsi.id',
                'dsi.due_date',
                'dsi.deal_id',
                'dsi.name as step_name',
                'd.reference',
                'd.branch_id',
                'd.selling_agent_id',
                'd.listing_agent_id',
                'd.property_id',
                'b.agency_id',
                DB::raw("COALESCE(p.address, p.title, CONCAT('Property #', p.id)) as property_label"),
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'deal',
                'category'    => 'deal_step_deadline',
                'title'       => ($r->step_name ?? 'Step') . ' Due — ' . ($r->reference ?? 'Deal') . ($r->property_label ? ' — ' . $r->property_label : ''),
                'event_date'  => Carbon::parse($r->due_date)->startOfDay(),
                'source_type' => \App\Models\DealV2\DealStepInstance::class,
                'source_id'   => $r->id,
                'user_id'     => $r->selling_agent_id ?? $r->listing_agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
                'metadata'    => [
                    'deal_id'   => $r->deal_id,
                    'deal_ref'  => $r->reference,
                    'step_name' => $r->step_name,
                ],
            ]);
    }

    private function dealRegistrationTarget(): Collection
    {
        return DB::table('deals_v2 as d')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.expected_registration')
            ->whereIn('d.status', self::ACTIVE_DEAL_STATUSES)
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('properties as p', 'p.id', '=', 'd.property_id')
            ->select(
                'd.id',
                'd.reference',
                'd.expected_registration',
                'd.branch_id',
                'd.selling_agent_id',
                'd.listing_agent_id',
                'd.property_id',
                'b.agency_id',
                DB::raw("COALESCE(p.address, p.title, CONCAT('Property #', p.id)) as property_label"),
            )
            ->get()
            ->map(fn ($d) => [
                'event_type'  => 'deal',
                'category'    => 'deal_registration_target',
                'title'       => 'Registration Expected — ' . ($d->reference ?? 'Deal') . ($d->property_label ? ' — ' . $d->property_label : ''),
                'event_date'  => Carbon::parse($d->expected_registration)->startOfDay(),
                'source_type' => \App\Models\DealV2\DealV2::class,
                'source_id'   => $d->id,
                'user_id'     => $d->selling_agent_id ?? $d->listing_agent_id,
                'agency_id'   => $d->agency_id,
                'branch_id'   => $d->branch_id,
                'property_id' => $d->property_id,
                'metadata'    => [
                    'deal_id'  => $d->id,
                    'deal_ref' => $d->reference,
                ],
            ]);
    }
}
