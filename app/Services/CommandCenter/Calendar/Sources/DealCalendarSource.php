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
            ->merge($this->dealRegistrationTarget());
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
            ->select(
                'dsi.id',
                'dsi.due_date',
                'dsi.deal_id',
                'dsi.name as step_name',
                'd.branch_id',
                'd.selling_agent_id',
                'd.property_id',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'deal',
                'category'    => 'deal_step_deadline',
                'title'       => $r->step_name
                    ? "{$r->step_name} due (deal #{$r->deal_id})"
                    : "Pipeline step due (deal #{$r->deal_id})",
                'event_date'  => Carbon::parse($r->due_date)->startOfDay(),
                'source_type' => \App\Models\DealV2\DealStepInstance::class,
                'source_id'   => $r->id,
                'user_id'     => $r->selling_agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
                'metadata'    => [
                    'deal_id'   => $r->deal_id,
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
            ->select(
                'd.id',
                'd.expected_registration',
                'd.branch_id',
                'd.selling_agent_id',
                'd.property_id',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($d) => [
                'event_type'  => 'deal',
                'category'    => 'deal_registration_target',
                'title'       => "Target registration — deal #{$d->id}",
                'event_date'  => Carbon::parse($d->expected_registration)->startOfDay(),
                'source_type' => \App\Models\DealV2\DealV2::class,
                'source_id'   => $d->id,
                'user_id'     => $d->selling_agent_id,
                'agency_id'   => $d->agency_id,
                'branch_id'   => $d->branch_id,
                'property_id' => $d->property_id,
            ]);
    }
}
