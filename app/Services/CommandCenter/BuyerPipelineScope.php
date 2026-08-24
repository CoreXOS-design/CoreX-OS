<?php

namespace App\Services\CommandCenter;

/**
 * The buyer pipeline board's Layer 3 workspace scope (own/branch/agency),
 * extracted so the Buyers Report's pipeline-state breakdown can apply the
 * IDENTICAL filter — not a re-derived copy — and therefore genuinely
 * reconcile with the board's kanban badges (Johan, 2026-08-20: "this report
 * especially should marry up to the buyers pipeline").
 *
 * Was BuyerPipelineController::applyPipelineScope(); that method now
 * delegates here. Layer 1 (AgencyScope) and Layer 2 (ContactScope,
 * role-based visibility) still apply automatically via Eloquent on
 * whichever Contact query this is called against -- this class only
 * covers the explicit own/branch/agency toggle.
 */
class BuyerPipelineScope
{
    public static function apply($query, string $level, ?int $agentId = null, ?int $branchId = null): void
    {
        if ($level === 'own') {
            $query->where('contacts.agent_id', $agentId);
        } elseif ($level === 'branch') {
            if ($branchId) {
                $query->whereIn('contacts.agent_id', function ($sub) use ($branchId) {
                    $sub->select('id')->from('users')->where('branch_id', $branchId)->whereNull('deleted_at');
                });
            } else {
                $query->where('contacts.agent_id', $agentId);
            }
        }
        // 'agency' = no additional filter (Layer 2 controls access)
    }
}
