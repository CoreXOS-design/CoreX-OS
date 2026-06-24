<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * AT-91 — WhatsApp Outreach Summary board read model.
 *
 * Produces the agents × outreach-states matrix in ONE scoped groupBy query.
 * Because the query is built on the Contact model, ContactScope (agent → own,
 * BM → branch, admin → all) and AgencyScope apply automatically — the board a
 * user sees is exactly the contacts they are allowed to see.
 *
 * Population: contacts with ≥1 non-deleted WhatsApp seller_outreach_send (the
 * hasWhatsappOutreach scope). Rows key off contacts.agent_id (the operational
 * responsible agent); NULL agent_id rolls into an "Unassigned" row so no send
 * is dropped. State columns + the 'awaiting' leftover come from the single
 * Contact::outreachStateSql() source, so the board and the contacts-list
 * drill-through count identically.
 *
 * Spec: .ai/specs/whatsapp-outreach-summary.md
 */
class WhatsappOutreachSummaryService
{
    /**
     * @return array{
     *   rows: array<int, array{agent_id:?int, agent_name:string, pending:int, confirmed:int, opt_out_no_response:int, opted_out:int, awaiting:int, total:int}>,
     *   totals: array{pending:int, confirmed:int, opt_out_no_response:int, opted_out:int, awaiting:int, total:int},
     *   has_awaiting: bool
     * }
     */
    public function board(): array
    {
        // SUM(CASE WHEN <state-sql> THEN 1 ELSE 0 END) per state — one pass.
        // The fragments are the single source of truth shared with the
        // contacts-list ?outreach_state filter (Contact::outreachStateSql).
        $selects = ['contacts.agent_id', 'COUNT(*) as total'];
        foreach (Contact::OUTREACH_BOARD_STATES_ALL as $state) {
            $selects[] = 'SUM(CASE WHEN ' . Contact::outreachStateSql($state) . ' THEN 1 ELSE 0 END) as ' . $state;
        }

        /** @var Collection<int, \stdClass> $aggregates */
        $aggregates = Contact::query()
            ->hasWhatsappOutreach()
            ->selectRaw(implode(', ', $selects))
            ->groupBy('contacts.agent_id')
            ->get();

        // Resolve agent display names in one query (the responsible agent =
        // contacts.agent_id → User). withoutGlobalScopes so an assignee in a
        // different branch than the viewer still renders a name, not a blank —
        // ContactScope already governed WHICH contacts were counted.
        $agentIds = $aggregates->pluck('agent_id')->filter()->unique()->values()->all();
        $names = $agentIds === []
            ? collect()
            : User::withoutGlobalScopes()->whereIn('id', $agentIds)->pluck('name', 'id');

        $rows = $aggregates->map(function ($row) use ($names): array {
            $agentId = $row->agent_id !== null ? (int) $row->agent_id : null;

            return [
                'agent_id' => $agentId,
                'agent_name' => $agentId === null
                    ? 'Unassigned'
                    : ($names[$agentId] ?? ('Agent #' . $agentId)),
                'pending' => (int) $row->pending,
                'confirmed' => (int) $row->confirmed,
                'opt_out_no_response' => (int) $row->opt_out_no_response,
                'opted_out' => (int) $row->opted_out,
                'awaiting' => (int) $row->awaiting,
                'total' => (int) $row->total,
            ];
        })
        // Named agents first (alphabetical), Unassigned last.
        ->sortBy(fn (array $r) => [$r['agent_id'] === null ? 1 : 0, mb_strtolower($r['agent_name'])])
        ->values()
        ->all();

        $totals = [
            'pending' => 0, 'confirmed' => 0, 'opt_out_no_response' => 0,
            'opted_out' => 0, 'awaiting' => 0, 'total' => 0,
        ];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'has_awaiting' => $totals['awaiting'] > 0,
        ];
    }
}
