<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 (road-to-sold.md 22.6, "a first-run note") - turn what already exists into prospects.
 *
 * A property an agent has a current call outcome on, or has an open list row for, becomes that agent's prospect. Where two agents both have it,
 * the agent with the call outcome wins, else the earliest open list row; the other agents' OPEN rows for it are closed as missed (a property
 * belongs to one agent). A dry run prints what would happen and writes nothing.
 *
 *   rts:adopt-prospects --agency=1            dry run
 *   rts:adopt-prospects --agency=1 --apply
 */
class AdoptProspects extends Command
{
    protected $signature = 'rts:adopt-prospects {--agency= : Only this agency id} {--track=sales} {--apply : Write the prospects (otherwise a dry run)}';

    protected $description = 'Road to Sold v3: adopt existing call outcomes and open list rows as prospects, one agent per property';

    public function handle(ProspectService $prospects, OutcomeDefinitionService $defs): int
    {
        $track = (string) $this->option('track');
        $apply = (bool) $this->option('apply');
        $agencyIds = DB::table('agencies')->when($this->option('agency'), fn ($q, $a) => $q->where('id', (int) $a))->pluck('id');

        foreach ($agencyIds as $agencyId) {
            // property => [agent => ['outcome' => latest current outcome or null, 'item' => earliest open row or null]]
            $claims = [];
            foreach (RtsContactOutcome::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->where('is_current', true)->get() as $o) {
                $claims[(int) $o->tracked_property_id][(int) $o->user_id]['outcome'] = $o;
            }
            foreach (RtsListItem::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->where('status', RtsListItem::STATUS_OPEN)
                ->whereNotNull('tracked_property_id')->orderBy('list_date')->orderBy('id')->get() as $i) {
                $claims[(int) $i->tracked_property_id][(int) $i->user_id]['item'] ??= $i;
            }

            $made = $already = $closed = 0;
            foreach ($claims as $tpId => $byAgent) {
                if ($prospects->find((int) $agencyId, $tpId, $track)) {
                    $already++;
                    continue;
                }
                $winner = $this->winner($byAgent);
                $user = User::query()->withoutGlobalScopes()->find($winner);
                if (! $user || ! $user->is_active || $user->is_assistant) {
                    continue;
                }
                $made++;
                foreach ($byAgent as $agentId => $c) {
                    if ($agentId !== $winner && isset($c['item'])) {
                        $closed++;
                    }
                }
                if (! $apply) {
                    continue;
                }
                $outcome = $byAgent[$winner]['outcome'] ?? null;
                $p = $prospects->place($user, $tpId, $track, [
                    'prospecting_listing_id' => ($byAgent[$winner]['item'] ?? null)?->prospecting_listing_id,
                    'property_id' => $outcome?->property_id ?? ($byAgent[$winner]['item'] ?? null)?->property_id,
                    'selection' => ($byAgent[$winner]['item'] ?? null)?->selection,
                    'reason' => ($byAgent[$winner]['item'] ?? null)?->reason,
                    'stage' => $outcome && $outcome->kind === 'conversation' ? ProspectStages::CONTACTED : ProspectStages::FOUND,
                ]);
                if ($p && $outcome) {
                    $def = $defs->list((int) $agencyId, $track, false, true)->firstWhere('key', $outcome->outcome_key);
                    $p->forceFill([
                        'last_outcome_id' => $outcome->id,
                        'next_action_key' => in_array($outcome->next_action, ProspectService::NO_ACTION, true) ? null : $outcome->next_action,
                        'next_action_label' => in_array($outcome->next_action, ProspectService::NO_ACTION, true) ? null : (ProspectService::ACTION_LABELS[$outcome->next_action] ?? ($def?->label ?: $outcome->outcome_label)),
                        'next_action_at' => $outcome->next_action_at,
                    ])->save();
                }
                // the other agents' open rows for it: it is not theirs
                foreach ($byAgent as $agentId => $c) {
                    if ($agentId !== $winner && isset($c['item'])) {
                        RtsListItem::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $agentId)
                            ->where('tracked_property_id', $tpId)->where('status', RtsListItem::STATUS_OPEN)
                            ->update(['status' => RtsListItem::STATUS_MISSED, 'status_changed_at' => now()]);
                    }
                }
                if ($p && ($byAgent[$winner]['item'] ?? null)) {
                    RtsListItem::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $winner)
                        ->where('tracked_property_id', $tpId)->whereNull('prospect_id')->update(['prospect_id' => $p->id]);
                }
            }
            $this->line(sprintf('agency #%d: %d would become prospects (%d already are), %d other agents\' open rows %s',
                $agencyId, $made, $already, $closed, $apply ? 'closed as missed' : 'would be closed as missed'));
        }
        $this->info($apply ? 'Done.' : 'Dry run - nothing was written. Add --apply to write.');

        return self::SUCCESS;
    }

    /** The agent a property goes to: the one who called it (latest call), else the one with the earliest open row. */
    private function winner(array $byAgent): int
    {
        $called = array_filter($byAgent, fn ($c) => isset($c['outcome']));
        if ($called !== []) {
            uasort($called, fn ($a, $b) => $b['outcome']->occurred_at <=> $a['outcome']->occurred_at);

            return (int) array_key_first($called);
        }
        uasort($byAgent, fn ($a, $b) => [$a['item']->list_date, $a['item']->id] <=> [$b['item']->list_date, $b['item']->id]);

        return (int) array_key_first($byAgent);
    }
}
